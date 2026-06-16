# Story 25.6 : Catalogue de tools agent — upload portable Rainmeter + serving skin + toggle parc-settings/agent

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement (et mainteneur en lab)**,
je veux **uploader une fois pour toutes l'archive portable Rainmeter depuis `parc-settings/agent/`, l'activer/désactiver par un toggle, et que la skin d'overlay soit servie comme un asset géré côté serveur (et non figée dans le binaire de l'agent)**,
afin **de gérer le cycle de vie de l'outil de rendu (le portable ET la skin) depuis l'UI, sans dépôt manuel de fichier sur la VM ni recompilation de l'agent à chaque retouche de skin**.

## Contexte & intention

**Sixième story de l'Epic 25** (« Gestion de flotte — distribution canari, bootstrap GPO, porte des postes migrés, console de pilotage »). Elle **complète la livraison Rainmeter de 27.1bis** en faisant du portable **et** de la skin des **assets gérés côté serveur, toggleables depuis l'UI** — là où 27.1bis les avait livrés en bouche-trou : artefact portable déposé **manuellement** sur la VM (`storage/agent/tools/`, hash figé en dur dans `RainmeterToolChecksum`, provisioning **désactivé** tant que ce hash est vide) et skin **embarquée** dans le binaire Go (`go:embed`, recompilation à chaque retouche).

> Numérotation `25-6`. **Ne PAS renuméroter 25.1–25.5.** Cette story est la suite logique du foyer distribution/auto-update (25.1 = canal release/asset authentifié agent, 25.5 = console parc-settings/agent) ET le prolongement direct de 27.1bis (mécanique agent download → pose → verrouillage).

**Deux volets — le cœur de la story :**

### Volet A — Skin servie (retire l'embed)

Aujourd'hui (27.1bis) : la skin canonique `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini` (UTF-8) est **copiée** dans `agent/shared/embedded/SambaEduOverlay.ini` et **embarquée** par `go:embed` (`agent/shared/rainmeter_embed.go`), avec un test de non-divergence (`agent/shared/rainmeter_embed_test.go`). Toute retouche de skin = sync de la copie + **recompilation/re-release de l'agent**.

