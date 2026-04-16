#!/bin/bash

# Script de mise à jour Laravel après git pull
# Usage: ./update.sh [--full] [--sync]
#   --full : Force la réinstallation complète des dépendances
#   --sync : Synchronise vers /var/www/sambaedu/laravel après la mise à jour

set -e

# Couleurs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_status() { echo -e "${BLUE}[INFO]${NC} $1"; }
print_success() { echo -e "${GREEN}[OK]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
print_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Options
FULL_UPDATE=false
SYNC_PROD=false

for arg in "$@"; do
    case $arg in
        --full) FULL_UPDATE=true ;;
        --sync) SYNC_PROD=true ;;
    esac
done

echo ""
echo "🔄 Mise à jour Laravel SE4FS"
echo "=============================="

# Vérifier le répertoire
if [ ! -f "artisan" ]; then
    print_error "Exécutez ce script depuis le répertoire Laravel"
    exit 1
fi

# 1. Vérifier les changements dans les fichiers de dépendances
COMPOSER_CHANGED=false
NPM_CHANGED=false

if git diff --name-only HEAD@{1} HEAD 2>/dev/null | grep -q "composer.lock"; then
    COMPOSER_CHANGED=true
fi

if git diff --name-only HEAD@{1} HEAD 2>/dev/null | grep -q "package-lock.json\|package.json"; then
    NPM_CHANGED=true
fi

# 2. Mise à jour Composer
if [ "$FULL_UPDATE" = true ] || [ "$COMPOSER_CHANGED" = true ]; then
    print_status "Installation des dépendances Composer..."
    composer install --no-dev --optimize-autoloader --no-interaction
    print_success "Composer mis à jour"
else
    print_status "Composer: pas de changements détectés (--full pour forcer)"
fi

# 3. Mise à jour NPM
if [ "$FULL_UPDATE" = true ] || [ "$NPM_CHANGED" = true ]; then
    print_status "Installation des dépendances npm..."
    npm ci --silent 2>/dev/null || npm install
    print_success "npm mis à jour"
else
    print_status "npm: pas de changements détectés (--full pour forcer)"
fi

# 4. Migrations base de données
print_status "Vérification des migrations..."
if php artisan migrate:status 2>/dev/null | grep -q "Pending"; then
    print_warning "Migrations en attente détectées"
    read -p "Exécuter les migrations ? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        php artisan migrate --force
        print_success "Migrations exécutées"
    fi
else
    print_success "Migrations: à jour"
fi

# 5. Nettoyage des caches
print_status "Nettoyage des caches..."
php artisan cache:clear 2>/dev/null || true
php artisan config:clear
php artisan view:clear
php artisan route:clear
php artisan event:clear 2>/dev/null || true
print_success "Caches nettoyés"

# 6. Régénération des caches optimisés (production)
print_status "Optimisation pour la production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
print_success "Caches optimisés"

# 7. Compilation des assets
print_status "Compilation des assets..."
npm run build
print_success "Assets compilés"

# 8. Publication des assets Livewire (si nécessaire)
if [ -d "vendor/livewire" ]; then
    print_status "Publication des assets Livewire..."
    php artisan vendor:publish --tag=livewire:assets --force 2>/dev/null || true
    print_success "Assets Livewire publiés"
fi

# 9. Permissions
# print_status "Correction des permissions..."
# if [ -d "storage" ]; then
#     sudo chown -R www-admin:www-admin storage bootstrap/cache 2>/dev/null || true
#     sudo chmod -R 775 storage bootstrap/cache 2>/dev/null || true
# fi
# print_success "Permissions corrigées"

# 10. Synchronisation vers production (optionnel)
# if [ "$SYNC_PROD" = true ]; then
#     PROD_PATH="/var/www/sambaedu/laravel"
#     if [ -d "$PROD_PATH" ]; then
#         print_status "Synchronisation vers $PROD_PATH..."
#         sudo rsync -av --delete \
#             --exclude='.env' \
#             --exclude='storage/logs/*' \
#             --exclude='storage/framework/cache/*' \
#             --exclude='storage/framework/sessions/*' \
#             --exclude='storage/framework/views/*' \
#             --exclude='.git' \
#             --exclude='node_modules' \
#             ./ "$PROD_PATH/"
        
#         # Permissions sur prod
#         sudo chown -R www-data:www-data "$PROD_PATH/storage" "$PROD_PATH/bootstrap/cache"
#         sudo chmod -R 775 "$PROD_PATH/storage" "$PROD_PATH/bootstrap/cache"
        
#         print_success "Synchronisation terminée"
#     else
#         print_warning "Répertoire production non trouvé: $PROD_PATH"
#     fi
# fi

# 11. Redémarrage des services (optionnel)
print_status "Redémarrage PHP-FPM..."
sudo systemctl reload php8.2-fpm 2>/dev/null || sudo systemctl reload php8.1-fpm 2>/dev/null || sudo systemctl reload php-fpm 2>/dev/null || true

# 12. Queue workers systemd
# Si les unit files ont changé → daemon-reload + hard restart.
# Sinon → soft restart (queue:restart) pour que les workers reprennent avec le nouveau code
#        après leur job courant.
print_status "Vérification des queue workers systemd..."
SERVICES_CHANGED=false
for svc in laravel-queue-sync.service laravel-queue-worker.service laravel-queue-general.service; do
    if ! sudo cmp -s "scripts/config/$svc" "/etc/systemd/system/$svc" 2>/dev/null; then
        sudo cp "scripts/config/$svc" "/etc/systemd/system/$svc"
        SERVICES_CHANGED=true
        print_status "  → $svc mis à jour"
    fi
done

if [ "$SERVICES_CHANGED" = true ]; then
    sudo systemctl daemon-reload
    sudo systemctl restart laravel-queue-sync laravel-queue-worker laravel-queue-general
    print_success "Queue workers redémarrés (hard) avec la nouvelle config systemd"
else
    php artisan queue:restart
    print_success "Queue workers notifiés (soft restart — fin propre du job courant)"
fi

# Résumé
echo ""
echo "=============================="
print_success "✅ Mise à jour terminée !"
echo ""
echo "Options utilisées:"
[ "$FULL_UPDATE" = true ] && echo "  --full : Réinstallation complète"
[ "$SYNC_PROD" = true ] && echo "  --sync : Synchronisation production"
echo ""
print_status "Commandes utiles:"
echo "  ./update.sh --full      # Réinstaller toutes les dépendances"
echo "  ./update.sh --sync      # Synchroniser vers /var/www/sambaedu/laravel"
echo "  ./update.sh --full --sync  # Les deux"
echo ""
