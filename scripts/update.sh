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
           && [[ -f "/etc/apache2/sites-available/sambaedu-legacy.conf" ]] \
           && grep -q "Alias /wpkg/bundle" "$APACHE_CONF_TARGET" \
           && grep -q "Alias /wpkg/files" "$APACHE_CONF_TARGET" \
           && grep -q "Alias /wpkg/tools" "$APACHE_CONF_TARGET"; then
            log_success "Apache déjà configuré pour SER (setupApache.sh)"
            return
        else
            # Vhost SER incomplet : legacy manquant OU alias /wpkg/bundle absent
            # (vhost antérieur à la Story 27.5) OU alias /wpkg/files absent (vhost
            # antérieur à la Story 27.19 — livraison HTTP des payloads WPKG) OU
            # alias /wpkg/tools absent (vhost antérieur à la Story 27.20 — outils
            # partagés WPKG) → relancer setupApache.sh pour (re)poser les aliases.
            # Idempotent.
            log_warning "Configuration Apache SER incomplète (legacy ou alias /wpkg/bundle ou /wpkg/files ou /wpkg/tools manquant) — relance de setupApache.sh"
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
# Export SMB [partages] des lecteurs réseau gérés (Epic 34)
# ============================================================================
# NetworkShareService crée les répertoires /var/sambaedu/Partages/<dir> + ACL
# POSIX, et DrivesStateProvider projette une lettre vers
# \\<se4fs>\partages\<dir>\. Mais le partage Samba `[partages]` qui expose
# /var/sambaedu/Partages n'est livré ni par le code, ni par le paquet Debian
# `sambaedu` (qui ne fournit que [users]/[classes]/[docs]/[progs]). Sans lui,
# l'agent reçoit la lettre mais le montage échoue (WNetAddConnection2 code=67
# « Nom de réseau introuvable »).
#
# On internalise donc ici le provisioning, idempotent : dépôt du stanza
# versionné dans /etc/samba/smb.conf.d/partages.conf + directive `include`
# en fin de smb.conf (un fichier précis — `include` ne globe pas), puis
# validation testparm + reload smbd. Profite aussi aux parcs déjà installés
# (update.sh rejoué à chaque déploiement).
# ============================================================================

