#!/bin/bash
#
# laravelUpgrade.sh - Script de migration SambaEdu Legacy vers Laravel
#
# Ce script exécute étape par étape l'ensemble des opérations nécessaires
# à la transition du code legacy vers Laravel.
#
# Usage: ./laravelUpgrade.sh [--dry-run] [--skip-backup] [--step=N]
#
# Options:
#   --dry-run      Affiche les commandes sans les exécuter
#   --skip-backup  Ignore les étapes de sauvegarde (dangereux!)
#   --step=N       Commence à l'étape N
#   --help         Affiche cette aide
#
# Auteur: SambaEdu Team
# Date: 2026-01-29
#

set -e  # Arrêter en cas d'erreur

# =============================================================================
# CONFIGURATION
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARAVEL_DIR="$(dirname "$SCRIPT_DIR")"
SAMBAEDU_DIR="$(dirname "$LARAVEL_DIR")"
BACKUP_DIR="/var/backups/sambaedu/laravel-upgrade"
LOG_FILE="/var/log/sambaedu/laravel-upgrade.log"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Couleurs pour l'affichage
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Options par défaut
DRY_RUN=false
SKIP_BACKUP=false
START_STEP=1

# =============================================================================
# FONCTIONS UTILITAIRES
# =============================================================================

log() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo -e "${timestamp} [${level}] ${message}" | tee -a "$LOG_FILE"
}

log_info() {
    echo -e "${BLUE}[INFO]${NC} $*"
    log "INFO" "$*"
}

log_success() {
    echo -e "${GREEN}[OK]${NC} $*"
    log "SUCCESS" "$*"
}

log_warning() {
    echo -e "${YELLOW}[WARN]${NC} $*"
    log "WARNING" "$*"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $*"
    log "ERROR" "$*"
}

log_step() {
    local step_num="$1"
    local step_name="$2"
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  ÉTAPE ${step_num}: ${step_name}${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    log "STEP" "Étape ${step_num}: ${step_name}"
}

run_cmd() {
    if [ "$DRY_RUN" = true ]; then
        echo -e "${YELLOW}[DRY-RUN]${NC} $*"
        return 0
    fi
    "$@"
}

confirm() {
    local message="$1"
    read -p "$message [o/N] " response
    case "$response" in
        [oOyY]) return 0 ;;
        *) return 1 ;;
    esac
}

check_root() {
    if [ "$EUID" -ne 0 ]; then
        log_error "Ce script doit être exécuté en tant que root"
        exit 1
    fi
}

check_services() {
    log_info "Vérification des services..."
    
    # Vérifier MySQL
    if ! systemctl is-active --quiet mysql; then
        log_error "MySQL n'est pas actif"
        exit 1
    fi
    log_success "MySQL actif"
    
    # Vérifier Apache
    if ! systemctl is-active --quiet apache2; then
        log_warning "Apache n'est pas actif (optionnel pour la migration)"
    else
        log_success "Apache actif"
    fi
    
    # Vérifier Samba AD
    if ! systemctl is-active --quiet samba-ad-dc; then
        log_warning "Samba AD DC n'est pas actif"
    else
        log_success "Samba AD DC actif"
    fi
}

# =============================================================================
# ÉTAPES DE MIGRATION
# =============================================================================

step_1_pre_checks() {
    log_step 1 "Vérifications préliminaires"
    
    check_root
    check_services
    
    # Vérifier l'espace disque
    local free_space=$(df -BG /var | tail -1 | awk '{print $4}' | tr -d 'G')
    if [ "$free_space" -lt 5 ]; then
        log_error "Espace disque insuffisant (< 5 Go disponibles)"
        exit 1
    fi
    log_success "Espace disque suffisant: ${free_space} Go disponibles"
    
    # Vérifier que Laravel est installé
    if [ ! -f "$LARAVEL_DIR/artisan" ]; then
        log_error "Laravel non trouvé dans $LARAVEL_DIR"
        exit 1
    fi
    log_success "Laravel trouvé"
    
    # Vérifier le fichier .env
    if [ ! -f "$LARAVEL_DIR/.env" ]; then
        log_error "Fichier .env manquant"
        exit 1
    fi
    log_success "Fichier .env présent"
    
    # Créer le répertoire de backup
    run_cmd mkdir -p "$BACKUP_DIR/$TIMESTAMP"
    log_success "Répertoire de backup créé: $BACKUP_DIR/$TIMESTAMP"
}

