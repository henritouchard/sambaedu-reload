#!/bin/bash

#===============================================================================
# SE4FS - Script de Setup Intelligent (From Scratch ou Update)
#===============================================================================
#
# DESCRIPTION:
#   Script intelligent qui détecte automatiquement le contexte d'exécution :
#   - FROM SCRATCH : Migration complète legacy → Laravel
#   - UPDATE : Mise à jour d'une installation Laravel existante
#
# MODES D'EXÉCUTION:
#
#   1. FROM SCRATCH (détection auto si /var/www/sambaedu/laravel n'existe pas)
#      - Clone du repo Git
#      - Setup .env (réutilisation credentials MySQL → PostgreSQL)
#      - Installation Docker + PostgreSQL
#      - Migration base de données
#      - Sauvegarde éléments cruciaux (apache conf, /var/www/sambaedu)
#      - Copie repo vers /var/www/sambaedu/
#      - Installation dépendances Composer
#      - Optimisations Laravel
#      - Configuration systemd workers + Apache
#
#   2. UPDATE (détection auto si /var/www/sambaedu/laravel existe)
#      - Vérification/mise à jour services systemd
#      - Vérification/mise à jour configuration Apache
#      - Exécution migrations
#      - Installation/mise à jour dépendances Composer
#      - Optimisations Laravel
#      - Vérification .env vs .env.example (nouvelles variables)
#
# UTILISATION:
#   sudo ./setup-v2.sh [--force-fresh|--force-update]
#
# OPTIONS:
#   --force-fresh   Force le mode FROM SCRATCH même si Laravel existe
#   --force-update  Force le mode UPDATE même si Laravel n'existe pas
#
# PRÉREQUIS:
#   - Exécution en tant que root (sudo)
#   - Connexion Internet (pour Docker, Composer, etc.)
#
# AUTEUR: Équipe SE4FS
# VERSION: 2.0.0
#===============================================================================

set -e  # Arrêter immédiatement en cas d'erreur

#-------------------------------------------------------------------------------
# CONFIGURATION GLOBALE
#-------------------------------------------------------------------------------
export GIT_REPO_URL="https://gitlab.sambaedu.org/sambaedu/se4.git"
export GIT_BRANCH="main"
SCRIPT_PATH="$(realpath "$0")"
SCRIPTS_PATH="$(dirname "$SCRIPT_PATH")"
REPO_PATH="$(dirname "$SCRIPTS_PATH")"
TARGET_PATH="/var/www/sambaedu/laravel"
BACKUP_PATH="/root/se4-backup-$(date +%Y%m%d-%H%M%S)"

# Git repo
GIT_REPO_URL="${GIT_REPO_URL:-https://github.com/your-org/sambaedu.git}"
GIT_BRANCH="${GIT_BRANCH:-main}"

# Chemins critiques
APACHE_CONF_SOURCE="$REPO_PATH/config/apache/sambaedu.conf"
APACHE_CONF_TARGET="/etc/apache2/sites-available/sambaedu.conf"
SYSTEMD_SOURCE_DIR="$REPO_PATH/config/systemd"
SYSTEMD_TARGET_DIR="/etc/systemd/system"

# PHP
PHP_CMD="/usr/bin/php8.2"

# Mode d'exécution (sera déterminé automatiquement)
SETUP_MODE=""  # "fresh" ou "update"

# Couleurs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

#===============================================================================
# FONCTIONS UTILITAIRES
#===============================================================================

log_message() {
    local level=$1
    local message=$2
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    case $level in
        "INFO")
            echo -e "${BLUE}ℹ️  $message${NC}"
            ;;
        "SUCCESS")
            echo -e "${GREEN}✅ $message${NC}"
            ;;
        "WARNING")
            echo -e "${YELLOW}⚠️  $message${NC}"
            ;;
        "ERROR")
            echo -e "${RED}❌ $message${NC}"
            ;;
        "STEP")
            echo -e "${CYAN}▶️  $message${NC}"
            ;;
    esac
}

separator() {
    echo ""
    echo "==============================================================================="
    echo ""
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        log_message "ERROR" "Ce script doit être exécuté en tant que root (sudo)"
        exit 1
    fi
}

