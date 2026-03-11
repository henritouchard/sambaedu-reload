#!/bin/bash
# ============================================================================
# SambaEdu Installation Script
# Installation complète: Docker + .env + dépendances + migrations
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log() {
    echo -e "${BLUE}[install]${NC} $*"
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
# Vérification de l'état existant
# ============================================================================

check_existing_services() {
    log "Vérification des services existants..."

    if ! command -v docker &>/dev/null; then
        return
    fi

    # Vérifier les conteneurs SambaEdu
    local running_count
    running_count=$(docker compose ps --services --filter "status=running" 2>/dev/null | wc -l || echo 0)

    if [[ $running_count -gt 0 ]]; then
        log_warning "Services SambaEdu déjà en cours d'exécution:"
        docker compose ps 2>/dev/null || true
        echo ""
        log_warning "Options:"
        echo "  1. Continuer (les services seront redémarrés)"
        echo "  2. Arrêter et nettoyer: docker compose down"
        echo "  3. Supprimer complètement: docker compose down -v"
        echo ""
    fi
}

# ============================================================================
# Vérification des prérequis système
# ============================================================================

check_apache() {
    log "Vérification Apache..."
    if ! command -v apache2 &>/dev/null; then
        log_warning "Apache2 n'est pas installé - configuration Apache ignorée"
        return 1
    fi
    log_success "Apache2 trouvé: $(apache2 -v 2>&1 | head -n1)"
    return 0
}

check_bash() {
    log "Vérification bash..."
    if [[ ! -n "${BASH_VERSION}" ]]; then
        log_error "Ce script doit être exécuté avec bash"
        exit 1
    fi
    log_success "bash OK"
}

check_docker() {
    log "Vérification Docker..."
    if ! command -v docker &>/dev/null; then
        log_error "Docker n'est pas installé"
        log_warning "Installation de Docker via get.docker.com..."
        curl -fsSL https://get.docker.com | sh
        log_success "Docker installé"
    else
        log_success "Docker trouvé: $(docker --version)"
    fi

    # Démarrer le service Docker
    if ! systemctl is-active --quiet docker; then
        log "Démarrage du service Docker..."
        systemctl start docker
        systemctl enable docker
        log_success "Docker service démarré"
    fi
}

check_docker_compose() {
    log "Vérification Docker Compose..."
    if ! docker compose version &>/dev/null; then
        log_error "Docker Compose plugin n'est pas installé"
        exit 1
    fi
    log_success "Docker Compose OK"
}

check_php() {
    log "Vérification PHP..."
    if ! command -v php &>/dev/null; then
        log_error "PHP n'est pas installé"
        log_warning "Installez PHP: sudo apt install php php-cli"
        exit 1
    fi
    local php_version
    php_version=$(php -v | head -n1)
    log_success "PHP trouvé: $php_version"
}

check_composer() {
    log "Vérification Composer..."
    if ! command -v composer &>/dev/null; then
        log_error "Composer n'est pas installé"
        log_warning "Installez Composer: https://getcomposer.org/download/"
        exit 1
    fi
    log_success "Composer trouvé: $(composer --version)"
}

check_npm() {
    log "Vérification NPM (optionnel)..."
    if ! command -v npm &>/dev/null; then
        log_warning "NPM n'est pas installé - build frontend sera ignoré"
        return 1
    fi
    log_success "NPM trouvé: $(npm --version)"
    return 0
}

# ============================================================================
# Génération du .env
# ============================================================================

generate_env() {
    log "Génération du fichier .env..."

    if [[ -f "$APP_DIR/.env" ]]; then
        log_warning ".env existe déjà, utilisation du fichier existant"
        return
    fi

    if [[ ! -f "$SCRIPT_DIR/create-env.sh" ]]; then
        log_error "Script create-env.sh non trouvé"
        exit 1
    fi

    cd "$APP_DIR"
    bash "$SCRIPT_DIR/create-env.sh"
    log_success ".env créé avec succès"
}

interactive_env_validation() {
    echo ""
    echo -e "${BLUE}════════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}Configuration du fichier .env${NC}"
    echo -e "${BLUE}════════════════════════════════════════════════════════════════${NC}"
    echo ""

    log "Affichage du fichier .env généré:"
    echo ""
    cat "$APP_DIR/.env"
    echo ""

    # Demander à l'utilisateur s'il veut éditer
    log_warning "IMPORTANT: Vérifiez et personnalisez les variables si nécessaire"
    echo ""

    while true; do
        read -p "Voulez-vous éditer le .env? (y/n): " -n 1 -r
        echo ""

        if [[ $REPLY == "y" ]]; then
            log "Ouverture de l'éditeur..."
            ${EDITOR:-nano} "$APP_DIR/.env"

            # Afficher à nouveau après édition
            echo ""
            log "Fichier .env après édition:"
            echo ""
            cat "$APP_DIR/.env"
            echo ""
        fi

        # Demander confirmation
        read -p "Continuer avec cette configuration? (y/n): " -n 1 -r
        echo ""

        if [[ $REPLY == "y" ]]; then
            log_success "Configuration validée"
            break
        fi
    done

    echo ""
}

# ============================================================================
# Déploiement Docker
# ============================================================================

deploy_docker() {
    log "Déploiement Docker (PostgreSQL + Redis)..."

    cd "$APP_DIR"

    # Vérifier si les conteneurs sont déjà lancés
    if docker compose ps --services --filter "status=running" 2>/dev/null | grep -q postgres; then
        log_warning "PostgreSQL est déjà en cours d'exécution"
        log "Redémarrage des services..."
        docker compose restart
        log_success "Services redémarrés"
    else
        # Lancer les conteneurs
        log "Démarrage des conteneurs..."
        docker compose up -d
        log_success "Conteneurs démarrés"
    fi

    # Attendre que PostgreSQL soit prêt
    log "Attente de disponibilité PostgreSQL..."
    max_attempts=30
    attempt=0
    while [[ $attempt -lt $max_attempts ]]; do
        if docker compose exec -T postgres pg_isready -U sambaedu -d sambaedu >/dev/null 2>&1; then
            log_success "PostgreSQL est prêt"
            break
        fi
        attempt=$((attempt + 1))
        sleep 1
    done

    if [[ $attempt -eq $max_attempts ]]; then
        log_error "PostgreSQL n'a pas répondu dans le délai imparti"
        exit 1
    fi

    # Attendre que Redis soit prêt
    log "Attente de disponibilité Redis..."
    attempt=0
    while [[ $attempt -lt $max_attempts ]]; do
        if docker compose exec -T redis redis-cli ping >/dev/null 2>&1; then
            log_success "Redis est prêt"
            break
        fi
        attempt=$((attempt + 1))
        sleep 1
    done

    if [[ $attempt -eq $max_attempts ]]; then
        log_error "Redis n'a pas répondu dans le délai imparti"
        exit 1
    fi
}

# ============================================================================
# Installation des dépendances
# ============================================================================

install_composer() {
    log "Installation des dépendances Composer..."
    cd "$APP_DIR"

    composer install --no-dev --optimize-autoloader --no-interaction

    log_success "Composer OK"
}

install_npm() {
    log "Installation des dépendances NPM..."
    cd "$APP_DIR"

    if [[ ! -f package.json ]]; then
        log_warning "package.json non trouvé - build frontend ignoré"
        return
    fi

    npm install
    npm run build

    log_success "NPM OK"
}

# ============================================================================
# Migrations de base de données
# ============================================================================

run_migrations() {
    log "Exécution des migrations de base de données..."
    cd "$APP_DIR"

    php artisan migrate:fresh --seed

    log_success "Migrations OK"
}

# ============================================================================
# Mise à jour applicative
# ============================================================================

run_application_update() {
    log "Exécution de la mise à jour applicative..."
    cd "$APP_DIR"

    php artisan sambaedu:app:update

    log_success "Mise à jour applicative OK"
}

# ============================================================================
# Configuration Apache
# ============================================================================

configure_apache() {
    local conf_source="$APP_DIR/config/apache/sambaedu-reload.conf"
    local conf_target="/etc/apache2/sites-available/sambaedu-reload.conf"

    log "Configuration Apache (port 8080)..."

    if [[ ! -f "$conf_source" ]]; then
        log_error "Configuration Apache source introuvable: $conf_source"
        exit 1
    fi

    cp "$conf_source" "$conf_target"

    # Ajouter Listen 8080 si absent
    if ! grep -q "Listen 8080" /etc/apache2/ports.conf; then
        echo "Listen 8080" >> /etc/apache2/ports.conf
        log "Listen 8080 ajouté à ports.conf"
    fi

    a2ensite sambaedu-reload.conf >/dev/null 2>&1 || true
    a2enmod rewrite >/dev/null 2>&1 || true
    a2enmod headers >/dev/null 2>&1 || true
    a2enmod proxy_fcgi >/dev/null 2>&1 || true
    systemctl reload apache2
    log_success "Apache configuré (sambaedu-reload sur port 8080)"
}

# ============================================================================
# Affichage du résumé
# ============================================================================

show_summary() {
    echo ""
    echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}Installation SambaEdu terminée avec succès!${NC}"
    echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo "Services disponibles:"
    echo "  📦 PostgreSQL:  localhost:5432"
    echo "  🔴 Redis:       localhost:6379"
    echo ""
    echo "Application:"
    echo "  🌐 URL: Configurez APP_URL dans .env"
    echo "  📝 Configuration: $APP_DIR/.env"
    echo ""
    echo "Prochaines étapes:"
    echo ""
    echo "  1. Vérifier la configuration:"
    echo "     cat $APP_DIR/.env"
    echo ""
    echo "  2. Relancer l'application:"
    echo "     docker compose restart"
    echo ""
    echo "  3. Mettre à jour régulièrement:"
    echo "     sudo $SCRIPT_DIR/update.sh"
    echo ""
    echo "Commandes utiles:"
    echo "  # Logs PostgreSQL:"
    echo "  docker compose logs -f postgres"
    echo ""
    echo "  # Logs Redis:"
    echo "  docker compose logs -f redis"
    echo ""
    echo "  # Accéder à PostgreSQL:"
    echo "  docker compose exec postgres psql -U sambaedu -d sambaedu"
    echo ""
    echo "  # Accéder à Redis:"
    echo "  docker compose exec redis redis-cli"
    echo ""
}

