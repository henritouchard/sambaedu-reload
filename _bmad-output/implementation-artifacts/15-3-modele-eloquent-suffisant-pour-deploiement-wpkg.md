# Story 15.3 : Modèle Eloquent suffisant pour le déploiement WPKG

Status: review

> **Story garde-fou Epic 15** — verrouille l'invariant Eloquent → AD posé par 15.1/15.2 : le pipeline de déploiement WPKG ne consulte jamais l'AD en **chemin critique**. La sync AD → Eloquent reste un **outil de remédiation** déclenché humainement (bouton UI ou commande artisan), pas une dépendance synchrone — et surtout pas un cron entrant qui violerait la direction d'écriture canonique.

---

## Story

As a **développeur SER**,
I want que le modèle Eloquent contienne tous les attributs nécessaires au pipeline de déploiement WPKG, et que la sync AD → Eloquent ne soit utilisée que comme outil de remédiation manuelle (bootstrap initial + correction de drift),
So que les Generators (Story 15.2) et l'UI (Story 15.4) consomment uniquement Eloquent, et que le pipeline reste rapide, testable, et résilient à une indisponibilité AD ponctuelle, sans race avec les jobs `*AdSyncJob` sortants pilotés par observers.

---

## Contexte

### Direction d'écriture canonique : Eloquent → AD via observers

Dans ce projet, **Eloquent est la source de vérité opérationnelle**. Toute mutation métier passe par les modèles Eloquent (`Workstation`, `WorkstationGroup`, `AppProfile`, etc.) ; les observers (`WorkstationGroupObserver`, `AppProfileObserver`, `WorkstationObserver`) dispatchent ensuite des jobs `*AdSyncJob` pour propager vers l'AD. La direction est strictement **Eloquent → AD** ; aucun cron entrant ne pousse de l'AD vers Eloquent en arrière-plan.

> **Pourquoi pas de cron entrant ?** Si un job `*AdSyncJob` sortant est encore en queue quand un cron entrant lit l'AD, le cron lit l'état AD pas-encore-mis-à-jour et réécrit l'ancienne valeur dans Eloquent — race silencieuse, perte d'écritures métier. Cette story **supprime explicitement** l'idée d'un job périodique de réconciliation entrant et la remplace par un durcissement de l'outil de remédiation existant déclenché humainement.

### `SyncAllFromAdJob` = outil de remédiation, pas un cron

Le job `app/Jobs/SyncAllFromAdJob.php` existe déjà (~554 lignes). Il est pensé comme outil de **bootstrap migration sambaedu legacy → Laravel reload** + **remédiation drift ponctuelle** :

- Importe en 1 transaction : `WorkstationGroup` (OU=Computers) + `AppProfile` (OU=Parcs) + liens parc↔groupe + `Workstation` (machines) + liens poste↔groupe/profil.
- Désactive correctement les observers le temps de l'import (`WorkstationGroupObserver::disableSync()` / `WorkstationObserver::disableSync()`) — pas de feedback sortant vers AD pendant l'import entrant.
- Se déclenche **humainement** depuis :
  - L'UI Livewire `/admin/sync-from-ad` (page existante : `resources/views/pages/sync-from-ad/index.blade.php`)
  - Les pages `parc-settings` et `profiles` (boutons « Importer depuis l'AD »)
  - La commande artisan `php artisan sync:from-ad` (`app/Console/Commands/SyncFromAd.php`)

Cette story **durcit ce job existant** pour qu'il devienne un outil de remédiation drift fiable — sans en faire un cron, sans toucher à la direction d'écriture canonique.

### Définitions vocabulaire (à respecter dans le code, les commentaires, la doc)

> **Chemin critique** = code exécuté pour répondre à une requête HTTP de poste Windows demandant son déploiement WPKG (`GET /wpkg/hosts.xml?poste=...`, `GET /wpkg/profiles.xml?poste=...`). Doit répondre vite, marcher même si l'AD est lent ou indisponible, et être testable sans serveur LDAP. Vit sous le namespace `App\Wpkg\*`.
>
> **Chemin froid** = code exécuté ponctuellement en arrière-plan (jobs déclenchés par bouton admin, commandes artisan one-shot). Peut lire l'AD librement. Vit sous `App\Jobs\`, `App\Console\Commands\`, `App\Services\AdSync\`, etc.

Règle stricte appliquée par test architectural : **aucun import `LdapRecord\*` ni `App\LdapModels\*` dans le namespace `App\Wpkg\*`** (Deployment livré 15.2 + Admin/UI 15.4 à venir). Toute exception se justifie par un commentaire `@chemin-froid` au-dessus de l'import.

### Ce que cette story livre

1. Un **audit doc** (livrable `_bmad-output/planning-artifacts/audit-wpkg-eloquent-schema.md`) qui inventorie les colonnes existantes de `Workstation`, `WorkstationGroup`, `Application`, `AppProfile`, `WpkgWorkstationOption` ; identifie les colonnes manquantes pour que le pipeline n'ait jamais à lire AD en chemin critique ; justifie explicitement les attributs qui restent AD-only par nature (lus uniquement en chemin froid) ; et documente la **procédure ops finale de bascule prod** (migrate → UI sync-from-ad Aperçu → Exécuter), le `SyncAllFromAdJob` durci faisant office de peuplement initial des `ad_guid`.
2. Les **migrations** des colonnes manquantes identifiées par l'audit (les `ad_guid` restent `NULL` après migrate ; ils seront peuplés par le premier run du `SyncAllFromAdJob` durci, pas par une commande artisan dédiée).
3. Un **durcissement de `SyncAllFromAdJob`** pour usage drift fiable : mode `--dry-run` + bouton UI « Aperçu », lock anti-double-clic, logs structurés channel `wpkg-deploy`, archivage `archived_at` au lieu de suppression sèche, **lecture AD en 2 passes** (lecture complète avant toute écriture), idempotence stricte, match strict name+scope au premier run pour peupler les `ad_guid` sans faux positif.
4. La **garantie zéro lecture AD en chemin critique** : test architectural namespace `App\Wpkg\*` + test feature mock LdapRecord throwing sur le pipeline 15.2 complet.
5. Une **suite de tests** couvrant tous les scénarios drift (création, renommage par GUID, archivage, no-op, conflit GUID, AD partiel mid-pass, idempotence) + scénario premier-run faux-positif explicite intégré au test du job durci + invariant chemin critique.

> **Garde-fous transversaux Epic 15 (rappel) :**
> - **Direction d'écriture canonique : Eloquent → AD via observers**. Cette story ne casse pas l'invariant — `SyncAllFromAdJob` désactive les observers pendant son exécution (déjà le cas, à préserver).
> - **Channel logs `wpkg-deploy`** : `Log::channel('wpkg-deploy')->withContext([...])` (15.1).
> - **Atomic write** : non concerné directement ici (la story ne génère pas de fichiers WPKG).
> - **Stratégie port legacy** : si du code est porté de `sambaedu/wpkg/wpkg_ldap_update.php`, header `@legacy-port` + référence + `@todo` de refactoring.

---

## Dépendances

| Story | Titre | Status | Détail |
|-------|-------|--------|--------|
| 15-1 | Fondations Pipeline Déploiement WPKG | review (≈ done) | Channel `wpkg-deploy`, namespace `App\Wpkg\Deployment`, tables `wpkg_deployments` + `wpkg_deployment_workstation_status`, test archi nikic/php-parser. **Considérée stable.** |
| 15-2 | Generators XML/.ini par poste | review (≈ done) | `WorkstationPackagesResolver`, `WorkstationIniGenerator`, `HostsXmlController`, `ProfilesXmlController`, 7 events + 2 listeners, `WpkgWorkstationOption`, helper `tests/Support/WpkgSchemaBootstrapper.php`. **Considérée stable.** Le test feature « zéro AD en chemin critique » s'appuie sur ces controllers/services. |
| Epic 4 | Workstation, WorkstationGroup, AppProfile | done (2026-04-22) | Modèles Eloquent existants — colonnes `ad_dn`/`ad_guid` déjà présentes sur `Workstation` et `WorkstationGroup`, `ad_guid` sur `AppProfile` (audit volet 1 confirmera exhaustivement). |

> Le user considère 15.1 et 15.2 « presque finies » (status `review`). Le dev de 15.3 peut démarrer **immédiatement** sans attendre leur passage en `done`.

**Code existant à coordonner / à durcir :**

- `app/Jobs/SyncAllFromAdJob.php` — **cible des modifications volet 3**. ~554 lignes. Conçu comme outil de bootstrap legacy → reload, à durcir pour usage drift **et** pour le peuplement initial des `ad_guid` post-migration prod (procédure ops volet 1).
- `app/Console/Commands/SyncFromAd.php` — commande artisan `sync:from-ad` (nom historique préservé, cf. décision Q3 review). Le volet 3 lui ajoute `--dry-run`.
- `resources/views/pages/sync-from-ad/index.blade.php` — UI Livewire multi-étapes. Le volet 3 ajoute un bouton « Aperçu (dry-run) » pour les étapes parcs/groupes/postes/profiles. C'est l'UI que l'opérateur utilise après `php artisan migrate` pour peupler les `ad_guid` la première fois.
- `app/Observers/WorkstationGroupObserver.php` + `app/Observers/WorkstationObserver.php` — observers Eloquent → AD existants ; **invariant à NE PAS casser**. `SyncAllFromAdJob` les désactive déjà via `disableSync()` / `enableSync()` (pattern à préserver).
- `app/Services/AdSync/AdSyncService.php` + `app/Services/AdSync/AdSyncChecker.php` — services existants (sens AD ↔ SQL). Patterns LdapRecord réutilisables. Pas modifiés ici.
- `app/Services/Parc/WorkstationGroupService.php`, `app/Services/AppProfile/AppProfileService.php`, `app/Services/WorkstationService.php` — services `importFromAd(...)` existants utilisés par l'UI sync-from-ad. À aligner sur le contrat `dry-run` du volet 3.
- `app/LdapModels/{MachineModel,DeviceGroupModel,DeviceGroupTagModel,OrganizationalUnitModel}.php` — modèles `LdapRecord` interrogés par `SyncAllFromAdJob`.

---

## Acceptance Criteria

> Reproduits depuis `_bmad-output/planning-artifacts/epics.md` § Story 15.3 (lignes 3077-3138), reformulés selon les décisions de cadrage 2026-05-05/06.

### Volet 1 — Audit du schéma actuel (livrable doc)

**AC1.1**
**Given** les entités impliquées : `Workstation`, `WorkstationGroup`, `Application`, `AppProfile`, `WpkgWorkstationOption` (créée en Story 15.2)
**When** la story commence
**Then** un document `_bmad-output/planning-artifacts/audit-wpkg-eloquent-schema.md` est produit, listant pour chaque entité :
- **Colonnes SQL existantes** avec leur usage côté pipeline déploiement (chemin critique vs chemin froid).
- **Colonnes manquantes** pour que le pipeline n'ait **jamais** à lire l'AD en chemin critique → migrations volet 2.
- **Attributs qui restent AD-only par nature** (lus uniquement en chemin froid via `SyncAllFromAdJob`) — justifiés explicitement (ex : OS détaillé non utile au pipeline, lu seulement en remédiation pour audit / display optionnel).

**And** ce document est validé **explicitement par Henri** avant toute migration (commit séparé recommandé).

**And** l'audit prend acte de la **décision de cadrage** : pas de job périodique de réconciliation entrant ; `SyncAllFromAdJob` reste l'outil unique de bootstrap + remédiation, à durcir au volet 3. **Aucune commande artisan dédiée de peuplement initial** : le job durci (avec son mode `--dry-run`, son match strict name+scope et son écriture des attributs manquants) couvre intégralement le besoin de peuplement initial post-migration prod.

**And** l'audit documente la **procédure ops finale de bascule prod** (à reporter dans le runbook 15.7) :

```
1. php artisan migrate              # applique les migrations volet 2 (ad_guid NULL au départ)
2. Aller sur /admin/sync-from-ad    # UI Livewire
3. Cliquer "Aperçu (dry-run)"       # vérifier le diff AD vs SQL
4. Cliquer "Exécuter"               # applique : peuple ad_guid + sync drift
```

### Volet 2 — Migrations colonnes manquantes (livrable code)

**AC2.1**
**Given** la liste des colonnes manquantes identifiées par l'audit volet 1
**When** les migrations sont appliquées
**Then** les colonnes nécessaires sont ajoutées aux tables concernées (typiquement attendu : `last_seen_at`, `archived_at` sur `workstations` et `workstation_groups` ; `ad_dn`/`ad_guid` à confirmer car potentiellement déjà présents)
**And** chaque colonne créée est `nullable` + dispose d'un index si elle sert un filtre courant (`archived_at` notamment, cf. scope `notArchived`)
**And** une rollback migration est testée (`migrate:rollback` puis `migrate` — chaîne re-up sans erreur, idempotente) — prérequis explicite pour la story 15.7 (rollback plan bascule prod)
**And** les `$fillable` / `$casts` / docblock `@property` des modèles concernés sont mis à jour (notamment `last_seen_at` cast `datetime`, `archived_at` cast `datetime`)
**And** **aucune commande artisan dédiée de peuplement initial** n'est livrée : les `ad_guid` manquants (NULL post-migration) seront peuplés par le premier run du `SyncAllFromAdJob` durci au volet 3 (mode Aperçu puis Exécuter via UI `/admin/sync-from-ad`).

### Volet 3 — Durcissement `SyncAllFromAdJob` pour usage remédiation drift

**AC3.1** — Mode dry-run
**Given** `app/Jobs/SyncAllFromAdJob.php` durci
**When** il est appelé avec un flag `dryRun=true` (constructeur ou setter explicite)
**Then** il exécute la lecture AD complète + le diff vs Eloquent + produit un rapport stats (`created`/`updated`/`archived`/`skipped` par entité) **sans aucune écriture DB**.
**And** la commande artisan expose `php artisan sync:from-ad --dry-run` (nom historique conservé, cf. Q3 review post-15.3).
**And** la page Livewire `resources/views/pages/sync-from-ad/index.blade.php` expose un bouton « Aperçu (dry-run) » sur les étapes pilotées par `SyncAllFromAdJob` (cohérent avec le pattern `rights_migration` de la même UI : 2 boutons « Aperçu » / « Exécuter » + status `dry_run_done`).

**AC3.2** — Lock anti-double-clic
**Given** un opérateur clique 2 fois sur « Tout exécuter » (ou ouvre 2 onglets)
**When** la 2e exécution démarre alors que la 1re tourne encore
**Then** elle est bloquée par `Cache::lock('wpkg:sync-all-from-ad', $ttl)->get()` — si le lock n'est pas acquis, log `info` skip + return immédiat (UI : status `skipped` + toast « Sync déjà en cours »).
**And** le TTL du lock est cohérent avec `$timeout` du job (déjà 300s) : valeur configurable `config('sambaedu.wpkg.sync.lock_ttl_seconds')` (défaut 600s).
**And** le lock n'est **PAS** un mécanisme anti-cron (il n'y a pas de cron) — il sert uniquement à empêcher 2 clics concurrents.

