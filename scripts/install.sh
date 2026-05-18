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
    apt-get remove -y docker-buildx 2>/dev/null || true
    apt-get autoremove -y
    apt-get update
    apt-get install -y ca-certificates curl
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc

    # Add the repository to Apt sources:
    tee /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: stable
Signed-By: /etc/apt/keyrings/docker.asc
EOF

    apt-get update
    if ! apt-get install -y docker-compose-plugin; then
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

  # Pré-remplir APP_URL avec l'UAI depuis /etc/sambaedu/sambaedu.conf
  local uai=""
  if [[ -f "/etc/sambaedu/sambaedu.conf" ]]; then
    uai=$(grep -oP 'UAI\s*=\s*"\K[^"]+' /etc/sambaedu/sambaedu.conf 2>/dev/null || echo "")
  fi

  if [[ -n "$uai" ]]; then
    sed -i "s|^APP_URL=.*|APP_URL=https://URL_A_COMPLETER/${uai}|" "$APP_DIR/.env"
    log "UAI détecté : $uai"
    log_warning "APP_URL pré-remplie : https://URL_A_COMPLETER/${uai}"
  else
    sed -i "s|^APP_URL=.*|APP_URL=https://URL_A_COMPLETER|" "$APP_DIR/.env"
    log_warning "UAI non trouvé dans /etc/sambaedu/sambaedu.conf"
    log_warning "APP_URL pré-remplie : https://URL_A_COMPLETER"
  fi

  # Pré-remplir ESTABLISHMENT_NAME avec etab_name depuis /etc/sambaedu/sambaedu.conf
  local etab_name=""
  if [[ -f "/etc/sambaedu/sambaedu.conf" ]]; then
    etab_name=$(grep -oP 'etab_name\s*=\s*"\K[^"]+' /etc/sambaedu/sambaedu.conf 2>/dev/null || echo "")
  fi

  if [[ -n "$etab_name" ]]; then
    sed -i "s|^ESTABLISHMENT_NAME=.*|ESTABLISHMENT_NAME=\"${etab_name}\"|" "$APP_DIR/.env"
    log "Nom de l'établissement détecté : $etab_name"
  fi
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

  # Vérifier si APP_URL contient encore le placeholder
  local app_url
  app_url=$(grep -oP '^APP_URL=\K.*' "$APP_DIR/.env" 2>/dev/null || echo "")
  if [[ "$app_url" == *"URL_A_COMPLETER"* ]]; then
    echo ""
    echo -e "${RED}════════════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}  APP_URL doit être configurée avant de continuer !${NC}"
    echo -e "${RED}════════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "  Valeur actuelle : ${YELLOW}${app_url}${NC}"
    echo ""
    echo "  Remplacez URL_A_COMPLETER par le domaine réel."
    echo "  Exemple : https://se4fs-0991229y.lab1.irundo.fr/0991229y"
    echo ""
  fi

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
  mkdir -p bootstrap/cache
  composer install --no-dev --optimize-autoloader --no-interaction

  # S'assurer que vendor/ appartient à www-admin (groupe web)
  chown -R www-admin:www-admin "$APP_DIR/vendor"

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
  cd "$APP_DIR"

  local app_env
  app_env=$(php -r "require 'vendor/autoload.php'; (Dotenv\Dotenv::createImmutable(__DIR__))->safeLoad(); echo \$_ENV['APP_ENV'] ?? 'production';" 2>/dev/null)

  if [[ "$app_env" == "production" ]]; then
    log "APP_ENV=production → migrations sans seed ni drop (migrate --force)..."
    php artisan migrate --force
  else
    log "APP_ENV=$app_env → migrate:fresh --seed (DROP + seed)..."
    php artisan migrate:fresh --seed --force
  fi

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
# Initialisation PKI Auth V1 (Story 16.10)
# ============================================================================
# Génère, si absents, le CA root local + le cert serveur + la paire JWT
# RS256 utilisés par l'API `/api/v1/agent/*`. Commande idempotente :
# une réexécution est un no-op si les artefacts existent et que le cert
# serveur n'expire pas dans les 30 jours. À relancer après remplacement
# matériel ou rotation manuelle (`--force` régénère tout — option catastrophe).

