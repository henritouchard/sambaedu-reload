#!/bin/bash
#
# Script de configuration PostgreSQL Docker pour SE4FS Laravel
#
# Usage: sudo ./scripts/setup-postgres.sh
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARAVEL_DIR="$(dirname "$SCRIPT_DIR")"

echo "=== Configuration PostgreSQL Docker pour SE4FS ==="
echo ""

# Vérifier si Docker est installé
if ! command -v docker &> /dev/null; then
    echo "Docker n'est pas installé. Installation via get.docker.com..."
    
    # Installation Docker via script officiel (évite les problèmes dpkg)
    curl -fsSL https://get.docker.com | sh
    
    echo "Docker installé avec succès."
fi

# Vérifier si le service Docker est démarré
if ! systemctl is-active --quiet docker; then
    echo "Démarrage du service Docker..."
    systemctl start docker
    systemctl enable docker
fi

# Aller dans le répertoire Laravel
cd "$LARAVEL_DIR"

# Démarrer PostgreSQL
echo ""
echo "Démarrage de PostgreSQL Docker..."
docker compose up -d

# Attendre que PostgreSQL soit prêt
echo "Attente de la disponibilité de PostgreSQL..."
sleep 5

# Vérifier la connexion
echo ""
echo "Vérification de la connexion..."
docker compose exec -T postgres pg_isready -U sambaedu -d sambaedu

# Installer le driver PHP PostgreSQL si nécessaire
if ! php -m | grep -q pgsql; then
    echo ""
    echo "Installation du driver PHP PostgreSQL..."
    apt-get install -y php-pgsql
    systemctl restart apache2 || systemctl restart php-fpm || true
fi

echo ""
echo "=== Configuration terminée ==="
echo ""
echo "PostgreSQL est accessible sur localhost:5432"
echo "Base de données: sambaedu"
echo "Utilisateur: sambaedu"
echo "Mot de passe: sambaedu_secret"
echo ""
echo "Pour exécuter les migrations:"
echo "  cd $LARAVEL_DIR"
echo "  php artisan migrate:fresh"
echo ""
