#!/bin/bash
# ============================================================================
# SambaEdu Installation Script
# Installation complète: Docker + .env + dépendances + migrations
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

TARGET_INSTALL_DIR="/var/www/sambaedu-reload"

# Composer tourne en root pendant l'install (VM/serveur dédié) : on supprime
# le prompt « Continue as root/super user [yes]? » sans interaction.
export COMPOSER_ALLOW_SUPERUSER=1

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

  # APP_URL : create-env.sh la dérive de se4_url (/etc/sambaedu/sambaedu.conf).
  # On ne pré-remplit le placeholder URL_A_COMPLETER que si elle est restée VIDE
  # (pas de se4_url) — sinon on conserve la valeur dérivée. Le placeholder reste
  # le garde-fou « à compléter » pour les déploiements proxifiés où l'URL
  # publique réelle (reverse-proxy lab1/controlHub) diffère du se4_url interne.
  local current_app_url uai=""
  current_app_url=$(grep -oP '^APP_URL=\K.*' "$APP_DIR/.env" 2>/dev/null || echo "")

  if [[ -n "$current_app_url" ]]; then
    log "APP_URL déjà dérivée par create-env.sh : $current_app_url (conservée)"
    log_warning "Déploiement derrière un reverse-proxy ? Remplacez APP_URL par l'URL publique réelle."
  else
    if [[ -f "/etc/sambaedu/sambaedu.conf" ]]; then
      uai=$(grep -oP 'UAI\s*=\s*"\K[^"]+' /etc/sambaedu/sambaedu.conf 2>/dev/null || echo "")
    fi
    if [[ -n "$uai" ]]; then
      sed -i "s|^APP_URL=.*|APP_URL=https://URL_A_COMPLETER/${uai}|" "$APP_DIR/.env"
      log "UAI détecté : $uai"
      log_warning "APP_URL pré-remplie : https://URL_A_COMPLETER/${uai}"
    else
      sed -i "s|^APP_URL=.*|APP_URL=https://URL_A_COMPLETER|" "$APP_DIR/.env"
      log_warning "se4_url/UAI absents — APP_URL pré-remplie : https://URL_A_COMPLETER"
    fi
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

  # Le hook post-autoload-dump de composer lance `artisan package:discover`,
  # qui boote le framework et instancie le compilateur Blade avec le chemin
  # config('view.compiled') = realpath(storage_path('framework/views')).
  # realpath() renvoie false si le dossier n'existe pas → « Please provide a
  # valid cache path. ». Sur un déploiement from-scratch (tarball / sync
  # inotify qui ne crée pas les arborescences vides .gitignore-only), ces
  # dossiers runtime peuvent manquer. On les crée AVANT composer install.
  mkdir -p \
    bootstrap/cache \
    storage/framework/views \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/logs \
    storage/app/public

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
# Registre d'extensions — catalogue embarqué (Epic 54)
# ============================================================================
# Enregistre les manifests livrés avec le code (`resources/extensions/*`) dans
# le registre : la source `bundled` et ses extensions.
#
# ⚠️ POURQUOI CE N'EST PAS COUVERT PAR run_migrations : en `APP_ENV=production`,
# `run_migrations` fait `migrate --force` SANS seed (seul le chemin non-prod
# passe par `migrate:fresh --seed`). Sur une installation de production, le
# registre resterait donc VIDE et le lanceur « gaufre » n'afficherait jamais
# rien — sans aucune erreur pour le signaler.
#
# Le seeder est IDEMPOTENT (re-jouable sans effet de bord) : il ne duplique
# rien, et le `status = integrated` d'une extension déjà intégrée par
# l'administrateur SURVIT à une réexécution. Il est donc appelé
# inconditionnellement, y compris quand `migrate:fresh --seed` vient déjà de
# le jouer.

seed_bundled_extensions() {
  log "Enregistrement du catalogue d'extensions embarqué (Epic 54)..."
  cd "$APP_DIR"

  if [[ ! -f "$APP_DIR/database/seeders/BundledExtensionSeeder.php" ]]; then
    log_warning "BundledExtensionSeeder absent (Epic 54 pas déployé) — étape ignorée"
    return 0
  fi

  if ! php artisan db:seed --class=BundledExtensionSeeder --force; then
    log_error "Échec du seed des extensions embarquées — le lanceur restera vide"
    return 1
  fi

  log_success "Catalogue d'extensions embarqué enregistré"
}

