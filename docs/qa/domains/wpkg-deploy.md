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

## Section 2 — Generators XML/INI per-poste (Story 15.2)

**Stories couvertes** : 15.2 (endpoints HTTP `hosts.xml` / `profiles.xml`,
service `WorkstationPackagesResolver` Eloquent-only, cache key-value 1000 s,
events + listeners d'invalidation, `WorkstationIniGenerator`, 3 commandes
Artisan utilitaires).

**Code de référence** :
- `app/Wpkg/Deployment/Http/Controllers/{Hosts,Profiles}XmlController.php`
- `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php`
- `app/Wpkg/Deployment/Generators/WorkstationIniGenerator.php`
- `app/Wpkg/Deployment/Events/*` + `Listeners/*`
- `app/Console/Commands/Wpkg{Cache{Warmup,Flush},IniRegenerate}Command.php`
- `routes/web.php` — bloc `Story 15.2 — Endpoints HTTP WPKG`
- Migrations `2026_05_05_100000_add_wpkg_resolver_pivots.php` + `2026_05_05_100100_create_wpkg_workstation_options_table.php`

### Pré-requis section 2

- Section 1 validée (canal `wpkg-deploy` vivant, migrations 15.1 OK).
- Ces 2 migrations 15.2 jouées : `php artisan migrate:status | grep wpkg`.
- Au moins une `Workstation` avec un `name` (hostname) en BDD pour les
  scénarios 2.3 / 2.4.

### Scénario 2.1 — `?poste=HOSTNAME` valide → XML conforme

1. Sur la VM : `curl -s "http://localhost/wpkg/hosts.xml?poste=PCEXEMPLE"`.
2. Vérifier que la réponse :
   - HTTP 200, `Content-Type: text/xml; charset=UTF-8`
   - contient `<host name="PCEXEMPLE" profile-id="PCEXEMPLE"/>`
   - contient le commentaire ` Fichier genere par SambaEdu. Ne pas modifier. `
   - parse correctement avec `xmllint --noout -`
3. Idem `curl -s "http://localhost/wpkg/profiles.xml?poste=PCEXEMPLE"`.

**Attendu** : XML strictement conforme à
`tests/Fixtures/wpkg/expected/hosts-PCEXEMPLE.xml` (à divergence whitespace
près). Aucune redirect / 401 / 403 (parité legacy AC1.5).

### Scénario 2.2 — Hostname inconnu → profile vide silencieux

1. Choisir un hostname jamais inscrit en BDD : `NOPOSTEDB`.
2. `curl -s "http://localhost/wpkg/profiles.xml?poste=NOPOSTEDB"`.

**Attendu** : HTTP 200, body contient `<profile id="NOPOSTEDB"/>` sans
aucun `<package…/>`. Pas de 404 (parité legacy AC1.3).

3. Idem `hosts.xml?poste=NOPOSTEDB` → renvoie quand même
   `<host name="NOPOSTEDB" profile-id="NOPOSTEDB"/>` (legacy parity AC1.3 :
   `hosts_xml_out.php` ne consulte pas la BDD).

### Scénario 2.3 — Poste avec apps directes + parc → vérif union

1. Tinker :
   ```php
   $w = \App\Models\Workstation::firstWhere('name', '<HOSTNAME>');
   $g = $w->groups->first();
   $a1 = \App\Models\Application::firstWhere('app_id', '<APPID_DIRECT_POSTE>');
   $a2 = \App\Models\Application::firstWhere('app_id', '<APPID_PARC>');
   $w->applications()->syncWithoutDetaching([$a1->id]);
   $g->applications()->syncWithoutDetaching([$a2->id]);
   ```
2. `php artisan wpkg:cache:flush --workstation=<HOSTNAME>` (purge cache).
3. `curl -s "http://localhost/wpkg/profiles.xml?poste=<HOSTNAME>"`.

**Attendu** : la réponse contient `<package package-id="<APPID_DIRECT_POSTE>"/>`
ET `<package package-id="<APPID_PARC>"/>`. Si une dépendance applicative
existe (table `application_dependencies`), elle apparaît également.
La liste est triée alpha ASC (déterminisme).

### Scénario 2.4 — Dispatch event manuel → cache invalidé → second appel reflète la modif

1. Pré-requis : poste `<HOSTNAME>` avec quelques packages.
2. `curl -s "http://localhost/wpkg/profiles.xml?poste=<HOSTNAME>" > /tmp/avant.xml`.
3. Vérifier que `Cache::has("wpkg:packages:<lower(HOSTNAME)>")` retourne `true`
   en tinker.
