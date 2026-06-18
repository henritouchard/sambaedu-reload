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
# CAUSE RACINE (au-delà du chmod de réparation) : les dossiers `os/` et
# `packages/` portent une ACL POSIX par défaut `default:other::---`. Tout
# fichier qui y NAÎT (ex. un installeur déposé par le module AppStore :
# download → rename, sans chmod) hérite de `other::---` et est illisible par le
# compte machine, QUEL QUE SOIT l'umask de php-fpm. On pose donc aussi
# `default:other::r-x` (`setfacl -d`) sur les dossiers → les futurs fichiers
# naissent lisibles et le fix est one-shot (plus besoin de re-réparer après
# chaque ajout). Lecture seule : on n'ajoute JAMAIS de bit `w` — aucune
# altération d'installeur possible (le seul vrai risque sécu serait `o+w`).
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
# ACL par défaut (cause racine)
# ----------------------------------------------------------------------------
# Liste les dossiers dont l'ACL POSIX par défaut porte `default:other::` SANS
# `r` : ils enfanteraient des fichiers nés `other::---` (illisibles par le
# compte machine), quel que soit l'umask. `wpkg/rapports/` est élagué.
# Robuste sous `set -e` (le pipe getfacl|awk ne fait jamais échouer l'appelant).
list_bad_default_acls() {
    local rapports_dir="$1"
    { getfacl -R -p "$INSTALL_ROOT" 2>/dev/null | awk -v rap="$rapports_dir" '
        /^# file: / { f = substr($0, 9); next }
        /^default:other::/ {
            # Isoler la PERMISSION (« --- » ou « r-x ») : le mot « other »
            # contient déjà un « r », ne pas tester la ligne entière.
            perm = $0; sub(/^default:other::/, "", perm)
            if (index(perm, "r") == 0 && index(f, rap) != 1) print f
        }
    '; } || true
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

    local has_setfacl=true
    command -v setfacl &>/dev/null || has_setfacl=false

    local rapports_dir="$INSTALL_ROOT/wpkg/rapports"
    log "Audit des permissions de lecture sous $INSTALL_ROOT (hors wpkg/rapports)..."

    # Violateurs d'accès : dossiers sans o+rx (octal 005), fichiers sans o+r (004).
    # La zone d'écriture client rapports/ est élaguée (-prune).
    local bad_dirs bad_files bad_dacl
    bad_dirs=$(find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o -type d ! -perm -005 -print 2>/dev/null | wc -l)
    bad_files=$(find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o -type f ! -perm -004 -print 2>/dev/null | wc -l)
    # Violateurs d'ACL par défaut (cause racine des futures naissances cassées).
    bad_dacl=$(list_bad_default_acls "$rapports_dir" | wc -l)
    local total=$((bad_dirs + bad_files + bad_dacl))

    if [[ "$total" -eq 0 ]]; then
        log_success "Permissions conformes (existant lisible + naissances saines)"
        return 0
    fi

    if [[ "$CHECK_ONLY" == true ]]; then
        log_warning "$total écart(s) : $bad_dirs dossier(s) o-rx, $bad_files fichier(s) o-r, $bad_dacl ACL défaut other sans 'r'"
        log_warning "Échantillon :"
        { find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o \( -type d ! -perm -005 -o -type f ! -perm -004 \) -print 2>/dev/null
          list_bad_default_acls "$rapports_dir" | sed 's/$/ (ACL défaut)/'
        } | head -10 | sed 's/^/    /'
        log_warning "Relancer sans --check (ou via update.sh) pour réparer."
        return 1
    fi

    log "$total écart(s) à corriger ($bad_dirs dir → o+rx, $bad_files file → o+r, $bad_dacl ACL défaut → other r-x)..."
    # 1. Réparation de l'existant : n'ajoute que le bit de lecture « other » (jamais w).
    find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o -type d ! -perm -005 -exec chmod o+rx {} + 2>/dev/null || true
    find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o -type f ! -perm -004 -exec chmod o+r  {} + 2>/dev/null || true
    # 2. Cause racine : ACL par défaut other::r-x sur les dossiers → naissances saines.
    if [[ "$has_setfacl" == true ]]; then
        find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o -type d -exec setfacl -d -m o::rx {} + 2>/dev/null || true
    else
        log_warning "setfacl indisponible — ACL par défaut NON corrigée ; les futurs ajouts pourront renaître non lisibles."
    fi

    # Re-vérification post-fix (accès + ACL par défaut).
    local remaining
    remaining=$(( $(find "$INSTALL_ROOT" -path "$rapports_dir" -prune -o \( -type d ! -perm -005 -o -type f ! -perm -004 \) -print 2>/dev/null | wc -l) + $(list_bad_default_acls "$rapports_dir" | wc -l) ))
    if [[ "$remaining" -eq 0 ]]; then
        log_success "$total écart(s) corrigé(s) — [install] lisible par les postes, naissances saines"
    else
        log_error "$remaining écart(s) restant(s) après réparation (droits insuffisants ?) — vérifier manuellement"
        return 1
    fi
}

main "$@"
