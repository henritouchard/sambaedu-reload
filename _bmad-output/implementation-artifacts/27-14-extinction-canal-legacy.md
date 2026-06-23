# Story 27.14 : Extinction du canal legacy — parité de compétences validée, la dette part en bloc

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->
<!-- Renumérotation : ancienne « Story 27.6 » de l'epic, renommée 27-14 pour collision (27-6 = « Catalogue WPKG — source unique »). La définition canonique reste epics-agent-desired-state.md §Story 27.6 (lignes 774-793). -->

## Story

En tant que mainteneur SambaEdu,
je veux valider la parité de compétences puis supprimer le canal de configuration legacy des postes d'un seul coup,
afin que SE5 ne porte plus qu'**UN** système de configuration des postes (l'agent desired-state), et que le KPI « 0 GPO créée/modifiée hors bootstrap » soit atteignable.

## Contexte (à lire en premier)

C'est la **dernière story de l'Epic 27** : elle ne porte aucune ressource au canal agent (toutes l'ont été par 27.1→27.5 + handlers wallpaper/overlay d'Epic 24). Elle **supprime le transport historique** une fois la parité prouvée. Deux gestes distincts :

1. **GATE de parité (AC1)** — documenter et valider la checklist de parité de compétences sur environnement réaliste (postes migrés + neufs, salles multiples). C'est le gate de la mise en production (palier 3 du brief). **Aucune suppression de code n'est légitime tant que ce gate n'est pas franchi.**
2. **Extinction en bloc (AC2-AC3)** — une fois la parité validée, suppression physique du canal legacy + de ses tests, audit KPI « 0 GPO hors bootstrap ».

**Décision projet actée (NE PAS rouvrir)** : pas d'état transitoire legacy/agent ; le canal legacy n'est pas maintenu pendant le dev agent et meurt en bloc (mémoire `project_no_legacy_transition_state`). La cohabitation de deux systèmes sur un même type de ressource n'existe qu'en lab (product brief §141).

**Pré-requis kill-switch déjà actif** : `LEGACY_CONFIG_CHANNEL_ENABLED=false` sur la VM depuis 2026-06-12 (mémoire `project_legacy_config_kill_switch`). Le canal legacy répond déjà 410/no-op en réversible. **Cette story rend la coupure irréversible** (suppression du code) — le kill-switch est le filet de répétition générale, pas la solution finale.

**Cadrage Henri (2026-06-19) — réversibilité d'abord + cible « repo SE5 autonome »** : l'extinction ne se limite pas au code du repo. Un grand nombre d'éléments legacy vivent **sur le serveur (VM), hors repo** (paquet `sambaedu` legacy, `/etc/sambaedu/`, `/var/unattended/`, `/usr/share/sambaedu/`…). Principe directeur : **déplacer ou renommer d'abord (réversible), supprimer sec ensuite** après observation — on ne purge définitivement que le prouvé-mort. **Cible** : que le repo SE5 soit **quasi-autonome** pour faire tourner le serveur. Les dépendances **indéplaçables** restent en place et sont **documentées**. Cette story est donc autant un livrable de **cartographie + neutralisation réversible** (AC4/T10) qu'une suppression de code.

**Ce qui SURVIT, intouché** : la GPO-dispatcher bootstrap `se4_agent_bootstrap` (story 25.4) — le dernier artefact AD, jamais ré-édité (cf. AC2/Dev Notes). Le canal agent `/api/v1/agent/*` est totalement indépendant et hors-scope.

## Décisions de cadrage (Henri — Étape 2, 2026-06-19)

Ces décisions sont **actées** ; le dev les applique **sans re-trancher** :