# ============================================================================
# Fournisseur OIDC — paire de clés de signature (Epic 55)
# ============================================================================
# Génère, si absente, la paire RS256 DÉDIÉE qui signe les `id_token` servis aux
# extensions (`storage/keys/oidc/`), et publie la clé publique au JWKS.
#
# ⚠️ Paire DISTINCTE de celle d'Auth V1 : le JWKS OIDC est un endpoint PUBLIC,
# la clé « workstation » n'est publiée nulle part. Ne jamais les confondre.
#
# Sans cette étape, `/oidc/authorize` et `/oidc/token` échouent en
# `oidc.keys_unavailable` et le JWKS répond 503 (fail-closed volontaire : mieux
# vaut un refus franc qu'un `{"keys":[]}` en 200 que les clients mettraient en
# cache). La commande est IDEMPOTENTE : no-op si la paire existe déjà, elle ne
# régénère JAMAIS en silence — une rotation exige `--force`, qui invaliderait
# tous les jetons en circulation.

init_oidc_provider_keys() {
  log "Initialisation des clés du fournisseur OIDC (Epic 55)..."
  cd "$APP_DIR"

  if ! php artisan list 2>/dev/null | grep -q 'oidc:keys:init'; then
    log_warning "Commande oidc:keys:init non disponible (Epic 55 pas déployé) — étape ignorée"
    return 0
  fi

  if ! php artisan oidc:keys:init --no-interaction; then
    log_error "Échec init des clés OIDC — vérifier storage/keys/ + extension OpenSSL"
    return 1
  fi

  log_success "Fournisseur OIDC prêt (paire RS256 + JWKS)"
}

# ============================================================================
# Configuration Apache
# ============================================================================

