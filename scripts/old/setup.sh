#!/bin/bash

#===============================================================================
# SE4FS - Script de Configuration Automatique
#===============================================================================
#
# DESCRIPTION:
#   Ce script configure automatiquement tous les services nécessaires au bon
#   fonctionnement de l'application Laravel SE4FS (SambaEdu 4 File Server).
#
# PRÉREQUIS:
#   - Exécution en tant que root (sudo)
#   - PHP 8.2 installé
#   - MySQL/MariaDB configuré et accessible
#   - Répertoire Laravel présent dans /var/www/sambaedu/laravel
#
# SERVICES CONFIGURÉS:
#   1. Service cron système (pour les tâches planifiées)
#   2. Cron Laravel (schedule:run toutes les minutes)
#   3. Queue Worker Laravel (traitement des jobs asynchrones)
#   4. Table jobs en base de données (stockage des jobs)
#
# UTILISATION:
#   sudo ./setup.sh
#
# LOGS:
#   Les logs sont écrits dans /var/www/sambaedu/laravel/storage/logs/setup.log
#
# AUTEUR: Équipe SE4FS
# VERSION: 1.0.0
#===============================================================================

set -e  # Arrêter immédiatement en cas d'erreur

#-------------------------------------------------------------------------------
# CONFIGURATION GLOBALE
#-------------------------------------------------------------------------------
# Ces variables définissent les chemins et paramètres utilisés par le script.
# Modifier ces valeurs si votre installation diffère de la configuration standard.

SCRIPT_PATH="$(realpath "$0")"
SCRIPTS_PATH="$(dirname "$SCRIPT_PATH")"       # Chemin des scripts utilitaires
LARAVEL_PATH="$(dirname "$SCRIPTS_PATH")"      # Chemin racine de l'application Laravel
LOG_FILE="$LARAVEL_PATH/storage/logs/setup.log" # Fichier de log du setup
PHP_CMD="/usr/bin/php8.2"                      # Commande PHP à utiliser

# Paramètres déterminés dynamiquement (depuis .env)
DB_CONNECTION="mysql"
QUEUE_CONNECTION="database"

# Détection environnement Docker
USE_DOCKER_ARTISAN=false
DOCKER_APP_SERVICE=""
DOCKER_CRON_PREFIX=""
DOCKER_COMPOSE_BIN=()

QUEUE_SERVICE_GENERAL="laravel-queue-general"  # Nom du service systemd pour le worker général
QUEUE_SERVICE_SYNC="laravel-queue-sync"        # Nom du service systemd pour le worker sync
QUEUE_SERVICE_FILE_GENERAL="/etc/systemd/system/$QUEUE_SERVICE_GENERAL.service"
QUEUE_SERVICE_FILE_SYNC="/etc/systemd/system/$QUEUE_SERVICE_SYNC.service"

#-------------------------------------------------------------------------------
# COULEURS POUR L'AFFICHAGE CONSOLE
#-------------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color (reset)

#===============================================================================
# FONCTIONS UTILITAIRES
#===============================================================================

#-------------------------------------------------------------------------------
# log_message - Affiche et enregistre un message de log
#-------------------------------------------------------------------------------
# Arguments:
#   $1 - Niveau de log (INFO, SUCCESS, WARNING, ERROR)
#   $2 - Message à afficher
#
# Comportement:
#   - Écrit le message horodaté dans le fichier de log
#   - Affiche le message coloré dans la console avec une icône appropriée
#-------------------------------------------------------------------------------
log_message() {
    local level=$1
    local message=$2
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    # Écriture dans le fichier de log
    echo "[$timestamp] [$level] $message" >> "$LOG_FILE"
    
    # Affichage console avec couleur et icône selon le niveau
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
    esac
}

#-------------------------------------------------------------------------------
# load_env_var - Lit une variable depuis le fichier .env Laravel
#-------------------------------------------------------------------------------
# Arguments:
#   $1 - Nom de la variable
#
# Retour:
#   Affiche la valeur trouvée (sans quotes) et retourne 0 si présente
#   Retourne 1 sinon
#-------------------------------------------------------------------------------
load_env_var() {
    local key="$1"
    local env_file="$LARAVEL_PATH/.env"

    if [[ ! -f "$env_file" ]]; then
        return 1
    fi

    local line
    line=$(grep -E "^[[:space:]]*${key}=" "$env_file" | tail -n 1 || true)
    if [[ -z "$line" ]]; then
        return 1
    fi

    local value
    value="${line#*=}"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    echo "$value"
    return 0
}

