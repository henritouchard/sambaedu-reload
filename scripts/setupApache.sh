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

# Pas de sambaedu.conf préexistant = première install (et non une bascule depuis
# le legacy) : on ne bloque pas. ServerName/ServerAdmin retomberont sur des
# défauts (hostname) plus bas. C'est ce qui permet à install.sh de déléguer ici
# sans condition (setupApache.sh = source unique de la conf Apache SER).
if [ ! -f "$APACHE_SITES_ENABLED/sambaedu.conf" ] && [ ! -L "$APACHE_SITES_ENABLED/sambaedu.conf" ]; then
    echo "   INFO : pas de sambaedu.conf existant — première install, valeurs par défaut."
fi

# Modules Apache requis : proxy_fcgi est indispensable au SetHandler proxy:fcgi
# qui route les .php vers PHP-FPM ; rewrite/headers pour Laravel.
a2enmod rewrite headers proxy_fcgi >/dev/null 2>&1 || true

# ─── 1. Backup des confs existantes ──────────────────────────────────────────

BACKUP_DIR="/etc/apache2/backups-${TIMESTAMP}"
mkdir -p "$BACKUP_DIR"

echo "[1/7] Backup des configurations dans $BACKUP_DIR"

# Backup seulement si une conf existe (absente en première install).
if [ -f "$APACHE_SITES_ENABLED/sambaedu.conf" ] || [ -L "$APACHE_SITES_ENABLED/sambaedu.conf" ]; then
    cp -L "$APACHE_SITES_ENABLED/sambaedu.conf" "$BACKUP_DIR/sambaedu.conf.backup"
