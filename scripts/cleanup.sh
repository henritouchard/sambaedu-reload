#!/bin/bash
# ============================================================================
# SambaEdu Cleanup Script
# Arrête et nettoie les services Docker existants
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log() {
    echo -e "${BLUE}[cleanup]${NC} $*"
}

log_success() {
    echo -e "${GREEN}[✓]${NC} $*"
}

log_warning() {
    echo -e "${YELLOW}[!]${NC} $*"
}

# ============================================================================
# Options de nettoyage
# ============================================================================

show_usage() {
    cat <<EOF
Usage: $0 [OPTION]

Options de nettoyage:
  --soft      Arrêter les conteneurs (données conservées)
  --hard      Arrêter + supprimer les conteneurs (données conservées)
  --full      Arrêter + supprimer tout (volumes + données)
  --help      Afficher cette aide

Exemples:
  # Arrêter sans perdre les données
  sudo $0 --soft

  # Supprimer les conteneurs mais garder les données
  sudo $0 --hard

  # Suppression complète (ATTENTION: perte de données)
  sudo $0 --full
EOF
}

cleanup_soft() {
    log "Arrêt des services..."
    cd "$APP_DIR"
    docker compose stop
    log_success "Services arrêtés"
}

cleanup_hard() {
    log "Arrêt et suppression des conteneurs..."
    cd "$APP_DIR"
    docker compose down
    log_success "Conteneurs supprimés"
    log_warning "Les volumes de données sont conservés"
}

cleanup_full() {
    log_warning "Suppression COMPLÈTE: conteneurs + volumes + données"
    read -p "Confirmez (yes/no): " confirm

    if [[ "$confirm" != "yes" ]]; then
        log_warning "Opération annulée"
        return
    fi

    cd "$APP_DIR"
    docker compose down -v
    log_success "Tous les services et données ont été supprimés"
}

show_status() {
    log "État des services:"
    cd "$APP_DIR"

    if docker compose ps | grep -q -E "sambaedu_(postgres|redis)"; then
        echo ""
        docker compose ps
        echo ""
    else
        log_warning "Aucun service SambaEdu en cours d'exécution"
    fi
}

# ============================================================================
# Main
# ============================================================================

main() {
    if [[ $# -eq 0 ]]; then
        show_status
        echo ""
        log "Usage: $0 [--soft|--hard|--full|--help]"
        return
    fi

    case "$1" in
        --soft)
            cleanup_soft
            ;;
        --hard)
            cleanup_hard
            ;;
        --full)
            cleanup_full
            ;;
        --status)
            show_status
            ;;
        --help|-h)
            show_usage
            ;;
        *)
            log_warning "Option inconnue: $1"
            show_usage
            exit 1
            ;;
    esac

    echo ""
    show_status
}

main "$@"
