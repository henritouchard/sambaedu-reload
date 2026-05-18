#!/usr/bin/env bash
# ============================================================================
# scripts/run-tests.sh — Story 16.8 (Stabilisation Phase 1)
# ----------------------------------------------------------------------------
# Lance la suite Pest/PHPUnit (php artisan test) avec capture log + summary
# JSON, code de retour 0 si tout passe, 1 sinon.
#
# Usage :
#   bash scripts/run-tests.sh                  # suite complète
#   bash scripts/run-tests.sh --phase1-only    # uniquement les chemins Phase 1
#                                              # (Architecture + Unit/Gpo
#                                              #  + Unit/Ldap + Feature/Gpo)
#
# Cible : exécution sur la VM iso-prod
#   ssh /vm 'cd /var/www/sambaedu-reload && bash scripts/run-tests.sh ...'
#   (les tests utilisent SQLite :memory: + shims `LEGACY_SKIP_LEGACY_INCLUDES`,
#    pas besoin de Postgres/Samba AD réels — cf. phpunit.xml + tests/bootstrap.php)
#
# Sorties :
#   storage/logs/tests/run-<TS>.log         # stdout/stderr complet horodaté
#   storage/logs/tests/last-run-summary.json # résumé synthétique exit code/totaux
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
cd "$APP_DIR"

PHASE1_ONLY=0
EXTRA_ARGS=()
for arg in "$@"; do
    case "$arg" in
        --phase1-only) PHASE1_ONLY=1 ;;
        *) EXTRA_ARGS+=("$arg") ;;
    esac
done

RUN_ID="$(date +%Y-%m-%dT%H-%M-%S)"
LOG_DIR="storage/logs/tests"
LOG_FILE="$LOG_DIR/run-$RUN_ID.log"
SUMMARY_FILE="$LOG_DIR/last-run-summary.json"
mkdir -p "$LOG_DIR"

PHASE1_PATHS=(tests/Architecture tests/Unit/Gpo tests/Unit/Ldap tests/Feature/Gpo)

TEST_ARGS=()
if [[ "$PHASE1_ONLY" -eq 1 ]]; then
    TEST_ARGS+=("${PHASE1_PATHS[@]}")
    SCOPE="phase1"
else
    SCOPE="full"
fi
TEST_ARGS+=("${EXTRA_ARGS[@]}")

echo "============================================================"
echo " run-tests.sh — Story 16.8"
echo " RUN_ID    = $RUN_ID"
echo " SCOPE     = $SCOPE"
echo " LOG_FILE  = $LOG_FILE"
echo " SUMMARY   = $SUMMARY_FILE"
echo " ARGS      = ${TEST_ARGS[*]:-<none>}"
echo " PWD       = $APP_DIR"
echo " PHP       = $(php -v | head -1)"
echo "============================================================"

START_EPOCH=$(date +%s)
set +e
php artisan test "${TEST_ARGS[@]}" 2>&1 | tee "$LOG_FILE"
EXIT_CODE=${PIPESTATUS[0]:-0}
TEE_CODE=${PIPESTATUS[1]:-0}
set -e
[[ "$TEE_CODE" -ne 0 ]] && echo "WARN: tee failed (code $TEE_CODE) — log peut être tronqué : $LOG_FILE" >&2
END_EPOCH=$(date +%s)
DURATION=$((END_EPOCH - START_EPOCH))

# ---------------------------------------------------------------------------
# Parsing résumé. Le rendu Collision (php artisan test) imprime en fin de run
# une ligne du type :
#   Tests:    1 failed, 2 skipped, 96 passed (291 assertions)
#   Duration: 8.94s
# On lit les ~30 dernières lignes du log et on extrait passed/failed/errors/
# skipped/risky.
# ---------------------------------------------------------------------------
extract_n() {
    # $1 = libellé (passed, failed, errors, skipped, risky, deprecated, notices, warnings)
    # Cherche "<N> <label>" insensible casse dans le tail.
    local label="$1"
    local n
    n=$(tail -40 "$LOG_FILE" | sed 's/\x1b\[[0-9;]*m//g' | grep -oE "[0-9]+ ${label}" | tail -1 | awk '{print $1}' || true)
    echo "${n:-0}"
}

PASSED=$(extract_n passed)
FAILED=$(extract_n failed)
ERRORS=$(extract_n errors)
SKIPPED=$(extract_n skipped)
RISKY=$(extract_n risky)
DEPRECATED=$(extract_n deprecated)
NOTICES=$(extract_n notices)
WARNINGS=$(extract_n warnings)

# Le total agrégé doit refléter la réalité ; à défaut, somme des composantes.
TOTAL=$((PASSED + FAILED + ERRORS + SKIPPED + RISKY))

# Échapper les guillemets et backslashes pour JSON valide.
ARGS_JSON="$(printf '%s' "${TEST_ARGS[*]:-}" | sed 's/\\/\\\\/g; s/"/\\"/g')"

cat > "$SUMMARY_FILE" <<EOF
{
  "run_id": "$RUN_ID",
  "scope": "$SCOPE",
  "args": "$ARGS_JSON",
  "log_file": "$LOG_FILE",
  "exit_code": $EXIT_CODE,
  "duration_seconds": $DURATION,
  "passed": $PASSED,
  "failed": $FAILED,
  "errors": $ERRORS,
  "skipped": $SKIPPED,
  "risky": $RISKY,
  "total": $TOTAL,
  "deprecated": $DEPRECATED,
  "notices": $NOTICES,
  "warnings": $WARNINGS
}
EOF

echo
echo "============================================================"
echo " Résumé (run $RUN_ID) — scope=$SCOPE — exit=$EXIT_CODE — ${DURATION}s"
echo "   passed=$PASSED failed=$FAILED errors=$ERRORS skipped=$SKIPPED risky=$RISKY"
echo "   deprecated=$DEPRECATED notices=$NOTICES warnings=$WARNINGS"
echo "   total (somme)=$TOTAL"
echo "   log     = $LOG_FILE"
echo "   summary = $SUMMARY_FILE"
echo "============================================================"

exit "$EXIT_CODE"
