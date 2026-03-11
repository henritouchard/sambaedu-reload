# Migration SambaEdu Legacy -> Laravel (exécution manuelle, première fois)

Ce document reprend les **12 étapes** de `scripts/laravelUpgrade.sh` pour les exécuter manuellement, une par une.

> Référence script: `scripts/laravelUpgrade.sh`

## 0) Préparation (variables)

```bash
# À exécuter en root
export SAMBAEDU_DIR="/root/se4/sources/var/www/sambaedu"
export TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
export BACKUP_DIR="/root/save/$TIMESTAMP"

mkdir -p "$BACKUP_DIR"
mkdir -p /var/log/sambaedu
```

## 0.1) Créer le fichier `.env` (si absent)

```bash
# Génère un .env PostgreSQL (par défaut)
bash "$SAMBAEDU_DIR/laravel/scripts/create-env.sh"

# ou Écraser un .env existant (avec backup automatique)
# bash "$SAMBAEDU_DIR/laravel/scripts/create-env.sh" --force
```

## 0.2) Installer Docker + PostgreSQL (si absent)

# Ne pas suivre ces instructions, on les remplacera par le pas à pas de docker

```bash
# Debian/Ubuntu
apt-get update
apt-get install -y ca-certificates curl gnupg lsb-release

install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian \
  $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  > /etc/apt/sources.list.d/docker.list

apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

systemctl enable --now docker

# PostgreSQL en conteneur Docker
docker volume create sambaedu-pgdata

docker run -d --name sambaedu-postgres \
  --restart unless-stopped \
  -e POSTGRES_DB=sambaedu_laravel \
  -e POSTGRES_USER=sambaedu \
  -e POSTGRES_PASSWORD='CHANGE_ME_STRONG_PASSWORD' \
  -p 5432:5432 \
  -v sambaedu-pgdata:/var/lib/postgresql/data \
  postgres:16

# Vérification rapide
docker ps --filter name=sambaedu-postgres
```

---

## 1) Vérifications préliminaires

```bash
# Root obligatoire
# Services
sudo systemctl is-active mysql
sudo systemctl is-active apache2 || true
sudo systemctl is-active samba-ad-dc || true

# Espace disque /var (>= 5G)
df -BG /var

# Présence Laravel + .env
test -f "$SAMBAEDU_DIR/laravel/artisan"
test -f "$SAMBAEDU_DIR/laravel/.env"
```

---

## 2) Sauvegarde base de données (MySQL, credentials legacy)

```bash
# D'après le legacy: DB sambaedu / user sambaedu, mot de passe dans /etc/sambaedu/sambaedu.conf
DB_NAME="sambaedu"
DB_USER="sambaedu"
DB_PASS="$(grep -E '^sql_passwd=' /etc/sambaedu/sambaedu.conf | tail -n1 | cut -d '=' -f2- | tr -d '"')"

if [ -z "$DB_PASS" ]; then
  echo "sql_passwd introuvable dans /etc/sambaedu/sambaedu.conf"
  exit 1
fi

mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$BACKUP_DIR/mysql_${DB_NAME}_${TIMESTAMP}.sql.gz"
ls -lh "$BACKUP_DIR"/mysql_*.sql.gz
```

---

## 3) Sauvegarde Active Directory (Samba)

```bash
AD_BACKUP_DIR="$BACKUP_DIR/samba-ad"
mkdir -p "$AD_BACKUP_DIR"

cp -a /etc/samba "$AD_BACKUP_DIR/"

if [ -d "/var/lib/samba/private" ]; then
  tar -czf "$AD_BACKUP_DIR/samba-private.tar.gz" -C /var/lib/samba private
fi

samba-tool domain backup online --targetdir="$AD_BACKUP_DIR" || true

if [ -f "/var/lib/samba/private/sam.ldb" ]; then
  ldbsearch -H /var/lib/samba/private/sam.ldb "(objectClass=*)" > "$AD_BACKUP_DIR/ad_export_${TIMESTAMP}.ldif" || true
fi
```

---

## 4) Sauvegarde des fichiers de configuration

```bash
FILES_BACKUP_DIR="$BACKUP_DIR/files"
mkdir -p "$FILES_BACKUP_DIR"

for path in \
  /etc/sambaedu \
  /var/www \
  /etc/apache2 \
  /etc/httpd \
  "$SAMBAEDU_DIR/includes/config.inc.php"
do
  [ -e "$path" ] && cp -a "$path" "$FILES_BACKUP_DIR/" || true
done

# Vérifier si il un .htaccess existe parce que je n'en suis plus sur

cp "$SAMBAEDU_DIR/laravel/.env" "$FILES_BACKUP_DIR/.env.backup"
```

---

## 5) Activer le mode maintenance Laravel

```bash
php "$SAMBAEDU_DIR/laravel/artisan" down --message="Migration en cours vers Laravel" --retry=60
```

---

## 6) Nettoyage des caches

```bash
php "$SAMBAEDU_DIR/laravel/artisan" cache:clear
php "$SAMBAEDU_DIR/laravel/artisan" config:clear
php "$SAMBAEDU_DIR/laravel/artisan" route:clear
php "$SAMBAEDU_DIR/laravel/artisan" view:clear
php -r "if (function_exists('apcu_clear_cache')) { apcu_clear_cache(); echo 'APCu cleared'.PHP_EOL; }"
```

---

## 7) Exécuter les migrations Laravel

```bash
php "$SAMBAEDU_DIR/laravel/artisan" migrate --database=pgsql --force
```

---

## 9) Optimiser Laravel

```bash
php "$SAMBAEDU_DIR/laravel/artisan" config:cache
php "$SAMBAEDU_DIR/laravel/artisan" route:cache
php "$SAMBAEDU_DIR/laravel/artisan" view:cache
composer dump-autoload --optimize --working-dir="$SAMBAEDU_DIR/laravel"
```

---

## 11) Désactiver le mode maintenance

```bash
php "$SAMBAEDU_DIR/laravel/artisan" up
```

---

## 12) Contrôles finaux recommandés

```bash
# Santé Laravel
php "$SAMBAEDU_DIR/laravel/artisan" about

# Vérifier Apache
systemctl status apache2 --no-pager || true

# Vérifier quelques logs
tail -n 100 /var/log/sambaedu/laravel-upgrade.log 2>/dev/null || true
tail -n 100 "$SAMBAEDU_DIR/laravel/storage/logs/laravel.log" 2>/dev/null || true
```

## Rollback manuel (base MySQL)

```bash
# Exemple: restaurer le dernier dump
LATEST_DUMP="$(ls -1t /var/backups/sambaedu/laravel-upgrade/*/mysql_*.sql.gz | head -n1)"

DB_NAME="$(grep '^DB_DATABASE=' "$SAMBAEDU_DIR/laravel/.env" | cut -d '=' -f2)"
DB_USER="$(grep '^DB_USERNAME=' "$SAMBAEDU_DIR/laravel/.env" | cut -d '=' -f2)"
DB_PASS="$(grep '^DB_PASSWORD=' "$SAMBAEDU_DIR/laravel/.env" | cut -d '=' -f2)"

zcat "$LATEST_DUMP" | mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME"
```