# ============================================================================
# Main
# ============================================================================

main() {
    echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}Installation SambaEdu - Installation complète${NC}"
    echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
    echo ""

    # Phase 1: Vérifications
    log "Phase 1/6: Vérifications initiales..."
    echo ""

    check_existing_services
    check_bash
    check_docker
    check_docker_compose
    check_php
    check_composer

    npm_available=true
    if ! check_npm; then
        npm_available=false
    fi

    apache_available=true
    if ! check_apache; then
        apache_available=false
    fi

    echo ""
    log_success "Tous les prérequis sont OK"

    # Phase 2: Docker et configuration
    echo ""
    log "Phase 2/6: Configuration Docker et .env..."
    echo ""

    generate_env
    interactive_env_validation
    deploy_docker

    # Phase 3: Dépendances
    echo ""
    log "Phase 3/6: Installation des dépendances..."
    echo ""

    install_composer

    if [[ $npm_available == true ]]; then
        install_npm
    else
        log_warning "NPM non disponible - frontend non compilé"
    fi

    # Phase 4: Base de données
    echo ""
    log "Phase 4/6: Migration de la base de données..."
    echo ""

    run_migrations

    # Phase 5: Optimisation
    echo ""
    log "Phase 5/6: Optimisation applicative..."
    echo ""

    run_application_update

    # Phase 6: Apache
    echo ""
    log "Phase 6/6: Configuration Apache..."
    echo ""

    if [[ $apache_available == true ]]; then
        configure_apache
    else
        log_warning "Apache non disponible - configuration ignorée"
    fi

    # Résumé
    echo ""
    show_summary
}

main "$@"
