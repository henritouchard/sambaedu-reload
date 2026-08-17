# 🛠️ Scripts d'installation et mise à jour

Référence complète de tous les scripts de gestion de SambaEdu.

## 📂 Localisation

Tous les scripts se trouvent dans: `scripts/`

```
scripts/
├── install.sh           ← Installation initiale
├── update.sh            ← Mise à jour de l'application
├── cleanup.sh           ← Gestion des services Docker
├── create-env.sh        ← Génération .env
├── config/              ← Fichiers de configuration systemd
│   ├── laravel-queue-general.service
│   ├── laravel-queue-sync.service
│   └── laravel-queue-worker.service
└── old/                 ← Scripts archivés
```

---

## 🚀 `scripts/install.sh`

**Installation initiale avec Docker et configuration**

### Usage

```bash
sudo ./scripts/install.sh
```

### Exécution depuis n'importe où

```bash
# Chemin absolu
sudo /var/www/sambaedu-reload/scripts/install.sh

# Chemin relatif (depuis le projet)
cd /var/www/sambaedu-reload
sudo ./scripts/install.sh
```

### Actions effectuées

1. ✅ **Vérification des services existants**
   - Détecte les conteneurs Docker déjà lancés
   - Affiche l'état et les options disponibles

2. ✅ **Vérification de bash**
   - S'assure que le script est exécuté avec bash

3. ✅ **Installation Docker**
   - Vérifie que Docker est installé
   - Installe Docker via `get.docker.com` si absent
   - Démarre le service Docker

4. ✅ **Vérification Docker Compose**
   - S'assure que le plugin Docker Compose est disponible

5. ✅ **Génération du .env**
   - Crée `.env` s'il n'existe pas
   - Génère les clés de sécurité:
     - `APP_KEY` - Clé de chiffrement Laravel
     - `SE4FS_INSTANCE_ID` - UUID unique
     - `SE4FS_INSTANCE_API_KEY` - Clé API
     - `REDIS_PASSWORD` - Mot de passe Redis
   - Charge la config depuis `/etc/sambaedu/sambaedu.conf` (si disponible)

6. ✅ **Déploiement Docker**
   - Démarre PostgreSQL 16
   - Démarre Redis 7
   - Attends que les services soient prêts (health checks)
   - Redémarre les services s'ils existaient déjà

### Output

```
[install] Démarrage de l'installation SambaEdu...
[install] Vérification des services existants...
[install] Vérification bash...
[✓] bash OK
[install] Vérification Docker...
[✓] Docker trouvé: Docker version 24.0.0...
...
═══════════════════════════════════════════════════════════════
Installation SambaEdu terminée
═══════════════════════════════════════════════════════════════
Services disponibles:
  📦 PostgreSQL:  localhost:5432
  🔴 Redis:       localhost:6379
```

### Variables d'environnement

Peut être appelé avec des variables pour personnaliser:

```bash
DB_PASSWORD=monmotdepasse REDIS_PASSWORD=monredis sudo ./scripts/install.sh
```

---

## 🔄 `scripts/update.sh`

**Mise à jour complète de l'application**

### Usage

```bash
sudo ./scripts/update.sh
```

### Prérequis

- Doit être exécuté en root (sudo)
- Laravel artisan doit être disponible

#### ⚠️ Reprise du plan de fichiers — à jouer AVANT la mise à jour

Sur une instance déjà en service qui n'a **jamais** joué la reprise des
emplacements de fichiers, jouer d'abord :

```bash
php artisan files:adopt-locations --dry-run   # voir ce qui serait écrit
php artisan files:adopt-locations             # appliquer
```

**La conséquence si on ne le fait pas** est immédiate et silencieuse :

- sans ligne de décision, le plan de fichiers rend ses **défauts** — les deux
  espaces sur le serveur de fichiers. Une instance qui servait ses fichiers en
  accès web **retrouve donc les lecteurs `K:` et `H:` sur tous les postes**, à la
  première ouverture de session qui suit le déploiement ;
- et l'écran des réglages **n'offre alors aucune décision d'emplacement** : il
  affiche l'état hérité en lecture seule et renvoie vers cette même commande.

