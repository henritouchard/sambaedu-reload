{{-- Story 16.13bis : shebang prefixée par MigrationFragmentRenderer
     (PHP strip le `#!` initial d'un fichier compilé Blade). --}}
# ===================================================================
# SambaEdu - Fragment migration SE4 -> SE5 (Story 16.13bis)
# Module App\Auth\V1\Migration - auto-obsolescence quand plus aucun
# deploiement SE4 actif n'existe (Sprint Change Proposal 2026-05-19).
# ===================================================================
# Etapes :
#   1. Idempotence : exit si /var/lib/sambaedu/migrated existe
#   2. Decode & install CA root local (update-ca-certificates)
#   3. Collecte UUID / MAC / hostname locaux
#   4. POST /api/v1/agent/enroll (HTTPS strict, sans skip-TLS)
#   5. Stockage tokens dans /var/lib/sambaedu/auth.json (0600 root:root)
#   6. Ecriture /etc/sambaedu/endpoints.conf pour pointer les futurs
#      appels vers /api/v1/workstation-config/*
#   7. Marquage /var/lib/sambaedu/migrated (touch)
#   8. systemd timer refresh 25j (parite 16.11)
#   9. wall + (sleep 30 && /sbin/shutdown -r now) &  (D14 — Henri 30s)
# ===================================================================
set -e

# --- 1. Idempotence ---
if [ -f /var/lib/sambaedu/migrated ]; then
    echo "[SambaEdu] Poste deja migre. No-op."
    exit 0
fi

# --- 2. Configuration ---
SERVER_BASE_URL="{!! $server_base_url !!}"
ENROLL_ENDPOINT="{!! $enroll_endpoint !!}"
REFRESH_ENDPOINT="{!! $refresh_endpoint !!}"
WORKSTATION_CONFIG_BASE="{!! $workstation_config_base !!}"
CA_CERT_B64="{!! $ca_cert_pem_b64 !!}"
# Story 16.13bis — Correction Q1 Option A (2026-05-20) : BOOTSTRAP_TOKEN
# minté côté serveur (clé APCu apps.<token>, TTL 1800s, parité 16.11).
# Token éphémère 32 chars hex — validé par RequireBootstrapToken à l'enroll.
export BOOTSTRAP_TOKEN="{{ $bootstrap_token }}"

# --- 3. Decode & install CA root local ---
CA_TMP="/tmp/sambaedu-ca-root.crt"
echo "$CA_CERT_B64" | base64 -d > "$CA_TMP" 2>/dev/null || {
    echo "[SambaEdu] Echec decodage CA root. Migration annulee."
    exit 1
}
if [ ! -s "$CA_TMP" ]; then
    echo "[SambaEdu] CA root vide. Migration annulee."
    exit 1
fi

cp "$CA_TMP" /usr/local/share/ca-certificates/sambaedu-ca.crt
update-ca-certificates >/dev/null 2>&1 || {
    echo "[SambaEdu] Echec installation CA root (update-ca-certificates)."
    exit 1
}

# --- 4. Collecte metadata machine ---
MACHINE_UUID="$(cat /sys/class/dmi/id/product_uuid 2>/dev/null | tr 'A-Z' 'a-z' || echo '')"
MACHINE_MAC="$(ip -br link 2>/dev/null | awk '$1!="lo" && $2=="UP" {print $3; exit}')"
MACHINE_HOSTNAME="$(hostname -f 2>/dev/null || hostname)"

# --- 5. POST /api/v1/agent/enroll (HTTPS strict, sans -k) ---
# Le BOOTSTRAP_TOKEN est `export` plus haut (cf. Story 16.13bis Correction
# Q1 Option A). curl le récupère via `${BOOTSTRAP_TOKEN:-}`.
PAYLOAD=$(printf '{"uuid":"%s","mac":"%s","hostname":"%s","os":"linux"}' \
    "$MACHINE_UUID" "$MACHINE_MAC" "$MACHINE_HOSTNAME")

ENROLL_RESP=$(curl -fsS -X POST \
    -H "Content-Type: application/json" \
    -H "X-Bootstrap-Token: ${BOOTSTRAP_TOKEN:-}" \
    -d "$PAYLOAD" \
    "$ENROLL_ENDPOINT") || {
    echo "[SambaEdu] Enrollment echoue (curl). Migration annulee."
    exit 1
}

# Parse JSON via jq (fallback python3 si jq absent — pattern iso 16.11)
if command -v jq >/dev/null 2>&1; then
    ACCESS_TOKEN=$(echo "$ENROLL_RESP" | jq -r '.access_token')
    REFRESH_TOKEN=$(echo "$ENROLL_RESP" | jq -r '.refresh_token')
    EXPIRES_AT=$(echo "$ENROLL_RESP" | jq -r '.expires_at // empty')
elif command -v python3 >/dev/null 2>&1; then
    ACCESS_TOKEN=$(echo "$ENROLL_RESP" | python3 -c "import json,sys;print(json.load(sys.stdin).get('access_token',''))")
    REFRESH_TOKEN=$(echo "$ENROLL_RESP" | python3 -c "import json,sys;print(json.load(sys.stdin).get('refresh_token',''))")
    EXPIRES_AT=$(echo "$ENROLL_RESP" | python3 -c "import json,sys;print(json.load(sys.stdin).get('expires_at',''))")
else
    echo "[SambaEdu] Ni jq ni python3 disponible — impossible de parser la reponse enroll."
    exit 1
fi

if [ -z "$ACCESS_TOKEN" ] || [ "$ACCESS_TOKEN" = "null" ]; then
    echo "[SambaEdu] Reponse enroll invalide (access_token absent)."
    exit 1
fi

# --- 6. Ecriture /var/lib/sambaedu/auth.json (0600 root:root) ---
mkdir -p /var/lib/sambaedu
cat > /var/lib/sambaedu/auth.json <<EOF
{"access_token":"$ACCESS_TOKEN","refresh_token":"$REFRESH_TOKEN","expires_at":"$EXPIRES_AT","server_base_url":"$SERVER_BASE_URL","workstation_config_base":"$WORKSTATION_CONFIG_BASE"}
EOF
chmod 0600 /var/lib/sambaedu/auth.json
chown root:root /var/lib/sambaedu/auth.json

# --- 7. Ecriture /etc/sambaedu/endpoints.conf ---
# Pivot par fichier conf (D6) : les futurs scripts logon Linux liront ce
# fichier pour pointer vers /api/v1/workstation-config/* au lieu de
# /sambaedu/gpo/*_out.php legacy.
mkdir -p /etc/sambaedu
cat > /etc/sambaedu/endpoints.conf <<EOF
WALLPAPER_URL=$WORKSTATION_CONFIG_BASE/wallpaper
FIREFOX_URL=$WORKSTATION_CONFIG_BASE/firefox
THUNDERBIRD_URL=$WORKSTATION_CONFIG_BASE/thunderbird
SHORTCUTS_URL=$WORKSTATION_CONFIG_BASE/shortcuts
NETWORK_URL=$WORKSTATION_CONFIG_BASE/network
VEYON_URL=$WORKSTATION_CONFIG_BASE/veyon
ASSOCIATIONS_URL=$WORKSTATION_CONFIG_BASE/associations
APPLICATIONS_SCRIPTS_URL=$WORKSTATION_CONFIG_BASE/applications-scripts
EOF
chmod 0644 /etc/sambaedu/endpoints.conf

# --- 8. systemd timer refresh tokens 25j (parite 16.11) ---
# Best-effort : si systemd absent (cas chroot ou container test), on saute.
if command -v systemctl >/dev/null 2>&1 && [ -d /etc/systemd/system ]; then
    cat > /etc/systemd/system/sambaedu-refresh.service <<'EOF'
[Unit]
Description=SambaEdu refresh JWT tokens (Story 16.13bis)
After=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/sambaedu-refresh.sh
EOF

    cat > /etc/systemd/system/sambaedu-refresh.timer <<'EOF'
[Unit]
Description=SambaEdu refresh JWT tokens daily

[Timer]
OnCalendar=daily
RandomizedDelaySec=1h
Persistent=true

[Install]
WantedBy=timers.target
EOF

    cat > /usr/local/sbin/sambaedu-refresh.sh <<'SHEOF'
#!/bin/bash
set -e
[ -f /var/lib/sambaedu/auth.json ] || exit 0
REFRESH_TOKEN=$(jq -r '.refresh_token' /var/lib/sambaedu/auth.json 2>/dev/null || echo '')
REFRESH_ENDPOINT=$(jq -r '.server_base_url' /var/lib/sambaedu/auth.json 2>/dev/null)/api/v1/agent/refresh
[ -z "$REFRESH_TOKEN" ] && exit 0
RESP=$(curl -fsS -X POST -H "Content-Type: application/json" \
    -d "{\"refresh_token\":\"$REFRESH_TOKEN\"}" "$REFRESH_ENDPOINT") || exit 1
NEW_ACCESS=$(echo "$RESP" | jq -r '.access_token')
NEW_REFRESH=$(echo "$RESP" | jq -r '.refresh_token')
TMP=$(mktemp)
jq --arg a "$NEW_ACCESS" --arg r "$NEW_REFRESH" \
    '.access_token=$a | .refresh_token=$r' /var/lib/sambaedu/auth.json > "$TMP"
mv "$TMP" /var/lib/sambaedu/auth.json
chmod 0600 /var/lib/sambaedu/auth.json
chown root:root /var/lib/sambaedu/auth.json
SHEOF
    chmod 0755 /usr/local/sbin/sambaedu-refresh.sh
    systemctl daemon-reload >/dev/null 2>&1 || true
    systemctl enable --now sambaedu-refresh.timer >/dev/null 2>&1 || true
fi

# --- 9. Marquage migré (touch — avant-dernier step pour idempotence R4) ---
touch /var/lib/sambaedu/migrated

rm -f "$CA_TMP"

# --- 10. wall + reboot 30s (D14 — confirme Henri 2026-05-20) ---
# `wall` broadcast a tous les TTYs connectes, `shutdown` planifie le reboot.
# Pourquoi `(sleep 30 && /sbin/shutdown -r now) &` plutot que `shutdown -r +1` :
# granularite 30s exact iso Windows `shutdown /r /t 30` (vs 60s mini avec +1).
echo "{!! $migration_message_fr !!}" | wall 2>/dev/null || true
echo "{!! $migration_message_fr !!}"
(sleep 30 && /sbin/shutdown -r now) &
exit 0
