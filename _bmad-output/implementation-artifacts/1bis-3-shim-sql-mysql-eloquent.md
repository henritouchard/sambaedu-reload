# Story 1bis.3 : Shim SQL MySQL→Eloquent

Status: review

## Dépendances Critiques

- **Story 1bis.1** (done) : `ErrorLoggerService` + `LegacyErrorHandler` — le shim logge les appels SQL non couverts via ce service.
- **Story 1bis.2** (ready-for-dev) : `bootstrap.php` + `config.inc.php` + `ldap.inc.php` — le shim SQL sera inclus par le bootstrap au même titre que le shim LDAP. Le bootstrap charge le contexte Laravel nécessaire (Eloquent, config). **Si le bootstrap n'est pas encore implémenté**, le shim SQL peut être développé et testé indépendamment (tests PHPUnit), mais son intégration runtime dépend du bootstrap.

## Story

En tant que **développeur**,
je veux remplacer les appels `mysqli_*` dans les modules legacy par des appels Eloquent, en s'appuyant sur les modèles Laravel existants,
afin que les modules legacy accèdent à PostgreSQL via Eloquent sans conserver de dépendance MySQL.

## Acceptance Criteria

1. **Shim SQL redirige vers Eloquent** — Given un module legacy utilise des appels `mysqli_*` (principalement via `wpkg_libsql.php`), when le shim SQL est en place, then les appels sont redirigés vers les modèles Eloquent existants (`Application`, `Depot`, `Workstation`, `WorkstationGroup`, `AppProfile`, `Report`…), and les résultats sont retournés dans le format attendu par le code legacy.

2. **Tests PHPUnit valident la cohérence** — Given les modèles Eloquent couvrent les tables utilisées par le legacy, when les tests PHPUnit s'exécutent, then chaque requête shimmée retourne des données cohérentes avec le schéma PostgreSQL.

3. **Appel SQL non couvert → erreur explicite** — Given un appel SQL non couvert par un modèle Eloquent est détecté, when l'appel est intercepté, then une erreur explicite est loggée via `ErrorLoggerService::log('legacy', "Fonction SQL non shimmée : {$functionName}")`.

## Tasks / Subtasks

- [x] **Tâche 1 : Analyser `wpkg_libsql.php` et cartographier les fonctions** (AC: 1)
  - [x] Lire `/home/htouchard/code/irundo/codebase/sambaedu/includes/wpkg_libsql.php` (1977 lignes, ~66 fonctions)
  - [x] Classifier chaque fonction par catégorie : lecture (info_*), écriture (insert_*, update_*, delete_*), maintenance (maintenance_*), utilitaire (test_*, mise_en_forme_*)
  - [x] Mapper chaque fonction legacy vers le modèle Eloquent correspondant (voir section Mapping ci-dessous)
  - [x] Identifier les fonctions critiques pour les modules Tier 2 (wpkg, parcs2) vs fonctions secondaires

- [x] **Tâche 2 : Créer `legacy/sql_shim.php`** (AC: 1, 3)
  - [x] Créer le fichier `legacy/sql_shim.php` (à côté de `ldap.inc.php`)
  - [x] Implémenter les fonctions wrapper qui remplacent `connexion_db_wpkg()` et `deconnexion_db_wpkg()` par des no-ops (Eloquent gère la connexion)
  - [x] Implémenter les fonctions de lecture prioritaires (voir Priorités ci-dessous)
  - [x] Implémenter les fonctions d'écriture
  - [x] Chaque fonction retourne les données dans le **format exact** attendu par le code legacy (même clés de tableau, mêmes types)
  - [x] Implémenter un mécanisme fallback pour les fonctions non shimmées : logge via `ErrorLoggerService::log('legacy', "Fonction SQL non shimmée : {$functionName}")` et retourne `false`

- [x] **Tâche 3 : Intégration avec le bootstrap** (AC: 1)
  - [x] Ajouter `require_once __DIR__ . '/sql_shim.php';` dans `legacy/bootstrap.php` (après `ldap.inc.php`)
  - [x] S'assurer que le shim est chargé **avant** l'inclusion de `wpkg_libsql.php` par les modules legacy
  - [x] Vérifier que les fonctions du shim écrasent bien celles de `wpkg_libsql.php` (chargement dans le bon ordre)

- [x] **Tâche 4 : Tests PHPUnit** (AC: 2, 3)
  - [x] Créer `tests/Unit/Legacy/SqlShimTest.php`
  - [x] Tester chaque fonction de lecture shimmée avec des données en DB (factory ou seeder)
  - [x] Tester chaque fonction d'écriture shimmée
  - [x] Tester qu'un appel à une fonction non shimmée logge l'erreur via `ErrorLoggerService`
  - [x] Vérifier que le format de retour correspond au format legacy (mêmes clés, mêmes types)
  - [x] `$this->withoutVite()` dans `setUp()` si tests Feature