detect_setup_mode() {
    local force_mode="$1"
    
    if [[ "$force_mode" == "fresh" ]]; then
        SETUP_MODE="fresh"
        log_message "INFO" "Mode FORCÉ : FROM SCRATCH"
        return
    fi
    
    if [[ "$force_mode" == "update" ]]; then
        SETUP_MODE="update"
        log_message "INFO" "Mode FORCÉ : UPDATE"
        return
    fi
    
    # Détection automatique
    if [[ -d "$TARGET_PATH" ]] && [[ -f "$TARGET_PATH/.env" ]]; then
        SETUP_MODE="update"
        log_message "INFO" "Mode détecté : UPDATE (Laravel déjà installé)"
    else
        SETUP_MODE="fresh"
        log_message "INFO" "Mode détecté : FROM SCRATCH (nouvelle installation)"
    fi
}

#===============================================================================
# MODE FROM SCRATCH - INSTALLATION COMPLÈTE
#===============================================================================

fresh_install() {
    separator
    log_message "STEP" "DÉBUT INSTALLATION FROM SCRATCH"
    separator
    
    # 1. Vérification prérequis système
    check_system_requirements
    
    # 2. Sauvegarde de l'existant (si présent)
    backup_existing_installation
    
    # 3. Installation Docker + PostgreSQL
    install_docker_postgres
    
    # 4. Clone du repo (si on n'est pas déjà dans le repo)
    clone_or_use_repo
    
    # 5. Configuration .env
    setup_env_file
    
    # 6. Installation dépendances Composer
    install_composer_dependencies
    
    # 7. Migration base de données
    run_database_migrations
    
    # 8. Copie vers /var/www/sambaedu/
    copy_to_production
    
    # 9. Configuration Apache
    setup_apache_config
    
    # 10. Configuration systemd workers
    setup_systemd_workers
    
    # 11. Permissions
    fix_permissions
    
    # 12. Optimisations Laravel
    optimize_laravel
    
    separator
    log_message "SUCCESS" "Installation FROM SCRATCH terminée avec succès !"
    separator
    
    display_next_steps_fresh
}

check_system_requirements() {
    log_message "STEP" "Vérification des prérequis système..."
    
    # PHP 8.2
    if ! command -v php8.2 &> /dev/null; then
        log_message "ERROR" "PHP 8.2 n'est pas installé"
        exit 1
    fi
    log_message "SUCCESS" "PHP 8.2 détecté"
    
    # Composer
    if ! command -v composer &> /dev/null; then
        log_message "WARNING" "Composer non détecté, installation..."
        install_composer
    else
        log_message "SUCCESS" "Composer détecté"
    fi
    
    # Git
    if ! command -v git &> /dev/null; then
        log_message "ERROR" "Git n'est pas installé"
        exit 1
    fi
    log_message "SUCCESS" "Git détecté"
}

install_composer() {
    log_message "INFO" "Installation de Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    log_message "SUCCESS" "Composer installé"
}

backup_existing_installation() {
    if [[ ! -d "/var/www/sambaedu" ]]; then
        log_message "INFO" "Aucune installation existante à sauvegarder"
        return
    fi
    
    log_message "STEP" "Sauvegarde de l'installation existante..."
    mkdir -p "$BACKUP_PATH"
    
    # Sauvegarde Apache conf
    if [[ -f "$APACHE_CONF_TARGET" ]]; then
        cp "$APACHE_CONF_TARGET" "$BACKUP_PATH/sambaedu.conf.bak"
        log_message "SUCCESS" "Configuration Apache sauvegardée"
    fi
    
    # Sauvegarde /var/www/sambaedu (sans laravel si présent)
    if [[ -d "/var/www/sambaedu" ]]; then
        rsync -a --exclude='laravel' /var/www/sambaedu/ "$BACKUP_PATH/sambaedu/"
        log_message "SUCCESS" "/var/www/sambaedu sauvegardé dans $BACKUP_PATH"
    fi
}

