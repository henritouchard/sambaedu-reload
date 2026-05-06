# QA Manuel — WPKG Deploy

**Domaine** : pipeline déploiement WPKG natif (Epic 15) — channel logs,
namespace `App\Wpkg\Deployment`, tables tracking UUID, atomic write
infrastructure, paramétrage chemins partage.

**Stories couvertes** : 15.1 (fondations). _Stories 15.2 → 15.7 ajoutent
sections au fil de leur livraison (générateurs XML/.ini, sync AD, UI
assignation, ingestion, dashboard, retrait shim legacy)._

**Code de référence** :
- `config/logging.php` — channel `wpkg-deploy`
- `config/sambaedu.php` — bloc `wpkg` (4 chemins en dur)
- `app/Providers/WpkgDeploymentServiceProvider.php` — check démarrage
- `app/Support/AtomicFileWriter.php` — atomic write consolidé
- `app/Wpkg/Deployment/` — namespace pipeline (squelette en 15.1)
- `database/migrations/2026_05_03_*_create_wpkg_deploy*.php` — tables tracking
- `docs/wpkg-deploy/architecture.md` — note technique

---

## Pré-requis communs

- VM SER accessible : `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`
- `scripts/update.sh` exécuté (migrations appliquées)
- User PHP-FPM cible : `www-admin` (vérifier `ps -o user -p $(pgrep -f php-fpm | head -1)`)
- Partages Samba existants (parité legacy) :
  - `/var/sambaedu/unattended/install/wpkg`
  - `/var/sambaedu/unattended/install/wpkg/ini`
  - `/var/sambaedu/unattended/install/wpkg/rapports`
  - `/var/sambaedu/unattended/install/wpkg/rapports/archive`

---

## Section 1 — Fondations Pipeline Déploiement WPKG (Story 15.1)

### Scénario 1.1 — Channel logs `wpkg-deploy` vivant

1. Sur la VM, lancer un tinker :
   ```bash
   php artisan tinker
   >>> Log::channel('wpkg-deploy')->withContext(['deployment_id' => 'qa-smoke-001'])->info('smoke test');
   >>> exit
   ```
   _Tinker affiche `null` — c'est normal, `Log::info()` ne retourne rien.
   Le succès se vérifie côté filesystem (étapes suivantes), pas dans
   tinker. Une vraie erreur de config Monolog lèverait une exception
   explicite type `InvalidArgumentException: Log [wpkg-deploy] is not
   defined`._
2. Vérifier que `storage/logs/wpkg-deploy/deploy-{date}.log` existe :
   ```bash
   ls -la storage/logs/wpkg-deploy/
   ```
3. `tail -1 storage/logs/wpkg-deploy/deploy-*.log` → la ligne contient
   `smoke test` ET `"deployment_id":"qa-smoke-001"` dans le payload JSON.

**Attendu** : fichier créé, ligne tracée, contexte `deployment_id` propagé
dans le payload Monolog. Format ligne typique :
`[2026-05-04 14:32:15] production.INFO: smoke test {"deployment_id":"qa-smoke-001"}`

**Pourquoi ce scénario** : valider qu'aucune erreur de config Monolog ne
casse silencieusement le routage (la suite des logs Story 15.2+ part de là).

### Scénario 1.2 — Migrations UP / DOWN

1. `php artisan migrate:status | grep wpkg_deploy`
   → 2 lignes `Y` (Ran) attendues.
2. `php artisan migrate:rollback --step=2`
3. `php artisan migrate:status | grep wpkg_deploy`
   → 2 lignes `N` (Pending).
4. `php artisan migrate`
   → les 2 migrations rejouent sans erreur.

**Attendu** : aucune erreur SQL Postgres, les UUID PK et FK UUID se posent
correctement, les indexes `wdws_deploy_ws_idx` et `wdws_ws_reported_idx` sont
créés (vérifier via `psql -c "\d wpkg_deployment_workstation_status"`).

### Scénario 1.3 — Atomic write Firefox / Thunderbird (non-régression)

1. Aller sur `/app/parc/customizations/firefox/<id>/edit`, modifier la
   homepage et sauver.
2. Sur la VM, vérifier le fichier exporté. Le chemin est
   `<fs_base_path>/<kind>/<key>.json` où :
   - `fs_base_path` = `config('app-customizations.fs_base_path')` (par défaut
     `/etc/sambaedu/applications`, override via `APP_CUSTOMIZATIONS_FS_BASE`)
   - `<kind>` = `firefox`
   - `<key>` = `default` pour le scope global (NULL/NULL, is_default=true),
     `<owner->login>` pour un User, `<owner->name>` pour UserGroup /
     WorkstationGroup.

   En pratique pour le scope global :
   `/etc/sambaedu/applications/firefox/default.json`.
   _Attention : `app-customizations.template_paths.firefox` pointe au
   contraire sur le template système en lecture seule
   (`/usr/share/sambaedu/applications/firefox/default.json`) — ce n'est
   PAS le fichier écrit par `savePolicies()`._