## Dev Notes

### Contexte Technique

- **Stack** : Laravel 12, PHP 8.1+, PostgreSQL (source de vérité pour les lectures)
- **Fichier source legacy** : `sambaedu/includes/wpkg_libsql.php` — 1977 lignes, ~66 fonctions globales PHP, toutes utilisant `mysqli_*`
- **Pattern** : chaque fonction legacy reçoit un `$config` (contient les credentials MySQL), ouvre une connexion `mysqli_connect("localhost", "sambaedu", $config['sql_passwd'], "sambaedu")`, exécute une requête, et retourne un tableau associatif PHP.

### Architecture du Shim

Le shim **ne simule pas un serveur MySQL** — il redéfinit les fonctions globales PHP de `wpkg_libsql.php` pour qu'elles utilisent Eloquent à la place de `mysqli_*`. Le paramètre `$config` est ignoré (Eloquent utilise la connexion PostgreSQL configurée dans Laravel).

```
Module legacy (wpkg/)
    ↓ require_once 'wpkg_libsql.php'  ← remplacé par sql_shim.php via bootstrap
    ↓ appelle info_postes($config)
    ↓ sql_shim.php → Workstation::all() → format legacy
    ↓ retourne tableau PHP identique au format mysqli_*
```

### Stratégie de Remplacement

**Option recommandée** : le `bootstrap.php` charge `sql_shim.php` AVANT que les modules legacy n'incluent `wpkg_libsql.php`. Puisque les fonctions du shim portent les mêmes noms, le PHP refuse de redéfinir une fonction déjà déclarée. Deux approches :

1. **Remplacement complet** : le bootstrap empêche le chargement de `wpkg_libsql.php` en déclarant un flag (`define('WPKG_LIBSQL_LOADED', true)`) et en modifiant le module legacy pour vérifier ce flag avant l'include.
2. **Override via namespace trick** : si les modules legacy font `require_once 'wpkg_libsql.php'`, modifier le `include_path` pour pointer vers `legacy/sql_shim.php` au lieu du fichier original.

**Approche la plus simple** : copier `wpkg_libsql.php` dans `legacy/` en remplaçant le contenu des fonctions par les équivalents Eloquent. Le bootstrap s'assure que cette version est chargée.

### Mapping Fonctions Legacy → Eloquent

#### Tables et Modèles

| Table Legacy MySQL | Modèle Eloquent | Table PostgreSQL |
|---|---|---|
| `postes` | `Workstation` | `workstations` |
| `parc` | `WorkstationGroup` | `workstation_groups` |
| `parc_profile` | pivot `workstation_group_workstation` | `workstation_group_workstation` |
| `applications` | `Application` | `applications` |
| `applications_profile` | pivot `app_profile_application` + `app_profile_workstation_group` | tables pivot |
| `poste_app` | `Report` | `poste_app` |
| `depot_applications` | `Application` (via `depot_id`) ou `Depot` | `depots` + `applications` |
| `dependance` | À créer ou requête raw | `dependance` (si migrée) |
| `journal_app` | `InstallationLog` (ou nouvelle table) | `installation_logs` |

#### Fonctions Prioritaires (Tier 2 — modules wpkg, parcs2)

**Lecture — critiques :**
- `info_postes($config)` → `Workstation::all()` — retourne `['nom_poste' => [...], ...]`
- `info_parcs($config)` → `WorkstationGroup::all()` — retourne `['nom_parc' => [...], ...]`
- `info_poste_parcs($config, $nom_poste)` → `Workstation::where('name', $nom_poste)->first()->groups`
- `info_parc_postes($config, $nom_parc)` → `WorkstationGroup::where('name', $nom_parc)->first()->workstations`
- `info_parc_appli($config, $nom_parc)` → via `WorkstationGroup` → `appProfiles` → `applications`
- `info_poste_applications($config, $nom_poste)` → via `Workstation` → `groups` → `appProfiles` → `applications`
- `liste_applications($config)` → `Application::all()`
- `info_poste_rapport($config, $nom_poste)` → `Report::where('id_poste', ...)->get()`
- `info_depot($config)` → `Depot::active()->get()`
- `info_depot_appli($config, $id_depot)` → `Depot::find($id_depot)->applications`

