# Gestion des Workers Laravel (Services Systemd)

## Architecture

L'application utilise **2 services systemd distincts** pour éviter les conflits de concurrence et les deadlocks PostgreSQL :

### 1. Worker GÉNÉRAL (`laravel-queue-general`)
- **Queues** : `default`, `high`, `low`
- **Usage** : Jobs standards (création/modification/suppression d'entités, etc.)
- **Service** : `/etc/systemd/system/laravel-queue-general.service`

### 2. Worker SYNC (`laravel-queue-sync`)
- **Queue** : `sync`
- **Usage** : Synchronisations AD → SQL (users, groups, workstation_groups, etc.)
- **Service** : `/etc/systemd/system/laravel-queue-sync.service`

---

## Commandes de gestion

### Démarrer les workers

```bash
# Worker général
sudo systemctl start laravel-queue-general

# Worker sync
sudo systemctl start laravel-queue-sync

# Les 2 en même temps
sudo systemctl start laravel-queue-general laravel-queue-sync
```

### Arrêter les workers

```bash
# Worker général
sudo systemctl stop laravel-queue-general

# Worker sync
sudo systemctl stop laravel-queue-sync

# Les 2 en même temps
sudo systemctl stop laravel-queue-general laravel-queue-sync
```

### Redémarrer les workers

```bash
# Worker général
sudo systemctl restart laravel-queue-general

# Worker sync
sudo systemctl restart laravel-queue-sync

# Les 2 en même temps
sudo systemctl restart laravel-queue-general laravel-queue-sync
```

### Vérifier le statut

```bash
# Worker général
sudo systemctl status laravel-queue-general

# Worker sync
sudo systemctl status laravel-queue-sync

# Les 2 en même temps
sudo systemctl status laravel-queue-*
```

### Activer au démarrage

```bash
# Activer les 2 services pour qu'ils démarrent automatiquement au boot
sudo systemctl enable laravel-queue-general
sudo systemctl enable laravel-queue-sync
```

### Désactiver au démarrage

```bash
# Désactiver le démarrage automatique
sudo systemctl disable laravel-queue-general
sudo systemctl disable laravel-queue-sync
```

---

## Logs

### Voir les logs en temps réel

```bash
# Worker général
sudo journalctl -u laravel-queue-general -f

# Worker sync
sudo journalctl -u laravel-queue-sync -f

# Les 2 en même temps
sudo journalctl -u laravel-queue-* -f
```

### Voir les dernières lignes

```bash
# 50 dernières lignes du worker général
sudo journalctl -u laravel-queue-general -n 50

# 50 dernières lignes du worker sync
sudo journalctl -u laravel-queue-sync -n 50
```

### Filtrer par période

```bash
# Logs depuis aujourd'hui
sudo journalctl -u laravel-queue-general --since today

# Logs des dernières 2 heures
sudo journalctl -u laravel-queue-sync --since "2 hours ago"

# Logs entre 2 dates
sudo journalctl -u laravel-queue-general --since "2026-02-26 10:00:00" --until "2026-02-26 12:00:00"
```

---

## Monitoring depuis l'interface web

L'interface `/app/workers` affiche en **lecture seule** :
- ✅ Liste des workers actifs (PID, uptime, queues)
- ✅ Nombre de jobs en attente/échoués
- ✅ Logs système (journalctl)
- ✅ Détails des tâches (queued/running/done)

**Gestion start/stop/restart** : **SSH uniquement** (sécurité).

---

## Installation automatique

Le script `setup.sh` configure automatiquement les 2 services :

```bash
cd /root/se4/sources/var/www/sambaedu/laravel
sudo ./scripts/setup.sh
```

Ce script :
1. Crée les 2 fichiers de service systemd
2. Recharge la configuration systemd (`daemon-reload`)
3. Active les services au démarrage (`enable`)
4. Démarre les services immédiatement (`start`)

---

## Dépannage

### Les workers ne démarrent pas

```bash
# Vérifier les erreurs dans les logs
sudo journalctl -u laravel-queue-general -n 100
sudo journalctl -u laravel-queue-sync -n 100

# Vérifier la configuration du service
sudo systemctl cat laravel-queue-general
sudo systemctl cat laravel-queue-sync

# Recharger la configuration systemd
sudo systemctl daemon-reload

# Redémarrer les services
sudo systemctl restart laravel-queue-general laravel-queue-sync
```

### Les jobs ne sont pas traités

```bash
# Vérifier que les workers sont actifs
sudo systemctl is-active laravel-queue-general
sudo systemctl is-active laravel-queue-sync

# Vérifier les jobs en attente
php artisan queue:work database --queue=default --once

# Vérifier la table jobs
php artisan tinker --execute="echo DB::table('jobs')->count().' jobs en attente'.PHP_EOL;"
```

### Deadlocks PostgreSQL

Si tu rencontres des deadlocks, ajoute des timeouts PostgreSQL :

```sql
-- Se connecter à PostgreSQL
docker exec -i se4fs_postgres psql -U sambaedu -d sambaedu

-- Ajouter les timeouts
alter role sambaedu set lock_timeout = '3s';
alter role sambaedu set idle_in_transaction_session_timeout = '30s';
alter role sambaedu set statement_timeout = '15s';
```

---

## Architecture technique

### Fichiers de service

**`/etc/systemd/system/laravel-queue-general.service`** :
```ini
[Unit]
Description=Laravel Queue Worker (General) for SE4FS
After=network.target mysql.service mariadb.service postgresql.service

[Service]
User=www-admin
Group=www-admin
Restart=always
RestartSec=3
WorkingDirectory=/root/se4/sources/var/www/sambaedu/laravel
ExecStart=/usr/bin/php8.2 artisan queue:work database --queue=default,high,low --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

**`/etc/systemd/system/laravel-queue-sync.service`** :
```ini
[Unit]
Description=Laravel Queue Worker (Sync) for SE4FS
After=network.target mysql.service mariadb.service postgresql.service

[Service]
User=www-admin
Group=www-admin
Restart=always
RestartSec=3
WorkingDirectory=/root/se4/sources/var/www/sambaedu/laravel
ExecStart=/usr/bin/php8.2 artisan queue:work database --queue=sync --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

### Paramètres importants

- `--queue=xxx` : Isolation stricte des queues (évite les conflits)
- `--sleep=3` : Attend 3s entre chaque vérification si pas de job
- `--tries=3` : Réessaie 3 fois un job en échec
- `--max-time=3600` : Redémarre le worker après 1h (évite fuites mémoire)
- `Restart=always` : Redémarre automatiquement si crash
- `RestartSec=3` : Attend 3s avant de redémarrer

---

## Sécurité

**Pourquoi pas de gestion depuis l'interface web ?**

Donner des droits `sudo systemctl` à `www-admin` expose à des escalades de privilèges si l'application est compromise.

**Solution retenue** :
- Interface web : **monitoring en lecture seule**
- Gestion workers : **SSH uniquement** (admin système)

Cette approche garantit que seuls les administrateurs système peuvent démarrer/arrêter les workers.