install_docker_postgres() {
    log_message "STEP" "Installation Docker + PostgreSQL..."
    
    # Vérifier si Docker est déjà installé
    if command -v docker &> /dev/null; then
        log_message "SUCCESS" "Docker déjà installé"
    else
        log_message "INFO" "Installation de Docker..."
        
        # Installation Docker (Debian/Ubuntu)
        apt-get update
        apt-get install -y ca-certificates curl gnupg lsb-release
        
        install -m 0755 -d /etc/apt/keyrings
        curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
        chmod a+r /etc/apt/keyrings/docker.gpg
        
        echo \
          "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian \
          $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
          > /etc/apt/sources.list.d/docker.list
        
        apt-get update
        apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
        
        systemctl enable --now docker
        log_message "SUCCESS" "Docker installé et démarré"
    fi
    
    # Vérifier si le conteneur PostgreSQL existe déjà
    if docker ps -a --format '{{.Names}}' | grep -q '^sambaedu-postgres$'; then
        log_message "WARNING" "Conteneur PostgreSQL 'sambaedu-postgres' déjà existant"
        read -p "Voulez-vous le supprimer et le recréer ? (o/N) " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Oo]$ ]]; then
            docker rm -f sambaedu-postgres
            docker volume rm sambaedu-pgdata || true
        else
            log_message "INFO" "Conteneur existant conservé"
            return
        fi
    fi
    
    # Créer le volume et le conteneur PostgreSQL
    log_message "INFO" "Création du conteneur PostgreSQL..."
    docker volume create sambaedu-pgdata
    
    # Récupérer le mot de passe MySQL pour le réutiliser
    local mysql_password=""
    if [[ -f "/etc/se4/config_mysql.cache.inc.php" ]]; then
        mysql_password=$(grep 'dbpass' /etc/se4/config_mysql.cache.inc.php | sed -n "s/.*'\([^']*\)'.*/\1/p" | head -1)
    fi
    
    if [[ -z "$mysql_password" ]]; then
        mysql_password="sambaedu_default_password"
        log_message "WARNING" "Mot de passe MySQL non trouvé, utilisation d'un mot de passe par défaut"
    fi
    
    docker run -d --name sambaedu-postgres \
      --restart unless-stopped \
      -e POSTGRES_DB=sambaedu \
      -e POSTGRES_USER=sambaedu \
      -e POSTGRES_PASSWORD="$mysql_password" \
      -p 5432:5432 \
      -v sambaedu-pgdata:/var/lib/postgresql/data \
      postgres:16
    
    log_message "SUCCESS" "PostgreSQL démarré (DB: sambaedu, User: sambaedu)"
    
    # Attendre que PostgreSQL soit prêt
    log_message "INFO" "Attente du démarrage de PostgreSQL..."
    sleep 5
    
    if docker exec sambaedu-postgres pg_isready -U sambaedu &> /dev/null; then
        log_message "SUCCESS" "PostgreSQL opérationnel"
    else
        log_message "ERROR" "PostgreSQL ne répond pas"
        exit 1
    fi
}

clone_or_use_repo() {
    # Si on exécute déjà depuis le repo, on l'utilise
    if [[ -d "$REPO_PATH/.git" ]]; then
        log_message "INFO" "Utilisation du repo existant : $REPO_PATH"
        return
    fi
    
    # Sinon, on clone
    log_message "STEP" "Clone du repository Git..."
    local clone_target="/root/se4"
    
    if [[ -d "$clone_target" ]]; then
        log_message "WARNING" "Le dossier $clone_target existe déjà"
        read -p "Voulez-vous le supprimer et re-cloner ? (o/N) " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Oo]$ ]]; then
            rm -rf "$clone_target"
        else
            REPO_PATH="$clone_target/sources/var/www/sambaedu/laravel"
            log_message "INFO" "Utilisation du repo existant"
            return
        fi
    fi
    
    git clone -b "$GIT_BRANCH" "$GIT_REPO_URL" "$clone_target"
    REPO_PATH="$clone_target/sources/var/www/sambaedu/laravel"
    log_message "SUCCESS" "Repository cloné dans $clone_target"
}