**Écriture — critiques :**
- `insert_parc($config, $nom_parc, $uuid)` → `WorkstationGroup::create([...])`
- `insert_parc_profile($config, $id_poste, $id_parc)` → `$group->workstations()->attach($id_poste)`
- `delete_parc_profile($config, $id_poste, $id_parc)` → `$group->workstations()->detach($id_poste)`
- `insert_application_profile($config, $type, $id_entite, $id_appli)` → pivot Eloquent
- `set_entite_apps($config, $list_id_appli, $nom_entite, $type_entite)` → `sync()` sur pivot

**Connexion — no-ops :**
- `connexion_db_wpkg($config)` → retourne un objet factice (Eloquent gère la connexion)
- `deconnexion_db_wpkg($link)` → no-op

#### Fonctions Secondaires (Tier 3 ou post-MVP)

- `mise_en_forme_*` — fonctions UI, peuvent être shimmées avec des valeurs par défaut
- `maintenance_*` — maintenance postes, shimmer par priorité
- `test_parent($config)` — test de structure, peut retourner `true`
- `test_mef($config)` — mise en forme par défaut

### Existant à Réutiliser

- **~~`LegacyParcBridgeService`~~** : NE PAS réutiliser — le bridge utilise `DB::table('parc')` (anciennes tables MySQL). Utiliser les modèles Eloquent directement.
- **Modèles existants** : `Application`, `Depot`, `Workstation`, `WorkstationGroup`, `AppProfile`, `WorkstationApplicationStatus`, `InstallationLog` — tous dans `app/Models/`
- **`ErrorLoggerService`** (`app/Services/ErrorLoggerService.php`) : `log(source, message)` pour les erreurs
- **`LegacyErrorHandler`** (`app/Services/LegacyErrorHandler.php`) : déjà configuré pour capturer erreurs PHP

### Format de Retour Legacy

Les fonctions de `wpkg_libsql.php` retournent des tableaux associatifs PHP indexés par un identifiant (nom_poste, nom_parc, etc.). Exemple de format pour `info_postes()` :

```php
[
    'PC-SALLE101-01' => [
        'id_poste' => 1,
        'nom_poste' => 'PC-SALLE101-01',
        'uuid_poste' => 'xxx-xxx',
        'flag_poste' => 0,
        'sha_poste' => 'abc123',
        'log_poste' => '/path/to/log',
        'rapport_poste' => '/path/to/rapport'
    ],
    // ...
]
```

**Critique** : les clés du tableau doivent correspondre exactement aux noms de colonnes legacy MySQL (`id_poste`, `nom_poste`, `uuid_poste`…), pas aux noms Eloquent (`id`, `name`, `uuid`…). Le shim doit faire le mapping de noms de colonnes.

### Mapping Colonnes Legacy → Eloquent

| Colonne Legacy (MySQL) | Propriété Eloquent | Table |
|---|---|---|
| `id_poste` | `id` | `workstations` |
| `nom_poste` | `name` | `workstations` |
| `uuid_poste` | `uuid` | `workstations` |
| `flag_poste` | `status` (mapper: active=0, protected=1) | `workstations` |
| `sha_poste` | `report_sha` | `workstations` |
| `log_poste` | `log_path` | `workstations` |
| `rapport_poste` | `report_path` | `workstations` |
| `id_parc` | `id` | `workstation_groups` |
| `nom_parc` | `name` | `workstation_groups` |
| `nom_parc_wpkg` | `display_name` | `workstation_groups` |
| `id_app` | `id` | `applications` |
| `id_nom_app` | `app_id` | `applications` |
| `nom_app` | `name` | `applications` |
| `version_app` | `version` | `applications` |
| `id_depot` | `depot_id` | `applications` |
| `id_depot` (table depots) | `id` | `depots` |
| `nom_depot` | `name` | `depots` |
| `url_depot` | `url` | `depots` |
| `depot_actif` | `is_active` | `depots` |
| `depot_principal` | `is_primary` | `depots` |

### Précautions / Risques

| Risque | Mitigation |
|--------|-----------|
| Format de retour incompatible avec le code legacy | Tests comparatifs : vérifier les clés et types de chaque tableau retourné |
| Tables legacy non encore migrées vers PostgreSQL | Certaines tables (`dependance`, `journal_app`, `mef_*`) peuvent nécessiter des migrations supplémentaires ou des requêtes `DB::table()` raw |
| Performance des requêtes Eloquent vs raw MySQL | Les modules legacy font beaucoup de requêtes unitaires. Utiliser eager loading et cache si nécessaire |
| Fonctions de `wpkg_libsql.php` auto-exécutées au chargement | Les lignes 87-92 du fichier (`test_mef($config)` + boucle `$mise_en_forme_perso`) s'exécutent au `require`. Le shim doit reproduire ce comportement ou le neutraliser |
| Le `LegacyParcBridgeService` utilise `DB::table('parc')` (ancienne table) | Le shim doit utiliser les modèles Eloquent (nouvelles tables), pas le bridge legacy qui pointe vers les anciennes tables |