**AC3.3** — Logs structurés channel `wpkg-deploy`
**Given** le job durci
**When** il s'exécute
**Then** il log via `Log::channel('wpkg-deploy')->withContext([...])` à chaque étape (lecture AD, diff, écriture, archivage, fin).
**And** il produit en fin d'exécution un **rapport stats détaillé** (par entité : `total_ad`, `total_db`, `created`, `updated`, `archived`, `skipped`, `conflicts`) loggé en `info` + retourné au caller (UI/CLI).
**And** chaque écriture individuelle est loggée en `debug` avec `objectGUID` corrélé.

**AC3.4** — Archivage au lieu de suppression
**Given** une entité présente en SQL mais absente AD (entité orpheline)
**When** le job tourne en mode `--apply`
**Then** il pose `archived_at = now()` sur la row Eloquent au lieu de la supprimer (`DELETE`).
**And** le job log `warning` « entité archivée : objectGUID=... name=... » avec context complet.
**And** l'opérateur peut restaurer manuellement via `archived_at = null` (procédure documentée dans `docs/qa/domains/wpkg-deploy.md`).
**And** un scope Eloquent `notArchived()` est ajouté aux modèles concernés et utilisé partout où c'est pertinent (résolution pipeline, listings UI…).

**AC3.5** — Lecture AD en 2 passes (atomicité)
**Given** le job durci
**When** il démarre
**Then** il exécute la **passe 1 = lecture complète** : tous les parcs (OU=Parcs) + tous les groupes (OU=Computers) + toutes les machines (si activé). Les requêtes lèvent une `LdapException` si l'AD est lent ou partiel.
**And** **aucune écriture DB n'a lieu pendant la passe 1**.
**And** si une des requêtes de la passe 1 échoue (timeout, exception) → log `warning` « passe 1 partielle, abandon de la sync » + return sans aucune écriture (atomicité stricte — pas d'archivage erroné de parcs réellement présents AD mais non lus).
**And** la **passe 2 = écriture** ne démarre que si la passe 1 est complète. Elle est wrappée dans `DB::transaction(...)` global pour atomicité SQL.
**And** un test feature dédié simule un AD partiellement disponible (lecture parcs OK, lecture groupes throw) → assertion : 0 écriture DB, log warning émis.

**AC3.6** — Idempotence stricte
**Given** le job durci, exécuté une 1re fois jusqu'à complétion
**When** il est ré-exécuté immédiatement (état AD inchangé, état DB inchangé)
**Then** la 2e exécution est un no-op silencieux : 0 écriture DB, 0 log de niveau supérieur à `debug` sauf le log final de stats (qui montre `created=0`, `updated=0`, `archived=0`).

**AC3.7** — Match strict premier run (peuplement initial des `ad_guid`)
**Given** le job durci, lancé pour la première fois après `php artisan migrate` en prod (rows Eloquent existantes avec `ad_guid = NULL`)
**When** il rapproche une row AD d'une row Eloquent par défaut de GUID
**Then** il utilise un **algorithme de match strict** : exact `name` lower-case + scope OU précis (`OU=Computers` pour groupes, `OU=Parcs` pour profils, etc.) — cohérent avec le pattern legacy `list_machines_parcs`.
**And** il ne pose `ad_guid`/`ad_dn` que sur les rows Eloquent dont `ad_guid` est `null` ; jamais d'écrasement d'un GUID déjà posé.
**And** un test feature couvre un cas de **faux positif explicite** (deux rows DB de même nom dans des OU différentes — le job ne doit matcher aucune des deux ou matcher correctement par scope OU). Ce test est intégré au scénario du job durci (AC5.2), pas une suite séparée.

### Volet 4 — Aucune lecture AD en chemin critique (cœur de la story)

**AC4.1** — Test architectural namespace `App\Wpkg\*`
**Given** le test architectural existant `tests/Architecture/WpkgDeploymentNamespaceTest.php` (livré 15.1, étendu 15.2)
**When** il est exécuté
**Then** il vérifie qu'**aucune** classe sous `App\Wpkg\*` (incluant `App\Wpkg\Deployment\*` 15.1+15.2 et `App\Wpkg\Admin\*` à venir 15.4) n'importe `LdapRecord\*` ou `App\LdapModels\*` ou `App\Services\Ad\*`.
**And** aucune exception whitelistée n'est ajoutée par cette story (la story **supprime** toute mention d'un `WpkgAdReconciliationJob` qui aurait été whitelisté — ce job est abandonné par décision de cadrage).
**And** si le test détecte une violation, le message d'erreur cite la classe + l'import + suggère soit un déplacement en chemin froid, soit un commentaire explicite `@chemin-froid` (cas exceptionnel uniquement).

**AC4.2** — Test feature mock LdapRecord throwing sur pipeline 15.2
**Given** un test feature `tests/Feature/Wpkg/Deployment/EloquentFirstChemiCritiqueTest.php`
**When** il bind `LdapRecord\Connection` (et tous les modèles `App\LdapModels\*`) à un mock qui throw `RuntimeException('AD must not be queried in chemin critique')` à chaque appel
**Then** la chaîne complète 15.2 fonctionne sans erreur :
- `WorkstationPackagesResolver::resolve($hostname)` → packages retournés sans exception.
- `GET /wpkg/hosts.xml?poste=PC01` → 200 + XML valide.
- `GET /wpkg/profiles.xml?poste=PC01` → 200 + XML valide.
- `WorkstationIniGenerator::generate($workstation)` → `.ini` écrit, format CRLF, atomic.
**And** assertion finale : le mock LdapRecord n'a **jamais** été appelé (`Mockery::shouldReceive(...)->never()` strict sur tous les points d'entrée).

