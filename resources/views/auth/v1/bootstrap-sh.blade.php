#!/bin/bash
# ===================================================================
# SambaEdu auto-bootstrap Linux (Story 16.11) — idempotent
#   - Vérifie l'état migré local (/var/lib/sambaedu/migrated)
#   - Installe le CA root local via update-ca-certificates
#   - POST /api/v1/agent/enroll avec UUID/MAC/hostname locaux
#   - Stocke tokens dans /var/lib/sambaedu/auth.json (0600 root:root)
#   - Dépose /usr/local/lib/sambaedu/sambaedu-refresh.sh local + timer systemd
# ===================================================================

set -e

# --- Idempotence ---
if [ -f /var/lib/sambaedu/migrated ]; then
    echo "[SambaEdu] Already migrated. Exiting."
    exit 0
fi

# --- Configuration ---
SERVER_BASE_URL="{!! $server_base_url !!}"
ENROLL_ENDPOINT="{!! $enroll_endpoint !!}"
REFRESH_ENDPOINT="{!! $refresh_endpoint !!}"
PING_ENDPOINT="{!! $ping_endpoint !!}"
CA_CERT_B64="{!! $ca_cert_pem_b64 !!}"
REFRESH_SCRIPT="/usr/local/lib/sambaedu/sambaedu-refresh.sh"
AUTH_JSON="/var/lib/sambaedu/auth.json"

# --- Prérequis : doit être root ---
if [ "$(id -u)" -ne 0 ]; then
    echo "[SambaEdu] Must be run as root. Aborting." >&2
    exit 1
fi

# --- Installation CA root ---
CA_TMP="$(mktemp /tmp/sambaedu-ca-XXXXXX.crt)"
echo "$CA_CERT_B64" | base64 -d > "$CA_TMP" 2>/dev/null || {
    echo "[SambaEdu] Failed to decode CA cert. Aborting." >&2
    exit 1
}

mkdir -p /usr/local/share/ca-certificates
cp "$CA_TMP" /usr/local/share/ca-certificates/sambaedu-ca.crt
chmod 0644 /usr/local/share/ca-certificates/sambaedu-ca.crt
update-ca-certificates >/dev/null 2>&1 || {
    echo "[SambaEdu] Failed to update-ca-certificates. Aborting." >&2
    rm -f "$CA_TMP"
    exit 1
}
rm -f "$CA_TMP"

# --- Récupération métadonnées machine ---
MACHINE_UUID="$(cat /sys/class/dmi/id/product_uuid 2>/dev/null || echo '')"
if [ -z "$MACHINE_UUID" ]; then
    MACHINE_UUID="$(cat /etc/machine-id 2>/dev/null | head -c 32 || echo '')"
fi

# MAC de la première interface UP non-loopback.
MACHINE_MAC="$(ip -br link 2>/dev/null | awk '$1!="lo" && $2=="UP" {print $3; exit}')"
[ -z "$MACHINE_MAC" ] && MACHINE_MAC="$(ip link 2>/dev/null | awk '/ether/ {print $2; exit}')"

MACHINE_HOSTNAME="$(hostname -f 2>/dev/null || hostname)"

# --- POST enroll ---
mkdir -p /var/lib/sambaedu
chmod 0700 /var/lib/sambaedu
chown root:root /var/lib/sambaedu

# Story 16.11 Q1.b — Le BOOTSTRAP_TOKEN est exporté en env var par le fragment.
# Le fragment télécharge ce script complet via le middleware InjectBootstrapFragment
# qui pose un contexte APCu apps.<token> avec uuid matching avant injection.

PAYLOAD=$(printf '{"uuid":"%s","mac":"%s","hostname":"%s","os":"linux"}' \
    "$MACHINE_UUID" "$MACHINE_MAC" "$MACHINE_HOSTNAME")

RESPONSE_FILE="$(mktemp /tmp/sambaedu-enroll-XXXXXX.json)"

HTTP_CODE=$(curl -sS -o "$RESPONSE_FILE" -w "%{http_code}" \
    -X POST "$ENROLL_ENDPOINT" \
    -H "Content-Type: application/json" \
    -H "X-Bootstrap-Token: ${BOOTSTRAP_TOKEN:-}" \
    --data "$PAYLOAD" || echo "000")

