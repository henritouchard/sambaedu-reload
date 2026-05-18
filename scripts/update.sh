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

# Flag positionné par `ensure_auth_v1_pki` quand un cert a été (re)généré.
# `reload_apache_after_pki_renewal` reload Apache dans ce cas-là uniquement
# (sinon Apache continue à servir l'ancien cert chargé en mémoire au boot).
AUTH_V1_PKI_REGENERATED=false

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

run_doctor_check() {
    log "Vérification des pré-requis environnementaux (sambaedu:doctor)..."
    cd "$APP_DIR"

    local doctor_user="root"
    if id www-admin >/dev/null 2>&1; then
        doctor_user="www-admin"
    else
        log_warning "User www-admin absent — doctor lancé en root (résultats moins représentatifs du runtime PHP-FPM)"
    fi

    local exit_code=0
    if [[ "$doctor_user" == "www-admin" ]]; then
        sudo -u www-admin php artisan sambaedu:doctor || exit_code=$?
    else
        php artisan sambaedu:doctor || exit_code=$?
    fi

    case "$exit_code" in
        0) log_success "Doctor : tous les pré-requis OK" ;;
        1) log_warning "Doctor : warnings détectés (voir ci-dessus) — non bloquant" ;;
        2) log_warning "Doctor : erreurs détectées (voir ci-dessus) — corriger avant utilisation, puis relancer 'sudo -u www-admin php artisan sambaedu:doctor'" ;;
        *) log_warning "Doctor : exit code inattendu ($exit_code)" ;;
    esac
}

update_env() {
    bash "$SCRIPT_DIR/updateEnv.sh"
}

# ============================================================================
# PKI Auth V1 (Story 16.10) — init conditionnelle
# ============================================================================
# Lance `php artisan auth:ca:init --no-interaction` UNIQUEMENT si :
#  - le CA root est absent (première install ou réinstallation),
#  - le CA root expire dans <30j,
#  - aucun cert serveur n'est trouvé,
#  - ou le cert serveur expire dans <30j.
# Sinon, no-op silencieux. La commande artisan est elle-même idempotente,
# mais ce pré-check évite un appel inutile à chaque update et fournit un
# log explicite du statut PKI pour les ops.

ensure_auth_v1_pki() {
    log "Vérification PKI Auth V1..."
    cd "$APP_DIR"

    if ! php artisan list 2>/dev/null | grep -q 'auth:ca:init'; then
        log_warning "Commande auth:ca:init non disponible (Story 16.10 pas déployée) — étape ignorée"
        return 0
    fi

    local pki_dir="$APP_DIR/storage/keys/pki"
    local ca_cert="$pki_dir/ca/ca-cert.pem"
    local renewal_threshold_days=30
    local renewal_threshold_seconds=$((renewal_threshold_days * 86400))
    local needs_init=false
    local reason=""

    if [[ ! -f "$ca_cert" ]]; then
        needs_init=true
        reason="CA root absent ($ca_cert)"
    elif ! openssl x509 -in "$ca_cert" -checkend "$renewal_threshold_seconds" -noout >/dev/null 2>&1; then
        needs_init=true
        reason="CA root expire dans moins de ${renewal_threshold_days}j"
    else
        # CA root OK → on vérifie aussi un éventuel cert serveur
        local server_cert
        server_cert="$(find "$pki_dir/server" -maxdepth 1 -name '*-cert.pem' -type f 2>/dev/null | head -1)"
        if [[ -z "$server_cert" ]]; then
            needs_init=true
            reason="aucun cert serveur dans $pki_dir/server/"
        elif ! openssl x509 -in "$server_cert" -checkend "$renewal_threshold_seconds" -noout >/dev/null 2>&1; then
            needs_init=true
            reason="cert serveur $(basename "$server_cert") expire dans moins de ${renewal_threshold_days}j"
        fi
    fi

    if [[ "$needs_init" == true ]]; then
        log "(Re)génération PKI Auth V1 — raison : $reason"
        if ! php artisan auth:ca:init --no-interaction; then
            log_error "Échec init/renouvellement PKI Auth V1 — vérifier storage/keys/ + extension OpenSSL"
            return 1
        fi
        AUTH_V1_PKI_REGENERATED=true
        log_success "PKI Auth V1 (re)générée"
    else
        log_success "PKI Auth V1 OK (CA + cert serveur valides >${renewal_threshold_days}j)"
    fi
}

# ============================================================================
# Reload Apache après renouvellement cert serveur
# ============================================================================
# Apache lit les certs SSL au démarrage et les garde en mémoire. Quand
# `ensure_auth_v1_pki` régénère le fichier `.pem`, Apache continue à servir
# l'ancien cert jusqu'au reload. On ne reload que si nécessaire (flag positionné
# par `ensure_auth_v1_pki`) — pas à chaque update.

reload_apache_after_pki_renewal() {
    if [[ "$AUTH_V1_PKI_REGENERATED" != true ]]; then
        return 0
    fi

    if ! command -v apache2ctl >/dev/null 2>&1; then
        log_warning "apache2ctl non disponible — reload Apache à faire manuellement après régénération PKI"
        return 0
    fi

    log "Cert serveur régénéré — vérification config Apache et reload..."
    if ! apache2ctl configtest 2>&1; then
        log_error "apache2ctl configtest a échoué — reload SKIPPED. Vérifiez la config manuellement."
        return 1
    fi

    if systemctl reload apache2; then
        log_success "Apache rechargé — nouveau cert serveur actif"
    else
        log_error "Échec du reload Apache (systemctl reload apache2)"
        return 1
    fi
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
    echo "  ✓ PKI Auth V1"
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
    ensure_auth_v1_pki

    echo ""
    update_apache

    echo ""
    reload_apache_after_pki_renewal

    echo ""
    update_systemd

    echo ""
    run_doctor_check

    echo ""
    show_summary
}

main "$@"
