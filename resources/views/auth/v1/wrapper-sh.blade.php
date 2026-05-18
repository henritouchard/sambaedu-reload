@verbatim
#!/bin/bash
# === SambaEdu script-execution-logs wrapper (Story 16.12 — Linux) ===
# Emballe un script user (managed 17.x ou GPO legacy) pour capturer
# stdout/stderr/exit_code/duration + POST sur /api/v1/script-execution-logs.
# Token lu depuis /var/lib/sambaedu/auth.json (mode 0600 iso 16.11 D11).
# Pas de `set -e` : on veut TOUJOURS POST le résultat même si le script
# user échoue.
set +e
@endverbatim

# Variables injectées par WrapperScriptRenderer côté serveur :
CORR='{{ $correlation_id }}'
ENDPOINT='{{ $endpoint_url }}'
ACTION='{{ $action }}'
OS_TAG='{{ $os }}'
SOURCE_TAG='{{ $source }}'
@if ($script_id !== null)
SCRIPT_ID='{{ (int) $script_id }}'
@else
SCRIPT_ID=''
@endif
SCRIPT_B64='{{ $script_content_b64 }}'

@verbatim
# 1. Décodage du script user (base64 → fichier .sh temporaire chmod 700).
SCRIPT_FILE="$(mktemp /tmp/sambaedu-script-${CORR}.XXXX.sh)"
STDOUT_FILE="/tmp/sambaedu-stdout-${CORR}.log"
STDERR_FILE="/tmp/sambaedu-stderr-${CORR}.log"
printf '%s' "$SCRIPT_B64" | base64 -d > "$SCRIPT_FILE" 2>/dev/null || { echo '[sambaedu-wrapper] base64 decode failed' >&2; rm -f "$SCRIPT_FILE"; exit 1; }
chmod 700 "$SCRIPT_FILE"

# 2. Timestamp démarrage (ISO 8601 UTC + ns pour calcul durée).
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
STARTED_NS="$(date +%s%N)"

# 3. Exécution du script user.
bash "$SCRIPT_FILE" > "$STDOUT_FILE" 2> "$STDERR_FILE"
EXIT_CODE=$?

# 4. Calcul durée en ms.
FINISHED_NS="$(date +%s%N)"
DURATION_MS=$(( (FINISHED_NS - STARTED_NS) / 1000000 ))
if [ "$DURATION_MS" -lt 0 ]; then DURATION_MS=0; fi

# 5. Status applicatif.
if [ "$EXIT_CODE" -eq 0 ]; then
    STATUS='success'
elif [ "$EXIT_CODE" -eq 124 ]; then
    STATUS='timeout'
else
    STATUS='failure'
fi

# 6. Lecture stdout/stderr (head 4 KB + tail 4 KB max via cut byte-safe).
read_excerpt() {
    local path="$1"
    if [ ! -s "$path" ]; then echo ''; return; fi
    local size; size=$(wc -c < "$path" 2>/dev/null || echo 0)
    if [ "$size" -le 8192 ]; then
        cat "$path"
    else
        head -c 4000 "$path"
        printf '\n[...truncated]\n'
        tail -c 4000 "$path"
    fi
}
STDOUT_EXCERPT="$(read_excerpt "$STDOUT_FILE")"
STDERR_EXCERPT="$(read_excerpt "$STDERR_FILE")"

# 7. Lecture token (jq prioritaire, fallback python3).
AUTH_FILE='/var/lib/sambaedu/auth.json'
TOKEN=''
if [ -r "$AUTH_FILE" ]; then
    if command -v jq >/dev/null 2>&1; then
        TOKEN="$(jq -r '.access_token // empty' "$AUTH_FILE" 2>/dev/null)"
    elif command -v python3 >/dev/null 2>&1; then
        TOKEN="$(python3 -c "import json,sys
try:
  print(json.load(open('$AUTH_FILE')).get('access_token',''))
except Exception:
  pass" 2>/dev/null)"
    fi
fi

if [ -z "$TOKEN" ]; then
    echo "[sambaedu-wrapper] auth token unreadable ($AUTH_FILE) — skip POST" >&2
    rm -f "$SCRIPT_FILE" "$STDOUT_FILE" "$STDERR_FILE"
    exit "$EXIT_CODE"
fi

# 8. Construction body JSON via jq (escape robuste). Fallback python3.
build_body_jq() {
    local sid_arg
    if [ -n "$SCRIPT_ID" ]; then sid_arg="$SCRIPT_ID"; else sid_arg='null'; fi
    jq -n \
        --argjson script_id "$sid_arg" \
        --arg script_source "$SOURCE_TAG" \
        --arg action "$ACTION" \
        --arg os "$OS_TAG" \
        --arg status "$STATUS" \
        --argjson exit_code "$EXIT_CODE" \
        --arg stdout "$STDOUT_EXCERPT" \
        --arg stderr "$STDERR_EXCERPT" \
        --arg started_at "$STARTED_AT" \
        --argjson duration_ms "$DURATION_MS" \
        --arg correlation_id "$CORR" \
        '{script_id: $script_id, script_source: $script_source, action: $action, os: $os, status: $status, exit_code: $exit_code, stdout: $stdout, stderr: $stderr, started_at: $started_at, duration_ms: $duration_ms, correlation_id: $correlation_id}'
}

build_body_py() {
    SCRIPT_ID_PY="$SCRIPT_ID" \
    SOURCE_TAG_PY="$SOURCE_TAG" ACTION_PY="$ACTION" OS_PY="$OS_TAG" \
    STATUS_PY="$STATUS" EXIT_CODE_PY="$EXIT_CODE" \
    STDOUT_PY="$STDOUT_EXCERPT" STDERR_PY="$STDERR_EXCERPT" \
    STARTED_PY="$STARTED_AT" DURATION_PY="$DURATION_MS" CORR_PY="$CORR" \
    python3 -c "import json,os
sid_raw = os.environ.get('SCRIPT_ID_PY','')
sid = int(sid_raw) if sid_raw else None
print(json.dumps({
  'script_id': sid,
  'script_source': os.environ['SOURCE_TAG_PY'],
  'action': os.environ['ACTION_PY'],
  'os': os.environ['OS_PY'],
  'status': os.environ['STATUS_PY'],
  'exit_code': int(os.environ['EXIT_CODE_PY']),
  'stdout': os.environ['STDOUT_PY'],
  'stderr': os.environ['STDERR_PY'],
  'started_at': os.environ['STARTED_PY'],
  'duration_ms': int(os.environ['DURATION_PY']),
  'correlation_id': os.environ['CORR_PY']
}, separators=(',',':')))"
}

if command -v jq >/dev/null 2>&1; then
    BODY="$(build_body_jq)"
else
    BODY="$(build_body_py)"
fi

# 9. POST avec retry exponentiel (1-2-3 → sleep 2,5,10s).
SUCCESS=0
for attempt in 1 2 3; do
    if curl -fsS --max-time 10 -X POST "$ENDPOINT" \
        -H "Authorization: Bearer $TOKEN" \
        -H 'Content-Type: application/json' \
        --data "$BODY" >/dev/null 2>&1; then
        SUCCESS=1
        break
    fi
    sleep $((attempt * 2))
done

if [ "$SUCCESS" -eq 0 ]; then
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] POST failed after 3 attempts — correlation=$CORR" \
        >> /tmp/sambaedu-wrapper-retry.log 2>/dev/null
fi

# 10. Cleanup fichiers temp.
rm -f "$SCRIPT_FILE" "$STDOUT_FILE" "$STDERR_FILE" >/dev/null 2>&1

exit "$EXIT_CODE"
@endverbatim
