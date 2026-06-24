# Story 27.17: Page « Configuration par défaut du parc » (couche Broadcast multi-domaines + outils obligatoires)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an **administrateur d'établissement SE5**,
I want **une page d'administration dédiée, à onglets, qui expose et édite en un seul endroit la configuration appliquée par défaut à TOUS les postes (la couche `Broadcast`), avec une notion d'éléments obligatoires vs facultatifs**,
so that **je pilote la base commune du parc sans la disperser dans plusieurs écrans, et les éléments obligatoires au bon fonctionnement de SE5 sont garantis présents sur le serveur (install/update) et poussés d'office sur les postes, tout en restant overridables par une config plus spécifique**.

## Contexte & cadrage (décisions Henri)

- Le mécanisme de précédence existe DÉJÀ : `StateCompiler` résout par spécificité `User=0 > UserGroup=1 > Workstation=2 > LogicalGroup=3 > PhysicalGroup=4 > Broadcast=5` (plancher). La maille **`Broadcast`** = « s'applique à tous, dernière priorité, overridable » → **aucun nouveau niveau ni groupe `_TousLesPostes` à créer**. ([Source: app/Services/Agent/StateCompiler.php:383-393])
- Cette story crée **une surface d'édition consolidée** de la couche Broadcast. **Option (a) actée : la page consolide ET remplace les éditeurs de défaut épars** — on migre l'édition du **défaut Broadcast** de chaque domaine vers cette page à onglets. Les providers `StateCompiler` et le backing storage de chaque domaine restent **inchangés** (on réutilise les services existants).
- **Hors scope (acté)** : l'override des apps **par poste**. `ApplicationsStateProvider` reste tout-Broadcast (D4 inchangé). L'override viendra avec une future refonte de la page poste (affichage live du state calculé + override in-situ).

## Acceptance Criteria

**Page & navigation**
1. Une route `GET /admin/settings/parc-defaults` existe (`Route::livewire('/settings/parc-defaults', 'pages::admin.settings.parc-defaults.index')->middleware('can:server.admin')->name('admin.settings.parc-defaults')`), alignée sur le pattern des routes `admin.settings.*`. ([Source: routes/web.php groupe `admin` / `settings`])
2. Une carte d'entrée vers cette page est ajoutée sur `/admin/settings` (composant `x-molecules.settings-card`, section pertinente). ([Source: resources/views/pages/admin/settings/index.blade.php:98-121])
3. La page suit le filesystem-router : `resources/views/pages/admin/settings/parc-defaults/index.blade.php` + `_partials/` ; navigation par onglets via `#[Url] public string $tab` (pattern maison DaisyUI `tabs tabs-boxed`, pas de composant tabs dédié). ([Source: resources/views/pages/parc-settings/index.blade.php:124-164])

