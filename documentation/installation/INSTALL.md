# 📦 Installation SambaEdu

Guide d'installation complet de SambaEdu avec Docker.

## 📋 Prérequis système

- **Linux** (Debian/Ubuntu recommandé)
- **Bash 4.0+**
- **PHP 8.2+**
- **Composer** (gestionnaire de dépendances PHP)
- **NPM** (optionnel, pour le frontend)
- **curl** (pour installer Docker)
- **Docker** (installé automatiquement si absent)
- **sudo** (pour certaines étapes)

### Installation des prérequis (si nécessaire)

```bash
# PHP et Composer
sudo apt update
sudo apt install php php-cli php-pgsql composer

# NPM (optionnel)
sudo apt install npm
```

## 🚀 Installation complète (une seule commande!)

```bash
cd /var/www/sambaedu-reload
sudo ./scripts/install.sh
```

### C'est tout! 🎉

Le script exécute automatiquement **5 phases**:

#### **Phase 1: Vérifications** ✓
- Bash, Docker, Docker Compose
- PHP, Composer
- NPM (optionnel)

#### **Phase 2: Configuration** ✓
- Génère `.env` avec clés de sécurité
- **Vous demande de vérifier/éditer** (pause interactive)
- Déploie PostgreSQL 16 + Redis 7
- Attend que les services soient prêts

#### **Phase 3: Dépendances** ✓
- `composer install`
- `npm install && npm run build` (si disponible)

#### **Phase 4: Base de données** ✓
- `php artisan migrate:fresh --seed`

#### **Phase 5: Optimisation** ✓
- `php artisan sambaedu:app:update`
  - Nettoie les caches
  - Compile les vues et routes
  - Optimise les événements

**Résultat:** Application **prête à être utilisée**! ✅

### Interaction pendant l'installation

Le script vous demandera une confirmation:

```
[!] IMPORTANT: Vérifiez et personnalisez les variables si nécessaire

Affichage du fichier .env généré:
...
APP_KEY=base64:...
SE4FS_INSTANCE_ID=...
REDIS_PASSWORD=...
...

Voulez-vous éditer le .env? (y/n):
```

- **Appuyez `y`** → Ouvre **nano** pour éditer
- **Appuyez `n`** → Accepte la configuration par défaut

Puis:
```
Continuer avec cette configuration? (y/n):
```

Appuyez `y` pour démarrer l'installation

### Si vous rencontrez une erreur "port already allocated"

Le script redémarre automatiquement les services existants. Sinon:

```bash
# Arrêter proprement
sudo ./scripts/cleanup.sh --soft

# Relancer l'installation
sudo ./scripts/install.sh
```

---

## 📝 Fichiers de configuration

### `docker-compose.yml`

Configuration des services Docker:

```yaml
services:
  postgres:
    - Port: 5432
    - Variables: DB_DATABASE, DB_USERNAME, DB_PASSWORD

  redis:
    - Port: 6379
    - Variable: REDIS_PASSWORD
```

#### Variables d'environnement supportées:

| Variable | Défaut | Description |
|----------|--------|-------------|
| `DB_DATABASE` | `sambaedu` | Nom de la base de données |
| `DB_USERNAME` | `sambaedu` | Utilisateur PostgreSQL |
| `DB_PASSWORD` | `sambaedu_secret` | Mot de passe PostgreSQL |
| `DB_PORT` | `5432` | Port PostgreSQL |
| `REDIS_PASSWORD` | `redis_secret` | Mot de passe Redis |
| `REDIS_PORT` | `6379` | Port Redis |

Exemple avec variables personnalisées:

```bash
DB_PASSWORD=ma_base_secret REDIS_PASSWORD=mon_redis docker compose up -d
```

### `.env`

Fichier généré automatiquement par `scripts/install.sh`:
- `APP_KEY` - Clé de chiffrement Laravel
- `SE4FS_INSTANCE_ID` - UUID unique de l'instance
- `SE4FS_INSTANCE_API_KEY` - Clé API
- `REDIS_PASSWORD` - Mot de passe Redis généré
- Et toutes les configurations nécessaires

**⚠️ À personnaliser:**
- `APP_URL` - URL de l'application
- `SAMBAEDU_SE4AD_IP` - IP du serveur Active Directory
- `SAMBAEDU_LDAP_*` - Configuration LDAP
- Configuration SMTP (mail)

---

## 🐳 Gestion des services Docker

### Voir les logs

```bash
# PostgreSQL
docker compose logs -f postgres

# Redis
docker compose logs -f redis

# Tous les services
docker compose logs -f
```

### Arrêter les services

```bash
docker compose down
```

### Redémarrer les services

```bash
docker compose restart
```

### Accéder à PostgreSQL

```bash
docker compose exec postgres psql -U sambaedu -d sambaedu
```

### Accéder à Redis

```bash
docker compose exec redis redis-cli
```

---

## 🔄 Mises à jour

Pour mettre à jour l'application:

```bash
sudo ./scripts/update.sh
```