fi

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

    # /ipxe : sert les statiques iPXE (boot.ipxe, png/, diconf/, binaires
    # undionly.kpxe/snponly_x64.efi) depuis le repo SE5 (Story 38.1) — plus AUCUNE
    # dépendance à /var/www/sambaedu : ces fichiers sont désormais versionnés sous
    # resources/ipxe/static/ et provisionnés dans storage/ipxe/static/ par
    # ensure_ipxe_statics (update.sh), chown www-admin. FallbackResource délègue à
    # Laravel pour toute URL SANS fichier physique (/ipxe/boot, /ipxe/admin,
    # /ipxe/enrollment/name, etc. — story 3.x + 4.9) : les routes natives priment
    # tant qu'aucun fichier ne shadow l'URL (ex. /ipxe/boot.ipxe reste servi en
    # statique, iso-fonctionnel au comportement legacy). À chown www-admin.
    #
    # GARDE-FOU SÉCURITÉ : l'Alias pointe EXACTEMENT sur le sous-dossier dédié
    # storage/ipxe/static, JAMAIS sur storage/ entier (storage/keys/pki/
    # contient les PFX code-signing + clés CA).
    Alias /ipxe $SER_ROOT/storage/ipxe/static
    <Directory $SER_ROOT/storage/ipxe/static>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    # /assets/shortcut-icons : icônes UPLOADÉES de raccourcis content-addressed
    # (Story 27.7), servies EN DIRECT. GARDE-FOU SÉCURITÉ : pointe EXACTEMENT sur
    # le sous-dossier dédié, JAMAIS sur storage/ entier (storage/keys/pki/ = PFX
    # code-signing + clés CA). -Indexes, PAS de FallbackResource. À chown
    # www-admin (lisible Apache).
    Alias /assets/shortcut-icons $SER_ROOT/storage/app/shortcut-icons
    <Directory $SER_ROOT/storage/app/shortcut-icons>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    # /assets/wallpaper : fonds d'écran content-addressed (biblio wallpaper,
    # config wallpapers.library_path = storage/app/wallpaper), servis EN DIRECT
    # (hors FPM/Laravel). GARDE-FOU SÉCURITÉ : pointe EXACTEMENT sur le
    # sous-dossier dédié, JAMAIS sur storage/ entier (storage/keys/pki/ = PFX
    # code-signing + clés CA). -Indexes, PAS de FallbackResource. À chown
    # www-admin (lisible Apache).
    Alias /assets/wallpaper $SER_ROOT/storage/app/wallpaper
    <Directory $SER_ROOT/storage/app/wallpaper>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    # /wpkg/bundle : bundle WPKG natif SE5 (Story 27.5) — scripts versionnés +
    # packages.xml pré-substitués, GÉNÉRÉS par 'php artisan wpkg:bundle' dans
    # storage/app/public/wpkg/bundle (ensure_wpkg_bundle dans update.sh). Servi
    # EN STATIQUE, zéro charge Laravel : c'est le client WPKG qui télécharge (D7).
    # GARDE-FOU SÉCURITÉ : pointe EXACTEMENT sur le sous-dossier dédié, JAMAIS sur
    # storage/ entier (storage/keys/pki/ = PFX code-signing + clés CA). -Indexes,
    # PAS de FallbackResource (un 404 reste un 404, ne retombe pas sur Laravel).
    # À chown www-admin (lisible Apache) — sinon serving 404 silencieux.
    Alias /wpkg/bundle $SER_ROOT/storage/app/public/wpkg/bundle
    <Directory $SER_ROOT/storage/app/public/wpkg/bundle>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    # /wpkg/files : PAYLOADS (binaires installeurs) des paquets WPKG (Story 27.19)
    # — livraison FULL HTTP : le moteur wpkg-se4.js télécharge chaque payload en
    # HTTP (download natif, target=%TEMP%) au lieu de l'ancien xcopy depuis le
    # partage SMB %SOFTWARE% (débranché). Les binaires sont déposés par
    # PackageInstallerService::downloadFiles à `storage_path/<saveto>` avec
    # saveto="packages/<...>" → l'alias mappe `packages/` sur sa racine, l'URL
    # publique est donc http://<se4fs>/wpkg/files/<saveto sans préfixe "packages/">.
    # GARDE-FOU SÉCURITÉ : pointe EXACTEMENT sur le sous-arbre des binaires
    # paquets (`.../install/packages`), JAMAIS sur `/var/sambaedu/unattended/install`
    # entier (qui contient aussi wpkg/{tmp2,packages.xml}, scripts, etc.), et JAMAIS
    # sur storage/keys/pki (PFX code-signing + clés CA). -Indexes (pas de listing),
    # PAS de FallbackResource (un 404 reste un 404, ne retombe pas sur Laravel).
    # Binaires world-readable 664, dossier à chown www-admin (lisible Apache) —
    # sinon 404 silencieux. Payloads = installeurs publics : pas d'ACL requise (v1
    # sans auth/HTTPS ; durcissement sha/HTTPS différé, cf. story 27.19 T4).
    Alias /wpkg/files /var/sambaedu/unattended/install/packages
    <Directory /var/sambaedu/unattended/install/packages>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    # /wpkg/tools : OUTILS PARTAGÉS WPKG (Story 27.20) — archiveur 7za.exe,
    # nircmd.exe (raccourcis), md5sum/wintail, tooltip/{wpkg-msg,tooltip}.exe.
    # Contrairement aux PAYLOADS par-app (/wpkg/files, téléchargés dans %TEMP%),
    # ce sont des outils « pareils pour tous » invoqués par les recettes via le
    # chemin EN DUR %Z%\wpkg\tools\… (= c:\windows\install\wpkg\tools\). 27.19 ne
    # les couvre PAS (aucun <download saveto> dans les recettes). On les sert donc
    # ici en HTTP (transport cohérent 27.19, jamais SMB) ; le poste les dépose UNE
    # FOIS sous %WinDir%\install\wpkg\tools\ via wpkg.cmd, de sorte que les recettes
    # restent INCHANGÉES. GARDE-FOU SÉCURITÉ : pointe EXACTEMENT le sous-arbre des
    # outils (`.../install/wpkg/tools`), JAMAIS `/var/sambaedu/unattended/install`
    # entier (qui contient aussi wpkg/{tmp2,packages.xml,wpkg-client.vbs}, packages,
    # ini, os, etc.), et JAMAIS storage/keys/pki (PFX code-signing + clés CA).
    # -Indexes (pas de listing), PAS de FallbackResource (un 404 reste un 404, ne
    # retombe pas sur Laravel). Outils world-readable 664, dossier à chown www-admin
    # (lisible Apache) — sinon 404 silencieux. Outils publics : pas d'ACL requise.
    Alias /wpkg/tools /var/sambaedu/unattended/install/wpkg/tools
    <Directory /var/sambaedu/unattended/install/wpkg/tools>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    # /install/iso : ISO Windows *sources* (déposées par upload ou téléchargées
    # par curl), config ipxe.iso_management.iso_storage_path = storage/install/iso.
    # Déplacées hors du tree /os pour respecter la convention storage/ ; servies
    # ici pour accès/vérification manuels. À chown www-admin (FPM + worker y
    # écrivent ET Apache y lit). -Indexes, pas de FallbackResource.
    Alias /install/iso $SER_ROOT/storage/install/iso
    <Directory $SER_ROOT/storage/install/iso>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    # NE PAS exposer le réassemblage de chunks (.part/.json partiels).
    <Directory $SER_ROOT/storage/install/iso/.uploads>
        Require all denied
    </Directory>

    # /doc : documentation publique SE5 (Story 52.1) — site statique VitePress
    # SANS authentification, deux parcours (« J'administre SE5 » / « J'utilise
    # mon poste »), généré depuis userDoc/ (chaîne npm ISOLÉE de l'application,
    # jamais de package.json/vite.config.js partagés) et publié dans
    # userDoc/dist/ par ensure_user_doc (update.sh — miroir/purge, PAS de build
    # direct dans ce dossier). Servi EN STATIQUE, zéro charge Laravel.
    #
    # GARDE-FOU SÉCURITÉ : l'Alias pointe EXACTEMENT sur le sous-dossier de
    # SORTIE PUBLIÉ userDoc/dist, JAMAIS sur userDoc/ entier (sources +
    # node_modules + package-lock.json) ni sur la racine du dépôt (.env,
    # storage/keys/pki/ = PFX code-signing + clés CA). -Indexes (pas de
    # listing), PAS de FallbackResource (un 404 reste un 404, ne retombe pas
    # sur Laravel). À chown www-admin (lisible Apache) — sinon 404 silencieux.
    #
    # Neutralisation PHP PAR RÉPERTOIRE : le <FilesMatch "\.php$"> du haut de
    # ce vhost (SetHandler proxy:fcgi) est GLOBAL et s'appliquerait sinon À CE
    # DOSSIER AUSSI — un .php déposé dans userDoc/dist serait routé vers FPM et
    # EXÉCUTÉ. SetHandler none désarme le handler hérité, Require all denied
    # referme le tir (défense en profondeur : même si SetHandler none était un
    # jour ignoré par une future version d'Apache, l'accès resterait refusé).
    #
    # ErrorDocument 404 : sert la page 404 STATIQUE générée par VitePress
    # (français, thème du site) plutôt que la page 404 générique d'Apache —
    # reste un 404 réel (PAS de FallbackResource, PAS de redirection vers
    # Laravel), simplement avec le contenu du site déjà présent dans le
    # dossier publié.
    Alias /doc $SER_ROOT/userDoc/dist
    <Directory $SER_ROOT/userDoc/dist>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        ErrorDocument 404 /doc/404.html
        <FilesMatch "\.php$">
            SetHandler none
            Require all denied
        </FilesMatch>
    </Directory>

    # /ext/<clé> : extensions de type « app » installées par SE5 (Story 56.2).
    # Chaque installation pose UN fragment ProxyPass vers le backend local de
    # l'extension (127.0.0.1:<port assigné>), généré et retiré par
    # sambaedu-ext-helper.sh — jamais par PHP, jamais à la main.
    #
    # ⚠️ IncludeOptional DANS le vhost :80, et surtout PAS un a2enconf global :
    # une conf globale s'appliquerait AUSSI au vhost legacy 8082, qui doit
    # rester strictement inchangé (NFR16). « Optional » : aucune extension
    # installée ⇒ aucun fichier ⇒ Apache démarre normalement.
    #
    # Le préfixe /ext/ n'entre en conflit avec aucun Alias existant (/ipxe,
    # /assets/*, /install/iso, /wpkg/*, /doc) ; un chemin proxifié n'atteint
    # jamais le FallbackResource de Laravel.
    IncludeOptional /etc/apache2/sambaedu-ext.d/*.conf

    ErrorLog /var/log/apache2/sambaedu-reload-error.log
    CustomLog /var/log/apache2/sambaedu-reload-access.log combined
</VirtualHost>
VHOST_SER

# ─── 5. Créer le vhost legacy (port 8082, localhost only) ────────────────────

echo "[3/7] Création du vhost legacy (port $LEGACY_PORT, localhost only)"

# Extraction du réseau local pour la directive preseed (Allow from ...)
PRESEED_NETWORK=$(grep -oP 'Allow from \K\S+' "$BACKUP_DIR/sambaedu.conf.backup" 2>/dev/null | head -1 || echo "")
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
    # En première install il n'y a pas de backup : on retire la conf invalide
    # plutôt que de laisser Apache refuser de démarrer.
    cp "$BACKUP_DIR/sambaedu.conf.backup" "$APACHE_SITES_ENABLED/sambaedu.conf" 2>/dev/null \
        || rm -f "$APACHE_SITES_ENABLED/sambaedu.conf"
    cp "$BACKUP_DIR/ports.conf.backup" "$PORTS_CONF" 2>/dev/null || true
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

# Service natif des assets OS iPXE (mod_xsendfile + XSendFile dans le vhost) —
# le vhost vient d'etre regenere sans ces directives, on les (re)pose. Idempotent.
bash "$SER_ROOT/scripts/setupXsendfile.sh"

# Clear les caches Laravel
cd "$SER_ROOT"
php artisan config:cache 2>/dev/null && echo "   Config Laravel cachée"
php artisan route:cache 2>/dev/null && echo "   Routes Laravel cachées"

# Ce script tourne en root : les caches viennent d'être écrits root:root, alors
# que PHP-FPM tourne en www-admin. La lecture passe (664) mais toute réécriture
# ultérieure par FPM échoue. Restituer la propriété, sinon la panne arrive plus
# tard et sans lien apparent avec ce script.
chown -R www-admin:www-admin "$SER_ROOT/bootstrap/cache" 2>/dev/null \
    && echo "   Caches rendus à www-admin"

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