step_2_backup_database() {
    log_step 2 "Sauvegarde de la base de données MySQL"
    
    if [ "$SKIP_BACKUP" = true ]; then
        log_warning "Sauvegarde ignorée (--skip-backup)"
        return 0
    fi
    
    local db_name=$(grep DB_DATABASE "$LARAVEL_DIR/.env" | cut -d '=' -f2)
    local db_user=$(grep DB_USERNAME "$LARAVEL_DIR/.env" | cut -d '=' -f2)
    local db_pass=$(grep DB_PASSWORD "$LARAVEL_DIR/.env" | cut -d '=' -f2)
    
    log_info "Sauvegarde de la base $db_name..."
    
    local backup_file="$BACKUP_DIR/$TIMESTAMP/mysql_${db_name}_${TIMESTAMP}.sql.gz"
    
    run_cmd mysqldump -u "$db_user" -p"$db_pass" "$db_name" | gzip > "$backup_file"
    
    if [ -f "$backup_file" ]; then
        local size=$(du -h "$backup_file" | cut -f1)
        log_success "Base de données sauvegardée: $backup_file ($size)"
    else
        log_error "Échec de la sauvegarde de la base de données"
        exit 1
    fi
}

step_3_backup_ad() {
    log_step 3 "Sauvegarde de l'Active Directory (Samba)"
    
    if [ "$SKIP_BACKUP" = true ]; then
        log_warning "Sauvegarde AD ignorée (--skip-backup)"
        return 0
    fi
    
    local ad_backup_dir="$BACKUP_DIR/$TIMESTAMP/samba-ad"
    run_cmd mkdir -p "$ad_backup_dir"
    
    # Sauvegarde des fichiers de configuration Samba
    log_info "Sauvegarde de la configuration Samba..."
    run_cmd cp -a /etc/samba "$ad_backup_dir/"
    
    # Sauvegarde de la base AD (tdb files)
    log_info "Sauvegarde de la base AD..."
    if [ -d "/var/lib/samba/private" ]; then
        run_cmd tar -czf "$ad_backup_dir/samba-private.tar.gz" -C /var/lib/samba private
    fi
    
    # Export LDIF de l'AD
    log_info "Export LDIF de l'AD..."
    local ldif_file="$ad_backup_dir/ad_export_${TIMESTAMP}.ldif"
    run_cmd samba-tool domain backup online --targetdir="$ad_backup_dir" 2>/dev/null || \
        log_warning "Backup online non disponible, utilisation de ldbsearch"
    
    # Alternative: export via ldbsearch
    if [ -f "/var/lib/samba/private/sam.ldb" ]; then
        run_cmd ldbsearch -H /var/lib/samba/private/sam.ldb "(objectClass=*)" > "$ldif_file" 2>/dev/null || true
    fi
    
    log_success "Active Directory sauvegardé dans $ad_backup_dir"
}

step_4_backup_files() {
    log_step 4 "Sauvegarde des fichiers de configuration"
    
    if [ "$SKIP_BACKUP" = true ]; then
        log_warning "Sauvegarde fichiers ignorée (--skip-backup)"
        return 0
    fi
    
    local files_backup_dir="$BACKUP_DIR/$TIMESTAMP/files"
    run_cmd mkdir -p "$files_backup_dir"
    
    # Fichiers de configuration SambaEdu
    log_info "Sauvegarde des fichiers de configuration SE4..."
    local config_files=(
        "/etc/sambaedu"
        "/var/www"
        "/etc/apache2"
        "/etc/httpd"
        "$SAMBAEDU_DIR/includes/config.inc.php"
    )
    
    for file in "${config_files[@]}"; do
        if [ -e "$file" ]; then
            run_cmd cp -a "$file" "$files_backup_dir/" 2>/dev/null || true
        fi
    done
    
    # Fichier .env Laravel
    run_cmd cp "$LARAVEL_DIR/.env" "$files_backup_dir/.env.backup"
    
    log_success "Fichiers de configuration sauvegardés"
}

step_5_maintenance_mode() {
    log_step 5 "Activation du mode maintenance"
    
    log_info "Activation du mode maintenance Laravel..."
    run_cmd php "$LARAVEL_DIR/artisan" down --message="Migration en cours vers Laravel" --retry=60
    
    log_success "Mode maintenance activé"
}

step_6_clear_caches() {
    log_step 6 "Nettoyage des caches"
    
    log_info "Nettoyage des caches Laravel..."
    run_cmd php "$LARAVEL_DIR/artisan" cache:clear
    run_cmd php "$LARAVEL_DIR/artisan" config:clear
    run_cmd php "$LARAVEL_DIR/artisan" route:clear
    run_cmd php "$LARAVEL_DIR/artisan" view:clear
    
    log_info "Nettoyage du cache APCu..."
    run_cmd php -r "if (function_exists('apcu_clear_cache')) { apcu_clear_cache(); echo 'APCu cleared'; }" || true
    
    log_success "Caches nettoyés"
}

