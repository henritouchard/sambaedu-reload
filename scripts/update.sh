#!/bin/bash
# ============================================================================
# SambaEdu Update Script
# Mises à jour: dépendances, migrations, configurations
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

# Mode dev : conserve les require-dev (phpunit, etc.) pour pouvoir lancer
# `php artisan test` après l'update. Off par défaut (déploiement prod).
DEV_MODE=false

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

    local composer_flags=(--optimize-autoloader --no-interaction)
    if [[ "$DEV_MODE" == true ]]; then
        log "  → mode dev : require-dev conservés (phpunit, etc.)"
    else
        composer_flags+=(--no-dev)
    fi

    composer install "${composer_flags[@]}"

    # Régénération forcée du classmap autoload : garantit que les classes
    # renommées/supprimées entre deux déploiements (ex: refactor 5.1a
    # QuotaService → XfsQuotaService) ne laissent pas d'entrées fantômes
    # pointant sur des fichiers absents (Fatal error à l'autoload).
    composer dump-autoload --optimize --no-interaction

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

    # `--resync-seeded-roles` : re-synchronise les permissions des rôles
    # seedés (SambaRole::*) sur leur définition canonique enum. Nécessaire
    # quand une nouvelle permission est ajoutée à un rôle déjà présent en DB
    # (le seeder est non-destructif par défaut). N'affecte ni les permissions
    # directes des users (model_has_permissions), ni l'attribution des rôles
    # (model_has_roles), ni les rôles custom créés via l'UI Profils.
    php artisan sambaedu:app:update --resync-seeded-roles

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

    # Résoudre le binaire PHP absolu (cf. install.sh:install_queue_workers).
    # La version peut changer entre deux updates (php8.2 → php8.4).
    local php_bin
    php_bin="$(command -v php || true)"
    if [[ -z "$php_bin" ]]; then
        log_error "Aucun binaire PHP trouvé dans le PATH"
        return 1
    fi
    php_bin="$(readlink -f "$php_bin")"

    for source_file in "$SYSTEMD_SOURCE_DIR"/*.service; do
        [[ -e "$source_file" ]] || continue

        local service
        service="$(basename "$source_file")"
        local target_file="$SYSTEMD_TARGET_DIR/$service"

        local rendered
        rendered="$(sed "s|__PHP_BIN__|${php_bin}|g" "$source_file")"

        if [[ ! -f "$target_file" ]] || ! diff -q <(echo "$rendered") "$target_file" >/dev/null 2>&1; then
            log "Mise à jour service systemd: $service"
            echo "$rendered" > "$target_file"
            changed=true
        fi
    done

    if [[ "$changed" == true ]]; then
        systemctl daemon-reload
        log_success "Services systemd mis à jour"
    else
        log_success "Services systemd déjà à jour"
    fi

    # Enable + restart des workers (les 3 : sync, worker, general).
    # On enable systématiquement : si l'install précédent a échoué,
    # les services sont restés "disabled" et l'update doit réparer.
    local workers=(laravel-queue-sync laravel-queue-worker laravel-queue-general)
    for svc in "${workers[@]}"; do
        [[ -f "$SYSTEMD_TARGET_DIR/$svc.service" ]] || continue
        if ! systemctl is-enabled "$svc" >/dev/null 2>&1; then
            log "  → $svc: activation (disabled → enabled)"
            systemctl enable "$svc" >/dev/null 2>&1 || log_error "  → $svc: enable a échoué"
        fi
        log "Redémarrage $svc..."
        if ! systemctl restart "$svc"; then
            log_error "  → $svc: restart a échoué"
            continue
        fi
        sleep 1
        if ! systemctl is-active --quiet "$svc"; then
            log_error "  → $svc: inactif après restart (voir: journalctl -u $svc)"
        fi
    done

    # Cron du scheduler Laravel : même rendu __PHP_BIN__ que les unit files,
    # et réinstallation idempotente (répare une install incomplète).
    local cron_src="$APP_DIR/scripts/config/sambaedu-scheduler.cron"
    local cron_dst="/etc/cron.d/sambaedu-scheduler"
    if [[ -f "$cron_src" ]]; then
        local rendered
        rendered="$(sed "s|__PHP_BIN__|${php_bin}|g" "$cron_src")"
        if [[ ! -f "$cron_dst" ]] || ! diff -q <(echo "$rendered") "$cron_dst" >/dev/null 2>&1; then
            log "Mise à jour cron scheduler: $cron_dst"
            echo "$rendered" > "$cron_dst"
            chown root:root "$cron_dst"
            chmod 644 "$cron_dst"
        fi
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

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --dev|-d)
                DEV_MODE=true
                ;;
            -h|--help)
                cat <<EOF
Usage: $(basename "$0") [--dev]

Options:
  --dev, -d   Conserve les dépendances de dev (phpunit, etc.) pour pouvoir
              lancer 'php artisan test' après l'update. Par défaut, le script
              exécute 'composer install --no-dev' (déploiement prod).
EOF
                exit 0
                ;;
            *)
                log_error "Option inconnue : $1 (voir --help)"
                exit 1
                ;;
        esac
        shift
    done
}

main() {
    parse_args "$@"

    log "Démarrage de la mise à jour..."
    if [[ "$DEV_MODE" == true ]]; then
        log "Mode : dev (require-dev conservés)"
    fi
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
