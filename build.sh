#!/bin/bash

# Script de build et initialisation Laravel avec Daisy UI
# Auteur: Assistant IA
# Description: Installe les dépendances, configure et compile les assets

set -e  # Arrêter le script en cas d'erreur

echo "🚀 Initialisation du projet Laravel avec Daisy UI..."
echo "=============================================="

# Couleurs pour les messages
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Fonction pour afficher les messages colorés
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Vérifier que nous sommes dans le bon répertoire
if [ ! -f "artisan" ]; then
    print_error "Ce script doit être exécuté depuis le répertoire Laravel (où se trouve le fichier artisan)"
    exit 1
fi

print_status "Répertoire Laravel détecté ✅"

# 1. Installation des dépendances Composer
print_status "Installation des dépendances PHP (Composer)..."
if command -v composer &> /dev/null; then
    composer install --no-dev --optimize-autoloader
    print_success "Dépendances Composer installées"
else
    print_warning "Composer non trouvé, passage à l'étape suivante"
fi

# 2. Vérification/Génération de la clé d'application
print_status "Vérification de la clé d'application..."
if grep -q "APP_KEY=base64:" .env 2>/dev/null; then
    print_success "Clé d'application déjà configurée"
else
    print_warning "Génération d'une nouvelle clé d'application..."
    php artisan key:generate
fi

# 3. Installation des dépendances Node.js
print_status "Installation des dépendances JavaScript (npm)..."
if command -v npm &> /dev/null; then
    npm install
    print_success "Dépendances npm installées"
else
    print_error "npm non trouvé. Veuillez installer Node.js"
    exit 1
fi

# 4. Vérification de la configuration Tailwind et Daisy UI
print_status "Vérification de la configuration Tailwind CSS et Daisy UI..."

# Vérifier si tailwind.config.js existe
if [ ! -f "tailwind.config.js" ]; then
    print_warning "Configuration Tailwind manquante, création..."
    cat > tailwind.config.js << 'EOF'
/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
    "./app/View/Components/**/*.php",
    "./app/Livewire/**/*.php",
    "./storage/framework/views/*.php",
  ],
  theme: {
    extend: {},
  },
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
    require('daisyui'),
  ],
  daisyui: {
    themes: [
      "light",
      "dark",
      "cupcake",
      "bumblebee",
      "emerald",
      "corporate",
      "synthwave",
      "retro",
      "cyberpunk",
      "valentine",
      "halloween",
      "garden",
      "forest",
      "aqua",
      "lofi",
      "pastel",
      "fantasy",
      "wireframe",
      "black",
      "luxury",
      "dracula",
      "cmyk",
      "autumn",
      "business",
      "acid",
      "lemonade",
      "night",
      "coffee",
      "winter",
    ],
    darkTheme: "dark",
    base: true,
    styled: true,
    utils: true,
    rtl: false,
    prefix: "",
    logs: true,
  },
}
EOF
    print_success "Configuration Tailwind créée"
fi

# Vérifier si postcss.config.js existe
if [ ! -f "postcss.config.js" ]; then
    print_warning "Configuration PostCSS manquante, création..."
    cat > postcss.config.js << 'EOF'
export default {
  plugins: {
    '@tailwindcss/postcss': {},
    autoprefixer: {},
  },
}
EOF
    print_success "Configuration PostCSS créée"
fi

# Vérifier si app.css contient les directives Tailwind v4
print_status "Vérification du fichier CSS principal..."
if [ -f "resources/css/app.css" ]; then
    if ! grep -q "@import \"tailwindcss\"" resources/css/app.css; then
        print_warning "Mise à jour du fichier CSS avec la syntaxe Tailwind v4..."
        cat > resources/css/app.css << 'EOF'
@import "tailwindcss";

@source "../**/*.blade.php";
@source "../**/*.js";
@source "../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php";
@source "../../storage/framework/views/*.php";

@plugin "daisyui";
EOF
        print_success "Fichier CSS mis à jour"
    else
        print_success "Fichier CSS déjà configuré"
    fi
else
    print_warning "Création du fichier CSS principal..."
    mkdir -p resources/css
    cat > resources/css/app.css << 'EOF'
@import "tailwindcss";

@source "../**/*.blade.php";
@source "../**/*.js";
@source "../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php";
@source "../../storage/framework/views/*.php";

@plugin "daisyui";
EOF
    print_success "Fichier CSS créé"
fi

# 5. Nettoyage des caches Laravel
print_status "Nettoyage des caches Laravel..."
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear
print_success "Caches nettoyés"

# 6. Correction des permissions storage
print_status "Correction des permissions du dossier storage..."
if [ -d "storage" ]; then
    sudo chown -R www-data:www-data storage
    sudo chmod -R 775 storage
    print_success "Permissions storage corrigées"
fi

# 7. Correction des permissions bootstrap/cache
print_status "Correction des permissions du cache bootstrap..."
if [ -d "bootstrap/cache" ]; then
    sudo chown -R www-data:www-data bootstrap/cache
    sudo chmod -R 775 bootstrap/cache
    print_success "Permissions bootstrap/cache corrigées"
fi

# 8. Arrêt du serveur Vite s'il est en cours d'exécution
print_status "Vérification du serveur Vite..."
if pgrep -f "vite" > /dev/null; then
    print_warning "Arrêt du serveur Vite en cours d'exécution..."
    pkill -f vite || true
    sleep 2
    print_success "Serveur Vite arrêté"
fi

# 9. Compilation des assets
print_status "Compilation des assets pour la production..."
npm run build

# Vérifier si la compilation a réussi
if [ $? -eq 0 ]; then
    print_success "Assets compilés avec succès !"
    
    # Afficher les fichiers générés
    print_status "Fichiers générés dans public/build/:"
    ls -la public/build/ 2>/dev/null || print_warning "Dossier public/build non trouvé"
else
    print_error "Erreur lors de la compilation des assets"
    exit 1
fi

# 10. Vérification finale
print_status "Vérification finale..."

# Vérifier que les assets existent
if [ -f "public/build/manifest.json" ]; then
    print_success "Manifest des assets trouvé"
else
    print_error "Manifest des assets manquant"
    exit 1
fi

# 11. Message de fin
echo ""
echo "=============================================="
print_success "🎉 Initialisation terminée avec succès !"
echo ""
print_status "Résumé:"
echo "  ✅ Dépendances Composer installées"
echo "  ✅ Dépendances npm installées"
echo "  ✅ Configuration Tailwind CSS v4 + Daisy UI"
echo "  ✅ Permissions corrigées"
echo "  ✅ Caches nettoyés"
echo "  ✅ Assets compilés"
echo ""
print_status "Votre application Laravel est prête !"
print_status "Vous pouvez maintenant accéder à votre application."
echo ""

# Option pour démarrer le serveur de développement
# read -p "Voulez-vous démarrer le serveur de développement Laravel ? (y/N): " -n 1 -r
# echo
# if [[ $REPLY =~ ^[Yy]$ ]]; then
#     print_status "Démarrage du serveur Laravel sur http://localhost:8000..."
#     php artisan serve
# fi
