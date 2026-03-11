#!/bin/bash
#
# create-env.sh - Génère un fichier .env propre pour SambaEdu Laravel
#
# Usage:
#   ./scripts/create-env.sh [--force]
#

set -e

SAMBAEDU_DIR="/var/www/sambaedu"
ENV_FILE="$SAMBAEDU_DIR/laravel/.env"

FORCE=false

for arg in "$@"; do
    case "$arg" in
        --force)
            FORCE=true
            ;;
        --help|-h)
            echo "Usage: $0 [--force]"
            exit 0
            ;;
        *)
            echo "Option inconnue: $arg"
            echo "Usage: $0 [--force]"
            exit 1
            ;;
    esac
done

if [[ -f "$ENV_FILE" && "$FORCE" != true ]]; then
    echo ".env existe déjà: $ENV_FILE"
    echo "Utilise --force pour l'écraser."
    exit 0
fi

if [[ -f "$ENV_FILE" ]]; then
    cp "$ENV_FILE" "$ENV_FILE.bak.$(date +%Y%m%d_%H%M%S)"
fi

if ! command -v php >/dev/null 2>&1; then
    echo "Erreur: php est requis pour générer APP_KEY."
    exit 1
fi
APP_KEY_VALUE="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"

if ! command -v uuidgen >/dev/null 2>&1; then
    echo "Erreur: uuidgen est requis pour générer SE4FS_INSTANCE_ID."
    exit 1
fi
SE4FS_INSTANCE_ID_VALUE="$(uuidgen)"

SE4FS_INSTANCE_API_KEY_VALUE="$(php -r 'echo "se4fs_instance_".bin2hex(random_bytes(16));')"

cat > "$ENV_FILE" <<EOF
APP_NAME=Sambaedu
APP_ENV=local
APP_KEY=$APP_KEY_VALUE
APP_DEBUG=true
APP_TIMEZONE=Europe/Paris
APP_URL=http://se4fs

APP_LOCALE=fr
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=fr_FR

APP_MAINTENANCE_DRIVER=file
APP_MAINTENANCE_STORE=database

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sambaedu
DB_USERNAME=sambaedu
DB_PASSWORD=sambaedu_secret

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
SESSION_DRIVER=file
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="\${APP_NAME}"

# SambaEdu: Fallbacks si absent de /etc/sambaedu/sambaedu.conf
SAMBAEDU_SE4AD_IP=192.168.122.60
SAMBAEDU_SE4AD_ETAB_IP=192.168.122.60
SAMBAEDU_STRICT_LOCAL_AD=true

# SambaEdu: Tests d'intégration legacy uniquement
SAMBAEDU_LDAP_HOST=192.168.122.60
SAMBAEDU_LDAP_PORT=636
SAMBAEDU_LDAP_BASE_DN="dc=localdev,dc=fr"
SAMBAEDU_LDAP_ADMIN_USER=Administrator
SAMBAEDU_LDAP_ADMIN_PASSWORD=CHANGE_ME
SAMBAEDU_LDAP_DOMAIN=localdev.fr

SE4FS_INSTANCE_ID=$SE4FS_INSTANCE_ID_VALUE
SE4FS_INSTANCE_API_KEY=$SE4FS_INSTANCE_API_KEY_VALUE
EOF

echo ".env créé: $ENV_FILE"
echo "Pense a ajuster les valeurs sensibles (DB_*, SAMBAEDU_LDAP_*, APP_URL)."