3. `cat <path>/<key>.json | jq .` → JSON valide.
4. Pendant l'écriture, en parallèle dans un autre terminal, faire des reads
   en boucle :
   ```bash
   while true; do cat <path>/<key>.json > /dev/null && echo OK || echo PARTIAL; done
   ```
5. Sauver plusieurs fois la customization → aucune lecture `PARTIAL`.

**Attendu** : aucune lecture partielle, le rename atomique tient. Le tmp
intermédiaire (`.policies.json.tmp.<pid>.<hex>`) ne doit jamais être visible
plus d'un instant et ne pas subsister après l'écriture.

**Pourquoi ce scénario** : la consolidation Story 15.1 a remplacé l'ancien
`AppCustomization\Support\AtomicFileWriter` par `App\Support\AtomicFileWriter`
avec ajout du suffixe `pid` et `fsync`. Régression possible : permission /
chmod / encoding du fichier exporté.

### Scénario 1.4 — Rename clés config sans casse runtime

1. Sur la VM, vérifier que les anciennes clés ne sont **plus** consommées :
   ```bash
   grep -rn "reports_path\|reports_archive_path" /var/www/sambaedu-reload/app /var/www/sambaedu-reload/config
   ```
   → aucun résultat (ou uniquement nom de tests).
2. Lancer la commande de traitement des rapports avec un fichier de test :
   ```bash
   echo "DUMMY" > /var/sambaedu/unattended/install/wpkg/rapports/QA-PC.txt
   touch -d "1 minute ago" /var/sambaedu/unattended/install/wpkg/rapports/QA-PC.txt
   php artisan wpkg:process-reports
   ```
3. Le rapport doit être archivé dans
   `/var/sambaedu/unattended/install/wpkg/rapports/archive/QA-PC_<timestamp>.txt`
   (chemin défini par `config('sambaedu.wpkg.reports_archive')`).
4. Vérifier le `.env` de production : retirer les éventuelles variables
   `WPKG_REPORTS_PATH` et `WPKG_REPORTS_ARCHIVE_PATH` qui ne sont **plus
   consommées** (cf. `docs/wpkg-deploy/architecture.md` § Migration `.env`).

**Attendu** : ingestion fonctionnelle, archivage OK, aucun warning
`reports_path invalide` dans le channel `wpkg-deploy` ni dans `laravel.log`.

### Scénario 1.5 — Check démarrage `WpkgDeploymentServiceProvider`

Le ServiceProvider tente de **créer automatiquement** les chemins manquants
(`mkdir -p`, mode `0755`) et ne log un warning que si la création échoue
ou si les permissions résultantes restent insuffisantes. Les admins SER
n'ont donc pas à provisionner manuellement l'arborescence.

**1.5a — Création auto d'un dossier manquant**

1. Supprimer un partage : `rm -rf /var/sambaedu/unattended/install/wpkg/rapports/archive`
2. Recharger : `php artisan config:clear && curl -sf http://localhost/ > /dev/null`
3. Vérifier que le dossier a été recréé : `ls -ld /var/sambaedu/unattended/install/wpkg/rapports/archive`
4. `tail storage/logs/wpkg-deploy/deploy-*.log`

**Attendu** : dossier recréé (mode `755`, owner = user PHP-FPM `www-admin`),
**aucun warning** dans le channel `wpkg-deploy`.

**1.5b — Création impossible (permission insuffisante sur le parent)**

1. Sauvegarder la config et basculer vers un chemin dont le parent est
   non-écrivable :
   ```bash
   php artisan tinker
   >>> Config::set('sambaedu.wpkg.deploy_path', '/root/wpkg-test-readonly');
   >>> $provider = new App\Providers\WpkgDeploymentServiceProvider(app());
   >>> $violations = $provider->ensurePaths(['sambaedu.wpkg.deploy_path']);
   >>> foreach ($violations as $v) { Log::channel('wpkg-deploy')->warning('[wpkg-deploy] chemin partage inaccessible', $v); }
   >>> exit
   ```
2. `tail storage/logs/wpkg-deploy/deploy-*.log`

**Attendu** : warning `[wpkg-deploy] chemin partage inaccessible` avec
`create_attempted=true`, `create_succeeded=false`, `exists=false`. Le boot
Laravel n'est pas bloqué.

---

## Checklist rapide (relecteur)

- [ ] Scénario 1.1 — channel `wpkg-deploy` vivant
- [ ] Scénario 1.2 — migrations UP / DOWN clean
- [ ] Scénario 1.3 — atomic write Firefox/Thunderbird sans lecture partielle
- [ ] Scénario 1.4 — rename clés config OK, ingestion rapports OK
- [ ] Scénario 1.5 — warning chemins inaccessibles tracé en logs