Cette story bascule la skin en **asset servi par la ROUTE AGENT authentifiée par token** (pas d'alias Apache public — la skin n'est pas client-facing à la manière de SYSVOL/wpkg, elle est consommée par l'agent). La skin canonique reste l'autorité dans `resources/...` ; elle est **provisionnée** (symlink ou copie) sous `storage/assets/overlay/rainmeter/SambaEduOverlay.ini` (convention storage non versionné, `project_storage_convention_non_versioned`) et servie avec **intégrité SHA-256**. L'agent la **télécharge** (vérif SHA-256 **avant** écriture), puis convertit **UTF-16 LE + BOM** et pose l'ACL (logique 27.1bis `ToUTF16LEWithBOM` + `setAssetsACL` **conservée à l'identique**). On **RETIRE l'embed** (`rainmeter_embed.go`, `agent/shared/embedded/`, le test de non-divergence) — **D1 : suppression sèche, le serving est la source unique** (voir Q3 pour le risque hors-ligne et le fallback débattu).

### Volet B — Portable Rainmeter en tool uploadé + toggle

Aujourd'hui (27.1bis) : un **seul** tool, hash figé en dur dans la constante Go `RainmeterToolChecksum` (vide → provisioning **off**), artefact déposé manuellement sur la VM. Pas d'UI, pas de toggle, pas de catalogue.

Cette story introduit un **catalogue de tools** (table dédiée `agent_tools` — **PAS** de table polymorphe générique, **D2**) avec **upload admin** dans une **nouvelle section « Tools »** de `parc-settings/agent/` (25.5 étant en review, c'est une section neuve qui s'ajoute à la page existante) :

- L'admin **upload le ZIP portable Rainmeter** une fois ; le serveur **calcule + stocke le SHA-256 et la taille**, range le fichier sous `storage/agent/tools/` (filename strict matchant la regex `ToolController`), valide l'extension `.zip`, une taille max, et **vérifie que le ZIP contient bien `Rainmeter.exe` + `Skins/` à la racine** (structure portable réelle attendue par l'agent, cf. `agent/shared/rainmeter.go`).
- **PAS de fetch Internet** : le serveur Linux ne peut pas produire le portable (option « Portable » de l'installeur Windows), Rainmeter n'a pas d'URL portable stable. L'upload manuel est le **seul** chemin assumé.
- **Toggle enabled/disabled** : activé → l'état cible du poste inclut le tool → l'agent le télécharge via `ToolController` (existant) et le déploie (mécanique 27.1bis `SyncRainmeterTool`). Désactivé → l'agent ne provisionne pas (no-op, **D4 : laisse en place sans désinstaller**, voir Q1).
- Le **checksum** quitte la constante Go figée : l'agent **lit le SHA-256 attendu depuis l'état cible servi** (le catalogue serveur est désormais l'autorité du hash, pas une constante bakée). Rainmeter = **1er tool** du catalogue ; on **généralise** le `RainmeterToolChecksum`/`RainmeterToolFilename` unique de 27.1bis vers une entrée de catalogue.

**Ce que cette story livre :**
- **Serveur** : migration `agent_tools` (catalogue) ; `AgentToolService` (upload validé + SHA-256 + taille + vérif structure ZIP + toggle, **seul écrivain** de la table) ; section UI « Tools » Livewire SFC sous `parc-settings/agent/_partials/` (upload + toggle, gardée `can:computer.install`) ; exposition de la skin **et** du tool actif dans l'état cible/le canal agent avec leurs SHA-256 ; route de serving de la skin authentifiée agent (étend `ToolController` ou route sœur).
- **Agent Go** : skin **téléchargée** (vérif SHA-256) au lieu d'embarquée → retrait de l'embed ; checksum du portable **lu depuis l'état** au lieu de la constante ; provisioning conditionné au toggle (tool absent/désactivé de l'état → no-op gracieux).
- **Skin** : reste canonique dans `resources/...` (autorité), provisionnée sous `storage/assets/...`.

**Ce que cette story N'EST PAS :**
- Une refonte du **rendu verrouillé** (ACL, watchdog, session-change, écriture overlay.json SYSTEM) — **réutilisés tels quels** de 27.1bis ; seuls la **provenance** de la skin (servie vs embarquée) et la **provenance** du checksum du portable (catalogue vs constante) changent.
- Une refonte du **payload overlay** ni de `ComposeOverlayDocument`/`OverlayDocumentForSession` — intouchés, golden inchangé.
- Une table polymorphe générique « tous types d'assets » (`agent_tools` est une table **dédiée** simple — D2).
- Un fetch Internet du portable (upload manuel assumé).
- Un versioning multi-version/rings du tool (mono-version par tool en MVP — D5, voir Q4).
- L'application du toggle **par parc/WorkstationGroup** (toggle **global** en MVP — D3, voir Q2).

## ⚠️ Pièges & tensions découverts à l'analyse (lire avant de coder)

1. **`ToolController` existe DÉJÀ et garantit le confinement — ne PAS le réécrire.**
   `app/Http/Controllers/Api/V1/Agent/ToolController.php` (route `agent.v1.tools.download`, `routes/api.php:315`) sert déjà `GET /api/v1/agent/tools/{filename}` : pattern filename **strict** `^sambaedu-rainmeter-[0-9A-Za-z.+~-]+\.zip$`, realpath confiné sous `agent.tools_path` (`rtrim` base + `str_starts_with`), 404 indistinct, chaîne middleware `auth.v1.secure-headers` + `throttle:60,1` + `agent.token`. L'**intégrité** y est **côté agent** (le serveur garantit le confinement, pas le contenu). Cette story **réutilise** ce serving pour le portable. **Tension** : le pattern regex est aujourd'hui **spécifique Rainmeter**. Si le catalogue doit servir d'autres tools demain, le pattern devra s'élargir — mais en MVP (Rainmeter seul) on **conserve la regex Rainmeter** et le confinement existant ; le filename d'upload DOIT matcher cette regex (validation à l'upload). [Source: app/Http/Controllers/Api/V1/Agent/ToolController.php:52,59-90]

2. **L'intégrité du portable était CÔTÉ AGENT (constante figée) — cette story la déplace vers l'ÉTAT servi.**
   27.1bis : `RainmeterToolChecksum` (constante Go, `agent/shared/rainmeter.go:48`) **vide** → provisioning **désactivé** (`provisionRainmeterPortable` no-op, `rainmeter_provision.go:63`). Le déposant figeait le hash en dur + recompilait. Avec le catalogue, **le serveur connaît le SHA-256** (calculé à l'upload, colonne `agent_tools.sha256`). L'agent doit **lire ce hash depuis l'état cible servi** (pas la constante). **Décision D6** : l'état cible expose le tool actif `{key, filename, sha256, size, enabled}` ; l'agent vérifie le SHA-256 téléchargé **contre celui de l'état** avant extraction (pattern `SyncWallpaperAssets` conservé). La constante `RainmeterToolChecksum` est **retirée** (ou neutralisée → lue de l'état). **Vérifier** : ne pas casser l'invariant « hash vérifié AVANT extraction » (`rainmeter_provision.go:87-95`). [Source: agent/shared/rainmeter.go:33-82 ; agent/shared/rainmeter_provision.go:38-131]

3. **La skin embarquée a un test de non-divergence — le retirer SANS laisser de référence morte.**
   27.1bis : `agent/shared/rainmeter_embed.go` (`//go:embed embedded/SambaEduOverlay.ini`), `agent/shared/embedded/SambaEduOverlay.ini` (copie), `agent/shared/rainmeter_embed_test.go` (diff copie ↔ canonique repo). `ensureRainmeterConfig` (`rainmeter_provision.go:181-184`) appelle `ToUTF16LEWithBOM(RainmeterSkinSource())` où `RainmeterSkinSource()` (`rainmeter_embed.go:17`) renvoie l'embed. **D1** : la skin est désormais **téléchargée** → `RainmeterSkinSource()` disparaît, `ensureRainmeterConfig` reçoit la skin **téléchargée et vérifiée** (UTF-8) en paramètre/depuis le cache de download. **Grep obligatoire** après suppression : aucune référence résiduelle à `rainmeterSkinSource`, `RainmeterSkinSource`, `//go:embed embedded/`. Supprimer `embedded/SambaEduOverlay.ini` et le test de non-divergence (devient sans objet). [Source: agent/shared/rainmeter_embed.go ; agent/shared/rainmeter_embed_test.go ; agent/shared/rainmeter_provision.go:181-210]

4. **La skin servie par la route agent ≠ alias Apache public.** La skin n'est PAS client-facing comme SYSVOL/wpkg/`/os`/`/ipxe` (exceptions de la convention storage versionné — les postes lisent SYSVOL **sans** l'agent). Ici la skin est consommée **par l'agent authentifié token**. **Décision D7** : servie par la route agent (étendre `ToolController` avec une action `skin()`/`overlaySkin()`, OU une route sœur `agent.v1.assets.overlay-skin` iso `AssetController`), chaîne middleware **identique** (`agent.token`), intégrité SHA-256 connue serveur, 404 indistinct, anti-traversal (filename dérivé/fixe, pas d'input client libre). Convention storage `storage/assets/overlay/rainmeter/` (non versionné, **chown www-admin** sinon `hash_file()` → false → 404 silencieux, `project_php_fpm_user_www_admin`). [Source: app/Http/Controllers/Api/V1/Agent/AssetController.php (modèle) ; mémoire project_storage_convention_non_versioned]

5. **Upload sécurisé = surface d'attaque NOUVELLE — c'est l'enjeu sécurité de la story.** Un upload admin de fichier binaire qui sera **servi à tout le parc** puis **extrait** sur des postes Windows. Garde-fous OBLIGATOIRES : (a) **filename strict** — soit imposé par le serveur (dérivé, ex. `sambaedu-rainmeter-<version>.zip` matchant la regex `ToolController`), soit validé contre la regex AVANT stockage ; **jamais** le nom client brut sans validation (anti-traversal) ; (b) **extension `.zip` + MIME** ; (c) **taille max** (clé config, ex. 200 Mio — un portable Rainmeter pèse ~quelques dizaines de Mio) ; (d) **vérif structure ZIP** : `Rainmeter.exe` ET `Skins/` à la racine (sinon l'agent extrait un ZIP inutile/hostile) ; (e) **SHA-256 calculé serveur** (`hash_file('sha256')`, pattern `ReleaseCreationService:104`) — jamais un hash déclaré par le client ; (f) le fichier stocké sous `storage/agent/tools/` confiné, chown www-admin. **Réutiliser** le confinement realpath de `ToolController` pour le serving aval. [Source: app/Services/Agent/Releases/ReleaseCreationService.php:64-115 ; ToolController.php:59-90]

6. **L'état cible servi à l'agent doit EXPOSER le tool actif + la skin, sans casser le golden.**
   Le canal agent sert `se5.desired-state/v1` (`GET /api/v1/agent/state`). Aujourd'hui le provisioning Rainmeter est **hors** état (constante Go) ; cette story **introduit** la donnée tool/skin dans le flux servi à l'agent. **Tension golden** : si on ajoute le tool dans l'enveloppe d'état desired-state versionnée, on **modifie le contrat** (golden `state`/`contract-v1` à bumper sciemment → décision contrat, croisé PHP↔Go, NFR13). **Décision D8 à challenger** : préférer un **canal/section séparé** (ex. champ `tools`/`overlay_skin` dans l'enveloppe agent OU un endpoint léger dédié `GET /api/v1/agent/tools-manifest`) plutôt que de polluer les `items` desired-state (qui portent une sémantique compliant/drift/hash par ressource — un tool n'est pas un StateItem). **Le dev tranche** : (a) extension d'enveloppe minimale hors `items` (bump golden state sciemment, croisé) OU (b) endpoint manifest dédié iso `release` manifest (`ReleaseController::manifest`, pas de golden item). Reco : **(b) endpoint manifest dédié** (iso 25.1 release-manifest, zéro impact golden items, sépare proprement « tools » de « desired-state des ressources »). [Source: app/Http/Controllers/Api/V1/Agent/ReleaseController.php (manifest) ; config/agent.php:36-40 ttl ; golden state/contract]

7. **Pas de table polymorphe — `agent_tools` est une table dédiée simple (D2).** Colonnes : `id`, `key` (unique, ex. `rainmeter`), `name`, `filename`, `sha256` (char 64), `size` (bigint), `enabled` (bool, default false), `uploaded_at` (timestamp), `uploaded_by` (FK users nullable). **PAS** de `morphs`, pas de table générique « assets ». Rainmeter = 1re et seule ligne en MVP. [Décision Henri — D2]

8. **`AgentToolService` = SEUL écrivain de `agent_tools` (pattern `ReleaseCreationService`).** Le composant Livewire **n'écrit jamais** la table directement (pas d'`updateOrCreate`/`save` sur le modèle depuis la vue) : il appelle `$service->upload(...)` / `$service->toggle(...)`. Le service calcule le hash, valide, range le fichier, émet les logs `agent.tool.*`. [Source: app/Services/Agent/Releases/ReleaseCreationService.php:18-46 ; piège 25.5 #1]

9. **`Gate::authorize('computer.install')` sur CHAQUE méthode mutante du composant Livewire.** Le middleware `can:computer.install` protège la page, mais chaque action Livewire (upload, toggle) est adressable via `/livewire/update` : double protection iso-pattern projet (25.5 #8, arbitrage review 25.3 #4). Réutiliser la permission Spatie de `parc-settings/agent` (`computer.install`) — **pas de refnum, pas de nouvelle permission**. [Source: resources/views/pages/parc-settings/agent/_partials/releases-rings.blade.php:84,93]

10. **`WithToasts` + modale réutilisable.** Retours d'action via `toastSuccess`/`toastError` (`App\Components\Traits\WithToasts`), jamais `session()->flash`/`alert()`. Upload + confirmation de désactivation via `x-molecules.modal` ou `wire:confirm` (un-clic léger). `WithFileUploads` (Livewire) pour le champ d'upload. [Source: app/Components/Traits/WithToasts.php ; CLAUDE.md « modale »/« notifications »]

11. **`inotify` ne propage pas les deletes ; clés config & cache VM.** Suppression de `rainmeter_embed.go`/`embedded/` côté repo : `inotify` ne propage pas les deletes → fichiers fantômes sur la VM (mais le code Go = **hôte uniquement**, pas servi par la VM ; sans objet pour l'agent). Côté serveur : nouvelle clé config éventuelle (ex. `agent.tool_max_upload_size`, `agent.overlay_skin_path`) → `config:cache` /vm + chown www-admin (`project_vm_config_cache_not_synced`). Route(s) neuve(s) (skin serving + éventuel tools-manifest) → `route:cache` /vm (`project_route_cache_vm_ephemeral_test_routes`). Migration `agent_tools` → `php artisan migrate` /vm (action Henri, `project_vm_migrations_not_auto_applied`). [Mémoires citées]

12. **NFR7 (critère Keycloak) : zéro dépendance AD dans le code agent ET dans le serving tool/skin.** Aucun `ldap`/`apcu`/`LdapRecord`/`ad_*`/`samba-tool`/`kerberos` dans le nouveau code Go ni dans `AgentToolService`/`ToolController`/route skin. Grep en validation. [Source: NFR7]

13. **`agent/` jamais mêlé à `app/`.** Code Go sous `agent/shared/` (logique pure : décision provisioning, conversion UTF-16, vérif hash) + `agent/windows/` (download/extraction/ACL). Serveur (catalogue, service, UI, serving) sous `app/`. Anti-couteau-suisse NFR9 : le provisioning d'un outil reste « pose d'un asset vérifié + maintien ». [Source: NFR7/NFR9]

14. **`storage/agent/tools/` est déjà la convention de 27.1bis — ne pas créer un autre chemin.** `config/agent.php:69` `tools_path` = `storage_path('agent/tools')`. Le fichier uploadé y va. La skin va sous `storage/assets/overlay/rainmeter/` (nouveau, ou réutiliser `storage/agent/tools/` si le dev juge cohérent — trancher en faveur d'un chemin **dédié skin** pour ne pas mélanger archive et skin). [Source: config/agent.php:62-69]

## Décisions de design — TRANCHÉES (cadrage validé avec Henri 2026-06-16)

> Le périmètre (2 volets) et les décisions ci-dessous sont tranchés. Les forks restants sont en Questions ouvertes (Q1..Q4).

1. **D1 — Embed skin SUPPRIMÉ sec ; le serving est la source unique.** Retrait de `rainmeter_embed.go`, `agent/shared/embedded/`, `rainmeter_embed_test.go`. La skin est **téléchargée** (vérif SHA-256) puis convertie UTF-16 LE + BOM à la pose (`ToUTF16LEWithBOM` conservé). Risque hors-ligne assumé (voir Q3) : un poste qui n'a jamais téléchargé la skin n'a pas d'overlay tant que le serveur n'est pas joignable — acceptable (zéro prod, démo ; l'overlay absent reste gracieux, invariant 24.6).
2. **D2 — Catalogue = table dédiée `agent_tools`** (`id, key, name, filename, sha256, size, enabled, uploaded_at, uploaded_by`). PAS de table polymorphe générique. Rainmeter = 1re ligne ; généralise le `RainmeterToolChecksum`/`RainmeterToolFilename` unique de 27.1bis.
3. **D3 — Toggle GLOBAL en MVP** (tout poste avec l'overlay activé déploie Rainmeter si le tool est `enabled`). Capacité de ciblage par parc/WG = follow-up (Q2).
4. **D4 — Désactivation = no-op côté agent, PAS de désinstallation.** Tool `disabled` → l'agent ne (re)provisionne pas, mais ne **supprime pas** Rainmeter déjà posé (laisse en place). La désinstallation propre est un follow-up (Q1).
5. **D5 — Mono-version par tool en MVP.** Un seul portable Rainmeter actif servi à tout le parc. Pas de rings/versioning Epic 25 appliqué au tool (Q4). Un nouvel upload **remplace** la version active du tool (même `key`, hash/taille/filename mis à jour).
6. **D6 — L'intégrité du portable vient de l'ÉTAT servi, plus de la constante Go.** Le serveur connaît le SHA-256 (calculé à l'upload) ; l'agent le lit depuis le manifest/état et vérifie le téléchargement avant extraction. `RainmeterToolChecksum` retiré/neutralisé.
7. **D7 — Skin servie par la ROUTE AGENT authentifiée token** (étendre `ToolController` ou route sœur iso `AssetController`), intégrité SHA-256, PAS d'alias Apache public.
8. **D8 — Tool/skin exposés par un ENDPOINT MANIFEST dédié** (iso `release` manifest 25.1), pas dans les `items` desired-state (préserve la sémantique StateItem + le golden state). *Le dev confirme manifest dédié vs extension d'enveloppe ; reco = manifest dédié.*

## Acceptance Criteria

### AC1 — Catalogue de tools : table dédiée `agent_tools` + 1re entrée Rainmeter (D2)

**Given** une migration créant `agent_tools` (`id`, `key` unique, `name`, `filename`, `sha256` char 64, `size` bigint, `enabled` bool default false, `uploaded_at` timestamp, `uploaded_by` FK users nullable)
**When** la migration est appliquée
**Then** la table existe, **dédiée** (PAS de `morphs`/table polymorphe) ; un modèle `AgentTool` la mappe ; Rainmeter en est la **1re et seule** entrée envisagée en MVP
**And** aucune écriture de la table hors `AgentToolService` (seul écrivain — pattern `ReleaseCreationService`).

### AC2 — Upload admin sécurisé du ZIP portable Rainmeter (D2, D5)

**Given** un admin (`can:computer.install`) sur la section « Tools » de `parc-settings/agent/`
**When** il uploade une archive
**Then** le serveur **valide** : extension `.zip` + MIME, taille ≤ borne config, filename matchant la regex `ToolController` (imposé/dérivé serveur — jamais le nom client brut), **structure ZIP** (`Rainmeter.exe` + `Skins/` à la racine, structure portable réelle) ; **calcule** le SHA-256 (`hash_file('sha256')` — jamais un hash client) et la taille ; **range** le fichier sous `storage/agent/tools/` (confiné, chown www-admin) ; **persiste** `{key:'rainmeter', name, filename, sha256, size, uploaded_at, uploaded_by}` (mono-version : un nouvel upload remplace la version active du même `key`, D5)
**And** tout échec de validation → `toastError` lisible (jamais de 500), aucun fichier orphelin laissé ; **aucun fetch Internet** (upload manuel = seul chemin).

### AC3 — Toggle enabled/disabled (D3, D4)

**Given** un tool présent dans le catalogue
**When** l'admin **active** le toggle
**Then** le tool devient `enabled=true` (global, D3) → exposé comme actif dans le manifest tool servi à l'agent → l'agent télécharge via `ToolController` et déploie (mécanique 27.1bis)
**When** l'admin **désactive** le toggle
**Then** le tool devient `enabled=false` → absent/marqué inactif du manifest → l'agent **ne (re)provisionne pas** (no-op), **sans désinstaller** Rainmeter déjà posé (D4)
**And** chaque méthode mutante du composant est gardée `Gate::authorize('computer.install')` (double protection `/livewire/update`) ; retours via `WithToasts`.

### AC4 — Skin servie par la route agent authentifiée, intégrité SHA-256 (Volet A, D1, D7)

**Given** la skin canonique `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini` (UTF-8, autorité) provisionnée sous `storage/assets/overlay/rainmeter/SambaEduOverlay.ini` (chown www-admin)
**When** l'agent demande la skin
**Then** elle est servie par la **route agent authentifiée token** (étend `ToolController` ou route sœur iso `AssetController` — chaîne `agent.token`, 404 indistinct, anti-traversal), avec un SHA-256 connu serveur exposé dans le manifest
**And** **PAS** d'alias Apache public ; le serving réutilise le confinement realpath existant ; clé config skin (chemin) → `config:cache` /vm, route neuve → `route:cache` /vm.

### AC5 — Agent : skin téléchargée (embed retiré) + checksum portable depuis l'état (D1, D6)

**Given** l'agent Go
**When** il provisionne l'overlay
**Then** il **télécharge la skin** (vérif SHA-256 **avant** écriture, depuis le manifest), la convertit **UTF-16 LE + BOM** (`ToUTF16LEWithBOM` **réutilisé**) et la pose (ACL **réutilisée**) ; l'**embed est RETIRÉ** (`rainmeter_embed.go`, `embedded/`, test de non-divergence supprimés ; **zéro** référence résiduelle à `RainmeterSkinSource`/`//go:embed embedded/`)
**And** le **checksum du portable** est **lu depuis le manifest servi** (D6), plus depuis la constante `RainmeterToolChecksum` (retirée/neutralisée) ; vérif SHA-256 **avant** extraction conservée (`SyncRainmeterTool`/`provisionRainmeterPortable` adaptés)
**And** **tool absent/désactivé du manifest → no-op gracieux** (Rainmeter absent reste gracieux, invariant 24.4/24.6 ; jamais d'`error` du seul fait de l'absence) ; le **rendu verrouillé** (ACL, watchdog, session-change, overlay.json SYSTEM) est **INTOUCHÉ**.

### AC6 — UI : section « Tools » neuve sous parc-settings/agent (25.5 en review)

**Given** la page `parc-settings/agent/` (Livewire SFC, `can:computer.install`)
**When** l'admin l'ouvre
**Then** une **nouvelle section « Tools »** (partial SFC `_partials/tools-catalog.blade.php`, intégré à `index.blade.php`) affiche le tool Rainmeter (nom, version/filename, hash court, taille, état, date d'upload), le champ d'**upload** (`WithFileUploads`) et le **toggle**
**And** convention `pages/` filesystem-router respectée ; modale réutilisable / `wire:confirm` pour les confirmations ; aucune logique d'écriture catalogue dans la vue (délègue à `AgentToolService`).

### AC7 — Tests : go test agent + serveur, golden overlay intact (NFR13)

**Then** côté **agent Go** : tests `go test` (skin téléchargée vérif-hash idempotente ; checksum portable lu de l'état → vérif avant extraction ; tool désactivé/absent → no-op gracieux ; conversion UTF-16 LE + BOM **inchangée** ; embed retiré → compile sans référence morte) ; `go test ./...`, `go vet` (linux + `GOOS=windows`), cross-compile verts sur l'hôte ; le spécifique Windows (download/ACL) validé cross-compile + lab humain
**And** côté **Laravel** : `AgentToolService` (upload valide/rejets : mauvaise extension, trop gros, structure ZIP KO, filename non conforme ; hash calculé serveur ; toggle) ; serving skin (intégrité hash, 404 indistinct, authentifié agent, anti-traversal — iso `ToolEndpointTest`) ; manifest tool ; composant Livewire (Gate sur mutations, toasts) ; non-régression `--filter Agent` /vm (baseline relevée en T0)
**And** **golden overlay `agent/shared/testdata/overlay.golden.json` INCHANGÉ** (la composition overlay n'est pas touchée) ; si l'option D8(a) extension d'enveloppe est retenue, **bump golden state/contract SCIEMMENT** (croisé PHP↔Go, NFR13) ; si D8(b) manifest dédié (reco), **aucun golden item** impacté.

### AC8 — Documentation + QA (append-only)

**Then** `resources/overlay/README.md` : section « Catalogue de tools — skin servie + portable uploadé (25.6) » (skin servie remplace l'embed, upload portable, toggle) ; `agent/README.md` : skin téléchargée (plus embarquée) + checksum depuis l'état ; `docs/agent/release-distribution.md` ou doc dédiée : catalogue de tools, endpoint manifest tool/skin, serving authentifié
**And** `docs/qa/domains/agent.md` enrichi **append-only** (nouvelle section, sans renuméroter) : upload tool (valide/rejets), toggle (activé → déploie / désactivé → no-op sans désinstaller), skin servie sans mojibake, intégrité SHA-256 ; ligne 25.6 dans `docs/qa/README.md`
**And** restent **INTOUCHÉS** : `ComposeOverlayDocument`/`OverlayDocumentForSession` (composition overlay), le rendu verrouillé (ACL/watchdog/session-change/overlay.json SYSTEM de 27.1bis), tout le canal legacy.

## Tasks / Subtasks

- [x] **T0 — Baseline & repérage** (AC7)
  - [x] Relever la baseline `--filter Agent` sur /vm (nb passants/échecs préexistants) AVANT toute modif (27.1bis/27.7 concurrentes en review : noter les échecs préexistants `ToolEndpointTest`/`ldap_search` host).
  - [x] `go test ./...` + `go vet` (linux + `GOOS=windows`) + cross-compile verts sur l'hôte (état initial).
  - [x] Confirmer golden `agent/shared/testdata/overlay.golden.json` vert (référence à ne PAS casser).
  - [x] Repérer la dépendance 27.1bis (`ToolController`, `rainmeter*.go`, embed) : si non encore mergée, **rebase/coordination** avec l'orchestrateur (prérequis fort).

- [x] **T1 — Serveur : migration + modèle catalogue `agent_tools`** (AC1) — *D2*
  - [x] Migration `agent_tools` (`key` unique, `filename`, `sha256` char 64, `size` bigint, `enabled` bool default false, `uploaded_at`, `uploaded_by` FK users nullable). PAS de `morphs`.
  - [x] Modèle `App\Models\AgentTool` (colonnes hors `$fillable` sensibles si pertinent ; casts).
  - [x] `php artisan migrate` = action /vm (Henri, `project_vm_migrations_not_auto_applied`).

- [x] **T2 — Serveur : `AgentToolService` (upload validé + SHA-256 + structure ZIP + toggle)** (AC2, AC3) — *D2, D5, piège #5/#8*
  - [x] `App\Services\Agent\Tools\AgentToolService` = **seul écrivain** de `agent_tools` (pattern `ReleaseCreationService`).
  - [x] `upload(UploadedFile $file, ?int $uploadedBy): AgentTool` : valide extension/MIME `.zip`, taille ≤ borne config, filename dérivé/conforme regex `ToolController`, **structure ZIP** (`Rainmeter.exe` + `Skins/` racine, via `ZipArchive`), calcule SHA-256 (`hash_file`) + taille, range sous `storage/agent/tools/` (confiné, chown — action ops si besoin), upsert mono-version (`key='rainmeter'`). Exceptions → message lisible, aucun orphelin.
  - [x] `toggle(AgentTool $tool, bool $enabled): void` + logs `agent.tool.uploaded`/`agent.tool.toggled`.
  - [x] Clé config `agent.tool_max_upload_size` (+ éventuelle `agent.overlay_skin_path`) → `config:cache` /vm.

- [x] **T3 — Serveur : manifest tool/skin + serving skin authentifié agent** (AC4) — *D6, D7, D8*
  - [x] Endpoint manifest tool/skin dédié (reco D8(b), iso `ReleaseController::manifest`) : expose le tool actif `{key, filename, sha256, size, enabled}` + la skin `{filename, sha256}`. Si D8(a) retenu (extension enveloppe) : bump golden state/contract sciemment, croisé.
  - [x] Serving skin : étendre `ToolController` (action `skin()`) OU route sœur `agent.v1.assets.overlay-skin` (iso `AssetController`) — chaîne `agent.token`, intégrité SHA-256, 404 indistinct, anti-traversal, confinement realpath réutilisé.
  - [x] Provisionner la skin canonique sous `storage/assets/overlay/rainmeter/SambaEduOverlay.ini` (copie/symlink, chown www-admin — action /vm). Routes neuves insérées APRÈS le groupe 16.12 (`project_api_routes_arch_test_window_trap`) → `route:cache` /vm.

- [x] **T4 — UI : section « Tools » Livewire SFC sous parc-settings/agent** (AC6) — *piège #9/#10*
  - [x] `resources/views/pages/parc-settings/agent/_partials/tools-catalog.blade.php` (SFC) : affichage tool (nom/version/hash court/taille/état/date), upload (`WithFileUploads`), toggle. Intégré à `index.blade.php` (nouvelle `<section>`).
  - [x] `Gate::authorize('computer.install')` sur CHAQUE mutation (upload, toggle) ; `WithToasts` pour tous les retours ; modale réutilisable / `wire:confirm` pour confirmations ; aucune écriture catalogue directe (délègue à `AgentToolService`).

- [x] **T5 — Agent Go : skin téléchargée (retrait embed) + checksum depuis l'état** (AC5) — *D1, D6, pièges #2/#3*
  - [x] Retirer `agent/shared/rainmeter_embed.go`, `agent/shared/embedded/SambaEduOverlay.ini`, `agent/shared/rainmeter_embed_test.go`. Grep : zéro référence `RainmeterSkinSource`/`rainmeterSkinSource`/`//go:embed embedded/`.
  - [x] `ensureRainmeterConfig` reçoit la skin **téléchargée + vérifiée** (SHA-256 du manifest) au lieu de l'embed ; conversion UTF-16 LE + BOM **inchangée**. Fonction de download skin (vérif hash avant écriture, pattern `SyncWallpaperAssets`/`SyncShortcutIcons`).
  - [x] Checksum du portable **lu du manifest** (D6) ; retirer/neutraliser `RainmeterToolChecksum` ; vérif SHA-256 **avant** extraction conservée ; `SyncRainmeterTool`/`provisionRainmeterPortable` adaptés.
  - [x] Tool/skin absents ou tool `disabled` → **no-op gracieux** (jamais d'`error` du seul fait de l'absence). Rendu verrouillé (ACL/watchdog/session-change/overlay.json SYSTEM) **INTOUCHÉ**.

- [x] **T6 — Tests** (AC7)
  - [x] Go : skin download vérif-hash (idempotence, hash KO → pas d'écriture), checksum portable depuis l'état → vérif avant extraction, tool désactivé/absent → no-op, UTF-16 LE+BOM inchangé, compile sans embed. `go test ./...`, `go vet` (linux+windows), cross-compile.
  - [x] Laravel : `AgentToolService` (upload valide + 4 rejets : extension, taille, structure ZIP, filename ; hash serveur ; toggle), serving skin (hash, 404 indistinct, authentifié, anti-traversal), manifest tool, composant Livewire (Gate mutations, toasts). Non-régression `--filter Agent` /vm.
  - [x] Golden overlay INCHANGÉ (vérif explicite) ; golden state/contract bumpé+croisé SEULEMENT si D8(a).

- [x] **T7 — Documentation + QA** (AC8)
  - [x] `resources/overlay/README.md`, `agent/README.md`, `docs/agent/release-distribution.md` (catalogue tools, manifest, serving skin) ; QA append-only `docs/qa/domains/agent.md` + ligne 25.6 `docs/qa/README.md`.

- [x] **T8 — Validation finale** (AC7)
  - [x] `php -l` sur les PHP ; grep critère Keycloak (`ldap|apcu|LdapRecord|ad_|samba-tool|kerberos`) sur le nouveau code agent + `AgentToolService`/serving → vide (NFR7) ; grep « zéro retrofit legacy » (aucun fichier legacy au diff).
  - [x] `go test ./...` + `go vet` (linux+windows) + cross-compile verts sur l'hôte ; golden overlay vert ; `--filter Agent` /vm sans régression (vs baseline T0).
  - [x] **Validation lab (poste Windows) : ACTION HUMAINE (Henri)** — upload du portable réel dans l'UI, toggle ON → Rainmeter posé/overlay rendu au logon (skin servie, accents corrects UTF-16, pas de `Â·`) ; toggle OFF → plus de (re)provisioning, Rainmeter laissé en place (D4) ; retouche skin canonique → re-provisionnée SANS recompiler l'agent.

## Dev Notes

### Périmètre — livré / hors-scope

| Livré (25.6) | Hors-scope (story) |
|---|---|
| Catalogue `agent_tools` (table dédiée) + modèle | Table polymorphe générique « tous assets » (D2) |
| Upload admin sécurisé du ZIP portable (SHA-256 serveur, structure ZIP, taille, filename strict) | Fetch Internet du portable (upload manuel assumé) |
| Toggle enabled/disabled GLOBAL | Toggle par parc/WorkstationGroup (Q2) |
| Skin **servie** par la route agent (retrait de l'embed) | Refonte du rendu verrouillé / overlay.json SYSTEM (réutilisés 27.1bis) |
| Checksum portable **depuis l'état/manifest** (plus la constante Go) | Refonte payload overlay / `ComposeOverlayDocument` (intouchés, golden inchangé) |
| Manifest tool/skin (endpoint dédié reco) | Versioning/rings du tool (mono-version MVP, Q4) |
| Section UI « Tools » parc-settings/agent | Désinstallation de Rainmeter à la désactivation (Q1) |
| Tests go + serveur + QA | Décommissionnement legacy (27.6) |

### Le socle 27.1bis — ce qu'on RÉUTILISE (ne PAS réinventer)

[Source: app/Http/Controllers/Api/V1/Agent/ToolController.php ; agent/shared/rainmeter.go ; agent/shared/rainmeter_provision.go ; config/agent.php]

- `ToolController` (`agent.v1.tools.download`, `routes/api.php:315`) : serving portable confiné, filename strict, 404 indistinct, chaîne `agent.token`. **Réutilisé tel quel** pour le download du portable ; **étendu** (action `skin()`) ou doublé d'une route sœur pour la skin.
- `config/agent.php:69` `tools_path` = `storage_path('agent/tools')` : où va le ZIP uploadé. Ajouter `tool_max_upload_size` (+ chemin skin).
- `agent/shared/rainmeter.go` : `ToUTF16LEWithBOM` (conversion **inchangée**), `BuildHardenedRainmeterIni`, `ExtractPortableZip` (anti zip-slip + bornes), `RainmeterStore`. `RainmeterToolFilename` reste le nom servi ; `RainmeterToolChecksum` **retiré** (D6, lu de l'état).
- `agent/shared/rainmeter_provision.go` : `SyncRainmeterTool` / `provisionRainmeterPortable` (vérif hash AVANT extraction, install-if-absent, atomique, marqueur) / `ensureRainmeterConfig` (skin + ini durci + ACL). **Adaptés** : checksum de l'état, skin téléchargée au lieu d'embed.
- `agent/shared/rainmeter_embed.go` + `embedded/` + `rainmeter_embed_test.go` : **SUPPRIMÉS** (D1).
- Rendu verrouillé 27.1bis (`overlay_logon*.go`, `watchdog.go`, `acl_windows.go::setAssetsACL`/`setOverlayFileACL`, service session-change) : **INTOUCHÉ**.
- Pattern download vérifié SHA-256 idempotent : `agent/shared/assets.go::SyncWallpaperAssets` (modèle skin/portable) ; `icon_assets.go::SyncShortcutIcons` (27.7, modèle content-addressé/gracieux récent).

### Le canal release 25.1 — modèle réutilisé

[Source: app/Http/Controllers/Api/V1/Agent/ReleaseController.php ; app/Services/Agent/Releases/ReleaseCreationService.php ; app/Http/Controllers/Api/V1/Agent/AssetController.php]

- `ReleaseCreationService` : **modèle d'écrivain unique** (hash de fichier `hash_file('sha256')`, `hash_equals`, exceptions typées) → calque pour `AgentToolService`.
- `ReleaseController::manifest` : **modèle du manifest dédié** (D8(b)) — sert un manifest JSON sans toucher aux golden items desired-state.
- `AssetController` : **modèle du serving asset authentifié agent** (route skin D7).
- **Différence clé** : les releases sont créées par **CLI `php artisan agent:release:create`** + dépôt VM ; le tool, lui, est **uploadé par l'UI** (volet B nouveau). Pas de réutilisation du flux release write — c'est `AgentToolService::upload` qui ingère.

### Le foyer UI parc-settings/agent (25.5)

[Source: resources/views/pages/parc-settings/agent/index.blade.php ; _partials/releases-rings.blade.php ; _partials/enrollment-requests.blade.php]

- Page `parc-settings/agent/index.blade.php` (SFC, route `parc-settings.agent`, `can:computer.install`) compose des partials Livewire sous `_partials/`. 25.6 **ajoute** `_partials/tools-catalog.blade.php` (nouvelle `<section>`). 25.5 en review : coordination (la page sera enrichie en parallèle — éviter les collisions d'édition sur `index.blade.php`).
- Pattern mutation : `Gate::authorize('computer.install')` sur chaque action (cf. `releases-rings.blade.php:84,93`) ; `WithToasts` ; modale `x-molecules.modal` / `wire:confirm`.
- Permission Spatie **réutilisée** : `computer.install` (pas de refnum, pas de nouvelle permission).

### Environnement de dev — règles VM

- Code à la RACINE (app/, agent/, …) ; édité sur l'hôte, sync inotify auto, **jamais de sync manuelle**. `inotify` ne propage pas les deletes (sans objet agent Go = hôte only ; côté serveur aucun delete de fichier servi attendu).
- **Go = hôte uniquement** (`~/go-toolchain/go/bin/go`, `package main` = `agent/windows`). PHPUnit sur /vm.
- ZIP portable uploadé → `storage/agent/tools/` (chown www-admin sinon `hash_file()` → false → 404 ; `project_php_fpm_user_www_admin`). Skin → `storage/assets/overlay/rainmeter/` (chown www-admin). Convention storage non versionnée (`project_storage_convention_non_versioned`).
- `config:cache` /vm après ajout de clés config ; `route:cache` /vm si route réelle ajoutée ; `php artisan migrate` /vm pour `agent_tools` (`project_vm_migrations_not_auto_applied`, `project_vm_config_cache_not_synced`, `project_route_cache_vm_ephemeral_test_routes`).
- Jamais d'interaction VM depuis un worktree git.

### Dépendances

| Story | Rôle pour 25.6 | Statut (sprint-status.yaml) | Bloquant ? |
|-------|----------------|------------------------------|------------|
| 27.1bis — rendu overlay verrouillé Rainmeter | **Prérequis FORT** : fournit `ToolController`, `rainmeter*.go`, l'embed (à retirer), le rendu verrouillé (réutilisé). 25.6 modifie son embed→served + checksum→état | `review` | **Prérequis fort** — dev autorisé avec **rebase si correctifs** ; coordination orchestrateur |
| 25.1 — releases serveur, manifest, rings | Modèle `ReleaseCreationService` (écrivain unique, `hash_file`), `ReleaseController::manifest` (manifest dédié), `AssetController` (serving authentifié) | `done` | Non (pattern réutilisé) |
| 25.5 — UI parc-settings/agent | Page hôte de la nouvelle section « Tools » ; pattern Gate/WithToasts/modale | `review` | Non bloquant — coordination édition `index.blade.php` |
| 24.6 / 24.4 — agent Go compagnon + handlers overlay | `ComposeOverlayDocument`, écriture overlay.json (intouchés) ; invariant Rainmeter absent = gracieux | `review` | Non (consommé/réutilisé) |
| 27.7 — icônes raccourcis asset statique | Modèle RÉCENT de download asset content-addressé gracieux côté agent (`SyncShortcutIcons`) | `review` | Non (pattern de référence) |

25.6 se positionne **après 25.5** dans l'Epic 25 (l'Epic 25 « complet » de 25.5 est rouvert par cette story de prolongement Rainmeter). `epic-25` est `in-progress`.

### Project Structure Notes

- Serveur : migration `agent_tools` → `database/migrations/` ; modèle `app/Models/AgentTool.php` ; service `app/Services/Agent/Tools/AgentToolService.php` ; serving skin → étendre `app/Http/Controllers/Api/V1/Agent/ToolController.php` (ou route sœur `AssetController`-like) ; manifest tool → `ReleaseController`-like ou endpoint dédié ; routes APRÈS le groupe 16.12 ; clés config → `config/agent.php`.
- UI : `resources/views/pages/parc-settings/agent/_partials/tools-catalog.blade.php` (SFC) + intégration `index.blade.php`.
- Agent (skin téléchargée + checksum état) : `agent/shared/` (download skin vérif-hash, décision provisioning, conversion UTF-16 **inchangée**) + `agent/windows/` (download/extraction/ACL **réutilisés**). Suppression : `agent/shared/rainmeter_embed.go`, `agent/shared/embedded/`, `agent/shared/rainmeter_embed_test.go`.
- Skin canonique : `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini` (autorité, UTF-8) → `storage/assets/overlay/rainmeter/` (servie).
- Doc : `resources/overlay/README.md`, `agent/README.md`, `docs/agent/release-distribution.md`, `docs/qa/domains/agent.md`, `docs/qa/README.md`.

### Section contrat / golden

**Golden overlay INCHANGÉ attendu.** La composition overlay (`ComposeOverlayDocument`/`OverlayDocumentForSession`) n'est pas touchée → `agent/shared/testdata/overlay.golden.json` **reste vert** (vérif T0/T8). Le manifest tool/skin :
- **D8(b) manifest dédié (reco)** : iso `release-manifest` 25.1 — **pas** un golden item desired-state, golden state/contract **intacts**.
- **D8(a) extension d'enveloppe** : modifie le contrat desired-state servi → **bump golden state/contract SCIEMMENT**, croisé PHP↔Go (NFR13), comme le bump de 27.7. À éviter si le manifest dédié suffit.

Le seul « contrat » nouveau est le **manifest tool/skin** (golden serveur optionnel iso `release-manifest.v1.json` si le dev juge utile ; non requis par l'AC).

### References

- [Source: _bmad-output/implementation-artifacts/27-1bis-rendu-overlay-verrouille-rainmeter.md] — story prolongée (ToolController D8, provisioning portable, embed skin, rendu verrouillé réutilisé).
- [Source: _bmad-output/implementation-artifacts/25-1-releases-serveur-binaires-signes-manifest-rings.md] — canal release/asset authentifié agent, `ReleaseCreationService` (écrivain unique, hash_file), `ReleaseController::manifest`, `AssetController`.
- [Source: _bmad-output/implementation-artifacts/25-5-ui-parc-settings-agent-rings-enrolements-releases.md] — UI parc-settings/agent (page hôte, Gate/WithToasts/modale, `can:computer.install`).
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Epic 25] — distribution canari, canal release SE5, manifest, rings.
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#NFR5, NFR7, NFR9, NFR13] — frontière de confiance (ACL SYSTEM), zéro AD agent, anti-couteau-suisse, golden croisé.
- [Source: app/Http/Controllers/Api/V1/Agent/ToolController.php] — serving portable confiné, filename strict, 404 indistinct, `agent.token` (réutilisé + étendu).
- [Source: config/agent.php:60-69] — `releases_path` (binaire agent, 25.1) vs `tools_path` (outils de rendu, 27.1bis) — séparation à respecter.
- [Source: agent/shared/rainmeter.go] — `ToUTF16LEWithBOM` (inchangé), `ExtractPortableZip`, `RainmeterToolFilename`, `RainmeterToolChecksum` (à retirer, D6).
- [Source: agent/shared/rainmeter_provision.go] — `SyncRainmeterTool`/`provisionRainmeterPortable`/`ensureRainmeterConfig` (adaptés : checksum état, skin téléchargée).
- [Source: agent/shared/rainmeter_embed.go ; agent/shared/embedded/SambaEduOverlay.ini ; agent/shared/rainmeter_embed_test.go] — embed skin (SUPPRIMÉS, D1).
- [Source: agent/shared/assets.go::SyncWallpaperAssets ; agent/shared/icon_assets.go::SyncShortcutIcons (27.7)] — patterns download vérifié SHA-256 idempotent/gracieux.
- [Source: app/Services/Agent/Releases/ReleaseCreationService.php:64-115] — écrivain unique, `hash_file('sha256')`, `hash_equals`, exceptions typées (calque `AgentToolService`).
- [Source: resources/views/pages/parc-settings/agent/index.blade.php ; _partials/releases-rings.blade.php] — composition de partials SFC, Gate `computer.install`, WithToasts, modale.
- [Mémoire: project_storage_convention_non_versioned ; project_php_fpm_user_www_admin ; project_vm_config_cache_not_synced ; project_route_cache_vm_ephemeral_test_routes ; project_api_routes_arch_test_window_trap ; project_vm_migrations_not_auto_applied ; project_zero_prod_publish_is_test].

## Questions ouvertes — pour décision Henri

> Ces forks ne sont PAS tranchés par le cadrage. À arbitrer avant ou pendant le dev.

- **Q1 — À la désactivation du toggle, l'agent DÉSINSTALLE-t-il Rainmeter du poste ou le laisse-t-il en place ?**
  Défaut proposé (D4) : **laisse en place (no-op)** — désactiver = ne plus (re)provisionner, sans toucher l'existant. La désinstallation propre (retrait du portable + arrêt du watchdog + retrait de l'overlay rendu) est un follow-up. Reco : **no-op MVP** (simplicité, réversibilité ; un poste retiré du parc se ré-image de toute façon). À confirmer.

- **Q2 — Le toggle est-il GLOBAL ou ciblé par parc/WorkstationGroup ?**
  Défaut proposé (D3) : **global** (tout poste avec l'overlay activé déploie Rainmeter si le tool est `enabled`). Le ciblage par maille (cohérent avec le ciblage overlay par parc) est une capacité optionnelle. Reco MVP : **global** ; ciblage par WG = follow-up aligné sur le ciblage overlay. À confirmer.

- **Q3 — Embed skin SUPPRIMÉ sec (D1) ou conservé en fallback hors-ligne ?**
  Défaut proposé (D1) : **supprimé** — le serving est la source unique, l'agent télécharge la skin (gracieux si serveur injoignable : pas d'overlay tant que pas téléchargé, acceptable en démo). Le fallback embarqué éviterait un poste sans overlay au tout premier bootstrap hors-ligne, au prix de re-figer la skin dans le binaire (le défaut qu'on corrige). Reco : **supprimé** (cohérence ; zéro prod = publier/servir est le test). À confirmer.

- **Q4 — Versioning/rings du tool (D5) ou mono-version par tool en MVP ?**
  Défaut proposé (D5) : **mono-version** (un seul portable Rainmeter actif servi à tout le parc ; un nouvel upload remplace). Le modèle rings d'Epic 25 (version de tool par WorkstationGroup) est sur-dimensionné pour Rainmeter (ne bouge quasi jamais). Reco : **mono-version**. À confirmer.

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : story **à fort risque transversal**, non mécanique, qui combine quatre fronts sensibles simultanés. (a) **Upload sécurisé** d'un binaire qui sera servi à tout un parc puis extrait sur des postes Windows — surface d'attaque neuve exigeant filename strict, validation extension/MIME/taille, **inspection de structure ZIP**, SHA-256 calculé serveur, confinement realpath ; une faille ici contamine la flotte. (b) **Catalogue + service écrivain unique** avec invariants (mono-version, toggle, hash autorité serveur) à tenir sans réinventer `ReleaseCreationService`/`ToolController`. (c) **Serving authentifié** d'un nouvel asset (skin) sur le canal agent, intégrité SHA-256, 404 indistinct, sans alias public — et le choix manifest dédié vs extension d'enveloppe qui **engage le golden contract** (croisé PHP↔Go, NFR13). (d) **Refonte embed→servi côté Go** : retirer `go:embed` + son test de non-divergence et basculer la skin **et** le checksum du portable vers la donnée téléchargée/servie, **sans casser** la conversion UTF-16 LE+BOM, le rendu verrouillé, ni le golden overlay — un changement de **provenance de donnée** structurant à mener à travers la frontière agent/serveur (NFR7). Le risque majeur — sécurité d'upload réelle + cohérence catalogue/manifest/golden + refonte de provenance sans régression du rendu — exige le raisonnement le plus rigoureux. `opus`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (DEV).

### Debug Log References

- Go : `go test ./...` (sambaedu/agent/shared) VERT ; `go vet ./...` + `GOOS=windows go vet ./...` + `GOOS=windows go build ./...` VERTS ; nouveau `rainmeter_provision_test.go` 12 cas VERTS (`-run 'Rainmeter|ParseRainmeter'`).
- Golden : `agent/shared/testdata/overlay.golden.json` INCHANGÉ (`git status` clean) ; `ContractV1Test` 5/78 assertions VERT (cross-check golden state).
- PHP : `ToolManifestSkinEndpointTest` 9/9 ; `ToolEndpointTest` (27.1bis non-régression) + `ToolManifestSkinEndpointTest` ensemble 17/43 VERTS.
- **Limite hôte** : `ext-zip` ABSENT (PHP herd-lite) → `AgentToolServiceTest` (12 cas) et les tests Livewire d'upload (`upload_persists…`, `invalid_structure_zip…`, `upload_is_gated…`) NE PEUVENT PAS s'exécuter sur l'hôte (`Class "ZipArchive" not found`) — à rejouer /vm. Les tests Livewire zip-free passent (`toggle_flips…`, `toggle_is_gated…`).
- Faux ami initial corrigé : `OverlaySkinProvisioner::resolveServedPath()` RÉALIGNE la cible servie sur la canonique versionnée → les tests endpoint asservissent le SHA-256 servi à celui de la canonique (comportement produit, pas un bug).

### Completion Notes List

Le socle SERVEUR + AGENT GO était DÉJÀ codé par une étape précédente (migration, modèle `AgentTool`, `AgentToolService`/`AgentToolManifestService`/`AgentToolException`/`OverlaySkinProvisioner`, `ToolController::manifest()/skin()`, routes, `config/agent.php`, `.gitignore`, refonte `rainmeter_provision.go`/`rainmeter_manifest.go`/`rainmeter.go`, suppression de l'embed). Cette session a COMPLÉTÉ et FINALISÉ :

- **T4 (UI)** : créé `_partials/tools-catalog.blade.php` (Livewire SFC — affichage tool nom/filename/hash court/taille/état/date, upload `WithFileUploads`, toggle `wire:confirm`, `Gate::authorize('computer.install')` sur upload+toggle, `WithToasts`, délègue tout à `AgentToolService`) ; intégré à `index.blade.php` (nouvelle `<section>` « Outils du parc »).
- **Vérifs de raccordement** : embed bien supprimé (`rainmeter_embed.go`/`embedded/`/test = `git D`) ; aucune référence morte `RainmeterSkinSource`/`go:embed embedded/` dans les `.go` ; `provisionRainmeterPortable`/`ensureRainmeterConfig` consomment le manifest (checksum `tool.sha256`) et la skin téléchargée ; skin canonique provisionnée sous `storage/assets/overlay/rainmeter/SambaEduOverlay.ini` (`.gitignore` déjà OK) ; manifest expose `{tool:{key,filename,sha256,size}, skin:{filename,sha256}}` (golden overlay INCHANGÉ, D8b confirmé). Test obsolète `TestRainmeterSkinSource_EmbeddedAndConvertible` retiré de `overlay_logon_test.go`.
- **T6 (tests)** : créé `rainmeter_provision_test.go` (Go : manifest→download+vérif SHA-256 AVANT extraction ; skin téléchargée→UTF-16 LE+BOM ; tool/skin null→no-op gracieux D4 ; hash divergent portable & skin→rejet sans pose ; quarantaine/store nil→no-op ; parsing strict filename/hash) ; `AgentToolServiceTest` (upload OK + 7 rejets : version/extension/MIME/taille/structure×2/zip corrompu ; SHA-256 serveur ; anti-traversal filename ; mono-version+purge ; toggle) ; `ToolManifestSkinEndpointTest` (manifest tool actif/désactivé/absent ; skin servie 200/401/403 ; fallback canonique ; check-in stampé) ; `ToolsCatalogSurfaceTest` (upload via service, structure KO→toastError, toggle, Gate sur upload+toggle).
- **T7 (doc/QA)** : `docs/qa/domains/agent.md` §18 (4 scénarios numérotés + 4 lignes de checklist) ; `docs/qa/README.md` ligne agent enrichie + Story 25.6 ; `agent/README.md` (skin servie + checksum manifest, embed retiré) ; `resources/overlay/README.md` (section « Catalogue de tools ») ; `docs/agent/release-distribution.md` (section catalogue + endpoints + serving skin).

Arbitrages Henri appliqués : Q1=no-op à la désactivation (D4), Q2=toggle global (D3), Q3=embed supprimé sec (D1), Q4=mono-version (D5), D8=manifest dédié (golden overlay inchangé).

**Validation lab Windows (T8) = ACTION HUMAINE Henri** (upload réel, toggle ON→pose+overlay, toggle OFF→no-op sans désinstaller, retouche skin sans recompiler).

**Actions /vm requises** : `php artisan migrate` (agent_tools) ; `php artisan config:cache` (clés `tool_max_upload_bytes`/`overlay_skin_path`) ; `php artisan route:cache` (routes `tools-manifest`/`overlay-skin`) ; `chown -R www-admin storage/assets/overlay/rainmeter storage/agent/tools` ; rejouer `AgentToolServiceTest` + tests Livewire d'upload (`ext-zip` présent sur la VM).

## Corrections post-review 2026-06-16

Suite au document `_bmad-output/codeReviews/25-6.md` (review sonnet + 2e avis opus), corrections clear-cut appliquées :

- **P5 — `wire:confirm` sur « Activer »** (`tools-catalog.blade.php`) : le bouton « Activer » porte désormais une confirmation symétrique à « Désactiver » (« Activer Rainmeter ? Les postes le déploieront automatiquement à leur prochain check-in. ») — l'action la plus impactante (déploiement parc entier au prochain check-in) n'était pas confirmée.
- **P6 — pas de fuite de chemin disque dans le toast** : `AgentToolService::reject()` logge désormais le `detail` complet (chemins disque inclus) dans le warning `agent.tool.rejected` ; la façade Livewire (`tools-catalog.blade.php`) n'affiche plus `$e->getMessage()` mais un message UI générique + le `reason` machine (« Upload refusé ({reason}). Vérifiez l'archive et réessayez. »). Idem `hash_failed`/`storage_unavailable` : le chemin reste côté serveur.
- **P1 — cleanup orphelin sur exception DB** (`AgentToolService::upload()`) : l'`updateOrCreate` post-`moveConfined` est enveloppé dans un `try { … } catch (\Throwable $e) { @unlink($destination); … throw; }` — une exception DB (QueryException, contrainte, DB down) ne laisse plus le `.zip` tout juste déplacé orphelin sur disque (log `agent.tool.upload_db_failed`).
- **P8 — tests « aucun orphelin »** (`AgentToolServiceTest`) : ajout de `rejected_upload_leaves_no_orphan_file_on_disk` (refus `invalid_version` → `glob(tools_path/*)` vide, indépendant de `ext-zip`) et `db_failure_after_move_leaves_no_orphan_file` (table droppée → QueryException post-move → `glob` vide ; `markTestSkipped` si `ext-zip` absent, vert sur /vm).
- **Doc — invariant ops** (`docs/agent/release-distribution.md`, §25.6) : note « ne JAMAIS remplacer le `.zip` portable / la skin sur disque hors upload — le SHA-256 fait foi depuis la DB/le manifest ; un remplacement manuel ferait rejeter l'agent en boucle (hash divergent) ».

**Non corrigés (documentés/écartés dans la review)** : N1 (provisioning dans le GET — follow-up), P7 (test manifest couplé skin), P2/P3/P4/P9 (faux ou sur-cotés).

**Gates (hôte, `ext-zip` ABSENT — limite connue)** : `AgentToolService` filter → les nouveaux tests `rejected_upload_leaves_no_orphan_file_on_disk` PASSE, `db_failure_after_move_leaves_no_orphan_file` SKIP (ext-zip), tous les autres échecs = `Class "ZipArchive" not found` (préexistant, non lié aux corrections). `ToolManifestSkin` 9/9 vert. Livewire `ToolsCatalogSurface` : les 2 tests sans ZIP verts, 3 échecs = ext-zip. **Golden `overlay.golden.json` INCHANGÉ** (aucun fichier Go touché).

### File List

**Créés par cette session :**
- `resources/views/pages/parc-settings/agent/_partials/tools-catalog.blade.php`
- `agent/shared/rainmeter_provision_test.go`
- `tests/Unit/Services/Agent/AgentToolServiceTest.php`
- `tests/Feature/Api/V1/Agent/ToolManifestSkinEndpointTest.php`
- `tests/Feature/Livewire/Agent/ToolsCatalogSurfaceTest.php`

**Modifiés par cette session :**
- `resources/views/pages/parc-settings/agent/index.blade.php` (nouvelle `<section>` « Outils du parc »)
- `agent/shared/overlay_logon_test.go` (retrait du test embed obsolète)
- `docs/qa/domains/agent.md` (§18 append-only + checklist)
- `docs/qa/README.md` (ligne agent + Story 25.6)
- `agent/README.md` (skin servie + checksum manifest, embed retiré)
- `resources/overlay/README.md` (section « Catalogue de tools »)
- `docs/agent/release-distribution.md` (section catalogue de tools)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (25-6 → review + `# last_updated`)
- `_bmad-output/implementation-artifacts/25-6-catalogue-tools-agent-upload-portable-serving-skin.md` (tâches, Dev Agent Record, status)

**Déjà présents (socle codé par l'étape précédente — vérifiés/raccordés, non réécrits) :**
- `database/migrations/2026_06_16_120000_create_agent_tools_table.php`
- `app/Models/AgentTool.php`
- `app/Services/Agent/Tools/AgentToolService.php`
- `app/Services/Agent/Tools/AgentToolManifestService.php`
- `app/Services/Agent/Tools/AgentToolException.php`
- `app/Services/Agent/Tools/OverlaySkinProvisioner.php`
- `app/Http/Controllers/Api/V1/Agent/ToolController.php` (étendu `manifest()` + `skin()`)
- `routes/api.php` (`tools-manifest`, `overlay-skin` — après le groupe 16.12)
- `config/agent.php` (`tool_max_upload_bytes`, `overlay_skin_path`)
- `.gitignore` (`/storage/assets/overlay/`)
- `agent/shared/rainmeter.go` (constantes routes manifest/skin, `RainmeterToolChecksum` retirée)
- `agent/shared/rainmeter_manifest.go` (parsing manifest + validation stricte)
- `agent/shared/rainmeter_provision.go` (provisioning piloté par manifest)
- `storage/assets/overlay/rainmeter/SambaEduOverlay.ini` (skin provisionnée, non versionnée)

**Supprimés (embed retiré — D1) :**
- `agent/shared/rainmeter_embed.go`
- `agent/shared/embedded/SambaEduOverlay.ini`
- `agent/shared/rainmeter_embed_test.go`