4. Modifier l'assignation :
   ```php
   $w->applications()->detach($a1);
   event(new \App\Wpkg\Deployment\Events\AppProfileWorkstationChanged(0, $w->id, 'detached'));
   // Note : l'event AppProfileWorkstationChanged invalide bien le poste cible
   // (`workstationId`). Pour un detach `Application` directe, on peut
   // également utiliser `WorkstationGroupMembershipChanged` ou
   // `WorkstationActivated/Archived` (tous routent vers le poste cible).
   ```
5. Vérifier que `Cache::has("wpkg:packages:<lower(HOSTNAME)>")` retourne `false`.
6. `curl -s "http://localhost/wpkg/profiles.xml?poste=<HOSTNAME>" > /tmp/apres.xml`.
7. `diff /tmp/avant.xml /tmp/apres.xml` → la nouvelle composition est servie.

**Attendu** : pas de stale. La log `wpkg-deploy` contient une ligne
`[InvalidateWorkstationPackagesCache] cache invalidé` et une ligne
`[WorkstationPackagesResolver] cache miss` au scénario 6.

> **Note importante** : l'émetteur réel des events (services métier /
> observers Eloquent / UI) est livré en **Story 15.4**. Ce scénario de QA
> dispatche les events manuellement pour tester le câblage.

### Scénario 2.5 — `WorkstationOptionsChanged` → `.ini` régénéré

1. Pré-requis : `<HOSTNAME>` connu, fichier
   `<config('sambaedu.wpkg.ini_path')>/<HOSTNAME>.ini` initialement absent
   ou aux valeurs `false` par défaut.
2. Tinker :
   ```php
   $w = \App\Models\Workstation::firstWhere('name', '<HOSTNAME>');
   \App\Wpkg\Deployment\Models\WpkgWorkstationOption::updateOrCreate(
       ['workstation_id' => $w->id, 'option_key' => 'debug'],
       ['option_value' => 'true']
   );
   event(new \App\Wpkg\Deployment\Events\WorkstationOptionsChanged($w->id, ['debug']));
   ```
3. Inspecter le fichier `.ini` correspondant :
   `cat /var/sambaedu/unattended/install/wpkg/ini/<HOSTNAME>.ini | xxd | head -2`.

**Attendu** :
- 8 lignes terminées par `\r\n` (CRLF strict, parité legacy AC5.4).
- 1ère ligne `debug=true ' Permet d'avoir des logs plus détaillés.\r\n`.
- Les 7 autres lignes `…=false ' …\r\n` (defaults).
- Atomic write : aucune persistance d'un `.tmp.<pid>.<hex>` à côté.
- Logs `wpkg-deploy` : ligne `Génération .ini` avec
  `workstation_id` + `hostname` + `target` + `success=true`.

4. Régénération idempotente : `php artisan wpkg:ini:regenerate --workstation=<HOSTNAME>`
   exécuté 2× → `md5sum <path>` identique.

### Commandes Artisan utilitaires

- `php artisan wpkg:cache:warmup --all` — pré-calcul cache pour tous les postes
  (progress bar). Utile post-flush ou après une mutation bulk.
- `php artisan wpkg:cache:flush [--workstation=H]` — vide le cache (ciblé ou
  global) ; équivalent legacy `apcu_delete_multi('#^wpkg_#')` mais ciblé.
- `php artisan wpkg:ini:regenerate --all` — régénère le `.ini` per-poste
  (utile après bascule prod ou changement de format).

---

---

## Section 3 — Remédiation drift `SyncAllFromAdJob` (Story 15.3)

**Stories couvertes** : 15.3 (modèle Eloquent suffisant + outil de
remédiation drift). Sync AD → Eloquent **manuelle uniquement** (pas de
cron — décision de cadrage 2026-05-05/06).

**Code de référence** :
- `app/Jobs/SyncAllFromAdJob.php` — job durci (dry-run, lock, 2 passes,
  archivage, idempotence, match strict premier run).
- `app/Console/Commands/SyncFromAd.php` — CLI `php artisan sync:from-ad [--dry-run]`.
- `resources/views/pages/sync-from-ad/index.blade.php` — UI Livewire,
  étape « 10. Remédiation drift WPKG » (boutons Aperçu / Exécuter).
- `config/sambaedu.php` § `wpkg.sync` — `lock_ttl_seconds` (défaut 600),
  `dry_run_default` (défaut false).
- `database/migrations/2026_05_06_*` — colonnes lifecycle (15.3 / AC2.1).