ensure_samba_partages_share() {
    log "Vérification de l'export SMB [partages] (lecteurs réseau gérés)..."

    local src="$APP_DIR/scripts/config/smb-partages.conf"
    local smb_conf="/etc/samba/smb.conf"
    local smb_conf_d="/etc/samba/smb.conf.d"
    local dst="$smb_conf_d/partages.conf"
    local include_line="include = $dst"
    local shares_root="/var/sambaedu/Partages"

    if [[ ! -f "$smb_conf" ]]; then
        log_warning "$smb_conf absent (Samba non installé sur cet hôte ?) — export [partages] ignoré"
        return 0
    fi
    if [[ ! -f "$src" ]]; then
        log_error "Fichier source manquant: $src"
        return 1
    fi

    # Racine du partage : NetworkShareService la crée au 1er provision(), mais
    # sur un déploiement neuf (aucun share encore) le path du share Samba
    # n'existerait pas. On la garantit ici (idempotent ; 0755 root:root via
    # umask root → traversable par les participants ro).
    mkdir -p "$shares_root"

    # Stanza versionné → smb.conf.d (autoritaire : on écrase à chaque update).
    mkdir -p "$smb_conf_d"
    install -o root -g root -m 0644 "$src" "$dst"

    # Directive include en [global]. `include` n'est pas un paramètre de
    # section : on l'append en fin de fichier (le stanza inclus rouvre sa
    # propre section [partages]). Idempotent : on n'ajoute qu'une fois.
    if grep -qE "^[[:space:]]*include[[:space:]]*=[[:space:]]*${dst//\//\\/}([[:space:]]|$)" "$smb_conf"; then
        log "  → include déjà présent dans $smb_conf"
    else
        cp -a "$smb_conf" "${smb_conf}.bak-$(date +%Y%m%d-%H%M%S)"
        {
            echo ""
            echo "# SambaEdu (Epic 34) — export SMB des lecteurs réseau gérés."
            echo "# Inclusion d'un fichier précis (la directive include ne globe pas)."
            echo "$include_line"
        } >> "$smb_conf"
        log "  → include ajouté dans $smb_conf"
    fi

    # Validation : le partage [partages] doit être effectif et pointer sur la
    # racine attendue. testparm renvoie rc=1 si la section est absente.
    local effective_path
    if ! effective_path="$(testparm -s --section-name partages --parameter-name path 2>/dev/null)"; then
        log_error "Validation testparm KO : le partage [partages] n'est pas effectif après injection. Vérifier $smb_conf et $dst."
        return 1
    fi
    if [[ "$effective_path" != "$shares_root" ]]; then
        log_error "[partages] pointe sur \"$effective_path\" au lieu de \"$shares_root\"."
        return 1
    fi

    # Reload à chaud (pas de coupure). smbcontrol si smbd tourne, sinon
    # systemctl reload ; non-fatal (la conf sera lue au prochain démarrage).
    if command -v smbcontrol >/dev/null 2>&1 && smbcontrol all reload-config >/dev/null 2>&1; then
        log "  → smbd: configuration rechargée (smbcontrol)"
    elif systemctl reload smbd >/dev/null 2>&1; then
        log "  → smbd: configuration rechargée (systemctl)"
    else
        log_warning "  → reload smbd non effectué (smbd non démarré ?) — la conf sera prise au prochain démarrage"
    fi

    log_success "Export SMB [partages] → $shares_root OK"
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
# Statiques iPXE servis par l'alias Apache /ipxe (Story 38.1)
# ============================================================================
# Les statiques iPXE (boot.ipxe, png/ipxe-se4.png, diconf/*, binaires
# undionly.kpxe / snponly_x64.efi) vivaient sous /var/www/sambaedu/ipxe (legacy)
# et étaient servis par l'alias Apache /ipxe. Pour rendre /var/www/sambaedu
# supprimable (Epic 38 — extinction SE4), ils sont désormais VERSIONNÉS dans le
# repo (resources/ipxe/static/) et provisionnés ici vers storage/ipxe/static/
# (emplacement hors legacy servi par l'alias repointé dans setupApache.sh).
#
# Deux volets :
#   1. Publier resources/ipxe/static/ → storage/ipxe/static/ (chown www-admin,
#      lisible Apache « other » — sinon 404 silencieux, cf. project_php_fpm_user_www_admin).
#   2. GREENFIELD TFTP : déposer undionly.kpxe / snponly_x64.efi dans
#      /var/lib/tftpboot/ SEULEMENT s'ils y sont absents ou différents
#      (cmp -s || install). Sur la VM actuelle c'est un no-op (fichiers identiques,
#      propriété du paquet Debian sambaedu-boot-server) ; sur un hôte vierge sans
#      les paquets SE4 cela rend le TFTP autonome. On NE touche PAS la config
#      atftpd (racine /var/lib/tftpboot inchangée). FAIL-SOFT : /var/lib/tftpboot
#      absent → log_warning + continuer (hôte sans serveur TFTP). IDEMPOTENT.

ensure_ipxe_statics() {
    log "Provisioning des statiques iPXE (Story 38.1)..."

    local src="$APP_DIR/resources/ipxe/static"
    local dest="$APP_DIR/storage/ipxe/static"

    if [[ ! -d "$src" ]]; then
        log_warning "Source des statiques iPXE absente ($src) — provisioning ignoré"
        return 0
    fi

    # ── 1. Publier resources/ipxe/static/ → storage/ipxe/static/ ─────────────
    mkdir -p "$dest"
    # cp -a préserve l'arborescence (boot.ipxe, diconf/, png/, binaires) ;
    # src/. copie le CONTENU (pas le dossier lui-même). Re-copie idempotente.
    cp -a "$src"/. "$dest"/

    # Miroir strict : un fichier retiré de resources/ipxe/static/ ne doit plus
    # être servi (sinon il resterait exposé en HTTP anonyme indéfiniment).
    # Purge fichier par fichier (pas de rm -rf), puis dossiers vides.
    (
        cd "$dest" || exit 0
        find . -type f | while IFS= read -r f; do
            [[ -f "$src/$f" ]] || rm -f "$f"
        done
        find . -depth -mindepth 1 -type d -empty -exec rmdir {} \; 2>/dev/null
    )

    if id www-admin >/dev/null 2>&1; then
        chown -R www-admin:www-admin "$dest" 2>/dev/null \
            || log_warning "chown www-admin échoué sur $dest (risque de 404 Apache)"
    fi
    # Lisible Apache (« other » : r sur fichiers, rx sur dossiers). u+rwX conserve
    # l'écriture propriétaire ; X = exécution seulement sur dossiers. Le parent
    # storage/ipxe/ créé par mkdir -p hérite de l'umask courant : le chmoder
    # explicitement, sinon umask durci (027) = traversée Apache perdue → 404
    # silencieux sur TOUS les statiques.
    chmod u+rwX,go+rX "$APP_DIR/storage/ipxe" 2>/dev/null || true
    chmod -R u+rwX,go+rX "$dest" 2>/dev/null || true
    log_success "Statiques iPXE publiés dans $dest (chown www-admin, lisible Apache)"

    # ── 2. Greenfield TFTP : déposer les binaires s'ils manquent/diffèrent ────
    local tftp_dir="/var/lib/tftpboot"
    if [[ ! -d "$tftp_dir" ]]; then
        log_warning "Racine TFTP absente ($tftp_dir) — dépôt greenfield des binaires iPXE ignoré (hôte sans serveur TFTP ?)"
        return 0
    fi

    local bin changed=0
    for bin in undionly.kpxe snponly_x64.efi; do
        if [[ ! -f "$src/$bin" ]]; then
            log_warning "Binaire iPXE source absent ($src/$bin) — non déposé dans le TFTP"
            continue
        fi
        # cmp -s : identique → no-op (cas VM, fichiers du paquet sambaedu-boot-server).
        if cmp -s "$src/$bin" "$tftp_dir/$bin"; then
            continue
        fi
        install -m 644 "$src/$bin" "$tftp_dir/$bin"
        changed=1
        log "Binaire TFTP déposé/mis à jour : $tftp_dir/$bin"
    done

    if [[ "$changed" -eq 0 ]]; then
        log_success "Binaires iPXE TFTP déjà présents et identiques ($tftp_dir) — aucun changement"
    else
        log_success "Binaires iPXE greenfield déposés dans $tftp_dir (644)"
    fi
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
# Dossiers d'écriture AppStore (install native d'apps) — ownership www-admin
# ============================================================================
# L'install native d'apps (InstallApplicationJob → AppStoreService →
# PackageInstallerService) télécharge dans wpkg/tmp2/ puis rename() vers
# packages/<app>/<fichier>, en CRÉANT le sous-dossier par-app (mkdir). Ces deux
# dossiers doivent donc être INSCRIPTIBLES par www-admin (uid 599, user PHP-FPM +
# workers). Sur un partage [install] legacy, packages/ naît souvent root:root 755
# → le mkdir du sous-dossier échoue → install KO (« Echec deplacement … »,
# InstallationLog failed à 20%). On garantit ici l'ownership www-admin du dossier
# lui-même (NON récursif : on ne réattribue pas les assets legacy déjà présents ;
# la lecture poste o+rX est posée juste après par ensure_install_permissions).
# Concern ÉCRITURE, volontairement distinct de verify-install-permissions.sh qui
# ne gère que la lecture « other » et ne touche jamais owner/group. Idempotent.
ensure_appstore_write_dirs() {
    log "Vérification dossiers d'écriture AppStore (packages/, wpkg/tmp2/)..."

    local install_root="${SE_INSTALL_ROOT:-/var/sambaedu/unattended/install}"
    if [[ ! -d "$install_root" ]]; then
        # Hôte de dev (pas la VM) : ce chemin n'existe pas → sortie propre.
        log_warning "Racine partage absente ($install_root) — étape ignorée"
        return 0
    fi

    if ! id www-admin &>/dev/null; then
        log_warning "Compte www-admin absent — ownership AppStore non appliqué"
        return 0
    fi

    local d
    for d in "$install_root/packages" "$install_root/wpkg/tmp2"; do
        [[ -d "$d" ]] || mkdir -p "$d"
        if [[ "$(stat -c '%U' "$d")" != "www-admin" ]]; then
            if chown www-admin:www-admin "$d"; then
                log_success "Ownership corrigé : $d → www-admin"
            else
                log_error "Échec chown $d (droits insuffisants ?)"
            fi
        fi
    done
    log_success "Dossiers d'écriture AppStore prêts (inscriptibles par www-admin)"
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
# Bundle WPKG natif SE5 (Story 27.5) — génération (servi statiquement)
# ============================================================================
# `php artisan wpkg:bundle` (ré)génère le bundle WPKG natif pré-substitué
# (scripts versionnés resources/wpkg/* + packages.xml avec SE4FS_NAME résolu)
# dans le sous-dossier PUBLIC servi en statique par Apache (alias /wpkg/bundle
# posé par setupApache.sh). À régénérer à chaque déploiement : SE4FS_NAME / la
# conf serveur peuvent changer, et les scripts versionnés évoluent.
#
# Propriété des fichiers : on GÉNÈRE directement sous www-admin (uid 599) via
# `sudo -u` — update.sh tournant en root, c'est sans mot de passe (même pattern
# que run_doctor_check). Les fichiers naissent donc lisibles par Apache : AUCUN
# chown séparé, et c'est l'exact user du runtime PHP-FPM en prod. Sans ce bon
# propriétaire, Apache servirait le bundle en 404 silencieux (convention storage
# non versionnée). Fallback root + chown si le compte www-admin est absent.
# Idempotent : le générateur réécrit tout (écriture atomique tmp + rename).

ensure_wpkg_bundle() {
    log "Génération du bundle WPKG natif (Story 27.5)..."
    cd "$APP_DIR"

    if ! php artisan list 2>/dev/null | grep -q 'wpkg:bundle'; then
        log_warning "Commande wpkg:bundle non disponible (Story 27.5 pas déployée) — étape ignorée"
        return 0
    fi

    if id www-admin >/dev/null 2>&1; then
        # Génération sous www-admin → fichiers nés avec le bon propriétaire,
        # pas de chown. sudo -u sans mot de passe puisqu'on est root.
        if ! sudo -u www-admin php artisan wpkg:bundle; then
            log_error "Échec génération bundle WPKG (sudo -u www-admin) — livraison WPKG native indisponible"
            return 1
        fi
        log_success "Bundle WPKG généré sous www-admin (lisible Apache, pas de chown requis)"
    else
        # Pas de compte www-admin (cas dégradé) : génération en root, puis chown
        # défensif. Chemin = override .env (AGENT_WPKG_BUNDLE_PATH) sinon défaut
        # config/agent.php (storage/app/public/wpkg/bundle).
        log_warning "User www-admin absent — génération en root + chown a posteriori"
        if ! php artisan wpkg:bundle; then
            log_error "Échec génération bundle WPKG — livraison WPKG native indisponible"
            return 1
        fi
        local bundle_path
        bundle_path="$(grep -oP '^AGENT_WPKG_BUNDLE_PATH=\K.*' "$APP_DIR/.env" 2>/dev/null || true)"
        bundle_path="${bundle_path:-$APP_DIR/storage/app/public/wpkg/bundle}"
        if [[ -d "$bundle_path" ]]; then
            chown -R www-admin:www-admin "$bundle_path" 2>/dev/null \
                && log_success "Bundle WPKG généré + chown www-admin ($bundle_path)" \
                || log_warning "Bundle généré mais chown échoué ($bundle_path) — risque de 404 Apache"
        fi
    fi
}

# ============================================================================
# Provisioning du client WPKG dans le partage SMB d'install (greenfield)
# ============================================================================
# `wpkg-client.vbs` / `wpkg-se4.js` sont normalement livrés par le `.deb`
# `sambaedu-wpkg` dans /var/sambaedu/unattended/install/wpkg/ (servi en SMB
# \\SE4FS\install\wpkg). L'install iPXE (resources/views/ipxe/windows/cmd/
# wpkg.blade.php, branche :autologon) les copie de là vers %WinDir%. Sur une VM
# GREENFIELD sans ce `.deb`, le dossier SMB n'a pas ces fichiers → le `copy`
# échoue en SILENCE → le poste reste sans client WPKG → l'agent ne peut JAMAIS
# déclencher WPKG (« wpkg-client.vbs introuvable »). On MIROITE donc le bundle
# natif (généré juste avant par ensure_wpkg_bundle) vers le partage SMB à chaque
# update/install : plus aucune dépendance au `.deb` pour ces scripts.
#
# World-readable (644) OBLIGATOIRE : le poste est mappé classe SMB « other » —
# un fichier non lisible par « other » échoue en silence (cf. payloads WPKG
# world-readable). On NE touche PAS `wpkg.cmd` du partage : il est patché par
# ensure_wpkg_bootstrap (variante legacy distincte du bundle HTTP) — l'écraser
# perdrait l'amorçage helpers. Idempotent (re-copie). Fail-soft : sources/dir
# absents → warn, jamais d'échec d'update.

ensure_wpkg_smb_client() {
    log "Provisioning client WPKG dans le partage SMB d'install (greenfield)..."

    local install_root="${SE_INSTALL_ROOT:-/var/sambaedu/unattended/install}"
    local smb_dir="$install_root/wpkg"
    local bundle_path
    bundle_path="$(grep -oP '^AGENT_WPKG_BUNDLE_PATH=\K.*' "$APP_DIR/.env" 2>/dev/null || true)"
    bundle_path="${bundle_path:-$APP_DIR/storage/app/public/wpkg/bundle}"

    if [[ ! -d "$smb_dir" ]]; then
        log_warning "Partage SMB install absent ($smb_dir) — provisioning client WPKG ignoré"
        return 0
    fi

    local own=()
    id www-admin >/dev/null 2>&1 && own=(-o www-admin -g www-admin)

    local f missing=0
    for f in wpkg-client.vbs wpkg-se4.js; do
        if [[ ! -f "$bundle_path/$f" ]]; then
            log_warning "Source bundle absente ($bundle_path/$f) — non miroité (bundle non généré ?)"
            missing=1
            continue
        fi
        install "${own[@]}" -m 644 "$bundle_path/$f" "$smb_dir/$f"
    done

    if [[ "$missing" -eq 0 ]]; then
        log_success "Client WPKG miroité vers le partage SMB ($smb_dir : wpkg-client.vbs, wpkg-se4.js, 644)"
    else
        log_warning "Client WPKG partiellement miroité — vérifier ensure_wpkg_bundle (génération du bundle)"
    fi
}

# ============================================================================
# Outils WPKG PARTAGÉS servis en HTTP (Story 27.20) — perms world-readable
# ============================================================================
# Les recettes WPKG invoquent des OUTILS partagés via le chemin EN DUR
# %Z%\wpkg\tools\… (7za.exe, nircmd.exe, md5sum/wintail, tooltip/*). Ce ne sont
# PAS des payloads par-app (aucun <download saveto>) : 27.19 ne les couvre pas.
# 27.20 les sert en HTTP (alias Apache /wpkg/tools, posé par setupApache.sh) et
# le poste les dépose une fois sous %WinDir%\install\wpkg\tools\ via wpkg.cmd.
#
# Source serveur : /var/sambaedu/unattended/install/wpkg/tools/ — DÉJÀ peuplé par
# le `.deb` legacy `sambaedu-wpkg` (on ne re-livre PAS les binaires : ce ne sont
# pas des assets versionnés du repo). Cette fonction garantit seulement les DROITS
# pour qu'Apache les serve : world-readable (664 fichiers / 755 dossiers, le poste
# est mappé classe SMB « other » côté legacy ET classe « other » Unix côté Apache —
# un 660 échouerait en SILENCE, cf. payloads WPKG) + owner www-admin (sinon 404
# silencieux). Sous-arbre tooltip/ préservé (find récursif). IDEMPOTENT (re-chmod).
# FAIL-SOFT : dossier absent (VM greenfield sans le .deb) → WARNING explicite,
# JAMAIS d'échec d'update — les recettes qui dépendent de ces outils échoueront
# alors côté poste (diagnosticable dans wpkg.log), pas l'update serveur.

ensure_wpkg_tools() {
    log "Provisioning des droits sur les outils WPKG partagés (Story 27.20)..."

    local install_root="${SE_INSTALL_ROOT:-/var/sambaedu/unattended/install}"
    local tools_dir="$install_root/wpkg/tools"

    if [[ ! -d "$tools_dir" ]]; then
        log_warning "Répertoire des outils WPKG absent ($tools_dir) — outils partagés non servis (.deb sambaedu-wpkg non installé ?). Les recettes utilisant %Z%\\wpkg\\tools\\ (7za, nircmd) échoueront côté poste."
        return 0
    fi

    if id www-admin >/dev/null 2>&1; then
        chown -R www-admin:www-admin "$tools_dir" 2>/dev/null || \
            log_warning "chown www-admin échoué sur $tools_dir (outils peuvent rester servis en 404)"
    fi
    # 664 fichiers (lisibles « other »), 755 dossiers. Sous-arbre tooltip/ inclus.
    find "$tools_dir" -type f -exec chmod 664 {} \; 2>/dev/null || true
    find "$tools_dir" -type d -exec chmod 755 {} \; 2>/dev/null || true

    # ── manifest.json (Story 27.20, pivot agent-driven) ──────────────────────
    # L'AGENT pilote le staging des outils : il fetch ce manifeste AVANT de
    # déclencher WPKG, réconcilie PAR HASH (sha256) et dépose sous
    # %WinDir%\install\wpkg\tools\. On l'énumère ici (sous-arbre tooltip/ inclus),
    # on calcule le sha256 par fichier, et on écrit un tableau JSON aligné sur
    # provision.Resource côté agent : [{id, kind:"wpkg-tool", relpath, sha256}].
    # relpath = chemin RELATIF à tools_dir (slashes Unix, l'agent compose l'URL =
    # toolsURL + "/" + relpath et préserve la sous-arbo). Le manifeste lui-même est
    # exclu de l'énumération (jamais une ressource). World-readable 664 + www-admin
    # (servi en « other » par Apache, comme les outils). IDEMPOTENT (réécrit à chaque
    # update). Régénéré atomiquement (tmp + mv) — l'agent ne lit jamais un demi-fichier.
    local manifest="$tools_dir/manifest.json"
    # tmp HORS de tools_dir : sinon le `find` ci-dessous l'énumérerait (course).
    local manifest_tmp
    manifest_tmp="$(dirname "$tools_dir")/.wpkg-tools-manifest.$$.json.tmp"
    {
        echo "["
        local first=1
        # -print0 / read -d '' : robustesse aux espaces dans les noms.
        while IFS= read -r -d '' f; do
            local rel sha
            rel="${f#"$tools_dir"/}"          # chemin relatif à tools_dir.
            rel="${rel//\\//}"                # normalise en slashes Unix (défensif).
            [[ "$rel" == "manifest.json" ]] && continue   # ne pas s'auto-référencer.
            sha=$(sha256sum "$f" | awk '{print $1}')
            local id="${rel##*/}"             # nom de fichier nu = id lisible.
            # Échappement JSON défensif (backslash puis guillemet) : un nom de
            # fichier contenant `"` produirait sinon un JSON malformé que l'agent
            # rejetterait (fail-soft, mais diagnostic serveur difficile). sha = hex,
            # sûr ; rel déjà normalisé en slashes Unix ci-dessus.
            local id_esc="${id//\\/\\\\}";  id_esc="${id_esc//\"/\\\"}"
            local rel_esc="${rel//\\/\\\\}"; rel_esc="${rel_esc//\"/\\\"}"
            if [[ $first -eq 0 ]]; then echo "  ,"; fi
            first=0
            printf '  { "id": "%s", "kind": "wpkg-tool", "relpath": "%s", "sha256": "%s" }\n' \
                "$id_esc" "$rel_esc" "$sha"
        done < <(find "$tools_dir" -type f ! -name 'manifest.json' -print0 2>/dev/null)
        echo "]"
    } > "$manifest_tmp" 2>/dev/null

    # Branche sur le résultat RÉEL du mv : un log_success inconditionnel masquerait
    # un manifeste non écrit (droits/FS plein) → l'opérateur croit le manifeste en
    # place alors que l'agent fetcherait un 404/une version périmée.
    if mv -f "$manifest_tmp" "$manifest" 2>/dev/null; then
        chmod 664 "$manifest" 2>/dev/null || true
        if id www-admin >/dev/null 2>&1; then
            chown www-admin:www-admin "$manifest" 2>/dev/null || true
        fi
        local tools_count
        tools_count=$(find "$tools_dir" -type f ! -name 'manifest.json' | wc -l)
        log_success "Outils WPKG partagés provisionnés ($tools_dir : $tools_count fichiers en 664, owner www-admin, sous-arbre tooltip/ inclus, manifest.json généré pour le staging agent-driven)"
    else
        rm -f "$manifest_tmp" 2>/dev/null || true
        log_warning "manifest.json NON écrit ($manifest) — l'agent restera en fail-soft (pas de staging d'outils) au prochain cycle ; vérifier les droits/l'espace disque sur $tools_dir"
    fi
}

# ============================================================================
# Outils agent OBLIGATOIRES embarqués (Story 27.17) — provisioning fail-soft
# ============================================================================
# La couche « config par défaut du parc » (Broadcast) comporte des éléments
# OBLIGATOIRES (`required`) à garantir présents côté serveur. Aujourd'hui : le
# portable Rainmeter, EMBARQUÉ dans le dépôt
# (`resources/agent/tools/sambaedu-rainmeter-*.zip`), enregistré dans `agent_tools`
# via le SEUL écrivain `AgentToolService` (clé `rainmeter`) si absent.
#
# Calque ensure_wpkg_bundle/ensure_wpkg_smb_client :
#   - IDEMPOTENT : la commande artisan ne (ré)enregistre rien si la clé existe ;
#   - FAIL-SOFT : un `required` sans source résolvable → WARNING explicite, JAMAIS
#     d'échec d'install/update (la commande sort 0, on protège quand même par `|| true`) ;
#   - HASH-VÉRIFIÉ : le SHA-256 est calculé SERVEUR par le service (jamais déclaré) ;
#   - WORLD-READABLE (644) + owner www-admin sur le répertoire des outils
#     (AGENT_TOOLS_PATH, défaut storage/agent/tools) : l'artefact est servi à
#     l'agent via une route authentifiée, mais on aligne les droits sur la
#     convention des payloads (lisible par le user PHP-FPM www-admin).
#
# Le déploiement effectif au parc reste un GESTE ADMIN explicite (toggle « activer »
# dans /admin/settings/parc-defaults, onglet « Outils agent ») : on n'active pas
# l'outil automatiquement ici (premier enregistrement = désactivé).

ensure_agent_required_tools() {
    log "Enregistrement des outils agent obligatoires embarqués (Story 27.17)..."
    cd "$APP_DIR"

    if ! php artisan list 2>/dev/null | grep -q 'agent:tools:register-defaults'; then
        log_warning "Commande agent:tools:register-defaults non disponible (Story 27.17 pas déployée) — étape ignorée"
        return 0
    fi

    # Enregistrement idempotent fail-soft (la commande sort 0 même sans source ;
    # `|| true` en filet supplémentaire — un required NE casse JAMAIS l'update).
    if id www-admin >/dev/null 2>&1; then
        sudo -u www-admin php artisan agent:tools:register-defaults || true
    else
        php artisan agent:tools:register-defaults || true
    fi

    # Droits du répertoire des outils : world-readable (644) + owner www-admin.
    local tools_path
    tools_path="$(grep -oP '^AGENT_TOOLS_PATH=\K.*' "$APP_DIR/.env" 2>/dev/null || true)"
    tools_path="${tools_path:-$APP_DIR/storage/agent/tools}"

    if [[ -d "$tools_path" ]]; then
        if id www-admin >/dev/null 2>&1; then
            chown -R www-admin:www-admin "$tools_path" 2>/dev/null || \
                log_warning "chown www-admin échoué sur $tools_path (artefact agent peut rester non servi)"
        fi
        # 644 sur les fichiers (lisibles « other »), 755 sur les dossiers.
        find "$tools_path" -type f -exec chmod 644 {} \; 2>/dev/null || true
        find "$tools_path" -type d -exec chmod 755 {} \; 2>/dev/null || true
        log_success "Outils agent provisionnés ($tools_path : 644 fichiers, owner www-admin)"
    else
        log_warning "Répertoire des outils agent absent ($tools_path) — rien à provisionner (portable embarqué non enregistré ?)"
    fi
}

# ============================================================================
# Synchronisation du catalogue WPKG « système » (Story 27.17 — AC12a)
# ============================================================================
# La source par défaut des paquets WPKG « système » est le dépôt PRIMAIRE déjà
# seedé (`depots.url = http://deb.sambaedu.org/wpkg/xml/packages.xml`,
# DepotApplicationSeeder, is_primary=true). `php artisan appstore:sync` télécharge
# ce packages.xml distant et rafraîchit le catalogue DISPONIBLE
# (table `depot_applications`) — il n'INSTALLE ni ne POUSSE rien : choisir,
# installer et marquer un paquet « obligatoire / défaut parc » (`is_parc_default`)
# reste un GESTE ADMIN explicite (décision Henri 27.17 #8).
#
# Calque ensure_agent_required_tools :
#   - IDEMPOTENT : `appstore:sync` ré-upsert sans dupliquer (skip si hash inchangé) ;
#   - FAIL-SOFT : dépôt HTTP injoignable / réseau coupé → la commande log et sort,
#     `|| true` en filet — une sync ratée NE casse JAMAIS l'update ;
#   - www-admin : exécuté sous le user PHP-FPM pour cohérence des écritures.

ensure_appstore_catalog_sync() {
    log "Synchronisation du catalogue WPKG système (Story 27.17 — dépôt primaire)..."
    cd "$APP_DIR"

    if ! php artisan list 2>/dev/null | grep -q 'appstore:sync'; then
        log_warning "Commande appstore:sync non disponible — synchronisation du catalogue ignorée"
        return 0
    fi

    # Sync fail-soft : un dépôt distant injoignable NE casse JAMAIS l'update.
    if id www-admin >/dev/null 2>&1; then
        sudo -u www-admin php artisan appstore:sync || \
            log_warning "Sync du catalogue WPKG échouée (dépôt injoignable ?) — catalogue inchangé, update poursuivi"
    else
        php artisan appstore:sync || \
            log_warning "Sync du catalogue WPKG échouée (dépôt injoignable ?) — catalogue inchangé, update poursuivi"
    fi
}

# ============================================================================
# GPO bootstrap agent (Story 27.16) — déploiement automatisé idempotent fail-soft
# ============================================================================
# `php artisan gpo:deploy-agent-bootstrap` publie la GPO-dispatcher figée
# `SE_agent_bootstrap` (filet éternel FR25/#27 : un poste agent-less se réinstalle
# l'agent au boot suivant) et isole nos postes des GPO legacy (blocage d'héritage
# + lien sur l'OU computers de l'établissement). La commande est :
#   - IDEMPOTENTE (republication best-effort, pas de doublon GPO) ;
#   - FAIL-SOFT (garde interne) : si le DC est injoignable ou `admin_passwd`
#     absent, elle warn et sort en 0 (skip) — la GPO sera reprise au prochain
#     passage. On NE met donc PAS --strict ici : un échec NE casse JAMAIS l'update.
# Prérequis pour une publication effective : DC AD joignable + `admin_passwd`
# (Domain Admin) dans la config (.env SAMBAEDU_ADMIN_PASSWD / sambaedu.conf).
# Cf. docs/runbooks/gpo-se4-agent-bootstrap.md.

ensure_agent_bootstrap_gpo() {
    log "Déploiement GPO bootstrap agent (Story 27.16)..."
    cd "$APP_DIR"

    if ! php artisan list 2>/dev/null | grep -q 'gpo:deploy-agent-bootstrap'; then
        log_warning "Commande gpo:deploy-agent-bootstrap non disponible (Story 27.16 pas déployée) — étape ignorée"
        return 0
    fi

    # Exécution sous www-admin (uid 599) quand présent : aligne le user runtime
    # PHP-FPM et le ccache Kerberos temporaire. La commande gère elle-même son
    # contexte Administrator (kinit dédié). Non bloquante : `|| true` en filet,
    # mais la garde fail-soft interne renvoie déjà 0 sur DC/creds absents.
    # Décision Henri (review 27.16 #10) : on GARDE www-admin pour la cohérence
    # avec les autres appels artisan d'update.sh (doctor, etc.). Le ticket
    # Administrator (kinit interne) rend l'uid indifférent pour l'écriture SYSVOL ;
    # PRÉREQUIS : www-admin doit pouvoir exécuter `kinit` et `smbclient`.
    if id www-admin >/dev/null 2>&1; then
        sudo -u www-admin php artisan gpo:deploy-agent-bootstrap || true
    else
        php artisan gpo:deploy-agent-bootstrap || true
    fi

    log_success "Étape GPO bootstrap agent terminée (voir sortie ci-dessus : déployé / skip / échec non bloquant)"
}

# ============================================================================
# Story 38.5 — Re-possession SE5 du cron système (lignes vitales)
# ============================================================================
# Provisionne /etc/cron.d/sambaedu-system (renew_ticket ×2 dont @reboot + smbstatus).
# Ces 3 lignes NE servent PAS le web legacy mais sont VITALES :
#  - renew_ticket.sh : ticket Kerberos www-sambaedu requis pour l'écriture SYSVOL
#    (project_sysvol_write_needs_wwwadmin_kinit) ; @reboot = ccache avant tout write
#    post-boot (pas d'équivalent scheduler Laravel propre, feedback_no_overengineered_choices).
#  - smbstatus.sh : produit /tmp/smbstatus, LU par UserSessionsService.
# DOIT être appelée AVANT ensure_legacy_crons_retired (T1.4 : zéro fenêtre sans ticket).
# Provision idempotente (rendu identique → pas de réécriture).

ensure_system_cron() {
    log "Provisioning du cron système SambaEdu (renew_ticket, smbstatus)..."

    local src="$APP_DIR/scripts/config/sambaedu-system.cron"
    local dst="/etc/cron.d/sambaedu-system"

    if [[ ! -f "$src" ]]; then
        log_warning "Source du cron système absente ($src) — provisioning ignoré"
        return 0
    fi

    # Lignes statiques (aucun rendu __PHP_BIN__) : diff -q avant écriture.
    if [[ ! -f "$dst" ]] || ! diff -q "$src" "$dst" >/dev/null 2>&1; then
        log "Mise à jour cron système: $dst"
        cp "$src" "$dst"
        chown root:root "$dst"
        chmod 644 "$dst"
        log_success "Cron système provisionné dans $dst"
    else
        log_success "Cron système déjà à jour ($dst)"
    fi
}

# ============================================================================
# Story 38.5 — Retrait des crons legacy (débranchement du web legacy)
# ============================================================================
# Retire EXACTEMENT les 3 fichiers cron.d qui invoquent le web legacy via
# action_cron_php.sh (curl http://<name>/<page>.php). Liste EXPLICITE — JAMAIS un
# glob sambaedu-* qui avalerait sambaedu-{scheduler,system,boot-server}.
#  - sambaedu-boot-server est EXCLU : make_dhcpd_conf est vivant, son remplacement
#    appartient à Story 8.3 (gating). Le retirer casserait DHCP/DNS.
#  - sambaedu-scheduler et sambaedu-system sont SE5 (intouchables).
# Retrait réversible par `mv` vers /var/backups/sambaedu-legacy-crons/ (JAMAIS rm -rf).
# Idempotent (fichier absent → no-op) et rejoué à CHAQUE update : couvre une
# réapparition par conffile (`apt reinstall sambaedu-web-common` reposerait le fichier).

ensure_legacy_crons_retired() {
    log "Retrait des crons legacy (débranchement du web legacy, Story 38.5)..."

    local backup_dir="/var/backups/sambaedu-legacy-crons"
    # Liste EXPLICITE — ne JAMAIS remplacer par un glob.
    local legacy_crons=(
        "sambaedu-web-common"
        "sambaedu-shares"
        "sambaedu-wpkg"
    )

    local removed=0
    local cron_name cron_path dst
    for cron_name in "${legacy_crons[@]}"; do
        cron_path="/etc/cron.d/${cron_name}"
        if [[ ! -f "$cron_path" ]]; then
            continue
        fi

        mkdir -p "$backup_dir"
        dst="${backup_dir}/${cron_name}"
        # Suffixe horodaté si une sauvegarde du même nom existe déjà (collision).
        if [[ -e "$dst" ]]; then
            dst="${dst}.$(date +%Y%m%d%H%M%S)"
        fi

        mv "$cron_path" "$dst"
        log_success "Cron legacy retiré : $cron_path → $dst"
        removed=$((removed + 1))
    done

    if (( removed == 0 )); then
        log_success "Aucun cron legacy présent (déjà retirés)"
    else
        log_success "Crons legacy retirés : $removed (réversibles depuis $backup_dir)"
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
    echo "  ✓ Bundle WPKG natif (généré sous www-admin)"
    echo "  ✓ Outils agent obligatoires (Rainmeter embarqué)"
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
    ensure_samba_partages_share

    echo ""
    ensure_ipxe_bootstrap_native

    echo ""
    ensure_ipxe_statics

    echo ""
    ensure_wpkg_bootstrap

    echo ""
    ensure_wpkg_bundle

    echo ""
    ensure_wpkg_smb_client

    echo ""
    ensure_wpkg_tools

    echo ""
    ensure_agent_required_tools

    echo ""
    ensure_agent_bootstrap_gpo

    # Story 38.5 : provisionner le cron système AVANT de retirer les crons legacy
    # (T1.4 — zéro fenêtre sans ticket Kerberos www-sambaedu pour l'écriture SYSVOL).
    echo ""
    ensure_system_cron

    echo ""
    ensure_legacy_crons_retired

    echo ""
    ensure_appstore_write_dirs

    echo ""
    ensure_appstore_catalog_sync

    echo ""
    ensure_install_permissions

    echo ""
    run_doctor_check

    echo ""
    show_summary
}

main "$@"