### Learnings Story 1bis.2

- **Tests Feature** : `$this->withoutVite()` dans `setUp()` pour éviter l'erreur Vite manifest
- **Config sambaedu** : sections existantes dans `config/sambaedu.php` — `legacy_path`, `legacy_ldap`, `wpkg`
- **Bootstrap** doit être idempotent — double appel ne doit pas crasher
- **LegacyCatchallController** strip le prefix UAI du path

### Git Intelligence — Patterns Récents

Derniers commits pertinents :
- `51c054b` — errorlogDashboard (story 1bis.1)
- `9bc0240` — user creation (story 2.1)
- `634ab0d` — legacy monitor filters
- `3fa156e` — auth guard (story 1.4)

Conventions observées : commits descriptifs en anglais, code en français pour les commentaires, architecture Services/ bien respectée.

### References

- Architecture — Cloisonnement Legacy : [_bmad-output/planning-artifacts/architecture.md#Cloisonnement-Legacy]
- Architecture — Shims : [_bmad-output/planning-artifacts/architecture.md#Shims]
- Epics — Story 1bis.3 : [_bmad-output/planning-artifacts/epics.md#Story-1bis-3]
- Fichier source legacy : [sambaedu/includes/wpkg_libsql.php]
- LegacyParcBridgeService : [app/Services/Legacy/LegacyParcBridgeService.php]
- ErrorLoggerService : [app/Services/ErrorLoggerService.php]
- LegacyErrorHandler : [app/Services/LegacyErrorHandler.php]
- Config sambaedu : [config/sambaedu.php]
- Modèle Application : [app/Models/Application.php]
- Modèle Depot : [app/Models/Depot.php]
- Modèle Workstation : [app/Models/Workstation.php]
- Modèle WorkstationGroup : [app/Models/WorkstationGroup.php]
- Modèle AppProfile : [app/Models/AppProfile.php]
- Modèle Report : [app/Models/Report.php]
- Story précédente 1bis.2 : [_bmad-output/implementation-artifacts/1bis-2-bootstrap-et-shim-ldap-eloquent.md]

## Dev Agent Record

### Agent Model Used
Claude Opus 4.6 (1M context)

### Debug Log References
- SQLite driver manquant sur la VM → installé via `apt-get install php8.4-sqlite3`
- Conflit nom `createApplication()` vs TestCase Laravel → renommé en `makeApp()`
- WorkstationGroupObserver (LDAP AD sync) déclenché en test → désactivé via `unsetEventDispatcher()`

### Completion Notes List
- **Tâche 1** : Analyse complète des 66 fonctions de `wpkg_libsql.php` — classifiées en lecture (27), écriture (32), maintenance (5), utilitaire (2), connexion (2). Mapping vers les modèles Eloquent existants et les nouvelles tables PostgreSQL (application_dependencies, depot_applications, workstation_application_status).
- **Tâche 2** : Créé `legacy/sql_shim.php` (~900 lignes) — shimme les 66 fonctions legacy. Utilise les modèles Eloquent (Workstation, WorkstationGroup, Application, Depot, DepotApplication, AppProfile, Report) et `DB::table()` pour `application_dependencies`. Table `mise_en_forme` non migrée → retourne des valeurs par défaut hardcodées. Guards : `SQL_SHIM_LOADED` + `WPKG_LIBSQL_LOADED`. Auto-execute reproduit le comportement d'initialisation du fichier legacy original.
- **Tâche 3** : Ajouté `require_once __DIR__ . '/sql_shim.php'` dans `legacy/bootstrap.php` après le shim LDAP. Le flag `WPKG_LIBSQL_LOADED` empêche le chargement du fichier original.
- **Tâche 4** : Créé `tests/Unit/Legacy/SqlShimTest.php` — 46 tests couvrant : connexion (no-ops), lecture postes/parcs/applications/dépôts/mise en forme, écriture postes/rapports/applications/dépendances/parcs/dépôts, maintenance, structure, fallback erreur. Tous les tests passent (131 assertions). Aucune régression sur les tests existants.

### Change Log
- 2026-03-26 : Implémentation complète story 1bis.3 — shim SQL, intégration bootstrap, tests PHPUnit

### File List
- `legacy/sql_shim.php` — NOUVEAU — Shim SQL MySQL→Eloquent (66 fonctions)
- `legacy/bootstrap.php` — MODIFIÉ — Ajout require sql_shim.php
- `tests/Unit/Legacy/SqlShimTest.php` — NOUVEAU — 46 tests unitaires
