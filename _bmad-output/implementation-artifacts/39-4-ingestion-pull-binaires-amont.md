# Story 39.4: Ingestion + pull des binaires amont (canal ④ : `artifact`/`executable`/`delivery_mode`)

Status: to-validate

<!-- Créée le 2026-07-04 (create-story, Epic 39 — epics-alignement-controlhub-se5.md).
     Décision produit figée (Henri, 2026-07-04) : adopter le PULL central pour le canal ④. -->

## Story

As l'autorité amont (controlHub),
I want que SE5 **ingère** les blocs `delivery_mode` (items), `artifact {url, checksum, filename, size}` (items `wallpapers`/`agent_tools`) et **tire** (pull) le binaire correspondant depuis une URL signée avec vérification **sha256** quand l'asset n'existe pas déjà localement,
so that un wallpaper ou un outil agent **imposé** par le contrat devienne disponible sur l'instance même si l'établissement ne l'a **jamais** uploadé lui-même — sans que le binaire ne transite jamais dans le payload du contrat (canal ④, FR-A4).

**Cadrage du périmètre (à lire avant tout le reste).** Cette story couvre l'**ingestion + la persistance + le pull + la matérialisation locale** des binaires `wallpapers`/`agent_tools`. Elle ne couvre **PAS** le branchement de ces types dans `StateCompiler` (aucun `UpstreamPayloadAdapter` n'existe pour `wallpapers`/`agent_tools` — cf. Dev Notes « Hors périmètre ») : un wallpaper pullé devient un `WallpaperAsset` de bibliothèque disponible, il ne devient **pas** automatiquement le fond imposé d'un poste. C'est un existant assumé (le point d'extension d'Epic 33), pas une régression introduite ici.

## Acceptance Criteria

1. **Artefact R2 remis en cohérence (tâche à part entière, AVANT le code).** `_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md` documente, en §3.1 et §3.4, les nouveaux champs comme **additifs et optionnels** :
   - `items[].delivery_mode` (string?, ex. `install` | `download_direct` — vocabulaire du contrat amont, non arbitré côté SE5 en 39.4, cf. AC6) ;
   - `items[].artifact` (object?, uniquement significatif pour `type ∈ {wallpapers, agent_tools}`) : `{url: string, checksum: string (sha256 hex), filename: string, size: int}` ;
   - `catalog_apps[].executable` (object?, même forme que `artifact`) — **persistance seule en 39.4**, cf. AC7 et la note de risque en Dev Notes.
   Aucun bump de `schema_version` (doctrine additive déjà en vigueur pour `source_xml_url`/`source_xml_sha`, Story 31.3). Une note de coordination confirme que **les deux BMAD** doivent pointer cet artefact (§5 existant, à compléter, pas à réécrire).

2. **Migration additive #1 — `controlhub_contract_items`.** Nouvelle migration (après `2026_07_04_120100_create_folder_access_rule_audit_logs_table.php`, garde `Schema::hasColumn`, `down()` symétrique, patron `2026_06_29_100000_add_source_to_controlhub_catalog_apps.php`) ajoutant, toutes **nullables** :
   - `delivery_mode` (string) ;
   - `artifact_checksum`, `artifact_filename` (string), `artifact_size` (bigInteger) ;
   - `pull_status` (string, valeurs `pending|downloaded|error` — nouvel enum `App\Enums\ControlHubArtifactPullStatus`, patron `ControlHubEnforcementState`), `pull_error` (text).
   ⚠️ **`artifact_url` n'est PAS persisté en colonne** — cf. AC5 (piège d'idempotence). La clé naturelle `(controlhub_contract_id, type, key, target_type, target_label)` reste **inchangée**.

3. **Migration additive #2 — `controlhub_contract_catalog_apps`.** Même patron, colonnes nullables `executable_checksum`, `executable_filename`, `executable_size` (pas de `pull_status`/`pull_error` requis : aucun traitement de pull ne les consomme en 39.4, cf. AC7). Clé naturelle `(controlhub_contract_id, app_key)` **inchangée**.

