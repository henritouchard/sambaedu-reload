#!/bin/bash
# ============================================================================
# SambaEdu — Vérification/réparation des permissions du partage [install]
# ============================================================================
# Les postes fetchent leurs assets d'installation et de post-install sur le
# partage Samba `[install]` (= /var/sambaedu/unattended/install). Deux contextes
# d'accès DIFFÉRENTS s'y succèdent :
#
#   1. WinPE / setup.exe : monte le partage avec les creds `se4install`
#      (`net use z: \\<se4fs>\install /user:se4install@<domain> ...`).
#      → accès en tant qu'utilisateur réel se4install (owner/group), OK.
#
#   2. Post-install au BOOT : la tâche planifiée GPO `wpkg4` exécute
#      `\\<se4fs>\install\wpkg\wpkg.cmd` en `NT Authority\System`, c.-à-d. le
#      COMPTE MACHINE (`<host>$`). Samba le mappe sur la classe POSIX « other ».
#      wpkg.cmd lit ensuite wpkg/ (scripts, packages.xml, tools), packages/
#      (.msi/.exe) et os/SambaEdu (helpers). Si l'un de ces chemins n'est pas
#      `o+rX`, le compte machine est refusé (ACCESS_DENIED, souvent silencieux)
#      → la post-install ne déploie RIEN (helpers absents de %PROGRAMFILES%).
#
# Invariant garanti ici : tout sous $INSTALL_ROOT est lisible par « other »
# (dossiers `o+rx`, fichiers `o+r`), SAUF la zone d'écriture client
# `wpkg/rapports/` (servie en écriture par le partage [rapports]) qui est
# volontairement exclue.
#
# Idempotent et non destructif (n'ajoute que le bit de lecture « other » ;
# ne retire jamais de droit, ne touche ni owner ni group). Conçu pour être
# appelé depuis update.sh. `--check` : audit seul, exit 1 si dérive détectée.
# ============================================================================

set -euo pipefail

# ----------------------------------------------------------------------------
# Configuration
# ----------------------------------------------------------------------------
# Racine du partage [install]. Surcharge possible via env SE_INSTALL_ROOT ou
# l'option `--root PATH` (utile en test / chemin non standard).
INSTALL_ROOT="${SE_INSTALL_ROOT:-/var/sambaedu/unattended/install}"
CHECK_ONLY=false

# Colors (alignées sur update.sh)
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()         { echo -e "${BLUE}[perms]${NC} $*"; }
log_success() { echo -e "${GREEN}[✓]${NC} $*"; }
log_error()   { echo -e "${RED}[✗]${NC} $*"; }
log_warning() { echo -e "${YELLOW}[!]${NC} $*"; }

# ----------------------------------------------------------------------------
# Args
# ----------------------------------------------------------------------------
parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --check|-c) CHECK_ONLY=true ;;
            --root)     INSTALL_ROOT="${2:?--root requiert un chemin}"; shift ;;
            -h|--help)
                cat <<EOF
Usage: $(basename "$0") [--check] [--root PATH]

Garantit que les assets du partage [install] sont lisibles par le compte
machine des postes (classe POSIX « other ») : dossiers o+rx, fichiers o+r,
hors zone d'écriture wpkg/rapports/.

Options:
  --check, -c   Audit seul (ne corrige rien). Exit 1 si des chemins non
                lisibles sont détectés, 0 sinon.
  --root PATH   Racine du partage install (défaut: \$SE_INSTALL_ROOT ou
                /var/sambaedu/unattended/install).
EOF
                exit 0 ;;
            *) log_error "Option inconnue : $1 (voir --help)"; exit 1 ;;
        esac
        shift
    done
}

# ----------------------------------------------------------------------------
# Cœur
# ----------------------------------------------------------------------------
main() {
    parse_args "$@"

    if [[ ! -d "$INSTALL_ROOT" ]]; then
        # Sur l'hôte de dev (pas la VM) ce chemin n'existe pas : on sort
        # proprement sans échouer, comme les autres étapes d'update.sh.
        log_warning "Racine partage absente ($INSTALL_ROOT) — étape ignorée"
        return 0
    fi

    local rapports_dir="$INSTALL_ROOT/wpkg/rapports"
    log "Audit des permissions de lecture sous $INSTALL_ROOT (hors wpkg/rapports)..."

    # Violateurs : dossiers sans o+rx (octal 005), fichiers sans o+r (004).
    # La zone d'écriture client rapports/ est élaguée (-prune).
    local bad_dirs bad_files
    bad_dirs=$(find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o -type d ! -perm -005 -print 2>/dev/null | wc -l)
    bad_files=$(find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o -type f ! -perm -004 -print 2>/dev/null | wc -l)
    local total=$((bad_dirs + bad_files))

    if [[ "$total" -eq 0 ]]; then
        log_success "Permissions conformes (tout est lisible par le compte machine)"
        return 0
    fi

    if [[ "$CHECK_ONLY" == true ]]; then
        log_warning "$total chemin(s) non lisible(s) par le compte machine ($bad_dirs dossier(s), $bad_files fichier(s))"
        log_warning "Échantillon :"
        find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o \( -type d ! -perm -005 -o -type f ! -perm -004 \) -print 2>/dev/null | head -10 | sed 's/^/    /'
        log_warning "Relancer sans --check (ou via update.sh) pour réparer."
        return 1
    fi

    log "$total chemin(s) à corriger ($bad_dirs dossier(s) → o+rx, $bad_files fichier(s) → o+r)..."
    # Réparation batchée (chmod {} +) ; n'ajoute que le bit de lecture « other ».
    find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o -type d ! -perm -005 -exec chmod o+rx {} + 2>/dev/null || true
    find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o -type f ! -perm -004 -exec chmod o+r  {} + 2>/dev/null || true

    # Re-vérification post-fix.
    local remaining
    remaining=$(find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o \( -type d ! -perm -005 -o -type f ! -perm -004 \) -print 2>/dev/null | wc -l)
    if [[ "$remaining" -eq 0 ]]; then
        log_success "$total chemin(s) corrigé(s) — partage [install] lisible par les postes"
    else
        log_error "$remaining chemin(s) toujours non lisible(s) après réparation (droits insuffisants ?) — vérifier manuellement"
        return 1
    fi
}

main "$@"
