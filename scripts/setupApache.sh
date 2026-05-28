#!/bin/bash
#
# setupApache.sh — Bascule Apache pour que SER devienne le vhost principal (port 80)
# et que le legacy sambaedu soit accessible en interne uniquement (port 8082).
#
# Ce que fait ce script :
# 1. Backup des confs existantes (sambaedu.conf, sambaedu-reload.conf, ports.conf)
# 2. Crée le vhost SER sur port 80 (remplace l'ancien sambaedu)
# 3. Crée le vhost legacy sur port 8082 (localhost only, pour le proxy catchall)
# 4. Ajoute le port 8082 dans ports.conf
# 5. Met à jour le .env de SER avec LEGACY_BASE_URL
# 6. Désactive l'ancien vhost sambaedu-reload (port 8080) devenu inutile
# 7. Reload Apache + clear caches Laravel
#
# Usage : bash /var/www/sambaedu-reload/scripts/setupApache.sh
#

set -euo pipefail

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
APACHE_SITES_AVAILABLE="/etc/apache2/sites-available"
APACHE_SITES_ENABLED="/etc/apache2/sites-enabled"
PORTS_CONF="/etc/apache2/ports.conf"
SER_ROOT="/var/www/sambaedu-reload"
LEGACY_PORT=8082

echo "=== setupApache.sh — Bascule SER comme vhost principal ==="
echo ""

# ─── 0. Vérifications ────────────────────────────────────────────────────────

if [ "$(id -u)" -ne 0 ]; then
    echo "ERREUR : Ce script doit être exécuté en root."
    exit 1
fi

if [ ! -d "$SER_ROOT/public" ]; then
    echo "ERREUR : $SER_ROOT/public n'existe pas."
    exit 1
fi

if [ ! -f "$APACHE_SITES_ENABLED/sambaedu.conf" ] && [ ! -L "$APACHE_SITES_ENABLED/sambaedu.conf" ]; then
    echo "ERREUR : sambaedu.conf n'est pas actif dans sites-enabled."
    exit 1
fi

# ─── 1. Backup des confs existantes ──────────────────────────────────────────

BACKUP_DIR="/etc/apache2/backups-${TIMESTAMP}"
mkdir -p "$BACKUP_DIR"

echo "[1/7] Backup des configurations dans $BACKUP_DIR"

cp -L "$APACHE_SITES_ENABLED/sambaedu.conf" "$BACKUP_DIR/sambaedu.conf.backup"

if [ -f "$APACHE_SITES_AVAILABLE/sambaedu-reload.conf" ]; then
    cp "$APACHE_SITES_AVAILABLE/sambaedu-reload.conf" "$BACKUP_DIR/sambaedu-reload.conf.backup"
fi

cp "$PORTS_CONF" "$BACKUP_DIR/ports.conf.backup"

echo "   Backups : $(ls "$BACKUP_DIR" | tr '\n' ' ')"

# ─── 2. Récupérer le ServerName depuis la conf actuelle ──────────────────────

SERVERNAME=$(grep -oP 'ServerName\s+\K\S+' "$APACHE_SITES_ENABLED/sambaedu.conf" 2>/dev/null || echo "")
SERVERADMIN=$(grep -oP 'ServerAdmin\s+\K\S+' "$APACHE_SITES_ENABLED/sambaedu.conf" 2>/dev/null || echo "webmaster@localhost")

if [ -z "$SERVERNAME" ]; then
    SERVERNAME=$(hostname)
    echo "   WARN : Pas de ServerName trouvé, utilisation de '$SERVERNAME'"
fi

echo "   ServerName : $SERVERNAME"

# ─── 3. Créer le vhost SER (port 80) ─────────────────────────────────────────

echo "[2/7] Création du vhost SER (port 80)"

cat > "$APACHE_SITES_AVAILABLE/sambaedu.conf" << VHOST_SER
<VirtualHost *:80>
    ServerAdmin $SERVERADMIN
    ServerName $SERVERNAME
    DocumentRoot $SER_ROOT/public

    <FilesMatch "\.php$">
        SetHandler "proxy:fcgi://127.0.0.1:9000/"
    </FilesMatch>

    <Directory $SER_ROOT/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # /ipxe : sert les fichiers statiques volumineux (.wim, .sdi, wimboot, etc.)
    # directement depuis le legacy, .php legacy via FPM, et FallbackResource
    # délègue à Laravel pour les URL sans fichier physique (/ipxe/boot,
    # /ipxe/admin, /ipxe/enrollment/name, etc. — story 3.x + 4.9).
    Alias /ipxe /var/www/sambaedu/ipxe
    <Directory /var/www/sambaedu/ipxe>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    ErrorLog /var/log/apache2/sambaedu-reload-error.log
    CustomLog /var/log/apache2/sambaedu-reload-access.log combined
</VirtualHost>
VHOST_SER

# ─── 5. Créer le vhost legacy (port 8082, localhost only) ────────────────────

echo "[3/7] Création du vhost legacy (port $LEGACY_PORT, localhost only)"

# Extraction du réseau local pour la directive preseed (Allow from ...)
PRESEED_NETWORK=$(grep -oP 'Allow from \K\S+' "$BACKUP_DIR/sambaedu.conf.backup" 2>/dev/null | head -1)
PRESEED_NETWORK="${PRESEED_NETWORK:-172.19.1.0}"