### Pré-requis Section 3

- Migrations 15.3 jouées :
  ```bash
  php artisan migrate:status | grep "add_lifecycle_attrs"
  ```
  → 2 lignes `Y` (Ran).
- Au moins un parc AD (`OU=parc-test,OU=Parcs,…`) accessible via le
  contexte établissement courant (vérifier la liste déroulante en haut
  de `/admin/sync-from-ad`).

### Scénario 3.1 — Aperçu (dry-run) sans écriture

1. Aller sur `/admin/sync-from-ad`, étape « 10. Remédiation drift WPKG ».
2. Cliquer **« Aperçu »**.
3. Constater dans les logs de l'étape (volet déroulant) :
   - Lignes `[DRY-RUN] WorkstationGroups : +N / ~N / archivés N / ignorés N`.
   - Status icone passe à `dry_run_done` (loupe orange).
4. Vérifier en base que **rien n'a été écrit** :
   ```bash
   psql -c "SELECT COUNT(*) FROM workstation_groups WHERE archived_at IS NOT NULL;"
   ```
   → identique avant/après l'Aperçu.

**Attendu** : aucune mutation SQL, le rapport stats remonté affiche les
counters `created/updated/archived` qui **auraient été** appliqués.

### Scénario 3.2 — Exécuter (apply) après Aperçu

1. Après scénario 3.1, cliquer **« Exécuter »**.
2. Lignes log sans préfixe `[DRY-RUN]`.
3. Status icone passe à `success` (✓ vert) ou `success` avec message
   « idempotent : aucune écriture nécessaire ».
4. `tail storage/logs/wpkg-deploy/deploy-*.log` :
   - Lignes `[SyncAllFromAd] Démarrage de la synchronisation` avec
     `run_id` (UUID) + `dry_run=false`.
   - Si archivage : ligne `warning [SyncAllFromAd] WorkstationGroup archivé`
     avec `id`, `name`, `ad_guid` en context.

**Attendu** : commit transactionnel, observers réactivés en `finally`
(le test est : un `WorkstationGroup::create()` ultérieur déclenche bien
un `*AdSyncJob` sortant).

### Scénario 3.3 — Anti-double-clic (lock)

1. Sur `/admin/sync-from-ad`, ouvrir 2 onglets.
2. Cliquer « Exécuter » dans les 2 onglets simultanément.
3. Le 2e clic doit afficher un toast info « Sync déjà en cours » et
   l'étape passer en status `skipped` (icone forward bleu).

**Attendu** : log `info [SyncAllFromAd] Lock non acquis — synchronisation
déjà en cours, skip.` Pas de double exécution. Le lock est libéré en
`finally` (en CLI : `php artisan sync:from-ad` 2 fois → 2e dit
« Synchronisation déjà en cours — exécution sautée »).

### Scénario 3.4 — Idempotence

1. Lancer 2 fois **« Exécuter »** consécutifs (sans changement AD entre
   les deux).
2. Le 2e run doit afficher « Aucune écriture nécessaire (idempotent). »

**Attendu** : 2e run = 0 `created` / 0 `updated` / 0 `archived`. Logs en
mode `info` final uniquement (pas de `debug` par row).

### Scénario 3.5 — AD partiel mid-pass (atomicité 2 passes)

> Test difficile à reproduire en QA manuel — couvert par tests feature
> (`SyncAllFromAdJobTest::pass1_failure_aborts_without_writes`).
> Procédure manuelle si besoin :

1. Bloquer temporairement la lecture `OU=Computers` (par ex. via firewall
   sortant ou pause du domaine SambaEdu).
2. Cliquer « Exécuter ».
3. L'étape doit passer en `error` avec message `pass1_failed: ...`.
4. Vérifier qu'**aucun archivage n'a eu lieu** : les rows DB GUID-ées
   restent `archived_at = NULL` malgré la lecture AD partielle.

**Attendu** : atomicité stricte. La passe 2 ne démarre pas si la passe 1
a échoué.

### Scénario 3.6 — Premier run post-migration prod (peuplement `ad_guid`)

> Procédure ops finale de bascule prod (runbook 15.7).

1. Sur une base SQL où certains `WorkstationGroup` / `AppProfile` ont
   `ad_guid IS NULL` (cas typique post-migration legacy → reload) :
   ```bash
   psql -c "SELECT name, ad_guid FROM workstation_groups WHERE ad_guid IS NULL LIMIT 5;"
   ```