if [ "$HTTP_CODE" != "200" ]; then
    echo "[SambaEdu] Enroll failed (HTTP $HTTP_CODE)." >&2
    cat "$RESPONSE_FILE" >&2 || true
    rm -f "$RESPONSE_FILE"
    exit 1
fi

# Parse JSON via jq (fallback python3 si jq absent).
if command -v jq >/dev/null 2>&1; then
    ACCESS_TOKEN="$(jq -r '.access_token' "$RESPONSE_FILE")"
    REFRESH_TOKEN="$(jq -r '.refresh_token' "$RESPONSE_FILE")"
    EXPIRES_IN="$(jq -r '.expires_in' "$RESPONSE_FILE")"
elif command -v python3 >/dev/null 2>&1; then
    ACCESS_TOKEN="$(python3 -c 'import json,sys;print(json.load(sys.stdin).get("access_token",""))' < "$RESPONSE_FILE")"
    REFRESH_TOKEN="$(python3 -c 'import json,sys;print(json.load(sys.stdin).get("refresh_token",""))' < "$RESPONSE_FILE")"
    EXPIRES_IN="$(python3 -c 'import json,sys;print(json.load(sys.stdin).get("expires_in",""))' < "$RESPONSE_FILE")"
else
    echo "[SambaEdu] Neither jq nor python3 found. Cannot parse enroll response." >&2
    rm -f "$RESPONSE_FILE"
    exit 1
fi

rm -f "$RESPONSE_FILE"

# --- Stockage tokens (auth.json 0600 root:root) ---
EXPIRES_AT="$(date -d "+${EXPIRES_IN:-3600} seconds" -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date -u '+%Y-%m-%dT%H:%M:%SZ')"

cat > "$AUTH_JSON" <<EOF
{
  "access_token": "$ACCESS_TOKEN",
  "refresh_token": "$REFRESH_TOKEN",
  "expires_at": "$EXPIRES_AT",
  "server_base_url": "$SERVER_BASE_URL",
  "ca_cert_path": "/usr/local/share/ca-certificates/sambaedu-ca.crt"
}
EOF

chmod 0600 "$AUTH_JSON"
chown root:root "$AUTH_JSON"

# ===================================================================
# SECTION 6 : déposer le script refresh local (sambaedu-refresh.sh)
# ===================================================================
# Story 16.11 Q1.c — Le script refresh local lit le refresh_token depuis
# /var/lib/sambaedu/auth.json (via jq), POST /refresh avec body JSON,
# puis ré-écrit auth.json avec les nouveaux access+refresh tokens.
# Invocation : le timer systemd invoque CE script local (pas un curl direct).
# ===================================================================

mkdir -p /usr/local/lib/sambaedu
chmod 0700 /usr/local/lib/sambaedu
chown root:root /usr/local/lib/sambaedu

cat > "$REFRESH_SCRIPT" <<'REFRESH_EOF'
#!/bin/bash
# === SambaEdu refresh tokens (Story 16.11 Q1.c) ===
set -e

AUTH_JSON="/var/lib/sambaedu/auth.json"

if [ ! -f "$AUTH_JSON" ]; then
    echo "[SambaEdu] auth.json missing — cannot refresh." >&2
    exit 1
fi

# Lecture du refresh_token actuel.
if command -v jq >/dev/null 2>&1; then
    REFRESH_TOKEN="$(jq -r '.refresh_token' "$AUTH_JSON")"
    SERVER_BASE_URL="$(jq -r '.server_base_url' "$AUTH_JSON")"
    CA_PATH="$(jq -r '.ca_cert_path' "$AUTH_JSON")"
elif command -v python3 >/dev/null 2>&1; then
    REFRESH_TOKEN="$(python3 -c 'import json;print(json.load(open("/var/lib/sambaedu/auth.json")).get("refresh_token",""))')"
    SERVER_BASE_URL="$(python3 -c 'import json;print(json.load(open("/var/lib/sambaedu/auth.json")).get("server_base_url",""))')"
    CA_PATH="$(python3 -c 'import json;print(json.load(open("/var/lib/sambaedu/auth.json")).get("ca_cert_path",""))')"