step_7_run_migrations() {
    log_step 7 "Exécution des migrations Laravel"
    
    log_info "Exécution des migrations..."
    run_cmd php "$LARAVEL_DIR/artisan" migrate --force
    
    log_success "Migrations exécutées avec succès"
}

step_8_sync_ad_data() {
    log_step 8 "Synchronisation des données depuis l'AD"
    
    log_info "Synchronisation des WorkstationGroups depuis l'AD..."
    
    # Exécuter la synchronisation via tinker
    run_cmd php "$LARAVEL_DIR/artisan" tinker --execute="
        \$adSync = app(\App\Services\AdSync\AdSyncService::class);
        
        // Sync des groupes de postes (salles/parcs)
        echo 'Synchronisation des WorkstationGroups...' . PHP_EOL;
        try {
            \$result = \$adSync->syncWorkstationGroups();
            echo 'WorkstationGroups synchronisés: ' . (\$result['synced'] ?? 0) . PHP_EOL;
        } catch (\Exception \$e) {
            echo 'Erreur sync WorkstationGroups: ' . \$e->getMessage() . PHP_EOL;
        }
        
        // Sync des profils applicatifs
        echo 'Synchronisation des AppProfiles...' . PHP_EOL;
        try {
            \$result = \$adSync->syncAppProfiles();
            echo 'AppProfiles synchronisés: ' . (\$result['synced'] ?? 0) . PHP_EOL;
        } catch (\Exception \$e) {
            echo 'Erreur sync AppProfiles: ' . \$e->getMessage() . PHP_EOL;
        }
    "
    
    log_success "Synchronisation AD terminée"
}

step_9_optimize() {
    log_step 9 "Optimisation Laravel"
    
    log_info "Optimisation des configurations..."
    run_cmd php "$LARAVEL_DIR/artisan" config:cache
    run_cmd php "$LARAVEL_DIR/artisan" route:cache
    run_cmd php "$LARAVEL_DIR/artisan" view:cache
    
    log_info "Optimisation de l'autoloader Composer..."
    run_cmd composer dump-autoload --optimize --working-dir="$LARAVEL_DIR"
    
    log_success "Optimisations appliquées"
}

step_10_verify() {
    log_step 10 "Vérifications post-migration"
    
    log_info "Vérification de la structure de la base de données..."
    
    # Vérifier les tables critiques
    run_cmd php "$LARAVEL_DIR/artisan" tinker --execute="
        \$tables = [
            'workstation_groups' => \App\Models\WorkstationGroup::count(),
            'app_profiles' => \App\Models\AppProfile::count(),
            'postes' => DB::table('postes')->count(),
        ];
        
        foreach (\$tables as \$table => \$count) {
            echo \$table . ': ' . \$count . ' enregistrements' . PHP_EOL;
        }
        
        // Vérifier que les tables legacy sont supprimées
        \$legacyTables = ['parc', 'parc_profile', 'applications_profile'];
        foreach (\$legacyTables as \$table) {
            \$exists = Schema::hasTable(\$table);
            echo \$table . ': ' . (\$exists ? 'EXISTE ENCORE (problème!)' : 'supprimée OK') . PHP_EOL;
        }
    "
    
    log_success "Vérifications terminées"
}

step_11_disable_maintenance() {
    log_step 11 "Désactivation du mode maintenance"
    
    log_info "Désactivation du mode maintenance..."
    run_cmd php "$LARAVEL_DIR/artisan" up
    
    log_success "Mode maintenance désactivé - Application accessible"
}

step_12_summary() {
    log_step 12 "Résumé de la migration"
    
    echo ""
    echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}  MIGRATION TERMINÉE AVEC SUCCÈS${NC}"
    echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo "Sauvegardes disponibles dans: $BACKUP_DIR/$TIMESTAMP"
    echo "Logs disponibles dans: $LOG_FILE"
    echo ""
    echo "Prochaines étapes recommandées:"
    echo "  1. Tester l'accès à l'interface web"
    echo "  2. Vérifier la synchronisation AD dans l'interface admin"
    echo "  3. Tester les fonctionnalités critiques (auth, parcs, etc.)"
    echo ""
    
    log "INFO" "Migration terminée avec succès"
}