2. Cliquer **« Aperçu »** sur l'étape 10 → vérifier dans les logs que
   les rows attendues remontent en `~updated` (les `ad_guid` seront posés).
3. Cliquer **« Exécuter »**.
4. Re-vérifier :
   ```bash
   psql -c "SELECT name, ad_guid FROM workstation_groups WHERE ad_guid IS NULL;"
   ```
   → uniquement les groupes vraiment absents AD (à archiver manuellement
   ou à laisser en l'état si bootstrap incomplet).

**Attendu — match strict** : un nom homonyme dans deux OU différentes
(ex. un parc « pc01 » sous OU=Parcs **et** un groupe « pc01 » sous
OU=Computers) ne doit jamais matcher cross-OU. Si le DN AD ne contient
pas `,OU=Computers,`, le match nom est refusé pour les
`WorkstationGroup`. Si le DN ne contient pas `,OU=Parcs,`, refusé pour
les `AppProfile`. Aucun `ad_guid` mauvais ne doit être écrit.

### Scénario 3.7 — Conflit `objectGUID` (corruption historique)

> Cas R3 du tableau de risques.

Si la base contient deux rows DB avec le **même `ad_guid`** (corruption
historique) :

1. La query `WorkstationGroup::whereNotNull('ad_guid')->get()->keyBy('ad_guid')`
   conserve la dernière (perte silencieuse). Le job ne crashe pas mais
   produit un mismatch.

**Procédure de remédiation** :
```sql
-- Identifier les doublons
SELECT ad_guid, COUNT(*) AS n
FROM workstation_groups
WHERE ad_guid IS NOT NULL
GROUP BY ad_guid
HAVING COUNT(*) > 1;

-- Pour chaque doublon : choisir la row à conserver, désaffecter l'autre
UPDATE workstation_groups SET ad_guid = NULL, ad_dn = NULL
 WHERE id = <id_de_la_row_perdante>;
```

Puis relancer **« Aperçu »** → le dédoublonnage se fera sans risque (le
match strict premier run rebindera proprement).

### Scénario 3.8 — Restauration d'une row archivée

1. Identifier une row archivée :
   ```sql
   SELECT id, name FROM workstation_groups WHERE archived_at IS NOT NULL;
   ```
2. Si l'entité réapparaît dans l'AD → le prochain run du job la
   **restaure automatiquement** (`archived_at = NULL` + counter
   `updated`). Cf. test `SyncAllFromAdJobTest::archived_row_is_restored_when_reappears_in_ad`.
3. Restauration manuelle possible :
   ```sql
   UPDATE workstation_groups SET archived_at = NULL WHERE id = ?;
   ```

**Attendu** : la row redevient visible en chemin critique (resolver,
listings UI). Le pipeline 15.2 honore le scope `notArchived()` (cf.
décision D8).

### Checklist rapide Section 3 (relecteur)

- [ ] Scénario 3.1 — Aperçu sans écriture
- [ ] Scénario 3.2 — Exécuter applique avec logs structurés `wpkg-deploy`
- [ ] Scénario 3.3 — Lock anti-double-clic skip + toast
- [ ] Scénario 3.4 — Idempotence 2e run no-op silencieux
- [ ] Scénario 3.5 — AD partiel mid-pass : 0 écriture (atomicité)
- [ ] Scénario 3.6 — Premier run post-migration peuple `ad_guid` sans faux positifs cross-OU
- [ ] Scénario 3.7 — Procédure conflit `objectGUID` documentée
- [ ] Scénario 3.8 — Restauration archived auto + manuelle

---

## Checklist rapide (relecteur)

- [ ] Scénario 1.1 — channel `wpkg-deploy` vivant
- [ ] Scénario 1.2 — migrations UP / DOWN clean
- [ ] Scénario 1.3 — atomic write Firefox/Thunderbird sans lecture partielle
- [ ] Scénario 1.4 — rename clés config OK, ingestion rapports OK
- [ ] Scénario 1.5 — warning chemins inaccessibles tracé en logs
- [ ] Scénario 2.1 — `?poste=` valide → XML conforme (hosts + profiles)
- [ ] Scénario 2.2 — hostname inconnu → profile vide silencieux (pas de 404)
- [ ] Scénario 2.3 — union poste/parc/dépendances dans `profiles.xml`
- [ ] Scénario 2.4 — invalidation cache via event → pas de stale
- [ ] Scénario 2.5 — `WorkstationOptionsChanged` → `.ini` régénéré (CRLF)
- [ ] Scénarios 3.1 → 3.8 — remédiation drift `SyncAllFromAdJob` (Story 15.3)