**Onglets — consolidation des défauts Broadcast existants**
4. Onglet **Wallpaper** : édite le défaut établissement (`wallpapers WHERE owner_id IS NULL AND is_default=true AND type='wallpaper'`) en réutilisant le composant `wallpaper-card` et `WallpaperUploadService` ; aucune nouvelle table. ([Source: resources/views/components/molecules/wallpaper-card.blade.php:36-212 ; app/Services/Wallpaper/WallpaperUploadService.php:44-126 ; app/Services/Agent/Providers/WallpaperStateProvider.php:69-121])
5. Onglet **Lockscreen** : idem wallpaper avec `type='lockscreen'` (même composant/service). ([Source: app/Services/Agent/Providers/LockscreenStateProvider.php:73-112])
6. Onglet **Registre / capacités** : édite le **défaut diffusé** (`capabilities.default_value`) via le service/flow existant `saveDefault()`, sans toucher aux overrides par parc (`capability_assignments`). ([Source: resources/views/pages/admin/settings/capabilities/index.blade.php:79-120 ; app/Services/Agent/Providers/AbstractCapabilityStateProvider.php:153-163])
7. **Overlay — EXTRAIT dans la story 27.18** (« Overlay par défaut du parc »). HORS SCOPE 27.17. 27.18 ajoutera l'onglet Overlay à la page créée ici (header variables + messages conditionnels + broadcast + migration `overlay-messages` → onglet workstationGroup).
8. Onglet **Outils agent** : édite `agent_tools` (Rainmeter aujourd'hui) via `AgentToolService` (SEUL écrivain). **Canal séparé** : c'est livré par le manifest tools (l'agent télécharge+extrait), **PAS** un item de state Broadcast et **non overridable** par poste — l'onglet doit le présenter distinctement des onglets « state ». ([Source: resources/views/pages/admin/settings/agent/_partials/tools-catalog.blade.php:32-220 ; app/Services/Agent/Tools/AgentToolService.php:69-227])
9. Migration des surfaces (option a) : les points d'édition du **défaut Broadcast** retirés/redirigés de leurs pages d'origine (wallpaper-card « défaut » sur `/parc-settings/wallpapers` ; `saveDefault` sur `/admin/settings/capabilities` ; tools-catalog sur `/admin/settings/agent`). **Ne PAS toucher** aux éditions *ciblées* (par parc / user / poste) qui restent sur leurs pages. (Overlay : voir 27.18.)

**Onglet Apps (net-new)**
10. Onglet **Apps obligatoires/défaut** : permet de désigner des applications appliquées **par défaut à tous les postes** (ex. `7za`, `NirCmd`). Aujourd'hui les apps ne sont rattachées que via des **profils** — il manque le champ « cette app = défaut établissement » (l'équivalent du `is_default` du wallpaper). On ajoute donc une colonne **`is_parc_default`** (décision Henri) sur `applications`, lue par `ApplicationsStateProvider` et émise en Broadcast **sans modifier la précédence**. ([Source: app/Services/Agent/Providers/ApplicationsStateProvider.php:71-164 ; app/Models/Application.php])

**Obligatoire vs facultatif + provisioning serveur**
11. Chaque élément éditable porte un flag **`required` (obligatoire)** vs **facultatif**. `required` ⇒ (a) **provisionné côté serveur** à `install.sh`/`update.sh` (fonction `ensure_*` idempotente, hash-vérifiée, world-readable, owner `www-admin`, sur le modèle de `ensure_wpkg_smb_client`/`ensure_wpkg_bundle`) ; (b) **poussé d'office** (Broadcast pour le state ; manifest pour les outils agent). Facultatif ⇒ activé seulement si l'admin le décide. ([Source: scripts/update.sh `ensure_wpkg_bundle`/`ensure_wpkg_smb_client`])
12. **Sources (décisions Henri)** : (a) source par défaut des paquets WPKG « système » = dépôt **`https://gitlab.sambaedu.org/sambaedu/sambaedu-wpkg-xml`** ; (b) le **portable Rainmeter** obligatoire est **embarqué dans le repo** : `resources/agent/tools/sambaedu-rainmeter-0.1.zip` (structure validée `Rainmeter.exe` + `Skins/`), et `install.sh`/`update.sh` l'**enregistre via `AgentToolService`** dans `agent_tools` si absent (clé `rainmeter`). Le provisioning `required` n'échoue **jamais** l'install/update (fail-soft + log) ; un `required` sans source résolvable → warning explicite, pas échec.

**Sécurité & non-régression**
13. Toutes les actions mutantes des onglets sont protégées par **`Gate::authorize('server.admin')`** côté composant (en plus du middleware route `can:server.admin`). Décision Henri : **tout en `server.admin` pour le moment** (on n'utilise plus `wallpaper.manage` sur cette page de défauts ; les pages ciblées d'origine gardent leur gate propre).
14. Les providers `StateCompiler` et le calcul de `specificity()` ne sont **pas modifiés** ; le state produit pour un poste sans config spécifique est inchangé (hors ajout volontaire d'apps défaut). Tests de non-régression sur la compilation Broadcast.

## Tasks / Subtasks

- [x] **T1 — Échafaudage page + route + nav** (AC: 1,2,3)
  - [x] Route `admin.settings.parc-defaults` dans `routes/web.php` (groupe `admin`, `can:server.admin`)
  - [x] `resources/views/pages/admin/settings/parc-defaults/index.blade.php` (SFC mince, onglets `#[Url] public string $tab`, `x-organisms.page`)
  - [x] Carte d'entrée sur `/admin/settings` (`x-molecules.settings-card`)
- [x] **T2 — Onglet Wallpaper + Lockscreen** (AC: 4,5,9) — réutiliser `wallpaper-card` (type/isDefault), retirer/rediriger la carte « défaut » de `/parc-settings/wallpapers`
- [x] **T3 — Onglet Registre/capacités (défaut diffusé)** (AC: 6,9) — surface `saveDefault()`/`toggleLock` ; laisser les overrides parc sur la page groupe
- [x] ~~**T4 — Onglet Overlay**~~ → **EXTRAIT en story 27.18** (hors scope 27.17)
- [x] **T5 — Onglet Outils agent** (AC: 8,9) — réutiliser le flow `tools-catalog` ; marquer « canal séparé / toujours-actif / non overridable »
- [x] **T6 — Onglet Apps défaut parc (net-new)** (AC: 10) — stockage `is_parc_default` (migration), lecture dans `ApplicationsStateProvider` (Broadcast), UI de sélection
- [x] **T7 — Flag `required` + provisioning install/update** (AC: 11,12) — notion `required` ; `ensure_*` dans `scripts/update.sh` (fail-soft, hash, world-readable, www-admin) ; source WPKG par défaut = `https://gitlab.sambaedu.org/sambaedu/sambaedu-wpkg-xml` ; enregistrer le portable Rainmeter embarqué (`resources/agent/tools/sambaedu-rainmeter-0.1.zip`) via `AgentToolService` si `agent_tools` vide
- [x] **T8 — Sécurité & Gates** (AC: 13) — `Gate::authorize('server.admin')` sur chaque action mutante (tout server.admin)
- [x] **T9 — Tests** (AC: 14) — Feature tests page (accès, onglets, autorisations) ; Unit/Feature non-régression `StateCompiler`/providers Broadcast ; test `ApplicationsStateProvider` avec apps défaut parc ; test provisioning `ensure_*` (présence/idempotence) — exécution sur HÔTE (php+sqlite+vendor). Penser `migrate:status` VM avant e2e base.

## Dev Notes

### Précédence & maille Broadcast (le cœur, inchangé)
- `StateCompiler::specificity()` : `User=0 … Broadcast=5` (plancher). Une config `User/Workstation/LogicalGroup` bat le Broadcast **sans modifier le compilateur**. ([Source: app/Services/Agent/StateCompiler.php:383-393])
- Providers émettant déjà du Broadcast : `WallpaperStateProvider`, `LockscreenStateProvider`, `OverlayStateProvider`, `AbstractCapabilityStateProvider` (27.3ter « défaut diffusé + override »), `ApplicationsStateProvider` (tout-Broadcast, D4).

### Inventaire backing par domaine (à RÉUTILISER, ne pas réécrire)
| Domaine | Stockage défaut | Service écrivain | Provider | Gate actuel |
|---|---|---|---|---|
| Wallpaper | `wallpapers` (owner_id NULL, is_default, type='wallpaper') | `WallpaperUploadService::store/assignExisting` | `WallpaperStateProvider` | `wallpaper.manage` |
| Lockscreen | `wallpapers` (… type='lockscreen') | idem | `LockscreenStateProvider` | `wallpaper.manage` |
| ~~Overlay~~ | **→ story 27.18** (extrait) | — | — | — |
| Capacités (défaut) | `capabilities.default_value` | flow `saveDefault()` (page capabilities) | `RegistryMachine/UserCapabilityProvider` | `server.admin` |
| Outils agent | `agent_tools` (key,enabled,sha256…) | `AgentToolService::upload/toggle` (SEUL écrivain) | manifest (`AgentToolManifestService` + `ToolController`) | `server.admin` |
| Apps défaut | **n'existe pas** → à créer | à créer | `ApplicationsStateProvider` (Broadcast) | — |

### Points d'attention / pièges (issus de l'analyse)
- **Apps — le flag « défaut établissement » manque** : toute l'instance EST l'établissement ; mais côté apps il n'existe aucun champ « cette app s'applique à tous les postes » (contrairement au wallpaper qui a `is_default`). Les apps ne sont rattachées que via des profils (`WorkstationProfile`↔`Application`). On ajoute donc **`is_parc_default`** (booléen) sur `applications`, lu par `ApplicationsStateProvider` pour émettre ces apps au set Broadcast de TOUS les postes. ⚠️ NE PAS introduire d'override par poste (hors scope).
- **Sources des obligatoires (décisions Henri)** : WPKG « système » par défaut depuis `https://gitlab.sambaedu.org/sambaedu/sambaedu-wpkg-xml` ; portable Rainmeter EMBARQUÉ `resources/agent/tools/sambaedu-rainmeter-0.1.zip` (Rainmeter.exe+Skins/ validés) → install.sh l'enregistre via `AgentToolService::upload`-équivalent si `agent_tools` vide. Note : versionner un binaire ~3,3 Mo dans le repo est assumé « pour le moment ».
- **Overlay → EXTRAIT en story 27.18** : 27.18 ajoutera l'onglet Overlay (variables d'en-tête + messages conditionnels + broadcast) à la page créée ici, et migrera `overlay-messages` vers un onglet workstationGroup. Rien à faire pour l'overlay dans 27.17.
- **« Remplace » = seulement l'édition du DÉFAUT** : les pages d'origine gardent leurs fonctions *ciblées* (wallpaper par parc/user, overlay ciblé, overrides de capacités par parc). Ne migrer que la carte/section « défaut établissement (Broadcast) ».
- **Outils agent = canal hors-state** : Rainmeter est livré par manifest (agent télécharge+extrait vers `C:\ProgramData\SambaEdu\Rainmeter`), pas par le state. `required`/toujours-actif, **non overridable**. Aujourd'hui `AgentToolService` est **mono-Rainmeter codé en dur** (`RAINMETER_KEY`, pattern `sambaedu-rainmeter-*.zip`, structure `Rainmeter.exe`+`Skins/`). Pour `required` + provisioning install.sh, prévoir une **source canonique** (URL dépôt) par outil obligatoire — sinon install.sh ne peut que vérifier la présence + warn (un binaire tiers comme Rainmeter n'a pas d'URL SambaEdu par défaut ; l'upload reste le fallback). ([Source: app/Services/Agent/Tools/AgentToolService.php:42-60])
- **Contrainte `required` à install.sh** : `ensure_*` doit être **idempotent, fail-soft, hash-vérifié, world-readable (664/644 — poste = classe SMB « other »), owner www-admin** — calquer sur `ensure_wpkg_bundle`/`ensure_wpkg_smb_client` (ajoutées récemment). ([Source: scripts/update.sh])