init_auth_v1_pki() {
  log "Initialisation PKI Auth V1 (Story 16.10)..."
  cd "$APP_DIR"

  if ! php artisan list 2>/dev/null | grep -q 'auth:ca:init'; then
    log_warning "Commande auth:ca:init non disponible (Story 16.10 pas déployée) — étape ignorée"
    return 0
  fi

  if ! php artisan auth:ca:init --no-interaction; then
    log_error "Échec init PKI Auth V1 — vérifier storage/keys/ + extension OpenSSL"
    return 1
  fi

  log_success "PKI Auth V1 prête (CA root + cert serveur + paire JWT)"
}

# ============================================================================
# Configuration Apache
# ============================================================================

configure_apache() {
  local legacy_port=8082
  local sites_available="/etc/apache2/sites-available"
  local sites_enabled="/etc/apache2/sites-enabled"
  local ports_conf="/etc/apache2/ports.conf"

  log "Configuration Apache — SER comme vhost principal (port 80)..."

  # ── Activer les modules nécessaires ──
  a2enmod rewrite >/dev/null 2>&1 || true
  a2enmod headers >/dev/null 2>&1 || true
  a2enmod proxy_fcgi >/dev/null 2>&1 || true

  # ── Backup de la conf existante ──
  local backup_dir="/etc/apache2/backups-$(date +%Y%m%d_%H%M%S)"
  mkdir -p "$backup_dir"

  if [[ -f "$sites_enabled/sambaedu.conf" ]] || [[ -L "$sites_enabled/sambaedu.conf" ]]; then
    cp -L "$sites_enabled/sambaedu.conf" "$backup_dir/sambaedu.conf.backup"
  fi
  if [[ -f "$sites_available/sambaedu-reload.conf" ]]; then
    cp "$sites_available/sambaedu-reload.conf" "$backup_dir/sambaedu-reload.conf.backup"
  fi
  cp "$ports_conf" "$backup_dir/ports.conf.backup"
  log "Backup dans $backup_dir"

  # ── Récupérer ServerName/ServerAdmin depuis la conf existante ──
  local servername serveradmin preseed_network
  servername=$(grep -oP 'ServerName\s+\K\S+' "$sites_enabled/sambaedu.conf" 2>/dev/null || hostname)
  serveradmin=$(grep -oP 'ServerAdmin\s+\K\S+' "$sites_enabled/sambaedu.conf" 2>/dev/null || echo "webmaster@localhost")
  preseed_network=$(grep -oP 'Allow from \K\S+' "$sites_enabled/sambaedu.conf" 2>/dev/null | head -1)
  preseed_network="${preseed_network:-172.19.1.0}"

  # ── Vhost SER (port 80) ──
  cat > "$sites_available/sambaedu.conf" << VHOST_SER
<VirtualHost *:80>
    ServerAdmin $serveradmin
    ServerName $servername
    DocumentRoot $APP_DIR/public

    <FilesMatch "\.php\$">
        SetHandler "proxy:fcgi://127.0.0.1:9000/"
    </FilesMatch>

    <Directory $APP_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog /var/log/apache2/sambaedu-reload-error.log
    CustomLog /var/log/apache2/sambaedu-reload-access.log combined
</VirtualHost>
VHOST_SER

  # ── Vhost legacy (port 8082, localhost only) ──
  cat > "$sites_available/sambaedu-legacy.conf" << VHOST_LEGACY
# Vhost legacy — accès interne uniquement (proxy catchall SER)
# Généré par install.sh le $(date +%Y-%m-%d)
<VirtualHost 127.0.0.1:${legacy_port}>
    ServerAdmin $serveradmin
    DocumentRoot /var/www/sambaedu/

    <FilesMatch "\.php\$">
        SetHandler "proxy:fcgi://127.0.0.1:9000/"
    </FilesMatch>

    <Directory />
        Options +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    <Directory /var/www/sambaedu>
        Options -Indexes +FollowSymLinks +MultiViews +ExecCGI
        AllowOverride None
        Require all granted
    </Directory>

    <Directory /var/www/sambaedu/api2>
        AllowOverride All
    </Directory>

    <Directory /var/www/sambaedu/central>
        AllowOverride All
    </Directory>

    <Directory /var/www/sambaedu/setup>
        AllowOverride All
    </Directory>

    ScriptAlias /cgi-bin/ /usr/lib/cgi-binse/
    <Directory "/usr/lib/cgi-binse">
        AllowOverride None
        Options +ExecCGI -MultiViews +SymLinksIfOwnerMatch
        Require all granted
    </Directory>

    Alias /images /var/sambaedu/Docs/images
    <Directory /var/sambaedu/Docs/images>
        AllowOverride None
        Require all granted
    </Directory>

    Alias /os /var/sambaedu/unattended/install/os
    <Directory /var/sambaedu/unattended/install/os>
        AllowOverride None
        Require all granted
    </Directory>

    Alias /doc/ "/usr/share/doc/"
    <Directory "/usr/share/doc/">
        Options +Indexes +MultiViews +FollowSymLinks
        AllowOverride None
        Require host 127.0.0.0/255.0.0.0 ::1/128
    </Directory>

    <FilesMatch "diconf/.*\.preseed\$">
        Order deny,allow
        Deny from all
        Allow from ${preseed_network}
    </FilesMatch>

    ErrorLog /var/log/apache2/sambaedu-legacy-error.log
    CustomLog /var/log/apache2/sambaedu-legacy-access.log combined
</VirtualHost>
VHOST_LEGACY

  # ── ports.conf : nettoyer et ajouter le port legacy ──
  sed -i "/Legacy sambaedu/d" "$ports_conf"
  sed -i "/Listen.*${legacy_port}/d" "$ports_conf"
  sed -i "/Listen 8080/d" "$ports_conf"
  echo "" >> "$ports_conf"
  echo "# Legacy sambaedu — accès interne uniquement (proxy catchall SER)" >> "$ports_conf"
  echo "Listen 127.0.0.1:${legacy_port}" >> "$ports_conf"

  # ── Activer les sites ──
  if [ -L "$sites_enabled/sambaedu.conf" ]; then
    # Symlink → déjà à jour via l'écriture dans sites-available
    log "sambaedu.conf mis à jour (via symlink)"
  else
    cp "$sites_available/sambaedu.conf" "$sites_enabled/sambaedu.conf"
  fi
  ln -sf "$sites_available/sambaedu-legacy.conf" "$sites_enabled/sambaedu-legacy.conf"
  rm -f "$sites_enabled/sambaedu-reload.conf"

  # ── Mettre à jour .env ──
  if [[ -f "$APP_DIR/.env" ]]; then
    if grep -q "^LEGACY_BASE_URL=" "$APP_DIR/.env"; then
      sed -i "s|LEGACY_BASE_URL=.*|LEGACY_BASE_URL=http://127.0.0.1:${legacy_port}|" "$APP_DIR/.env"
    else
      echo "" >> "$APP_DIR/.env"
      echo "# URL interne du vhost legacy (proxy catchall)" >> "$APP_DIR/.env"
      echo "LEGACY_BASE_URL=http://127.0.0.1:${legacy_port}" >> "$APP_DIR/.env"
    fi
  fi

  # ── Vérifier et recharger ──
  if ! apache2ctl configtest 2>&1; then
    log_error "Configuration Apache invalide — restauration automatique..."
    cp "$backup_dir/sambaedu.conf.backup" "$sites_enabled/sambaedu.conf" 2>/dev/null || true
    cp "$backup_dir/ports.conf.backup" "$ports_conf"
    rm -f "$sites_enabled/sambaedu-legacy.conf"
    apache2ctl configtest 2>/dev/null && apache2ctl graceful
    log_error "Config restaurée. Vérifiez manuellement. Backups : $backup_dir"
    return 1
  fi

  apache2ctl graceful
  log_success "Apache configuré — SER sur port 80, legacy interne sur port $legacy_port"
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
# Queue workers systemd
# ============================================================================

install_queue_workers() {
  log "Installation des queue workers systemd..."

  local src="$APP_DIR/scripts/config"
  local dst="/etc/systemd/system"
  local services=(laravel-queue-sync.service laravel-queue-worker.service laravel-queue-general.service)
  local changed=false

  # Résoudre le binaire PHP absolu (évite /usr/bin/php8.2 hardcodé absent).
  local php_bin
  php_bin="$(command -v php || true)"
  if [[ -z "$php_bin" ]]; then
    log_error "Aucun binaire PHP trouvé dans le PATH"
    return 1
  fi
  # systemd n'exécute pas via PATH : il faut un chemin absolu et réel.
  php_bin="$(readlink -f "$php_bin")"
  log "  → PHP détecté : $php_bin"

  for svc in "${services[@]}"; do
    if [[ ! -f "$src/$svc" ]]; then
      log_error "Fichier source manquant: $src/$svc"
      return 1
    fi
    local rendered
    rendered="$(sed "s|__PHP_BIN__|${php_bin}|g" "$src/$svc")"
    if [[ ! -f "$dst/$svc" ]] || ! diff -q <(echo "$rendered") "$dst/$svc" >/dev/null 2>&1; then
      echo "$rendered" > "$dst/$svc"
      changed=true
      log "  → $svc installé/mis à jour"
    fi
  done

  if [[ "$changed" == "true" ]]; then
    systemctl daemon-reload
  fi

  local failed=()
  for svc in "${services[@]}"; do
    if ! systemctl enable "$svc" >/dev/null 2>&1; then
      log_error "  → $svc: enable a échoué"
      failed+=("$svc")
      continue
    fi
    if ! systemctl restart "$svc"; then
      log_error "  → $svc: restart a échoué"
      failed+=("$svc")
      continue
    fi
    # Laisser au service le temps de démarrer puis vérifier qu'il ne soit pas
    # retombé (ExecStart introuvable → exit 203 immédiat, mais systemd retourne 0).
    sleep 1
    if ! systemctl is-active --quiet "$svc"; then
      log_error "  → $svc: service inactif après restart (voir: journalctl -u $svc)"
      failed+=("$svc")
    fi
  done

  if (( ${#failed[@]} > 0 )); then
    log_error "Queue workers en échec : ${failed[*]}"
    return 1
  fi

  log_success "Queue workers actifs : ${services[*]}"
}

install_scheduler_cron() {
  log "Installation du cron Laravel scheduler..."

  local src="$APP_DIR/scripts/config/sambaedu-scheduler.cron"
  local dst="/etc/cron.d/sambaedu-scheduler"

  if [[ ! -f "$src" ]]; then
    log_error "Fichier source manquant: $src"
    return 1
  fi

  local php_bin
  php_bin="$(command -v php || true)"
  if [[ -z "$php_bin" ]]; then
    log_error "Aucun binaire PHP trouvé dans le PATH"
    return 1
  fi
  php_bin="$(readlink -f "$php_bin")"

  sed "s|__PHP_BIN__|${php_bin}|g" "$src" > "$dst"
  chown root:root "$dst"
  chmod 644 "$dst"
  log_success "Cron scheduler installé dans $dst (PHP: $php_bin)"

  # Nettoyage de l'ancien crontab utilisateur (ligne schedule:run dans www-admin)
  # pour éviter la double exécution.
  if crontab -u www-admin -l 2>/dev/null | grep -q "artisan schedule:run"; then
    crontab -u www-admin -l 2>/dev/null | grep -v "artisan schedule:run" | crontab -u www-admin -
    log "  → ancien cron schedule:run retiré du crontab www-admin"
  fi
}

# ============================================================================
# Vérification des pré-requis environnementaux (doctor)
# ============================================================================

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
  log "Phase 1/9: Vérifications initiales..."
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
  log "Phase 2/9: Configuration Docker et .env..."
  echo ""

  generate_env
  interactive_env_validation
  deploy_docker

  # Phase 3: Dépendances
  echo ""
  log "Phase 3/9: Installation des dépendances..."
  echo ""

  install_composer

  if [[ $npm_available == true ]]; then
    install_npm
  else
    log_warning "NPM non disponible - frontend non compilé"
  fi

  # Phase 4: Base de données
  echo ""
  log "Phase 4/9: Migration de la base de données..."
  echo ""

  run_migrations

  # Phase 5: Optimisation
  echo ""
  log "Phase 5/8: Optimisation applicative + PKI Auth V1..."
  echo ""

  run_application_update
  init_auth_v1_pki

  # Phase 6: Apache
  echo ""
  log "Phase 6/9: Configuration Apache..."
  echo ""

  if [[ $apache_available == true ]]; then
    configure_apache
  else
    log_warning "Apache non disponible - configuration ignorée"
  fi

  # Phase 7: Permissions
  echo ""
  log "Phase 7/9: Configuration des permissions ACL..."
  echo ""

  setup_acl_permissions "$APP_DIR"

  # Phase 8: Queue workers
  echo ""
  log "Phase 8/9: Installation des queue workers systemd..."
  echo ""

  install_queue_workers
  install_scheduler_cron

  # Phase 9: Doctor (vérification post-install)
  echo ""
  log "Phase 9/9: Vérification des pré-requis environnementaux..."
  echo ""

  run_doctor_check

  # Résumé
  echo ""
  show_summary
}

main "$@"
