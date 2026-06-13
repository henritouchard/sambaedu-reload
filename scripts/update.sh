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

# ============================================================================
# APCu CLI — activation automatique
# ============================================================================
# APCu est typiquement installé pour PHP-FPM mais désactivé en CLI sur
# Debian/Ubuntu (`apc.enable_cli=0` par défaut). Conséquence : `php artisan`
# perd les caches APCu (sambaedu:doctor le diagnostique). On active la
# directive en CLI uniquement, de manière idempotente.

ensure_apcu_cli() {
    log "Vérification APCu CLI..."

    if ! command -v php >/dev/null 2>&1; then
        log_warning "php absent — APCu CLI ignoré"
        return 0
    fi

    if php -r 'exit(function_exists("apcu_enabled") && apcu_enabled() ? 0 : 1);' 2>/dev/null; then
        log_success "APCu CLI déjà actif"
        return 0
    fi

    # Glob sur tous les `*apcu*.ini` des conf.d CLI (Debian/Ubuntu place le
    # fichier sous `/etc/php/<version>/cli/conf.d/`).
    shopt -s nullglob
    local conf_files=(/etc/php/*/cli/conf.d/*apcu*.ini)
    shopt -u nullglob

    if [[ ${#conf_files[@]} -eq 0 ]]; then
        log_warning "Aucun fichier conf APCu CLI trouvé — extension probablement non installée (`apt install php-apcu`)"
        return 0
    fi

    local changed=false
    for conf in "${conf_files[@]}"; do
        if grep -qE '^[[:space:]]*apc\.enable_cli[[:space:]]*=' "$conf"; then
            if ! grep -qE '^[[:space:]]*apc\.enable_cli[[:space:]]*=[[:space:]]*1' "$conf"; then
                sed -i 's/^[[:space:]]*apc\.enable_cli[[:space:]]*=.*/apc.enable_cli=1/' "$conf"
                log "  → $conf : apc.enable_cli forcé à 1"
                changed=true
            fi
        else
            echo "apc.enable_cli=1" >> "$conf"
            log "  → $conf : apc.enable_cli=1 ajouté"
            changed=true
        fi
    done

    if [[ "$changed" == true ]]; then
        log_success "APCu CLI activé (effectif au prochain lancement PHP)"
    else
        log_success "APCu CLI déjà configuré"
    fi
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

    # Chemins iso config/auth_v1.php (ca_root_crt / server_crt). Si on change
    # AUTH_V1_PKI_CA_CRT ou AUTH_V1_PKI_SERVER_CRT en env, mettre à jour ici.
    local pki_dir="$APP_DIR/storage/keys/pki"
    local ca_cert="$pki_dir/ca-root.crt"
    local server_cert="$pki_dir/server.crt"
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
    elif [[ ! -f "$server_cert" ]]; then
        needs_init=true
        reason="cert serveur absent ($server_cert)"
    elif ! openssl x509 -in "$server_cert" -checkend "$renewal_threshold_seconds" -noout >/dev/null 2>&1; then
        needs_init=true
        reason="cert serveur expire dans moins de ${renewal_threshold_days}j"
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
# PFX code-signing agent (Story 24.5) — émission conditionnelle
# ============================================================================
# Délègue à scripts/emit-codesign-pfx.sh (idempotent : no-op si le PFX existe,
# valide >30j et chaîne vers le ca-root courant). Forcé si ensure_auth_v1_pki
# vient de régénérer la CA (l'ancienne chaîne ne remonterait plus à la racine
# déployée sur les postes). NON-FATAL : un échec ici ne doit pas bloquer un
# update serveur — le PFX ne sert qu'aux builds de l'agent.

ensure_codesign_pfx() {
    log "Vérification PFX code-signing agent..."

    local emit_script="$SCRIPT_DIR/emit-codesign-pfx.sh"
    if [[ ! -x "$emit_script" ]]; then
        if [[ -f "$emit_script" ]]; then
            chmod +x "$emit_script"
        else
            log_warning "emit-codesign-pfx.sh absent — étape ignorée"
            return 0
        fi
    fi

    local args=()
    if [[ "$AUTH_V1_PKI_REGENERATED" == true ]]; then
        log "CA régénérée pendant cet update — ré-émission forcée du PFX code-signing"
        args+=(--force)
    fi

    if bash "$emit_script" "${args[@]}"; then
        log_success "PFX code-signing agent OK (storage/keys/pki/sambaedu-codesign.pfx)"
    else
        log_warning "Émission du PFX code-signing échouée — les builds agent signés sont bloqués (relancer : scripts/emit-codesign-pfx.sh), l'update continue"
    fi
}

# ============================================================================
# Build agent Go signé (Story 24.5) — build conditionnel côté serveur
# ============================================================================
# Délègue à scripts/build-agent.sh (idempotent : no-op si le binaire dist/
# est plus récent que les sources agent/ et que le cert code-signing ;
# amorce toolchain Go épinglée + osslsigncode au premier passage). Le PFX ne
# quitte jamais le serveur. NON-FATAL : un échec de build agent ne doit pas
# bloquer un update serveur.

# ============================================================================
# Toolchain Go (agent) + cache partagé + dépendances
# ============================================================================
# Délègue à scripts/setupGo.sh : installe la toolchain Go épinglée, la met sur
# le PATH, prépare le cache Go partagé (www-admin) et télécharge les deps du
# module agent/. Prérequis de `ensure_agent_build` (build) ET du pont
# `php artisan test` → `go test ./...` (tests/Feature/Agent/GoAgentTest.php).

setup_go() {
    log "Vérification toolchain Go (agent)..."

    local setup_script="$SCRIPT_DIR/setupGo.sh"
    if [[ ! -f "$setup_script" ]]; then
        log_warning "setupGo.sh absent — étape ignorée"
        return 0
    fi

    if bash "$setup_script"; then
        log_success "Toolchain Go OK (go test agent disponible)"
    else
        log_warning "setupGo.sh échoué — go test agent indisponible, l'update continue"
    fi
}

ensure_agent_build() {
    log "Vérification build agent Go signé..."

    local build_script="$SCRIPT_DIR/build-agent.sh"
    if [[ ! -f "$build_script" ]]; then
        log_warning "build-agent.sh absent — étape ignorée"
        return 0
    fi

    if bash "$build_script"; then
        log_success "Agent Go signé OK (agent/build/dist/)"
    else
        log_warning "Build agent Go échoué — artefact signé non produit (relancer : scripts/build-agent.sh), l'update continue"
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
# LDAP client — SASL_NOCANON (bind GSSAPI vers DC sans reverse DNS)
# ============================================================================
# Sans `SASL_NOCANON on` dans /etc/ldap/ldap.conf, libldap canonicalise le
# hostname du DC via reverse DNS avant le bind GSSAPI. Si le DC n'a pas
# d'enregistrement PTR (cas courant en étab), le bind échoue et les pages
# legacy LDAP (ex: /ipxe/boot.php) renvoient 500. Fix idempotent constaté
# sur VM le 2026-05-28 — porté ici pour survivre aux réinstalls.

ensure_ldap_sasl_nocanon() {
    log "Vérification SASL_NOCANON (/etc/ldap/ldap.conf)..."

    local ldap_conf="/etc/ldap/ldap.conf"

    if [[ ! -f "$ldap_conf" ]]; then
        log_warning "$ldap_conf absent (libldap-common non installé ?) — étape ignorée"
        return 0
    fi

    if grep -qE '^[[:space:]]*SASL_NOCANON[[:space:]]+on' "$ldap_conf"; then
        log_success "SASL_NOCANON déjà actif"
        return 0
    fi

    if grep -qE '^[[:space:]]*SASL_NOCANON[[:space:]]' "$ldap_conf"; then
        # Directive présente mais pas à "on" → forcer.
        sed -i -E 's|^[[:space:]]*SASL_NOCANON[[:space:]].*$|SASL_NOCANON on|' "$ldap_conf"
        log "  → $ldap_conf : SASL_NOCANON forcé à on"
    else
        {
            echo ""
            echo "# SambaEdu — bind GSSAPI sans canonicalisation reverse-DNS du DC"
            echo "# (DC sans PTR → échec bind → 500 sur les pages LDAP legacy)."
            echo "SASL_NOCANON on"
        } >> "$ldap_conf"
        log "  → $ldap_conf : SASL_NOCANON on ajouté"
    fi

    log_success "SASL_NOCANON activé"
}

# ============================================================================
# Bascule PXE bootstrap vers la route Laravel native (Story 4.9 / 3.7)
# ============================================================================
# La conf legacy `/etc/sambaedu/sambaedu.conf.d/dhcp.conf` posée par les
# anciennes versions de Sambaedu définit `ipxe_script = "boot.php"`, ce qui
# fait pointer le `filename` DHCP des postes PXE vers le bootstrap legacy PHP.
# Tant que cette valeur est en place, les actions iPXE (rename, enrollment…)
# passent par le legacy via le catchall et ne touchent PAS PostgreSQL — d'où
# les divergences PG↔AD observées avant la story 4.9.
#
# Bascule idempotente : si `ipxe_script` ≠ "boot", on remplace puis on
# régénère `dhcpd.conf` + reload `isc-dhcp-server`. Sinon no-op.
# ============================================================================

ensure_ipxe_bootstrap_native() {
    log "Bascule PXE bootstrap → route Laravel native..."

    local conf_file="/etc/sambaedu/sambaedu.conf.d/dhcp.conf"

    if [[ ! -f "$conf_file" ]]; then
        log_warning "$conf_file absent — bascule iPXE ignorée (pas de DHCP Sambaedu sur cet hôte ?)"
        return 0
    fi

    local current
    current=$(grep -E '^ipxe_script\s*=' "$conf_file" | head -1 | sed -E 's/^ipxe_script\s*=\s*"?([^"]*)"?\s*$/\1/')

    local needs_regen=false

    if [[ "$current" == "boot" ]]; then
        log_success "ipxe_script déjà sur 'boot' (route Laravel) — pas de changement"
    else
        log "ipxe_script actuel = \"$current\" → bascule vers \"boot\""

        cp -a "$conf_file" "${conf_file}.bak-$(date +%Y%m%d-%H%M%S)"
        sed -i -E 's|^(ipxe_script\s*=\s*).*$|\1"boot"|' "$conf_file"

        local new
        new=$(grep -E '^ipxe_script\s*=' "$conf_file" | head -1 | sed -E 's/^ipxe_script\s*=\s*"?([^"]*)"?\s*$/\1/')
        if [[ "$new" != "boot" ]]; then
            log_error "Echec du sed sur $conf_file (ipxe_script = \"$new\")"
            return 1
        fi
        needs_regen=true
    fi

    # Cohérence du dhcpd.conf généré : `ipxe_script` peut être correct alors
    # que /etc/dhcp/dhcpd.conf n'a jamais été régénéré depuis (édition
    # manuelle, update interrompu…) et chaîne encore les postes PXE sur le
    # bootstrap legacy. Constaté sur VM le 2026-06-04 : une install Windows
    # est repartie en flow legacy (→ PG jamais renseigné) à cause de ça.
    local dhcpd_conf="/etc/dhcp/dhcpd.conf"
    if [[ "$needs_regen" == false ]]; then
        if [[ -f "$dhcpd_conf" ]] && grep -qE 'filename\s+"[^"]*/ipxe/boot\.php"' "$dhcpd_conf"; then
            log_warning "dhcpd.conf chaîne encore /ipxe/boot.php (désynchronisé de $conf_file) — régénération"
            needs_regen=true
        else
            log_success "dhcpd.conf cohérent (bootstrap natif)"
            return 0
        fi
    fi

    local make_script="/usr/share/sambaedu/sbin/make_dhcpd_conf.sh"
    if [[ -x "$make_script" ]]; then
        log "Régénération de /etc/dhcp/dhcpd.conf via make_dhcpd_conf.sh..."
        # Note : make_dhcpd_conf.sh régénère le fichier ET redémarre déjà
        # `isc-dhcp-server.service` en interne (cf. legacy ligne 242). Pas
        # besoin d'un reload/restart additionnel ici — et `isc-dhcp-server`
        # ne supporte de toute façon pas `reload`.
        "$make_script" || {
            log_warning "make_dhcpd_conf.sh a retourné un code d'erreur — vérifier manuellement"
            return 0
        }
    else
        log_warning "$make_script introuvable ou non exécutable — régénération dhcpd.conf à faire manuellement"
        return 0
    fi

    log_success "PXE bootstrap basculé sur route Laravel native (/ipxe/boot)"
}

# ============================================================================
# Permissions du partage [install] (lecture par le compte machine des postes)
# ============================================================================
# La tâche planifiée GPO `wpkg4` lance la post-install en SYSTEM (= compte
# machine, mappé « other » côté Samba). Si wpkg/, packages/ ou os/ ne sont pas
# o+rX, le compte machine est refusé et la post-install ne déploie rien
# (helpers absents de %PROGRAMFILES%\SambaEdu). Réparation idempotente,
# délègue au script dédié (zone d'écriture wpkg/rapports/ exclue).

ensure_install_permissions() {
    local perms_script="$SCRIPT_DIR/verify-install-permissions.sh"
    if [[ -x "$perms_script" ]]; then
        bash "$perms_script"
    else
        log_warning "verify-install-permissions.sh introuvable/non exécutable — étape ignorée"
    fi
}

# ============================================================================
# Amorçage des helpers SambaEdu dans wpkg.cmd (bootstrap %PROGRAMFILES%)
# ============================================================================
# Le déploiement des helpers .ps1/.cmd dans %PROGRAMFILES%\SambaEdu côté poste
# repose sur la chaîne applications/WPKG, qui ne peut pas s'auto-amorcer (son
# lanceur `applications-startup.cmd` vit DANS %PROGRAMFILES%\SambaEdu — œuf et
# poule), et SE5 a retiré le robocopy d'amorçage que le legacy faisait à l'OOBE.
# On réinjecte donc l'amorçage dans `wpkg.cmd` (lancé à chaque boot en SYSTEM
# par la tâche planifiée GPO `wpkg4`), juste après le MKLINK qui monte
# %WinDir%\install → \\<se4fs>\install. Idempotent ; réinjecté après une
# réinstall du paquet `sambaedu-client-windows` (qui écraserait wpkg.cmd).
#
# CRLF CRITIQUE : un .cmd en LF échoue silencieusement côté Windows. awk réémet
# explicitement `\r` sur les lignes ajoutées pour préserver les fins CRLF.

ensure_wpkg_bootstrap() {
    log "Vérification amorçage helpers dans wpkg.cmd..."

    local install_root="${SE_INSTALL_ROOT:-/var/sambaedu/unattended/install}"
    local wpkg_cmd="$install_root/wpkg/wpkg.cmd"

    if [[ ! -f "$wpkg_cmd" ]]; then
        log_warning "wpkg.cmd absent ($wpkg_cmd) — amorçage ignoré"
        return 0
    fi

    if grep -q 'ROBOCOPY.*os.SambaEdu.*ProgramFiles' "$wpkg_cmd"; then
        log_success "Amorçage helpers déjà présent dans wpkg.cmd"
        return 0
    fi

    # Ancre : la ligne MKLINK qui crée %WinDir%\install (le robocopy en dépend).
    if ! grep -q '%Windir%\\install MKLINK' "$wpkg_cmd"; then
        log_warning "Ancre MKLINK install introuvable dans wpkg.cmd — amorçage NON inséré (format inattendu)"
        return 0
    fi

    cp -a "$wpkg_cmd" "${wpkg_cmd}.bak-$(date +%Y%m%d-%H%M%S)"

    local tmp
    tmp="$(mktemp)"
    awk '
        { print }
        /%Windir%\\install MKLINK/ {
            print "REM SambaEdu: amorcage helpers dans %PROGRAMFILES% (independant chaine WPKG)\r"
            print "IF NOT EXIST \"%ProgramFiles%\\SambaEdu\" MD \"%ProgramFiles%\\SambaEdu\"\r"
            print "ROBOCOPY \"%WinDir%\\install\\os\\SambaEdu\" \"%ProgramFiles%\\SambaEdu\" /E\r"
        }
    ' "$wpkg_cmd" > "$tmp"
    # `cat >` (et non mv) pour préserver owner/perms/ACL du fichier original.
    cat "$tmp" > "$wpkg_cmd"
    rm -f "$tmp"

    if grep -q 'ROBOCOPY.*os.SambaEdu.*ProgramFiles' "$wpkg_cmd"; then
        log_success "Amorçage helpers ajouté à wpkg.cmd (robocopy os\\SambaEdu → %PROGRAMFILES%\\SambaEdu)"
    else
        log_error "Échec insertion amorçage dans wpkg.cmd — backup conservé (${wpkg_cmd}.bak-*)"
        return 1
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
    echo "  ✓ LDAP client (SASL_NOCANON)"
    echo "  ✓ PXE bootstrap (Laravel native)"
    echo "  ✓ Amorçage helpers (wpkg.cmd)"
    echo "  ✓ Permissions partage [install]"
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
    ensure_codesign_pfx

    echo ""
    setup_go

    echo ""
    ensure_agent_build

    echo ""
    update_apache

    echo ""
    reload_apache_after_pki_renewal

    echo ""
    update_systemd

    echo ""
    ensure_apcu_cli

    echo ""
    ensure_ldap_sasl_nocanon

    echo ""
    ensure_ipxe_bootstrap_native

    echo ""
    ensure_wpkg_bootstrap

    echo ""
    ensure_install_permissions

    echo ""
    run_doctor_check

    echo ""
    show_summary
}

main "$@"