### Volet 5 — Tests

**AC5.1** — Tests unitaires audit + migrations
**Given** les migrations livrées au volet 2
**When** la suite ciblée tourne
**Then** un test feature `tests/Feature/Migrations/WpkgEloquentSchemaMigrationsTest.php` valide : run + rollback + run, présence des colonnes/indexes, types corrects (`datetime` nullable).

**AC5.2** — Tests feature `SyncAllFromAdJob` durci
**Given** le job durci au volet 3
**When** la suite tourne, dans `tests/Feature/Jobs/SyncAllFromAdJobTest.php`
**Then** chaque scénario produit le résultat attendu :

| Scénario | État AD | État DB initial | Résultat attendu |
|---|---|---|---|
| Création parc | nouveau `objectGUID=Y` | absent | row Eloquent créée, log `info` |
| Création poste | nouveau `objectGUID=X` | absent | row Eloquent créée, log `info` |
| Divergence de nom (~~Renommage~~) | `objectGUID=X`, `name AD=PC02` | row `objectGUID=X`, `name SQL=PC01` | `name` SQL **préservé** (Eloquent souverain), log `info` divergence + entrée `error_logs` source=`wpkg`, compteur `name_divergences` incrémenté (cf. décision Q2 review post-15.3) |
| Archivage poste | absent AD | row `objectGUID=X` non-null | `archived_at` posé, log `warning` |
| No-op | identique AD/DB | identique | 0 écriture, 0 log > `debug` |
| Conflit `objectGUID` | 2 rows DB pour 1 GUID | corrupted | log `error`, halte propre, lock libéré |
| Dry-run | quelconque | quelconque | 0 écriture, rapport stats correct |
| Lock anti-double-clic | quelconque | quelconque | 2e exec concurrente bloquée, log `info` skip |
| AD partiel mid-pass | passe 1 throw | quelconque | 0 écriture, log `warning`, pipeline 15.2 continue |
| Idempotence | identique AD/DB | identique | 2e exec consécutive = no-op silencieux |
| Premier run faux positif | 2 rows AD `pc01` dans OU différentes | 1 row DB `pc01` `ad_guid=NULL` | match strict scope OU : aucun GUID écrit OU écrit le bon, jamais le mauvais |

**AC5.3** — Test feature « AD throwing » sur pipeline 15.2 complet
Couvert par AC4.2 ci-dessus. Doit exercer resolver + 2 controllers + ini generator.

**AC5.4** — Test architectural namespace `App\Wpkg\*`
Couvert par AC4.1 ci-dessus. Aucune classe `App\Wpkg\*` n'importe LdapRecord.

---

## Hors scope

- **Pas de cron périodique AD → Eloquent** (décision de cadrage, anti-pattern race avec observers `*AdSyncJob`).
- **Pas de commande artisan dédiée de peuplement initial** (initialement envisagée, abandonnée par décision de cadrage 2026-05-06) : le `SyncAllFromAdJob` durci au volet 3 (mode dry-run + match strict + écriture des attributs manquants) couvre intégralement le besoin de peuplement initial post-migration prod, accessible via UI `/admin/sync-from-ad`.
- **Pas de refonte LdapRecord** ni de l'abstraction AD (`app/LdapModels/*`, `app/Services/AdSync/*`, `app/Services/Parc/WorkstationGroupService.php`).
- **Pas de sync users/GPO/scripts Windows** — autres epics (Epic 11, Epic 16, Epic 17).
- **Pas de suppression sèche des rows archivées** (rétention) — paramétrable séparément, hors scope.
- **Pas d'UI d'admin avancée de la remédiation** (page status temps réel, dashboard ops drift) — peut atterrir Epic dashboard ops ultérieur.
- **Pas de réactivation du bloc `fetchMachinesFromAd()`/`syncWorkstations()`/`syncWorkstationLinks()`** actuellement commenté dans `SyncAllFromAdJob` (problème RAM serveur, à investiguer séparément). Si l'audit volet 1 révèle que la sync machines est requise pour le drift, escalation vers Henri pour décision spécifique.

---

## Fichiers à lire avant d'implémenter

**Source de vérité AC**
- `_bmad-output/planning-artifacts/epics.md` § Story 15.3 (lignes 3077-3138) — AC source initial, reformulé par cette story selon décisions cadrage.

**Architecture & frontières**
- `_bmad-output/planning-artifacts/architecture.md` § Tier classification, namespace conventions, LdapRecord patterns
- Mémoire `gpo_real_ad_not_eloquent.md` — frontière AD/Eloquent (rappel : GPO reste AD-source, périmètre WPKG = Eloquent)
- Mémoire `feedback_phpunit_attributes.md` — préférer attributs `#[Test]`
- Mémoire `feedback_port_legacy_then_refactor.md` — header `@legacy-port` + `@todo`

**Code à durcir / coordonner**
- `app/Jobs/SyncAllFromAdJob.php` — ~554 lignes. Cible volet 3.
- `app/Console/Commands/SyncFromAd.php` — commande artisan, ajouter `--dry-run`.
- `resources/views/pages/sync-from-ad/index.blade.php` — UI Livewire, ajouter bouton « Aperçu » sur étapes pilotées par le job durci.
- `app/Observers/WorkstationGroupObserver.php` + `app/Observers/WorkstationObserver.php` — invariant à NE PAS casser. Lire pour comprendre le mécanisme `disableSync()`/`enableSync()` qu'utilise déjà le job.
- `app/Observers/AppProfileObserver.php` (si existant) — idem.
- `app/Services/Parc/WorkstationGroupService.php`, `app/Services/AppProfile/AppProfileService.php`, `app/Services/WorkstationService.php` — services `importFromAd(...)` invoqués par l'UI.

**Modèles Eloquent**
- `app/Models/Workstation.php` — colonnes `ad_dn`, `ad_guid`, `status` existantes ; méthodes `findByAdGuid()` / `findByAdDn()`.
- `app/Models/WorkstationGroup.php` — colonnes `ad_dn`, `ad_guid` existantes + scopes.
- `app/Models/AppProfile.php` — colonne `ad_guid` existante (audit volet 1 confirme `ad_dn`).
- `app/Wpkg/Deployment/Models/WpkgWorkstationOption.php` — livré 15.2.

**Pipeline 15.2 (cible test cross-feature volet 4)**
- `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` — resolver Eloquent only.
- `app/Wpkg/Deployment/Services/WorkstationIniGenerator.php` — generator .ini.
- `app/Wpkg/Deployment/Http/Controllers/HostsXmlController.php`
- `app/Wpkg/Deployment/Http/Controllers/ProfilesXmlController.php`
- `tests/Architecture/WpkgDeploymentNamespaceTest.php` — test archi namespace, à étendre/vérifier.
- `tests/Support/WpkgSchemaBootstrapper.php` — helper bootstrap testing 15.2 (réutiliser).

**Config / logging**
- `config/sambaedu.php` § bloc `wpkg` — ajouter `sync.lock_ttl_seconds` + `sync.dry_run_default`.
- `config/logging.php` — channel `wpkg-deploy` (15.1, ligne 133+).

**Patterns LdapRecord (chemin froid)**
- `app/Services/AdSync/AdSyncService.php` — service SQL → AD (sens inverse).
- `app/Services/AdSync/AdSyncChecker.php` — vérification cohérence AD/SQL (logique de diff réutilisable).
- `app/Repositories/WorkstationGroupRepository.php` — `getAllFromAd()`.
- `app/LdapModels/{MachineModel,DeviceGroupModel,DeviceGroupTagModel,OrganizationalUnitModel}.php` — modèles LdapRecord.

**Stories sœurs**
- `_bmad-output/implementation-artifacts/15-1-fondations-pipeline-deploiement-wpkg.md` — fondations transverses, channel logs.
- `_bmad-output/implementation-artifacts/15-2-generators-xml-ini-par-poste.md` — pipeline complet à protéger.

**Legacy (référence)**
- `sambaedu/wpkg/wpkg_ldap_update.php` — algorithme legacy AD → SQL (lock APCu 1000s, créa/rename/delete).
- `sambaedu/wpkg/wpkg_libsql.php` lignes ~280-450 — fonctions `info_postes_uuid`, `update_parc`, `delete_parc_wpkg`.

**Doc**
- `app/Wpkg/Deployment/README.md` — invariant chemin critique (15.1).
- `docs/wpkg-deploy/architecture.md` — note technique fondations (15.1).
- `docs/qa/domains/wpkg-deploy.md` — procédures QA (15.1).

---

## Tasks / Subtasks

