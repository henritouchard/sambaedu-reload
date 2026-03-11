# 🚨 Dépannage

Guide de résolution des problèmes courants lors de l'installation et la mise à jour de SambaEdu.

## 📋 Table des matières

1. [Problèmes Docker](#problèmes-docker)
2. [Problèmes de ports](#problèmes-de-ports)
3. [Problèmes de permissions](#problèmes-de-permissions)
4. [Problèmes de base de données](#problèmes-de-base-de-données)
5. [Problèmes d'application](#problèmes-dapplication)
6. [Problèmes de scripts](#problèmes-de-scripts)

---

## 🐳 Problèmes Docker

### Docker n'est pas installé

**Erreur:** `command not found: docker`

**Solution:**

Le script `install.sh` l'installe automatiquement. Sinon, installer manuellement:

```bash
curl -fsSL https://get.docker.com | sh
sudo systemctl start docker
sudo systemctl enable docker
```

### Service Docker n'a pas démarré

**Erreur:** `Cannot connect to Docker daemon`

**Solution:**

```bash
# Démarrer Docker
sudo systemctl start docker

# Vérifier que c'est bien lancé
sudo systemctl status docker

# Activer au démarrage
sudo systemctl enable docker
```

### Docker Compose n'est pas installé

**Erreur:** `docker: 'compose' is not a command`

**Solution:**

C'est le plugin Docker Compose qui manque. Réinstaller Docker:

```bash
sudo apt update
sudo apt install docker-ce docker-ce-cli containerd.io docker-compose-plugin
```

### Permission denied (sans sudo)

**Erreur:** `Got permission denied while trying to connect to Docker daemon`

**Solution:**

Ajouter votre utilisateur au groupe docker:

```bash
sudo usermod -aG docker $USER
newgrp docker

# Vérifier que ça marche
docker ps
```

---

## 🔌 Problèmes de ports

### Port 5432 (PostgreSQL) déjà utilisé

**Erreur:**
```
Error response from daemon: failed to set up container networking:
Bind for 0.0.0.0:5432 failed: port is already allocated
```

**Cause:** PostgreSQL est déjà lancé (conteneur Docker ou service system)

**Solutions:**

**Option 1: Redémarrer les services (recommandé)**
```bash
sudo ./scripts/install.sh
# Le script détecte automatiquement et redémarre
```

**Option 2: Arrêter les services proprement**
```bash
sudo ./scripts/cleanup.sh --soft
sudo ./scripts/install.sh
```

**Option 3: Chercher le processus qui utilise le port**
```bash
sudo lsof -i :5432
sudo kill <PID>

# ou
sudo docker compose down
```

**Option 4: Utiliser un port différent**
```bash
DB_PORT=5433 docker compose up -d
# Modifier .env
nano .env  # DB_PORT=5433
```

### Port 6379 (Redis) déjà utilisé

**Erreur:**
```
Bind for 0.0.0.0:6379 failed: port is already allocated
```

**Solutions:** Même que PostgreSQL ci-dessus

```bash
# Voir quel processus utilise le port
sudo lsof -i :6379

# Ou supprimer les conteneurs existants
sudo ./scripts/cleanup.sh --soft
```

### Port 3306 (MySQL) ou autre

Si un autre service utilise le port 3000-9000, voir lequel:

```bash
# Lister tous les ports utilisés
sudo lsof -i -P -n | grep LISTEN

# Arrêter le service conflictuel
sudo systemctl stop <service>

# Ou changer les ports dans docker-compose.yml
nano docker-compose.yml
```

---

## 🔐 Problèmes de permissions

### Permission denied lors de `sudo ./scripts/`

**Erreur:** `Permission denied`

**Solution:**

```bash
# Rendre le script exécutable
chmod +x ./scripts/install.sh
chmod +x ./scripts/update.sh
chmod +x ./scripts/cleanup.sh

# Vérifier
ls -la ./scripts/*.sh
```

### Permission denied sur `.env`

**Erreur:** `touch: cannot touch '.env': Permission denied`

**Solution:**

```bash
# Vérifier les permissions du répertoire
ls -la /var/www/sambaedu-reload/

# Changer le propriétaire
sudo chown -R $USER:$USER /var/www/sambaedu-reload
sudo chown -R www-data:www-data /var/www/sambaedu-reload/storage
```

### Docker: permission denied sur socket

**Erreur:** `Cannot connect to Docker daemon socket`

**Solution:**

```bash
# Ajouter au groupe docker
sudo usermod -aG docker $USER
newgrp docker

# Ou utiliser sudo
sudo ./scripts/install.sh
```

---

## 💾 Problèmes de base de données

### PostgreSQL n'a pas démarré

**Erreur:**
```
Container sambaedu_postgres exited
docker-compose logs postgres
```

**Solutions:**

**Vérifier les logs:**
```bash
docker compose logs postgres
```

**Redémarrer:**
```bash
docker compose restart postgres
docker compose logs -f postgres  # Voir en temps réel
```

**Supprimer et recréer:**
```bash
sudo ./scripts/cleanup.sh --hard
sudo ./scripts/install.sh
```

### Pas de connexion à PostgreSQL

**Erreur:** `Connection refused` ou `FATAL: no pg_hba.conf entry`

**Solutions:**

**Vérifier que le container tourne:**
```bash
docker compose ps postgres
```

**Attendre que PostgreSQL soit prêt:**
```bash
docker compose logs postgres
# Chercher "database system is ready"

# Ou attendre avec
sleep 10
docker compose exec postgres pg_isready
```

**Vérifier les credentials:**
```bash
# Dans .env
grep "^DB_" .env

# Utiliser les mêmes dans docker-compose.yml
docker compose env | grep "DB_"
```

### Base de données corrompue

**Symptômes:** Erreurs SQL aléatoires, migrations qui échouent

**Solution:**

⚠️ **Cela supprimera tous les données!**

```bash
# Suppression complète des données
sudo ./scripts/cleanup.sh --full

# Redéployer
sudo ./scripts/install.sh

# Créer les tables
php artisan migrate:fresh
```

---

## 🚀 Problèmes d'application

### Artisan not found

**Erreur:** `bash: artisan: command not found`

**Solution:**

Vous n'êtes pas dans le répertoire du projet:

```bash
# Aller au répertoire du projet
cd /var/www/sambaedu-reload

# Vérifier
ls -la artisan
```

### APP_KEY not set

**Erreur:** `InvalidArgumentException: The APP_KEY environment variable is not set`

**Solution:**

```bash
# Générer la clé
php artisan key:generate

# Ou relancer install.sh
sudo ./scripts/install.sh
```

### Migration failed

**Erreur:** `SQLSTATE[08006]: Connection to server failed`

**Solution:**

1. Vérifier que PostgreSQL tourne:
   ```bash
   docker compose ps postgres
   ```

2. Attendre que PostgreSQL soit prêt:
   ```bash
   docker compose logs postgres | tail
   ```

3. Vérifier les credentials:
   ```bash
   grep "^DB_" .env
   ```

4. Réessayer:
   ```bash
   php artisan migrate:fresh
   ```

### Composer dependencies conflict

**Erreur:** `Your requirements could not be resolved to an installable set`

**Solution:**

```bash
# Mettre à jour Composer
composer self-update

# Nettoyer et réinstaller
rm -rf vendor composer.lock
composer install
```

### NPM build failed

**Erreur:** `npm ERR! code ERESOLVE` ou `npm error`

**Solution:**

```bash
# Mettre à jour npm
npm install -g npm

# Nettoyer
rm -rf node_modules package-lock.json

# Réinstaller
npm install
npm run build
```

---

## 📝 Problèmes de scripts

### Script dit "permission denied"

**Erreur:** `bash: ./scripts/install.sh: Permission denied`

**Solution:**

```bash
chmod +x ./scripts/install.sh
chmod +x ./scripts/update.sh
chmod +x ./scripts/cleanup.sh
```

### Script dit "command not found"

**Erreur:** `docker: command not found` (dans le script)

**Cause:** Docker n'est pas installé

**Solution:**

```bash
# Install Docker
sudo apt update
sudo apt install curl
curl -fsSL https://get.docker.com | sudo sh

# Relancer le script
sudo ./scripts/install.sh
```

### Script dit "cd: not found"

**Erreur:** Très rare

**Cause:** Le shell n'est pas bash

**Solution:**

```bash
# Utiliser bash explicitement
bash ./scripts/install.sh
```

### Variables .env manquantes

**Erreur:** `Variable présentes dans .env.example mais absentes de .env`

**Solution:**

```bash
# Voir les variables manquantes
grep -E "^[A-Z_]+=" .env.example | cut -d= -f1 > /tmp/expected.txt
grep -E "^[A-Z_]+=" .env | cut -d= -f1 > /tmp/actual.txt
comm -23 /tmp/expected.txt /tmp/actual.txt

# Ajouter manuellement au .env
nano .env
```

---

## 🔍 Diagnostic

### Vérifier l'état général

```bash
# État des containers
docker compose ps

# Logs tout
docker compose logs

# État du système
sudo systemctl status docker

# Ports utilisés
sudo lsof -i -P -n | grep LISTEN
```

### Accéder aux services

```bash
# PostgreSQL
docker compose exec postgres psql -U sambaedu -d sambaedu

# Redis
docker compose exec redis redis-cli

# Bash dans le container
docker compose exec postgres bash
```

### Supprimer tout (nuclear option)

⚠️ **PERTE DE TOUTES LES DONNÉES**

```bash
# Arrêter tout
docker compose down -v

# Supprimer les volumes
docker volume prune

# Relancer depuis zéro
sudo ./scripts/install.sh
```

---

## 💬 Aide supplémentaire

### Consulter la documentation

- [INSTALL.md](./INSTALL.md) - Guide d'installation
- [SCRIPTS.md](./SCRIPTS.md) - Référence des scripts
- Documentation Laravel: [laravel.com/docs](https://laravel.com/docs)
- Documentation Docker: [docs.docker.com](https://docs.docker.com)

### Logs utiles

```bash
# Tous les logs
docker compose logs

# PostgreSQL seulement
docker compose logs -f postgres

# Dernières 50 lignes
docker compose logs --tail=50 postgres

# En temps réel
docker compose logs -f
```

### Commandes utiles

```bash
# Redémarrer tout
docker compose restart

# Arrêter
docker compose stop

# Relancer
docker compose up -d

# Supprimer les containers (garder les données)
docker compose rm

# Status détaillé
docker compose ps --all
```

---

Si votre problème n'est pas listé ici, vérifier les logs:

```bash
docker compose logs -f
php artisan tinker
```