setup_env_file() {
    log_message "STEP" "Configuration du fichier .env..."
    
    local env_file="$REPO_PATH/.env"
    local env_example="$REPO_PATH/.env.example"
    
    if [[ -f "$env_file" ]]; then
        log_message "WARNING" "Fichier .env déjà existant"
        read -p "Voulez-vous le recréer depuis .env.example ? (o/N) " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Oo]$ ]]; then
            log_message "INFO" "Fichier .env existant conservé"
            return
        fi
    fi
    
    if [[ ! -f "$env_example" ]]; then
        log_message "ERROR" "Fichier .env.example introuvable"
        exit 1
    fi
    
    cp "$env_example" "$env_file"
    
    # Récupérer les credentials MySQL
    local mysql_host="localhost"
    local mysql_db="se4"
    local mysql_user="se4"
    local mysql_password=""
    
    if [[ -f "/etc/se4/config_mysql.cache.inc.php" ]]; then
        mysql_password=$(grep 'dbpass' /etc/se4/config_mysql.cache.inc.php | sed -n "s/.*'\([^']*\)'.*/\1/p" | head -1)
        mysql_db=$(grep 'dbname' /etc/se4/config_mysql.cache.inc.php | sed -n "s/.*'\([^']*\)'.*/\1/p" | head -1)
        mysql_user=$(grep 'dbuser' /etc/se4/config_mysql.cache.inc.php | sed -n "s/.*'\([^']*\)'.*/\1/p" | head -1)
    fi
    
    # Configuration PostgreSQL avec les mêmes credentials
    sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=pgsql/" "$env_file"
    sed -i "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" "$env_file"
    sed -i "s/^DB_PORT=.*/DB_PORT=5432/" "$env_file"
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=sambaedu/" "$env_file"
    sed -i "s/^DB_USERNAME=.*/DB_USERNAME=sambaedu/" "$env_file"
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=\"$mysql_password\"/" "$env_file"
    
    # Configuration MySQL legacy (pour compatibilité)
    sed -i "s/^LEGACY_DB_CONNECTION=.*/LEGACY_DB_CONNECTION=mysql/" "$env_file"
    sed -i "s/^LEGACY_DB_HOST=.*/LEGACY_DB_HOST=$mysql_host/" "$env_file"
    sed -i "s/^LEGACY_DB_DATABASE=.*/LEGACY_DB_DATABASE=$mysql_db/" "$env_file"
    sed -i "s/^LEGACY_DB_USERNAME=.*/LEGACY_DB_USERNAME=$mysql_user/" "$env_file"
    sed -i "s/^LEGACY_DB_PASSWORD=.*/LEGACY_DB_PASSWORD=\"$mysql_password\"/" "$env_file"
    
    # Générer APP_KEY
    cd "$REPO_PATH"
    $PHP_CMD artisan key:generate --force
    
    log_message "SUCCESS" "Fichier .env configuré avec credentials MySQL → PostgreSQL"
}

install_composer_dependencies() {
    log_message "STEP" "Installation des dépendances Composer..."
    
    cd "$REPO_PATH"
    
    if [[ ! -f "composer.json" ]]; then
        log_message "ERROR" "composer.json introuvable"
        exit 1
    fi
    
    composer install --no-dev --optimize-autoloader --no-interaction
    
    log_message "SUCCESS" "Dépendances Composer installées"
}

run_database_migrations() {
    log_message "STEP" "Exécution des migrations de base de données..."
    
    cd "$REPO_PATH"
    
    # Vérifier la connexion PostgreSQL
    if ! $PHP_CMD artisan db:show &> /dev/null; then
        log_message "ERROR" "Impossible de se connecter à PostgreSQL"
        exit 1
    fi
    
    $PHP_CMD artisan migrate --force
    
    log_message "SUCCESS" "Migrations exécutées avec succès"
}

copy_to_production() {
    log_message "STEP" "Copie vers /var/www/sambaedu/..."
    
    # Créer le dossier parent si nécessaire
    mkdir -p /var/www/sambaedu
    
    # Supprimer l'ancien laravel si présent
    if [[ -d "$TARGET_PATH" ]]; then
        log_message "WARNING" "Suppression de l'ancien $TARGET_PATH"
        rm -rf "$TARGET_PATH"
    fi
    
    # Copier le repo
    cp -rf "$REPO_PATH" "$TARGET_PATH"
    
    log_message "SUCCESS" "Application copiée vers $TARGET_PATH"
}