La commande ne déplace **aucun octet** et n'émet aucun appel réseau. Elle est
idempotente : la rejouer sur une instance déjà reprise n'écrit rien. Son
`--dry-run` **rend un code d'échec** tant qu'il reste quelque chose à écrire —
c'est ce qui permet d'enchaîner `files:adopt-locations --dry-run && <bascule>`
sans risquer de basculer une instance non reprise.

Elle **refuse** plutôt que de deviner dans deux cas : deux produits cloud
configurés à la fois, ou un emplacement qui devrait désigner un cloud alors
qu'aucun n'est configuré. L'option `--cloud=` est la sortie — voir
`php artisan help files:adopt-locations`.

> Ce prérequis est **documenté, pas câblé** : `scripts/update.sh` ne le joue pas.
> Voir [`../filesystem/`](../filesystem/README.md) pour le modèle
> complet.

### Actions effectuées

1. ✅ **Mise à jour Composer**
   - Installe les dépendances PHP
   - `--no-dev` pour production
   - `--optimize-autoloader`

2. ✅ **Mise à jour NPM** (optionnel)
   - Install des dépendances frontend
   - Build du bundle webpack/vite
   - Ignoré si `package.json` absent ou npm non installé

3. ✅ **Mise à jour Laravel**
   - Exécute `php artisan sambaedu:app:update`
   - Vérification des variables .env manquantes
   - Affiche les clés à ajouter manuellement

4. ✅ **Mise à jour Apache** (si applicable)
   - Compare les configurations
   - Met à jour si différente
   - Active les modules nécessaires (rewrite, headers)
   - Recharge Apache

5. ✅ **Mise à jour systemd**
   - Copie les fichiers .service
   - Recharge la configuration systemd
   - Redémarre les workers Laravel

### Output

```
[update] Démarrage de la mise à jour...
[update] Mise à jour dépendances Composer...
[✓] Composer OK
[update] Mise à jour dépendances NPM...
[✓] NPM OK
...
═══════════════════════════════════════════════════════════════
Mise à jour terminée avec succès!
═══════════════════════════════════════════════════════════════
✓ Composer
✓ NPM/Build frontend
✓ Laravel update
✓ Apache
✓ Services systemd
```

---

## 🧹 `scripts/cleanup.sh`

**Gestion et nettoyage des services Docker**

### Usage

```bash
sudo ./scripts/cleanup.sh [OPTION]
```

### Options

| Option | Effet | Données |
|--------|-------|---------|
| `--soft` | Arrête les conteneurs | ✅ Conservées |
| `--hard` | Arrête + supprime conteneurs | ✅ Conservées |
| `--full` | Supprime tout (conteneurs + volumes) | ❌ Perdues |
| `--status` | Affiche l'état | - |
| `--help` | Affiche l'aide | - |

### Exemples

**Voir l'état des services:**
```bash
sudo ./scripts/cleanup.sh
# ou
sudo ./scripts/cleanup.sh --status
```

**Arrêter les services** (conserve les données):
```bash
sudo ./scripts/cleanup.sh --soft
```

**Supprimer les conteneurs** (conserve les données):
```bash
sudo ./scripts/cleanup.sh --hard
```

**Suppression complète** (⚠️ PERTE DE DONNÉES):
```bash
sudo ./scripts/cleanup.sh --full
# Demande une confirmation: tapez "yes"
```

### Cas d'usage courants

**Port déjà utilisé:**
```bash
# Option 1: Redémarrer install.sh
sudo ./scripts/install.sh

# Option 2: Nettoyer proprement
sudo ./scripts/cleanup.sh --soft
sudo ./scripts/install.sh
```

**Réinitialiser la base de données:**
```bash
sudo ./scripts/cleanup.sh --hard
sudo ./scripts/install.sh
sudo ./scripts/cleanup.sh --status
# Puis recréer les tables
php artisan migrate:fresh
```

**Suppression complète** (pour redémarrer from scratch):
```bash
sudo ./scripts/cleanup.sh --full
# Cela supprime tous les conteneurs ET les volumes (données)
```

---

## 📝 `scripts/create-env.sh`

**Génération du fichier .env avec clés de sécurité**