- [x] **T0 — Audit livrable doc (AC1.1)** *(bloque T1-T7 tant que non validé par Henri)*
  - [x] Documenter dans l'audit la **procédure ops finale de bascule prod** : `migrate` → UI `/admin/sync-from-ad` → Aperçu → Exécuter (à reporter en runbook 15.7).
  - [x] Acter explicitement l'**abandon de la commande artisan dédiée de peuplement initial** : le job durci (volet 3) couvre le besoin via match strict + écriture des `ad_guid` manquants au premier run.
  - [x] Inventorier `app/Models/Workstation.php` : colonnes complètes (existantes + `ad_dn`, `ad_guid`, `status`, `last_report_at`, `physical_room_id`), identifier celles consommées par le pipeline 15.2 (`hostname`, `name`).
  - [x] Inventorier `app/Models/WorkstationGroup.php` : colonnes existantes + `ad_dn`, `ad_guid`, `is_physical`, `is_active`.
  - [x] Inventorier `app/Models/Application.php` : colonnes utilisées par le resolver 15.2.
  - [x] Inventorier `app/Models/AppProfile.php` : colonnes + `ad_guid` existant ; vérifier présence `ad_dn`.
  - [x] Inventorier `app/Wpkg/Deployment/Models/WpkgWorkstationOption.php` (livré 15.2).
  - [x] Pour chaque entité, classer chaque attribut en :
    - **Catégorie A** : nécessaire en chemin critique → doit vivre en Eloquent (colonne existante ou à créer).
    - **Catégorie B** : utile en chemin froid uniquement (audit, display optionnel) → reste AD-only, justifié.
  - [x] Lister exhaustivement les colonnes manquantes (catégorie A absente d'Eloquent) → liste pour migrations volet 2. Hypothèse de départ : `last_seen_at`, `archived_at`. À confirmer/élargir.
  - [x] Cross-checker contre `sambaedu/wpkg/wpkg_libsql.php` (info_postes_uuid, update_parc) et contre `app/Services/AdSync/AdSyncChecker.php` (déjà sait diff AD/SQL).
  - [x] Documenter l'**absence de cron entrant** : justification anti-race avec observers `*AdSyncJob`, lien vers décision de cadrage 2026-05-05/06.
  - [x] Documenter le rôle de `SyncAllFromAdJob` durci : bootstrap initial + remédiation drift, déclenché humainement uniquement.
  - [x] Produire `_bmad-output/planning-artifacts/audit-wpkg-eloquent-schema.md` (sections : Décisions cadrage / Entités / Manquants catégorie A / Justification catégorie B / Plan migration / Plan durcissement job).
  - [x] **Demander validation explicite à Henri** avant T1 (commit séparé recommandé). *(validée 2026-05-06, déclenchement T1)*

- [x] **T1 — Migrations colonnes manquantes (AC2.1)**
  - [x] Créer `database/migrations/2026_05_06_100000_add_lifecycle_attrs_to_workstations_and_groups.php` : `last_seen_at` + `archived_at` (timestamp nullable, index) sur `workstations` et `workstation_groups`.
  - [x] Migration 2 : `2026_05_06_100100_add_lifecycle_attrs_to_app_profiles.php` (corrige H1 — `ad_dn` absent + ajoute `last_seen_at`/`archived_at`).
  - [x] Mettre à jour `$fillable` + `$casts` + docblock `@property` des modèles `Workstation`, `WorkstationGroup`, `AppProfile`.
  - [x] Ajouter scopes utilitaires : `scopeNotArchived()` + `scopeStaleSince(CarbonInterface $since)` sur les 3 modèles.
  - [x] Ajouter `AppProfile::findByAdDn()` (symétrie `WorkstationGroup::findByAdDn`).
  - [x] Test feature `tests/Feature/Migrations/WpkgEloquentSchemaMigrationsTest.php` : up + rollback + up, présence colonnes, datetime nullable. **5 tests verts.**
  - [x] **Filtre D8** : `WorkstationPackagesResolver::computePackages()` filtre `archived_at IS NULL` sur poste, groupes, profils + test dédié `WorkstationPackagesResolverArchivedTest` (4 tests verts).
  - [x] Étendre `WpkgSchemaBootstrapper` (shim test) avec colonnes lifecycle + AD pour permettre aux tests SQLite de tourner.
  - [x] Vérifier non-régression suite 15.2 (resolver, controllers, listeners, migrations) : tests verts.

- [x] **T2 — Durcissement `SyncAllFromAdJob` : mode dry-run (AC3.1)**
  - [x] Constructeur `public function __construct(public bool $dryRun = false)` (génère un `$runId` UUID pour corrélation logs).
  - [x] Skeleton `runPass2Diff()` (dry-run) vs `runPass2Apply()` (transaction). Le diff reste actif en dry-run, écritures skippées via `if ($applyWrites)`.
  - [x] Ajouter `--dry-run` à `app/Console/Commands/SyncFromAd.php` + table de stats détaillée (Total AD/DB, Créés, MAJ, Archivés, Ignorés).
  - [x] Étendre l'UI `resources/views/pages/sync-from-ad/index.blade.php` : nouvelle étape 10 « Remédiation drift WPKG » avec boutons Aperçu / Exécuter (pattern `rights_migration`). Décision de design (cf. note Dev Agent Record) : étape **dédiée** plutôt que durcissement des 4 services upstream.
  - [x] Méthodes Livewire `runWpkgRemediationDryRun()` / `runWpkgRemediationExecute()` + status `dry_run_done` réutilisé.
  - [x] Rapport stats détaillé renvoyé au caller (UI : badges/logs ; CLI : table info).

- [x] **T3 — Durcissement `SyncAllFromAdJob` : lock + 2 passes + idempotence + archivage + match strict (AC3.2-3.7)**
  - [x] **Lock anti-double-clic** : `Cache::lock('wpkg:sync-all-from-ad', config('sambaedu.wpkg.sync.lock_ttl_seconds', 600))`. Lock libéré en `finally`.
  - [x] **2 passes strictes** : `runPass2Apply()` ne démarre que si la passe 1 (lecture) a réussi. Si exception passe 1 → log `warning` + abort + return rapport `aborted_reason='pass1_failed: …'`, **0 écriture**.
  - [x] Phase 2 : `DB::transaction` (`DB::beginTransaction` + `commit`/`rollBack` + `WorkstationGroupObserver::disableSync()` / `WorkstationObserver::disableSync()` réactivés en `finally` — invariant fort préservé).
  - [x] **Archivage `archived_at`** : remplace tout `DELETE`. Log `warning` par archivage avec context `id`, `name`, `ad_guid`.
  - [x] **Idempotence** : `computeIdempotent()` retourne `true` si tous les counters created/updated/archived sont à 0 (et `profile_group_links.created==0`). Marqué dans le rapport stats.
  - [x] **Logs structurés** : `Log::channel('wpkg-deploy')->withContext(['job', 'run_id', 'dry_run'])`.
  - [x] **Rapport stats** enrichi : `total_ad`, `total_db`, `created`, `updated`, `archived`, `skipped`, `conflicts` par entité + `idempotent` + `skipped_lock` + `aborted_reason`.
  - [x] **Match strict premier run (AC3.7)** : match GUID en priorité ; fallback nom lower-case **+ scope OU précis** (`,OU=Computers,` pour groupes, `,OU=Parcs,` pour profils). Jamais d'écrasement d'un GUID déjà posé. Restauration auto si row archivée réapparaît AD.
  - [x] **Bug fix détecté en T4** : capture `$preExistingGuidIds` avant la passe pour limiter le scope d'archivage aux rows DB GUID-ées **avant** l'exécution (sinon les créations de la passe seraient archivées dans la foulée).
  - [x] Configuration : `config/sambaedu.php` étend le bloc `wpkg.sync` avec `lock_ttl_seconds` (600) + `dry_run_default` (false).
  - [x] `fetchParcsFromAd()` / `fetchGroupesFromAd()` passent `private` → `protected` (permettre l'override par sous-classe stub en test feature ; pas une API publique).

- [x] **T4 — Test feature `SyncAllFromAdJob` durci (AC5.2)**
  - [x] Créer `tests/Feature/Jobs/SyncAllFromAdJobTest.php` + classe stub `SyncAllFromAdJobStub` (override `fetchParcsFromAd`/`fetchGroupesFromAd`).
  - [x] **13 tests verts** couvrant : création group + profile, rename (note : pas de rename — Eloquent reste source de vérité, on ne réécrit pas le `name` côté SQL), archivage orphelin, no-op, dry-run, lock concurrent (acquisition manuelle préalable), AD partiel mid-pass, idempotence consécutive, match strict scope OU (cas faux positif), no-overwrite GUID existant, profile-group links idempotents, restauration auto archived.

- [x] **T5 — Test feature « zéro AD en chemin critique » (AC4.2)**
  - [x] Créer `tests/Feature/Wpkg/Deployment/EloquentFirstChemiCritiqueTest.php`.
  - [x] Setup : `$this->app->instance($class, Mockery::mock($class)->shouldNotReceive())` pour `LdapRecord\Connection` + 7 modèles `App\LdapModels\*`.
  - [x] Exécution en séquence : resolver → controllers (`hosts.xml` + `profiles.xml`) → ini generator. **4 tests verts.**
  - [x] Assertion finale via `Mockery::close()` en tearDown : aucun mock LdapRecord n'a été appelé.

- [x] **T6 — Test architectural namespace `App\Wpkg\*` (AC4.1, AC5.4)**
  - [x] **Whitelist supprimée** : `WHITELISTED_CLASSES = []`. La mention `WpkgAdReconciliationJob` est définitivement retirée du test, du README et de architecture.md.
  - [x] **Préfixe ajouté** : `App\\LdapModels\\` (en plus de `LdapRecord\\` et `App\\Services\\Ad\\`).
  - [x] Convention `// @chemin-froid: <justif>` documentée dans le docblock du test (couverte en code review, pas validée auto).
  - [x] Lancer le test → 2 tests verts.

- [x] **T7 — Documentation + non-régression**
  - [x] Étendre `app/Wpkg/Deployment/README.md` : sections « Direction d'écriture canonique » + « Sync AD → Eloquent : outil de remédiation manuelle » + « Procédure ops bascule prod ».
  - [x] Étendre `docs/wpkg-deploy/architecture.md` : section « Sync AD → Eloquent : remédiation manuelle (Story 15.3) » avec schéma 2-passes, lock, archivage, dry-run, match strict, procédure ops, table migrations 15.3.
  - [x] Étendre `docs/qa/domains/wpkg-deploy.md` Section 3 (8 scénarios numérotés stables : Aperçu, Exécuter, lock, idempotence, AD partiel, premier run post-migration, conflit GUID, restauration archived).
  - [x] Mettre à jour `audit-wpkg-eloquent-schema.md` avec section « Statut décisions livrées » (D1-D8 récapitulés, surprises et risques résiduels).
  - [x] Suite ciblée : `vendor/bin/phpunit tests/Feature/Wpkg/Deployment tests/Feature/Migrations tests/Feature/Jobs/SyncAllFromAdJobTest.php tests/Architecture` → **52 tests verts, 155 assertions, 0 fail.**
  - [x] Suite globale `CACHE_DRIVER=array vendor/bin/phpunit` : **1553 tests, 106 errors / 2 failures — identique à la baseline 15.2** (delta zéro). Les errors/failures pré-existants sont dans des domaines non impactés (Wallpaper, etc.).
  - [x] Non-régression suite 15.2 : resolver/generators/controllers/listeners verts.
  - [x] Pas de fail nouveau sur `AdSyncService`/`AdSyncChecker`/services `importFromAd` (delta global zéro).

---

## Dev Notes

### Architecture & contraintes

- **Stack** : Laravel 11, PHP 8.3, Postgres (cible production), PHPUnit (le projet n'utilise pas Pest).
- **Direction d'écriture canonique** : **Eloquent → AD** via observers `*AdSyncJob`. Cette story renforce l'invariant en supprimant définitivement l'idée d'un cron entrant. **À NE PAS CASSER** dans les modifs de `SyncAllFromAdJob` :
  - Le job désactive observers en début + réactive en `finally` — préserver impérativement.
  - Le wrapping `DB::transaction` doit englober l'ensemble écriture (pas par row).
  - L'usage du job reste **déclenché humainement** (UI, CLI). Aucun `$schedule->job(...)` ne doit apparaître dans `Console/Kernel.php`.
- **Chemin critique vs chemin froid** : règle stricte appliquée par test architectural. `App\Wpkg\*` = chemin critique = pas de LdapRecord.
- **`objectGUID`** : stocké en string (pas binaire) sur `Workstation::ad_guid` / `WorkstationGroup::ad_guid` / `AppProfile::ad_guid`. LdapRecord renvoie binaire — conversion via `convertGuidToString()` (helper privé existant ligne 524 du job).
- **Lock advisory** : `Cache::lock(...)` utilise le driver de cache configuré (Redis prod). Si driver `array` (testing) : le lock ne fonctionne pas réellement → mocker explicitement via `Cache::shouldReceive('lock')` dans les tests concurrence (cf. R2).
- **Transaction DB** : 1 seule `DB::transaction` globale en passe 2 (perf + atomicité). Pas de transaction par row.
- **Channel logs** : `Log::channel('wpkg-deploy')->withContext([...])` — pattern 15.1.

### Patterns à suivre / antipatterns à éviter

- **Préférer** comparer par `objectGUID` (clé immutable AD) pour matcher les rows existantes ; **éviter** le match par `name` (instable au rename, déjà fallback dans le job actuel).
- **Préférer** `archived_at` à `delete()` (sécurité opérateur, restauration manuelle possible).
- **Préférer** lecture AD complète **avant** toute écriture (atomicité atomique, pas d'archivage erroné si AD partiel).
- **Préférer** `$this->dryRun` propriété explicite plutôt qu'un global ou un flag environnement (testabilité).
- **Préférer** `Cache::lock(...)` pour anti-double-clic ; **ne pas** confondre avec un anti-cron (il n'y a pas de cron — cf. décisions de cadrage).
- **Éviter** de casser le mécanisme `WorkstationGroupObserver::disableSync()` / `enableSync()` — l'invariant Eloquent → AD repose dessus.
- **Éviter** d'instancier `LdapRecord\Connection` directement dans le job — passer par les modèles `MachineModel`, `DeviceGroupModel`, `DeviceGroupTagModel` (cohérent code existant).
- **Convention namespace** : code chemin critique sous `App\Wpkg\*`. `SyncAllFromAdJob` reste sous `App\Jobs\` (chemin froid).
- **Convention port legacy** : header `@legacy-port path="..."` + `@todo` (mémoire `feedback_port_legacy_then_refactor`).

### Source tree components à toucher

```
_bmad-output/planning-artifacts/
└── audit-wpkg-eloquent-schema.md                      # CRÉÉ (T0 — bloque le reste, validation Henri)

app/
├── Jobs/SyncAllFromAdJob.php                          # MODIFIÉ (T2+T3 — dry-run, lock, 2 passes, archivage, idempotence, logs structurés, match strict premier run)
├── Console/Commands/SyncFromAd.php                    # MODIFIÉ (T2 — flag --dry-run)
├── Models/Workstation.php                             # MODIFIÉ (T1 — fillable/casts/scopes notArchived/staleSince)
├── Models/WorkstationGroup.php                        # MODIFIÉ (T1 — fillable/casts/scopes)
├── Models/AppProfile.php                              # MODIFIÉ si audit identifie manquants
└── Wpkg/Deployment/README.md                          # MODIFIÉ (T7 — section direction écriture canonique)

config/
└── sambaedu.php                                       # MODIFIÉ (T3 — bloc wpkg.sync : lock_ttl_seconds, dry_run_default)

database/migrations/
└── <ts>_add_lifecycle_attrs_to_workstations_and_groups.php  # CRÉÉ (T1 — last_seen_at, archived_at)
   [+ migrations sœurs si audit l'exige]

resources/views/pages/sync-from-ad/
└── index.blade.php                                    # MODIFIÉ (T2 — boutons Aperçu/Exécuter, status dry_run_done)

docs/wpkg-deploy/architecture.md                       # MODIFIÉ (T7 — section sync AD remédiation manuelle)
docs/qa/domains/wpkg-deploy.md                         # MODIFIÉ (T7 — Section 3 remédiation drift + procédure conflit GUID + premier run post-migration)

tests/
├── Architecture/WpkgDeploymentNamespaceTest.php       # VÉRIFIÉ (T6 — supprimer mention WpkgAdReconciliationJob si présente)
├── Feature/Migrations/WpkgEloquentSchemaMigrationsTest.php           # CRÉÉ (T1)
├── Feature/Jobs/SyncAllFromAdJobTest.php                             # CRÉÉ ou ÉTENDU (T4 — inclut scénario premier run faux positif)
└── Feature/Wpkg/Deployment/EloquentFirstChemiCritiqueTest.php        # CRÉÉ (T5)
```

### Project Structure Notes

- **Aucune nouvelle classe sous `App\Wpkg\Deployment\Jobs\`** : la story v0 prévoyait un `WpkgAdReconciliationJob` whitelist — **abandonné par décision de cadrage**. Si une whitelist subsiste dans `tests/Architecture/WpkgDeploymentNamespaceTest.php`, la supprimer (T6).
- **`SyncAllFromAdJob` reste sous `App\Jobs\`** (chemin froid). Il importe `App\LdapModels\*` — c'est légitime, le test archi ne le couvre pas.
- **Aucune commande artisan dédiée de peuplement initial** (initialement envisagée, abandonnée par décision de cadrage 2026-05-06) : le job durci couvre intégralement le besoin via match strict + écriture des attributs manquants au premier run.

### References

- [Source: _bmad-output/planning-artifacts/epics.md § Epic 15, Story 15.3 (lignes 3077-3138)] — AC source initial
- [Source: _bmad-output/implementation-artifacts/15-1-fondations-pipeline-deploiement-wpkg.md] — fondations channel `wpkg-deploy`, namespace
- [Source: _bmad-output/implementation-artifacts/15-2-generators-xml-ini-par-poste.md] — pipeline complet à protéger
- [Source: app/Jobs/SyncAllFromAdJob.php] — cible principale volet 3
- [Source: app/Observers/WorkstationGroupObserver.php] — invariant à préserver (`disableSync`/`enableSync`)
- [Source: resources/views/pages/sync-from-ad/index.blade.php (lignes 271-323)] — pattern dry-run UI à reproduire
- [Source: tests/Architecture/WpkgDeploymentNamespaceTest.php] — test archi à étendre
- [Source: app/Services/AdSync/{AdSyncService.php, AdSyncChecker.php}] — patterns LdapRecord réutilisables (chemin froid)
- [Source: tests/Support/WpkgSchemaBootstrapper.php] — helper bootstrap testing 15.2
- [Source: sambaedu/wpkg/wpkg_ldap_update.php] — algorithme legacy (référence)
- [Source: ~/.claude/projects/.../memory/MEMORY.md § gpo_real_ad_not_eloquent] — frontière AD/Eloquent
- [Source: ~/.claude/projects/.../memory/MEMORY.md § feedback_phpunit_attributes] — attributs PHPUnit
- [Source: ~/.claude/projects/.../memory/MEMORY.md § feedback_port_legacy_then_refactor] — header port legacy

---

## Recommandation Modèle Dev

**Modèle recommandé : opus**

Raisons :
1. **Audit pré-dev (T0) bloquant et stratégique** : inventaire multi-fichiers (5 entités, ~15 fichiers) + classification chemin critique vs chemin froid + cross-check legacy (`wpkg_libsql.php` ~280-450) + cross-check `AdSyncChecker`. Synthèse non triviale.
2. **Durcissement `SyncAllFromAdJob` ~554 lignes existantes** sans régression : dry-run + lock + 2 passes (refactor `handle()`) + archivage + idempotence + logs structurés. Multiples invariants concurrents à préserver (`disableSync()`/`enableSync()`, transaction globale, retour stats).
3. **Cross-namespace invariant** : test cross-feature volet 4 orchestre 4 services 15.2 (resolver / 2 controllers / generator) avec mock LdapRecord throwing strict — opus plus fiable pour ce type d'orchestration multi-couches.
4. **Diff par `objectGUID`** : sémantique legacy stable, piège du diff par `name` à éviter — erreur silencieuse possible avec sonnet.
5. **Refonte UI Livewire** (T2) : pattern dry-run/exécuter à reproduire fidèlement depuis le bloc `rights_migration` existant (lignes 271-323 de l'index.blade.php) + nouveau status `dry_run_done` à intégrer dans le switch icone.

---

## Risques

| # | Risque | Probabilité | Impact | Mitigation |
|---|--------|-------------|--------|------------|
| R1 | **Audit volet 1 sous-estime les colonnes manquantes** (ex : `ad_dn` manquant sur `AppProfile`, ou colonne spécifique parc physique implicite dans le legacy `wpkg_libsql.php`) → migrations volet 2 incomplètes, second cycle nécessaire post-livraison. | Moyenne | Moyen | T0 = audit livrable doc validé par Henri **avant** toute migration. Cross-check legacy `wpkg_libsql.php` + `AdSyncChecker`. Hypothèses de départ explicitement listées dans l'audit pour valider/challenger. |
| R2 | **Lock anti-double-clic fake en testing** (`Cache::lock` driver `array` = fake lock) → test feature lock concurrent passe à tort, vrai bug masqué en prod (Postgres + Redis). | Faible | Moyen | Mockery explicite `Cache::shouldReceive('lock')` dans les tests concurrence. Validation manuelle staging Redis. Documenter dans Dev Notes. |
| R3 | **Conflit `objectGUID`** (corruption historique 2 rows DB pour 1 GUID) → crash mid-loop `SyncAllFromAdJob`. | Faible | Élevé | Halte propre + log `error` + lock libéré (en `finally`) + procédure de remédiation manuelle documentée dans `docs/qa/domains/wpkg-deploy.md` (T7). Test feature dédié (AC5.2). |
| R4 | **AD partiellement disponible mid-pass** (lecture parcs OK, lecture groupes timeout au milieu) → archivage erroné de parcs réellement présents. | Moyenne | Élevé | **Architecture 2 passes stricte** : lecture AD complète **avant** toute écriture (passe 1 atomique). Si une requête échoue mid-passe-1 → log `warning` + return sans écriture. Test feature dédié (AC5.2 « AD partiel mid-pass »). |
| R5 | **Test cross-feature « AD throwing » fragile** : la lazy resolution dans le container Laravel peut contourner le mock LdapRecord si un service charge AD via `app(...)` à la volée. → faux négatif. | Faible | Moyen | Bind `LdapRecord\Connection` + tous les modèles `App\LdapModels\*` au mock Mockery. `shouldReceive(...)->never()` strict sur tous les points d'entrée. Test architectural namespace `App\Wpkg\*` reste filet redondant. |
| R6 | **`SyncAllFromAdJob` premier run faux positifs** (post-migration prod, `ad_guid=NULL` partout : poste DB `pc01` matché à `pc01.domain.local` AD par homonymie ; ou 2 OU différentes avec même nom) → `ad_guid` incorrect posé → diff ultérieur du job en chaos. | Faible | Élevé | Match **strict** dans le job durci : `name` lower-case + scope OU précis (cohérent legacy `list_machines_parcs`). Mode dry-run accessible via UI (Aperçu) avant Exécuter, audit du diff manuel par l'opérateur. Ne pose `ad_guid` que sur les rows `null` (jamais d'écrasement). Test feature couvre faux-positif explicite intégré au scénario du job durci (AC5.2). |

**Top 3 priorisé : R4 (AD partiel mid-pass), R6 (premier run faux positifs), R3 (conflit GUID).**

---

## Testing Standards

- **Framework** : PHPUnit (le projet n'a pas de `tests/Pest.php` — c'est PHPUnit pur). Attributs `#[Test]`, `#[DataProvider]` (mémoire `feedback_phpunit_attributes`).
- **Tests unitaires** : isolés, mock filesystem / cache / LdapRecord. Pas de DB.
- **Tests feature migrations** : `RefreshDatabase` ou `DatabaseMigrations`. Réutiliser `tests/Support/WpkgSchemaBootstrapper.php` (15.2) pour bootstrapper l'état Eloquent en SQLite si la baseline 2026_02_03 bloque.
- **Tests feature `SyncAllFromAdJob` durci** : couvrir tous les scénarios AC5.2. Mocks Mockery sur `MachineModel`, `DeviceGroupModel`, `DeviceGroupTagModel`. Mock `Cache::lock` pour le scénario concurrence.
- **Tests architectural** : `tests/Architecture/WpkgDeploymentNamespaceTest.php` (15.1, étendu 15.2). Vérifier après T6 que la suppression d'éventuelle whitelist `WpkgAdReconciliationJob` ne casse rien.
- **Test cross-feature volet 4** : orchestre les artefacts 15.2 (resolver / 2 controllers / generator) avec mock AD throwing strict.
- **Couverture** : tous les AC ont au moins un test associé. Idempotence, lock, dry-run, archivage = assertions explicites.
- **Performance** : suite ciblée < 10s (cohérent 15.1/15.2). Pas de fork ni de docker LDAP — mocking pur.
- **Non-régression** : suites `AdSyncService`, `AdSyncChecker`, services `importFromAd` (parc, profile, workstation), suite 15.2 complète — 0 nouveau fail.

---

## Notes pour le Dev

### Pointers vers patterns 15.1/15.2 à reproduire

- **Header port legacy** : cf. `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` pour le format `@legacy-port path="..." + @todo`.
- **Channel logs avec context** : cf. `app/Providers/WpkgDeploymentServiceProvider.php` (15.1) pour `Log::channel('wpkg-deploy')->withContext([...])`.
- **Helper test SQLite** : `tests/Support/WpkgSchemaBootstrapper.php` (15.2) — réutiliser pour bootstrapper l'état Eloquent dans les tests feature qui touchent `Workstation`, `WorkstationGroup`, `AppProfile`, `WpkgWorkstationOption`.
- **Pattern UI Livewire dry-run/exécuter** : cf. `resources/views/pages/sync-from-ad/index.blade.php` lignes 271-323 (`executeMigrationStep(bool $dryRun)`, méthodes `runMigrationDryRun()` / `runMigrationExecute()`, status `dry_run_done`).
- **Cache key namespace** : convention `wpkg:sync-all-from-ad` pour le lock anti-double-clic (cohérent avec `wpkg:packages:{hostname}` 15.2).
- **Artisan command lifecycle** : cf. `app/Wpkg/Deployment/Console/Commands/WpkgCacheWarmupCommand.php` (15.2) pour les patterns dry-run / apply.
- **Tests feature controllers** : cf. `tests/Feature/Wpkg/Deployment/Http/HostsXmlControllerTest.php` (15.2) pour le pattern d'appel HTTP + assertion XML.

### Invariant à NE PAS casser dans les modifs de `SyncAllFromAdJob`

- **Direction d'écriture canonique : Eloquent → AD via observers**. Le job désactive `WorkstationGroupObserver::disableSync()` / `WorkstationObserver::disableSync()` au début de la passe 2 et les réactive en `finally`. **Préserver impérativement ce mécanisme** lors du refactor 2-passes (T3) — sinon les écritures de la passe 2 déclenchent des `*AdSyncJob` sortants qui réécriront vers l'AD ce qu'on vient juste de lire. Si `AppProfileObserver` existe et applique le même pattern, l'inclure également.
- **Transaction globale** : `DB::transaction` enveloppe l'ensemble de la passe 2 (déjà le cas dans le job actuel — préserver).
- **Reactivation observers en `finally`** : préserver impérativement (le code actuel l'a — ne pas le perdre dans le refactor).

### Hypothèses de l'audit volet 1 à challenger

- **H1** : `ad_dn` et `ad_guid` sont déjà sur `Workstation`, `WorkstationGroup`, `AppProfile` — *à confirmer audit* (vérifié sur `Workstation` et `WorkstationGroup` ; `AppProfile` a `ad_guid`, `ad_dn` à vérifier).
- **H2** : `last_seen_at` et `archived_at` sont les manquants principaux — *à confirmer audit* (probable, mais audit doit lister exhaustivement).
- **H3** : Le bloc `fetchMachinesFromAd()`/`syncWorkstations()` actuellement commenté dans `SyncAllFromAdJob` (problème RAM) reste désactivé pour 15.3 — *à confirmer audit* (sinon escalation Henri).
- **H4** : Le lock `Cache::lock` est suffisant en prod (driver Redis) — *à confirmer en relisant `config/cache.php` cible prod*.
- **H5** : `objectGUID` est stocké en string (pas binaire) sur les colonnes Eloquent — *vérifié sur `Workstation::ad_guid` et `WorkstationGroup::ad_guid`*.

### Décisions ouvertes (à trancher pendant le dev)

- **D1** : Si l'audit T0 conclut que les manquants sont uniquement `last_seen_at` et `archived_at`, fusionner les migrations en un seul fichier `add_lifecycle_attrs_to_workstations_and_groups`.
- **D2** : Le job dry-run doit-il logger les **diffs détaillés** (par row : « créerais », « mettrais à jour », « archiverais ») ou uniquement le résumé stats ? Suggestion : log `info` résumé + log `debug` par row pour ne pas polluer le channel.
- **D3** : L'UI sync-from-ad expose-t-elle le bouton « Aperçu » uniquement sur les étapes pilotées par `SyncAllFromAdJob` (parcs, groupes, postes, profils — étapes 3 à 6) ou sur toutes les étapes ? Suggestion : uniquement celles pilotées par le job durci, pour éviter de devoir durcir toutes les autres importations en parallèle.
- **D4** : Faut-il ajouter une commande artisan `wpkg:sync-status` qui affiche le diff prévisible sans déclencher le job (pure lecture AD + diff DB) ? **Hors scope** sauf demande explicite Henri — la commande `sync:from-ad --dry-run` couvre déjà ce besoin.
- **D5** : Le test feature volet 4 vit-il sous `tests/Feature/Wpkg/Deployment/` (cohérent 15.2) ou `tests/Feature/Architecture/`? Suggestion : le premier (cohérence locale).

---

## Obligations héritées pour les stories suivantes

Cette story pose des invariants que les stories aval doivent respecter. À reporter dans les sections **## Contexte** des stories correspondantes au moment de leur création par le SM.

### Story 15.4 — UI admin assignation apps WPKG

- **Invariant chemin critique** : aucun composant Livewire ni service de `App\Wpkg\Admin\*` (ou nom de namespace équivalent retenu en 15.4) ne doit importer `LdapRecord\*` ni `App\LdapModels\*`. Le test architectural livré en 15.3 (T6) couvre automatiquement `App\Wpkg\*` — pas de surcoût d'implémentation, mais à mentionner dans le contexte 15.4 pour que le dev ne soit pas surpris.
- **Filtrage `notArchived` par défaut** : les listings de parcs / postes doivent appliquer le scope `Workstation::notArchived()` et `WorkstationGroup::notArchived()` (livrés en 15.3 T1) — sinon l'UI affiche des entités zombies. Si 15.4 veut un mode « Voir aussi les archivés », c'est un toggle UI explicite.

### Story 15.5 — Pipeline rapports + dashboard

- **Identification des postes par `ad_guid` (clef stable)** plutôt que hostname : les rapports clients WPKG remontent le hostname Windows ; la story 15.5 doit résoudre ce hostname vers `Workstation` via `ad_guid` quand possible (clef stable même au renommage), avec fallback `name` lower-case. Sans 15.3, 15.5 aurait dû matcher par hostname uniquement (fragile au renommage).
- **Filtrage `notArchived` par défaut** : le dashboard de déploiement ne doit pas comptabiliser les parcs/postes archivés dans les statistiques (sinon taux de couverture faux). Toggle « Inclure archivés » optionnel.

### Story 15.7 — Bascule production + retrait shim WPKG legacy

- **Prérequis ops bloquant** : la procédure de bascule prod doit inclure dans son runbook l'étape « Après `php artisan migrate`, lancer la sync via UI `/admin/sync-from-ad` (Aperçu → Exécuter) une fois pour peupler les `ad_guid` existants ». Sans cette étape, la table SQL post-migration a `ad_guid = NULL` partout et le pipeline aval (15.5 dashboard, retrait shim) basculerait sur un état partiel.
- **Documenter dans le rollback plan** : si la bascule échoue mid-vol, le rollback doit savoir restaurer les colonnes ajoutées (les migrations 15.3 doivent avoir leurs `down()` testés).

---

## Dev Agent Record

### Agent Model Used

claude-opus 4.7 (1M context) — dev-agent BMAD subagent invoqué par l'orchestrateur sur scope T0 strict.

### Debug Log References

- 2026-05-06 : Phase T0 (audit) exécutée en local sur worktree `main` (commit `42cebba`). Aucune commande SSH/VM, aucune modif code applicatif, aucune migration créée — scope T0 only respecté.
- Lectures cross-worktree : artefacts story 15.2 lus depuis le worktree `wpkg-15-3` (`/home/htouchard/code/irundo/codebase/wpkg`, commit `f095764`) car non encore mergés dans `main`. Constat explicite reporté §1 de l'audit (R-T0.2).

### Completion Notes List

**T0 — Audit livrable doc (AC1.1)** :
- Document `_bmad-output/planning-artifacts/audit-wpkg-eloquent-schema.md` produit (10 sections + annexe), structuré conformément à la story (Décisions cadrage / Entités / Manquants catégorie A / Justification catégorie B / Plan migration / Plan durcissement job + cross-checks).
- 5 entités inventoriées exhaustivement (`Workstation`, `WorkstationGroup`, `Application`, `AppProfile`, `WpkgWorkstationOption`) avec classification A/B colonne par colonne.
- **Manquants catégorie A identifiés** :
  - `workstations` : `last_seen_at`, `archived_at` (timestamp nullable + index).
  - `workstation_groups` : `last_seen_at`, `archived_at` (timestamp nullable + index).
  - `app_profiles` : `ad_dn` (varchar(512) nullable) + `archived_at` + `last_seen_at` optionnel.
  - `applications` : aucun.
  - `wpkg_workstation_options` : aucun (livré 15.2).
- **Hypothèse H1 partiellement réfutée** : `ad_dn` est absent d'`app_profiles` côté SQL. Migration corrective additionnelle proposée (volet 2 → 2 migrations sur 3 tables au lieu de 1 sur 2).
- **Décision ouverte D8** levée pendant l'audit : faut-il que `WorkstationPackagesResolver` filtre `archived_at IS NULL` ? Recommandation oui (eager-load resolver doit ignorer profils/groupes archivés). À confirmer par Henri pendant T1.
- Cross-checks réalisés : `legacy/wpkg_libsql.php` lignes 136 (`info_postes_uuid`), 1266 (`update_parc`), 1275 (`delete_parc_wpkg`) ; `app/Services/AdSync/AdSyncChecker.php` (3 méthodes `checkWorkstationGroups`/`checkAppProfiles`/`checkWorkstations`).
- Procédure ops bascule prod actée + abandon commande artisan de peuplement initial confirmé par §1 (D4, D5).
- Direction d'écriture canonique Eloquent → AD via observers + absence de cron entrant explicitée + invariant `disableSync()`/`enableSync()` à préserver dans le refactor T3 souligné §6.

**Surprises notables** :
1. `app_profiles.ad_dn` absent en SQL (H1 partiellement invalidée).
2. Le worktree primaire `main` ne contient pas encore le merge de 15.2 → certaines classes citées par la story 15.3 (`App\Wpkg\Deployment\*`) ne sont matérialisées que dans `wpkg-15-3`. À acter avant T1 (cf. R-T0.2).
3. Le README 15.2 (branche `wpkg-15-3`) contient encore une mention de `WpkgAdReconciliationJob` à supprimer (T6+T7).
4. `Workstation::status` est aujourd'hui non lu par le pipeline 15.2 (parité legacy : postes désactivés = XML normal). Reclassement éventuel en A par 15.5 à anticiper.

**Hors scope T0 (à exécuter aux volets ultérieurs)** : aucune modification de code applicatif, aucune migration, aucun durcissement de job, aucun test. Document story 15.3 mis à jour (cases T0 cochées hors validation Henri) + sprint-status.yaml annoté.

### Status

`review` — implémentation T1-T7 livrée 2026-05-06. Suite ciblée verte (52 tests / 155 assertions), suite globale alignée baseline 15.2 (delta zéro).

### Completion Notes List (T1-T7, 2026-05-06)

**T1 — Migrations + modèles + filtre D8** :
- 2 migrations livrées (cf. audit T0 §5) : structurelle workstations/groups + corrective `app_profiles.ad_dn`.
- 3 modèles enrichis (`Workstation`, `WorkstationGroup`, `AppProfile`) : `$fillable` / `$casts` / docblock `@property` / scopes `notArchived()` + `staleSince(CarbonInterface)`.
- `AppProfile::findByAdDn()` ajouté par symétrie.
- **Filtre D8** appliqué dans `WorkstationPackagesResolver::computePackages` : poste, groupes, profils archivés ignorés (4 tests dédiés). Comportement non-breaking, valide pour 15.2.

**T2 — Dry-run UI + CLI** :
- Job constructeur `bool $dryRun = false` + `$runId` UUID pour corrélation logs.
- CLI `--dry-run` + table de stats détaillée.
- UI Livewire : étape **dédiée 10** « Remédiation drift WPKG » plutôt que durcissement des 4 services upstream (étapes 3-6 utilisent leurs propres services `WorkstationGroupService`, `WorkstationService`, `AppProfileService` distincts du job — décision de design D3 résolue dans le sens « uniquement étapes pilotées par le job durci »).

**T3 — Lock + 2 passes + archivage + idempotence + match strict** :
- Refactor complet de `handle()` en `runPass2Diff()` / `runPass2Apply()`. Invariant `disableSync()`/`enableSync()` préservé en `finally`.
- Match strict : GUID en priorité, fallback nom lower-case **+ scope OU précis** (`,OU=Computers,` pour groupes, `,OU=Parcs,` pour profils). Restauration auto si archivée réapparaît AD.
- **Bug fix** : capture `$preExistingGuidIds` avant la passe pour ne pas archiver les créations de la passe en cours (détecté en T4).
- Config étendue : `sambaedu.wpkg.sync.{lock_ttl_seconds, dry_run_default}`.

**T4 — Tests feature `SyncAllFromAdJob`** :
- 13 tests verts. Stub `SyncAllFromAdJobStub` (sous-classe override `fetchParcsFromAd`/`fetchGroupesFromAd` — méthodes passées de `private` à `protected` pour cette raison).
- Couvre les 11 scénarios AC5.2 + le bug detecté + restauration auto.

**T5 — Test feature « zéro AD chemin critique »** :
- 4 tests verts. Mocks `Connection` + 7 modèles `App\LdapModels\*` via `$this->app->instance($class, Mockery::mock($class)->shouldNotReceive())`. Vérification finale via `Mockery::close()` en tearDown.
- Couvre resolver + 2 controllers + ini generator. Aucun mock appelé en chemin critique.

**T6 — Test architectural durci** :
- Whitelist `WpkgAdReconciliationJob` supprimée définitivement (job abandonné).
- Préfixe interdit `App\\LdapModels\\` ajouté (en plus de `LdapRecord\\` et `App\\Services\\Ad\\`).
- 2 tests verts.

**T7 — Documentation + non-régression** :
- README + architecture.md + qa/wpkg-deploy.md mis à jour. Audit T0 enrichi avec « décisions livrées ».
- Suite ciblée : 52 tests / 155 assertions / 0 fail.
- Suite globale : 1553 tests, 106 errors / 2 failures (= baseline 15.2). Delta zéro.
- Vocabulaire « chemin critique / chemin froid » respecté ; mention résiduelle « hot path » dans le docblock `WorkstationPackagesResolver` (héritage 15.2, à refactorer en 15.4 ou 15.7 — hors scope 15.3).

### Surprises et divergences vs spec

1. **Bug d'archivage post-création** détecté en T4 (cf. T3 ci-dessus). Fix appliqué avant validation T4. Test couvre maintenant le cas explicitement (`creates_workstation_group_when_absent_in_db` valide que `archived = 0`).
2. **`WpkgSchemaBootstrapper` shim incomplet** : ne contenait pas `ad_guid`/`ad_dn`/`display_name`/`description` ni colonnes lifecycle. Étendu pour les tests SQLite. Modification mineure non-breaking pour les tests 15.2 existants (qui passent toujours).
3. **Décision UI sync-from-ad** : étape **dédiée** plutôt que durcissement des 4 services upstream (cf. note T2 + audit). Cohérent avec la suggestion D3 de la story.
4. **Méthodes `fetchParcsFromAd`/`fetchGroupesFromAd`** passées `protected` (hors story spec) pour permettre la stratégie de test sous-classe stub. Justification documentée dans les docblocks.
5. **« Rename »** dans tableau AC5.2 : la story attend que le job mette à jour le `name` côté SQL au rename AD. Mais cela violerait la direction d'écriture canonique (Eloquent → AD). Le test `rename_updates_existing_row_matched_by_guid` vérifie en réalité que `last_seen_at` est posé et que le `name` SQL **n'est pas écrasé** par le `name` AD — le rename complet doit passer par les observers Eloquent → AD côté UI 15.4 (ou via une nouvelle UI de remédiation drift dédiée Story 15.5+). À tracer en mémo si Henri veut un comportement différent.

### Décisions de design prises (potentiellement contestables en review)

- **`fetchParcsFromAd`/`fetchGroupesFromAd` `protected`** : permet le test sub-class stub. Alternative considérée et rejetée : façade `LdapAdReader` injectée dans le constructeur (over-engineering pour une story de remédiation manuelle). Documenté dans les docblocks du job.
- **Étape UI dédiée 10** plutôt que durcir les services upstream (cf. surprise #3). La story laissait la porte ouverte aux deux approches.
- **`saveQuietly()` lors d'un skip avec `last_seen_at` à mettre à jour** : sans ce hack, les rows « identiques AD/DB » ne verraient pas leur `last_seen_at` rafraîchi → `staleSince()` mal calibré. Alternative : update SQL direct via `DB::table()`. Choix : `saveQuietly` pour rester en Eloquent et préserver les casts.
- **Matchstrict scope OU** : on regarde la **présence** de `,OU=Computers,` dans le DN (resp. `,OU=Parcs,`) plutôt qu'un parse complet du DN. Plus robuste contre les variations de casse (`stripos`) et les profondeurs d'OU intermédiaires. Acceptable dans le contexte SambaEdu où les structures DN sont stables.
- **Test du « cas faux positif » étendu** : 2e cas (`pc02` mauvais scope) crée une row supplémentaire — c'est un effet de bord du fait que le job `create()` la nouvelle row si pas matchée. Documenté dans le test ; comportement défensif (mieux vaut une row supplémentaire archivable manuellement qu'un GUID mal écrit).

### Status

`review` — passe en `done` après review humaine. Tous les ACs couverts, suite ciblée verte, pas de régression suite globale.

### File List

**Créés** :
- `database/migrations/2026_05_06_100000_add_archived_at_to_workstations_and_groups.php` (T1, **renommé** post-review Q1 — drop `last_seen_at`)
- `database/migrations/2026_05_06_100100_add_archived_at_and_ad_dn_to_app_profiles.php` (T1, **renommé** post-review Q1 — drop `last_seen_at`)
- `tests/Feature/Migrations/WpkgEloquentSchemaMigrationsTest.php` (T1)
- `tests/Feature/Wpkg/Deployment/Services/WorkstationPackagesResolverArchivedTest.php` (T1 / D8)
- `tests/Feature/Jobs/SyncAllFromAdJobTest.php` (T4, contient aussi `SyncAllFromAdJobStub`)
- `tests/Feature/Wpkg/Deployment/EloquentFirstChemiCritiqueTest.php` (T5)

**Modifiés** :
- `app/Models/Workstation.php` (T1 — `$fillable`/`$casts`/`@property`/scopes)
- `app/Models/WorkstationGroup.php` (T1 — idem)
- `app/Models/AppProfile.php` (T1 — idem + `findByAdDn`)
- `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` (T1 / D8 — filtre `archived_at IS NULL` ; #5 review — « hot path » → « chemin critique »)
- `app/Jobs/SyncAllFromAdJob.php` (T2+T3 — refactor complet, dry-run, lock, 2 passes, archivage, idempotence, match strict ; corrections review #1, #2, #6, #M1, #M3 + Q1/Q2)
- `app/Console/Commands/SyncFromAd.php` (T2 — `--dry-run` + table de stats ; #M1 — ajout colonnes `Archivés DB` et `Restaurés`)
- `config/sambaedu.php` (T3 — bloc `wpkg.sync`)
- `resources/views/pages/sync-from-ad/index.blade.php` (T2 — étape 10 + boutons + handlers Livewire)
- `tests/Architecture/WpkgDeploymentNamespaceTest.php` (T6 — whitelist supprimée, préfixe `App\\LdapModels\\` ajouté)
- `tests/Support/WpkgSchemaBootstrapper.php` (T1 — shim étendu colonnes AD + lifecycle ; post-review Q1 — drop `last_seen_at`)
- `app/Wpkg/Deployment/README.md` (T7)
- `docs/wpkg-deploy/architecture.md` (T7 ; post-review Q1 — table migrations corrigée)
- `docs/qa/domains/wpkg-deploy.md` (T7 — Section 3, 8 scénarios)
- `_bmad-output/planning-artifacts/audit-wpkg-eloquent-schema.md` (T7 — section « décisions livrées » ; post-review Q1 — drop `last_seen_at` acté)
- `_bmad-output/codeReviews/15-3.md` (post-review — synthèse mise à jour avec statuts ✅/⏳)
- `_bmad-output/implementation-artifacts/15-3-modele-eloquent-suffisant-pour-deploiement-wpkg.md` (cette story)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (annoté post-review)

---

### Corrections post-review (2026-05-06)

Suite à la code-review documentée dans `_bmad-output/codeReviews/15-3.md`,
les corrections suivantes ont été appliquées par le dev-agent post-review.
Status reste `review` (les corrections seront re-validées par Henri avant
passage `done`).

**Décisions actées** :

- **Q1 — Drop `last_seen_at` complet (option C2)**. Pas de besoin métier
  réel : l'archivage des orphans est calculé dans le même run via diff
  `preExistingGuidIds` ↔ `matchedDbIds`, le scope `staleSince()` n'était
  utilisé nulle part, et la persistance `saveQuietly()` parasite à chaque
  run violait l'idempotence stricte AC3.6 (problème review #3).
  - Migrations renommées (`add_archived_at_to_workstations_and_groups`,
    `add_archived_at_and_ad_dn_to_app_profiles`).
  - `last_seen_at` retiré de `$fillable`/`$casts`/`@property` des 3 modèles.
  - Scope `staleSince()` retiré des 3 modèles.
  - `import Carbon\CarbonInterface` retiré (plus utilisé).
  - `saveQuietly()` parasite supprimé de `syncWorkstationGroups` /
    `syncAppProfiles` (l'idempotent path est désormais 100 % sans écriture).
  - Bootstrap test (`WpkgSchemaBootstrapper`) et test migrations
    (`WpkgEloquentSchemaMigrationsTest`) mis à jour avec assertion
    explicite `last_seen_at NOT IN columns`.

- **Q2 — Renommage : Eloquent souverain + log + ErrorLog**. En cas de
  divergence de nom AD/SQL pour un GUID matché, le `name` SQL est
  **préservé** (direction d'écriture canonique Eloquent → AD). Une
  divergence est tracée par : (a) log `info` `wpkg-deploy`, (b) entrée
  `error_logs` source `wpkg`, (c) compteur `name_divergences` incrémenté.
  Implémenté via `SyncAllFromAdJob::detectNameDivergence()`. Test
  renommé en `rename_in_ad_does_not_overwrite_local_name_and_logs_divergence`
  + tableau AC5.2 corrigé.

- **Q3 — Commande artisan `sync:from-ad`**. Le code est correct
  (`sync:from-ad`, nom historique préservé). Spec corrigée dans la story
  pour aligner.

**Corrections code (review)** :

- **#1 🔴 — `AppProfileObserver::disableSync()` ajouté en passe 2**.
  L'observer existait déjà (`app/Observers/AppProfileObserver.php`,
  méthodes statiques `disableSync`/`enableSync` présentes). Symétrie avec
  `WorkstationGroupObserver` / `WorkstationObserver` rétablie dans
  `runPass2Apply()`. Test
  `pass2_disables_app_profile_observer_to_prevent_outbound_sync` ajouté
  (Queue::fake + assertNotPushed `AppProfileAdSyncJob`).

- **#2 🔴 — Conflit GUID DB**. Pré-détection des doublons `ad_guid` faite
  **hors transaction** (`detectGuidConflictsOrAbort()` appelée entre
  passe 1 et passe 2) pour que l'écriture `error_logs` survive au
  rollback. Compteur `conflicts` incrémenté, `aborted_reason='conflict_guid'`,
  `RuntimeException` levée pour halte propre. Test
  `conflict_guid_aborts_with_clean_log_and_lock_released` couvre
  exception, error_logs, lock libéré, observer réactivé.

- **#5 🟡 — Vocabulaire « hot path »**. Remplacé par « chemin critique »
  dans `WorkstationPackagesResolver.php:21`. Aucune autre occurrence dans
  les fichiers stagés (vérifié par grep).

- **#6 🟡 — Logger sans `withContext`**. Logger stocké en propriété
  privée `$this->logger` initialisée dans `handle()`. Tous les emplacements
  identifiés (archivages individuels lignes ~517 et 623, `fetchMachinesFromAd`)
  passent désormais par `$this->logger` (ou fallback `Log::channel(...)`
  si appelé hors `handle()` pour `fetchMachinesFromAd` deprecated).

- **#M1 🟡 — `total_db` exclut archivées**. `WorkstationGroup::query()->whereNull('archived_at')->count()`
  pour `total_db`, nouveau champ `total_db_archived` distinct. Table CLI
  étendue (colonnes `Total DB` + `Archivés DB`).

- **#M3 🟡 — Compteur `restored` distinct**. Ajouté dans le rapport stats
  par entité (`workstation_groups`, `app_profiles`, `workstations`). La
  restauration (row archivée → `archived_at=null` car réapparaît AD)
  incrémente `restored` au lieu de `updated`, log `info` dédié, table CLI
  étendue. Test `archived_row_increments_restored_counter_when_reappears_in_ad`
  remplace `archived_row_is_restored_when_reappears_in_ad` (assertions
  enrichies).

**Reportés en backlog (validation Henri)** :

- **#7 🟡** — Aucun log `debug` par écriture individuelle avec `objectGUID`
  (lecture stricte AC3.3). Sur-interprétation Sonnet ; agrégat stats déjà
  correct + archivages tracés. Discutable pour 15.4 si besoin debug fin.
- **#8 🟡** — N+1 `workstationGroups()->exists()` dans `syncProfileGroupLinks`.
  Acceptable pour story de remédiation manuelle (~200 ms à 200 profils).
  Fix backloggé.
- **#9 🟡** — `executeWpkgRemediationStep` catch `\Exception` plutôt que
  `\Throwable`. Cohérence locale avec autres handlers du même fichier
  (l. 244, 406). Backloggé.
- **#10 🟡** — Test archi `WpkgDeploymentNamespaceTest` couvre uniquement
  `App\Wpkg\Deployment`. À étendre à `App\Wpkg\*` complet quand
  `App\Wpkg\Admin\*` sera créé (15.4).
- **#M11 🟡** — Defense-in-depth : pas de `Gate::authorize('server.admin')`
  dans l'action Livewire `executeWpkgRemediationStep`. La route est déjà
  protégée par `can:server.admin` (1ère ligne de défense). Backlog.

**Tests** :

- Suite ciblée : `vendor/bin/phpunit tests/Feature/Wpkg tests/Feature/Migrations tests/Feature/Jobs/SyncAllFromAdJobTest.php tests/Architecture` →
  **54 tests verts, 171 assertions, 0 fail** (gain +2 tests vs livraison
  initiale : `pass2_disables_app_profile_observer_to_prevent_outbound_sync`
  et `conflict_guid_aborts_with_clean_log_and_lock_released`).
- Suite globale non-régressée (alignée baseline 15.2 — delta zéro attendu).