else
    echo "[SambaEdu] Neither jq nor python3 found. Cannot parse auth.json." >&2
    exit 1
fi

if [ -z "$REFRESH_TOKEN" ] || [ -z "$SERVER_BASE_URL" ]; then
    echo "[SambaEdu] refresh_token or server_base_url missing in auth.json." >&2
    exit 1
fi

PAYLOAD=$(printf '{"refresh_token":"%s"}' "$REFRESH_TOKEN")
RESPONSE_FILE="$(mktemp /tmp/sambaedu-refresh-XXXXXX.json)"

CURL_CA_OPT=""
if [ -n "$CA_PATH" ] && [ -f "$CA_PATH" ]; then
    CURL_CA_OPT="--cacert $CA_PATH"
fi

HTTP_CODE=$(curl -sS $CURL_CA_OPT -o "$RESPONSE_FILE" -w "%{http_code}" \
    -X POST "$SERVER_BASE_URL/api/v1/agent/refresh" \
    -H "Content-Type: application/json" \
    --data "$PAYLOAD" || echo "000")

if [ "$HTTP_CODE" != "200" ]; then
    echo "[SambaEdu] Refresh failed (HTTP $HTTP_CODE)." >&2
    cat "$RESPONSE_FILE" >&2 || true
    rm -f "$RESPONSE_FILE"
    exit 1
fi

# Parse nouveaux tokens + écriture atomique auth.json.
if command -v jq >/dev/null 2>&1; then
    NEW_ACCESS="$(jq -r '.access_token' "$RESPONSE_FILE")"
    NEW_REFRESH="$(jq -r '.refresh_token' "$RESPONSE_FILE")"
    NEW_EXPIRES_IN="$(jq -r '.expires_in' "$RESPONSE_FILE")"
else
    NEW_ACCESS="$(python3 -c 'import json,sys;print(json.load(sys.stdin).get("access_token",""))' < "$RESPONSE_FILE")"
    NEW_REFRESH="$(python3 -c 'import json,sys;print(json.load(sys.stdin).get("refresh_token",""))' < "$RESPONSE_FILE")"
    NEW_EXPIRES_IN="$(python3 -c 'import json,sys;print(json.load(sys.stdin).get("expires_in",""))' < "$RESPONSE_FILE")"
fi

rm -f "$RESPONSE_FILE"

NEW_EXPIRES_AT="$(date -d "+${NEW_EXPIRES_IN:-3600} seconds" -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date -u '+%Y-%m-%dT%H:%M:%SZ')"

TMP_AUTH="$(mktemp "${AUTH_JSON}.XXXXXX")"
cat > "$TMP_AUTH" <<EOF2
{
  "access_token": "$NEW_ACCESS",
  "refresh_token": "$NEW_REFRESH",
  "expires_at": "$NEW_EXPIRES_AT",
  "server_base_url": "$SERVER_BASE_URL",
  "ca_cert_path": "$CA_PATH",
  "refreshed_at": "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
}
EOF2

chmod 0600 "$TMP_AUTH"
chown root:root "$TMP_AUTH"
mv -f "$TMP_AUTH" "$AUTH_JSON"

echo "[SambaEdu] Tokens refreshed."
exit 0
REFRESH_EOF

chmod 0700 "$REFRESH_SCRIPT"
chown root:root "$REFRESH_SCRIPT"

# --- Création timer systemd refresh (25j → daily 03:00) ---
cat > /etc/systemd/system/sambaedu-refresh.service <<EOF
[Unit]
Description=SambaEdu Auth V1 refresh tokens
After=network-online.target

[Service]
Type=oneshot
ExecStart=$REFRESH_SCRIPT
User=root
EOF

cat > /etc/systemd/system/sambaedu-refresh.timer <<EOF
[Unit]
Description=SambaEdu Auth V1 refresh timer

[Timer]
OnCalendar=*-*-* 03:00:00
Persistent=true

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload 2>/dev/null || true
systemctl enable sambaedu-refresh.timer 2>/dev/null || true
systemctl start sambaedu-refresh.timer 2>/dev/null || true

touch /var/lib/sambaedu/migrated
chmod 0644 /var/lib/sambaedu/migrated

echo "[SambaEdu] Bootstrap complete."
exit 0
