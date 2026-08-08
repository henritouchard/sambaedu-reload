# setup de votre poste de travail

## Clonage du repo
## Installation

### repo + laravel
1. Cloner le repository

2. Lancer le script initialisation de laravel

```bash
chmod +x laravelInit.sh
./laravelInit.sh
```


### Modifier /etc/apache2/sites-enabled/sambaedu.conf pour que le document root soit le dossier laravel
Trouver les lignes (~17:20) et les remplacer par :
```bash
    <Directory /var/www/sambaedu>
		Options -Indexes +FollowSymLinks +MultiViews +ExecCGI
		AllowOverride All    
		Require all granted
	</Directory>
```
*"None" est à remplacer par "All"*

# Erreur de permissions sur /var/lock/sambaedu.lock
```bash
cd /var/lock
ls -la
chown root:root sambaedu.lock
```

# Erreur de permission ou absence de dossier laravel/storage/logs
There is no existing directory at "/root/se4/sources/var/www/sambaedu/laravel/storage/logs" and it could not be created: Permission denied

```bash
cd /var/www/sambaedu/laravel
php artisan cache:clear && php artisan config:clear && php artisan view:clear && php artisan config:cache

# en cas d'erreur incompréhensibe: cache corrompu:
rm -rf bootstrap/cache/*.php storage/framework/cache/* storage/framework/views/*
rm -f bootstrap/cache/config.php bootstrap/cache/services.php bootstrap/cache/packages.php
php artisan config:clear
php artisan cache:clear && php artisan view:clear

# si il faut recharger l'autoload de composer
composer dump-autoload
```

# Absence de connexion mariadb
le fichier ibdata1 n'est pas accessible en écriture. C'est un problème de permissions sur les fichiers de données MariaDB.
```bash
	ls -la /var/lib/mysql/ | head -20
```

Corriger les permissions :
```bash
chown -R mysql:mysql /var/lib/mysql/
chmod -R 755 /var/lib/mysql/
```
Puis redémarrer MariaDB :
```bash
systemctl restart mariadb
```






# Configuration PostgreSQL (Laravel)

Laravel utilise PostgreSQL via Docker pour la nouvelle architecture. MySQL/MariaDB reste utilisé par le code legacy.

## Dépendances requises

### Système
- **Docker** : Conteneurisation de PostgreSQL
- **curl** : Pour l'installation de Docker

### PHP
- **php-pgsql** : Driver PostgreSQL pour PHP

## Installation automatique

```bash
cd /var/www/sambaedu/laravel
sudo ./scripts/setup-postgres.sh
```

Ce script :
1. Installe Docker via `get.docker.com`
2. Démarre le conteneur PostgreSQL 16
3. Installe le driver PHP `php-pgsql`
4. Redémarre Apache

## Installation manuelle

### 1. Installer Docker
```bash
curl -fsSL https://get.docker.com | sh
systemctl start docker
systemctl enable docker
```

### 2. Installer le driver PHP PostgreSQL
```bash
apt-get install -y php-pgsql
systemctl restart apache2
```

### 3. Démarrer PostgreSQL
```bash
cd /var/www/sambaedu/laravel
docker compose up -d
```

### 4. Exécuter les migrations
```bash
php artisan migrate:fresh
```

## Configuration (.env)

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sambaedu
DB_USERNAME=sambaedu
DB_PASSWORD=sambaedu_secret
```

## Commandes utiles

```bash
# Voir l'état du conteneur
docker compose ps

# Voir les logs PostgreSQL
docker compose logs -f postgres

# Arrêter PostgreSQL
docker compose down

# Redémarrer PostgreSQL
docker compose up -d

# Accéder à psql
docker compose exec postgres psql -U sambaedu -d sambaedu
```

## Résolution de problèmes

### PostgreSQL ne démarre pas
```bash
docker compose logs postgres
```

### Erreur de connexion PHP
Vérifier que le driver est chargé :
```bash
php -m | grep pgsql
```

Si absent, installer et redémarrer :
```bash
apt-get install -y php-pgsql
systemctl restart apache2
```