4. **Ingestion étendue — lecture/persistance additive.** `ControlHubContractIngestionService::normalizeItems()` lit `delivery_mode` et `artifact.{checksum,filename,size}` (absents ⇒ `null`, comme `source_xml_url`/`sha` en 31.3) ; `normalizeCatalogApps()` lit `executable.{checksum,filename,size}` de la même façon. **Aucune validation de domaine nouvelle** sur ces champs (pas d'enum fermé sur `delivery_mode` en 39.4 — cf. AC6) : un payload qui ne les porte pas reste accepté à l'identique d'aujourd'hui (28.2/33 non régressés). `artifact.url`/`executable.url` sont **lus mais jamais écrits en colonne** (cf. AC5) — ils ne servent qu'à alimenter, en mémoire, le déclenchement du pull (AC8).

5. **Piège d'idempotence — identité stable ≠ URL (NFR-A2, critique).** Les URL signées sont **régénérées à chaque émission** du contrat, même si le binaire est strictement identique (l'identité stable d'un binaire est son `checksum` — cf. `CONTRAT-MANAGE-SE5-IRUNDO.md#7.2`). Le calcul de mutation (`reconcileChildren()` via `Model::wasChanged()`) **NE DOIT PORTER QUE** sur les colonnes stables (`delivery_mode`, `artifact_checksum`, `artifact_filename`, `artifact_size`, `executable_checksum`, `executable_filename`, `executable_size`) : puisque `artifact_url` n'est pas une colonne (AC2), il ne peut pas polluer `wasChanged()`. **Test qui prouve l'AC** : ré-ingérer un payload identique dont **seule** `artifact.url` diffère (checksum/filename/size inchangés) → `mutated=false`, aucun événement `ControlHubContractChanged`, aucun nouveau job de pull dispatché (AC8).

6. **`delivery_mode` : capturé, non arbitré.** Un `delivery_mode` inconnu ou absent est accepté **sans rejet** (pas de `InvalidUpstreamContractException` sur ce champ en 39.4 — aucun consommateur n'en dépend aujourd'hui, cf. Dev Notes « Hors périmètre »). C'est une décision de **portée volontairement minimale** : le champ est stocké pour traçabilité/évolution future, pas interprété.

7. **`catalog_apps.executable` : persistance seule, PAS de pull en 39.4.** Le champ est lu et persisté (AC3/AC4) mais **aucune matérialisation locale n'est déclenchée** pour `executable` dans cette story (aucun job, aucun téléchargement). Justification (Dev Notes) : absence de spécification concrète côté central (aucun exemple JSON, aucune colonne `executable` sur `contract_catalog_apps` dans l'ER du board central) et recouvrement avec un mécanisme **déjà tenté et abandonné** côté SE5 (`applications.installer_url/installer_sha256/installer_filename/installer_size/local_installer_path`, marqué destruction séparée — `sprint-status.yaml` → `personal_notes.todos` → `supprimer-colonnes-installer-star`). Si un besoin réel émerge, une story dédiée symétrique à AC8 est le point d'extension propre.

