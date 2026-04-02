#!/bin/bash
# ============================================================================
# SambaEdu Update Script
# Mises à jour: dépendances, migrations, configurations
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

# Configuration
APACHE_CONF_SOURCE="$APP_DIR/config/apache/sambaedu.conf"
APACHE_CONF_TARGET="/etc/apache2/sites-available/sambaedu.conf"
SYSTEMD_SOURCE_DIR="$APP_DIR/scripts/config"
SYSTEMD_TARGET_DIR="/etc/systemd/system"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log() {
    echo -e "${BLUE}[update]${NC} $*"
}

log_success() {
    echo -e "${GREEN}[✓]${NC} $*"
}

log_error() {
    echo -e "${RED}[✗]${NC} $*"
}

log_warning() {
    echo -e "${YELLOW}[!]${NC} $*"
}

# ============================================================================
# Validation des prérequis
# ============================================================================

check_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        log_error "Ce script doit être exécuté en root (sudo)"
        exit 1
    fi
}

check_artisan() {
    if [[ ! -f "$APP_DIR/artisan" ]]; then
        log_error "artisan non trouvé - pas une installation Laravel valide"
        exit 1
    fi
}

# ============================================================================
# Mise à jour des dépendances
# ============================================================================

update_composer() {
    log "Mise à jour dépendances Composer..."
    cd "$APP_DIR"

    composer install --no-dev --optimize-autoloader --no-interaction

    # S'assurer que vendor/ appartient à www-admin (groupe web)
    chown -R www-admin:www-admin "$APP_DIR/vendor"

    log_success "Composer OK"
}

update_npm() {
    log "Mise à jour dépendances NPM..."
    cd "$APP_DIR"

    if [[ ! -f package.json ]]; then
        log_warning "package.json non trouvé - build frontend ignoré"
        return
    fi

    if ! command -v npm >/dev/null 2>&1; then
        log_warning "npm non installé - build frontend ignoré"
        return
    fi

    npm install
    npm run build

    log_success "NPM OK"
}

# ============================================================================
# Mise à jour Laravel
# ============================================================================

run_laravel_update() {
    log "Exécution de la mise à jour applicative..."
    cd "$APP_DIR"

    php artisan sambaedu:app:update

    log_success "Mise à jour Laravel OK"
}

update_env() {
    bash "$SCRIPT_DIR/updateEnv.sh"
}

# ============================================================================
# Mise à jour Apache
# ============================================================================

update_apache() {
    log "Vérification configuration Apache..."

    local SETUP_APACHE_SCRIPT="$APP_DIR/scripts/setupApache.sh"

    # Vérifier si le vhost SER est en place (setupApache.sh déjà exécuté)
    if [[ -f "$APACHE_CONF_TARGET" ]] && grep -q "sambaedu-reload/public" "$APACHE_CONF_TARGET"; then
        # Le vhost SER est actif — vérifier qu'il n'a pas été altéré
        # en comparant le DocumentRoot et la structure attendue
        if grep -q "DocumentRoot.*sambaedu-reload/public" "$APACHE_CONF_TARGET" \
           && [[ -f "/etc/apache2/sites-available/sambaedu-legacy.conf" ]]; then
            log_success "Apache déjà configuré pour SER (setupApache.sh)"
            return
        else
            # Le vhost SER est incomplet (legacy manquant) → relancer setupApache.sh
            log_warning "Configuration Apache SER incomplète — relance de setupApache.sh"
            if [[ -x "$SETUP_APACHE_SCRIPT" ]]; then
                bash "$SETUP_APACHE_SCRIPT"
                log_success "Apache reconfiguré via setupApache.sh"
            else
                log_error "setupApache.sh introuvable ou non exécutable: $SETUP_APACHE_SCRIPT"
            fi
            return
        fi
    fi

    # Pas de vhost SER en place → exécuter setupApache.sh si disponible
    if [[ -x "$SETUP_APACHE_SCRIPT" ]]; then
        log "Premier déploiement SER — exécution de setupApache.sh"
        bash "$SETUP_APACHE_SCRIPT"
        log_success "Apache configuré via setupApache.sh"
    elif [[ -f "$APACHE_CONF_SOURCE" ]]; then
        # Fallback : ancien comportement (copie du template)
        log_warning "setupApache.sh non disponible — fallback sur le template Apache"
        cp "$APACHE_CONF_SOURCE" "$APACHE_CONF_TARGET"
        a2ensite sambaedu.conf >/dev/null 2>&1 || true
        a2enmod rewrite >/dev/null 2>&1 || true
        a2enmod headers >/dev/null 2>&1 || true
        systemctl reload apache2
        log_success "Apache mis à jour (template)"
    else
        log_warning "Aucune source de configuration Apache trouvée"
    fi
}

# ============================================================================
# Mise à jour des services systemd
# ============================================================================

update_systemd() {
    log "Vérification services systemd..."

    local changed=false

    if [[ ! -d "$SYSTEMD_SOURCE_DIR" ]]; then
        log_warning "Répertoire services introuvable: $SYSTEMD_SOURCE_DIR"
        return
    fi

    for source_file in "$SYSTEMD_SOURCE_DIR"/*.service; do
        [[ -e "$source_file" ]] || continue

        local service
        service="$(basename "$source_file")"
        local target_file="$SYSTEMD_TARGET_DIR/$service"

        if [[ ! -f "$target_file" ]] || ! cmp -s "$source_file" "$target_file"; then
            log "Mise à jour service systemd: $service"
            cp "$source_file" "$target_file"
            changed=true
        fi
    done

    if [[ "$changed" == true ]]; then
        systemctl daemon-reload
        log_success "Services systemd mis à jour"
    else
        log_success "Services systemd déjà à jour"
    fi

    # Redémarrer les workers
    if systemctl is-enabled laravel-queue-general >/dev/null 2>&1; then
        log "Redémarrage des workers..."
        systemctl restart laravel-queue-general || true
    fi
    if systemctl is-enabled laravel-queue-sync >/dev/null 2>&1; then
        systemctl restart laravel-queue-sync || true
    fi
}

# ============================================================================
# Affichage du résumé
# ============================================================================

show_summary() {
    echo ""
    echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}Mise à jour terminée avec succès!${NC}"
    echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
    log_success "Toutes les étapes complétées"
    echo ""
    log "Statistiques:"
    echo "  ✓ Composer"
    echo "  ✓ NPM/Build frontend"
    echo "  ✓ Laravel update"
    echo "  ✓ Apache"
    echo "  ✓ Services systemd"
    echo ""
}

# ============================================================================
# Main
# ============================================================================

main() {
    log "Démarrage de la mise à jour..."
    echo ""

    check_root
    check_artisan

    echo ""
    update_composer

    echo ""
    update_npm

    echo ""
    update_env

    echo ""
    run_laravel_update

    echo ""
    update_apache

    echo ""
    update_systemd

    echo ""
    show_summary
}

main "$@"
