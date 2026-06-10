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

1. Sur la VM : `curl -s -v "http://localhost/wpkg/hosts.xml?poste=PCEXEMPLE"`.
2. Vérifier que la réponse :
   - HTTP 200, `Content-Type: text/xml; charset=UTF-8`
   - contient `<host name="PCEXEMPLE" profile-id="PCEXEMPLE"/>`
   - contient le commentaire `Fichier genere par SambaEdu. Ne pas modifier.`
   - parse correctement : `curl -s "<http://se4fs/wpkg/hosts.xml?poste=pc-cdi-07>" | xmllint --noout -`
3. Idem `curl -s "http://localhost/wpkg/profiles.xml?poste=PCEXEMPLE"`.

**Attendu** : XML strictement conforme à
`tests/Fixtures/wpkg/expected/hosts-PCEXEMPLE.xml` (à divergence whitespace
près). Aucune redirect / 401 / 403 (parité legacy AC1.5).

### Scénario 2.2 — Hostname inconnu → profile vide silencieux

1. Choisir un hostname jamais inscrit en BDD : `NOPOSTEDB`.
2. `curl -s "http://localhost/wpkg/profiles.xml?poste=NOPOSTEDB"`.

**Attendu** : HTTP 200, body contient `<profile id="NOPOSTEDB"/>` sans
aucun `<package…/>`. Pas de 404 (parité legacy AC1.3).

1. Idem `hosts.xml?poste=NOPOSTEDB` → renvoie quand même
   `<host name="NOPOSTEDB" profile-id="NOPOSTEDB"/>` (legacy parity AC1.3 :
   `hosts_xml_out.php` ne consulte pas la BDD).

### Scénario 2.3 — Poste avec apps directes + parc → vérif union

**Poste de référence** : `NOM_DU_POSTE` (membre du groupe `windows-all`).

1. Dans l'interface SambaEdu, aller sur **Paramètres du parc**
   (`/app/parc-settings`) → onglet **Profils** → ouvrir le profil `dev-tools`.

   - Onglet **Postes** → **Ajouter** → sélectionner `NOM_DU_POSTE` → **Confirmer**.

   \_Résultat attendu : `NOM_DU_POSTE` est maintenant assigné directement au
   profil `dev-tools` (apps : `notepadpp`, `vscode`, `python3`) — source 1
   (AppProfile direct poste). Le profil `base-windows` est déjà assigné au
   groupe `windows-all`, ce qui couvre `NOM_DU_POSTE` via source 2 (apps :
   `firefox`, `libreoffice`, `vlc`, `7zip`).

2. Sur la VM, purger le cache :

   ```bash
   php artisan wpkg:cache:flush --workstation=NOM_DU_POSTE
   ```

3. `curl -s "http://localhost/wpkg/profiles.xml?poste=NOM_DU_POSTE"`.

**Attendu** : la réponse contient à la fois `<package package-id="notepadpp"/>`
(depuis `dev-tools` direct sur le poste) ET `<package package-id="firefox"/>`
(depuis `base-windows` sur le groupe `windows-all`). La liste est triée alpha
ASC (déterminisme).

_Nettoyage après test : retirer `NOM_DU_POSTE` de `dev-tools` via l'onglet
**Postes** du même profil._

### Scénario 2.4 — Dispatch event manuel → cache invalidé → second appel reflète la modif

1. Pré-requis : poste `<HOSTNAME>` avec au moins un AppProfile assigné
   (directement ou via groupe), donc `profiles.xml?poste=<HOSTNAME>` retourne
   au moins un `<package…/>`.
2. `curl -s "http://localhost/wpkg/profiles.xml?poste=<HOSTNAME>" > /tmp/avant.xml`
   (ce hit HTTP **doit** précéder l'étape 3 — c'est lui qui peuple le cache).
3. Vérifier que la clé cache existe en tinker. La clé est
   `wpkg:packages:` + `strtolower($hostname)` (cf.
   `WorkstationPackagesResolver::cacheKey()`). Pour `pc-cdi-07` →
   `wpkg:packages:pc-cdi-07` (déjà en minuscules) :

   ```bash
   php artisan tinker --execute='echo Cache::has("wpkg:packages:pc-cdi-07") ? "HIT" : "MISS";'
   ```

   → attendu `HIT`.
4. Modifier l'assignation **via l'UI** : aller sur **Paramètres du parc**
   (`/app/parc-settings`) → onglet **Profils** → ouvrir un profil actuellement
   assigné à `<HOSTNAME>` → onglet **Postes** → retirer `<HOSTNAME>` de la
   liste → **Confirmer**.
5. Dispatcher l'event manuellement (l'émetteur réel arrive en Story 15.4) :

   ```php
   php artisan tinker
   >>> $w = \App\Models\Workstation::firstWhere('name', '<HOSTNAME>');
   >>> event(new \App\Wpkg\Deployment\Events\AppProfileWorkstationChanged(0, $w->id, 'detached'));
   >>> exit
   ```

   _Note : `AppProfileWorkstationChanged` invalide bien le poste cible
   (`workstationId`). Pour un changement de groupe ou une (dé)activation, on
   peut également utiliser `WorkstationGroupMembershipChanged` ou
   `WorkstationActivated/Archived` (tous routent vers le poste cible)._

