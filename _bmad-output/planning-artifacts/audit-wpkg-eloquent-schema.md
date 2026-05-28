# Audit — Modèle Eloquent suffisant pour le déploiement WPKG (Story 15.3, T0)

**Date** : 2026-05-06
**Auteur** : Dev Agent BMAD (claude-opus 4.7)
**Story** : 15.3 — Modèle Eloquent suffisant pour le déploiement WPKG
**AC source** : AC1.1
**Statut** : Audit produit, en attente validation explicite Henri avant T1 (migrations).

---

## 1. Décisions de cadrage actées

Source : transcripts SM 2026-05-05/06, repris dans `sprint-status.yaml` ligne 235 et dans le préambule de la story 15.3.

- **D1 — Pas de cron entrant AD → Eloquent.** Tout cron périodique de réconciliation entrant est définitivement abandonné. Justification : race silencieuse avec les jobs `*AdSyncJob` sortants pilotés par observers (cf. `WorkstationGroupObserver`, `WorkstationObserver`, `AppProfileObserver`). Si un cron lit l'AD pendant qu'un job sortant est encore en queue, il lit l'état AD pas-encore-mis-à-jour et réécrit l'ancienne valeur dans Eloquent → perte d'écritures métier.
- **D2 — Direction d'écriture canonique = Eloquent → AD via observers.** Eloquent est la source de vérité opérationnelle. La sync AD → Eloquent ne sert que de **bootstrap initial** (post-migration prod) et de **remédiation drift ponctuelle** (déclenchée humainement).
- **D3 — `SyncAllFromAdJob` est l'outil unique de remédiation entrant.** Existe déjà (~554 lignes, `app/Jobs/SyncAllFromAdJob.php`). Sera durci au volet 3 (dry-run, lock, 2 passes, archivage, idempotence, match strict premier run).
- **D4 — Pas de commande artisan dédiée de peuplement initial.** Initialement envisagée (`wpkg:backfill-ad-attrs`), abandonnée. Le `SyncAllFromAdJob` durci (mode `--dry-run` + match strict name+scope OU + écriture idempotente des `ad_guid`/`ad_dn` quand `null`) couvre intégralement le besoin.
- **D5 — Procédure ops finale de bascule prod** :
  ```
  1. php artisan migrate                # applique les migrations volet 2 (ad_guid NULL au départ)
  2. Aller sur /admin/sync-from-ad      # UI Livewire
  3. Cliquer « Aperçu (dry-run) »       # vérifier le diff AD vs SQL avant écriture
  4. Cliquer « Exécuter »               # applique : peuple ad_guid + sync drift
  ```
  À reporter dans le runbook 15.7 (bascule prod + retrait shim WPKG legacy).