### Usage

```bash
bash ./scripts/create-env.sh
```

### Actions effectuées

1. **Copie .env.example → .env**
   ```bash
   cp .env.example .env
   ```

2. **Génère APP_KEY**
   ```bash
   php artisan key:generate
   ```

3. **Génère SE4FS_INSTANCE_ID** (UUID)
   ```bash
   uuidgen  # Si disponible
   ```

4. **Génère SE4FS_INSTANCE_API_KEY**
   ```bash
   se4fs_instance_<random_hex_16>
   ```

5. **Génère REDIS_PASSWORD**
   ```bash
   openssl rand -base64 32 | head -c10
   ```

6. **Charge depuis `/etc/sambaedu/sambaedu.conf`** (si existe)
   - `se4ad_ip` → `SE4AD_IP`
   - `se4ad_etab_ip` → `SE4AD_ETAB_IP`
   - `sql_passwd` → `DB_PASSWORD`
   - `se4_url` → `APP_URL`
   - Les paramètres LDAP (host, port, base_dn, admin user/password, domain)
     sont lus directement par `SambaEduConfig` depuis `/etc/sambaedu/sambaedu.conf`
     sans passer par le `.env` (source de vérité unique).

7. **Charge depuis `/etc/msmtprc`** (si existe)
   - Configuration SMTP automatique
   - `host` → `MAIL_HOST`
   - `port` → `MAIL_PORT`
   - etc.

8. **Charge depuis `/etc/aliases`** (si existe)
   - `root:` → `MAIL_FROM_NAME`

### Variables générées

```bash
APP_KEY=base64:...                                    # Clé chiffrement
SE4FS_INSTANCE_ID=<uuid>                             # UUID unique
SE4FS_INSTANCE_API_KEY=se4fs_instance_<hex16>        # Clé API
REDIS_PASSWORD=<random_10_chars>                     # Mot de passe Redis
```

### Appelé automatiquement

Ce script est généralement appelé automatiquement par `install.sh`:

```bash
bash "$SCRIPT_DIR/create-env.sh"
```

Peut être appelé manuellement:

```bash
bash ./scripts/create-env.sh
```

---

## 📊 Flux d'installation complet

```
┌─────────────────────────────────────────┐
│  sudo ./scripts/install.sh              │
├─────────────────────────────────────────┤
│  ✓ Vérifie les services existants       │
│  ✓ Installe Docker (si absent)          │
│  ✓ Exécute create-env.sh                │
│  ✓ Lance PostgreSQL + Redis             │
│  ✓ Attend les health checks             │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│  composer install                       │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│  npm install && npm run build (opt.)    │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│  php artisan migrate:fresh              │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│  sudo ./scripts/update.sh               │
├─────────────────────────────────────────┤
│  ✓ Met à jour Composer                  │
│  ✓ Build frontend (NPM)                 │
│  ✓ Exécute migrations                   │
│  ✓ Met à jour Apache                    │
│  ✓ Met à jour systemd                   │
└─────────────────────────────────────────┘
```

---

## 🔍 Vérification

### Vérifier que les services tournent

```bash
docker compose ps
# ou
./scripts/cleanup.sh --status
```

### Voir les logs

```bash
# PostgreSQL
docker compose logs -f postgres

# Redis
docker compose logs -f redis

# Tous
docker compose logs -f
```

### Accéder aux services

```bash
# PostgreSQL
docker compose exec postgres psql -U sambaedu -d sambaedu

# Redis
docker compose exec redis redis-cli
```

---

## 🚨 Troubleshooting

### "Port already allocated"

```bash
sudo ./scripts/cleanup.sh --soft
sudo ./scripts/install.sh
```

### "artisan not found"

```bash
# Vérifier que vous êtes dans le répertoire du projet
pwd  # doit être /var/www/sambaedu-reload

# Vérifier artisan existe
ls -la artisan
```

### "Docker not found"

Le script `install.sh` l'installe automatiquement. Sinon:

```bash
curl -fsSL https://get.docker.com | sh
```

Voir [TROUBLESHOOTING.md](./TROUBLESHOOTING.md) pour plus de solutions.