configure_apache() {
  # Source unique de verite = setupApache.sh : il genere le vhost SER (port 80),
  # le vhost legacy (port 8082, localhost-only), met a jour ports.conf et le
  # .env (LEGACY_BASE_URL), active les modules Apache requis, et appelle
  # setupXsendfile.sh pour le service natif des assets OS iPXE (X-Sendfile).
  # install.sh n'a donc plus aucun heredoc Apache a maintenir en double.
  log "Configuration Apache — delegation a setupApache.sh (source unique)..."

  if bash "$SCRIPT_DIR/setupApache.sh"; then
    log_success "Apache configure (vhost SER + legacy + X-Sendfile) via setupApache.sh"
  else
    log_error "setupApache.sh a echoue — voir la sortie ci-dessus (backups dans /etc/apache2/backups-*)."
    return 1
  fi
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
# Dépendances système du pipeline iPXE / install Windows (Story 3.10)
# ============================================================================

# Installe les binaires requis par l'injection de pilotes NIC dans le boot.wim
# WinPE (Story 3.10) et prépare le pack de pilotes persistant :
#   - wimtools     → fournit `wimlib-imagex` (injection dans le boot.wim)
#   - innoextract  → extraction des `.exe` InnoSetup Lenovo (7z ne suffit pas)
#   - unzip        → extraction des `.zip` Intel
# Le pack `storage/install/winpe-drivers/` est créé (vide = no-op à l'injection,
# zéro régression pour les parcs à NIC inbox) et possédé par www-admin.
install_ipxe_winpe_deps() {
  log "Installation des dépendances iPXE/WinPE (injection pilotes NIC — Story 3.10)..."

  local target_dir="${1:-$APP_DIR}"
  local packages=(wimtools innoextract unzip)

  if command -v apt-get &>/dev/null; then
    # Best-effort : on n'échoue pas l'install globale si un paquet manque dans
    # les dépôts (l'injection est no-op sans pack, et le message reste clair).
    if apt-get install -y "${packages[@]}"; then
      log_success "Paquets installés: ${packages[*]}"
    else
      log_warning "Échec d'installation d'au moins un paquet (${packages[*]})."
      log_warning "Installez manuellement: sudo apt-get install -y ${packages[*]}"
    fi
  else
    log_warning "apt-get indisponible — installez manuellement: ${packages[*]}"
  fi

  # Vérification des binaires effectivement disponibles.
  local bin missing=()
  for bin in wimlib-imagex innoextract unzip; do
    command -v "$bin" &>/dev/null || missing+=("$bin")
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    log_warning "Binaires manquants après installation: ${missing[*]} — l'ingestion/injection de pilotes WinPE échouera tant qu'ils ne sont pas présents."
  fi

  # Pack de pilotes persistant (hors arbre extrait — survit aux ré-extractions
  # d'ISO). Vide par défaut = injection no-op.
  local pack_dir="$target_dir/storage/install/winpe-drivers"
  if mkdir -p "$pack_dir"; then
    if id www-admin &>/dev/null; then
      chown -R www-admin:www-admin "$pack_dir" 2>/dev/null || \
        log_warning "chown www-admin sur $pack_dir échoué (vérifier les droits)."
    fi
    log_success "Pack de pilotes WinPE prêt: $pack_dir (vide = no-op à l'injection)"
  else
    log_warning "Création de $pack_dir échouée — déposez-y les familles de pilotes manuellement."
  fi
}

# ============================================================================
# spice-guest-tools — confort VM, DEV UNIQUEMENT
# ============================================================================

# Dépose spice-guest-tools sous la racine `os_assets`, d'où la route
# `/ipxe/os/{path}` (LAN-only, sans JWT) le sert aux postes en cours d'install.
# L'`Order 4` du template unattend le récupère à cette URL — mais uniquement si
# le SMBIOS de la machine annonce QEMU/KVM. Un parc physique ne le télécharge
# jamais : le garde sort avant toute requête réseau.
#
# DEV UNIQUEMENT (`APP_ENV != production`), pour deux raisons : en production il
# n'y a pas de VM au parc, et on ne veut pas d'un curl sortant vers Internet
# pendant l'install serveur.
#
# NOTE — un banc de dev qui déclare `APP_ENV=production` ne recevra rien, et le
# symptôme sera un silence (pas une erreur). Positionner `APP_ENV` en
# conséquence sur la VM de développement.
#
# Best-effort de bout en bout : sans ce binaire, l'`Order 4` écrit `erreur ...`
# dans `c:\netinst\spice-guest-tools.log` et l'installation Windows se poursuit
# normalement (try/catch + exit 0). Rien ici ne doit faire échouer install.sh.
install_spice_guest_tools() {
  local target_dir="${1:-$APP_DIR}"
  local url="https://www.spice-space.org/download/windows/spice-guest-tools/spice-guest-tools-latest.exe"

  cd "$target_dir" 2>/dev/null || return 0

  # Même lecture d'APP_ENV que run_migrations (Dotenv, tolère quotes/absence).
  local app_env
  app_env=$(php -r "require 'vendor/autoload.php'; (Dotenv\Dotenv::createImmutable(__DIR__))->safeLoad(); echo \$_ENV['APP_ENV'] ?? 'production';" 2>/dev/null)

  if [[ "$app_env" == "production" ]]; then
    log "APP_ENV=production → spice-guest-tools ignoré (confort VM, dev uniquement)."
    return 0
  fi

  # Racine servie par IpxeOsAssetController — même défaut que config/ipxe.php.
  local os_root
  os_root=$(php -r "require 'vendor/autoload.php'; (Dotenv\Dotenv::createImmutable(__DIR__))->safeLoad(); echo \$_ENV['IPXE_OS_ASSETS_ROOT'] ?? '';" 2>/dev/null)
  os_root="${os_root:-/var/sambaedu/unattended/install/os}"

  local dest_dir="$os_root/tools"
  local dest="$dest_dir/spice-guest-tools.exe"

  # Idempotent : ~10 Mo, inutile de le retélécharger à chaque run d'install.sh.
  if [[ -s "$dest" ]]; then
    log_success "spice-guest-tools déjà présent: $dest"
    return 0
  fi

  if ! command -v curl &>/dev/null; then
    log_warning "curl indisponible — spice-guest-tools non déployé."
    return 0
  fi

  if ! mkdir -p "$dest_dir" 2>/dev/null; then
    log_warning "Création de $dest_dir échouée — spice-guest-tools non déployé."
    return 0
  fi

  # Téléchargement vers un temporaire puis pose atomique : un transfert coupé ne
  # doit JAMAIS laisser un .exe tronqué à l'emplacement servi (le poste
  # l'exécuterait et échouerait de façon opaque).
  local tmp
  tmp=$(mktemp "${TMPDIR:-/tmp}/spice-guest-tools.XXXXXX.exe" 2>/dev/null) || return 0

  log "Téléchargement de spice-guest-tools (confort VM, dev)..."
  if ! curl -fsSL --connect-timeout 10 --max-time 300 -o "$tmp" "$url"; then
    log_warning "Téléchargement de spice-guest-tools échoué ($url) — non bloquant."
    rm -f "$tmp"
    return 0
  fi

  # Garde-fou : une page d'erreur HTML ou une redirection captive ferait
  # quelques Ko et passerait le `curl -f`. Le binaire réel pèse ~10 Mo.
  local size
  size=$(stat -c %s "$tmp" 2>/dev/null || echo 0)
  if [[ "$size" -lt 1000000 ]]; then
    log_warning "spice-guest-tools suspect (${size} octets, attendu ~10 Mo) — abandonné."
    rm -f "$tmp"
    return 0
  fi

  # 644 + www-admin : aligné sur ce que pose WindowsIsoExtractor pour Win11/ et
  # winpe/. Apache (X-Sendfile) doit pouvoir lire le fichier.
  local owner_args=()
  if id www-admin &>/dev/null; then
    owner_args=(-o www-admin -g www-admin)
  fi

  if install "${owner_args[@]}" -m 644 "$tmp" "$dest" 2>/dev/null; then
    log_success "spice-guest-tools déployé: $dest ($((size / 1024 / 1024)) Mo)"
  else
    log_warning "Pose de $dest échouée — spice-guest-tools non déployé."
  fi
  rm -f "$tmp"
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
# Story 38.5 — cron système SE5 (lignes vitales re-possédées) + retrait legacy
# ============================================================================
# install_system_cron provisionne /etc/cron.d/sambaedu-system (renew_ticket ×2 dont
# @reboot + smbstatus). DOIT s'exécuter AVANT retire_legacy_crons — zéro fenêtre sans
# ticket Kerberos www-sambaedu (l'écriture SYSVOL en dépend, Story 38.4).

install_system_cron() {
  log "Installation du cron système SambaEdu (renew_ticket, smbstatus)..."

  local src="$APP_DIR/scripts/config/sambaedu-system.cron"
  local dst="/etc/cron.d/sambaedu-system"

  if [[ ! -f "$src" ]]; then
    log_error "Fichier source manquant: $src"
    return 1
  fi

  # Pas de rendu (lignes statiques) : copie directe.
  cp "$src" "$dst"
  chown root:root "$dst"
  chmod 644 "$dst"
  log_success "Cron système installé dans $dst"
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
# Release agent stable initiale (Epic 25)
# ============================================================================
# update.sh (ensure_agent_build) BUILDE le binaire agent signé mais ne PUBLIE
# jamais de release (un update serveur ne pousse rien au parc). Conséquence sur
# un déploiement from-scratch : agent_releases vide → /api/v1/agent/stable
# répond `no_release`, et un poste qui s'amorce via la GPO/iPXE bootstrap n'a
# AUCUN binaire à télécharger. On comble ce trou ici : si — et seulement si —
# aucune release stable n'existe encore, on build+publie la dernière version en
# stable (`build-agent.sh --stable`). Idempotent : sur un parc déjà pourvu d'une
# stable, c'est un no-op (on ne rétrograde jamais la stable en place).
# NON-FATAL : un échec (PFX absent, build KO) n'interrompt pas l'install.

ensure_agent_stable_release() {
  log "Vérification d'une release agent stable publiée..."
  cd "$APP_DIR"

  local build_script="$SCRIPT_DIR/build-agent.sh"
  if [[ ! -f "$build_script" ]]; then
    log_warning "build-agent.sh absent — étape ignorée"
    return 0
  fi

  # La table agent_releases peut être absente (Epic 25 pas déployé) ou vide
  # (install neuve). On ne publie que s'il n'existe AUCUNE stable.
  local has_stable
  has_stable="$(php artisan tinker --execute \
    "echo \\Schema::hasTable('agent_releases') && \\App\\Models\\AgentRelease::where('is_stable', true)->exists() ? '1' : '0';" \
    2>/dev/null | tail -1 | tr -d '[:space:]')"

  if [[ "$has_stable" == "1" ]]; then
    log_success "Release agent stable déjà publiée — rien à faire"
    return 0
  fi

  log_warning "Aucune release agent stable — build + publication de la dernière version (--stable)..."
  if bash "$build_script" --stable; then
    log_success "Release agent stable publiée (storage/agent/releases/ + manifeste agent_releases)"
  else
    log_warning "Build/publication stable échouée (relancer : scripts/build-agent.sh --stable) — l'install continue"
  fi
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

  # Dépendances système du pipeline iPXE/WinPE (injection pilotes NIC — 3.10).
  install_ipxe_winpe_deps "$APP_DIR"

  # spice-guest-tools servi aux VM en cours d'install (no-op si APP_ENV=production).
  install_spice_guest_tools "$APP_DIR"

  # Phase 4: Base de données
  echo ""
  log "Phase 4/9: Migration de la base de données..."
  echo ""

  run_migrations

  # Phase 5: Optimisation
  echo ""
  log "Phase 5/8: Optimisation applicative + PKI Auth V1 + extensions/OIDC..."
  echo ""

  run_application_update
  init_auth_v1_pki
  seed_bundled_extensions
  init_oidc_provider_keys

  # Phase 6: Apache
  echo ""
  log "Phase 6/9: Configuration Apache..."
  echo ""

  if [[ $apache_available == true ]]; then
    # Délègue tout à setupApache.sh (vhost SER + legacy + ports + .env +
    # modules + setupXsendfile.sh). Plus d'appel séparé à maintenir ici.
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
  # Story 38.5 : provisionne sambaedu-system (renew_ticket/smbstatus) AVANT tout
  # retrait des crons legacy. Le retrait effectif (ensure_legacy_crons_retired)
  # est joué par le replay update.sh en fin d'install (cf. « Finalisation »
  # ci-dessous) — une machine greenfield avec paquets legacy résiduels ne relance
  # donc pas les crons web legacy.
  install_system_cron

  # Phase 9: Doctor (vérification post-install)
  echo ""
  log "Phase 9/9: Vérification des pré-requis environnementaux..."
  echo ""

  run_doctor_check

  # Résumé
  echo ""
  show_summary

  # ── Finalisation : rejouer update.sh (idempotent) ──
  # install.sh ne couvre pas toutes les étapes « ensure » (notamment la bascule
  # du PXE bootstrap vers la route native /ipxe/boot via
  # ensure_ipxe_bootstrap_native, le bundle WPKG, et le déploiement de la GPO
  # bootstrap agent `ensure_agent_bootstrap_gpo` — Story 27.16). Plutôt que de
  # dupliquer ces étapes ici (et risquer la dérive), on rejoue update.sh en fin
  # d'install : il est idempotent (composer, migrations, apache, systemd, ipxe
  # bootstrap, GPO bootstrap agent, doctor) et garantit qu'un déploiement
  # from-scratch finit dans le même état qu'un parc à jour.
  # NB : pas de forward des args d'install (update.sh a son propre parse_args).
  echo ""
  log "Finalisation : exécution de update.sh (idempotent) pour appliquer les étapes 'ensure'..."
  echo ""
  if bash "$SCRIPT_DIR/update.sh"; then
    log_success "update.sh terminé — déploiement finalisé (PXE bootstrap natif inclus)."
  else
    log_warning "update.sh a retourné une erreur — l'install de base est faite, mais des étapes 'ensure' ont pu échouer (dont la bascule PXE /ipxe/boot). Vérifier la sortie ci-dessus."
  fi

  # ── Release agent stable initiale ──
  # update.sh a buildé le binaire signé + émis le PFX, mais ne publie aucune
  # stable. Sur une install neuve, on publie la dernière version en stable pour
  # qu'un poste s'amorçant via la GPO/iPXE bootstrap trouve un binaire (sinon
  # /api/v1/agent/stable → no_release). No-op si une stable existe déjà.
  echo ""
  ensure_agent_stable_release
}

main "$@"
