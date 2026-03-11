#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARAVEL_DIR="$(dirname "$SCRIPT_DIR")"
APACHE_CONF_SOURCE="$LARAVEL_DIR/config/apache/sambaedu.conf"
APACHE_CONF_TARGET="/etc/apache2/sites-available/sambaedu.conf"
SYSTEMD_SOURCE_DIR="$LARAVEL_DIR/scripts/config"
SYSTEMD_TARGET_DIR="/etc/systemd/system"
PHP_CMD="${PHP_CMD:-/usr/bin/php8.2}"

log() {
    echo "[install] $*"
}

require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        echo "Ce script doit être exécuté en root (sudo)."
        exit 1
    fi
}

run_app_steps() {
    cd "$LARAVEL_DIR"

    if [[ ! -f .env ]]; then
        if [[ -f "$SCRIPT_DIR/create-env.sh" ]]; then
            log "Création du .env via scripts/create-env.sh"
            bash "$SCRIPT_DIR/create-env.sh"
        else
            log "create-env.sh absent/non exécutable, fallback sur .env.example"
            cp .env.example .env
        fi
    fi

    log "Installation dépendances Composer"
    composer install --no-dev --optimize-autoloader --no-interaction

    if [[ -f package.json ]] && command -v npm >/dev/null 2>&1; then
        log "Installation dépendances NPM"
        npm install
        log "Build frontend"
        npm run build
    else
        log "NPM/package.json absent: build frontend ignoré"
    fi

    if [[ -f artisan ]]; then
        log "Exécution des étapes applicatives Laravel"
        "$PHP_CMD" artisan sambaedu:app:update
    fi
}

setup_postgres() {
    if [[ -f "$SCRIPT_DIR/setup-postgres.sh" ]]; then
        log "Configuration PostgreSQL"
        bash "$SCRIPT_DIR/setup-postgres.sh"
    else
        log "setup-postgres.sh absent/non exécutable: étape ignorée"
    fi
}

setup_apache() {
    if [[ -f "$APACHE_CONF_SOURCE" ]]; then
        log "Installation configuration Apache"
        cp "$APACHE_CONF_SOURCE" "$APACHE_CONF_TARGET"
        a2ensite sambaedu.conf >/dev/null 2>&1 || true
        a2enmod rewrite >/dev/null 2>&1 || true
        a2enmod headers >/dev/null 2>&1 || true
        systemctl reload apache2
    else
        log "Configuration Apache source introuvable: $APACHE_CONF_SOURCE"
    fi
}

setup_systemd_workers() {
    local services=(laravel-queue-general.service laravel-queue-sync.service)

    if [[ ! -d "$SYSTEMD_SOURCE_DIR" ]]; then
        log "Répertoire services introuvable: $SYSTEMD_SOURCE_DIR"
        return
    fi

    for service in "${services[@]}"; do
        if [[ -f "$SYSTEMD_SOURCE_DIR/$service" ]]; then
            cp "$SYSTEMD_SOURCE_DIR/$service" "$SYSTEMD_TARGET_DIR/$service"
        fi
    done

    systemctl daemon-reload
    systemctl enable laravel-queue-general laravel-queue-sync
    systemctl restart laravel-queue-general laravel-queue-sync
}

main() {
    require_root

    log "Démarrage installation complète"
    setup_postgres
    run_app_steps
    setup_apache
    setup_systemd_workers

    log "Installation terminée"
    log "Note: aucune correction globale de permissions n'a été appliquée."
}

main "$@"