setup_apache_config() {
    log_message "STEP" "Configuration Apache..."
    
    if [[ ! -f "$APACHE_CONF_SOURCE" ]]; then
        log_message "WARNING" "Configuration Apache source introuvable : $APACHE_CONF_SOURCE"
        return
    fi
    
    cp "$APACHE_CONF_SOURCE" "$APACHE_CONF_TARGET"
    
    # Activer le site
    a2ensite sambaedu.conf &> /dev/null || true
    
    # Activer les modules nécessaires
    a2enmod rewrite &> /dev/null || true
    a2enmod headers &> /dev/null || true
    
    # Recharger Apache
    systemctl reload apache2
    
    log_message "SUCCESS" "Configuration Apache installée et rechargée"
}

setup_systemd_workers() {
    log_message "STEP" "Configuration des workers systemd..."
    
    if [[ ! -d "$SYSTEMD_SOURCE_DIR" ]]; then
        log_message "WARNING" "Dossier systemd source introuvable : $SYSTEMD_SOURCE_DIR"
        return
    fi
    
    # Copier les services
    if [[ -f "$SYSTEMD_SOURCE_DIR/laravel-queue-general.service" ]]; then
        cp "$SYSTEMD_SOURCE_DIR/laravel-queue-general.service" "$SYSTEMD_TARGET_DIR/"
        log_message "SUCCESS" "Service laravel-queue-general copié"
    fi
    
    if [[ -f "$SYSTEMD_SOURCE_DIR/laravel-queue-sync.service" ]]; then
        cp "$SYSTEMD_SOURCE_DIR/laravel-queue-sync.service" "$SYSTEMD_TARGET_DIR/"
        log_message "SUCCESS" "Service laravel-queue-sync copié"
    fi
    
    # Recharger systemd
    systemctl daemon-reload
    
    # Activer et démarrer les services
    systemctl enable laravel-queue-general laravel-queue-sync
    systemctl start laravel-queue-general laravel-queue-sync
    
    # Vérifier le statut
    if systemctl is-active --quiet laravel-queue-general && systemctl is-active --quiet laravel-queue-sync; then
        log_message "SUCCESS" "Workers systemd démarrés avec succès"
    else
        log_message "WARNING" "Certains workers n'ont pas démarré, vérifiez avec : systemctl status laravel-queue-*"
    fi
}

fix_permissions() {
    log_message "STEP" "Correction des permissions..."
    
    chown -R www-admin:www-admin "$TARGET_PATH"
    chmod -R 755 "$TARGET_PATH"
    chmod -R 775 "$TARGET_PATH/storage"
    chmod -R 775 "$TARGET_PATH/bootstrap/cache"
    
    log_message "SUCCESS" "Permissions corrigées (www-admin:www-admin)"
}

optimize_laravel() {
    log_message "STEP" "Optimisations Laravel..."
    
    cd "$TARGET_PATH"
    
    $PHP_CMD artisan config:cache
    $PHP_CMD artisan route:cache
    $PHP_CMD artisan view:cache
    $PHP_CMD artisan event:cache
    
    log_message "SUCCESS" "Laravel optimisé"
}

display_next_steps_fresh() {
    echo ""
    echo "╔════════════════════════════════════════════════════════════════════════════╗"
    echo "║                    INSTALLATION FROM SCRATCH TERMINÉE                      ║"
    echo "╚════════════════════════════════════════════════════════════════════════════╝"
    echo ""
    echo "📍 Application installée dans : $TARGET_PATH"
    echo "📍 Sauvegarde de l'ancien système : $BACKUP_PATH"
    echo ""
    echo "🔧 Prochaines étapes :"
    echo "   1. Vérifier la configuration Apache : $APACHE_CONF_TARGET"
    echo "   2. Vérifier les workers : systemctl status laravel-queue-*"
    echo "   3. Accéder à l'application : http://votre-serveur/"
    echo "   4. Configurer le cron Laravel (si pas déjà fait)"
    echo ""
    echo "📝 Logs PostgreSQL : docker logs sambaedu-postgres"
    echo "📝 Logs workers : journalctl -u laravel-queue-*"
    echo ""
}