6. Vérifier que la clé cache a été invalidée :

   ```bash
   php artisan tinker --execute='echo Cache::has("wpkg:packages:pc-cdi-07") ? "HIT" : "MISS";'
   ```

   → attendu `MISS` (remplacer `pc-cdi-07` par `strtolower(<HOSTNAME>)`).
7. `curl -s "http://localhost/wpkg/profiles.xml?poste=<HOSTNAME>" > /tmp/apres.xml`.
8. `diff /tmp/avant.xml /tmp/apres.xml` → la nouvelle composition est servie.

**Attendu** : pas de stale. La log `wpkg-deploy` contient une ligne
`[InvalidateWorkstationPackagesCache] cache invalidé` et une ligne
`[WorkstationPackagesResolver] cache miss` au scénario 6.

> **Note importante** : l'émetteur réel des events (services métier /
> observers Eloquent / UI) est livré en **Story 15.4**. Ce scénario de QA
> dispatche les events manuellement pour tester le câblage.

### Scénario 2.5 — `WorkstationOptionsChanged` → `.ini` régénéré

curl -s "<http://se4fs/wpkg/profiles.xml?poste=pc-cdi-07>"

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

1. Régénération idempotente : `php artisan wpkg:ini:regenerate --workstation=<HOSTNAME>`
   exécuté 2× → `md5sum <path>` identique.

### Commandes Artisan utilitaires

- `php artisan wpkg:cache:warmup --all` — pré-calcul cache pour tous les postes
  (progress bar). Utile post-flush ou après une mutation bulk.
- `php artisan wpkg:cache:flush [--workstation=H]` — vide le cache (ciblé ou
  global) ; équivalent legacy `apcu_delete_multi('#^wpkg_#')` mais ciblé.
- `php artisan wpkg:ini:regenerate --all` — régénère le `.ini` per-poste
  (utile après bascule prod ou changement de format).

---

## Section 4 — UI admin assignation apps WPKG (Story 15.4)

> **Convention 2026-05-07** : numérotation **stable et append-only**. Les sections
> sont conservées même si une section précédente est retirée (cf. Section 3 drift
> `SyncAllFromAdJob` 15.3 supprimée 2026-05-08 — numéro non réutilisé).
> Les 5 scénarios 4.1 → 4.5 ne doivent plus jamais être renumérotés.

### Pré-requis Section 4

- User admin avec permission Spatie `wpkg.assign` (`SambaPermission::WpkgAssign`).
- Au moins 1 `WorkstationGroup` actif et 1 `Workstation` rattachée.
- Au moins 2 `AppProfile` actifs et 3 `Application` (idéalement avec `category` non null).
- Endpoint `/wpkg/profiles.xml?poste=<HOSTNAME>` joignable (cf. Section 2 — pré-requis).

### Scénario 4.1 — Vue parc / ajout AppProfile → cache invalidé sans flush manuel

**Objectif** : vérifier que l'assignation d'un profil à un parc invalide
automatiquement le cache `wpkg:packages:{hostname}` pour tous les postes du
parc, sans intervention `php artisan wpkg:cache:flush`.

1. Naviguer vers `/app/parc/groups/{id}?tab=wpkg` → onglet « Applications WPKG ».
2. Sur le sous-onglet par défaut « Assignations », cliquer « Ajouter » sur la card
   « Profils applicatifs ».
3. Cocher 1 profil non encore rattaché, cliquer « Ajouter ».
4. Toast vert : « 1 profil(s) ajouté(s) au parc ».
5. Sur la VM, `tail` sur les logs `wpkg-deploy` :
   `tail -f storage/logs/wpkg-deploy/wpkg-deploy-*.log` — doit contenir
   `[InvalidateWorkstationPackagesCache] cache invalidé` avec le bon `event`.
6. `curl http://<vm>/wpkg/profiles.xml?poste=<hostname>` (un poste du parc) :
   le XML doit lister les apps du nouveau profil (premier appel = MISS cache,
   reflète la modif).

### Scénario 4.2 — Vue poste / override profil direct

**Objectif** : un profil peut être à la fois hérité (via parc) ET direct sur
le poste — la dédup UI affiche les deux badges, le cache est invalidé.

1. Pour un poste membre d'un parc auquel un profil P est déjà rattaché, naviguer
   `/app/parc/machines/{id}?tab=wpkg`.
2. P apparaît avec badge « hérité (via parc XYZ) ».
3. Cliquer « Ajouter directement » sur la card profils → cocher P → confirmer.
4. P apparaît désormais 2 fois dans la liste : une ligne « hérité » + une ligne
   « direct » avec bouton de retrait.
