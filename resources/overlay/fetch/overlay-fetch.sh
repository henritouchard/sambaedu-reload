#!/bin/sh
# Adaptateur FETCH (Linux) du POC overlay SambaEdu.
#
# Poll authentifié de GET /api/v1/workstation-config/overlay puis écriture
# atomique dans overlay.json local. Seul composant porteur du JWT workstation ;
# l'adaptateur render (Conky) ne lit que le fichier local.
#
# Single-shot : cadence pilotée par un timer systemd / autostart (toutes les
# ttl_seconds, défaut 60 s). Cf. resources/overlay/README.md.
#
# POC — non testé sur poste réel. SE_TOKEN_PATH est un TODO : à brancher sur le
# store réel du token workstation (enroll/refresh 16.10).

set -eu

# TODO: résoudre l'hôte SE4FS réel + le store du token.
BASE_URL="${SE_OVERLAY_URL:-https://${SE4FS:-se4fs}/api/v1/workstation-config/overlay}"
TOKEN_PATH="${SE_TOKEN_PATH:-/etc/sambaedu/workstation.jwt}"
OUT_PATH="${SE_OVERLAY_OUT:-/run/sambaedu/overlay.json}"
USER_LOGIN="${SE_USER:-$(id -un)}"
OS="linux"

if [ ! -r "$TOKEN_PATH" ]; then
    echo "[overlay-fetch] token workstation introuvable: $TOKEN_PATH" >&2
    exit 1
fi
TOKEN="$(tr -d '\r\n' < "$TOKEN_PATH")"

OUT_DIR="$(dirname "$OUT_PATH")"
mkdir -p "$OUT_DIR"
# Le répertoire doit être traversable par la session user (Conky lit en user).
chmod 0755 "$OUT_DIR" 2>/dev/null || true
TMP="$(mktemp "${OUT_DIR}/overlay.XXXXXX.json")"
# Nettoyage du temporaire en cas d'échec.
trap 'rm -f "$TMP"' EXIT

# -f : échoue sur HTTP >= 400 (ne pas écraser le dernier overlay.json valide).
if ! curl -fsS --max-time 10 \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Accept: application/json" \
        "${BASE_URL}?os=${OS}&user=${USER_LOGIN}" \
        -o "$TMP"; then
    echo "[overlay-fetch] poll échoué" >&2
    exit 1
fi

# Validation minimale : schéma attendu (jq si dispo, sinon fallback grep —
# review finding H : ne jamais accepter un corps non-overlay si jq manque).
if command -v jq >/dev/null 2>&1; then
    schema="$(jq -r '.schema // empty' "$TMP" 2>/dev/null || true)"
    case "$schema" in
        se5.wallpaper-overlay/*) : ;;
        *) echo "[overlay-fetch] réponse inattendue (schema)" >&2; exit 1 ;;
    esac
elif ! grep -q '"schema"[[:space:]]*:[[:space:]]*"se5\.wallpaper-overlay/' "$TMP"; then
    echo "[overlay-fetch] réponse inattendue (schema, jq absent)" >&2
    exit 1
fi

# Le fichier doit être lisible par la session user (Conky) — review finding B :
# mktemp crée en 0600, sinon Conky (user) ne peut pas lire overlay.json.
chmod 0644 "$TMP"

# Remplacement atomique.
mv -f "$TMP" "$OUT_PATH"
trap - EXIT
exit 0