### Conventions projet (à respecter)
- Filesystem router : `resources/views/pages/<route>/index.blade.php` + `_partials/` ; route déclarée via `Route::livewire('pages::...')`. ([Source: routes/web.php])
- Livewire **SFC** (classe inline dans le blade), réactivité par onglet via `#[Url] public string $tab` ; rendu DaisyUI `tabs tabs-boxed` (pas de composant tabs dédié). ([Source: resources/views/pages/parc-settings/index.blade.php:124-164])
- Notifications : trait `App\Components\Traits\WithToasts` (`toastSuccess/toastError/...`). ([Source: app/Components/Traits/WithToasts.php])
- Modale réutilisable : `x-molecules.modal` (+ `x-molecules.modal.section`, slot `footer`) ; bouton déclencheur `wire:click="openModal"`. ([Source: resources/views/components/molecules/modal/index.blade.php])
- Page : wrapper `x-organisms.page` (title/icon/description) ; carte settings `x-molecules.settings-card` + section `x-molecules.settings-section`.
- Tests sur HÔTE (php 8.4 + pdo_sqlite + vendor) ; sur VM, `migrate:status` avant e2e base, `config:cache`/`route:cache` + `chown www-admin` après tout `config/*.php` / route ajoutée.

### Project Structure Notes
- Nouvelle arbo : `resources/views/pages/admin/settings/parc-defaults/{index.blade.php,_partials/*-tab.blade.php}`.
- Migration éventuelle : `is_parc_default` sur `applications` (+ `required` selon stockage retenu). Migration jouée SQLite (tests hôte) ; **PENDING sur VM** (pas auto-jouée par le dev-cycle).
- `scripts/update.sh` : nouvelle (ou nouvelles) fonction `ensure_*` appelée dans `main()` après `ensure_wpkg_smb_client`.