Cette commande:
1. Met à jour les dépendances PHP (Composer)
2. Reconstruit le frontend (NPM)
3. Exécute les migrations
4. Met à jour Apache et les services systemd

---

## 🛠️ Scripts disponibles

### `scripts/install.sh` - **Installation complète** ⭐

**Usage:** `sudo ./scripts/install.sh`

**Installation initiale (tout en un):**
- ✅ Vérification des prérequis (PHP, Composer, NPM)
- ✅ Installation Docker (si absent)
- ✅ Génération du `.env` avec interaction utilisateur
- ✅ Déploiement PostgreSQL + Redis
- ✅ Installation Composer
- ✅ Installation et build NPM
- ✅ Migrations de base de données
- ✅ Optimisation applicative

**Voir aussi:** [SCRIPTS.md](./SCRIPTS.md) pour plus de détails

### `scripts/update.sh` - **Mise à jour applicative**

**Usage:** `sudo ./scripts/update.sh`

**Mise à jour régulière de l'application:**
- Mise à jour dépendances Composer
- Reconstruction frontend (NPM)
- Exécution migrations Laravel
- Mise à jour Apache (si applicable)
- Mise à jour services systemd

À utiliser après des changements du code ou des migrations

### `scripts/cleanup.sh` - **Gestion des services Docker**

**Usage:** `sudo ./scripts/cleanup.sh [OPTION]`

**Gestion et nettoyage des services:**

| Option | Effet |
|--------|--------|
| `--soft` | Arrête les conteneurs (données conservées) |
| `--hard` | Supprime les conteneurs (données conservées) |
| `--full` | Supprime tout (conteneurs + volumes + données) |
| `--status` | Affiche l'état des services |

**Exemples:**
```bash
# Voir l'état
sudo ./scripts/cleanup.sh --status

# Arrêter sans perdre les données
sudo ./scripts/cleanup.sh --soft

# Suppression complète (attention!)
sudo ./scripts/cleanup.sh --full
```

### `scripts/create-env.sh` - **Génération .env**

**Usage:** `bash ./scripts/create-env.sh`

**Génération du fichier `.env` :**
- Clés de sécurité (APP_KEY, SE4FS_INSTANCE_*, REDIS_PASSWORD)
- Intégration config système (`/etc/sambaedu/sambaedu.conf`)
- Intégration SMTP (`/etc/msmtprc`)
- Intégration mail (`/etc/aliases`)

*(Généralement appelé automatiquement par `install.sh` - rarement besoin d'appel manuel)*

---

## 🚨 Dépannage

### Docker n'est pas installé

Le script `install.sh` l'installe automatiquement via `get.docker.com`.

### PostgreSQL ne répond pas

Vérifier le statut:
```bash
docker compose ps
docker compose logs postgres
```

Redémarrer:
```bash
docker compose restart postgres
```

### Redis ne répond pas

Vérifier le statut:
```bash
docker compose ps
docker compose logs redis
```

Redémarrer:
```bash
docker compose restart redis
```

### Variables d'environnement manquantes

Le script `update.sh` affiche les variables manquantes. Les ajouter manuellement au `.env`:

```bash
nano .env
```

### Port déjà utilisé

Erreur: `Bind for 0.0.0.0:5432 failed: port is already allocated`

**Cause:** PostgreSQL ou Redis est déjà lancé sur ces ports.

**Solutions:**

1. Redémarrer les services:
   ```bash
   sudo ./scripts/install.sh
   ```

2. Arrêter les services existants:
   ```bash
   sudo ./scripts/cleanup.sh --soft
   ```

3. Vérifier les processus:
   ```bash
   sudo lsof -i :5432    # PostgreSQL
   sudo lsof -i :6379    # Redis
   ```

4. Tuer les processus directement:
   ```bash
   sudo docker compose down
   ```

### Permissions

Certains scripts nécessitent `sudo`. Si vous rencontrez des erreurs de permissions:

```bash
sudo chown -R $USER:$USER /var/www/sambaedu-reload
```

---

## 📚 Documentation additionnelle

- **Migrations:** `php artisan migrate --help`
- **Seeds:** `php artisan db:seed --help`
- **Artisan:** `php artisan list`
- **Docker Compose:** `docker compose --help`
- **PostgreSQL:** `docker compose exec postgres psql --help`

---

## 🔐 Sécurité

⚠️ **Important pour la production:**

1. **Changer tous les mots de passe par défaut:**
   ```bash
   nano .env
   ```
   - `DB_PASSWORD`
   - `REDIS_PASSWORD`
   - `MAIL_PASSWORD`
   - etc.

2. **Configurer HTTPS:**
   - Certificat SSL
   - Redirection HTTP → HTTPS

3. **Restreindre les accès:**
   - Firewall
   - Authentification AD/LDAP

4. **Sauvegardes:**
   - Base de données PostgreSQL
   - Données Redis
   - Fichiers d'application

---

Besoin d'aide? Consultez les logs:

```bash
docker compose logs
php artisan tinker
```