rollback() {
    log_step "ROLLBACK" "Restauration depuis la sauvegarde"
    
    local backup_to_restore="$1"
    
    if [ -z "$backup_to_restore" ]; then
        # Lister les sauvegardes disponibles
        echo "Sauvegardes disponibles:"
        ls -la "$BACKUP_DIR/"
        read -p "Entrez le nom du dossier de sauvegarde à restaurer: " backup_to_restore
    fi
    
    local restore_dir="$BACKUP_DIR/$backup_to_restore"
    
    if [ ! -d "$restore_dir" ]; then
        log_error "Sauvegarde non trouvée: $restore_dir"
        exit 1
    fi
    
    log_warning "ATTENTION: Cette opération va restaurer la base de données!"
    if ! confirm "Continuer?"; then
        log_info "Rollback annulé"
        exit 0
    fi
    
    # Restaurer la base de données
    local db_name=$(grep DB_DATABASE "$LARAVEL_DIR/.env" | cut -d '=' -f2)
    local db_user=$(grep DB_USERNAME "$LARAVEL_DIR/.env" | cut -d '=' -f2)
    local db_pass=$(grep DB_PASSWORD "$LARAVEL_DIR/.env" | cut -d '=' -f2)
    
    local sql_backup=$(find "$restore_dir" -name "mysql_*.sql.gz" | head -1)
    
    if [ -n "$sql_backup" ]; then
        log_info "Restauration de la base de données depuis $sql_backup..."
        run_cmd zcat "$sql_backup" | mysql -u "$db_user" -p"$db_pass" "$db_name"
        log_success "Base de données restaurée"
    else
        log_error "Aucune sauvegarde SQL trouvée"
    fi
    
    log_success "Rollback terminé"
}

# =============================================================================
# PARSING DES ARGUMENTS
# =============================================================================

show_help() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Script de migration SambaEdu Legacy vers Laravel"
    echo ""
    echo "Options:"
    echo "  --dry-run       Affiche les commandes sans les exécuter"
    echo "  --skip-backup   Ignore les étapes de sauvegarde (dangereux!)"
    echo "  --step=N        Commence à l'étape N"
    echo "  --rollback[=DIR] Restaure depuis une sauvegarde"
    echo "  --help          Affiche cette aide"
    echo ""
    echo "Étapes:"
    echo "  1. Vérifications préliminaires"
    echo "  2. Sauvegarde base de données MySQL"
    echo "  3. Sauvegarde Active Directory"
    echo "  4. Sauvegarde fichiers de configuration"
    echo "  5. Activation mode maintenance"
    echo "  6. Nettoyage des caches"
    echo "  7. Exécution des migrations Laravel"
    echo "  8. Synchronisation données depuis AD"
    echo "  9. Optimisation Laravel"
    echo "  10. Vérifications post-migration"
    echo "  11. Désactivation mode maintenance"
    echo "  12. Résumé"
}

for arg in "$@"; do
    case $arg in
        --dry-run)
            DRY_RUN=true
            log_warning "Mode DRY-RUN activé - aucune modification ne sera effectuée"
            ;;
        --skip-backup)
            SKIP_BACKUP=true
            log_warning "Les sauvegardes seront ignorées!"
            ;;
        --step=*)
            START_STEP="${arg#*=}"
            log_info "Démarrage à l'étape $START_STEP"
            ;;
        --rollback)
            rollback ""
            exit 0
            ;;
        --rollback=*)
            rollback "${arg#*=}"
            exit 0
            ;;
        --help|-h)
            show_help
            exit 0
            ;;
        *)
            log_error "Option inconnue: $arg"
            show_help
            exit 1
            ;;
    esac
done

# =============================================================================
# EXÉCUTION PRINCIPALE
# =============================================================================

main() {
    echo ""
    echo -e "${BLUE}╔═══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║     MIGRATION SAMBAEDU LEGACY → LARAVEL                       ║${NC}"
    echo -e "${BLUE}║     $(date '+%Y-%m-%d %H:%M:%S')                                      ║${NC}"
    echo -e "${BLUE}╚═══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    
    # Créer le répertoire de logs si nécessaire
    mkdir -p "$(dirname "$LOG_FILE")"
    
    log "INFO" "=== Début de la migration Laravel ==="
    
    # Exécuter les étapes
    [ $START_STEP -le 1 ] && step_1_pre_checks
    [ $START_STEP -le 2 ] && step_2_backup_database
    [ $START_STEP -le 3 ] && step_3_backup_ad
    [ $START_STEP -le 4 ] && step_4_backup_files
    [ $START_STEP -le 5 ] && step_5_maintenance_mode
    [ $START_STEP -le 6 ] && step_6_clear_caches
    [ $START_STEP -le 7 ] && step_7_run_migrations
    [ $START_STEP -le 8 ] && step_8_sync_ad_data
    [ $START_STEP -le 9 ] && step_9_optimize
    [ $START_STEP -le 10 ] && step_10_verify
    [ $START_STEP -le 11 ] && step_11_disable_maintenance
    [ $START_STEP -le 12 ] && step_12_summary
    
    log "INFO" "=== Fin de la migration Laravel ==="
}

# Lancer le script
main