### References
- [Source: app/Services/Agent/StateCompiler.php:383-393] — specificity / maille Broadcast
- [Source: app/Services/Agent/Providers/ApplicationsStateProvider.php:71-164] — apps tout-Broadcast (D4)
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php:153-163] — défaut diffusé Broadcast + override (27.3ter)
- [Source: app/Services/Wallpaper/WallpaperUploadService.php:44-126] — écriture défaut wallpaper/lockscreen
- [Source: app/Services/Agent/Tools/AgentToolService.php:69-227] — agent_tools (mono-Rainmeter, SEUL écrivain)
- [Source: resources/views/pages/admin/settings/capabilities/index.blade.php:79-120] — saveDefault() capacités
- [Source: resources/views/pages/parc-settings/index.blade.php:124-164] — pattern onglets `#[Url]`
- [Source: resources/views/pages/admin/settings/agent/index.blade.php:1-61] — modèle page settings
- [Source: scripts/update.sh] — `ensure_wpkg_bundle` / `ensure_wpkg_smb_client` (modèle de provisioning)

## Décisions tranchées (Henri, 2026-06-24)

1. **Gate** : tout en **`server.admin`** pour le moment (cette page n'utilise plus `wallpaper.manage`).
2. **Stockage apps défaut** : colonne **`is_parc_default`** sur `applications`.
3. **Sources des obligatoires** : WPKG « système » → dépôt **`https://gitlab.sambaedu.org/sambaedu/sambaedu-wpkg-xml`** ; **Rainmeter portable embarqué** dans le repo (`resources/agent/tools/sambaedu-rainmeter-0.1.zip`), enregistré par install.sh via `AgentToolService`.
4. **Overlay** : **extrait en story 27.18** (redessiné en 3 sections + migration `overlay-messages` → onglet workstationGroup). Hors scope 27.17.

## Découpage (fait)

✅ **Overlay EXTRAIT en story 27.18** (« Overlay par défaut du parc »). 27.17 = la page « config par défaut » + onglets **Wallpaper / Lockscreen / Registre / Apps / Outils agent** (tous sur de l'existant ou le flag `is_parc_default`). 27.18 ajoutera l'onglet Overlay à la page créée ici (dépendance : 27.18 après 27.17).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context)

### Debug Log References

- `php artisan test --filter=ApplicationsStateProviderTest` → 10 passed (25 assertions)
- `php artisan test --filter=AdminSettingsParcDefaultsPageTest` → 11 passed (16 assertions)
- `php artisan test --filter=AgentToolsRegisterDefaultsCommandTest` → 1 passed, 3 skipped (ext-zip absent sur HÔTE — verts sur /vm)
- Non-régression : `AdminSettingsCapabilitiesPageTest` (6 passed), `GpoNativeSectionLinksTest` (7 passed), `WallpaperStateProviderTest`/`LockscreenStateProviderTest` (17 passed), `WallpaperLibraryPickerTest` (6 passed)
- `StateCompilerTest` : 2 échecs PRÉEXISTANTS sur HEAD (sync AD observer dans `WorkstationGroupAdSyncJob`, indépendants de 27.17 — vérifié par stash/baseline), 25 passed. `specificity()` NON modifié.

### Completion Notes List

- **T1** — Route `admin.settings.parc-defaults` (`can:server.admin`) + page filesystem-router à onglets (`#[Url] $tab`, DaisyUI `tabs tabs-boxed`) + carte d'entrée sur `/admin/settings` (section « Agent / Flotte »). Onglets : wallpaper, lockscreen, registry, apps, tools. L'onglet overlay arrivera en 27.18.
- **T2** — Onglets Wallpaper + Lockscreen réutilisent `wallpaper-card` (`isDefault=true`, `ownerType=null`) + `WallpaperUploadService`. Le composant `wallpaper-card` gagne une prop `gate` (défaut `wallpaper.manage`, rétrocompat) — les onglets passent `gate="server.admin"` (décision Henri). La page `/parc-settings/wallpapers` (qui ne faisait QUE l'édition du défaut) est transformée en REDIRECTION vers `parc-defaults?tab=wallpaper` (le lien GPO `?from_gpo=` continue de fonctionner).
- **T3** — Onglet Registre/capacités : nouveau partial réutilisant le flow `saveDefault()`/`toggleLock` (édition de `capabilities.default_value` + gel `overrides_locked`). Les overrides par parc restent sur l'onglet « Options/Capacités » du groupe (intacts). DÉVIATION (voir §Déviations) : `/admin/settings/capabilities` N'EST PAS redirigée (préservation de sa suite de tests) — la carte d'entrée capabilities est conservée.
- **T5** — Onglet Outils agent : partial réutilisant `AgentToolService` (upload/toggle, SEUL écrivain). Présenté distinctement (alert « canal séparé / hors-state / non overridable », livré par le manifest).
- **T6** — Migration `is_parc_default` (booléen, défaut false, indexé) sur `applications` + fillable/cast/`@property`/scope `parcDefault()` sur le modèle. `ApplicationsStateProvider::itemsFor()` UNIONNE les apps `is_parc_default=true` à l'ensemble résolu par le resolver (toujours en candidats Broadcast) — le resolver et `StateCompiler::specificity()` restent INCHANGÉS (type `aggregate` → union sans conflit de précédence). UI : onglet « Applications » (liste des défauts + recherche/ajout). `WpkgSchemaBootstrapper` mis à jour (la table `applications` y est créée à la main).
- **T7** — Commande artisan `agent:tools:register-defaults` (idempotente, fail-soft) + méthode `AgentToolService::registerEmbedded()` (enregistre le portable embarqué `resources/agent/tools/sambaedu-rainmeter-*.zip` via le SEUL écrivain, no-op si la clé existe déjà). Fonction `ensure_agent_required_tools` dans `scripts/update.sh` (appelée dans `main()` après `ensure_wpkg_smb_client`, modèle `ensure_wpkg_*`) : enregistrement idempotent fail-soft + droits world-readable 644 + owner www-admin sur le répertoire des outils. `install.sh` rejoue `update.sh` → couvert. Un `required` sans source résolvable → warning, JAMAIS échec.
- **T8** — `Gate::allows('server.admin')` en `mount()` de chaque partial + `Gate::authorize('server.admin')` sur chaque action mutante (apps-tab `setParcDefault`, tools-tab `importTool`/`toggle`, registry-tab `guardAdmin()`). Le wallpaper-tab délègue au composant via `gate="server.admin"`.
- **T9** — Tests Feature page (accès/onglets/autorisations) + onglet apps (toggle) + onglet registry (gate/rendu) + provider `is_parc_default` (diffusion Broadcast d'un poste neuf, union sans doublon, non-régression vide) + commande de provisioning (présence/idempotence/fail-soft). Correction d'un BUG PRÉEXISTANT de `ApplicationsStateProviderTest` (combinait `RefreshDatabase` + `WpkgSchemaBootstrapper` → faux échecs au tearDown sous SQLite :memory:/PHP 8.4 ; `RefreshDatabase` retiré, aligné sur `WorkstationPackagesResolverTest`).

#### Post-review corrections (review Sonnet + 2e avis Opus)

- **#1 — `#[Locked]` sur `$gate` (wallpaper-card)** — La prop `gate` est désormais `#[Locked]` (import `Livewire\Attributes\Locked`), pattern iso-Story 16.5 (page liaisons GPO) : la gate est figée au mount, plus mutable via `/livewire/update` — un acteur ne peut pas la rabaisser à une permission qu'il détient. `resources/views/components/molecules/wallpaper-card.blade.php`.
- **#2 — Fail-soft élargi à `\Throwable` (commande)** — `AgentToolsRegisterDefaultsCommand::handle()` attrape MAINTENANT, en plus de `AgentToolException`, tout `\Throwable` (DB down, contrainte, I/O re-levés par `registerEmbedded()`) → `warn()` + `SUCCESS`. La commande honore son docblock « ne casse JAMAIS l'install/update ». `app/Console/Commands/AgentToolsRegisterDefaultsCommand.php`.
- **#3 — RÉGRESSION lien GPO `from_gpo`** — La redirection de `parc-settings/wallpapers` PROPAGE désormais `from_gpo` (lu sur la requête courante) dans les params de `redirectRoute('admin.settings.parc-defaults', …)`. La page `parc-defaults` expose `#[Url] ?string $from_gpo` (persistance du param sur la navigation par onglets Livewire) et rend `<x-molecules.gpo-back-link>` quand il est présent. Commentaire « le lien GPO continue de fonctionner » remplacé par la mécanique réelle (propagation + breadcrumb). `resources/views/pages/parc-settings/wallpapers/index.blade.php`, `resources/views/pages/admin/settings/parc-defaults/index.blade.php`.
- **#4 — Tests registry-tab** — `registry_tab_save_default_persists_valid_value()` (valeur valide → `default_value` persisté) + `registry_tab_toggle_lock_flips_overrides_locked()` (bascule `overrides_locked`), flow iso `AdminSettingsCapabilitiesPageTest`. Catalogue capacités vidé en `setUp` (clés déterministes). `tests/Feature/Livewire/Admin/AdminSettingsParcDefaultsPageTest.php`.
- **#5 — Tests tools-tab** — `tools_tab_gate_blocks_mount_without_server_admin()` (403, pattern des autres partials) + `tools_tab_toggle_flips_enabled()` (entrée catalogue créée directement via le modèle `AgentTool`, `toggle` ne décompresse rien → pas de dépendance ext-zip). Même fichier.
- **#6 — Test fail-soft réécrit** — `fail_soft_when_no_path_and_no_embedded_portable` (qui skippait sur ext-zip et testait l'inverse de son nom) → `fail_soft_when_discovery_finds_no_embedded_portable` : teste RÉELLEMENT la découverte vide (répertoire de découverte vide via la nouvelle config `agent.tools_embedded_path`, défaut `resources/agent/tools` — prod INCHANGÉE) → `warn` + `SUCCESS`, SANS ext-zip (plus de skip, tourne partout). Seam minimal : `discoverEmbeddedRainmeter()` lit `config('agent.tools_embedded_path')`. `config/agent.php`, `app/Console/Commands/AgentToolsRegisterDefaultsCommand.php`, `tests/Feature/Console/AgentToolsRegisterDefaultsCommandTest.php`.
- **#7 — Suppression page doublon `/admin/settings/capabilities` (décision Henri)** — La page `/admin/settings/capabilities` (27.12) était un DOUBLON fonctionnel 1:1 de l'onglet « Registre / capacités » de parc-defaults (même flow `saveDefault()`/`toggleLock()`/modale). Elle est SUPPRIMÉE (dossier `resources/views/pages/admin/settings/capabilities/`, route `admin.settings.capabilities`, carte `card-capabilities-defaults` de `/admin/settings`, test `AdminSettingsCapabilitiesPageTest`). Sa couverture est PORTÉE sur le registry-tab : les 2 cas spécifiques `invalid_default_is_rejected` et `warning_default_requires_acknowledgement` sont ajoutés comme `registry_tab_rejects_invalid_default` et `registry_tab_warning_default_requires_acknowledgement` dans `AdminSettingsParcDefaultsPageTest`. Annule la déviation #1 (plus de page capabilities concurrente). `routes/web.php`, `resources/views/pages/admin/settings/index.blade.php`, `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` (docblock mis à jour).
- **#8 — Sync du catalogue WPKG système à l'update (AC12a, décision Henri)** — Clarification Henri : la source des paquets WPKG « système » est `http://deb.sambaedu.org/wpkg/xml/packages.xml`, **déjà le dépôt PRIMAIRE seedé** (`DepotApplicationSeeder`, `is_primary=true`) — donc rien à enregistrer. Manquait seulement la synchronisation à l'update : `update.sh` n'invoquait jamais `appstore:sync`. Ajout de la fonction `ensure_appstore_catalog_sync` (`scripts/update.sh`, appelée dans `main()` après `ensure_appstore_write_dirs`), modelée sur `ensure_agent_required_tools` : check de présence de la commande, exécution sous `www-admin`, **fail-soft** (dépôt distant injoignable → `log_warning`, jamais d'échec d'update). Rafraîchit `depot_applications` à chaque update. L'install + le marquage `is_parc_default` des paquets obligatoires restent un **geste admin** ultérieur (acté). `scripts/update.sh`.

### File List

**Créés**
- `database/migrations/2026_06_24_120000_add_is_parc_default_to_applications.php`
- `app/Console/Commands/AgentToolsRegisterDefaultsCommand.php`
- `resources/views/pages/admin/settings/parc-defaults/index.blade.php`
- `resources/views/pages/admin/settings/parc-defaults/_partials/wallpaper-tab.blade.php`
- `resources/views/pages/admin/settings/parc-defaults/_partials/lockscreen-tab.blade.php`
- `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php`
- `resources/views/pages/admin/settings/parc-defaults/_partials/apps-tab.blade.php`
- `resources/views/pages/admin/settings/parc-defaults/_partials/tools-tab.blade.php`
- `tests/Feature/Livewire/Admin/AdminSettingsParcDefaultsPageTest.php`
- `tests/Feature/Console/AgentToolsRegisterDefaultsCommandTest.php`

**Modifiés**
- `routes/web.php` (route `admin.settings.parc-defaults`)
- `resources/views/pages/admin/settings/index.blade.php` (carte d'entrée)
- `app/Models/Application.php` (fillable + cast + `@property` + scope `parcDefault`)
- `app/Services/Agent/Providers/ApplicationsStateProvider.php` (union des apps `is_parc_default` en Broadcast)
- `app/Services/Agent/Tools/AgentToolService.php` (méthode `registerEmbedded`)
- `resources/views/components/molecules/wallpaper-card.blade.php` (prop `gate` configurable ; **post-review #1** : `#[Locked]`)
- `resources/views/pages/parc-settings/wallpapers/index.blade.php` (redirection vers parc-defaults ; **post-review #3** : propagation `from_gpo`)
- `scripts/update.sh` (fonction `ensure_agent_required_tools` + appel dans `main()` + résumé)

**Modifiés (post-review)**
- `app/Console/Commands/AgentToolsRegisterDefaultsCommand.php` (**#2** : catch `\Throwable` fail-soft ; **#6** : découverte via `config('agent.tools_embedded_path')`)
- `config/agent.php` (**#6** : clé `tools_embedded_path`, défaut `resources/agent/tools` — seam de test, prod inchangée)
- `resources/views/pages/admin/settings/parc-defaults/index.blade.php` (**#3** : `#[Url] $from_gpo` + rendu `gpo-back-link`)
- `tests/Feature/Livewire/Admin/AdminSettingsParcDefaultsPageTest.php` (**#4/#5** : tests registry-tab `saveDefault`/`toggleLock` + tools-tab gate/`toggle` ; **#7** : `registry_tab_rejects_invalid_default` + `registry_tab_warning_default_requires_acknowledgement` portés depuis le test capabilities supprimé ; helper `makeToggleCapability` accepte un `warning`)
- `tests/Feature/Console/AgentToolsRegisterDefaultsCommandTest.php` (**#6** : test découverte vide réécrit, sans skip ext-zip)
- `tests/Support/WpkgSchemaBootstrapper.php` (colonne `is_parc_default` dans la table `applications` de test)
- `tests/Unit/Services/Agent/ApplicationsStateProviderTest.php` (3 tests `is_parc_default` + retrait `RefreshDatabase`, fix bug préexistant)
- `docs/qa/domains/parc.md` (runbook QA — Section 27.17, append-only)
- `routes/web.php` (**#7** : retrait du bloc route `admin.settings.capabilities`)
- `resources/views/pages/admin/settings/index.blade.php` (**#7** : retrait de la carte `card-capabilities-defaults`)
- `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` (**#7** : docblock — page capabilities supprimée)

**Supprimés (post-review #7)**
- `resources/views/pages/admin/settings/capabilities/index.blade.php` (page doublon 27.12 — vers corbeille)
- `tests/Feature/Livewire/Admin/AdminSettingsCapabilitiesPageTest.php` (couverture portée sur le registry-tab — vers corbeille)

## Déviations par rapport à la story

1. **~~`/admin/settings/capabilities` non redirigée (T3/AC9)~~ — RÉSOLU (post-review #7)** — La déviation initiale conservait la page capabilities (carte + tests) en parallèle de l'onglet consolidé. Décision Henri (review, finding #7) : la page étant un DOUBLON fonctionnel 1:1 de l'onglet « Registre / capacités », elle est SUPPRIMÉE, et ses 2 cas de test spécifiques (`invalid_default_is_rejected`, `warning_default_requires_acknowledgement`) sont PORTÉS sur le registry-tab. L'AC9 (« consolide ET remplace ») est désormais pleinement respecté côté capabilities. Voir Post-review correction #7.
2. **Tests `ensure_*`/registerEmbedded skippés sur HÔTE** — ext-zip est absent du PHP hôte ; les tests qui fabriquent un ZIP réel sont `markTestSkipped` (pattern projet identique à `AgentToolServiceTest`). Ils seront verts sur /vm. Le cas fail-soft (source absente, sans zip) passe sur HÔTE.