1. **Séquençage = « réversible, tout maintenant ».** L'extinction porte sur **tout** le canal legacy (pas d'extinction partielle), mais **en mode réversible** : code-repo → réversibilité par git (suppression via `trash`, historique git) ; VM → réversibilité par **move/rename d'abord** (AC4/T10). Le gate de parité AC1 devient une **validation de sortie documentée**, PAS un blocage dur — les 7 stories en review n'interdisent plus la suppression du code (rien d'irréversible). → tranche R1/T1.3.
2. **`linux_out.php` + `winget_out.php` = HORS SCOPE, conservés**, ainsi que le flag/middleware kill-switch `LEGACY_CONFIG_CHANNEL_ENABLED` qui les protège. → tranche R3/T1.4/T7.3 : NE PAS supprimer `EnsureLegacyConfigChannelEnabled`, la clé `config/sambaedu.php`, ni la ligne `.env.example`.
3. **Cartographie VM = inspection SSH lecture seule autorisée** (`/vm` = `ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50`). Le dev fait l'inventaire réel A/B/C/D (T10). **Aucune mutation VM depuis le worktree** : le plan de neutralisation (move/rename Bucket A) est livré pour validation/application sur main.

## Acceptance Criteria

### AC1 — GATE : checklist de parité de compétences validée et documentée (palier 3)

**Given** la checklist de parité (référence : capacités legacy — wallpapers, raccourcis, lecteurs, imprimantes, associations, registre, config d'app, applications, ciblage par critères)
**When** chaque capacité est démontrée sur le canal **agent** en environnement réaliste (postes migrés + neufs, salles multiples)
**Then** la checklist est cochée et documentée dans cette story (section « Checklist de parité ») — c'est le **gate de la mise en production**.
**And** une capacité dont la story SE5 n'est pas `done` ne peut PAS être déclarée à parité : la suppression du code legacy qui la sert reste bloquée jusqu'à clôture (voir Dépendances + Risques — 7 stories de parité sont en `review` à la création).

### AC2 — Extinction en bloc du canal legacy (une fois AC1 franchi)

**Given** la parité validée (AC1)
**When** l'extinction s'exécute
**Then** sont **supprimés** :
- les services de génération de scripts applications legacy : `ApplicationScriptsGenerator`, `ApplicationScriptsAssembler`, `ApplicationLoggerService`, `ApplicationTemplatesScanner` ;
- le matching par tags `list_*` propre à l'assembly de scripts (les usages `list_*` du domaine métier — partages, classes, AD — sont **hors-scope**, ne pas y toucher) ;
- les controllers du canal legacy : `Gpo\ApplicationsScriptsController`, `Gpo\NetworkOutController`, `Gpo\VeyonOutController`, `Gpo\AssociationsOutController`, `OverlayController` (entier — n'a que `apiV1`), `Api\v1\ShortcutExportController` (entier), `AppPolicyController` (entier — toutes ses méthodes sont legacy) ;
- les **méthodes** legacy `WallpaperController::legacyOut` + `WallpaperController::apiV1` **uniquement** (les méthodes `thumbnail`/`assetThumbnail` survivent — consommées par l'UI admin) ;
- les services resolver/generators du canal legacy : `WorkstationConfigContextResolver`, `Dto\Gpo\WorkstationConfigContext`, `NetworkScriptGenerator`, `VeyonConfigGenerator`, `AssociationsResolver`, `PackagesXmlAssociationsReader`, `LegacyConfigBridge` (code mort) ;
- le service de publication de templates GPO de config : `GpoPublisher` (code mort runtime — résiduel #13 de 27.5) et les actions « Publier (SYSVOL/étage 2) » des pages Livewire admin GPO ;
- les shims `/gpo/*` : routes `gpo/{shortcuts,wallpaper,firefox,thunderbird,network,veyon}_out.php`, `gpo/associations_out.php`, `gpo/applications.php` ET les modules legacy correspondants `legacy/modules/gpo/{applications,associations_out,network_out,veyon_out,gestion_gpo,gpo-maj,gpo-export,wine}.php` ;
- le canal `/api/v1/workstation-config/*` (les 9 routes `agent.v1.config.*`) et `/v1/shortcuts/export/{script,file,icon}` (les routes overlay/agent ayant déjà migré vers `OverlayStateProvider`/`/v1/agent/*`) ;
- les templates GPO serveur **hors bootstrap** publiés via ce canal (cf. Dev Notes : ne pas supprimer le template `se4_agent_bootstrap`).

**And** les **services métier partagés** sont **PRÉSERVÉS** car consommés par les StateProviders agent : `AppCustomizationService` (← `AppConfigStateProvider`), `ShortcutCompilerService`/`WindowsLnkGenerator` (← chaîne raccourcis), `WallpaperResolver`/`WallpaperComposer` (← `WallpaperStateProvider`), `OverlayService` (← `OverlayStateProvider`), `GpoService`/`GpoTemplateRegistry`/`NativeSectionResolver` (lecture AD générique + reconnaissance du bootstrap). **Aucune écriture métier supprimée** (frontière données : les StateProviders sont lecture seule).

### AC2bis — Seule la GPO-dispatcher bootstrap (25.4) survit, figée

**Given** l'extinction exécutée
**Then** le template `resources/gpo/se4_agent_bootstrap/` (GPT.INI + `Machine/Scripts/Startup/startup.cmd` + `scripts.ini`) est **intact, octet pour octet** — jamais ré-édité.
**And** `GpoTemplateRegistry::isPublishable('se4_agent_bootstrap')` continue de renvoyer `true` (le bootstrap reste reconnu/publiable).
**And** le test `tests/Unit/Gpo/Se4AgentBootstrapTemplateTest.php` reste vert.
**And** le bootstrap ne dépend que du canal agent natif (`/api/v1/agent/ca`, `/api/v1/agent/stable/download`) — aucune des routes/services supprimés.

### AC3 — Audit post-extinction : KPI 0 GPO hors bootstrap + plus aucune route legacy

**Given** l'audit post-extinction
**Then** **0 GPO créée ou modifiée hors bootstrap** (KPI du brief §153, audit SYSVOL — vérification opérateur en lab, non automatisable côté repo) ;
**And** aucune route legacy ne répond : `php artisan route:list` ne contient plus aucune route `gpo/*_out.php`, `gpo/applications.php`, `agent.v1.config.*`, `shortcuts.export.*` ;
**And** les tests du canal supprimé sont **retirés avec lui** (cf. Dev Notes : liste « SUPPRIMER » vs « AMPUTER » vs « AJUSTER ») et la suite de tests reste verte (`php artisan test`, validation par filtres ciblés — mémoire `project_vm_phpunit_bulk_run_false_failures`) ;
**And** le canal **Linux** (`/wpkg/linux_out.php`) et l'install OS Windows (`/wpkg/winget_out.php`) ne sont pas cassés par effet de bord (voir Risques — décision de scope à confirmer avant suppression).

### AC4 — Cartographie des éléments serveur (hors repo) + neutralisation réversible (cadrage Henri)

**Given** les répertoires serveur historiques hors repo (paquet `sambaedu` legacy [.deb + dépôt APT SE4], `/etc/sambaedu/`, `/var/unattended/`, `/usr/share/sambaedu/`, et tout chemin **ni servi par Apache ni utilisé par SE5**)
**When** la cartographie est menée (inspection **lecture seule** de la VM, cf. Risques R8)
**Then** chaque élément est classé dans **un** des 4 buckets et documenté (story + handoff) :
- **Bucket A** — plus nécessaire (ni à la migration SE4→SE5, ni au fonctionnement SE5) → **neutralisé de façon réversible** (déplacé/renommé, p.ex. suffixe `.disabled-27.14`), suppression sèche différée après observation ;
- **Bucket B** — indispensable à la migration SE4→SE5 mais **purgeable une fois la migration terminée** → cartographié + marqué « purgeable post-migration » (PAS supprimé ici) ;
- **Bucket C** — indispensable à la migration **ET** encore nécessaire post-migration → cartographié + **conservé** ;
- **Bucket D** — encore nécessaire à **SE5 pour fonctionner** (runtime app) → cartographié + **conservé** (et **documenté** si indéplaçable hors repo).

**And** la cible « repo SE5 quasi-autonome » est évaluée : pour chaque dépendance D hors repo, noter si elle peut être internalisée au repo ou si elle reste une dépendance serveur documentée.
**And** **aucune** neutralisation (move/rename) n'est appliquée sur la **VM** depuis le worktree : le dev produit le plan + les commandes, Henri valide, l'application se fait à la livraison sur main (cf. R8).

## Tasks / Subtasks

> **Ordre impératif** : T1 (gate) AVANT toute suppression. T2→T9 ne démarrent que si T1 est validé pour la cible concernée. Travail 100 % local (worktree git — interdiction VM/serveurs distants).

- [x] **T1 — GATE parité : remplir et valider la checklist de parité (AC1)**
  - [x] T1.1 Compléter la table « Checklist de parité » (Dev Notes) : pour chaque capacité, story SE5, statut, et preuve de démo réaliste (postes migrés + neufs, salles multiples).
  - [x] T1.2 Marquer chaque capacité `À PARITÉ` seulement si sa story est `done`. Les capacités dont la story est en `review` restent `BLOQUÉ` → leur code legacy ne sera PAS supprimé dans cette passe (séquençage, voir Risques).
  - [x] T1.3 **DÉCIDÉ (Henri Étape 2) : extinction TOTALE en mode réversible** (pas de partiel ; gate AC1 = validation de sortie documentée, pas blocage). Documenter la checklist comme preuve de sortie sans bloquer la suppression du code.
  - [x] T1.4 **DÉCIDÉ (Henri Étape 2) : `winget_out` + `linux_out` HORS SCOPE, conservés** (+ kill-switch gardé, cf. T7.3). Vérifier en fin de passe qu'ils répondent toujours.

- [x] **T2 — Supprimer les services de scripts applications legacy (AC2)**
  - [x] T2.1 Supprimer `app/Gpo/Services/ApplicationScriptsGenerator.php`, `ApplicationScriptsAssembler.php`, `ApplicationLoggerService.php`, `ApplicationTemplatesScanner.php`.
  - [x] T2.2 Retirer toute liaison container (AppServiceProvider/bindings) et tout `use` orphelin (grep des FQCN).
  - [x] T2.3 Vérifier que `LinuxOutController` ne dépend PAS de ces classes (seules des références en commentaire existent — ne pas casser le canal Linux).

- [x] **T3 — Supprimer les controllers du canal legacy (AC2)**
  - [x] T3.1 Supprimer en entier : `app/Http/Controllers/Gpo/ApplicationsScriptsController.php`, `Gpo/NetworkOutController.php`, `Gpo/VeyonOutController.php`, `Gpo/AssociationsOutController.php`, `OverlayController.php`, `Api/v1/ShortcutExportController.php`, `AppPolicyController.php`.
  - [x] T3.2 Dans `WallpaperController.php`, supprimer **uniquement** `legacyOut()` + `apiV1()` ; conserver `thumbnail()`, `assetThumbnail()`, le constructeur et leurs dépendances. Retirer les `use` devenus orphelins.

- [x] **T4 — Supprimer les resolver/generators du canal legacy (AC2)**
  - [x] T4.1 Supprimer `app/Gpo/Services/WorkstationConfigContextResolver.php`, `app/Dto/Gpo/WorkstationConfigContext.php`, `app/Gpo/Services/NetworkScriptGenerator.php`, `VeyonConfigGenerator.php`, `AssociationsResolver.php`, `PackagesXmlAssociationsReader.php`.
  - [x] T4.2 Supprimer `app/Config/LegacyConfigBridge.php` (code mort — aucun appelant) après confirmation grep.
  - [x] T4.3 PRÉSERVER explicitement : `AppCustomizationService`, `ShortcutCompilerService`/`WindowsLnkGenerator`, `WallpaperResolver`/`WallpaperComposer`, `OverlayService` (consommés par les StateProviders agent). Grep de non-régression : chaque StateProvider sous `app/Services/Agent/Providers/` doit encore résoudre ses dépendances.

- [x] **T5 — Supprimer la publication de templates GPO de config (AC2)**
  - [x] T5.1 Supprimer `app/Gpo/Services/GpoPublisher.php` (code mort runtime — seul un docblock le référence).
  - [x] T5.2 Retirer les actions « Publier (SYSVOL / étage 2) » et l'injection `GpoPublisher` des pages Livewire `resources/views/pages/admin/settings/gpo/index.blade.php` et `[guid]/index.blade.php` (boutons + méthodes `publish*`). Conserver la consultation read-only (listing, détail, liens, sections).
  - [x] T5.3 Qualifier `WpkgGpoSynchronizer` + `WpkgGpoSyncCommand` (`wpkg:gpo:sync`, publie `se4_wpkg`) : 27.5 a retiré le déclencheur GPO de WPKG (l'agent déclenche `wpkg-client.vbs`). Si `se4_wpkg` n'est plus un transport actif → supprimer synchronizer + commande ; sinon documenter pourquoi il survit. **Décision à reporter dans le Dev Agent Record.**
  - [x] T5.4 Qualifier `AuditApplicationsGpoTemplateCommand` (`gpo:applications:audit`, audite `se4_applications.zip`) : si `se4_applications` est un template de config hors bootstrap → supprimer ; sinon justifier.

- [x] **T6 — Supprimer les shims `/gpo/*` + modules legacy (AC2)**
  - [x] T6.1 Dans `routes/web.php`, supprimer les routes `migration.legacy.{shortcuts,wallpaper,firefox,thunderbird,network,veyon,associations,applications}` (lignes ~601-681) ET le mécanisme `MigrationController::serveFragment` + `PASSTHROUGH_HANDLERS` (les fragments réinjectaient la conf legacy aux postes migrés — c'est le cœur du canal à éteindre). Conserver `MigrationController` uniquement si d'autres routes le consomment ; sinon supprimer. **Vérifier l'impact migration SE4→SE5 (voir Risques R2).**
  - [x] T6.2 Supprimer les modules `legacy/modules/gpo/{applications,associations_out,network_out,veyon_out,gestion_gpo,gpo-maj,gpo-export,wine}.php`. Utiliser `trash` (jamais `rm -rf`) sur l'hôte ; **NE PAS** sync la VM (worktree). Noter : inotify ne propage pas les deletes (mémoire `project_inotify_no_delete_sync`).
  - [x] T6.3 Conserver les redirections 301 `/app/gpo/*` → `/admin/settings/gpo/*` (consultation native) — ce ne sont pas du canal de config.

- [x] **T7 — Supprimer `/api/v1/workstation-config/*` + `/v1/shortcuts/export/*` (AC2)**
  - [x] T7.1 Dans `routes/api.php`, supprimer le groupe `Route::prefix('v1/workstation-config')` (9 routes `agent.v1.config.*`, lignes ~396-409) et le groupe `Route::prefix('v1/shortcuts/export')` (lignes ~411-418).
  - [x] T7.2 Retirer les `use` orphelins des controllers supprimés en tête de `routes/api.php` et `routes/web.php`.
  - [x] T7.3 **DÉCIDÉ (Henri Étape 2) : GARDER** le middleware `legacy.config.channel` + flag `LEGACY_CONFIG_CHANNEL_ENABLED` + clé `config/sambaedu.php` + ligne `.env.example` — ils protègent encore `winget_out`/`linux_out` (hors scope). Vérifier qu'après suppression des routes config gatées, le middleware ne s'applique plus qu'aux routes Linux/winget restantes et reste fonctionnel.

- [x] **T8 — Retirer/amender les tests du canal supprimé (AC3)**
  - [x] T8.1 **SUPPRIMER** (purement legacy) : tous les `tests/Feature/Api/V1/Config/*ApiV1Test.php` + `ApiV1ConfigSecurityTest.php` ; `tests/Feature/Gpo/{ApplicationsScriptsEndpoint,NetworkOutEndpoint,VeyonOutEndpoint,AssociationsOutEndpoint,ApplicationsScriptsComparison,AssociationsOutComparison,ApplicationsScriptsSecurity,NetworkOutSecurity,AssociationsOutRouteRegistration,NetworkVeyonRouteRegistration,LegacyGestionGpoRedirect}Test.php` ; `tests/Feature/Wallpaper/LegacyOutEndpointTest.php` ; `tests/Feature/AppCustomization/AppPolicyLegacyEndpointTest.php` ; `tests/Feature/Legacy/{LegacyModuleGpoGestion,LegacyModuleGpoOutputs}Test.php`.
  - [x] T8.2 **SUPPRIMER** les tests unitaires des services supprimés : `tests/Unit/Gpo/{ApplicationScriptsGenerator,ApplicationScriptsAssembler,ApplicationTemplatesScanner,ApplicationLoggerService,NetworkScriptGenerator,VeyonConfigGenerator,AssociationsResolver}Test.php`.
  - [x] T8.3 **AMPUTER** (garder la structure, retirer les cas legacy/`_out.php`) : `tests/Architecture/ApiV1ConfigRoutesTest.php` (retirer les assertions sur les 9 routes `agent.v1.config.*` disparues) ; `tests/Architecture/GpoLegacyIsolationTest.php` ; les tests de parité byte/critical (`ApplicationsScriptsByteParityTest`, `ApplicationsScriptsCriticalParityTest`, `NetworkOutComparisonTest`, `VeyonOutComparisonTest`, `ShortcutExportComparisonTest`) — vérifier qu'aucun ne postule un endpoint supprimé ; si la cible legacy disparaît, supprimer plutôt qu'amputer.
  - [x] T8.4 **GARDER intacts** : `tests/Unit/Gpo/{GpoService,GpoServiceWrite,WpkgGpoSynchronizer}Test.php` (sauf décision T5.3), `tests/Unit/Gpo/Se4AgentBootstrapTemplateTest.php` (bootstrap — DOIT rester vert), `tests/Architecture/{WpkgOutRoutes,IpxeNamespace,ScriptsOsNamespace,GpoNamespace}Test.php` (garde-fous d'ordre/namespace — ajuster si une assertion vise une route supprimée), `tests/Feature/Gpo/LegacyAppGpoRoutesRedirectTest.php` (redirections 301).
  - [x] T8.5 Vérifier les pages Livewire admin GPO (`GpoDetailPublishTest`, `GpoIndexPublishTest` etc.) : retirer les assertions sur le bouton « Publier » supprimé en T5.2 ; garder les tests de consultation.

- [x] **T9 — Audit final + KPI (AC3)**
  - [x] T9.1 `php artisan route:list` : confirmer 0 route `gpo/*_out.php`, `gpo/applications.php`, `agent.v1.config.*`, `shortcuts.export.*`.
  - [x] T9.2 Lancer la suite de tests par filtres ciblés (jamais un run massif — faux échecs) : suites Agent, Gpo, Api/V1, Wallpaper, AppCustomization, Architecture. Zéro régression.
  - [x] T9.3 Confirmer `se4_agent_bootstrap` intact (diff vide) et `isPublishable` vert.
  - [x] T9.4 Documenter le checklist KPI « 0 GPO hors bootstrap » comme vérification opérateur (audit SYSVOL en lab) — non automatisable depuis le repo, à porter au handoff.
  - [x] T9.5 Mettre à jour le commentaire daté dans `sprint-status.yaml` à la clôture.

- [x] **T10 — Cartographier les éléments serveur hors repo + neutralisation réversible (AC4)**
  > Peut démarrer en parallèle de T1 (lecture seule + planification, ne supprime rien d'irréversible). L'**application** des neutralisations est hors worktree (R8).
  - [x] T10.1 Inventorier (inspection **lecture seule** VM, après accord Henri — R8) : paquet `sambaedu` legacy (`dpkg -L sambaedu` + dépôt APT SE4), `/etc/sambaedu/`, `/var/unattended/`, `/usr/share/sambaedu/`, parties non-SE5 de `/var/www/`, et tout chemin ni servi par Apache (`apache2ctl -S` / vhosts) ni référencé par SE5 (grep config/repo).
  - [x] T10.2 Classer chaque élément dans un bucket A/B/C/D (cf. AC4) dans la table « Cartographie serveur » (Dev Notes), en confirmant/infirmant la classification présumée.
  - [x] T10.3 **Bucket A** : proposer la neutralisation **réversible** (déplacement/renommage `*.disabled-27.14`). NE PAS appliquer sur la VM depuis le worktree — produire le plan + les commandes à valider par Henri (application à la livraison sur main).
  - [x] T10.4 **Bucket D** indéplaçables : documenter la dépendance (quoi/pourquoi/où) au handoff + évaluer l'internalisation au repo (cible « SE5 autonome »).
  - [x] T10.5 Croiser avec le paquet `sambaedu` legacy : confirmer/affiner la direction « SE5 autonome = retirer deb + dépôt SE4 » (mémoire `project_windows_install_helpers_oem_staging`) — vérifier qu'aucun poste neuf/migré n'en dépend encore.

## Dev Notes

### Le geste de cette story (à ne pas confondre)
Elle ne porte AUCUNE ressource au canal agent (déjà fait). Elle **retire le tuyau historique**. Pattern inverse des stories 27.1-27.5 : pas de provider/handler/golden à créer, mais des suppressions chirurgicales + un gate documentaire.

### Frontière critique : « route /api/v1/ » ≠ « canal agent »
Piège central confirmé par l'audit code. Le canal **agent** a ses propres StateProviders (`app/Services/Agent/Providers/` : `OverlayStateProvider`, `OverlayMachineStateProvider`, `WallpaperStateProvider`, `AppConfigStateProvider`, `ShortcutsStateProvider`, `PrintersStateProvider`, `ApplicationsStateProvider`…) servis sous `/api/v1/agent/*`. Le préfixe `/api/v1/workstation-config/*` est, lui, **100 % legacy** (controllers `apiV1`, Story 16.13) — il meurt en entier. Ne PAS « préserver `OverlayController::apiV1` parce qu'il est en /api/v1/ » : l'overlay agent passe par `OverlayStateProvider` + `/v1/agent/overlay-skin`, pas par ce controller.

### Distinction services métier (GARDER) vs glue legacy (SUPPRIMER)
Les StateProviders agent **réutilisent en lecture** les services métier. Couper la glue legacy sans toucher au métier :
- `AppConfigStateProvider` → `AppCustomizationService::resolvePoliciesForMachine()` → **GARDER `AppCustomizationService`** ; supprimer `AppPolicyController`.
- chaîne raccourcis → `ShortcutCompilerService`/`WindowsLnkGenerator` → **GARDER** ; supprimer `ShortcutExportController`.
- `WallpaperStateProvider` → `WallpaperResolver`/`WallpaperComposer` → **GARDER** ; supprimer `WallpaperController::{legacyOut,apiV1}` (garder `thumbnail`/`assetThumbnail`).
- `OverlayStateProvider` → `OverlayService` → **GARDER** ; supprimer `OverlayController`.
- Le **builder** `WorkstationConfigContextResolver` (+ DTO `WorkstationConfigContext`) ne sert QUE les controllers `apiV1`/`legacyOut` → SUPPRIMER.

### Inventaire vérifié des cibles de suppression (chemins absolus)
Services scripts apps (legacy-only) :
- `app/Gpo/Services/ApplicationScriptsGenerator.php`
- `app/Gpo/Services/ApplicationScriptsAssembler.php`
- `app/Gpo/Services/ApplicationLoggerService.php`
- `app/Gpo/Services/ApplicationTemplatesScanner.php`

Controllers legacy (entiers) :
- `app/Http/Controllers/Gpo/ApplicationsScriptsController.php`
- `app/Http/Controllers/Gpo/NetworkOutController.php`
- `app/Http/Controllers/Gpo/VeyonOutController.php`
- `app/Http/Controllers/Gpo/AssociationsOutController.php`
- `app/Http/Controllers/OverlayController.php`
- `app/Http/Controllers/Api/v1/ShortcutExportController.php`
- `app/Http/Controllers/AppPolicyController.php`

Controller MIXTE (amputer) :
- `app/Http/Controllers/WallpaperController.php` → retirer `legacyOut()` (l.45) + `apiV1()` (l.117) ; garder `thumbnail()` (l.202), `assetThumbnail()` (l.244).

Resolver/generators/bridge legacy :
- `app/Gpo/Services/WorkstationConfigContextResolver.php`
- `app/Dto/Gpo/WorkstationConfigContext.php`
- `app/Gpo/Services/NetworkScriptGenerator.php`
- `app/Gpo/Services/VeyonConfigGenerator.php`
- `app/Gpo/Services/AssociationsResolver.php`
- `app/Gpo/Services/PackagesXmlAssociationsReader.php`
- `app/Config/LegacyConfigBridge.php` (code mort)

Publication GPO de config :
- `app/Gpo/Services/GpoPublisher.php` (code mort runtime ; appelé seulement par les boutons « Publier » des vues Livewire admin GPO)
- Actions `publish*` dans `resources/views/pages/admin/settings/gpo/index.blade.php` (l.808, l.835) et `[guid]/index.blade.php` (l.295)

Shims `/gpo/*` (routes web.php ~601-681) + fragments migration :
- routes `migration.legacy.*` + `MigrationController::serveFragment` + `PASSTHROUGH_HANDLERS` (`app/Auth/V1/Migration/Http/Controllers/MigrationController.php` l.80-89)
- modules legacy `legacy/modules/gpo/{applications,associations_out,network_out,veyon_out,gestion_gpo,gpo-maj,gpo-export,wine}.php`

Routes API :
- groupe `Route::prefix('v1/workstation-config')` (`routes/api.php` ~396-409, 9 routes `agent.v1.config.*`)
- groupe `Route::prefix('v1/shortcuts/export')` (`routes/api.php` ~411-418)

Kill-switch (décision T7.3) :
- `app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php` + alias `app/Http/Kernel.php` l.87
- `config/sambaedu.php` l.41 (`legacy_config_channel_enabled`)
- `.env.example` l.39

### Ce qui SURVIT, intouché (garde-fous explicites)
- **Bootstrap 25.4** : `resources/gpo/se4_agent_bootstrap/` (GPT.INI + `Machine/Scripts/Startup/startup.cmd` + `scripts.ini`) — le `startup.cmd` est le dernier artefact AD : déploie la CA (`/api/v1/agent/ca`), télécharge le binaire stable (`/api/v1/agent/stable/download`), `agent.exe install` (idempotent, filet #27), recrée la tâche de refresh. **Aucune logique métier, jamais ré-édité.** Test `tests/Unit/Gpo/Se4AgentBootstrapTemplateTest.php`.
- **Reconnaissance bootstrap** : `GpoTemplateRegistry::isPublishable()` doit rester (le bootstrap en dépend). `GpoService` (samba-tool list/show/links — lecture AD), `NativeSectionResolver` : génériques, à garder.
- **Services métier** : voir distinction ci-dessus.
- **Canal Linux & install Windows** : `app/Wpkg/Deployment/Http/Controllers/LinuxOutController.php` + `WingetOutController.php`, routes `/wpkg/linux_out.php` + `/wpkg/winget_out.php` (web.php ~739-753). **Autonomes** (les mentions `ApplicationScriptsGenerator` y sont en commentaire, pas en `use`). Voir Risques R3.
- **Redirections 301** `/app/gpo/*` → `/admin/settings/gpo/*` (web.php ~294-334) : consultation, pas du canal config.
- **Canal agent** `/api/v1/agent/*` : indépendant, hors-scope total.

### Checklist de parité (à valider en T1 — gate AC1)

| Capacité legacy | Story SE5 (canal agent) | Statut | Verdict parité |
|---|---|---|---|
| Wallpapers + overlay | Epic 24 (24-4 superseded → **24-6** « agent Go compagnon handlers parité démo ») | `done` | À PARITÉ (sous réserve démo réaliste) |
| Raccourcis (bureau, nature de poste — Bug C) | **27-1** (+ 27-1bis/1ter overlay, **27-7** icônes uploadées) | `done` (27-1, 27-7) | À PARITÉ |
| Lecteurs réseau | **27-2** | `done` | À PARITÉ |
| Imprimantes (défaut = règle spécifique) | **27-2** | `done` | À PARITÉ |
| Associations de fichiers (UserChoice) | **27-3bis** (+ **27-11** composer extension/app) | `review`, `review` | **BLOQUÉ** (review) |
| Registre / capacités | **27-3** done ; **27-3ter** (défaut diffusé) `review` ; **27-12** (capability-first, supersede 27.3/3ter) `review` | mixte | **BLOQUÉ** (27-3ter/27-12 en review) |
| Config d'app (policies.json FF/TB, profils) | **27-4** | `review` | **BLOQUÉ** (review) |
| Applications (déclenche WPKG) | **27-5** ; **27-6** catalogue source unique `review` | `done` (27-5), `review` (27-6) | À PARITÉ pour 27-5 ; catalogue 27-6 en review |
| Ciblage par critères (mailles parc/groupes/poste, nature) | **23-4** StateCompiler/mailles ; **26-1** WorkstationEnvironment | `done` (23-4), `review` (26-1) | **BLOQUÉ** (26-1 en review — la nature de poste pilote le placement) |
| Réveil agent au logon (cycle desired-state) | **27-9** | `done` | À PARITÉ (support) |

> **À la création (2026-06-19)** : 9 stories de parité `done`, 7 en `review` (27-3bis, 27-3ter, 27-4, 27-6, 27-10, 27-11, 27-12) + dépendances 26-1/25.x `review`. **Le gate AC1 n'est PAS franchi tant que ces reviews ne sont pas closes** (voir Dépendances + Risques R1).

> **Gate AC1 — résolution dev (2026-06-19, T1.1/T1.2/T1.3, décision Henri R1)** : extinction **TOTALE en mode réversible**. Le gate de parité est une **validation de sortie documentée** (cette table), PAS un blocage : les capacités `À PARITÉ` (wallpapers/overlay, raccourcis, lecteurs, imprimantes, applications 27-5, ciblage 23-4, réveil 27-9) sont démontrées sur le canal agent ; les capacités `BLOQUÉ` (associations 27-3bis/27-11, registre 27-3ter/27-12, config d'app 27-4, catalogue 27-6, nature 26-1) restent en `review` — leur code legacy part **quand même** (réversibilité par git/move-rename, rien d'irréversible). La **validation de parité réelle en environnement** (postes migrés + neufs, salles multiples) reste un geste **opérateur de pré-prod** (QA `docs/qa/domains/gpo.md` §27.14-0) — non automatisable depuis le repo, à dérouler avant tag prod. Les reviews ouvertes doivent être closes côté process AVANT la bascule prod ; elles ne bloquent plus la suppression du code dans cette passe.

### Cartographie filesystem serveur — axe Henri (2026-06-19)

L'extinction ne se limite pas au repo : une partie du legacy vit **sur le serveur (VM), hors repo**. Principe **réversible** (déplacer/renommer d'abord) et cible **repo SE5 autonome**. Le dev produit l'inventaire RÉEL par inspection **lecture seule** VM (R8) ; la table ci-dessous donne les **répertoires candidats** + une **classification présumée** (à confirmer en T10, **NON autoritaire**) :

| Élément serveur (hors repo) | Bucket présumé | Note |
|---|---|---|
| Paquet `sambaedu` legacy (.deb) + dépôt APT SE4 | A→B | helpers client/.ps1 ; direction « SE5 autonome = retirer deb+dépôt SE4 » (`project_windows_install_helpers_oem_staging`) — confirmer qu'aucun poste neuf/migré n'en dépend |
| `/usr/share/sambaedu/gpo/` (templates GPO JSON) | A (sauf bootstrap) | templates de config legacy ; seul `se4_agent_bootstrap` survit (exception `project_storage_convention_non_versioned`) |
| `/etc/sambaedu/` — `default.xml`, `applications/shortcuts/*.ico` | A/B | icônes name-addressed superseded par 27-7 (storage content-addressed) ; `default.xml` lu par le SEEDER associations 27-3bis (geste one-shot, pas runtime) → B |
| `/etc/sambaedu/www-sambaedu.keytab` (+ krb5) | D | écriture SYSVOL Kerberos www-admin pour publier le bootstrap (`project_sysvol_write_needs_wwwadmin_kinit`) — runtime SE5, conserver |
| `/var/unattended/` (unattend Windows) | C/D | install OS Windows neuf (lié à `winget_out`, R3) — conserver tant que l'install n'est pas internalisée |
| SYSVOL, partages WPKG, `/os`, `/ipxe` (servis Apache/Samba) | D | client-facing, runtime SE5 (exceptions storage convention) — conserver |

> Classification **à valider en T10** par inspection réelle. Les buckets A déclenchent une neutralisation **réversible** (renommage `*.disabled-27.14`), JAMAIS appliquée sur la VM depuis le worktree sans accord Henri (plan livré, application à la mise sur main).

#### Cartographie serveur RÉELLE (T10 — inspection SSH lecture seule VM `192.168.122.50`, 2026-06-19)

> Méthode : `dpkg -l`, `dpkg -L`, `ls`/`find`, `apache2ctl -S`, lecture vhosts + croisement avec `grep` des chemins dans `config/` + `app/` du repo SE5. AUCUNE mutation VM.

**Faits VM confirmés** :
- La VM héberge **DEUX** docroots : `/var/www/sambaedu` (SE4 legacy complet, servi UNIQUEMENT sur `127.0.0.1:8082` = vhost `sambaedu-legacy.conf`, interne, proxy catchall) et `/var/www/sambaedu-reload` (SE5, port 80 = vhost `sambaedu.conf`).
- Suite de paquets Debian `sambaedu-*` (v4.17.695) : `sambaedu` (méta, 7 fichiers doc), `sambaedu-config`, `sambaedu-web-common`, `sambaedu-client-windows`, `sambaedu-client-linux`, `sambaedu-wpkg`, `sambaedu-ipxe`, `sambaedu-ad-dc/client`, `sambaedu-boot-server`, `sambaedu-php-libs`, `sambaedu-shares`, `sambaedu-winbind`, `sambaedu-proxy-config`. Dépôt APT SE4 actif : `/etc/apt/sources.list.d/se4.list` → `deb http://deb.sambaedu.org/debian bookworm se4XP`.
- `/usr/share/sambaedu/gpo/` : `etab_Bureau.zip` + `sambaedu-gpo/` (20 templates `se4_*` legacy : Bureau, Wallpaper, applications, wpkg, impression, lecteurs reseau, redirections, optimisations, windows-update-ON, etc.). **`se4_wpkg.zip` et `se4_applications.zip` ABSENTS** (cohérent 27.5 + suppressions T5.3/T5.4).
- `/etc/sambaedu/` : `sambaedu.conf` (lu par SE5 `SambaEduConfig`), `reservations.inc` (DHCP, lu SE5), `www-sambaedu.keytab` (Kerberos), `applications/{shortcuts,firefox,thunderbird,veyon,wallpaper,winget}` + `gpos.json` (versions GPO legacy). 28 `.ico` + 30 `.png` sous `applications/shortcuts/`. **Pas de `default.xml`** sous `/etc/sambaedu/applications/` (le seeder 27-3bis le cherche aussi sous `/usr/share/sambaedu/applications/associations/`).
- `/var/sambaedu/unattended/install/` (PAS `/var/unattended/`) : assets iPXE OS, partage WPKG (`packages.xml`, `wpkg-client.vbs`, `ini/`, `rapports/`, `tools/`), wine. **Activement référencé par `config/sambaedu.php` + `config/ipxe.php` SE5** (IPXE_OS_ASSETS_ROOT, WPKG_STORAGE_PATH, wine prefix_base, install-win-iso.sh).
- vhost SE5 (port 80) **aliase `/ipxe` → `/var/www/sambaedu/ipxe`** (legacy) et **`/os` n'est aliasé que sur le vhost legacy 8082** ; `XSendFilePath /var/sambaedu/unattended/install/os`. `/wpkg/bundle`, `/assets/shortcut-icons`, `/assets/wallpaper` aliasés vers `storage/app/*` SE5.

| Élément serveur (hors repo) | Bucket CONFIRMÉ | Justification (croisement VM ↔ repo SE5) |
|---|---|---|
| Paquet méta `sambaedu` + suite `sambaedu-config/web-common/php-libs/shares/winbind/proxy-config` | **C/D** | infra serveur (apache/php-fpm/samba/AD) sur laquelle SE5 tourne ; pas du canal de config poste. `sambaedu-config` fournit `sambaedu.conf`/`make_dhcpd_conf.sh`/`install-win-iso.sh` lus par SE5 (D). À conserver tant que non internalisés. Internalisation au repo = gros chantier hors 27.14. |
| Paquet `sambaedu-client-windows` / `-client-linux` (helpers `.ps1`, deb client) + dépôt APT `se4.list` | **A→B** | direction « SE5 autonome = retirer deb+dépôt SE4 » (`project_windows_install_helpers_oem_staging`). Purgeable une fois l'install poste internalisée SE5 ($OEM$ staging) ; conserver tant que des postes neufs/migrés en dépendent → **vérification opérateur requise** avant purge. |
| `/usr/share/sambaedu/gpo/sambaedu-gpo/se4_*` (20 templates config GPO legacy) + `etab_Bureau.zip` | **A** (sauf bootstrap) | templates de config legacy publiés par l'ex-canal (GpoPublisher/WpkgGpoSynchronizer supprimés). Plus aucun consommateur SE5 runtime après 27.14. `se4_agent_bootstrap` est dans le REPO (`resources/gpo/`), PAS ici → survit indépendamment. **Bucket A = neutraliser réversible.** |
| `/etc/sambaedu/applications/{firefox,thunderbird,veyon,winget,wallpaper}` + `gpos.json` | **A/D mixte** | `winget/{add,remove}.json` + `wallpaper/` lus par config SE5 (winget_out hors-scope, wallpaper biblio) → **D**. `firefox/thunderbird/veyon/*.json` + `gpos.json` = surcharges du canal config legacy (consommés par ApplicationScriptsAssembler/AppPolicyController supprimés) → **A**. |
| `/etc/sambaedu/applications/shortcuts/*.{ico,png}` | **A→B** | name-addressed, superseded par 27-7 (content-addressed `storage/app/shortcut-icons`). MAIS `ShortcutCompilerService`/`ShortcutsService`/`ImageManagerService` SE5 lisent encore ce chemin (`config('shortcut_icons.legacy_path')`) + route `shortcuts.icon` (web.php, gardée) → **B purgeable après bascule 27-7 complète**, pas A pur. |
| `/etc/sambaedu/{sambaedu.conf,reservations.inc,hashes,www-sambaedu.keytab,id_rsa,sambaedu-*.pem}` | **D** | runtime SE5 : `sambaedu.conf` (SambaEduConfig), `reservations.inc` (DHCP), `hashes` (se4install), keytab (écriture SYSVOL Kerberos bootstrap). **Conserver.** |
| `/var/sambaedu/unattended/install/` (iPXE OS, WPKG share, wine) | **D** | référencé activement par `config/sambaedu.php` + `config/ipxe.php` (IPXE_OS_ASSETS_ROOT, WPKG_STORAGE_PATH/deploy_path/ini, wine). Runtime SE5 client-facing. **Conserver** (indéplaçable hors repo — partage SMB + assets volumineux). |
| `/var/www/sambaedu/ipxe` (aliasé `/ipxe` par vhost SE5) | **D** | le vhost SE5 sert les assets iPXE statiques volumineux DEPUIS le legacy + FallbackResource → Laravel. Dépendance runtime SE5 documentée (indéplaçable sans migrer les assets). **Conserver.** |
| `/var/www/sambaedu` (SE4 legacy, vhost 8082 interne) hors `ipxe/` | **A→B** | SE4 PHP complet servi en interne (catchall proxy). Plus aucun canal de config poste actif côté SE5 après 27.14. Purgeable progressivement (sauf `ipxe/` = D ci-dessus, `central/api2/setup` = controlHub/migration éventuelle). **Vérification opérateur.** |
| SYSVOL (`/var/lib/samba/sysvol`), partages WPKG/SMB, `/os` | **D** | client-facing runtime SE5 (bootstrap publié, WPKG, install OS). **Conserver.** |

**Plan de neutralisation Bucket A (réversible — À VALIDER PAR HENRI, à appliquer sur `main`/VM HORS worktree, jamais depuis ce worktree)** :

```bash
# Sur la VM (après accord Henri) — renommage réversible, suppression sèche différée après observation.
# 1. Templates GPO de config legacy (le bootstrap est dans le REPO, pas ici → non touché) :
mv /usr/share/sambaedu/gpo/sambaedu-gpo /usr/share/sambaedu/gpo/sambaedu-gpo.disabled-27.14
mv /usr/share/sambaedu/gpo/etab_Bureau.zip /usr/share/sambaedu/gpo/etab_Bureau.zip.disabled-27.14
# 2. Surcharges config app legacy (firefox/thunderbird/veyon/gpos.json) — PAS winget/ (hors-scope) ni wallpaper/ (biblio) :
mv /etc/sambaedu/applications/firefox      /etc/sambaedu/applications/firefox.disabled-27.14
mv /etc/sambaedu/applications/thunderbird  /etc/sambaedu/applications/thunderbird.disabled-27.14
mv /etc/sambaedu/applications/veyon        /etc/sambaedu/applications/veyon.disabled-27.14
mv /etc/sambaedu/applications/gpos.json    /etc/sambaedu/applications/gpos.json.disabled-27.14
# 3. NE PAS toucher (Bucket B — purge différée après vérif opérateur) : paquets sambaedu-client-*, dépôt se4.list,
#    /etc/sambaedu/applications/shortcuts/ (encore lu par ShortcutCompilerService tant que 27-7 pas 100% bascule),
#    /var/www/sambaedu (sauf décision dédiée — héberge ipxe/ = D).
# Observation ~1-2 semaines (logs apache legacy 8082, conformité agent) → suppression sèche si zéro régression.
```

> **Fantômes inotify (R7)** : les fichiers de code supprimés dans le worktree ne se propagent PAS à la VM. Après merge `main`, les fichiers `legacy/modules/gpo/*.php`, `app/Gpo/Services/*`, `app/Auth/V1/Migration/*` supprimés peuvent subsister côté VM (`/var/www/sambaedu-reload/...`) — nettoyage SSH à valider avec l'opérateur, jamais depuis un worktree.

> **Cible « repo SE5 quasi-autonome »** : atteinte côté code (canal config poste = agent seul). Dépendances serveur **D indéplaçables documentées** : `sambaedu.conf` + suite paquets infra (`sambaedu-config/web-common`), `/var/sambaedu/unattended/install/` (assets/WPKG/wine), `/var/www/sambaedu/ipxe` (aliasé), SYSVOL/keytab. Internalisation au repo = chantiers ultérieurs (install poste $OEM$, assets iPXE versionnés) hors 27.14.

### Conventions transverses (rappel)
- Worktree git : **aucune** interaction VM/serveurs. Tout en local. Suppressions via `trash`, jamais `rm -rf`.
- Tests : valider par **filtres ciblés**, jamais un run massif (mémoire `project_vm_phpunit_bulk_run_false_failures`).
- Racine = projet Laravel (artisan/app à la racine, pas de `laravel/`).
- Frontière données : les StateProviders sont lecture seule — cette story ne supprime AUCUNE écriture métier ni table.

### Project Structure Notes
- Suppressions concentrées sous `app/Gpo/`, `app/Http/Controllers/{Gpo,Api/v1}/`, `routes/{web,api}.php`, `legacy/modules/gpo/`, `tests/{Feature,Unit,Architecture}/`.
- Aucune migration de base. Aucune nouvelle dépendance. Aucun changement de schéma.
- Variance assumée : extinction potentiellement **partielle** si le gate AC1 n'est franchi que pour les capacités `done` (séquençage décidé en T1.3).
- **Axe cartographie serveur (AC4/T10)** : une partie du livrable est documentaire (inventaire VM hors repo en 4 buckets A/B/C/D) + neutralisation **réversible** des éléments morts ; cible = repo SE5 quasi-autonome. Aucune mutation VM depuis le worktree.

### References
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 27.6 (lignes 774-793)] — définition canonique + 3 blocs Given/When/Then.
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Epic 27 (lignes 679-681)] — « le canal legacy meurt en bloc » + pattern.
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 25.4 (lignes 580-598)] — GPO-dispatcher bootstrap, dernier artefact AD.
- [Source: _bmad-output/planning-artifacts/product-brief-agent-desired-state-2026-06-11.md (lignes 140-142, 153)] — paliers 2/3, KPI « 0 GPO créée/modifiée hors bootstrap | Audit SYSVOL ».
- [Source: _bmad-output/planning-artifacts/architecture-agent-desired-state.md (lignes 594-642)] — frontière par canal, transition par ressource, bootstrap GPO « template serveur hors repo applicatif » (NB : dans CE repo le template existe sous `resources/gpo/se4_agent_bootstrap/`).
- [Source: app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php] — kill-switch 410, périmètre exact.
- [Source: app/Auth/V1/Migration/Http/Controllers/MigrationController.php#PASSTHROUGH_HANDLERS] — le passthrough qui réinjecte la conf legacy.

## Previous Story Intelligence
- **27-5** (`done`) : a déjà supprimé le shim WPKG `/wpkg/{hosts,profiles}.xml` (livraison native), retiré la publication `se4_wpkg` de la page wpkg-deployment, et noté `GpoPublisher::publish()` comme **code mort à supprimer en 27.6/27.14** (résiduel #13). Fichiers backup `resources/wpkg/*-original|.bak` à `trash` (résiduel #7).
- **27-2/27-1/27-3** (`done`) : confirment que les StateProviders lisent PG/tables métier en lecture seule, indépendamment des controllers legacy.
- **16.13 / 16.13bis** : ont créé `/api/v1/workstation-config/*` (méthodes `apiV1`) et transformé les `/gpo/*_out.php` en fragments de migration — c'est exactement ce que cette story démantèle.

## Testing Requirements
- Suite Pest/PHPUnit sous `tests/`. Valider par filtres ciblés (Agent, Gpo, Api/V1, Wallpaper, AppCustomization, Architecture).
- Garde-fous d'ordre de routes (`WpkgOutRoutesTest`, `IpxeNamespaceTest`, `ScriptsOsNamespaceTest`) : ajuster si une assertion vise une route supprimée, sinon garder.
- Le test bootstrap `Se4AgentBootstrapTemplateTest` doit rester vert (preuve que la survivance est intacte).
- KPI « 0 GPO hors bootstrap » = audit SYSVOL opérateur en lab (non automatisable repo) → porter au handoff.

## Dépendances
**Gate de parité (AC1) — la suppression dépend de la clôture de ces stories** :
- À PARITÉ (`done`) : 24-6, 27-1, 27-7, 27-2, 27-5, 27-9, 23-4, 27-3.
- BLOQUANT (`review` à la création 2026-06-19) : **27-3bis** (associations), **27-3ter** (registre défaut), **27-4** (config app), **27-6** (catalogue WPKG), **27-10** (overlay identité), **27-11** (associations composer), **27-12** (capacités registre), + **26-1** (WorkstationEnvironment — pilote le placement par nature de poste).
- Aucune dépendance technique inverse : rien d'autre ne consomme les artefacts supprimés (audit code confirmé).

## Risques

- **R1 (MAJEUR) — Gate parité vs 7 stories en review.** L'AC1 est un gate de prod qui suppose la parité validée, or 7 stories de parité (+ 26-1) sont en `review`, pas `done`. Supprimer le code legacy d'une capacité dont la story n'est pas close = perdre le filet sans certitude de remplacement. **Recommandation : séquencer.** Option A — attendre la clôture des reviews puis extinction totale. Option B — extinction partielle (ne supprimer que le code des capacités `done` ; laisser les autres derrière le kill-switch jusqu'à clôture). **RÉSOLU (Henri Étape 2, 2026-06-19) : extinction TOTALE en mode réversible** (git pour le code, move/rename pour la VM) ; le gate AC1 devient validation de sortie, pas blocage. Cf. Décisions de cadrage.
- **R2 — Suppression du passthrough migration.** `MigrationController::PASSTHROUGH_HANDLERS` réinjecte la conf legacy aux postes **migrés** SE4→SE5 pendant la transition. Le supprimer suppose que tout poste migré reçoit désormais sa conf par l'agent (bootstrap 25.4 → enrôlement → convergence). À confirmer avec le statut réel du parc migré avant de couper (sinon un poste migré sans agent vivant perd sa conf). Le kill-switch montre déjà le comportement cible (fragment no-op) — la suppression rend ce no-op permanent.
- **R3 — Canal Linux & install Windows.** `linux_out.php` (config-as-code Linux ; l'agent Linux est post-MVP, mémoire `project_linux_no_gpo_http_scripts`) et `winget_out.php` (install OS Windows neuf, `install.ps1`) sont gatés par `legacy.config.channel` mais l'epic les notait « INTOUCHÉS → 27.6 ». Les supprimer casserait le canal Linux (pas de remplaçant agent) et l'install Windows. **RÉSOLU (Henri Étape 2) : `linux_out` + `winget_out` exclus du scope, conservés + flag kill-switch gardé** (cf. T7.3). Cf. Décisions de cadrage.
- **R4 — Code mort vs vraies dépendances Livewire.** `GpoPublisher` est appelé depuis les vues Livewire admin GPO (non capté par un grep PHP simple) — vérifier exhaustivement les `resources/views/` avant suppression (fait : seules les 2 pages GPO l'utilisent).
- **R5 — `WpkgGpoSynchronizer`/`se4_wpkg` ambigu.** Encore référencé par `wpkg:gpo:sync` mais le déclencheur GPO WPKG a été retiré en 27.5. Statut transport actif à confirmer (T5.3) avant suppression.
- **R6 — Faux échecs de tests.** Un run massif fera échouer des classes indépendantes. Valider par filtres ciblés uniquement.
- **R7 — inotify deletes.** Les suppressions de fichiers ne se propagent pas à la VM ; des fantômes peuvent subsister côté VM. Ne pas nettoyer la VM depuis le worktree — signaler à Henri (mémoire `project_inotify_no_delete_sync`).
- **R8 — Cartographie VM depuis un worktree (AC4/T10).** Inspecter les répertoires serveur (`/etc/sambaedu`, `/var/unattended`, `/usr/share/sambaedu`, paquet `sambaedu`…) exige un accès VM. Le CLAUDE.md interdit toute interaction VM depuis un worktree « sauf contre-indication explicite » : Henri l'a donnée pour l'**inspection lecture seule** (2026-06-19). L'**application** d'une neutralisation (move/rename) sur la VM reste **hors worktree** : produire le plan + commandes, faire valider, appliquer à la livraison sur main. Ne jamais muter la VM depuis le worktree.

## Change Log

| Date | Auteur | Changement |
|---|---|---|
| 2026-06-19 | DEV (opus claude-opus-4-8[1m]) | Extinction TOTALE réversible du canal de config legacy : 95 fichiers supprimés (27 classes app + module Auth\V1\Migration fragment + 8 modules legacy/gpo + 51 tests + 4 vues fragment) + 17 amputés (routes, WallpaperController, config, 3 vues Livewire GPO, providers, 6 tests). Décisions T5.3/T5.4/R2/R5 (suppressions) + déviations PackagesXmlAssociationsReader/LegacyConfigBridge (préservés). Bootstrap 25.4 + kill-switch + linux_out/winget_out conservés. route:list 206→181. Cartographie serveur T10 + plan Bucket A. QA gpo.md §27.14. Status → review. |

## Dev Agent Record

### Agent Model Used

opus claude-opus-4-8[1m]

### Debug Log References

- Environnement hôte : `composer install --ignore-platform-req=ext-apcu --ignore-platform-req=ext-imagick` OK ; `bootstrap/cache/` créé ; `.env` hôte minimal (SQLite, CACHE_DRIVER=array, sans `LEGACY_CONFIG_CHANNEL_ENABLED` override). Stub Vite manifest `public/build/manifest.json` (gitignored) créé pour les tests de rendu full-page.
- `php artisan route:list` : **206 → 181 routes** (25 routes legacy de config supprimées).
- Validation par **filtres ciblés** (jamais run massif — `project_vm_phpunit_bulk_run_false_failures`).

### Completion Notes List

**Geste** : suppression chirurgicale du canal de config legacy des postes (extinction TOTALE réversible, décision Henri R1). Aucune ressource portée au canal agent (déjà fait 27.1→27.5 + Epic 24).

**AC1 (gate parité)** : checklist documentée (validation de sortie, pas blocage). Démo réaliste = geste opérateur pré-prod (QA §27.14-0).

**AC2 (extinction)** : 27 classes app + 8 modules legacy/gpo + 4 vues fragment supprimés ; WallpaperController amputé (legacyOut+apiV1 retirés, thumbnail/assetThumbnail gardés, constructeur simplifié car deps devenues inutilisées) ; routes web.php (migration.legacy.* + app-policy.canonical) + api.php (workstation-config/* + shortcuts/export/*) retirées ; actions « Publier étage 2 » retirées des 3 vues Livewire GPO (index, [guid], wpkg-deployment) en conservant la consultation read-only + les réglages de déploiement 15.6. Services métier PRÉSERVÉS (AppCustomizationService, ShortcutCompilerService/WindowsLnkGenerator, WallpaperResolver/Composer, OverlayService, GpoService/GpoTemplateRegistry/NativeSectionResolver) — vérifié : aucun StateProvider ne dépend d'une classe supprimée.

**AC2bis (bootstrap)** : `resources/gpo/se4_agent_bootstrap/` diff VIDE, `Se4AgentBootstrapTemplateTest` 4 PASS, `isPublishable('se4_agent_bootstrap')` = true. `GpoTemplateRegistry` conservé (docblock mis à jour : publication supprimée, registre = reconnaissance de publiabilité).

**AC3 (audit)** : `route:list` = 0 route `gpo/*_out.php` / `gpo/applications.php` / `workstation-config/*` / `shortcuts/export/*` / `api/policies/*`. Tests supprimés avec le code. Suite verte par filtres ciblés (détail Debug Log). Kill-switch + `linux_out`/`winget_out` confirmés fonctionnels (linux_out 7 PASS ; 410 quand `LEGACY_CONFIG_CHANNEL_ENABLED=false` → middleware gate toujours actif). KPI « 0 GPO hors bootstrap » = audit SYSVOL opérateur (QA §27.14-4, non automatisable repo).

**AC4 (cartographie serveur)** : inventaire VM réel (SSH lecture seule) + classification A/B/C/D confirmée + plan de neutralisation Bucket A réversible (`*.disabled-27.14`) livré pour validation Henri — voir « Cartographie serveur RÉELLE (T10) ». AUCUNE mutation VM depuis le worktree.

**Décisions tranchées par le dev** :
- **T5.3 (WpkgGpoSynchronizer + WpkgGpoSyncCommand + DTO + Enum)** → **SUPPRIMÉS**. La GPO `se4_wpkg` n'est plus un transport actif (27.5 : l'agent déclenche `wpkg-client.vbs`, plus la GPO) ; `se4_wpkg.zip` ABSENT de la VM ; le seul consommateur runtime était la commande (supprimée) + l'audit read-only de la page wpkg-deployment (amputé, le reste = réglages 15.6 conservés). Toutes les autres références = docblocks. Binding `legacy.import_gpo` consommé uniquement par GpoPublisher + WpkgGpoSynchronizer (les deux supprimés).
- **T5.4 (AuditApplicationsGpoTemplateCommand)** → **SUPPRIMÉ**. Audite le template GPO de config `se4_applications` (legacy hors bootstrap), dont l'objet est de détecter les `.cmd` pointant vers `gpo/applications.php` (endpoint supprimé) ; `se4_applications.zip` ABSENT de la VM. Config associée (`gpo.applications_template`, `gpo.applications.substitutions`) retirée.
- **R2 (passthrough migration)** → `MigrationController` + le module fragment `App\Auth\V1\Migration` (renderer, status-checker, messages, exception, 4 vues fragment) **SUPPRIMÉS**. `serveFragment` était l'unique point d'entrée, atteint seulement par les routes `migration.legacy.*` (supprimées) ; il portait à la fois l'enrôlement migration ET le passthrough de conf legacy (PASSTHROUGH_HANDLERS → controllers supprimés). Les modèles `WorkstationMigrationStatus`/`WorkstationMigrationAttempt` + `MigrationAttemptRecorder` (consommés par `EnrollController` du canal agent) sont CONSERVÉS — l'enrôlement agent reste intact. **Risque résiduel** : un poste migré sans agent vivant ne reçoit plus de fragment de réinjection — confirmer la couverture agent du parc migré avant bascule prod (le kill-switch montrait déjà ce comportement cible depuis 2026-06-12).
- **R5** = identique T5.3.

**Déviations vs liste de suppression de la story (pièges révélés par l'audit de code, justifiées)** :
- **`PackagesXmlAssociationsReader` → PRÉSERVÉ** (la story le listait en suppression T4.1). Grep prouve une consommation ACTIVE par le canal agent : `App\Services\Agent\Resolvers\AssociationResolver` (story 27.11, `new PackagesXmlAssociationsReader()`) + `database/seeders/FileAssociationSeeder` (27.3bis). Son docblock le qualifie de « geste d'administration admis, hors chemin desired-state ». Le supprimer casserait les associations du canal agent.
- **`LegacyConfigBridge` → PRÉSERVÉ** (la story le listait en suppression T4.2 « code mort »). Grep prouve : binding singleton `AppServiceProvider:76` + consommé par `SambaEduConfig::legacy()` (cœur de la config SE5, bridge LDAP/fonctions legacy SE4). Ce n'est PAS le canal de config des postes ; le supprimer casserait `SambaEduConfig`.

**Tests** : SUPPRIMÉS = tous les `tests/Feature/Api/V1/Config/*` + tests Gpo legacy (endpoint/comparison/security/route-registration) + tests Wallpaper/AppCustomization legacy + tests Legacy module GPO + tests unitaires des services supprimés + tests Migration fragment + `ApiV1ConfigRoutesTest` + `MigrationModuleArchitectureTest` + `WpkgDeploymentPageTest` + `GpoDetail/IndexPublishTest` + AppPolicyCanonical/Legacy + applications-scripts. AMPUTÉS = `GpoNamespaceTest` (whitelists shell/exec + 2 tests frontière WpkgGpoSynchronizer retirés), `CacheAbstractionArchitectureTest` (3 fichiers supprimés retirés du scope), `AuthV1NamespaceTest` (`legacy_out_routes_are_preserved` retiré), `ScriptsOsNamespaceTest` (assertion `workstation-config` retirée), `WpkgDeploymentSettingsPageTest` + `WpkgDeploymentPagePermissionTest` (mocks WpkgGpoSynchronizer + test audit retirés). GARDÉS intacts = `Se4AgentBootstrapTemplateTest`, `GpoService(Write)Test`, `WpkgOutRoutesTest`, `IpxeNamespaceTest`, `GpoNamespaceTest` (structure), `LegacyAppGpoRoutesRedirectTest`, `GpoIndex/DetailPageTest` (consultation), migration models test.

**Échecs résiduels = environnement hôte, PAS régressions 27.14** (confirmé par diff vide sur les fichiers concernés) : `IpxeNamespaceTest` (non-ASCII dans `resources/views/ipxe/windows/cmd/*` — pré-existant, commit « windows capabilities ») ; `GpoIndexExportTest::it_shows_advanced_filter_controls` (panneau `@include` commenté depuis 16.14 / commit daa3268) ; tests créant des WorkstationGroup → `WorkstationGroupObserver` dispatche un AD-sync qui throw (pas d'AD sur l'hôte) ; `WallpaperUploadServiceTest` (ext-imagick absent) ; rendu full-page (Vite — résolu par stub manifest).

### File List

**Supprimés — classes app (27)** :
- `app/Gpo/Services/ApplicationScriptsGenerator.php`, `ApplicationScriptsAssembler.php`, `ApplicationLoggerService.php`, `ApplicationTemplatesScanner.php`
- `app/Http/Controllers/Gpo/ApplicationsScriptsController.php`, `NetworkOutController.php`, `VeyonOutController.php`, `AssociationsOutController.php`
- `app/Http/Controllers/OverlayController.php`, `app/Http/Controllers/AppPolicyController.php`, `app/Http/Controllers/Api/v1/ShortcutExportController.php`
- `app/Gpo/Services/WorkstationConfigContextResolver.php`, `app/Dto/Gpo/WorkstationConfigContext.php`, `app/Gpo/Services/NetworkScriptGenerator.php`, `VeyonConfigGenerator.php`, `AssociationsResolver.php`
- `app/Gpo/Services/GpoPublisher.php`, `WpkgGpoSynchronizer.php` ; `app/Gpo/Dto/WpkgGpoSyncReport.php`, `app/Gpo/Enums/WpkgGpoSyncSeverity.php`
- `app/Wpkg/Deployment/Console/Commands/WpkgGpoSyncCommand.php`, `app/Console/Commands/AuditApplicationsGpoTemplateCommand.php`
- `app/Auth/V1/Migration/Http/Controllers/MigrationController.php`, `app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php`, `MigrationStatusChecker.php`, `app/Auth/V1/Migration/Support/MigrationMessages.php`, `app/Auth/V1/Migration/Exceptions/CaUnavailableException.php`

**Supprimés — modules legacy (8)** : `legacy/modules/gpo/{applications,associations_out,network_out,veyon_out,gestion_gpo,gpo-maj,gpo-export,wine}.php`

**Supprimés — vues fragment migration (4)** : `resources/views/auth/v1/migration/{fragment-cmd,fragment-sh,fragment-noop-cmd,fragment-noop-sh}.blade.php`

**Supprimés — tests (51)** : `tests/Feature/Api/V1/Config/*` (10) ; `tests/Feature/Gpo/{ApplicationsScriptsEndpoint,NetworkOutEndpoint,VeyonOutEndpoint,AssociationsOutEndpoint,ApplicationsScriptsComparison,AssociationsOutComparison,ApplicationsScriptsSecurity,NetworkOutSecurity,AssociationsOutRouteRegistration,NetworkVeyonRouteRegistration,LegacyGestionGpoRedirect,ApplicationsScriptsApiV1,ApplicationsScriptsByteParity,ApplicationsScriptsCriticalParity,ApplicationsScriptsLocalOverride,ApplicationsScriptsWrapperIntegration,NetworkOutComparison,VeyonOutComparison,AuditApplicationsGpoTemplateCommand,GpoDetailPublish,GpoIndexPublish,WpkgDeploymentPage}Test.php` ; `tests/Feature/Wallpaper/LegacyOutEndpointTest.php` ; `tests/Feature/AppCustomization/{AppPolicyLegacyEndpoint,AppPolicyCanonicalEndpoint}Test.php` ; `tests/Feature/Legacy/{LegacyModuleGpoGestion,LegacyModuleGpoOutputs}Test.php` ; `tests/Feature/Console/Wpkg/WpkgGpoSyncCommandTest.php` ; `tests/Feature/Auth/V1/Migration/{MigrationController,MigrationE2EScenario}Test.php` ; `tests/Unit/Gpo/{ApplicationScriptsGenerator,ApplicationScriptsAssembler,ApplicationTemplatesScanner,ApplicationLoggerService,NetworkScriptGenerator,VeyonConfigGenerator,AssociationsResolver,Substitutions,WpkgGpoSynchronizer}Test.php` ; `tests/Unit/Gpo/Dto/WpkgGpoSyncReportTest.php` ; `tests/Unit/Gpo/Services/WorkstationConfigContextResolverTest.php` ; `tests/Unit/Auth/V1/Migration/{MigrationFragmentRenderer,MigrationStatusChecker}Test.php` ; `tests/Architecture/ApiV1ConfigRoutesTest.php` ; `tests/Architecture/Migration/MigrationModuleArchitectureTest.php` ; `tests/Concerns/AssertsScriptParity.php`

**Modifiés (17)** :
- `routes/web.php` (routes migration.legacy.* + app-policy.canonical + imports retirés)
- `routes/api.php` (groupes workstation-config + shortcuts/export + imports retirés)
- `config/sambaedu.php` (sous-configs `gpo.wpkg_sync`, `gpo.applications.substitutions`, `gpo.applications_template` retirées)
- `app/Http/Controllers/WallpaperController.php` (legacyOut + apiV1 + composeWallpaperResponse retirés, constructeur simplifié)
- `app/Providers/WpkgDeploymentServiceProvider.php` (registration WpkgGpoSyncCommand retirée)
- `app/Gpo/Support/GpoTemplateRegistry.php` (docblock publication mis à jour)
- `resources/views/pages/admin/settings/gpo/index.blade.php` (publish étage 2 + colonne Actions retirés)
- `resources/views/pages/admin/settings/gpo/[guid]/index.blade.php` (publish étage 2 retiré)
- `resources/views/pages/admin/settings/gpo/wpkg-deployment/index.blade.php` (audit se4_wpkg + re-publish retirés, réglages déploiement gardés)
- `tests/Architecture/GpoNamespaceTest.php`, `CacheAbstractionArchitectureTest.php`, `AuthV1NamespaceTest.php`, `ScriptsOsNamespaceTest.php` (amputés)
- `tests/Feature/Gpo/WpkgDeploymentSettingsPageTest.php`, `WpkgDeploymentPagePermissionTest.php` (mocks/tests audit retirés)
- `docs/qa/domains/gpo.md` (section §27.14 ajoutée — append-only)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (statut review + last_updated)

**Conservés explicitement (garde-fous)** : `resources/gpo/se4_agent_bootstrap/*` (intact) ; `app/Http/Middleware/EnsureLegacyConfigChannelEnabled.php` + alias Kernel + clé config + `.env.example` (kill-switch) ; `app/Wpkg/Deployment/Http/Controllers/{LinuxOut,WingetOut}Controller.php` + routes ; `app/Gpo/Services/PackagesXmlAssociationsReader.php` + `app/Config/LegacyConfigBridge.php` (déviations justifiées) ; `app/Auth/V1/Models/WorkstationMigration*` + `app/Auth/V1/Services/MigrationAttemptRecorder.php` (enrôlement agent).

**Note** : `public/build/manifest.json` (+ stubs) créé LOCALEMENT pour les tests de rendu sur l'hôte — gitignored, non versionné, pas un livrable.

### Post-review corrections (orchestrateur dev-cycle, 2026-06-19)

Review sonnet + 2e avis opus → corrections appliquées (cf. `_bmad-output/codeReviews/27-14.md`) :
- **Finding 2** : `tests/Unit/Gpo/GpoTemplateRegistryTest.php` **créé** (5 cas négatifs de `GpoTemplateRegistry` — classe survivante — rapatriés de l'ex-`GpoDetailPublishTest`).
- **S1 (décision Henri : amputer)** : résidu legacy raccourcis. L'amputation a révélé que tout `ShortcutCompilerService` était devenu code mort → **suppression intégrale** (décision Henri confirmée) : `git rm` `app/Services/ShortcutCompilerService.php` + `app/Services/WindowsLnkGenerator.php` ; retrait singleton/`use` `AppServiceProvider` ; `git rm` `app/Observers/ShortcutObserver.php` (+ désenregistrement) + `app/Console/Commands/CompileShortcutsCommand.php` + `app/Models/CompiledShortcut.php` ; `app/Models/Shortcut.php` (relation `compiledShortcuts()`/`invalidateCompilation()` mortes retirées, colonnes `compiled_*` **dormantes** — pas de drop). Le contrat raccourcis passe 100 % par `ShortcutsStateProvider`.
- **S2** : `git rm tests/Feature/Shortcuts/ShortcutExportComparisonTest.php` (parité legacy orpheline).
- **S3** : `.env.example` — purge `GPO_APPLICATIONS_TEMPLATE_PATH` + `SAMBAEDU_APPLICATIONS_SCRIPTS_URL` (morts ; `SAMBAEDU_SCRIPTS_LOGGING_ENABLED` vérifié vivant, conservé).
- **Finding 1 (décision Henri : supprimer)** : `git rm` `app/Gpo/Services/ReadUserManager.php` + `tests/Unit/Gpo/ReadUserManagerTest.php` ; `app/Gpo/README.md` mis à jour.
- **#3/#4** (commentaires obsolètes `MigrationController`/`ApplicationScriptsGenerator`) : différés (cosmétique, sans impact runtime) sauf ceux des fichiers déjà touchés (`ShortcutsStateProvider`, `WorkstationEnvironmentResolver`) nettoyés.

**Correction de doc** : les Dev Notes / AC2 / T4 ci-dessus listent `PackagesXmlAssociationsReader` et `LegacyConfigBridge` en suppression — c'est **inexact**, ils ont été (à raison) **conservés** (consommateurs vivants : `AssociationResolver` 27.11 / `FileAssociationSeeder` ; `SambaEduConfig::legacy()`).

Vérifs post-correction (hôte, filtres ciblés) : `php artisan about` OK ; `--filter Shortcut` 69 passed (1 risky préexistant) ; `ShortcutsStateProvider`+`ContractV1` 19 passed ; bootstrap `Se4AgentBootstrapTemplateTest` + `GpoTemplateRegistryTest` 7 passed / 2 skipped (ext-zip hôte). Diff final : **129 fichiers, +904 / −26332**.

## Recommandation Modèle Dev

**Modèle recommandé : `opus`.**

Justification :
- **Risque de régression élevé, transverse.** C'est une suppression massive (≈25 fichiers + 2 blocs de routes + tests) avec plusieurs pièges non triviaux : ne pas confondre `/api/v1/workstation-config/*` (legacy, à tuer) avec `/api/v1/agent/*` (agent, à garder) ; ne pas supprimer les services métier partagés par les StateProviders ; amputer `WallpaperController` méthode par méthode plutôt que le supprimer ; ne JAMAIS toucher au template bootstrap `se4_agent_bootstrap`.
- **Cas limites à arbitrer en cours de route** (canal Linux `linux_out`, install Windows `winget_out`, sort du kill-switch, passthrough migration, `WpkgGpoSynchronizer`) : exigent un raisonnement de conséquence, pas une exécution mécanique. Un modèle plus faible risquerait de « nettoyer trop » et de casser le canal Linux ou l'enrôlement des postes migrés.
- **Le gate de parité (AC1) demande du jugement** : décider quoi est réellement `done` vs `review` et recommander un séquençage — typiquement le genre de décision où `fable`/`sonnet` sous-performe.

`sonnet`/`fable` seraient acceptables uniquement pour une extinction purement mécanique après que tous les arbitrages (T1.3, T1.4, R2, R3, R5) auront été tranchés par Henri en amont — auquel cas la story deviendrait du « delete par liste ». En l'état (arbitrages ouverts + frontières subtiles), `opus`.