- **D6 — Vocabulaire imposé.** « Chemin critique » (HTTP poste Windows → réponse rapide, sans LDAP) / « chemin froid » (jobs admin, commandes artisan, pages settings). Le terme « hot path » a été rejeté par Henri.
- **D7 — Test architectural namespace `App\Wpkg\*` sans whitelist.** Aucun import `LdapRecord\*` ni `App\LdapModels\*` ni `App\Services\Ad\*` toléré sous `App\Wpkg\*`. Toute mention de `WpkgAdReconciliationJob` whitelisté doit être supprimée (le job n'existera pas).

### Observation préalable (cohérence worktree main)

Au moment de la rédaction de cet audit, le worktree primaire `main` (commit `42cebba`) **ne contient pas** les artefacts de la story 15.2 :
- `app/Wpkg/Deployment/Models/WpkgWorkstationOption.php` → absent (`main` a uniquement les `.gitkeep`).
- `database/migrations/2026_05_05_100100_create_wpkg_workstation_options_table.php` → absent.
- Les services `WorkstationPackagesResolver`, `WorkstationIniGenerator`, controllers `HostsXmlController`/`ProfilesXmlController` → absents.

Ces artefacts vivent dans la branche `wpkg-15-3` (worktree `/home/htouchard/code/irundo/codebase/wpkg`, commit `f095764 — 15.2-dev-cycle XML generator`). L'audit s'appuie sur le code source pertinent peu importe la branche : il décrit l'état attendu **après** merge 15.2 → main.

À noter pour Henri : il faudra avoir mergé 15.2 avant d'attaquer T1 (les migrations volet 2 risquent autrement de cohabiter avec celles de 15.2 sans coordination horodatage).

---

## 2. Inventaire des entités

### 2.1 `Workstation` — `app/Models/Workstation.php` + `workstations`

Source schéma : `database/migrations/2026_01_30_000000_create_unified_schema.php` lignes 33-67.

| Colonne | Type SQL | Nullable | Catégorie | Usage chemin critique | Justification |
|---|---|---|---|---|---|
| `id` | `bigint PK` | non | A | Resolver lookup, FK pivots | clé primaire |
| `name` | `varchar(100) UNIQUE` | non | **A** | `WorkstationPackagesResolver::computePackages()` ligne 83 (`->where('name', $hostname)`) ; clé d'invalidation cache `wpkg:packages:{hostname}` ; nom de fichier `{name}.ini` dans `WorkstationIniGenerator::generate()` ligne 50 | hostname = identifiant unique côté pipeline |
| `os` | `varchar(100) nullable` | oui | B | non lu par 15.2 | utile en chemin froid (`AdSyncChecker`, dashboard ops) |
| `ip` | `varchar(45) nullable` | oui | B | non lu par 15.2 | display/audit chemin froid |
| `mac` | `varchar(17) nullable` | oui | B | non lu par 15.2 | display/audit chemin froid |
| `uuid` | `uuid nullable` | oui | B | non lu par 15.2 (≠ `ad_guid`) | UUID matériel (BIOS), distinct de `objectGUID` AD ; consommé par legacy shim `info_postes_uuid` (`legacy/wpkg_libsql.php:136`) — chemin froid uniquement |
| `status` | `varchar(20) default 'active'` | non | B (15.3) | non filtrant en chemin critique 15.2 (parité legacy : postes désactivés = XML normal, décision user #1 dans 15.2) | actuellement non lu par le pipeline ; consommé par dashboard et legacy `info_postes_uuid` (flag protected). Reste catégorie B pour 15.3, mais à reclasser **A** si la story 15.5 impose un filtrage des statuts en chemin critique |
| `last_report_at` | `timestamp nullable` | oui | B | non lu par 15.2 | rapport WPKG remonté par client Windows — chemin froid (story 15.5 dashboard) |
| `report_sha` | `varchar(64) nullable` | oui | B | non lu par 15.2 | déduplication rapport — 15.5 |
| `log_path` | `text nullable` | oui | B | non lu par 15.2 | display chemin froid |
| `report_path` | `text nullable` | oui | B | non lu par 15.2 | display chemin froid |
| `physical_room_id` | `bigint nullable FK workstation_groups` | oui | B | non lu par 15.2 (relation salle physique 1:N orthogonale aux parcs WPKG) | inventaire matériel — chemin froid |
| `ad_dn` | `varchar(512) nullable` | oui | B | non lu par 15.2 (le pipeline 15.2 ne consulte jamais l'AD) | match AD chemin froid (`SyncAllFromAdJob`, `AdSyncChecker`) |
| `ad_guid` | `varchar(36) nullable indexed` | oui | B (15.3) → **A** (15.5) | non lu par 15.2 | clé immutable AD ; **promu A** par 15.5 (rapports clients WPKG → match stable même au renommage). Présent en SQL → pas de migration nécessaire. |
| `managed_by_control_hub` | `boolean default false` | non | B | non lu par 15.2 | pilotage ControlHub — chemin froid |
| `created_at` / `updated_at` | timestamps | oui | infra | — | — |

**Manquants catégorie A (à ajouter au volet 2)** :
- `last_seen_at` (timestamp nullable, indexé) — **NOUVEAU**. Cible : permettre au job durci (volet 3) de détecter les postes orphelins AD avec une fenêtre de tolérance, avant archivage. Aussi consommé par les futurs scopes `staleSince(Carbon)` (cf. story Tasks/Subtasks T1). Catégorie A car il sera renseigné par `SyncAllFromAdJob` à chaque passage et lu par le filtre `notArchived()` indirectement (un poste vu récemment ne doit pas être archivé). Cast `datetime`.
- `archived_at` (timestamp nullable, indexé) — **NOUVEAU**. Cible : remplacer toute logique de suppression sèche par un archivage logique (AC3.4). Le scope `notArchived()` devient le filtre par défaut des listings UI 15.4 et du pipeline 15.2 (le resolver doit ignorer un poste archivé : décision implicite à confirmer mais cohérente avec « parité legacy : postes désactivés = XML normal » — un archivé n'est PAS un désactivé, c'est une row morte côté refactor). Cast `datetime`.

> **Question ouverte D8** (à trancher pendant T1 / impact resolver 15.2) : `WorkstationPackagesResolver` doit-il filtrer `archived_at IS NULL` ? Hypothèse forte → oui (les rows archivées sont des fantômes ; renvoyer leur liste de packages risque de faire des deltas bruyants côté client Windows). Si oui, c'est une légère évolution non breaking de 15.2 à acter dans le Dev Agent Record du volet 2 + test resolver dédié.

### 2.2 `WorkstationGroup` — `app/Models/WorkstationGroup.php` + `workstation_groups`

Source schéma : `database/migrations/2026_01_30_000000_create_unified_schema.php` lignes 72-102, complétée par migrations ultérieures (`2026_02_03_*` ajoute `app_profile_name`, `2026_02_04_183844_*` ajoute `is_physical` + `parent_id`, `2026_02_05_*` ajoute `locked`, `2026_02_06_*` ajoute `controlhub_id`/`controlhub_version`).

| Colonne | Type SQL | Nullable | Catégorie | Usage chemin critique | Justification |
|---|---|---|---|---|---|
| `id` | `bigint PK` | non | A | FK pivots `app_profile_workstation_group`, `workstation_group_workstation` | — |
| `name` | `varchar(100) UNIQUE` | non | **A** | Lu indirectement via relation `Workstation::groups` puis chargé par eager load `with('groups.appProfiles.applications')` dans `WorkstationPackagesResolver` ligne 84-89 (le pipeline ne filtre pas par name mais charge la collection complète) | identifiant logique du parc/groupe |
| `controlhub_id` | `varchar nullable` | oui | B | non lu par 15.2 | ControlHub — chemin froid |
| `controlhub_version` | `timestamp nullable` | oui | B | non lu par 15.2 | ControlHub |
| `is_physical` | `boolean` | non | B | non lu par 15.2 | utile au shim legacy `info_parcs` (legacy/wpkg_libsql.php:430) et à `AdSyncChecker::checkWorkstationGroups()` (filtre `is_physical=true`). En chemin critique, le resolver charge **tous** les groupes du poste sans filtrer par physical. |
| `display_name` | `varchar(255) nullable` | oui | B | non lu par 15.2 | display UI |
| `description` | `text nullable` | oui | B | non lu par 15.2 | display UI |
| `app_profile_name` | `varchar nullable` | oui | B | non lu par 15.2 (utilisé par observers Eloquent → AD pour créer le profile lié) | sortant chemin froid |
| `parent_id` | `bigint nullable FK self` | oui | B | non lu par 15.2 | hiérarchie GPO — chemin froid |
| `ad_dn` | `varchar(512) nullable` | oui | B | non lu par 15.2 | match AD chemin froid |
| `ad_guid` | `varchar(36) nullable indexed` | oui | B | non lu par 15.2 | match AD chemin froid (présent en SQL) |
| `is_active` | `boolean default true` | non | B | non lu par 15.2 (le resolver charge tous les groupes du poste sans filtrer) | filtre admin chemin froid |
| `locked` | `varchar nullable` | oui | B | non lu par 15.2 | lock UI — chemin froid |
| `managed_by_control_hub` | `boolean` | non | B | non lu par 15.2 | ControlHub |
| timestamps | — | — | infra | — | — |

**Manquants catégorie A (à ajouter au volet 2)** :
- `last_seen_at` (timestamp nullable, indexé) — **NOUVEAU**, symétrie avec `Workstation`.
- `archived_at` (timestamp nullable, indexé) — **NOUVEAU**, symétrie avec `Workstation`. Idem question D8 : un groupe archivé ne devrait plus exposer ses applications via le resolver. Le scope `notArchived()` doit être appliqué côté `Workstation::groups()` ou directement dans le resolver.

### 2.3 `Application` — `app/Models/Application.php` + `applications`

Source schéma : `database/migrations/2026_01_30_000000_create_unified_schema.php` lignes 147-171, étendue par `2026_02_16_180000_add_appstore_fields_to_applications.php`, `2026_02_17_180000_add_controlhub_fields_to_applications.php`.

| Colonne | Type SQL | Catégorie | Usage chemin critique | Justification |
|---|---|---|---|---|
| `id` | `bigint PK` | A | FK pivots `app_profile_application`, `application_dependencies`, `app_profile_workstation`, `application_workstation_group`, `application_workstation` | — |
| `app_id` | `varchar(255)` | **A** | `WorkstationPackagesResolver` lignes 86, 102, 110, 117, 122, 134 (`pluck('app_id')`) → c'est la valeur sérialisée dans `<package package-id="..."/>` de `profiles.xml` | identifiant WPKG (technique) |
| `name` | `varchar(255)` | B | non lu par 15.2 | display UI / legacy `info_poste_applications` |
| `version` / `installed_version` | `varchar nullable` | B | non lu par 15.2 (parité legacy : profile.xml ne renvoie pas la version, juste l'app_id) | matching version — chemin froid (story 15.5) |
| `category`, `branch`, `compatibility` | `varchar nullable` | B | non lu par 15.2 | filtres admin |
| `xml`, `xml_url`, `xml_sha`, `log_url` | text/varchar nullable | B | non lu par 15.2 (le legacy ne lit pas le XML pour générer profiles.xml) | gestion appstore — chemin froid |
| AppStore fields (`installer_*`, `local_*_path`, `installed_at`, `last_checked_at`, `description`, `icon_url`, `author`, `status`) | divers | B | non lu par 15.2 | dépôt local — chemin froid |
| `depot_id` | `bigint nullable FK depots` | B | non lu par 15.2 | regroupement source — chemin froid |
| ControlHub fields | divers | B | non lu par 15.2 | — |
| timestamps | — | infra | — | — |

**Manquants catégorie A** : **AUCUN**. L'unique colonne consommée en chemin critique est `app_id` (déjà présente). Pas de migration nécessaire pour `applications` au volet 2.

### 2.4 `AppProfile` — `app/Models/AppProfile.php` + `app_profiles`

Source schéma : `database/migrations/2026_01_30_000000_create_unified_schema.php` lignes 176-186 (table créée minimaliste), enrichie par `2026_02_06_181915_*` (controlhub_id/version).

| Colonne | Type SQL | Catégorie | Usage chemin critique | Justification |
|---|---|---|---|---|
| `id` | `bigint PK` | A | FK pivots | — |
| `name` | `varchar(100) UNIQUE` | **A** | Lu indirectement via relations `Workstation::appProfiles` et `WorkstationGroup::appProfiles` chargées par `with('appProfiles.applications')` dans le resolver. Pas de filtre direct par nom mais structure Eloquent attendue. | identifiant logique du profil |
| `controlhub_id` / `controlhub_version` | varchar / timestamp nullable | B | non lu | ControlHub |
| `display_name` | `varchar(255) nullable` | B | non lu par 15.2 | display UI |
| `description` | `text nullable` | B | non lu par 15.2 | display UI |
| `ad_guid` | `varchar(36) nullable` | B | non lu par 15.2 | match AD chemin froid. **Présent en SQL** → confirmé H1. |
| `is_active` | `boolean default true` | B (potentiel A) | non lu par 15.2 actuellement (le resolver ne filtre pas) | À surveiller : le legacy `info_poste_applications` charge sans filtrer ; aligné. |
| timestamps | — | infra | — | — |

**Manquants catégorie A (volet 2)** :
- `ad_dn` (varchar(512) nullable) — **NOUVEAU**. Justification : `AppProfile` n'a actuellement **que** `ad_guid` en SQL, **pas** `ad_dn`. Cela invalide partiellement l'hypothèse H1 de la story (« `ad_dn` et `ad_guid` sont déjà sur Workstation, WorkstationGroup, AppProfile »). Conséquence : le `SyncAllFromAdJob` durci ne pourra pas matérialiser `ad_dn` côté `AppProfile` en passe 2 sans cette colonne. Catégorie : **A par cohérence** avec les deux autres entités sur lesquelles le job écrit déjà `ad_dn`. Reste lu uniquement en chemin froid (job + AdSyncChecker), donc **on pourrait argumenter B** ; mais ne pas la créer maintenant condamne la symétrie de l'audit chemin froid et complique la remédiation drift (un opérateur qui voit un drift parc voudra pouvoir comparer `ad_dn` SQL vs AD).
  > **Décision proposée** : créer `ad_dn` sur `app_profiles` même si chemin froid, par cohérence ; documenter dans le commit que c'est un manquant "structurel" et non un attribut chemin critique.
- `archived_at` (timestamp nullable, indexed) — **NOUVEAU**. Cohérence avec `Workstation` et `WorkstationGroup`. Si un parc est supprimé de l'AD (`OU=Parcs`) mais reste référencé en SQL, on doit pouvoir l'archiver plutôt que le supprimer (sécurité opérateur). Catégorie A car le scope `notArchived()` impacte l'eager load du resolver : `with('appProfiles.applications')` doit ne charger que les profils non archivés, sinon des `<package>` zombies pourraient remonter.
- `last_seen_at` (timestamp nullable, indexed) — **NOUVEAU**, symétrie. Optionnel mais cohérent.

### 2.5 `WpkgWorkstationOption` — `app/Wpkg/Deployment/Models/WpkgWorkstationOption.php` + `wpkg_workstation_options`

> Livré par story 15.2 (branche `wpkg-15-3`, pas encore mergée dans `main` au moment de cet audit — cf. observation §1).

Source schéma : `database/migrations/2026_05_05_100100_create_wpkg_workstation_options_table.php` (worktree `wpkg-15-3`).

| Colonne | Type SQL | Catégorie | Usage chemin critique | Justification |
|---|---|---|---|---|
| `id` | `bigint PK` | A | — | — |
| `workstation_id` | `bigint FK workstations cascade` | **A** | `WorkstationIniGenerator::renderContent()` ligne 74 (`$workstation->wpkgOptions->keyBy('option_key')`) | scoping per-poste |
| `option_key` | `varchar(64)` (UNIQUE par workstation_id) | **A** | idem ligne 74 (`keyBy('option_key')`) puis ligne 81 (`$overrides->get($key, 'false')`) | clé d'option (8 valeurs legacy) |
| `option_value` | `varchar(255)` | **A** | idem ligne 76 et 81 | valeur sérialisée dans le `.ini` |
| timestamps | — | infra | — | — |

**Manquants catégorie A** : **AUCUN**. Table livrée 15.2 et adéquate.

---

## 3. Tableau de synthèse — Manquants catégorie A par entité

| Entité | Colonnes manquantes (catégorie A à créer en volet 2) | Type | Index | Justification courte |
|---|---|---|---|---|
| `workstations` | `last_seen_at` | `timestamp nullable` | oui | tracking dernier passage AD → seuil archivage |
| `workstations` | `archived_at` | `timestamp nullable` | oui | scope `notArchived()` filtrant resolver/UI |
| `workstation_groups` | `last_seen_at` | `timestamp nullable` | oui | symétrie poste |
| `workstation_groups` | `archived_at` | `timestamp nullable` | oui | scope `notArchived()` ; resolver ignore groupes archivés |
| `app_profiles` | `ad_dn` | `varchar(512) nullable` | non (pas de filtre fréquent) | symétrie schéma chemin froid (H1 invalidée partiellement — cf. §2.4) |
| `app_profiles` | `archived_at` | `timestamp nullable` | oui | scope `notArchived()` ; eager load resolver doit l'appliquer |
| `app_profiles` | `last_seen_at` | `timestamp nullable` | oui | symétrie (optionnel) |
| `applications` | — | — | — | aucun manquant |
| `wpkg_workstation_options` | — | — | — | aucun manquant (livré 15.2) |

**Total** : **2 tables structurelles à migrer** (`workstations`, `workstation_groups`) + **1 table corrective** (`app_profiles`).

**Décision proposée pour T1** :
- **Migration 1** : `<ts>_add_lifecycle_attrs_to_workstations_and_groups.php` → 2x{`last_seen_at`, `archived_at`} sur `workstations` et `workstation_groups`, avec index sur `archived_at` (filtre courant) et `last_seen_at` (futurs jobs cleanup).
- **Migration 2** : `<ts>_add_lifecycle_attrs_to_app_profiles.php` → `ad_dn` + `archived_at` (+ `last_seen_at` optionnel) sur `app_profiles`. Index sur `archived_at`.

→ 2 migrations envisagées, sur 3 tables. Conformément à D1 de la story (« Si l'audit T0 conclut que les manquants sont uniquement `last_seen_at` et `archived_at`, fusionner en un seul fichier »), **on ne fusionne pas** : H1 partiellement invalidée → `app_profiles.ad_dn` justifie sa propre migration ciblée.

> Alternative : 1 seule migration multi-tables. Acceptable mais moins lisible en revue. Décision finale : à confirmer par Henri lors de la validation T0.

---

## 4. Justification des attributs catégorie B (chemin froid uniquement)

Liste exhaustive des attributs **lus** par le code mais **jamais en chemin critique**, donc qui n'ont pas à être migrés et peuvent rester dans leur position actuelle (en SQL ou AD-only).

### 4.1 Attributs déjà en SQL — restent catégorie B (pas d'évolution)

| Attribut | Lu où | Chemin |
|---|---|---|
| `Workstation::os`, `ip`, `mac`, `uuid`, `physical_room_id`, `last_report_at`, `report_sha`, `report_path`, `log_path`, `managed_by_control_hub`, `status` | `AdSyncChecker::checkWorkstations()`, `legacy/wpkg_libsql.php` (shim), pages settings, dashboard | froid |
| `WorkstationGroup::display_name`, `description`, `is_physical`, `parent_id`, `app_profile_name`, `is_active`, `locked`, `managed_by_control_hub`, `controlhub_*` | `AdSyncChecker::checkWorkstationGroups()`, observers `WorkstationGroupObserver`, services `Parc/`, UI Livewire `parc-settings` | froid |
| `AppProfile::display_name`, `description`, `is_active`, `controlhub_*` | `AdSyncChecker::checkAppProfiles()`, observers `AppProfileObserver`, page `profiles` | froid |
| `Application::*` (~25 colonnes hors `id`/`app_id`) | AppStore (`8-2.x`), pages admin, dashboard installs | froid |

### 4.2 Attributs AD-only par nature — non migrés et NON destinés à l'être

Aucun. Tous les attributs AD utiles côté SQL ont déjà leur miroir (`ad_dn`/`ad_guid` sur les 3 entités principales après volet 2).

Particulièrement :
- `objectGUID` AD est stocké en string lisible (format dashed `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`) sur les colonnes `ad_guid`, conversion par `SyncAllFromAdJob::convertGuidToString()` ligne 524 (cf. H5 de la story confirmée par lecture code).
- Les attributs LDAP `cn`, `iphostnumber`, `networkaddress`, `operatingsystem`, `description`, `memberof` sont mappés vers les colonnes SQL existantes (`name`, `ip`, `mac`, `os`, `description`, relations `groups`) au moment du sync — cf. `SyncAllFromAdJob::fetchMachinesFromAd()` lignes 200-242. Aucun attribut LDAP n'est volontairement laissé "AD-only".
- `objectClass`, `whenCreated`, `whenChanged`, `userAccountControl`, `dnsHostName` AD ne sont **pas** copiés en SQL et le restent (purement AD, jamais lus par le pipeline). Catégorie B confirmée.

---

## 5. Plan migration (volet 2 — non exécuté ici)

Tâches T1 telles que reformulées par cet audit, à valider par Henri avant exécution :

```
database/migrations/<ts1>_add_lifecycle_attrs_to_workstations_and_groups.php
  - workstations:
      + last_seen_at  TIMESTAMP NULL  INDEX
      + archived_at   TIMESTAMP NULL  INDEX
  - workstation_groups:
      + last_seen_at  TIMESTAMP NULL  INDEX
      + archived_at   TIMESTAMP NULL  INDEX

database/migrations/<ts2>_add_lifecycle_attrs_to_app_profiles.php
  - app_profiles:
      + ad_dn         VARCHAR(512) NULL
      + archived_at   TIMESTAMP NULL  INDEX
      + last_seen_at  TIMESTAMP NULL  INDEX  (optionnel — à confirmer Henri)
```

**Mise à jour modèles requise (T1)** :
- `Workstation` : `$fillable += ['last_seen_at', 'archived_at']`, `$casts += ['last_seen_at' => 'datetime', 'archived_at' => 'datetime']`, docblock `@property` ajouté, scopes `scopeNotArchived(Builder)` et `scopeStaleSince(Builder, Carbon $since)`.
- `WorkstationGroup` : idem.
- `AppProfile` : `$fillable += ['ad_dn', 'archived_at', 'last_seen_at']`, `$casts += [...]`, docblock, scope `notArchived()`. Méthode statique `findByAdDn()` à ajouter par symétrie (cf. `WorkstationGroup::findByAdDn()` ligne 515).

**Tests (volet 2)** :
- `tests/Feature/Migrations/WpkgEloquentSchemaMigrationsTest.php` : run + rollback + run, présence colonnes/indexes, casts datetime nullable. PHPUnit attributs `#[Test]`.
- Vérifier non-régression suite 15.2 (resolver, generator, controllers, listeners).
- **Nouveau test resolver** (à acter pendant T1, en lien avec D8) : `WorkstationPackagesResolver::resolve()` ignore les postes/groupes/profils où `archived_at IS NOT NULL`.

---

## 6. Plan durcissement `SyncAllFromAdJob` (volet 3 — non exécuté ici)

Synthèse rapide pour traçabilité audit (cf. story 15.3 §AC3.x pour détails). Reste à exécuter au volet 3 par le dev-agent suivant.

| Élément | Source story | Statut audit |
|---|---|---|
| `dryRun` constructeur + skip écritures | AC3.1 | OK, pas d'impact schéma |
| `--dry-run` sur `SyncFromAd.php` artisan | AC3.1 | OK, pas d'impact schéma |
| Bouton UI « Aperçu » sur `/admin/sync-from-ad` (pattern `rights_migration` lignes 271-323) | AC3.1 | OK |
| `Cache::lock('wpkg:sync-all-from-ad', $ttl)` anti-double-clic + TTL config `sambaedu.wpkg.sync.lock_ttl_seconds` | AC3.2 | OK, dépend de `config/sambaedu.php` (à étendre T3) |
| Logs `Log::channel('wpkg-deploy')->withContext([...])` + rapport stats détaillé | AC3.3 | OK, channel livré 15.1 |
| `archived_at = now()` au lieu de DELETE | AC3.4 | **dépend volet 2** (les colonnes doivent exister) |
| 2 passes strictes (lecture complète AVANT écriture) | AC3.5 | OK, refactor `handle()` |
| Idempotence stricte (no-op si rien ne change) | AC3.6 | OK |
| Match strict premier run : `name` lower-case + scope OU précis | AC3.7 | OK ; le job actuel match déjà par `name` lower-case mais sans scope OU (fallback dans `syncAppProfiles` ligne 312-323 et `syncWorkstationGroups` ligne 254-264). À durcir avec un check de scope OU explicite (`OU=Computers` pour groupes, `OU=Parcs` pour profils) avant écriture. |

**Préservation des invariants (NE PAS CASSER)** :
- `WorkstationGroupObserver::disableSync()` / `enableSync()` autour de la passe 2 (déjà en place lignes 76-77 et 109-110 du job). À préserver impérativement.
- `WorkstationObserver::disableSync()` / `enableSync()` (lignes 77 et 110). Idem.
- Si `AppProfileObserver` existe et applique le même mécanisme, l'inclure dans la même paire `try/finally`.
- `DB::transaction` enveloppe la passe 2 (actuellement `DB::beginTransaction` ligne 73 + `commit` ligne 98 / `rollBack` ligne 105). Refactor possible vers `DB::transaction(function () {...})` pour atomicité plus stricte, mais le pattern `beginTransaction`/`commit`/`rollBack`/`finally` actuel est équivalent — ne pas perdre les `disableSync()` dans le refactor.
- Bloc `fetchMachinesFromAd()`/`syncWorkstations()`/`syncWorkstationLinks()` est actuellement **commenté** (lignes 92-96 du job, problème RAM serveur) ; H3 confirmée. Le volet 3 ne le réactive pas (hors scope). Si la 15.5 veut un drift machines fiable, escalation Henri pour décision spécifique.

---

## 7. Cross-check legacy

### 7.1 `legacy/wpkg_libsql.php` (shim Eloquent du legacy SE3)

- `info_postes_uuid()` ligne 136 : itère `Workstation::withUuid()->get()`, exporte `[uuid_poste, nom_poste, flag_poste(=protected), check_poste]`. Consomme `Workstation::name`, `uuid` (matériel ≠ ad_guid), `status`. → Aucune colonne nouvelle nécessaire ; tous les attributs nécessaires sont en SQL aujourd'hui. Catégorie B confirmée.
- `update_parc()` ligne 1266 : `WorkstationGroup::update(['name', 'display_name', 'ad_guid'])`. → Colonnes existantes, OK.
- `delete_parc_wpkg()` ligne 1275 : `$group->delete()` brut. → **À transformer** post-volet 2 en `update(['archived_at' => now()])` + détacher relations, pour cohérence archivage. Hors scope T0/T1, à acter dans story 15.7 (retrait shim) ou via mémo "tech-debt".
- `info_parcs()` ligne 427 : itère `WorkstationGroup::all()`, exporte `name`, `display_name`, `ad_guid`. → OK.
- `info_parc_postes()` ligne 444 : `WorkstationGroup → workstations()`, exporte `os`, `last_report_at`. → OK, attributs existants, chemin froid.
- `info_poste_applications()` (ligne 197+) : porte le legacy équivalent du chemin critique 15.2 → progressivement remplacé par `WorkstationPackagesResolver::resolve()` qui couvre ses 5 sources d'union (cf. signatures des relations Workstation utilisées par le resolver).

### 7.2 `app/Services/AdSync/AdSyncChecker.php`

Service de diff AD ↔ SQL utilisé par les pages settings (chemin froid). Confirme l'inventaire :
- `checkWorkstationGroups()` filtre `is_physical=true` (ligne 45) → chemin froid uniquement (le pipeline 15.2 ne filtre pas).
- `checkAppProfiles()` exclut `name='computers'` (système).
- `checkWorkstations()` charge tous les attributs AD (`memberof`, `iphostnumber`, `networkaddress`, `objectguid`, DN parsing pour salle) → tous chemin froid.
- Le diff par GUID est déjà la stratégie privilégiée (lignes 60-95, 196-233, 351-389) → cohérent avec le match strict du job durci AC3.7.

→ Aucun manquant catégorie A révélé par cross-check.

### 7.3 Note `WpkgAdReconciliationJob` whitelist

- README de 15.2 (`app/Wpkg/Deployment/README.md` ligne 61, branche `wpkg-15-3`) mentionne encore : « la sync AD reste un job périodique (`WpkgAdReconciliationJob`, Story 15.3) ». **À retirer** au volet T6 (test archi) **et** au volet T7 (doc) après suppression définitive du job par décision de cadrage 2026-05-06.
- `tests/Architecture/WpkgDeploymentNamespaceTest.php` est à inspecter pour vérifier qu'aucune whitelist `WpkgAdReconciliationJob` n'y subsiste. (Non lu durant T0 — à faire au volet T6.)

---

## 8. Hypothèses de la story 15.3 — confirmation/réfutation par l'audit

| Hyp | Énoncé | Statut |
|---|---|---|
| **H1** | `ad_dn` et `ad_guid` sont déjà sur `Workstation`, `WorkstationGroup`, `AppProfile` | **Partiellement réfutée** : `ad_dn` est **absent** d'`app_profiles` (cf. §2.4). `ad_guid` confirmé partout. |
| **H2** | `last_seen_at` et `archived_at` sont les manquants principaux | **Confirmée + élargie** : `archived_at` requis sur les 3 entités structurelles (`workstations`, `workstation_groups`, `app_profiles`). `last_seen_at` recommandé sur les 3 par symétrie. Plus le `ad_dn` sur `app_profiles`. |
| **H3** | `fetchMachinesFromAd()` reste désactivé pour 15.3 | **Confirmée** (lignes 92-96 du job commentées, problème RAM préservé). |
| **H4** | `Cache::lock` suffisant en prod (Redis) | **Hors scope audit T0** — à confirmer en lisant `config/cache.php` lors du volet 3. |
| **H5** | `objectGUID` stocké en string (pas binaire) sur `ad_guid` | **Confirmée** : `convertGuidToString()` ligne 524 du job convertit vers format dashed string ; colonnes SQL `varchar(36)`. |

---

## 9. Risques résiduels post-T0

- **R-T0.1** : `app_profiles.ad_dn` est un manquant structurel non anticipé par la story. Impact : si le volet 2 oublie de migrer cette colonne, le volet 3 ne pourra pas écrire `ad_dn` sur `AppProfile` lors du peuplement initial → drift partiel sur les profils, opérateur frustré. Mitigation : migration 2 explicitement listée au §5.
- **R-T0.2** : Le worktree primaire `main` n'a pas encore reçu le merge de 15.2. Impact : en l'état, la migration `2026_05_05_100100_create_wpkg_workstation_options_table.php` n'est pas dans `main`, et l'audit cite des classes `App\Wpkg\Deployment\*` qui n'existent pas dans `main`. Mitigation : Henri merge 15.2 → main avant T1, ou bien T1/T2/T3 attendent la promotion de 15.2 en `done`.
- **R-T0.3** : Décision D8 (filtrage `archived_at IS NULL` dans `WorkstationPackagesResolver`) a un impact léger non breaking sur 15.2. À acter pendant T1 et tester explicitement, sinon des packages zombies pourraient remonter.
- **R-T0.4** : Le legacy `delete_parc_wpkg` (`legacy/wpkg_libsql.php:1275`) supprime sèchement. Une fois `archived_at` introduit, il faut soit l'adapter, soit l'isoler comme dette technique tracée. Hors scope T0/T1 ; à mentionner dans story 15.7.

---

## 10. Conclusion / recommandation

L'audit confirme la lecture qu'avait la story 15.3 du schéma actuel mais y apporte **2 corrections** :

1. **`app_profiles.ad_dn` est manquant** (H1 partiellement réfutée) → 1 migration corrective ciblée.
2. **`archived_at` doit aussi atterrir sur `app_profiles`**, pas seulement sur `workstations` et `workstation_groups` → impacts modèle + scope `notArchived()` + eager-load resolver à l'examen (D8).

**Plan T1 proposé** : 2 migrations (vs 1 envisagée), sur 3 tables (vs 2 envisagées). Pas de migration nécessaire pour `applications` ni `wpkg_workstation_options`.

**Plan T3 (volet 3, hors T0)** : conforme à la story 15.3 sans déviation.

Cet audit est en attente de **validation explicite par Henri** avant T1 (cf. AC1.1 « validé explicitement par Henri ; commit séparé recommandé »). Le dev agent (T1+) ne démarre pas tant que ce document n'est pas signé.

---

## Annexe — Checklist BMAD T0 réalisée

- [x] Procédure ops finale de bascule prod documentée (`migrate` → UI sync-from-ad → Aperçu → Exécuter) — §1, D5
- [x] Abandon explicite de la commande artisan dédiée de peuplement initial — §1, D4
- [x] Inventaire `Workstation` — §2.1
- [x] Inventaire `WorkstationGroup` — §2.2
- [x] Inventaire `Application` — §2.3
- [x] Inventaire `AppProfile` — §2.4
- [x] Inventaire `WpkgWorkstationOption` — §2.5
- [x] Classification A/B par attribut — §2 entier + §4
- [x] Liste exhaustive manquants catégorie A — §3
- [x] Cross-check `legacy/wpkg_libsql.php` (`info_postes_uuid`, `update_parc`, `delete_parc_wpkg`, `info_parcs`, `info_poste_applications`) — §7.1
- [x] Cross-check `app/Services/AdSync/AdSyncChecker.php` — §7.2
- [x] Documentation absence de cron entrant + lien décisions cadrage 2026-05-05/06 — §1, D1
- [x] Rôle de `SyncAllFromAdJob` durci documenté — §1 D3 + §6
- [x] Document produit dans `_bmad-output/planning-artifacts/audit-wpkg-eloquent-schema.md`
- [x] Validation explicite Henri (validée 2026-05-06, déclenchement T1)

---

## Statut « décisions livrées » (post-implémentation T1-T7, 2026-05-06)

L'implémentation T1-T7 est livrée et confirme l'audit T0 sans déviation
majeure. Récapitulatif des décisions de cadrage actées en code :

| Décision | Statut | Référence livraison |
|---|---|---|
| **D1** Pas de cron entrant | livré | aucun `$schedule->job(SyncAllFromAdJob)` ; doc README + architecture.md mises à jour |
| **D2** Direction d'écriture canonique Eloquent → AD | préservé | invariant `disableSync()`/`enableSync()` en `finally` dans `SyncAllFromAdJob::runPass2Apply` |
| **D3** `SyncAllFromAdJob` outil unique | livré | job durci (lock + 2 passes + dry-run + archivage + idempotence + match strict) |
| **D4** Pas de commande artisan dédiée peuplement initial | livré | `php artisan sync:from-ad [--dry-run]` couvre intégralement le besoin |
| **D5** Procédure ops bascule prod | documentée | `app/Wpkg/Deployment/README.md` + `docs/wpkg-deploy/architecture.md` + `docs/qa/domains/wpkg-deploy.md` Section 3.6 |
| **D6** Vocabulaire « chemin critique / chemin froid » | appliqué | tous les commentaires + docblocks + doc respectent la convention ; « hot path » résiduel uniquement dans `WorkstationPackagesResolver` (port legacy header — à refactorer en 15.4 ou 15.7) |
| **D7** Test architectural sans whitelist | livré | `WpkgDeploymentNamespaceTest` durci (3 préfixes interdits : `LdapRecord\\`, `App\\LdapModels\\`, `App\\Services\\Ad\\` ; whitelist vide) |
| **D8** Filtre `archived_at IS NULL` dans resolver | livré | `WorkstationPackagesResolver::computePackages` filtre poste, groupes, profils ; test dédié `WorkstationPackagesResolverArchivedTest` (4 tests verts) |

### Manquants catégorie A migrés (T1) — révisé post-review (2026-05-06)

- `workstations` : `archived_at` ✅
- `workstation_groups` : `archived_at` ✅
- `app_profiles` : `ad_dn` (correction H1) + `archived_at` ✅

> **Décision post-review (Q1, 2026-05-06)** : la colonne `last_seen_at`
> initialement migrée a été **retirée du scope** (option C2 retenue
> par Henri). Justification : pas de besoin métier réel — l'archivage
> des orphans est déjà calculé dans le même run du `SyncAllFromAdJob`
> (par diff `preExistingGuidIds` ↔ `matchedDbIds`), et le scope
> `staleSince()` n'était utilisé nulle part. La présence de `last_seen_at`
> imposait par ailleurs un `saveQuietly()` parasite à chaque run, ce qui
> violait l'idempotence stricte AC3.6 (cf. doc review §Q1, problème #3).
> Pas de table de remplacement (option C1 rejetée).

→ 2 migrations livrées : `2026_05_06_100000_add_archived_at_to_workstations_and_groups`,
`2026_05_06_100100_add_archived_at_and_ad_dn_to_app_profiles`. Test feature
`WpkgEloquentSchemaMigrationsTest` couvre up/down/up + présence colonnes
+ insert null/datetime + assertion explicite `last_seen_at NOT IN columns`.

### Risques résiduels post-implémentation

- **R-T0.1** Audit sous-estime manquants : ✅ adressé (audit listait
  exhaustivement, migrations couvrent tout).
- **R-T0.2** Worktree main sans 15.2 mergé : encore vrai mais non bloquant
  (le worktree `wpkg-15-3` contient T1-T7 + 15.2). Henri merge à sa
  convenance — la story 15.3 ne dépend pas de l'état de `main`.
- **R-T0.3** Filtre D8 résolveur : ✅ livré + test dédié.
- **R-T0.4** `delete_parc_wpkg` legacy supprime sec : tracé pour 15.7 (hors
  scope 15.3, mémo « tech-debt » dans le runbook bascule).

### Surprises livraison T1-T7

1. **Bug archivage post-création détecté en T4** : la première version
   de `syncWorkstationGroups` queryait les orphans avec
   `whereNotIn('id', $matchedDbIds)` → les rows fraîchement créées
   pendant la même passe étaient considérées orphelines. Fix : capturer
   `$preExistingGuidIds` **avant** la passe et limiter le scope
   d'archivage à ces IDs uniquement. Idem pour `syncAppProfiles`.
2. **`WpkgSchemaBootstrapper` shim 15.2 incomplet** : ne contenait pas
   les colonnes `ad_guid`, `ad_dn`, `display_name`, `description` ni les
   colonnes lifecycle. Étendu pour permettre aux tests `SyncAllFromAdJobTest`
   et `WorkstationPackagesResolverArchivedTest` de tourner sur SQLite
   :memory: sans rejouer la baseline migrations complète.
3. **UI `sync-from-ad`** : la story laissait la porte ouverte à
   « ajouter Aperçu sur les étapes pilotées par le job ». L'analyse a
   montré que les étapes 3-6 utilisent leurs **propres services**
   (`WorkstationGroupService::importFromAd`, etc.), distincts du
   `SyncAllFromAdJob`. Décision de design : ajouter une **étape 10
   dédiée** « Remédiation drift WPKG » pour ne pas durcir 4 services
   upstream — cohérent avec la suggestion D3 de la story (« uniquement
   les étapes pilotées par le job durci »).