8. **Pull asynchrone — wallpapers/agent_tools uniquement.** Après le commit de l'ingestion (même point que `ControlHubContractChanged::dispatch()`, **hors transaction**), pour chaque item `type ∈ {wallpapers, agent_tools}` porteur d'un `artifact` complet (`checksum` non vide) :
   - **Précédence locale (le pull comble l'absence, jamais ne remplace) :**
     - `wallpapers` : absent localement si `WallpaperAsset::where('checksum', $checksum)->doesntExist()` — identité par **checksum**, cohérent avec le modèle de bibliothèque content-addressée existant (`WallpaperUploadService`) ;
     - `agent_tools` : absent localement si `AgentTool::where('key', $item->key)->doesntExist()` — identité par **clé fonctionnelle**, cohérent avec `AgentToolService::registerEmbedded()` (mono-version par clé, idempotent) ;
   - Si présent localement → **aucun pull déclenché**, `pull_status` reste `null` (rien à faire — ce n'est pas un état « pending » puisqu'aucune action n'est requise).
   - Si absent → un job `ShouldQueue` (`App\Jobs\ControlHub\PullContractArtifactJob`, patron `DownloadWindowsIsoJob` pour la structure job/lock/`tries=1`, mais téléchargement via `Illuminate\Support\Facades\Http` — fichiers petits, pas de `curl` shell) est dispatché avec `(itemId, type, key, url, checksum, filename, size)` **en arguments du job** (l'URL vit dans le job, JAMAIS en colonne DB — AC2/AC5). `pull_status` passe à `pending` avant dispatch.

9. **Vérification d'intégrité + matérialisation (job).** Le job télécharge vers un fichier temporaire (jamais directement dans le foyer final), calcule `hash_file('sha256', ...)` **côté serveur** (jamais un hash déclaré) :
   - **Match** → matérialise dans le foyer local approprié SANS faire confiance à `artifact.filename` pour le nommage disque (anti-traversal — dérivé serveur, iso `AgentToolService`/`WallpaperUploadService`) :
     - `wallpapers` → écrit `<checksum>.jpg`-style content-addressé dans `WallpaperAsset::libraryPath()` (`config('wallpapers.library_path')`) SANS re-normaliser/recompresser (une re-normalisation Imagick changerait le checksum — le fichier pullé est déjà celui dont le checksum a été vérifié), puis `WallpaperAsset::firstOrCreate(['checksum' => ...], ['filename' => ..., 'byte_size' => ..., 'uploaded_by' => null])` ;
     - `agent_tools` → écrit dans `config('agent.tools_path')` un filename dérivé serveur (jamais `artifact.filename` brut), puis `AgentTool::updateOrCreate(['key' => $item->key], ['filename' => ..., 'sha256' => $checksum, 'size' => ..., 'uploaded_at' => now(), 'uploaded_by' => null])` — **`enabled` non touché s'il existe déjà** ; à la création, reste désactivé par défaut (iso `registerEmbedded()`, l'admin active explicitement).
     `pull_status = downloaded`, `pull_error = null`.
   - **Mismatch** (checksum calculé ≠ `artifact.checksum` déclaré) → **aucune écriture** dans `WallpaperAsset`/`AgentTool`, fichier temporaire supprimé, `pull_status = error`, `pull_error` renseigné (message court, sans logguer l'URL signée en clair si elle contient un secret de signature — NFR-A3). Item consommable en `error` par un futur émetteur de conformité (Story 39.2, canal ③) via `pull_status`.
   - **Ré-pull au même checksum = no-op** : si un job est redéclenché pour un item déjà `downloaded` avec le **même** checksum, la précédence locale (AC8, vérifiée à nouveau en tête du job) fait qu'aucun téléchargement n'est retenté.

10. **Non-régression (NFR-A4/NFR-A5/R3).** Comportement à **0 binaire amont strictement inchangé** : sans `artifact`/`executable` dans le payload, l'ingestion produit exactement le même résultat qu'avant 39.4 (28.2/33 non régressés — le test golden le prouve, cf. AC11). `StateCompiler`/`ContractV1`/golden/`FROZEN_STATE_HASH`/`agent/**` **non touchés** ; bump `agent/shared/version.go` **uniquement** si le payload agent change (a priori **NON** — aucun `StateProvider` nouveau, aucun payload agent modifié). Aucun mot « central » dans un identifiant/colonne/message nouveau.

11. **Tests HÔTE** (php8.4 + pdo_sqlite, `RefreshDatabase`) :
    - Extension de `tests/Feature/ControlHub/ControlHubContractIngestionTest.php` (ou nouveau fichier `UpstreamArtifactIngestionTest.php`, au choix du dev — cohérence avec le patron existant) : payload avec `delivery_mode`+`artifact` sur un item `wallpapers` → colonnes persistées ; payload **sans** ces champs → comportement byte-identique à l'existant (no-op préservé, compteurs inchangés) ; ré-ingestion à **URL différente, checksum identique** → `mutated=false` (AC5, LE test qui protège le piège) ;
    - Nouveau `tests/Feature/ControlHub/ArtifactPullServiceTest.php` (ou `Jobs/PullContractArtifactJobTest.php`) avec `Http::fake()` : sha256 OK → `WallpaperAsset`/`AgentTool` créé, `pull_status=downloaded` ; sha256 KO → **aucune** écriture, `pull_status=error`, `pull_error` rempli ; asset local déjà présent (par checksum/clé) → **aucun appel HTTP** (assert `Http::assertNothingSent()` ou équivalent), pas de job dispatché depuis l'ingestion ; ré-pull même checksum → no-op identique.
    - Non-régression golden : `php artisan test --filter=ContractV1Test` (ou suite golden équivalente) → `FROZEN_STATE_HASH` inchangé.

## Tasks / Subtasks

- [x] Task 1 — Artefact R2 (AC: 1)
  - [x] Étendre §3.1 (`items`) et §3.4 (`catalog_apps`) de `schema-echange-controlhub-se5.md` : nouveaux champs additifs optionnels, exemples JSON repris de `CONTRAT-MANAGE-SE5-IRUNDO.md#3.1`
  - [x] Compléter §5 (coordination R2) : mention explicite de la remise en cohérence 39.4
  - [x] **Ne pas** bumper `schema_version` (doctrine additive)
- [x] Task 2 — Migrations additives (AC: 2, 3)
  - [x] `..._add_delivery_and_artifact_to_controlhub_contract_items.php` (guard `hasColumn`, `down()` symétrique)
  - [x] `..._add_executable_to_controlhub_contract_catalog_apps.php` (idem)
  - [x] Nouvel enum `App\Enums\ControlHubArtifactPullStatus` (`Pending`, `Downloaded`, `Error`)
- [x] Task 3 — Ingestion étendue (AC: 4, 5, 6, 10)
  - [x] `normalizeItems()` : lecture additive `delivery_mode`/`artifact.{checksum,filename,size}`, jamais `artifact.url` en colonne
  - [x] `normalizeCatalogApps()` : lecture additive `executable.{checksum,filename,size}`
  - [x] Vérifier que `reconcileChildren()`/`wasChanged()` ne peut PAS être pollué par une URL (pas de colonne = pas de risque structurel — documenté en commentaire ; URL portée hors key/attrs sur la row)
  - [x] Golden/FROZEN_STATE_HASH : confirmé intact (aucun `StateProvider` touché ; `ContractV1Test` vert)
- [x] Task 4 — Déclenchement du pull (AC: 8)
  - [x] Après commit (à côté de `ControlHubContractChanged::dispatch()`), collecter les items `wallpapers`/`agent_tools` avec `artifact.checksum` non vide
  - [x] Vérifier précédence locale (`WallpaperAsset` par checksum / `AgentTool` par clé) — dispatch `PullContractArtifactJob` uniquement si absent
  - [x] Poser `pull_status=pending` avant dispatch
- [x] Task 5 — Job de pull + matérialisation (AC: 9)
  - [x] `App\Jobs\ControlHub\PullContractArtifactJob` (`ShouldQueue`, `tries=1`, patron structurel `DownloadWindowsIsoJob` mais `Http::` pour le téléchargement) ; logique dans `ArtifactPullService` (testabilité)
  - [x] Téléchargement vers fichier temporaire, `hash_file('sha256', ...)` serveur, comparaison stricte (`hash_equals`)
  - [x] Match → matérialisation content-addressée (`WallpaperAsset`) ou par-clé (`AgentTool`), filename **dérivé serveur** (jamais `artifact.filename` brut)
  - [x] Mismatch → `pull_status=error` + `pull_error`, aucune écriture, fichier tmp supprimé
  - [x] Re-vérifier la précédence locale EN TÊTE du job (garde contre une double exécution / re-tentative)
- [x] Task 6 — `catalog_apps.executable` : persistance seule (AC: 7)
  - [x] Confirmé dans le code/commentaire qu'aucun pull n'est déclenché pour `executable` en 39.4 (docblock explicite dans `normalizeCatalogApps()` + migration, renvoyant à cette story + à la note de risque)
- [x] Task 7 — Tests (AC: 11)
  - [x] Nouveau test ingestion `UpstreamArtifactIngestionTest` (persistance additive, no-op préservé, piège URL/checksum AC5)
  - [x] Nouveau test pull `ArtifactPullServiceTest` (sha256 OK/KO, précédence locale, no-op ré-pull)
  - [x] Non-régression golden + suites ControlHub existantes (`ControlHubContractIngestionTest`, `ControlHubContractSchemaVersionTest`, `ContractIngestionEndpointTest`, `ContractV1Test`)

## Dev Notes

### Existant à réutiliser — NE PAS réécrire

| Brique | Fichier | Rôle pour 39.4 |
|---|---|---|
| Ingestion idempotente | `app/Services/ControlHub/ControlHubContractIngestionService.php` | Cible d'extension additive (`normalizeItems()`/`normalizeCatalogApps()`). Ne pas toucher `reconcileChildren()` générique (partagé par les 4 agrégats). |
| Bibliothèque wallpaper (content-addressée) | `app/Models/WallpaperAsset.php`, `app/Services/Wallpaper/WallpaperUploadService.php` | Patron de matérialisation par `checksum` : `<checksum>.jpg` sous `WallpaperAsset::libraryPath()`, `firstOrCreate(['checksum' => ...], [...])`. Le pull NE DOIT PAS repasser par `ingestAsset()` (attend un `UploadedFile`, re-normalise Imagick — changerait le checksum vérifié). Écrire directement, iso convention de nommage. |
| Outil agent (par-clé) | `app/Services/Agent/Tools/AgentToolService.php` (`registerEmbedded()`) | Patron le plus proche du pull : source locale (pas d'`UploadedFile`), idempotent par `key`, filename **dérivé** (jamais le nom source brut), SHA-256 calculé serveur, reste désactivé à la création. Réutiliser la DISCIPLINE (dérivation filename, hash serveur, nettoyage orphelin), pas forcément la méthode elle-même (elle prend un `$sourcePath` local, pas une URL — le job doit d'abord télécharger). |
| Provisioning applicatif ordonné | `app/Services/ControlHub/OrderedApplicationProvisioner.php` + `AppStoreService::materializeFromSource()` | Patron de réconciliation « items non-`absent` → matérialisation si absent localement » (structurellement identique à AC8, mais pour `catalog_apps`/WPKG). Ne PAS confondre avec le pull `executable` (hors périmètre 39.4, AC7). |
| Job de téléchargement + vérif | `app/Ipxe/Iso/Jobs/DownloadWindowsIsoJob.php` | Patron STRUCTUREL (`ShouldQueue`, `tries=1`, gestion d'erreur, log dédié) — PAS le mécanisme de téléchargement (celui-ci shell `curl` pour des ISO multi-Go ; nos artefacts sont petits → `Illuminate\Support\Facades\Http` avec sink/stream suffit, pas de `Process::run`). |
| Enum du domaine | `app/Enums/ControlHubEnforcementState.php` | Patron pour le nouvel enum `ControlHubArtifactPullStatus` (string-backed, 3 valeurs). |
| Schéma d'échange | `_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md` | À ÉTENDRE (Task 1), pas à réécrire — patron d'écriture additive déjà utilisé pour `source_xml_url`/`source_xml_sha` (§3.4 existant). |

### Hors périmètre (à ne PAS livrer dans cette story)

- **Aucun branchement `StateCompiler`** pour `wallpapers`/`agent_tools` amont : `app/Services/ControlHub/Resolution/UpstreamPayloadAdapter.php` documente explicitement que seuls `registry` et `shortcuts` ont un adaptateur enregistré (Story 28.3/33.1) ; les autres types sont **« ignorés proprement »** par `UpstreamContractSource` — ni exception, ni candidat fantôme. Un wallpaper/outil pullé par 39.4 devient un asset de bibliothèque disponible, **pas** un fond/outil imposé sur un poste : `WallpaperStateProvider` résout ses candidats **uniquement** depuis les assignations locales (`Wallpaper`/`wallpaper_assets`), sans jamais lire `controlhub_contract_items`. C'est un existant assumé, pas une régression de 39.4 — le brancher serait le sujet d'une story future (point d'extension déjà identifié par Epic 33).
- **`catalog_apps.executable`** : persistance seule (AC7) — aucun pull, aucune matérialisation « store app ». Voir la note de risque ci-dessous.
- **Aucune interprétation de `delivery_mode`** au-delà du stockage (AC6) — pas de branchement conditionnel `install` vs `download_direct`.

### ⚠️ Note de risque — `catalog_apps.executable` recouvre un mécanisme déjà abandonné

`sprint-status.yaml` (`personal_notes.todos`) porte cette entrée : *« `supprimer-colonnes-installer-star` : colonnes `installer_url`, `installer_sha256`, `installer_filename`, `installer_size`, `local_installer_path` dans `applications` — legacy mono-fichier **jamais utilisé**. Migration destructive à planifier séparément. »* Ces colonnes (migration `2026_02_16_180000_add_appstore_fields_to_applications.php`) sont **exactement** la forme `{url, checksum, filename, size, local_path}` qu'on nous demande de réintroduire pour `executable`. Elles ont été abandonnées au profit du modèle WPKG multi-fichiers (`source_xml_url` + `AppStoreService::materializeFromSource()`, Story 31.3) — un installeur réel n'est quasiment jamais un exécutable isolé. Par ailleurs, **aucun exemple JSON ni colonne ER** ne démontre `executable` côté board central (`CONTRAT-MANAGE-SE5-IRUNDO.md` mentionne seulement un `ExecutableDownloadController`/`…/executables/{depotApplication}/download` en prose, sans schéma de payload). Conclusion : persister sans matérialiser (AC7) évite de réinventer un mécanisme déjà jugé inadapté, en attendant un signal central concret.

### Le piège d'idempotence des URL signées (AC5) — pourquoi c'est critique

`CONTRAT-MANAGE-SE5-IRUNDO.md#7.2` : *« Les URL sont régénérées à chaque émission ; l'identité stable d'un binaire est son checksum. »* Si `artifact_url` était persisté en colonne et inclus dans le calcul `wasChanged()` générique de `reconcileChildren()`, **toute** ré-émission du contrat (même sans changement réel) ferait basculer `mutated=true` pour tout item porteur d'un artefact — un `ControlHubContractChanged` parasite serait émis, et un nouveau job de pull serait dispatché inutilement à chaque re-diffusion. C'est pourquoi `artifact_url`/`executable_url` **ne sont volontairement pas des colonnes** (AC2/AC3) : l'URL ne vit que dans les arguments du job dispatché en mémoire, jamais en DB. Le test AC5/AC11 (ré-ingestion à URL différente, checksum identique → `mutated=false`) est LE garde-fou de non-régression le plus important de cette story.

### Autre garde-fou : le pull ne doit jamais bloquer la requête HTTP d'ingestion

L'ingestion est invoquée synchronement depuis `POST /api/v1/controlhub/contract` (39.1). Un téléchargement (même petit) qui échouerait/traînerait dans la même requête HTTP dégraderait la fiabilité du canal ① tout entier. Le pull est donc **strictement asynchrone** (job `ShouldQueue` dispatché après le commit, jamais un appel HTTP synchrone dans `ingest()` ou dans le contrôleur 39.1).

### Project Structure Notes

- Nouveau job : `app/Jobs/ControlHub/PullContractArtifactJob.php` (namespace à créer si absent — vérifier `app/Jobs/` existant, sinon suivre la convention `app/Jobs/<Domaine>/`).
- Nouveau service optionnel `app/Services/ControlHub/ArtifactPullService.php` si le dev préfère extraire la logique de matérialisation du job (recommandé pour testabilité — le job devient alors un thin wrapper `ShouldQueue`, patron déjà vu ailleurs dans le repo pour les jobs métier).
- Nouvel enum : `app/Enums/ControlHubArtifactPullStatus.php`.
- Migrations : `database/migrations/2026_07_04_1[3-4]xxxx_*.php` (après la dernière migration existante `2026_07_04_120100`).
- Tests : `tests/Feature/ControlHub/` (suite dédiée du domaine, patron `ControlHubContractIngestionTest.php`).

### References

- [Source: _bmad-output/planning-artifacts/epics-alignement-controlhub-se5.md#Story-39.4] — intention, AC-skeleton, FR-A4, NFR-A1..A5, section « La divergence du canal ④ » + « Constat de gouvernance R2 »
- [Source: _bmad-output/planning-artifacts/schema-echange-controlhub-se5.md#3] — schéma actuel des 5 agrégats, à étendre (Task 1)
- [Source: ../irundoo/documentation/CONTRAT-MANAGE-SE5-IRUNDO.md#3.1, #7.2] — exemple JSON `artifact`/`delivery_mode`, mécanique de pull par URL signée + checksum
- [Source: app/Services/ControlHub/ControlHubContractIngestionService.php] — cible d'extension, `reconcileChildren()` générique
- [Source: app/Services/ControlHub/Resolution/UpstreamPayloadAdapter.php] — preuve que `wallpapers`/`agent_tools` ne sont PAS branchés à `StateCompiler` (hors périmètre)
- [Source: app/Services/Agent/Providers/WallpaperStateProvider.php] — confirme la lecture par assignation locale, indépendante des items amont
- [Source: app/Services/Agent/Tools/AgentToolService.php#registerEmbedded] — patron de matérialisation par-clé, idempotente, désactivée par défaut
- [Source: app/Ipxe/Iso/Jobs/DownloadWindowsIsoJob.php] — patron structurel de job de téléchargement asynchrone
- [Source: database/migrations/2026_02_16_180000_add_appstore_fields_to_applications.php] + `sprint-status.yaml#personal_notes.todos.supprimer-colonnes-installer-star` — précédent legacy abandonné, justifie AC7
- [Source: database/migrations/2026_06_29_100000_add_source_to_controlhub_catalog_apps.php] — patron de migration additive nullable pour `controlhub_contract_catalog_apps`

## Dépendances

- **Amont** : Story 39.1 (réception HTTP du contrat — déjà livrée sur cette base, statut `to-validate`) ; `ControlHubContractIngestionService` (28.2/33, DONE) ; providers de binaires locaux `WallpaperAsset`/`WallpaperUploadService`, `AgentTool`/`AgentToolService` (existants, non modifiés dans leur contrat public).
- **Aval** : Story 39.2 (émetteur de conformité, canal ③) pourra lire `pull_status`/`pull_error` pour construire son statut `error` par item (cf. AC9) — **coordination de fichiers** : les deux stories peuvent toucher `config/controlHub.php` en parallèle (39.2 y ajoute un endpoint de conformité) — vérifier au merge qu'aucune des deux n'écrase l'ajout de l'autre.
- **Indépendante** de 39.3 (bridge IdP).

### Questions ouvertes (position par défaut proposée — à confirmer au dev/Henri)

1. **Foyer de stockage des binaires tirés** — TRANCHÉ par défaut dans cette story : `WallpaperAsset::libraryPath()` pour wallpapers, `config('agent.tools_path')` pour agent_tools (foyers EXISTANTS, pas de nouveau chemin de stockage à inventer). Pas de foyer pour `executable`/catalog_apps (AC7, différé).
2. **Moment du pull** — TRANCHÉ par défaut : job asynchrone dispatché **après le commit d'ingestion** (pas au check-in agent, pas à la résolution `StateCompiler` — ces deux points ne consultent de toute façon pas `controlhub_contract_items` pour ces types, cf. Hors périmètre). Alternative écartée : pull au check-in agent aurait exigé un branchement `StateCompiler` hors périmètre (voir ci-dessus) et aurait couplé la latence de résolution d'état à un téléchargement réseau — moins sûr.
3. **TTL des URL signées et re-signature** — RISQUE RÉSIDUEL ASSUMÉ : le job consomme l'URL reçue à l'ingestion ; si la queue est très en retard (rare, hors charge nominale), l'URL pourrait avoir expiré au moment de l'exécution du job → le pull échoue en `error`, **récupérable** à la prochaine ré-émission du contrat (qui régénère une URL fraîche et re-décide de dispatcher si l'asset est toujours absent localement). Aucune re-signature à la demande n'est implémentée en 39.4 (spéculatif sans occurrence réelle observée — anti sur-engineering).
4. **`delivery_mode` inconnu** — TRANCHÉ par défaut : accepté sans rejet, stocké tel quel, non interprété (AC6). Pas de domaine fermé imposé côté SE5 en 39.4.
5. **`catalog_apps.executable`** — TRANCHÉ par défaut : persistance seule, pull explicitement différé (AC7) — cf. note de risque legacy ci-dessus. **Cette position doit être confirmée avec Henri** avant que le dev n'investisse au-delà de la persistance si un besoin réel se précise en cours de route.

## Dev Agent Record

### Agent Model Used

opus (Claude Opus 4.8 [1M]) — worktree `ultradev/39-4`, tests HÔTE php8.4 + pdo_sqlite.

### Debug Log References

- `php artisan test --filter="UpstreamArtifactIngestion|ArtifactPullService"` → **15 passed (48 assertions)**.
- `php artisan test --filter="ControlHubContractIngestion|ControlHubContractSchemaVersion|ContractIngestionEndpoint|ContractSeveranceChannels"` → **37 passed (767 assertions)** (non-régression 28.2/33/39.1).
- `php artisan test --filter=ContractV1Test` → **5 passed (124 assertions)** — `FROZEN_STATE_HASH` inchangé (« state hash is frozen regression guard » vert).
- `git status` : aucun fichier `agent/**`, `StateCompiler*`, `version.go`, golden touché.

### Completion Notes List

- **Idempotence par checksum (AC5, piège critique)** : `artifact_url`/`executable_url` ne sont PAS des colonnes. L'URL signée est portée hors `key`/`attrs` sur la row normalisée (`artifact_url`), consommée uniquement en argument du job post-commit. `reconcileChildren()`/`wasChanged()` ne voit donc que l'identité stable (checksum/filename/size/delivery_mode). Test dédié : ré-ingestion URL≠ / checksum= → `mutated=false`, aucun événement, aucun nouveau job.
- **`pull_status`/`pull_error` hors payload** : jamais dans `attrs` → jamais réécrits par une ré-ingestion (no-op préservé sur un item déjà `downloaded`/`error`). Pilotés uniquement par le flux de pull (post-commit + job).
- **Scope `executable` (AC7)** : persistance seule des colonnes `executable_{checksum,filename,size}`, AUCUN pull (le dispatch est limité à `wallpapers`/`agent_tools`). Résistance délibérée au mimétisme avec `artifact` — recouvre le mécanisme legacy abandonné `applications.installer_*`.
- **Anti-traversal / hash serveur** : filename dérivé serveur (`<checksum>.jpg` pour wallpapers ; `sambaedu-tool-<safekey>-<checksum>.<ext-whitelisté>` pour agent_tools, confinement `realpath`), jamais `artifact.filename` brut. `hash_file('sha256')` + `hash_equals` côté serveur ; mismatch → aucune écriture d'asset, tmp supprimé, `pull_error` sans URL signée (NFR-A3). Wallpaper pullé NON re-normalisé (préserve le checksum vérifié).
- **Précédence locale** : `WallpaperAsset` par checksum, `AgentTool` par clé ; le pull ne comble que l'absence (jamais de remplacement). Re-vérifiée en tête du job (ré-pull no-op). Comportement à 0 binaire amont strictement inchangé.
- **NFR-A4** : `StateCompiler`/`ContractV1`/golden/`FROZEN_STATE_HASH`/`agent/**` intacts ; aucun `StateProvider` ajouté ⇒ payload agent inchangé ⇒ pas de bump `agent/shared/version.go` (invariant golden vérifié après coup).

### File List

**Créés :**
- `app/Enums/ControlHubArtifactPullStatus.php`
- `app/Jobs/ControlHub/PullContractArtifactJob.php`
- `app/Services/ControlHub/ArtifactPullService.php`
- `database/migrations/2026_07_04_130000_add_delivery_and_artifact_to_controlhub_contract_items.php`
- `database/migrations/2026_07_04_130100_add_executable_to_controlhub_contract_catalog_apps.php`
- `tests/Feature/ControlHub/UpstreamArtifactIngestionTest.php`
- `tests/Feature/ControlHub/ArtifactPullServiceTest.php`

**Modifiés :**
- `app/Services/ControlHub/ControlHubContractIngestionService.php` (lecture additive `delivery_mode`/`artifact`/`executable` ; dispatch pull post-commit `dispatchArtifactPulls()`)
- `app/Models/ControlHubContractItem.php` (fillable + casts `pull_status`/`artifact_size`)
- `app/Models/ControlHubContractCatalogApp.php` (fillable + cast `executable_size`)
- `_bmad-output/planning-artifacts/schema-echange-controlhub-se5.md` (artefact R2 : §3.1, §3.4, §5 — additif, pas de bump `schema_version`)
- `docs/qa/domains/controlhub-contract.md` (Section 22 — runbook pull sha256 OK/KO + précédence locale)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (ligne 39-4 → `review`)

## Recommandation Modèle Dev

**opus** — confirmé, en nuançant la répartition. L'epic préconise opus pour « extension de schéma + intégrité + fallback + préservation du contrat agent figé » : c'est justifié pour les parties à risque réel — (a) le piège d'idempotence AC5 (une erreur ici casse silencieusement NFR-A2/A4 pour toute instance recevant des wallpapers/tools amont, régression difficile à détecter sans le test dédié) ; (b) la décision de scope AC7 (résister à la tentation de matérialiser `executable` par symétrie mécanique avec `artifact`, en reconnaissant le précédent legacy abandonné) ; (c) la discipline anti-traversal sur le nommage disque (ne jamais faire confiance à `artifact.filename`). Une exécution sonnet risquerait de reconstruire naïvement `wasChanged()` sur une colonne URL, ou de matérialiser `executable` par pur mimétisme avec `artifact` sans repérer le doublon legacy — deux erreurs coûteuses à corriger après coup. Les tâches 2 (migrations) et 6 (docblock de scope) sont mécaniques ; l'essentiel du risque est concentré dans les Tasks 3-5.