cat > "$APACHE_SITES_AVAILABLE/sambaedu-legacy.conf" << VHOST_LEGACY
# Vhost legacy — accès interne uniquement (proxy catchall SER)
# Généré par setupApache.sh le $(date +%Y-%m-%d)
# NE PAS exposer ce port à l'extérieur
<VirtualHost 127.0.0.1:${LEGACY_PORT}>
    ServerAdmin $SERVERADMIN
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
        Allow from ${PRESEED_NETWORK}
    </FilesMatch>

    ErrorLog /var/log/apache2/sambaedu-legacy-error.log
    CustomLog /var/log/apache2/sambaedu-legacy-access.log combined
</VirtualHost>
VHOST_LEGACY

# ─── 6. Ajouter le port 8082 dans ports.conf ────────────────────────────────

echo "[4/7] Configuration ports.conf"

# Nettoyer les éventuelles entrées legacy précédentes avant d'ajouter
sed -i "/Legacy sambaedu/d" "$PORTS_CONF"
sed -i "/Listen.*${LEGACY_PORT}/d" "$PORTS_CONF"

# Ajouter le port legacy (listen localhost only)
echo "" >> "$PORTS_CONF"
echo "# Legacy sambaedu — accès interne uniquement (proxy catchall SER)" >> "$PORTS_CONF"
echo "Listen 127.0.0.1:${LEGACY_PORT}" >> "$PORTS_CONF"
echo "   Port $LEGACY_PORT ajouté (localhost only)"

# Retirer le port 8080 s'il existe (l'ancien vhost SER)
if grep -q "Listen 8080" "$PORTS_CONF"; then
    sed -i '/Listen 8080/d' "$PORTS_CONF"
    echo "   Port 8080 retiré (ancien vhost SER)"
fi

# ─── 7. Activer/désactiver les sites ────────────────────────────────────────

echo "[5/7] Activation des vhosts"

# Activer le legacy
ln -sf "$APACHE_SITES_AVAILABLE/sambaedu-legacy.conf" "$APACHE_SITES_ENABLED/sambaedu-legacy.conf"
echo "   sambaedu-legacy.conf activé"

# Activer sambaedu.conf dans sites-enabled
if [ -L "$APACHE_SITES_ENABLED/sambaedu.conf" ]; then
    # Symlink vers sites-available → déjà à jour via l'écriture précédente
    echo "   sambaedu.conf mis à jour (via symlink)"
else
    # Fichier direct → copier le nouveau contenu
    cp "$APACHE_SITES_AVAILABLE/sambaedu.conf" "$APACHE_SITES_ENABLED/sambaedu.conf"
    echo "   sambaedu.conf mis à jour (SER)"
fi

# Désactiver l'ancien vhost SER port 8080
if [ -f "$APACHE_SITES_ENABLED/sambaedu-reload.conf" ] || [ -L "$APACHE_SITES_ENABLED/sambaedu-reload.conf" ]; then
    rm -f "$APACHE_SITES_ENABLED/sambaedu-reload.conf"
    echo "   sambaedu-reload.conf désactivé (port 8080 retiré)"
fi

# ─── 8. Mettre à jour .env SER ──────────────────────────────────────────────

echo "[6/7] Mise à jour .env SER"

ENV_FILE="$SER_ROOT/.env"

if [ -f "$ENV_FILE" ]; then
    if grep -q "^LEGACY_BASE_URL=" "$ENV_FILE"; then
        sed -i "s|LEGACY_BASE_URL=.*|LEGACY_BASE_URL=http://127.0.0.1:${LEGACY_PORT}|" "$ENV_FILE"
    else
        echo "" >> "$ENV_FILE"
        echo "# URL interne du vhost legacy (proxy catchall)" >> "$ENV_FILE"
        echo "LEGACY_BASE_URL=http://127.0.0.1:${LEGACY_PORT}" >> "$ENV_FILE"
    fi
    echo "   LEGACY_BASE_URL=http://127.0.0.1:${LEGACY_PORT}"
else
    echo "   WARN : $ENV_FILE non trouvé — penser à ajouter LEGACY_BASE_URL manuellement"
fi

# ─── 9. Vérifier la config Apache et recharger ──────────────────────────────

echo "[7/7] Vérification et rechargement Apache"

if ! apache2ctl configtest 2>&1; then
    echo ""
    echo "ERREUR : La configuration Apache est invalide ! Restauration automatique..."
    cp "$BACKUP_DIR/sambaedu.conf.backup" "$APACHE_SITES_ENABLED/sambaedu.conf"
    cp "$BACKUP_DIR/ports.conf.backup" "$PORTS_CONF"
    rm -f "$APACHE_SITES_ENABLED/sambaedu-legacy.conf"
    if apache2ctl configtest 2>/dev/null; then
        apache2ctl graceful
        echo "   Config restaurée et Apache rechargé."
    else
        echo "   WARN : La restauration a aussi échoué. Vérifier manuellement."
        echo "   Backups dans : $BACKUP_DIR"
    fi
    exit 1
fi

apache2ctl graceful
echo "   Apache rechargé"

# Clear les caches Laravel
cd "$SER_ROOT"
php artisan config:cache 2>/dev/null && echo "   Config Laravel cachée"
php artisan route:cache 2>/dev/null && echo "   Routes Laravel cachées"

echo ""
echo "=== Terminé ==="
echo ""
echo "SER est maintenant le vhost principal (port 80)."
echo "Le legacy est accessible en interne sur http://127.0.0.1:${LEGACY_PORT}"
echo "Backups dans : $BACKUP_DIR"
echo ""
echo "Pour revenir en arrière :"
echo "  cp $BACKUP_DIR/sambaedu.conf.backup /etc/apache2/sites-enabled/sambaedu.conf"
echo "  rm /etc/apache2/sites-enabled/sambaedu-legacy.conf"
echo "  apache2ctl graceful"
