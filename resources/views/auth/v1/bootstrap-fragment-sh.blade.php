# === SambaEdu auto-bootstrap (Story 16.11) — idempotent ===
if [ ! -f /var/lib/sambaedu/auth.json ] && [ ! -f /var/lib/sambaedu/migrated ] && [ ! -f /tmp/sambaedu-bootstrap-running.flag ]; then
    touch /tmp/sambaedu-bootstrap-running.flag 2>/dev/null || true
    # Q1.b — token md5 frais posé en APCu par middleware InjectBootstrapFragment, transmis au script complet via env var exportée.
    export BOOTSTRAP_TOKEN="{!! $bootstrap_token_placeholder !!}"
    curl -kfsS "{!! $server_base_url !!}/api/v1/agent/bootstrap.sh" 2>/dev/null | bash 2>/dev/null || true
    unset BOOTSTRAP_TOKEN
    rm -f /tmp/sambaedu-bootstrap-running.flag 2>/dev/null || true
fi
# === Fin auto-bootstrap ===