#===============================================================================
# MODE UPDATE - MISE À JOUR
#===============================================================================

update_installation() {
    separator
    log_message "STEP" "DÉBUT MISE À JOUR"
    separator
    
    # 1. Vérification/mise à jour systemd
    update_systemd_services
    
    # 2. Vérification/mise à jour Apache
    update_apache_config
    
    # 3. Installation/mise à jour Composer
    update_composer_dependencies
    
    # 4. Exécution migrations
    update_database_migrations
    
    # 5. Vérification .env vs .env.example
    check_env_changes
    
    # 6. Optimisations Laravel
    optimize_laravel_update
    
    # 7. Correction des permissions
    fix_permissions_update
    
    # 8. Redémarrage workers
    restart_workers
    
    separator
    log_message "SUCCESS" "Mise à jour terminée avec succès !"
    separator
    
    display_next_steps_update
}

update_systemd_services() {
    log_message "STEP" "Vérification des services systemd..."
    
    local source_dir="$TARGET_PATH/config/systemd"
    local needs_reload=false
    
    if [[ ! -d "$source_dir" ]]; then
        log_message "WARNING" "Dossier config/systemd introuvable"
        return
    fi
    
    # Comparer et mettre à jour si nécessaire
    for service_file in "$source_dir"/*.service; do
        local service_name=$(basename "$service_file")
        local target_file="$SYSTEMD_TARGET_DIR/$service_name"
        
        if [[ ! -f "$target_file" ]]; then
            log_message "INFO" "Service $service_name absent, installation..."
            cp "$service_file" "$target_file"
            needs_reload=true
        elif ! cmp -s "$service_file" "$target_file"; then
            log_message "INFO" "Service $service_name modifié, mise à jour..."
            cp "$service_file" "$target_file"
            needs_reload=true
        else
            log_message "SUCCESS" "Service $service_name à jour"
        fi
    done
    
    if $needs_reload; then
        systemctl daemon-reload
        log_message "SUCCESS" "Services systemd mis à jour"
    fi
}

update_apache_config() {
    log_message "STEP" "Vérification de la configuration Apache..."
    
    local source_conf="$TARGET_PATH/config/apache/sambaedu.conf"
    
    if [[ ! -f "$source_conf" ]]; then
        log_message "WARNING" "Configuration Apache source introuvable"
        return
    fi
    
    if [[ ! -f "$APACHE_CONF_TARGET" ]]; then
        log_message "INFO" "Configuration Apache absente, installation..."
        cp "$source_conf" "$APACHE_CONF_TARGET"
        a2ensite sambaedu.conf &> /dev/null || true
        systemctl reload apache2
        log_message "SUCCESS" "Configuration Apache installée"
    elif ! cmp -s "$source_conf" "$APACHE_CONF_TARGET"; then
        log_message "INFO" "Configuration Apache modifiée, mise à jour..."
        cp "$source_conf" "$APACHE_CONF_TARGET"
        systemctl reload apache2
        log_message "SUCCESS" "Configuration Apache mise à jour"
    else
        log_message "SUCCESS" "Configuration Apache à jour"
    fi
}

update_composer_dependencies() {
    log_message "STEP" "Mise à jour des dépendances Composer..."
    
    cd "$TARGET_PATH"
    
    composer install --no-dev --optimize-autoloader --no-interaction
    
    log_message "SUCCESS" "Dépendances Composer à jour"
}

update_database_migrations() {
    log_message "STEP" "Exécution des migrations..."
    
    cd "$TARGET_PATH"
    
    $PHP_CMD artisan migrate --force
    
    log_message "SUCCESS" "Migrations exécutées"
}

check_env_changes() {
    log_message "STEP" "Vérification .env vs .env.example..."
    
    local env_file="$TARGET_PATH/.env"
    local env_example="$TARGET_PATH/.env.example"
    
    if [[ ! -f "$env_example" ]]; then
        log_message "WARNING" ".env.example introuvable"
        return
    fi
    
    if [[ ! -f "$env_file" ]]; then
        log_message "ERROR" ".env introuvable !"
        exit 1
    fi
    
    # Extraire les clés de .env.example
    local example_keys=$(grep -E '^[A-Z_]+=' "$env_example" | cut -d= -f1 | sort)
    local env_keys=$(grep -E '^[A-Z_]+=' "$env_file" | cut -d= -f1 | sort)
    
    # Trouver les clés manquantes
    local missing_keys=$(comm -23 <(echo "$example_keys") <(echo "$env_keys"))
    
    if [[ -n "$missing_keys" ]]; then
        log_message "WARNING" "Nouvelles variables détectées dans .env.example :"
        echo "$missing_keys" | while read -r key; do
            echo "   - $key"
        done
        echo ""
        log_message "INFO" "Pensez à ajouter ces variables dans votre .env"
    else
        log_message "SUCCESS" "Fichier .env à jour"
    fi
}

optimize_laravel_update() {
    log_message "STEP" "Optimisations Laravel..."
    
    cd "$TARGET_PATH"
    
    $PHP_CMD artisan config:clear
    $PHP_CMD artisan route:clear
    $PHP_CMD artisan view:clear
    $PHP_CMD artisan cache:clear
    
    $PHP_CMD artisan config:cache
    $PHP_CMD artisan route:cache
    $PHP_CMD artisan view:cache
    $PHP_CMD artisan event:cache
    
    log_message "SUCCESS" "Laravel optimisé"
}

fix_permissions_update() {
    log_message "STEP" "Correction des permissions..."
    
    chown -R www-admin:www-admin "$TARGET_PATH"
    chmod -R 755 "$TARGET_PATH"
    chmod -R 775 "$TARGET_PATH/storage"
    chmod -R 775 "$TARGET_PATH/bootstrap/cache"
    
    log_message "SUCCESS" "Permissions corrigées (www-admin:www-admin)"
}

restart_workers() {
    log_message "STEP" "Redémarrage des workers..."
    
    systemctl restart laravel-queue-general laravel-queue-sync
    
    sleep 2
    
    if systemctl is-active --quiet laravel-queue-general && systemctl is-active --quiet laravel-queue-sync; then
        log_message "SUCCESS" "Workers redémarrés avec succès"
    else
        log_message "WARNING" "Certains workers n'ont pas redémarré, vérifiez avec : systemctl status laravel-queue-*"
    fi
}

display_next_steps_update() {
    echo ""
    echo "╔════════════════════════════════════════════════════════════════════════════╗"
    echo "║                         MISE À JOUR TERMINÉE                               ║"
    echo "╚════════════════════════════════════════════════════════════════════════════╝"
    echo ""
    echo "✅ Services systemd vérifiés/mis à jour"
    echo "✅ Configuration Apache vérifiée/mise à jour"
    echo "✅ Migrations exécutées"
    echo "✅ Dépendances Composer à jour"
    echo "✅ Laravel optimisé"
    echo "✅ Workers redémarrés"
    echo ""
    echo "📝 Vérifiez les logs si nécessaire :"
    echo "   - Workers : journalctl -u laravel-queue-* -f"
    echo "   - Apache : tail -f /var/log/apache2/error.log"
    echo "   - Laravel : tail -f $TARGET_PATH/storage/logs/laravel.log"
    echo ""
}

#===============================================================================
# MAIN
#===============================================================================

main() {
    clear
    echo "╔════════════════════════════════════════════════════════════════════════════╗"
    echo "║                  SE4FS - Setup Intelligent v2.0                            ║"
    echo "╚════════════════════════════════════════════════════════════════════════════╝"
    echo ""
    
    # Vérifier root
    check_root
    
    # Parser les arguments
    local force_mode=""
    case "${1:-}" in
        --force-fresh)
            force_mode="fresh"
            ;;
        --force-update)
            force_mode="update"
            ;;
    esac
    
    # Détecter le mode
    detect_setup_mode "$force_mode"
    
    echo ""
    
    # Exécuter le mode approprié
    if [[ "$SETUP_MODE" == "fresh" ]]; then
        fresh_install
    else
        update_installation
    fi
}

# Exécution
main "$@"