#-------------------------------------------------------------------------------
# detect_runtime_context - Détecte DB/queue et le mode d'exécution artisan
#-------------------------------------------------------------------------------
detect_runtime_context() {
    if [[ -x "/usr/bin/php8.2" ]]; then
        PHP_CMD="/usr/bin/php8.2"
    elif command -v php >/dev/null 2>&1; then
        PHP_CMD="$(command -v php)"
    fi

    local env_db
    env_db="$(load_env_var "DB_CONNECTION" || true)"
    if [[ -n "$env_db" ]]; then
        DB_CONNECTION="$env_db"
    fi

    local env_queue
    env_queue="$(load_env_var "QUEUE_CONNECTION" || true)"
    if [[ -n "$env_queue" ]]; then
        QUEUE_CONNECTION="$env_queue"
    fi

    local compose_file=""
    if [[ -f "$LARAVEL_PATH/docker-compose.yml" ]]; then
        compose_file="$LARAVEL_PATH/docker-compose.yml"
    elif [[ -f "$LARAVEL_PATH/docker-compose.yaml" ]]; then
        compose_file="$LARAVEL_PATH/docker-compose.yaml"
    elif [[ -f "$LARAVEL_PATH/compose.yml" ]]; then
        compose_file="$LARAVEL_PATH/compose.yml"
    fi

    if [[ -n "$compose_file" ]] && command -v docker >/dev/null 2>&1; then
        if docker compose version >/dev/null 2>&1; then
            DOCKER_COMPOSE_BIN=(docker compose)
            DOCKER_CRON_PREFIX="$(command -v docker) compose"
        elif command -v docker-compose >/dev/null 2>&1; then
            DOCKER_COMPOSE_BIN=("$(command -v docker-compose)")
            DOCKER_CRON_PREFIX="$(command -v docker-compose)"
        fi

        if [[ ${#DOCKER_COMPOSE_BIN[@]} -gt 0 ]]; then
            local services
            services="$("${DOCKER_COMPOSE_BIN[@]}" -f "$compose_file" config --services 2>/dev/null || true)"
            for candidate in app laravel.test php workspace; do
                if echo "$services" | grep -qx "$candidate"; then
                    DOCKER_APP_SERVICE="$candidate"
                    USE_DOCKER_ARTISAN=true
                    break
                fi
            done
        fi
    fi

    if $USE_DOCKER_ARTISAN; then
        log_message "INFO" "Contexte détecté: Docker (${DOCKER_APP_SERVICE}) | DB=${DB_CONNECTION} | Queue=${QUEUE_CONNECTION}"
    else
        log_message "INFO" "Contexte détecté: hôte local | DB=${DB_CONNECTION} | Queue=${QUEUE_CONNECTION}"
    fi
}

#-------------------------------------------------------------------------------
# run_artisan - Exécute une commande artisan (hôte ou docker)
#-------------------------------------------------------------------------------
run_artisan() {
    if $USE_DOCKER_ARTISAN; then
        "${DOCKER_COMPOSE_BIN[@]}" exec -T "$DOCKER_APP_SERVICE" php artisan "$@"
    else
        "$PHP_CMD" artisan "$@"
    fi
}

#-------------------------------------------------------------------------------
# docker_service_exists - Vérifie l'existence d'un service docker compose
#-------------------------------------------------------------------------------
docker_service_exists() {
    local service_name="$1"
    if ! $USE_DOCKER_ARTISAN; then
        return 1
    fi
    "${DOCKER_COMPOSE_BIN[@]}" config --services 2>/dev/null | grep -qx "$service_name"
}

#-------------------------------------------------------------------------------
# docker_service_running - Vérifie si un service docker compose est actif
#-------------------------------------------------------------------------------
docker_service_running() {
    local service_name="$1"
    if ! $USE_DOCKER_ARTISAN; then
        return 1
    fi
    "${DOCKER_COMPOSE_BIN[@]}" ps --services --filter status=running 2>/dev/null | grep -qx "$service_name"
}

#===============================================================================
# FONCTIONS DE GESTION DU CRON LARAVEL
#===============================================================================

#-------------------------------------------------------------------------------
# check_laravel_cron - Vérifie si le cron Laravel est configuré
#-------------------------------------------------------------------------------
# Description:
#   Le cron Laravel exécute `artisan schedule:run` toutes les minutes.
#   Cette commande est essentielle pour :
#   - Les tâches planifiées (nettoyage, rapports, synchronisations)
#   - Le heartbeat ControlHub (communication avec le serveur central)
#   - Les jobs différés et récurrents
#
# Retour:
#   0 - Le cron est configuré
#   1 - Le cron n'est pas configuré
#-------------------------------------------------------------------------------
check_laravel_cron() {
    log_message "INFO" "Vérification de la configuration du cron Laravel..."
    
    local cron_user="www-admin"
    
    if crontab -u "$cron_user" -l 2>/dev/null | grep -q "schedule:run"; then
        log_message "SUCCESS" "Cron Laravel déjà configuré pour $cron_user"
        return 0
    else
        log_message "WARNING" "Cron Laravel non configuré pour $cron_user"
        return 1
    fi
}

#-------------------------------------------------------------------------------
# setup_laravel_cron - Configure le cron Laravel
#-------------------------------------------------------------------------------
# Description:
#   Ajoute une entrée crontab pour exécuter `artisan schedule:run` chaque minute.
#   Cette commande vérifie les tâches planifiées définies dans app/Console/Kernel.php
#   et exécute celles qui sont dues.
#
# Comportement:
#   1. Sauvegarde le crontab existant dans un fichier temporaire
#   2. Vérifie si l'entrée Laravel existe déjà
#   3. Ajoute l'entrée si absente
#   4. Applique le nouveau crontab
#   5. Nettoie le fichier temporaire
#
# Note:
#   Le cron s'exécute en tant que www-admin (même utilisateur qu'Apache).
#   Cela évite les problèmes de permissions sur les fichiers Laravel.
#-------------------------------------------------------------------------------
setup_laravel_cron() {
    log_message "INFO" "Configuration du cron Laravel pour www-admin..."
    
    local cron_entry=""
    if $USE_DOCKER_ARTISAN; then
        cron_entry="* * * * * cd $LARAVEL_PATH && $DOCKER_CRON_PREFIX exec -T $DOCKER_APP_SERVICE php artisan schedule:run >> /dev/null 2>&1"
    else
        cron_entry="* * * * * cd $LARAVEL_PATH && $PHP_CMD artisan schedule:run >> /dev/null 2>&1"
    fi
    local cron_user="www-admin"
    
    # Sauvegarder le crontab existant de www-admin (s'il existe)
    local temp_cron="/tmp/crontab_backup_$(date +%s)"
    crontab -u "$cron_user" -l > "$temp_cron" 2>/dev/null || touch "$temp_cron"
    
    # Ajouter la ligne Laravel si elle n'existe pas
    if ! grep -q "schedule:run" "$temp_cron"; then
        echo "$cron_entry" >> "$temp_cron"
        crontab -u "$cron_user" "$temp_cron"
        log_message "SUCCESS" "Cron Laravel configuré avec succès pour $cron_user"
    else
        log_message "INFO" "Cron Laravel déjà présent dans le crontab de $cron_user"
    fi
    
    # Nettoyer le fichier temporaire
    rm -f "$temp_cron"
    
    # Supprimer l'ancien cron de root s'il existe (migration)
    if crontab -l 2>/dev/null | grep -q "schedule:run"; then
        log_message "INFO" "Migration : suppression du cron Laravel de root..."
        crontab -l 2>/dev/null | grep -v "schedule:run" | crontab -
        log_message "SUCCESS" "Ancien cron root supprimé"
    fi
}

#===============================================================================
# FONCTIONS DE GESTION DES PERMISSIONS LARAVEL
#===============================================================================

#-------------------------------------------------------------------------------
# check_laravel_permissions - Vérifie les permissions des répertoires Laravel
#-------------------------------------------------------------------------------
# Description:
#   Laravel nécessite des permissions d'écriture sur certains répertoires :
#   - storage/ : logs, cache, sessions, fichiers uploadés
#   - bootstrap/cache/ : cache de configuration et services
#
#   Ces répertoires doivent être accessibles en écriture par l'utilisateur
#   qui exécute PHP (www-admin pour SE4FS).
#
# Retour:
#   0 - Toutes les permissions sont correctes
#   1 - Au moins un répertoire a des permissions incorrectes
#-------------------------------------------------------------------------------
check_laravel_permissions() {
    log_message "INFO" "Vérification des permissions Laravel..."
    
    local dirs_to_check=("storage" "bootstrap/cache")
    local permissions_ok=true
    
    for dir in "${dirs_to_check[@]}"; do
        local full_path="$LARAVEL_PATH/$dir"
        if [[ -d "$full_path" ]]; then
            if [[ ! -w "$full_path" ]]; then
                log_message "WARNING" "Répertoire $dir non accessible en écriture"
                permissions_ok=false
            fi
        else
            log_message "WARNING" "Répertoire $dir n'existe pas"
            permissions_ok=false
        fi
    done
    
    if $permissions_ok; then
        log_message "SUCCESS" "Permissions Laravel correctes"
        return 0
    else
        return 1
    fi
}

#-------------------------------------------------------------------------------
# fix_laravel_permissions - Corrige les permissions des répertoires Laravel
#-------------------------------------------------------------------------------
# Description:
#   Crée et configure les répertoires nécessaires à Laravel avec les bonnes
#   permissions. L'utilisateur www-admin est utilisé car c'est l'utilisateur
#   standard pour les services web SE4FS.
#
# Répertoires créés/corrigés:
#   - storage/logs : fichiers de log Laravel
#   - storage/framework/cache : cache du framework
#   - storage/framework/sessions : sessions utilisateurs
#   - storage/framework/views : vues compilées (Blade)
#   - bootstrap/cache : cache de configuration
#
# Permissions appliquées:
#   - Mode 755 (rwxr-xr-x) : lecture/exécution pour tous, écriture pour owner
#   - Propriétaire : www-admin:www-admin
#-------------------------------------------------------------------------------
fix_laravel_permissions() {
    log_message "INFO" "Correction des permissions Laravel..."
    
    cd "$LARAVEL_PATH"
    
    # Créer les répertoires s'ils n'existent pas
    mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache
    
    # Définir les permissions (755 = rwxr-xr-x)
    chmod -R 755 storage bootstrap/cache
    
    # Attribuer à www-admin (utilisateur standard SE4FS pour les services web)
    chown -R www-admin:www-admin storage bootstrap/cache
    
    log_message "SUCCESS" "Permissions Laravel corrigées"
}

#===============================================================================
# FONCTIONS DE VÉRIFICATION ET OPTIMISATION LARAVEL
#===============================================================================

#-------------------------------------------------------------------------------
# check_laravel_config - Vérifie que Laravel est opérationnel
#-------------------------------------------------------------------------------
# Description:
#   Teste si Laravel peut s'exécuter correctement en appelant `artisan --version`.
#   Cette vérification permet de détecter :
#   - Problèmes de configuration PHP
#   - Fichiers manquants ou corrompus
#   - Erreurs de syntaxe dans le code
#   - Problèmes de dépendances Composer
#
# Retour:
#   0 - Laravel est opérationnel
#   1 - Laravel ne fonctionne pas (erreur critique)
#-------------------------------------------------------------------------------
check_laravel_config() {
    log_message "INFO" "Vérification de la configuration Laravel..."
    
    cd "$LARAVEL_PATH"
    
    # Vérifier que Laravel fonctionne en appelant artisan
    if run_artisan --version > /dev/null 2>&1; then
        log_message "SUCCESS" "Laravel opérationnel"
        return 0
    else
        log_message "ERROR" "Laravel non opérationnel"
        return 1
    fi
}

#-------------------------------------------------------------------------------
# optimize_laravel - Optimise Laravel pour la production
#-------------------------------------------------------------------------------
# Description:
#   Met en cache les configurations, routes et vues pour améliorer les
#   performances en production. Ces caches évitent de parser les fichiers
#   de configuration et les routes à chaque requête.
#
# Caches créés:
#   - config:cache : Fusionne tous les fichiers de config en un seul
#   - route:cache : Compile les routes en un fichier PHP optimisé
#   - view:cache : Précompile toutes les vues Blade
#
# Note:
#   Après modification des fichiers de config ou routes, il faut relancer
#   cette optimisation ou vider les caches avec `artisan cache:clear`.
#-------------------------------------------------------------------------------
optimize_laravel() {
    log_message "INFO" "Optimisation de Laravel..."
    
    cd "$LARAVEL_PATH"
    
    # Cache des configurations (fusionne config/*.php en un seul fichier)
    run_artisan config:cache > /dev/null 2>&1
    
    # Cache des routes (compile routes/web.php et routes/api.php)
    run_artisan route:cache > /dev/null 2>&1
    
    # Cache des vues (précompile les templates Blade)
    run_artisan view:cache > /dev/null 2>&1
    
    log_message "SUCCESS" "Laravel optimisé"
}

#===============================================================================
# FONCTIONS DE GESTION DU SERVICE CRON SYSTÈME
#===============================================================================

#-------------------------------------------------------------------------------
# check_cron_service - Vérifie si le service cron système est actif
#-------------------------------------------------------------------------------
# Description:
#   Le service cron système est nécessaire pour exécuter les tâches planifiées.
#   Sans ce service, le cron Laravel ne pourra pas s'exécuter.
#
# Retour:
#   0 - Le service cron est actif
#   1 - Le service cron est inactif
#-------------------------------------------------------------------------------
check_cron_service() {
    log_message "INFO" "Vérification du service cron..."
    
    if systemctl is-active --quiet cron; then
        log_message "SUCCESS" "Service cron actif"
        return 0
    else
        log_message "WARNING" "Service cron inactif"
        return 1
    fi
}

#-------------------------------------------------------------------------------
# start_cron_service - Démarre et active le service cron système
#-------------------------------------------------------------------------------
# Description:
#   Démarre le service cron et l'active pour qu'il se lance automatiquement
#   au démarrage du système.
#
# Actions:
#   1. systemctl start cron : Démarre le service immédiatement
#   2. systemctl enable cron : Active le démarrage automatique au boot
#-------------------------------------------------------------------------------
start_cron_service() {
    log_message "INFO" "Démarrage du service cron..."
    
    systemctl start cron
    systemctl enable cron
    
    log_message "SUCCESS" "Service cron démarré et activé"
}

#===============================================================================
# FONCTIONS DE GESTION DU QUEUE WORKER LARAVEL
#===============================================================================

#-------------------------------------------------------------------------------
# check_queue_worker - Vérifie si le service queue worker est configuré et actif
#-------------------------------------------------------------------------------
# Description:
#   Le queue worker est un processus daemon qui traite les jobs asynchrones
#   de Laravel. Il est essentiel pour :
#   - Traitement des tâches ControlHub (création de raccourcis, etc.)
#   - Envoi d'emails en arrière-plan
#   - Traitements longs sans bloquer les requêtes HTTP
#   - Callbacks vers ControlHub après exécution des tâches
#
# Vérifications:
#   1. Le fichier de service systemd existe
#   2. Le service est actif (en cours d'exécution)
#
# Retour:
#   0 - Le service queue worker est configuré et actif
#   1 - Le service n'est pas configuré ou est inactif
#-------------------------------------------------------------------------------
check_queue_worker() {
    log_message "INFO" "Vérification du service queue worker Laravel..."

    if [[ "$QUEUE_CONNECTION" == "sync" ]]; then
        log_message "INFO" "Queue=sync: aucun worker asynchrone nécessaire"
        return 0
    fi

    if $USE_DOCKER_ARTISAN; then
        local docker_worker_service=""
        for candidate in queue worker horizon; do
            if docker_service_exists "$candidate"; then
                docker_worker_service="$candidate"
                break
            fi
        done

        if [[ -n "$docker_worker_service" ]] && docker_service_running "$docker_worker_service"; then
            log_message "SUCCESS" "Service queue worker Docker actif ($docker_worker_service)"
            return 0
        fi

        log_message "WARNING" "Service queue worker Docker non configuré ou inactif"
        return 1
    fi

    if [[ -f "$QUEUE_SERVICE_FILE" ]] && systemctl is-active --quiet "$QUEUE_SERVICE_NAME"; then
        log_message "SUCCESS" "Service queue worker actif"
        return 0
    fi

    log_message "WARNING" "Service queue worker non configuré ou inactif"
    return 1
}

#-------------------------------------------------------------------------------
# setup_queue_worker - Configure les services systemd pour les queue workers Laravel
#-------------------------------------------------------------------------------
# Description:
#   Crée 2 services systemd distincts pour éviter les conflits de concurrence :
#   1. Worker GÉNÉRAL (queues: default, high, low) - Jobs standards
#   2. Worker SYNC (queue: sync) - Synchronisations AD (users, groups, etc.)
#
#   Chaque service :
#   - S'exécute en tant que www-admin (utilisateur SE4FS standard)
#   - Redémarre automatiquement en cas de crash
#   - Se recycle toutes les heures (--max-time=3600) pour éviter les fuites mémoire
#
# Configuration du service systemd:
#   [Unit]
#   - After=network.target postgresql.service : Attend que le réseau et PostgreSQL soient prêts
#
#   [Service]
#   - User/Group=www-admin : Exécution sous l'utilisateur SE4FS standard
#   - Restart=always : Redémarre automatiquement si le processus s'arrête
#   - RestartSec=3 : Attend 3 secondes avant de redémarrer
#
#   Options queue:work:
#   - database : Utilise le driver de queue "database" (table jobs)
#   - --queue=xxx : Filtre les queues à traiter (isolation stricte)
#   - --sleep=3 : Attend 3 secondes entre chaque vérification si pas de job
#   - --tries=3 : Réessaie 3 fois un job en échec avant de l'abandonner
#   - --max-time=3600 : Redémarre le worker après 1 heure (évite fuites mémoire)
#
# Actions:
#   1. Crée /etc/systemd/system/laravel-queue-general.service
#   2. Crée /etc/systemd/system/laravel-queue-sync.service
#   3. Recharge la configuration systemd (daemon-reload)
#   4. Active les 2 services au démarrage (enable)
#   5. Démarre les 2 services immédiatement (start)
#   6. Vérifie que les 2 services sont bien actifs
#-------------------------------------------------------------------------------
setup_queue_worker() {
    log_message "INFO" "Configuration des services queue workers Laravel (général + sync)..."

    if [[ "$QUEUE_CONNECTION" == "sync" ]]; then
        log_message "INFO" "Queue=sync: configuration des workers ignorée"
        return 0
    fi

    if $USE_DOCKER_ARTISAN; then
        local docker_worker_service=""
        for candidate in queue worker horizon; do
            if docker_service_exists "$candidate"; then
                docker_worker_service="$candidate"
                break
            fi
        done

        if [[ -z "$docker_worker_service" ]]; then
            log_message "WARNING" "Aucun service queue/worker/horizon trouvé dans docker compose"
            log_message "WARNING" "Configurez un service dédié puis relancez ce script"
            return 0
        fi

        "${DOCKER_COMPOSE_BIN[@]}" up -d "$docker_worker_service" > /dev/null 2>&1

        if docker_service_running "$docker_worker_service"; then
            log_message "SUCCESS" "Service queue worker Docker démarré ($docker_worker_service)"
            return 0
        fi

        log_message "ERROR" "Échec du démarrage du service queue worker Docker ($docker_worker_service)"
        return 1
    fi
    
    # Copier les fichiers de service depuis config/systemd/
    local systemd_config_dir="$LARAVEL_PATH/config/systemd"
    
    if [[ ! -d "$systemd_config_dir" ]]; then
        log_message "ERROR" "Dossier config/systemd/ introuvable dans $LARAVEL_PATH"
        return 1
    fi
    
    log_message "INFO" "Copie du service worker GÉNÉRAL..."
    if [[ -f "$systemd_config_dir/laravel-queue-general.service" ]]; then
        cp "$systemd_config_dir/laravel-queue-general.service" "$QUEUE_SERVICE_FILE_GENERAL"
    else
        log_message "ERROR" "Fichier laravel-queue-general.service introuvable dans config/systemd/"
        return 1
    fi
    
    log_message "INFO" "Copie du service worker SYNC..."
    if [[ -f "$systemd_config_dir/laravel-queue-sync.service" ]]; then
        cp "$systemd_config_dir/laravel-queue-sync.service" "$QUEUE_SERVICE_FILE_SYNC"
    else
        log_message "ERROR" "Fichier laravel-queue-sync.service introuvable dans config/systemd/"
        return 1
    fi
    
    # Recharger la configuration systemd
    systemctl daemon-reload
    
    # Activer et démarrer le worker GÉNÉRAL
    systemctl enable "$QUEUE_SERVICE_GENERAL"
    systemctl start "$QUEUE_SERVICE_GENERAL"
    
    # Activer et démarrer le worker SYNC
    systemctl enable "$QUEUE_SERVICE_SYNC"
    systemctl start "$QUEUE_SERVICE_SYNC"
    
    # Vérifier que les 2 services sont bien démarrés
    local general_ok=false
    local sync_ok=false
    
    if systemctl is-active --quiet "$QUEUE_SERVICE_GENERAL"; then
        log_message "SUCCESS" "Service queue worker GÉNÉRAL configuré et démarré"
        general_ok=true
    else
        log_message "ERROR" "Échec du démarrage du service queue worker GÉNÉRAL"
    fi
    
    if systemctl is-active --quiet "$QUEUE_SERVICE_SYNC"; then
        log_message "SUCCESS" "Service queue worker SYNC configuré et démarré"
        sync_ok=true
    else
        log_message "ERROR" "Échec du démarrage du service queue worker SYNC"
    fi
    
    if $general_ok && $sync_ok; then
        return 0
    else
        return 1
    fi
}

#===============================================================================
# FONCTIONS DE GESTION DE LA TABLE JOBS EN BASE DE DONNÉES
#===============================================================================

#-------------------------------------------------------------------------------
# check_jobs_table - Vérifie si la table jobs existe en base de données
#-------------------------------------------------------------------------------
# Description:
#   La table "jobs" stocke les jobs en attente de traitement par le queue worker.
#   Elle est créée par la migration Laravel `queue:table`.
#
#   Structure de la table jobs:
#   - id : Identifiant unique du job
#   - queue : Nom de la queue (default, high, low, etc.)
#   - payload : Données sérialisées du job
#   - attempts : Nombre de tentatives d'exécution
#   - reserved_at : Timestamp de réservation par un worker
#   - available_at : Timestamp de disponibilité
#   - created_at : Timestamp de création
#
# Méthode de vérification:
#   Utilise artisan tinker pour interroger le schéma de la BDD via Laravel.
#
# Retour:
#   0 - La table jobs existe
#   1 - La table jobs n'existe pas
#-------------------------------------------------------------------------------
check_jobs_table() {
    log_message "INFO" "Vérification de la table jobs en base de données..."

    if [[ "$QUEUE_CONNECTION" != "database" ]]; then
        log_message "INFO" "Queue=$QUEUE_CONNECTION: table jobs non requise"
        return 0
    fi
    
    cd "$LARAVEL_PATH"
    
    # Vérifier si la table existe via artisan tinker
    if run_artisan tinker --execute="echo Schema::hasTable('jobs') ? 'exists' : 'missing';" 2>/dev/null | grep -q "exists"; then
        log_message "SUCCESS" "Table jobs présente en BDD"
        return 0
    else
        log_message "WARNING" "Table jobs absente en BDD"
        return 1
    fi
}

#-------------------------------------------------------------------------------
# setup_jobs_table - Crée la table jobs si elle n'existe pas
#-------------------------------------------------------------------------------
# Description:
#   Crée la migration et la table jobs nécessaires au fonctionnement du
#   queue worker avec le driver "database".
#
# Étapes:
#   1. Vérifie si la migration existe déjà dans database/migrations/
#   2. Si absente, génère la migration avec `artisan queue:table`
#   3. Exécute toutes les migrations en attente avec `artisan migrate --force`
#   4. Vérifie que la table a bien été créée
#
# Note:
#   L'option --force est nécessaire en production pour éviter la confirmation
#   interactive.
#
# Tables créées:
#   - jobs : Jobs en attente de traitement
#   - failed_jobs : Jobs en échec (créée par queue:failed-table si nécessaire)
#-------------------------------------------------------------------------------
setup_jobs_table() {
    log_message "INFO" "Création de la table jobs..."

    if [[ "$QUEUE_CONNECTION" != "database" ]]; then
        log_message "INFO" "Queue=$QUEUE_CONNECTION: création de la table jobs ignorée"
        return 0
    fi
    
    cd "$LARAVEL_PATH"
    
    # Créer la migration si elle n'existe pas
    if ! ls database/migrations/*_create_jobs_table.php 1>/dev/null 2>&1; then
        run_artisan queue:table > /dev/null 2>&1
        log_message "INFO" "Migration jobs créée"
    fi
    
    # Exécuter les migrations (--force pour éviter la confirmation en production)
    run_artisan migrate --force > /dev/null 2>&1
    
    # Vérifier que la table a bien été créée
    if check_jobs_table; then
        log_message "SUCCESS" "Table jobs créée avec succès"
    else
        log_message "ERROR" "Échec de la création de la table jobs"
        return 1
    fi
}

#===============================================================================
# FONCTIONS D'AFFICHAGE DU STATUT
#===============================================================================

#-------------------------------------------------------------------------------
# show_status - Affiche un récapitulatif du statut de tous les services
#-------------------------------------------------------------------------------
# Description:
#   Affiche un tableau récapitulatif coloré de l'état de tous les services
#   configurés par ce script. Permet de vérifier rapidement que tout
#   fonctionne correctement.
#
# Services vérifiés:
#   1. Service cron système : Nécessaire pour les tâches planifiées
#   2. Cron Laravel : Entrée crontab pour schedule:run
#   3. Queue Worker : Service systemd pour le traitement des jobs
#   4. Table jobs : Table en BDD pour stocker les jobs
#   5. Laravel : Vérification que l'application fonctionne
#
# Affichage:
#   ✅ Vert : Service actif/configuré
#   ❌ Rouge : Service inactif/non configuré
#-------------------------------------------------------------------------------
show_status() {
    log_message "INFO" "=== STATUT FINAL ==="
    
    echo
    echo -e "${BLUE}📊 Statut des services :${NC}"
    
    # 1. Service cron système
    if systemctl is-active --quiet cron; then
        echo -e "  ${GREEN}✅ Service cron : ACTIF${NC}"
    else
        echo -e "  ${RED}❌ Service cron : INACTIF${NC}"
    fi
    
    # 2. Cron Laravel (entrée crontab de www-admin)
    if crontab -u www-admin -l 2>/dev/null | grep -q "schedule:run"; then
        echo -e "  ${GREEN}✅ Cron Laravel (www-admin) : CONFIGURÉ${NC}"
    else
        echo -e "  ${RED}❌ Cron Laravel (www-admin) : NON CONFIGURÉ${NC}"
    fi
    
    # 3. Queue worker (service systemd)
    if [[ "$QUEUE_CONNECTION" == "sync" ]]; then
        echo -e "  ${BLUE}ℹ️  Queue Worker : NON REQUIS (sync)${NC}"
    elif $USE_DOCKER_ARTISAN; then
        local docker_worker_service=""
        for candidate in queue worker horizon; do
            if docker_service_exists "$candidate"; then
                docker_worker_service="$candidate"
                break
            fi
        done

        if [[ -n "$docker_worker_service" ]] && docker_service_running "$docker_worker_service"; then
            echo -e "  ${GREEN}✅ Queue Worker Docker : ACTIF (${docker_worker_service})${NC}"
        else
            echo -e "  ${RED}❌ Queue Worker Docker : INACTIF${NC}"
        fi
    elif systemctl is-active --quiet "$QUEUE_SERVICE_NAME"; then
        echo -e "  ${GREEN}✅ Queue Worker : ACTIF${NC}"
    else
        echo -e "  ${RED}❌ Queue Worker : INACTIF${NC}"
    fi
    
    # 4. Table jobs en BDD
    cd "$LARAVEL_PATH"
    if [[ "$QUEUE_CONNECTION" != "database" ]]; then
        echo -e "  ${BLUE}ℹ️  Table jobs : NON REQUISE (queue=$QUEUE_CONNECTION)${NC}"
    elif run_artisan tinker --execute="echo Schema::hasTable('jobs') ? 'exists' : 'missing';" 2>/dev/null | grep -q "exists"; then
        echo -e "  ${GREEN}✅ Table jobs : PRÉSENTE${NC}"
    else
        echo -e "  ${RED}❌ Table jobs : ABSENTE${NC}"
    fi
    
    # 5. Laravel opérationnel
    if run_artisan --version > /dev/null 2>&1; then
        echo -e "  ${GREEN}✅ Laravel : OPÉRATIONNEL${NC}"
    else
        echo -e "  ${RED}❌ Laravel : PROBLÈME${NC}"
    fi
    
    echo
    echo -e "${GREEN}🚀 Configuration terminée !${NC}"
    echo -e "${BLUE}📝 Logs disponibles dans : $LOG_FILE${NC}"
}

#===============================================================================
# FONCTION PRINCIPALE
#===============================================================================

#-------------------------------------------------------------------------------
# main - Point d'entrée principal du script
#-------------------------------------------------------------------------------
# Description:
#   Orchestre l'exécution séquentielle de toutes les étapes de configuration.
#   Chaque étape vérifie d'abord si la configuration est nécessaire avant
#   d'appliquer des modifications.
#
# Ordre d'exécution:
#   1. Service cron système : Prérequis pour les tâches planifiées
#   2. Permissions Laravel : Nécessaire pour que Laravel puisse écrire ses fichiers
#   3. Vérification Laravel : S'assure que l'application fonctionne
#   4. Optimisation Laravel : Cache les configs/routes/vues pour la production
#   5. Cron Laravel : Configure l'exécution périodique du scheduler
#   6. Table jobs : Crée la table en BDD pour stocker les jobs
#   7. Queue Worker : Configure le service systemd pour traiter les jobs
#
# Gestion des erreurs:
#   - Si Laravel n'est pas opérationnel, le script s'arrête (exit 1)
#   - Les autres erreurs sont loguées mais n'arrêtent pas le script
#
# Sortie:
#   Affiche un récapitulatif coloré de l'état de tous les services
#-------------------------------------------------------------------------------
main() {
    echo -e "${BLUE}🔧 SE4/SambaEdu - Script de configuration automatique${NC}"
    echo -e "${BLUE}=================================================${NC}"
    echo
    
    # Créer le répertoire de logs si nécessaire
    mkdir -p "$(dirname "$LOG_FILE")"
    
    log_message "INFO" "Début de la configuration automatique"

    # Détection du contexte d'exécution (DB/queue + Docker ou hôte)
    detect_runtime_context
    
    #---------------------------------------------------------------------------
    # ÉTAPE 1 : Service cron système
    #---------------------------------------------------------------------------
    # Le service cron est nécessaire pour exécuter les tâches planifiées.
    # Sans lui, le scheduler Laravel ne pourra pas fonctionner.
    if ! check_cron_service; then
        start_cron_service
    fi
    
    #---------------------------------------------------------------------------
    # ÉTAPE 2 : Permissions Laravel
    #---------------------------------------------------------------------------
    # Laravel a besoin de pouvoir écrire dans storage/ et bootstrap/cache/.
    # Ces répertoires doivent appartenir à www-admin avec les bonnes permissions.
    if ! check_laravel_permissions; then
        fix_laravel_permissions
    fi
    
    #---------------------------------------------------------------------------
    # ÉTAPE 3 : Vérification Laravel
    #---------------------------------------------------------------------------
    # Vérifie que Laravel peut s'exécuter. Si cette étape échoue, il y a un
    # problème critique (PHP, dépendances, configuration) et on arrête le script.
    if ! check_laravel_config; then
        log_message "ERROR" "Laravel non fonctionnel - Vérifiez la configuration"
        exit 1
    fi
    
    #---------------------------------------------------------------------------
    # ÉTAPE 4 : Optimisation Laravel
    #---------------------------------------------------------------------------
    # Met en cache les configurations, routes et vues pour améliorer les
    # performances en production.
    optimize_laravel
    
    #---------------------------------------------------------------------------
    # ÉTAPE 5 : Cron Laravel
    #---------------------------------------------------------------------------
    # Ajoute l'entrée crontab pour exécuter `artisan schedule:run` chaque minute.
    # Cette commande vérifie et exécute les tâches planifiées définies dans Laravel.
    if ! check_laravel_cron; then
        setup_laravel_cron
    fi
    
    #---------------------------------------------------------------------------
    # ÉTAPE 6 : Table jobs en BDD
    #---------------------------------------------------------------------------
    # Crée la table "jobs" nécessaire au fonctionnement du queue worker
    # avec le driver "database".
    if ! check_jobs_table; then
        setup_jobs_table
    fi
    
    #---------------------------------------------------------------------------
    # ÉTAPE 7 : Queue Worker
    #---------------------------------------------------------------------------
    # Configure et démarre le service systemd qui traite les jobs asynchrones.
    # Ce service tourne en permanence sous l'utilisateur www-admin.
    if ! check_queue_worker; then
        setup_queue_worker
    fi
    
    log_message "SUCCESS" "Configuration automatique terminée avec succès"
    
    # Afficher le récapitulatif final
    show_status
}

#===============================================================================
# VÉRIFICATIONS PRÉALABLES ET EXÉCUTION
#===============================================================================

#-------------------------------------------------------------------------------
# Vérification des privilèges root
#-------------------------------------------------------------------------------
# Ce script modifie des fichiers système (crontab, systemd) et des permissions.
# Il doit donc être exécuté avec les privilèges root (sudo).
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}❌ Ce script doit être exécuté en tant que root${NC}"
    echo -e "${YELLOW}   Utilisez : sudo ./setup.sh${NC}"
    exit 1
fi

#-------------------------------------------------------------------------------
# Vérification de l'existence du répertoire Laravel
#-------------------------------------------------------------------------------
# Le script ne peut pas fonctionner si le répertoire Laravel n'existe pas.
# Cela indique généralement que l'application n'a pas été déployée correctement.
if [[ ! -d "$LARAVEL_PATH" ]]; then
    echo -e "${RED}❌ Répertoire Laravel non trouvé : $LARAVEL_PATH${NC}"
    echo -e "${YELLOW}   Vérifiez que l'application a été déployée correctement.${NC}"
    exit 1
fi

#-------------------------------------------------------------------------------
# Exécution du script principal
#-------------------------------------------------------------------------------
main

exit 0