5. Logs `wpkg-deploy` : 1 ligne d'invalidation pour le hostname du poste.
6. `curl /wpkg/profiles.xml?poste=<hostname>` : pas de doublon dans le XML
   (l'union métier dédupplique côté `WorkstationPackagesResolver`).

### Scénario 4.3 — Bulk catégorie → 1 event pluriel + cache invalidé

**Objectif** : valider la Décision C 2026-05-07 (1 event pluriel
`AppProfileApplicationsChanged` plutôt que N events).

1. Sur `/app/parc/groups/{id}?tab=wpkg`, cliquer le bouton « Bulk catégorie ».
2. Sélectionner une catégorie (ex. `browsers`) avec ≥ 3 apps.
3. Choisir « Créer un nouveau profil » — laisser le nom par défaut.
4. Confirmer.
5. Toast vert : « N application(s) de la catégorie "browsers" assignées au profil ».
6. Logs `wpkg-deploy` :
   - 1 ligne `Bulk catégorie` avec `apps_count: N`, `target_type: group`, `profile_id`.
   - **1 seule** ligne `[InvalidateWorkstationPackagesCache]` avec
     `event: App\\Wpkg\\Deployment\\Events\\AppProfileApplicationsChanged`
     (pas N lignes).
7. Vérifier en BDD : `select count(*) from app_profile_application where app_profile_id = <new>` = N.

### Scénario 4.4 — Clone parc → parc → diff appliqué + ligne `wpkg_deployments`

**Objectif** : valider l'AC4 — clone synchrone, transaction, ligne
`wpkg_deployments` UUID, events ciblés.

1. Sur `/app/parc/groups/{src}?tab=wpkg`, cliquer « Cloner cette configuration vers... ».
2. Dans la modale, sélectionner un parc cible {tgt}.
3. La preview affiche le diff (Ajouts vs Retraits, profils + apps directes).
4. Confirmer (wire:confirm).
5. Toast vert récapitule le diff.
6. En BDD :
   `select id, status, target_scope, summary from wpkg_deployments order by created_at desc limit 1`
   doit retourner 1 ligne avec :
   - `id` = UUID v4
   - `status` = `completed`
   - `target_scope` = `{"workstation_group_ids":[<tgt>]}`
   - `summary` JSON avec `source_group_id` et les arrays `added`/`removed`.
7. Logs `wpkg-deploy` : 2 lignes (« Clone configuration parc — début » + « terminé »)
   partageant le même `deployment_id`.
8. Sur la fiche du parc cible, l'onglet WPKG reflète exactement la config source.

### Scénario 4.5 — Modif option `.ini` → fichier régénéré sur disque

**Objectif** : valider l'AC5 — toggle option → event `WorkstationOptionsChanged`
→ listener regen `.ini` via `WorkstationIniGenerator` + `AtomicFileWriter`.

1. Sur `/app/parc/machines/{id}?tab=wpkg`, basculer le sous-onglet « Options .ini ».
2. Noter mtime initial du fichier `.ini` :
   `ls -la $(php artisan tinker --execute='echo config("sambaedu.wpkg.ini_path");')/<HOSTNAME>.ini`.
3. Toggler l'option `debug` (off → on).
4. Cliquer « Enregistrer ».
5. Toast vert : « Options WPKG mises à jour (1 modification) ».
6. Re-vérifier mtime : il doit avoir changé.
7. `cat <ini_path>/<HOSTNAME>.ini` : la ligne doit contenir
   `debug=true ' Permet d'avoir...` (CRLF strict, ordre des 8 options stable).
8. Sur la BDD : `select * from wpkg_workstation_options where workstation_id = <id>`
   contient une ligne `option_key=debug, option_value=true`.
9. Toggle débugger off → enregistrer → la ligne BDD est **supprimée** (parité legacy
   « ne stocker que les overrides »), le `.ini` repasse à `debug=false ' …`.
10. Test « Réinitialiser aux défauts » : toutes les lignes BDD du poste sont
    supprimées en une fois, le `.ini` reflète tous les défauts.

### Checklist rapide Section 4 (relecteur)

- [ ] Scénario 4.1 — Vue parc / ajout profil → invalidation auto cache hostname
- [ ] Scénario 4.2 — Vue poste / héritage + override coexistent (badges UI)
- [ ] Scénario 4.3 — Bulk catégorie → 1 event pluriel (pas N)
- [ ] Scénario 4.4 — Clone parc → ligne `wpkg_deployments` UUID + diff
- [ ] Scénario 4.5 — Toggle option → mtime `.ini` change + format CRLF stable

---

## Section 5 — Pipeline rapports clients + Dashboard (Story 15.5)

> **Cadre** : ingestion via worker `wpkg:process-reports` (Story 9.4), archivage
> brut, corrélation `deployment_id`, parser graceful, dashboard global
> `/app/wpkg/deployments`, rotation archives 90j.

> **Note 2026-05-11 — retour iso-legacy auth** : les scénarios 5.1 (provisioning
> secret Bearer) et 5.2 (token expiré → 401) ont été supprimés suite au retour
> au mode d'auth legacy (jointure AD + ACL Samba sur le partage `rapports/`).
> Les numéros 5.1 et 5.2 **ne sont pas réutilisés** (convention append-only).
> Les scénarios suivants déclenchent l'ingestion via le worker
> `php artisan wpkg:process-reports` après dépôt d'un fichier `.txt` dans le
> partage SMB.

### Scénario 5.3 — Rapport corrélé à un clone parc 15.4

1. Pré-condition : un poste de test `PC-TEST` actif en BDD.
2. Depuis `/app/parc/groups/{id}` → cloner la config parc vers un parc
   contenant `PC-TEST` → noter le `deployment_id` UUID dans le toast.
3. Déposer un rapport sur le partage SMB, puis déclencher l'ingestion :
   ```bash
   cp rapport.txt /var/sambaedu/unattended/install/wpkg/rapports/PC-TEST.txt
   touch -d "1 minute ago" /var/sambaedu/unattended/install/wpkg/rapports/PC-TEST.txt
   php artisan wpkg:process-reports
   ```
4. Tinker :
   ```bash
   php artisan tinker
   >>> \App\Wpkg\Deployment\Models\WpkgDeploymentWorkstationStatus::where('workstation_id', $id)->latest()->first();
   ```
5. `client_status` reflète le rapport, `deployment_id` matche le clone.
6. `wpkg_deployments.summary->reported` incrémenté de 1.
7. Si `total_targets <= reported` → status passé à `completed`.

### Scénario 5.4 — Rapport spontané (sans déploiement actif)

1. Sur un poste **sans** déploiement actif : déposer un rapport sur le partage
   SMB et lancer `php artisan wpkg:process-reports`.
2. `wpkg_deployment_workstation_status` n'a **pas** de nouvelle ligne.
3. `workstation_application_status` (table 9.4) est mise à jour normalement.
4. Log : `event=wpkg_report_ingested deployment_id=null`.

### Scénario 5.5 — Bouton « Forcer une re-évaluation »

1. Pré-condition : user avec permission `wpkg.assign`.
2. Naviguer vers `/app/wpkg/deployments/workstation/{id}`.
3. Cliquer « Forcer une re-évaluation » → modale de confirmation.
4. Confirmer → toast vert.
5. Vérifier en log `wpkg-deploy` :
   - `event=wpkg_manual_reevaluation triggered_by_user_id=...`
   - `event=wpkg_manual_reevaluation_ini_regenerated`
6. `Cache::has("wpkg:packages:{hostname}")` retourne false (cache purgé).
7. Le fichier `.ini` du poste a un mtime récent (régénération).

### Scénario 5.6 — Rotation des archives 90j

1. Pré-condition : `config('sambaedu.wpkg.reports_archive_retention_days')`
   = 90.
2. Créer un fichier d'archive vieux de 100 jours :
   ```bash
   touch -d '100 days ago' "$(php artisan tinker --execute='echo config("sambaedu.wpkg.reports_archive");')/2026/01/01/PC-OLD_old.txt"
   ```
3. Lancer en dry-run pour audit :
   ```bash
   php artisan wpkg:reports:archive:rotate --dry-run
   ```
   Sortie : `[DRY-RUN] 1 fichier(s) sélectionné(s), … libéré(s).`.
4. Run réel : `php artisan wpkg:reports:archive:rotate`.
5. Le fichier vieux a disparu, les fichiers récents sont conservés.
6. Off-by-one : un fichier de pile 90 jours est CONSERVÉ (strict `<`).

### Checklist rapide Section 5 (relecteur)

- [ ] Scénario 5.3 — Rapport corrélé clone parc 15.4 → status_row + summary
- [ ] Scénario 5.4 — Rapport spontané → uniquement `workstation_application_status`
- [ ] Scénario 5.5 — Bouton re-évaluation → cache purgé + `.ini` régénéré
- [ ] Scénario 5.6 — Rotation archives 90j → fichiers anciens supprimés

### Post-correctifs & non-régressions (Story 15.5 — étape 8 dev-cycle)

Suite à la code review adversariale + corrections appliquées, vérifications QA manuelles à effectuer en plus des scénarios 5.3–5.6 :

- [ ] **Fix #1 (sécurité Livewire)** — sur la vue détail poste `/app/wpkg/deployments/{workstation}` : connecté en user **sans** `wpkg.assign`, s'assurer (i) que le bouton « Forcer une re-évaluation » n'apparaît pas, (ii) qu'un appel direct à la méthode Livewire `forceReevaluation()` (ex. via DevTools) renvoie **403** (et pas une simple toast d'erreur). Côté user **avec** la permission : le bouton fonctionne et déclenche bien le listener.
- [ ] **Fix #2 (logs channel `wpkg-deploy`)** — ingérer un rapport contenant des bytes invalides (UTF-16 / latin1) **ET** un rapport référençant des `app_id` absents de la table `applications`. Vérifier que les warnings apparaissent dans `storage/logs/wpkg-deploy*.log` (PAS dans le channel par défaut), avec les clés structurées `event=wpkg_report_invalid_utf8` / `event=wpkg_report_unknown_apps_ignored` et `workstation_id` + `hostname` renseignés.
- [ ] **Fix #9 (fanout `total_targets`)** — créer un déploiement WPKG via clone parc (15.4) ciblant un parc de N postes (≥ 5). Avant qu'aucun rapport n'arrive, vérifier dans le dashboard que la barre de progression annonce `0/N` (et non `0/0`). Recevoir 1 rapport → vérifier `1/N` (pas `1/1`). Status doit rester `running` tant que `reported < total_targets`.
- [ ] **Fix #11 (dédup incidents 24h)** — provoquer 3 rapports `failed` consécutifs sur le même poste (ex. réinstall manuelle 3× du même paquet). Sur `/app/wpkg/deployments`, la table « Incidents 24h » doit afficher **1 seule ligne** pour ce poste (avec le timestamp du dernier rapport), pas 3. Filtre `severity` (partial/failed/unknown) toujours fonctionnel.
- [ ] **Fix #12 (jointure `profileAggregates`)** — créer un AppProfile P avec : 1 lien direct sur poste actif Wa, 1 lien via groupe G contenant un poste actif Wb + un poste **archivé** Wc. Sur le dashboard / onglet « par profil », le total du profil P doit être `2` (Wa + Wb), **pas 3** (Wc archivé exclu).

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
- [ ] Scénarios 4.1 → 4.5 — UI admin assignation apps WPKG (Story 15.4)
- [ ] Scénarios 5.3 → 5.6 — Pipeline rapports + Dashboard (Story 15.5)
- [ ] Scénarios 6.1 → 6.5 — Endpoints natifs `/wpkg/{linux,winget}_out.php` (Story 17.6)

---

## Section 6 — Endpoints natifs `linux_out` / `winget_out` (Story 17.6)

> Porte nativement (Laravel) les 2 derniers endpoints `*_out.php` encore servis
> par le shim PHP-FPM legacy (catchall), non couverts par 16.13 :
>
> - `/wpkg/linux_out.php` — consommé par `applications/wpkg/startup.linux`.
>   Résout les paquets APT applicables au poste → plain-text `"pkg1 pkg2 pkg3"`.
> - `/wpkg/winget_out.php` — consommé par `install/os/SambaEdu/install.ps1`.
>   Mappe `Application::xml` + `add.json`/`remove.json` → JSON
>   `{install?, upgrade?, uninstall?}`.
>
> **Pas d'auth JWT** (D2) : postes pas encore enrôlés au boot/install. Protection
> = `local.request` (allowlist LAN) + `throttle:300,1`. Routes déclarées dans
> `routes/web.php` AVANT le catchall legacy (chemins littéraux `.php`).
>
> **Pré-requis** :
> - `WPKG_WINGET_ENABLED=true` dans `.env` (sinon `winget_out` renvoie 400).
>   Catalogues `/usr/share/sambaedu/applications/winget/{add,remove}.json`
>   (+ couche `/etc/...`) présents pour le test winget réaliste.
> - **`WPKG_ALLOWED_IPS`** doit contenir l'IP du testeur (ou le CIDR du LAN)
>   en plus de `127.0.0.1,::1` — sinon tous les `curl` ci-dessous depuis une
>   autre machine que la VM tombent en **403** (`local.request`). Sur la VM
>   elle-même (localhost), aucun ajout nécessaire.
>
> **Correctif post-review #1 (2026-05-25)** : `linux_out` ne résout PLUS par
> hostname. Il lit le contexte pré-calculé `apps.<md5>` (store `app_context`,
> posé par le pipeline d'assembly des scripts via `CacheAppContextWriter`),
> exactement comme les 6 autres endpoints `*_out.php` natifs. Le param `id`
> est donc le **md5 de session** (`md5(strtolower(user).strtolower(machine).action.application)`)
> que `startup.linux` envoie déjà — **pas** un hostname.

### Scénario 6.1 — `linux_out` happy path (paquets APT)

1. Poste `PC01` enregistré (table `workstations`) avec ≥2 applications WPKG
   **Installed** rattachées (directes ou via parc/profil), dont au moins une
   portant un fragment `<linux type="apt" package="…"/>` dans `Application::xml`.
   Le contexte `apps.<md5>` doit exister (posé au logon/boot par l'assembly du
   script) avec la clé `liste_applications` (liste plate d'`app_id` lowercase).
2. Récupérer le `<md5>` réel d'une session active (clé `apps.<md5>` du store
   `app_context`) — c'est le même `id` que `startup.linux` injecte. Depuis une
   IP autorisée (`WPKG_ALLOWED_IPS`) ou la VM :
   ```bash
   curl -s "http://se4fs/wpkg/linux_out.php?id=<md5>"
   ```
3. Sortie attendue : plain-text `"pkg1 pkg2 pkg3"` (espaces simples, ordre =
   ordre de `liste_applications`). `Content-Type: text/plain`.
4. Une app sans noeud `<linux type="apt">` → fallback `strtolower(app_id)`
   (parité `linux_out.php:36-38`). Une app assignée mais **non Installed** est
   absente (S1 — filtre `->installed()`, parité packages.xml).
5. Parité legacy : comparer le body au legacy pour le même `id`/contexte
   (le `for p in $packages` shell tolère un trailing space/newline éventuel).

### Scénario 6.2 — `linux_out` cas limites (id absent / invalide / contexte expiré)

1. `curl -s "http://se4fs/wpkg/linux_out.php"` (sans `id`) → **body vide**, 200.
2. `curl -s "http://se4fs/wpkg/linux_out.php?id=PAS_UN_MD5"` (id non-md5) →
   **body vide**, 200 (le legacy `apcu_fetch` sur une clé invalide retournerait
   false → bloc non exécuté).
3. `curl -s "http://se4fs/wpkg/linux_out.php?id=<md5_inexistant>"` (md5 valide
   mais contexte `apps.<md5>` expiré/absent — TTL 1800s dépassé) → **body vide**,
   200 (pas de crash : le poste boot quand même ; `Log::debug` côté natif).

### Scénario 6.3 — `winget_out` machine vierge → tout en install

1. `WPKG_WINGET_ENABLED=true`. Poste `PC02` avec ≥1 app portant un fragment
   `<windows type="winget" id="…"/>`.
2. ```bash
   curl -s -X POST -F "machine=PC02" -F "list=[]" -F "action=list" \
     http://se4fs/wpkg/winget_out.php
   ```
3. Sortie attendue : JSON `{"install":[{"Id":"…","Source":"winget"}, …]}`
   (toutes les apps demandées + `add.json` en `install`). `Content-Type: text/json`,
   `JSON_PRETTY_PRINT`. Clés `upgrade`/`uninstall` absentes.

### Scénario 6.4 — `winget_out` upgrade + uninstall + surcharge `/etc/`

1. Construire un `list` représentatif de `Get-WinGetPackage | ConvertTo-Json`
   contenant : (a) une app demandée déjà installée avec `IsUpdateAvailable=true`
   + `AvailableVersions`, (b) une app présente dans `remove.json`.
2. Ajouter une entrée dans `/etc/sambaedu/applications/winget/add.json`
   (surcharge admin) absente de `/usr/share/...`.
3. POST `machine=PC02`, `list=<json>`, `action=list`.
4. Sortie attendue : `upgrade` peuplé (avec `AvailableVersion` bornée par la
   version pinnée XML si présente), `uninstall` peuplé (app de `remove.json`),
   `install` contient l'entrée `/etc/add.json`. Parité `winget_out.php:133-189`.
5. Vérifier **qu'aucun fichier `/tmp/winget_*.json` n'est créé** (le legacy le
   faisait en debug — non porté).

### Scénario 6.5 — `winget_out` flag off + validations + ordre catchall

1. `WPKG_WINGET_ENABLED=false` → POST winget_out → **400 Bad request**
   (parité `:23-26`).
2. Flag on mais `action != "list"` OU `machine` vide OU `list` vide OU `list`
   non-JSON → **400**.
3. **Non-régression catchall** : les autres chemins `*.php` legacy (ex.
   `/wpkg/packages_xml_out.php`) continuent d'être servis par
   `LegacyCatchallController`. Vérifier que `/wpkg/linux_out.php` et
   `/wpkg/winget_out.php` sont bien servis par le **natif** (header / log
   d'app Laravel), pas par le shim legacy. Test archi `WpkgOutRoutesTest`
   garantit l'ordre avant catchall.

### Post-correctifs & non-régressions (Story 17.6)

- [ ] **Parité format** — `linux_out` : espaces simples, pas de newline final
  injecté côté natif (DO-3). `winget_out` : `Content-Type: text/json`
  (non-standard, parité stricte), clés JSON omises si vides.
- [ ] **S4 — `Content-Type` sur cas vide `linux_out`** : le natif pose
  `Content-Type: text/plain` **même** sur le cas body vide (id absent/invalide
  ou contexte expiré) ; le legacy ne pose ce header que sur cache-hit
  (`linux_out.php:44`, bloc `if ($info)`). Micro-divergence sans impact
  (`startup.linux` split sur les espaces, ignore le Content-Type), mais à
  connaître pour une capture **bytes** de référence (ne pas créer de faux échec
  de parité de header sur le cas vide).
- [ ] **#1 — `linux_out` lit `apps.<md5>`** : vérifier qu'une session réelle
  produit bien la clé `apps.<md5>` dans le store `app_context` et que `linux_out`
  renvoie la liste APT attendue (pas un body vide). Si body vide en prod : vérifier
  que le contexte n'a pas expiré (TTL 1800s) et que `liste_applications` est peuplée.
- [ ] **S1 — filtre `installed()`** : une app assignée au poste mais `Available`/
  `UpdateAvailable` est absente de la sortie `linux_out` ET de la liste XML winget
  (helper `loadByAppIds` partagé). Parité packages.xml legacy.
- [ ] **Pas d'écriture disque** — `winget_out` n'écrit aucun fichier temporaire
  (`/tmp/winget_*.json` legacy non porté — sécurité + idempotence).
- [ ] **Sécurité LAN** — un appel depuis une IP hors allowlist `local.request`
  est rejeté (403), iso `wpkg/reports/*`. Aucun JWT/Bearer requis (D2). Pré-requis :
  l'IP du testeur doit être dans `WPKG_ALLOWED_IPS` (sinon 403 attendu).

---

## Section 7 — Réglages de déploiement WPKG configurables au runtime (Story 15.6)

> **Objectif** : vérifier que les bascules opérationnelles du canal winget
> (`winget_enabled`) et de l'allowlist IP (`allowed_ips`) peuvent être modifiées
> depuis l'UI admin **sans accès SSH, sans modifier `.env`, sans `config:cache`**.
>
> **Rappel** : ces endpoints sont non authentifiés (auth iso-legacy).
> L'allowlist IP est la frontière de sécurité primaire.
>
> **Code de référence** :
> - `app/Wpkg/Deployment/Services/WpkgDeploymentSettings.php` — résolveur
> - `app/Wpkg/Deployment/Rules/SafeIpCidrRule.php` — validation IP/CIDR
> - `app/Http/Middleware/EnsureLocalRequest.php` — middleware allowlist
> - `app/Wpkg/Deployment/Http/Controllers/WingetOutController.php` — gate flag
> - `resources/views/pages/admin/settings/gpo/wpkg-deployment/index.blade.php` — UI

### Pré-requis Section 7

- VM accessible, migrations à jour (`php artisan migrate:status`).
- User admin avec permission Spatie `server.admin`.
- Au moins 1 poste dans la table `workstations` (pour le test winget_out).
- L'IP de la machine depuis laquelle vous testez doit être **dans le même réseau
  que la VM** (ou tester depuis la VM via curl localhost).

### Scénario 7.1 — Activer winget + ajouter CIDR depuis l'UI → poste installe sans SSH

**Objectif** : reproduire le déclencheur terrain 2026-06-10 (poste `windaube`,
groupe `cdi`) et démontrer que l'admin peut débloquer le canal en quelques clics,
sans connexion SSH ni `config:cache`.

#### Étape A — Vérifier l'état initial fail-closed

1. Depuis la VM (ou une IP non listée) :
   ```bash
   curl -s -o /dev/null -w "%{http_code}" -X POST \
     -F "machine=windaube" -F "list=[]" -F "action=list" \
     http://localhost/wpkg/winget_out.php
   ```
   → attendu **400** (winget désactivé).

2. Depuis une IP LAN non listée (ex. autre VM) :
   ```bash
   curl -s -o /dev/null -w "%{http_code}" -X POST \
     -F "machine=windaube" -F "list=[]" -F "action=list" \
     http://192.168.122.50/wpkg/winget_out.php
   ```
   → attendu **403** (IP non dans l'allowlist).

#### Étape B — Activer via l'UI (sans SSH)

3. Se connecter sur l'interface SE5 en tant qu'admin (`server.admin`).
4. Naviguer vers `/admin/settings/gpo/wpkg-deployment`.
5. Dans la carte **Réglages de déploiement** :
   - Toggle « Canal winget » → basculer **Activé**.
   - Toast vert « Canal winget activé ».
   - Badge source passe de `env` à `DB`.

#### Étape C — Ajouter le CIDR du parc

6. Dans la section **Allowlist IP / CIDR** :
   - Saisir `192.168.122.0/24` (CIDR du parc LAN).
   - Cliquer **Ajouter**.
   - Modale de confirmation : lire l'avertissement (endpoint non authentifié).
   - Cliquer **Confirmer l'ajout**.
   - Toast vert « Entrée ajoutée ».
   - La liste affiche `192.168.122.0/24` avec badge `DB`.

#### Étape D — Vérifier l'effet immédiat (sans config:cache)

7. **Depuis la VM (localhost)** — test winget :
   ```bash
   curl -s -X POST -F "machine=windaube" -F "list=[]" -F "action=list" \
     http://localhost/wpkg/winget_out.php
   ```
   → attendu **200** + JSON `{install: [...]}` ou `{}` selon les apps assignées.

8. **Depuis une IP du CIDR 192.168.122.0/24** — test allowlist :
   ```bash
   curl -s -X POST -F "machine=windaube" -F "list=[]" -F "action=list" \
     http://192.168.122.50/wpkg/winget_out.php
   ```
   → attendu **200** (l'IP est maintenant dans l'allowlist DB).

9. Vérifier le log `wpkg-deploy` :
   ```bash
   tail -20 storage/logs/wpkg-deploy/deploy-*.log | grep 'setting_changed'
   ```
   → deux entrées `event=wpkg_deployment_setting_changed` (une pour `winget_enabled`,
   une pour `allowed_ips`) avec `user_id`, `old`, `new`.

10. **Vérifier qu'aucun `config:cache` n'a été nécessaire** :
    ```bash
    stat bootstrap/cache/config.php | grep Modify
    ```
    Le timestamp ne doit PAS avoir changé entre les étapes B/C et D.

**Attendu** : le canal winget est opérationnel pour le parc LAN, entièrement piloté
depuis l'UI, sans intervention shell.

### Scénario 7.2 — Validation stricte de l'allowlist (garde-fous sécurité)

**Objectif** : vérifier que les entrées dangereuses sont rejetées avant persistance.

1. Dans la carte **Allowlist IP / CIDR** → saisir `0.0.0.0/0` → cliquer **Ajouter**.
   → attendu : message d'erreur « ouvre l'accès à tout Internet », pas de modale,
   pas de persistance.

2. Saisir `10.0.0.0/8` → cliquer **Ajouter**.
   → attendu : message d'erreur « préfixe trop large », pas de modale.

3. Saisir `999.1.1.1` → cliquer **Ajouter**.
   → attendu : message d'erreur « adresse IP valide », pas de modale.

4. Saisir `192.168.1.0/24` deux fois → la deuxième fois : « déjà dans la liste ».

5. Saisir `192.168.50.0/24` → modale → annuler → pas de persistance.

### Scénario 7.3 — Désactiver et retirer une entrée allowlist

1. Toggle « Canal winget » → **Désactivé**.
   → Toast vert « Canal winget désactivé ».
   ```bash
   curl -s -o /dev/null -w "%{http_code}" -X POST \
     -F "machine=windaube" -F "list=[]" -F "action=list" http://localhost/wpkg/winget_out.php
   ```
   → attendu **400** (sans reboot, sans SSH).

2. Dans l'allowlist, cliquer le `×` sur `192.168.122.0/24`.
   → Toast « Entrée retirée ». Pas de modale pour la suppression.
   ```bash
   curl -s -o /dev/null -w "%{http_code}" -X POST \
     -F "machine=windaube" -F "list=[]" -F "action=list" \
     http://192.168.122.50/wpkg/winget_out.php
   ```
   → attendu **403** (IP à nouveau hors allowlist).

### Scénario 7.4 — Non-régression audit GPO existant

1. Après les manipulations des scénarios 7.1–7.3, vérifier que l'audit GPO
   existant reste fonctionnel :
   - Cliquer **Re-auditer** → badge sévérité visible, pas d'erreur.
   - Cliquer **Re-publier la GPO `se4_wpkg`** → modale confirmation s'ouvre.
   - Annuler la modale.
   → La section audit GPO est inchangée et fonctionnelle.

### Scénario 7.5 — Fail-closed : DB polluée `0.0.0.0/0` → IP externe reste 403

**Contexte** : correction post-review #3 (2026-06-10). Vérifie que `allowedIps()` écarte
en lecture toute entrée dangereuse contournant la validation UI (ex. insertion directe en
DB via psql, import SQL, ou migration data corruption).

**Prérequis** : accès psql à la DB de test (ou `tinker`).

1. Insérer directement `0.0.0.0/0` dans `system_settings` (hors UI) :
   ```bash
   php artisan tinker --execute="App\Models\SystemSetting::set('wpkg.allowed_ips', ['0.0.0.0/0']);"
   ```

2. Vérifier que `allowedIps()` retourne une liste vide (0.0.0.0/0 écarté) :
   ```bash
   php artisan tinker --execute="dd(app(App\Wpkg\Deployment\Services\WpkgDeploymentSettings::class)->allowedIps());"
   ```
   → attendu : `[]` (liste vide, `0.0.0.0/0` silencieusement écarté).

3. Depuis une IP externe (ex. `203.0.113.5` — adresse de documentation RFC 5737) :
   ```bash
   curl -s -o /dev/null -w "%{http_code}" -X POST \
     -F "machine=test" -F "list=[]" -F "action=list" \
     --interface lo http://127.0.0.1/wpkg/winget_out.php
   ```
   Simuler l'IP externe via un test unitaire (cf. `EnsureLocalRequestSettingsTest::db_polluted_with_deny_all_cidr_still_rejects_external_ip`).
   → attendu : **403** même si `0.0.0.0/0` est en DB.

4. Vérifier le log warning dans `storage/logs/wpkg-deploy/deploy-*.log` :
   ```bash
   grep 'wpkg_allowed_ip_rejected' storage/logs/wpkg-deploy/deploy-*.log
   ```
   → attendu : entrée avec `entry: 0.0.0.0/0`.

5. Nettoyage : restaurer la liste via l'UI ou `tinker`.

**Attendu** : même avec une DB polluée, aucune IP externe ne peut accéder aux endpoints
WPKG via `0.0.0.0/0` — le fail-closed est garanti en lecture.

### Checklist rapide Section 7 (relecteur)

- [ ] Scénario 7.1 — Activer winget + CIDR LAN → 200 sans SSH ni config:cache
- [ ] Scénario 7.2 — Validation stricte rejet 0.0.0.0/0, préfixes larges, syntaxe invalide
- [ ] Scénario 7.3 — Désactivation et retrait allowlist → effet immédiat
- [ ] Scénario 7.4 — Non-régression audit GPO (re-auditer / modale publish)
- [ ] Scénario 7.5 — Fail-closed DB polluée `0.0.0.0/0` → IP externe reste 403
