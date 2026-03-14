#!/bin/bash
# ============================================================================
# SambaEdu Installation Script
# Installation complète: Docker + .env + dépendances + migrations
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

TARGET_INSTALL_DIR="/var/www/sambaedu-reload"

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
# Vérification et déplacement vers le bon répertoire
# ============================================================================

relocate_if_needed() {
  if [[ "$APP_DIR" == "$TARGET_INSTALL_DIR" ]]; then
    return
  fi

  log_warning "Le projet se trouve dans: $APP_DIR"
  log_warning "Il doit être installé dans: $TARGET_INSTALL_DIR"
  echo ""

  read -p "Déplacer le projet vers $TARGET_INSTALL_DIR ? (y/n): " -n 1 -r
  echo ""

  if [[ $REPLY != "y" ]]; then
    log_warning "Déplacez manuellement le projet vers $TARGET_INSTALL_DIR puis relancez."
    exit 1
  fi

  mkdir -p "$(dirname "$TARGET_INSTALL_DIR")"

  if [[ -d "$TARGET_INSTALL_DIR" ]]; then
    log_warning "$TARGET_INSTALL_DIR existe déjà."
    read -p "Écraser le répertoire existant ? (y/n): " -n 1 -r
    echo ""
    if [[ $REPLY != "y" ]]; then
      log_error "Installation annulée."
      exit 1
    fi
    rm -rf "$TARGET_INSTALL_DIR"
  fi

  log "Déplacement du projet vers $TARGET_INSTALL_DIR..."
  mv "$APP_DIR" "$TARGET_INSTALL_DIR"
  log_success "Projet déplacé vers $TARGET_INSTALL_DIR"

  # Relancer le script depuis le nouveau chemin (les variables SCRIPT_DIR/APP_DIR
  # seraient fausses si on continuait — exec repart proprement depuis le bon endroit)
  local new_script="$TARGET_INSTALL_DIR/scripts/install.sh"
  log "Relancement depuis $new_script..."
  exec "$new_script" "$@"
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
    log "Tentative d'installation"
    sudo apt remove docker-buildx
    # Add Docker's official GPG key:
    sudo apt autoremove
    sudo apt update
    sudo apt install ca-certificates curl
    sudo install -m 0755 -d /etc/apt/keyrings
    sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
    sudo chmod a+r /etc/apt/keyrings/docker.asc

    # Add the repository to Apt sources:
    sudo tee /etc/apt/sources.list.d/docker.sources <<EOF
    Types: deb
    URIs: https://download.docker.com/linux/debian
    Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
    Components: stable
    Signed-By: /etc/apt/keyrings/docker.asc
    E# Add Docker's official GPG key:
    sudo apt update
    sudo apt install ca-certificates curl
    sudo install -m 0755 -d /etc/apt/keyrings
    sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
    sudo chmod a+r /etc/apt/keyrings/docker.asc
    
    # Add the repository to Apt sources:
    sudo tee /etc/apt/sources.list.d/docker.sources <<EOF
    Types: deb
    URIs: https://download.docker.com/linux/debian
    Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
    Components: stable
    Signed-By: /etc/apt/keyrings/docker.asc
    EOF

    sudo apt updateOF

    sudo apt update
    if ! sudo apt install -y docker-compose-plugin; then
      log_error "Échec de l'installation de docker-compose-plugin"
      exit 1
    fi
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

  # Vérification de l'extension pdo_pgsql
  log "Vérification de l'extension PHP pdo_pgsql..."
  if ! php -m 2>/dev/null | grep -q "pdo_pgsql"; then
    log_warning "Extension pdo_pgsql manquante — tentative d'installation..."
    local php_ver
    php_ver=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
    if command -v apt-get &>/dev/null; then
      apt-get install -y "php${php_ver}-pgsql" 2>/dev/null || apt-get install -y php-pgsql
    else
      log_error "Impossible d'installer pdo_pgsql automatiquement."
      log_error "Installez-le manuellement: sudo apt install php-pgsql"
      exit 1
    fi
    log_success "Extension pdo_pgsql installée"
  else
    log_success "Extension pdo_pgsql OK"
  fi
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
  log "Vérification NPM..."
  if ! command -v npm &>/dev/null; then
    log_warning "NPM n'est pas installé — tentative d'installation via NodeSource..."
    if command -v apt-get &>/dev/null; then
      curl -fsSL https://deb.nodesource.com/setup_lts.x | bash -
      apt-get install -y nodejs
      log_success "NPM installé: $(npm --version)"
    else
      log_error "Impossible d'installer NPM automatiquement."
      log_error "Installez-le manuellement: https://nodejs.org"
      return 1
    fi
  else
    log_success "NPM trouvé: $(npm --version)"
  fi
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
  # while [[ $attempt -lt $max_attempts ]]; do
  #     if docker compose exec -T redis redis-cli ping >/dev/null 2>&1; then
  #         log_success "Redis est prêt"
  #         break
  #     fi
  #     attempt=$((attempt + 1))
  #     sleep 1
  # done
  #
  # if [[ $attempt -eq $max_attempts ]]; then
  #     log_error "Redis n'a pas répondu dans le délai imparti"
  #     exit 1
  # fi
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
    echo "Listen 8080" >>/etc/apache2/ports.conf
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
# Permissions ACL
# ============================================================================

setup_acl_permissions() {
  log "Configuration des permissions ACL pour www-admin..."

  local target_dir="${1:-$APP_DIR}"

  if ! command -v setfacl &>/dev/null; then
    log_warning "setfacl non disponible — tentative d'installation de acl..."
    if command -v apt-get &>/dev/null; then
      apt-get install -y acl
    else
      log_warning "Impossible d'installer acl. Appliquez manuellement:"
      log_warning "  setfacl -R -m u:www-admin:rwX $target_dir/storage $target_dir/bootstrap/cache"
      log_warning "  setfacl -R -d -m u:www-admin:rwX $target_dir/storage $target_dir/bootstrap/cache"
      return
    fi
  fi

  setfacl -R -m u:www-admin:rwX "$target_dir/storage" "$target_dir/bootstrap/cache"
  setfacl -R -d -m u:www-admin:rwX "$target_dir/storage" "$target_dir/bootstrap/cache"

  log_success "ACL configurées: www-admin peut lire/écrire dans storage/ et bootstrap/cache/"
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

  # Phase 0: Emplacement du projet
  relocate_if_needed "$@"

  # Phase 1: Vérifications
  log "Phase 1/7: Vérifications initiales..."
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
  log "Phase 2/7: Configuration Docker et .env..."
  echo ""

  generate_env
  interactive_env_validation
  deploy_docker

  # Phase 3: Dépendances
  echo ""
  log "Phase 3/7: Installation des dépendances..."
  echo ""

  install_composer

  if [[ $npm_available == true ]]; then
    install_npm
  else
    log_warning "NPM non disponible - frontend non compilé"
  fi

  # Phase 4: Base de données
  echo ""
  log "Phase 4/7: Migration de la base de données..."
  echo ""

  run_migrations

  # Phase 5: Optimisation
  echo ""
  log "Phase 5/7: Optimisation applicative..."
  echo ""

  run_application_update

  # Phase 6: Apache
  echo ""
  log "Phase 6/7: Configuration Apache..."
  echo ""

  if [[ $apache_available == true ]]; then
    configure_apache
  else
    log_warning "Apache non disponible - configuration ignorée"
  fi

  # Phase 7: Permissions
  echo ""
  log "Phase 7/7: Configuration des permissions ACL..."
  echo ""

  setup_acl_permissions "$APP_DIR"

  # Résumé
  echo ""
  show_summary
}

main "$@"
