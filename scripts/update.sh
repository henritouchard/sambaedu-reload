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
    echo "[update] $*"
}

require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        echo "Ce script doit être exécuté en root (sudo)."
        exit 1
    fi
}

update_dependencies() {
    cd "$LARAVEL_DIR"

    log "Mise à jour dépendances Composer"
    composer install --no-dev --optimize-autoloader --no-interaction

    if [[ -f package.json ]] && command -v npm >/dev/null 2>&1; then
        log "Build frontend"
        npm run build
    else
        log "NPM/package.json absent: build frontend ignoré"
    fi
}

run_laravel_update() {
    cd "$LARAVEL_DIR"

    if [[ ! -f artisan ]]; then
        log "artisan introuvable, arrêt"
        exit 1
    fi

    log "Exécution commande artisan de mise à jour applicative"
    "$PHP_CMD" artisan sambaedu:app:update

    if [[ -f .env.example && -f .env ]]; then
        local missing_keys
        missing_keys=$(comm -23 \
            <(grep -E '^[A-Z_]+=' .env.example | cut -d= -f1 | sort) \
            <(grep -E '^[A-Z_]+=' .env | cut -d= -f1 | sort) || true)

        if [[ -n "$missing_keys" ]]; then
            log "Variables présentes dans .env.example mais absentes de .env:"
            echo "$missing_keys" | sed 's/^/  - /'
        fi
    fi
}

update_apache_if_needed() {
    if [[ ! -f "$APACHE_CONF_SOURCE" ]]; then
        log "Configuration Apache source introuvable: $APACHE_CONF_SOURCE"
        return
    fi

    if [[ ! -f "$APACHE_CONF_TARGET" ]] || ! cmp -s "$APACHE_CONF_SOURCE" "$APACHE_CONF_TARGET"; then
        log "Mise à jour configuration Apache"
        
        cp "$APACHE_CONF_SOURCE" "$APACHE_CONF_TARGET"
        a2ensite sambaedu.conf >/dev/null 2>&1 || true
        systemctl reload apache2
    else
        log "Configuration Apache déjà à jour"
    fi
}

update_systemd_if_needed() {
    local changed=false

    if [[ ! -d "$SYSTEMD_SOURCE_DIR" ]]; then
        log "Répertoire services introuvable: $SYSTEMD_SOURCE_DIR"
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
    fi

    systemctl restart laravel-queue-general laravel-queue-sync
}

main() {
    require_root

    log "Démarrage mise à jour"
    update_dependencies
    run_laravel_update
    update_apache_if_needed
    update_systemd_if_needed

    log "Mise à jour terminée"
    log "Note: aucune correction globale de permissions n'a été appliquée."
}

main "$@"
