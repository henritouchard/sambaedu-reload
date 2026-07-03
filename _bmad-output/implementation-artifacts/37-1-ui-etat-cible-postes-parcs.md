# Story 37.1 : Réforme UI — onglet « État cible » (raccourcis + applications + origine) sur la fiche poste et la page parc

Status: done

<!-- Source d'autorité : demande directe Henri (2026-07-03) — story INDÉPENDANTE, hors epics.md
     (aucun fichier de cadrage epic dédié ; epic-37 ouvert comme conteneur léger dans sprint-status.yaml).
     Reco dev : opus (surface UI, patrons éprouvés 27.12/34.2) — le seul point chaud (refactor
     explainPackages du resolver WPKG) est verrouillé par les invariants AC3/AC5. -->

## Story

En tant que **référent numérique**,
je veux **voir, sur la fiche d'un poste et sur la page d'un parc, l'état cible résolu — tous les raccourcis et toutes les applications — avec l'origine de chaque item (réglage propre, hérité d'un parc/salle, socle commun, contrat amont, dépendance)**,
afin **de comprendre en un coup d'œil ce qu'un poste va recevoir et POURQUOI, sans reconstituer mentalement la précédence en fouillant quatre onglets et trois pages**.

## Contexte & intention

**La donnée existe, la vue n'existe pas.** L'état cible d'un poste est compilé côté serveur (`StateCompiler::compile(TargetContext)`) et servi à l'agent (`GET /api/v1/agent/state`) — mais **aucune UI ne l'affiche** (`grep compile(` sur les vues = vide). La consultation est aujourd'hui éclatée : l'onglet WPKG de la fiche poste montre profils/apps direct-vs-hérité (computed `getWpkgAttachedApplicationsProperty()`, `index.blade.php:711-761`), l'onglet WPKG du parc montre les assignations propres SANS héritage, les défauts vivent sur une page admin séparée (`/settings/parc-defaults`, 27.17/27.18), les ordres amont sont invisibles, et **les raccourcis ne sont visibles nulle part côté poste/parc** (seulement depuis la fiche raccourci, sens inverse).

**Ce que la story livre** : un onglet **« État cible »** sur la fiche poste (`/parc/machines/{id}`) et sur la page parc (`/parc/groups/{id}`), en **consultation pure**, listant raccourcis + applications résolus avec un badge d'origine par item (et lien vers le parc/salle source). La résolution passe par un **service de projection lecture seule** (`DesiredStateOriginService`) qui réutilise les mêmes sources que les providers agent — **sans toucher au pipeline agent**.

**Ce que la story ne touche PAS** : `StateCompiler`, `StateCandidate`, les providers, le contrat v1, les golden files, `agent/**` (zéro bump `version.go`), `routes/web.php` (les onglets sont des query params `?tab=`), aucune migration. Le seul fichier moteur modifié est `WorkstationPackagesResolver` (ajout `explainPackages()`, sortie de `computePackages()` byte-identique).

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — le pipeline agent est SANCTUARISÉ.** L'item compilé fait exactement 4 clés `{type, semantics, payload, hash}` (`StateCompiler.php:170-182`) et l'ETag agent en dépend. On n'ajoute RIEN au contrat, on ne modifie ni `StateCandidate` (`StateCandidate.php:40-46`) ni les providers ni `specificity()` (`StateCompiler.php:402-414`). La provenance UI se calcule dans un chemin de CONSULTATION parallèle — jamais dans le chemin agent.
2. **Piège #2 — interdiction de réimplémenter l'union WPKG + BFS de dépendances hors du resolver.** Le docblock d'`ApplicationsStateProvider` (l.33-48) l'interdit explicitement (risque de divergence agent vs WPKG). La provenance apps se livre donc par une méthode `explainPackages(string $hostname): array` **DANS** `WorkstationPackagesResolver` (non cachée, comme `computePackages()`), et `computePackages()` est ré-exprimée dessus (`array_keys` + re-tri). **Invariant non négociable** : sortie de `computePackages()` byte-identique (ordre alpha `strcasecmp` compris) — test d'invariant `array_keys(explainPackages($h)) == computePackages($h)->all()` + suite WPKG existante verte. Ne pas toucher au wrapper caché `resolve()`.
3. **Piège #3 — périmètre machine DIRECT, pas d'héritage physique pour raccourcis/apps.** `TargetContext::workstationGroupIds()` est DIRECT par contrat documenté (note de classe `TargetContext.php:27-36` : l'héritage de la chaîne `parent_id` est réservé aux capacités via `physicalGroupDepths` — « élargir cet accesseur ferait fuiter l'héritage hors des capacités »). Le service UI reproduit CE périmètre : salles directes + parcs logiques du poste. Ne pas « améliorer » en remontant les ancêtres — ce serait afficher un état que l'agent ne recevra pas.
4. **Piège #4 — étiquetage physique/logique iso-provider, filtre `is_active` iso-provider.** La distinction salle/parc d'un `WorkstationGroup` se fait par appartenance aux listes du contexte (`ShortcutsStateProvider::mailleFor()`, l.237-253 : `assignable_id ∈ physicalGroupIds` ⇒ physique) — reproduire la même règle. Seuls les raccourcis `is_active = true` sont compilés (l.94) : même filtre dans la vue (pas de ligne grisée pour les inactifs — ils n'existent pas dans l'état cible).
5. **Piège #5 — un raccourci, PLUSIEURS origines.** Le pivot `shortcut_assignables` est polymorphe : un même raccourci peut être assigné au poste ET à un parc. Le compilateur (aggregate) dédup par contenu et l'origine disparaît ; l'UI, elle, doit **agréger les origines par raccourci** (une ligne par raccourci, badge principal = origine la plus spécifique selon l'ordre de `specificity()`, autres origines en tooltip). Idem pour une app présente à la fois en direct et via un parc.
6. **Piège #6 — crochets `[id]` et directive `@livewire`.** Le chemin SFC `pages/parc/machines/[id]/_partials/...` casse la tag-syntax Blade : inclure via `@livewire('pages::parc.machines.[id]._partials.desired-state-tab', ['workstationId' => $id], key('state-'.$id))` (précédent : 35.4 piège #7). Côté parc, `<livewire:pages::parc.groups._partials....>` marche (précédent `capabilities-tab`) mais un partial sous `groups/[id]/_partials/` repasse par `@livewire`.
7. **Piège #7 — N+1 et hydratation.** Une requête pivot pour les raccourcis (JOIN iso-provider en gardant `assignable_type`/`assignable_id`), UNE requête pour hydrater les noms des groupes sources (`WorkstationGroup::whereIn`), une hydratation `Application::whereIn('app_id', …)` pour les apps (mêmes gardes que le provider : `app_id` non vide ; un `app_id` sans ligne `Application` est SKIPPÉ silencieusement côté UI — le provider logue déjà). Jamais de requête par ligne dans la boucle Blade.
8. **Piège #8 — amont : réutiliser `UpstreamContractSource`, court-circuit NFR3.** Les ordres d'install amont s'obtiennent par `UpstreamContractSource::orderedApplicationAppIds($ctx)` et les raccourcis amont par `candidatesFor()` (singleton mémoïsé — ≤ 1 requête « contrat actif ? », zéro requête items sans contrat). JAMAIS de requête directe aux tables `controlhub_contract_*` depuis le service UI.
9. **Piège #9 — tests HÔTE ciblés, observers AD.** Tests Feature Livewire sur l'HÔTE (php8.4 + sqlite, filtres ciblés — jamais de run massif, mémoire `project_vm_phpunit_bulk_run_false_failures`). Création de postes/groupes en tests ⇒ désactiver la sync AD des observers comme le font les tests parc existants (pattern `MachinesListTest`/`CapabilitiesTabTest`). `withoutVite()` si le rendu de page complet est testé.

## Décisions de design (tranchées)

1. **D1 — chemin de consultation séparé, pipeline agent intouché.** Nouveau service `app/Services/Agent/Reporting/DesiredStateOriginService` (à côté de `ConformityService` — même famille : projection lecture pour l'UI). PG-pur (aucun `Cache::`/APCu/LDAP — NFR7), quatre lectures : `shortcutsFor(Workstation)`, `applicationsFor(Workstation)`, `shortcutsForGroup(WorkstationGroup)`, `applicationsForGroup(WorkstationGroup)`. Chaque ligne retournée : `{label, detail (target/app_id), origins: list<{kind, group?}>, primary_origin}`.
2. **D2 — provenance apps par `explainPackages()` dans le resolver** (piège #2). Origines retournées par le resolver : `workstation` (app/profil direct poste), `group` (app/profil du parc X — id du groupe), `dependency` (via l'app parente Y). Le service COMPLÈTE hors resolver : `parc_default` (`applications.is_parc_default`, socle commun 27.17) et `upstream` (ordres d'install 31.2). Ces cinq origines couvrent exactement l'union de l'`ApplicationsStateProvider` (l.133-178).
3. **D3 — fiche poste = vérité machine.** Raccourcis affichés = ciblages `Workstation` + `WorkstationGroup` (salle directe / parc logique) du poste + items amont — le périmètre exact des candidats machine du provider avec `user = null`. Les ciblages `User`/`UserGroup` (session) sont EXCLUS de la fiche poste, avec une note de section : « Les raccourcis ciblés par utilisateur ou groupe d'utilisateurs dépendent de la session — consultez la fiche du raccourci. » (Ils s'appliqueraient sur n'importe quel poste : les afficher partout = bruit.)
4. **D4 — page parc = la contribution de CE parc + les planchers.** Raccourcis : ciblages `shortcut_assignables` dont l'assignable EST ce groupe. Applications : apps directes du parc + apps de ses profils (badge « via profil X ») + socle commun (`is_parc_default`) + ordres amont. On n'affiche PAS les réglages propres des postes membres (par-poste, visibles sur chaque fiche) ni ceux des autres parcs.
5. **D5 — un onglet « État cible » par page, deep-linkable.** Fiche poste : `$tab` allowed `general|wpkg|agent` → `+ state` (`setTab`, `index.blade.php:677-680`). Page parc : allowed `general|wpkg` (+ `capabilities|associations` sous `app.customize`) → `+ state` inconditionnel (consultation, `index.blade.php:1237-1247`). Contenu = un partial SFC Livewire par page (`_partials/desired-state-tab.blade.php`), `#[Locked] int $workstationId` / `#[Locked] int $groupId`, deux sections (Raccourcis, Applications) en `table table-sm`. Onglet visible sous le gate de page existant (`viewAny-workstationGroup`) — consultation pure, aucun nouveau droit, aucune mutation.
6. **D6 — vocabulaire d'origine unifié (badges DaisyUI, conventions existantes).** `Ce poste` / `Ce parc` = badge-success ; parc logique = badge-primary `fa-layer-group` + **lien** vers le parc ; salle physique = badge-warning `fa-door-open` + lien (palette iso `assigned-groups.blade.php` de la fiche raccourci) ; `Socle commun` = badge-ghost (tooltip : « défaut appliqué à tous les postes — configurable dans Réglages → Configuration par défaut du parc ») ; `Contrat amont` = badge-neutral `fa-lock` (verrouillé) / badge-info (permissif) ; `Dépendance de <app>` = badge-info outline. Multi-origines (piège #5) : badge principal = plus spécifique, `+N` en tooltip listant les autres.
7. **D7 — ordre de spécificité pour le badge principal** : miroir de `specificity()` — `Contrat amont (verrouillé)` > `Ce poste` > `Parc logique` > `Salle` > `Socle commun` > `Contrat amont (permissif)`. On ne recode PAS la précédence (types aggregate = union, rien à arbitrer) — c'est un simple ordre d'affichage, constante privée du service avec renvoi en docblock vers `StateCompiler::specificity()`.
8. **D8 — pas de sur-conception** (mémoire `feedback_no_overengineered_choices`) : pas d'affichage des autres types d'état (registry/drives/wallpaper/associations — déjà visibles sur leurs surfaces), pas d'appel à `StateCompiler::compile()` (l'enveloppe 4-clés n'apporte rien à l'UI), pas d'export, pas de pagination (volumes faibles), pas de rafraîchissement temps réel.

## Acceptance Criteria

### AC1 — Onglet « État cible » sur la fiche poste

**Given** la fiche d'un poste (`/parc/machines/{id}`)
**When** l'utilisateur (gate de page existant) ouvre l'onglet « État cible » (`?tab=state`, deep-linkable)
**Then** il voit une section **Raccourcis** : chaque raccourci actif résolu pour la machine (nom, cible, emplacement `place`) avec son badge d'origine — et une note indiquant que les ciblages utilisateur/groupe d'utilisateurs dépendent de la session
**And** une section **Applications** : chaque application de l'ensemble cible (nom, `app_id`) avec son badge d'origine
**And** les origines « parc » et « salle » sont des liens vers la page du groupe source
**And** un poste sans salle/parc/assignation affiche des états vides propres (pas d'erreur), avec les seuls items socle commun / amont s'ils existent.

### AC2 — Onglet « État cible » sur la page parc

**Given** la page d'un parc ou d'une salle (`/parc/groups/{id}`)
**When** l'utilisateur ouvre l'onglet « État cible » (`?tab=state`)
**Then** il voit les raccourcis assignés à CE groupe et les applications qu'il apporte (directes + via profils, badge « via profil X »)
**And** les planchers hérités sont listés et distingués : socle commun (`is_parc_default`) et contrat amont (ordres d'install / items raccourcis), chacun avec son badge d'origine
**And** la vue précise (encart d'intro) que les réglages propres à chaque poste membre sont visibles sur leur fiche.

### AC3 — Fidélité à l'état servi à l'agent (invariants)

**Given** un poste avec assignations mixtes (directes, parc, salle, profils, dépendances, défaut parc, ordre amont)
**Then** l'ensemble des `app_id` affichés par `applicationsFor()` est ÉGAL à l'union calculée par `ApplicationsStateProvider::itemsFor()` (resolver ∪ défaut parc ∪ ordres amont, mêmes gardes d'hydratation)
**And** l'ensemble des raccourcis affichés par `shortcutsFor()` est ÉGAL aux candidats machine de `ShortcutsStateProvider::itemsFor(TargetContext::for($ws, null))` (+ items amont), comparés par id de raccourci
**And** `array_keys(WorkstationPackagesResolver::explainPackages($h))` == `computePackages($h)->all()` (invariant, ordre compris).

### AC4 — Origines exactes

**Given** les cas suivants, **Then** le badge principal affiché est :
- app assignée directement au poste (pivot poste ou profil direct) → **Ce poste** ;
- app apportée par le parc X (app ou profil du parc) → **parc X** (lien) ; raccourci assigné à une salle → **salle** (badge distinct du parc logique, règle d'étiquetage iso-`mailleFor()`) ;
- app tirée uniquement comme dépendance WPKG → **Dépendance de <app parente>** ;
- app `is_parc_default = true` (sans autre rattachement) → **Socle commun** ;
- app ordonnée par le contrat amont (sans résolution locale) → **Contrat amont** ;
- item multi-origines (ex. app directe poste ET du parc X) → badge le plus spécifique (**Ce poste**) + autres origines en tooltip.

### AC5 — Zéro régression du pipeline agent

**Given** le diff final
**Then** `app/Services/Agent/StateCompiler.php`, `StateCandidate.php`, `app/Services/Agent/Providers/*`, `agent/**`, `tests/Fixtures/Agent/` (golden), `routes/web.php` sont INCHANGÉS (git status à l'appui) — zéro bump `agent/shared/version.go`, aucune migration
**And** `computePackages()` reste byte-identique (suite de tests WPKG existante verte + test d'invariant AC3)
**And** les tests agent/state existants passent sans modification.

### AC6 — Qualité de la surface

**Given** l'onglet rendu
**Then** aucune requête N+1 (pivot + hydratations `whereIn`, groupes sources résolus en une requête)
**And** sans contrat amont actif, AUCUNE requête `controlhub_contract_items` (court-circuit NFR3 via `UpstreamContractSource`)
**And** les composants suivent les conventions : SFC Livewire, `#[Locked]`, badges/tooltips DaisyUI (`tooltip … data-tip`), tables `table table-sm` dans `overflow-x-auto`, aucun formulaire (consultation pure — le trait `WithToasts` n'est requis qu'en cas de message d'erreur).

## Tasks / Subtasks

- [x] **Task 1 — `explainPackages()` dans `WorkstationPackagesResolver` (AC3, AC4, AC5)**
  - [x] 1.1 Refactor interne : la logique union (profils/apps × poste/groupes) + BFS de dépendances construit `app_id → list<origin>` (`{source: workstation|group|dependency, group_id?, profile_id?, via_app_id?}`) ; `computePackages()` ré-exprimée en `array_keys` + tri existant — sortie byte-identique.
  - [x] 1.2 Test d'invariant (fixtures mixtes : direct, groupe, profil, dépendance transitive) + non-régression de la suite WPKG existante (filtres ciblés).
- [x] **Task 2 — `DesiredStateOriginService` (AC3, AC4)**
  - [x] 2.1 `shortcutsFor(Workstation)` : requête pivot iso-provider (types `Workstation` + `WorkstationGroup` sur `workstationGroupIds()` DIRECT, `is_active`), étiquetage salle/parc iso-`mailleFor()`, agrégation multi-origines par raccourci, items amont via `UpstreamContractSource::candidatesFor()`.
  - [x] 2.2 `applicationsFor(Workstation)` : `explainPackages()` + `is_parc_default` + `orderedApplicationAppIds()`, hydratation `Application::whereIn`, fusion des origines par `app_id`.
  - [x] 2.3 `shortcutsForGroup()` / `applicationsForGroup()` : contribution du groupe (D4) + planchers socle/amont.
  - [x] 2.4 Tests unit du service (sqlite) : chaque cas d'origine d'AC4 + multi-origines + ensembles vides + invariants AC3 (comparaison aux providers réels).
- [x] **Task 3 — Onglet fiche poste (AC1, AC6)**
  - [x] 3.1 `resources/views/pages/parc/machines/[id]/_partials/desired-state-tab.blade.php` : SFC Livewire (`#[Locked] int $workstationId`, computeds `shortcuts`/`applications` → service), deux sections + badges D6 + note session.
  - [x] 3.2 `index.blade.php` : onglet « État cible » ajouté (`setTab` allowed + bouton onglet + `@livewire(…, key())` — piège #6).
  - [x] 3.3 Tests Feature Livewire (rendu, origines affichées, liens groupes, `#[Locked]`, poste nu). Deep-link `?tab=state` : couvert par l'ajout de `state` à `setTab` allowed + binding `#[Url(as:'tab')]` préexistant.
- [x] **Task 4 — Onglet page parc (AC2, AC6)**
  - [x] 4.1 `resources/views/pages/parc/groups/[id]/_partials/desired-state-tab.blade.php` : SFC (`#[Locked] int $groupId`), sections raccourcis/apps + planchers + encart d'intro.
  - [x] 4.2 `index.blade.php` (parc) : onglet ajouté (allowed + bouton + inclusion).
  - [x] 4.3 Tests Feature Livewire (contribution du parc, via profil, socle, salle vs parc logique).
- [x] **Task 5 — Validation finale (AC5)**
  - [x] 5.1 Tests HÔTE ciblés passants (nouveaux + non-régression : suite WPKG resolver, `StateEndpointTest`/tests provider existants, tests fiche poste/page parc existants).
  - [x] 5.2 `git status` : zéro modif `StateCompiler.php`, `StateCandidate.php`, `Providers/*`, `agent/**`, golden, `routes/web.php` ; zéro migration.

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` | modifié — `explainPackages()` + refactor interne, `computePackages()` byte-identique |
| `app/Services/Agent/Reporting/DesiredStateOriginService.php` | NOUVEAU — projection lecture seule raccourcis/apps + origines |
| `resources/views/pages/parc/machines/[id]/_partials/desired-state-tab.blade.php` | NOUVEAU — SFC onglet État cible poste |
| `resources/views/pages/parc/machines/[id]/index.blade.php` | modifié — onglet `state` (allowed + bouton + inclusion) |
| `resources/views/pages/parc/groups/[id]/_partials/desired-state-tab.blade.php` | NOUVEAU — SFC onglet État cible parc |
| `resources/views/pages/parc/groups/[id]/index.blade.php` | modifié — onglet `state` |
| `tests/Unit/Services/Agent/DesiredStateOriginServiceTest.php` | NOUVEAU — origines + invariants AC3 |
| `tests/Feature/Wpkg/…ResolverExplainTest.php` (ou suite resolver existante) | invariant explain/compute + non-régression |
| `tests/Feature/Livewire/Parc/MachineDesiredStateTabTest.php` | NOUVEAU |
| `tests/Feature/Livewire/Parc/GroupDesiredStateTabTest.php` | NOUVEAU |

**NE PAS TOUCHER** : `app/Services/Agent/StateCompiler.php`, `app/Services/Agent/StateCandidate.php`, `app/Services/Agent/Providers/*` (dont `ShortcutsStateProvider`/`ApplicationsStateProvider` — on les LIT comme référence, on ne les modifie pas), `app/Services/Agent/TargetContext.php`, `agent/**` + `agent/shared/version.go` (story serveur/UI pure), `tests/Fixtures/Agent/` (golden), `routes/web.php` (onglets = `?tab`, aucune route nouvelle — évite le piège route:cache VM), `app/Http/Controllers/Api/V1/Agent/StateController.php`, l'onglet WPKG existant des deux pages (l'édition reste où elle est), `sprint-status.yaml`/`backlog.*` (orchestrateur).

### Patrons de référence (transposer, ne pas réinventer)

| Besoin | Référence |
|---|---|
| Badge « direct » vs « hérité (via parc) » + lien source | fiche poste `_partials/wpkg-assignment-tab.blade.php:69-184` |
| Palette badges par type de cible (salle/parc/poste/user) | fiche raccourci `assigned-groups.blade.php` (physique badge-warning `fa-door-open`, logique badge-primary `fa-layer-group`) |
| Structure onglet SFC + `#[Locked]` + computeds | `groups/_partials/capabilities-tab.blade.php`, `associations-tab.blade.php` |
| Service de projection lecture pour l'UI | `app/Services/Agent/Reporting/ConformityService.php` |
| Tooltip riche | `associations-tab.blade.php:513-528` (`tooltip before:max-w-xs before:whitespace-normal`) |
| Wiring onglets `$tab` `#[Url(as:'tab')]` + `setTab` | fiche poste `index.blade.php:677-680` (allowed) + `:1169-1188` (boutons) ; parc `index.blade.php:1237-1247` + `:1987-2016` |

### Chaîne existante (s'y appuyer, ne rien recoder)

- **Sources raccourcis** : pivot polymorphe `shortcut_assignables` ; périmètre machine = `TargetContext::workstationGroupIds()` DIRECT (`TargetContext.php:85-88`, note de classe l.27-36 — piège #3) ; étiquetage salle/parc : `ShortcutsStateProvider::mailleFor()` l.237-253 ; filtre `is_active` l.94.
- **Sources apps** : `WorkstationPackagesResolver::computePackages()` (union poste+groupes+profils+dépendances, NON cachée) ; défaut parc `Application::parcDefault()` (`ApplicationsStateProvider.php:148-154`) ; ordres amont `UpstreamContractSource::orderedApplicationAppIds()` (l.164) ; gardes d'hydratation l.184-210.
- **Amont** : `UpstreamContractSource` singleton mémoïsé — `candidatesFor()` pour les items raccourcis amont, court-circuit NFR3 sans contrat actif.
- **Ordre de spécificité (affichage seulement)** : `StateCompiler::specificity()` l.402-414 — `Upstream(-1) < User(0) < UserGroup(1) < Workstation(2) < LogicalGroup(3) < PhysicalGroup(4) < Broadcast(5) < UpstreamPermissive(6)`.
- **Relations** : `Workstation::physicalRooms()`/`logicalGroups()`/`applications`/`appProfiles` ; `WorkstationGroup::applications()`/`appProfiles()`/`is_physical`.

### Rappels transverses

- Tests sur l'HÔTE (php8.4 + sqlite), filtres ciblés uniquement ; sync AD des observers désactivée dans les fixtures (pattern tests parc existants).
- Aucune vérification VM requise pour merger (pas de route nouvelle, pas de migration) ; si démo manuelle sur `/vm` : le code sync par inotify, ne jamais sync à la main.
- UX : consultation pure — pas de formulaire ; si un message d'erreur devient nécessaire, trait `WithToasts`, jamais d'alerte maison.
- Doc : néant à ce stade (la doc suit le code — pas de fiche domaine impactée par une vue de consultation).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

- Non-régression resolver WPKG : `php artisan test --filter=WorkstationPackagesResolverTest` → 6/6.
- Invariant `explainPackages`/`computePackages` : `--filter=WorkstationPackagesResolverExplainTest` → 4/4.
- Service : `--filter=DesiredStateOriginServiceTest` → 14/14 (dont invariants AC3 vs providers réels).
- Onglets Feature Livewire : `MachineDesiredStateTabTest` 4/4, `GroupDesiredStateTabTest` 3/3.
- Contrat/pipeline agent intacts : `StateCompilerTest|StateHasherTest|ContractV1Test|UpstreamInstallOrderTest` → 58/58 ; `StateEndpointTest` → 18/18 ; `ApplicationsStateProviderTest|ShortcutsStateProviderTest` verts.
- Pages parent après wiring : `MachineShowPageTest`+`GroupShowPageTest` → 34/34.

### Completion Notes List

- **Task 1** — `WorkstationPackagesResolver::computePackages()` ré-exprimée sur la nouvelle `explainPackages()` (map `app_id → list<origin>`, `{source: workstation|group|dependency, group_id?, profile_id?, via_app_id?}`). BFS de dépendances désormais `collectDependencyParents()` (suit le parent découvreur pour « Dépendance de X ») — même ensemble visité que l'ancien `collectDependenciesTransitive()`. Sortie de `computePackages()` **byte-identique** (invariant testé, ordre `strcasecmp` compris). Wrapper caché `resolve()` non touché.
- **Task 2** — `DesiredStateOriginService` (lecture seule, PG-pur) : 4 méthodes `shortcutsFor`/`applicationsFor`/`shortcutsForGroup`/`applicationsForGroup`. Réutilise `explainPackages()` ∪ `is_parc_default` ∪ `UpstreamContractSource::orderedApplicationAppIds()` (apps) et le pivot `shortcut_assignables` restreint au périmètre machine DIRECT (`workstationGroupIds()`, `is_active`) ∪ `candidatesFor()` (raccourcis amont). Ordre de spécificité `RANKS` = miroir d'affichage de `StateCompiler::specificity()` (D7). Ciblages User/UserGroup exclus de la fiche poste (D3, `user = null`). Multi-origines agrégées (piège #5). Anti-N+1 : `whereIn` groupes/apps.
- **Task 3/4** — Deux SFC Livewire anonymes (`#[Locked]` sur les ids), inclusion via `@livewire('pages::…[id]…', […], key())` (piège #6). Badges/tooltips DaisyUI factorisés dans un partial partagé `parc/_partials/desired-state-origins.blade.php` (palette iso `assigned-groups` : salle badge-warning `fa-door-open`, parc logique badge-primary `fa-layer-group`, liens vers le groupe source). Onglet `state` : fiche poste `setTab` allowed `+state` + bouton + `@elseif`; page parc `+state` **inconditionnel** (consultation) + bouton + `@elseif`. Consultation pure (aucun formulaire).
- **Écart assumé — portée amont sur la page parc (D4/AC2)** : les ordres d'install amont affichés sur la fiche PARC sont les ordres `instance` (toute la flotte), obtenus via un `TargetContext` « flotte » sans appartenance de parc (`workstationGroupIds()` vide ⇒ seuls les `instance` remontent). Les ordres ciblés par `label` sont poste-portés (dérivés des labels que porte un poste, pas un parc) et restent visibles sur les fiches des postes concernés — l'API `UpstreamContractSource` étant poste-centrique, on n'a pas synthétisé de contexte de parc pour éviter le hack d'un poste représentant (qui fuiterait les labels d'AUTRES parcs). En prod sans contrat actif : court-circuit NFR3 (aucune requête `controlhub_contract_items`). Raccourcis amont non câblés en prod (`ShortcutsUpstreamAdapter` non enregistré) ⇒ `candidatesFor` renvoie `[]` (court-circuit).
- **AC5 vérifié** : `git status` prouve zéro modif de `StateCompiler.php`, `StateCandidate.php`, `app/Services/Agent/Providers/*`, `app/Services/Agent/TargetContext.php`, `agent/**`, `tests/Fixtures/Agent/`, `routes/web.php`, `StateController.php` ; aucune migration.

### Corrections post-review (2026-07-03)

- **P1 — PERF (lazy-loading Livewire, signalé en test réel)** : la bascule vers l'onglet « État cible » montait le SFC EN SYNCHRONE dans le même roundtrip Livewire que le re-rendu complet de la page parente (~1600-2100 lignes de Blade) ⇒ navigation lente alors que le service est rapide (≤ 25 ms). Fix : attribut **`#[Lazy]`** sur la classe anonyme des DEUX SFC (`new #[Lazy] class extends Component`) + méthode **`placeholder()`** retournant un squelette DaisyUI (alert « Chargement de l'état cible… » + 2 cards `skeleton` aux dimensions proches du réel, zéro saut de layout). Le contenu réel est chargé dans un second roundtrip isolé (`x-intersect`), la page parente n'est plus re-rendue en même temps. **Pattern retenu** : `#[Lazy]` sur la classe + `placeholder()` — le projet n'avait AUCUN précédent lazy Livewire réel (les occurrences « lazy » de `parc/index.blade.php` / `admin/settings/gpo/index.blade.php` étaient des commentaires, pas des composants) ; on applique donc le pattern canonique Livewire 4.3 (attribut de classe, cohérent avec `#[Title]` déjà utilisé sur `parc/index.blade.php`). Tests : `Livewire::withoutLazyLoading()` en `setUp` des deux suites SFC (rendu eager pour les assertions de contenu/verrou) + un test dédié `renders_lazy_placeholder_before_loading_real_content` qui réactive le lazy (`SupportLazyLoading::$disableWhileTesting = false`) et prouve le rendu du squelette (`assertSee('Chargement')`/`'skeleton'`, `assertDontSee('Identifiant WPKG')`).
- **#1 — Câblage pages parentes testé** : ajout de `test_state_tab_wires_desired_state_component` dans `MachineShowPageTest` et `GroupShowPageTest` (`->call('setTab','state')->assertSet('tab','state')->assertSee('État cible du poste'|'du parc')`). Le SFC étant `#[Lazy]`, la page ne rend que son placeholder ⇒ le câblage `@elseif ($tab==='state')` + le nom du composant `@livewire(...)` sont exercés SANS dépendre des tables raccourcis/apps (mount court-circuité).
- **#2 — Couverture contrat amont (AC4)** : ajout de `app_ordered_by_active_upstream_contract_is_contrat_amont` dans `DesiredStateOriginServiceTest` — contrat amont ACTIF + item `type='applications'`, `target_type='instance'`, `enforcement_state=locked` ordonnant une app non résolue localement ⇒ `applicationsFor()` la remonte avec `kind === 'upstream'`. Le singleton mémoïsé `UpstreamContractSource` est ré-instancié (`app()->forgetInstance()`) après création du contrat pour que sa résolution le voie.
- **#3 — Déterminisme `via_app_id`** : `collectDependencyParents()` du resolver reçoit un `->orderBy('application_id')` (tiebreak stable = plus petite PK parente si une dépendance a ≥ 2 parents dans le même batch BFS). Purement cosmétique (tooltip « Dépendance de X ») — l'ENSEMBLE des app_id / l'invariant AC3 est inchangé. Test `dependency_with_two_parents_attributes_smallest_parent_pk` (app_id d'ordre alpha inverse de l'ordre de PK, pour prouver que c'est la PK qui départage).
- **#4 — Verrou `#[Locked]` réellement testé** : les tests `*_is_locked` existants (assertSet initial) sont complétés par `*_mutation_is_rejected_by_locked` qui tentent `->set('workstationId'|'groupId', 999)` et attendent `CannotUpdateLockedPropertyException` (pattern maison iso `CapabilitiesTabCustomizeScopingTest::tampering_group_id_throws_locked_property_exception`).
- **#7 — BUG câblage cassé de l'onglet « État cible » sur la FICHE POSTE (découvert en test réel)** : le bloc `@elseif ($tab === 'state')` avait été inséré À L'INTÉRIEUR de la branche `@else` (Général) — accroché au `@if ($deploySuccess->isNotEmpty() || …)` de la card déploiement. Conséquences constatées : en `?tab=state` la branche Général se rendait AUSSI (card « Groupes logiques » au-dessus de l'état cible), et sur un poste ayant des statuts de déploiement (deploy non vide) le contenu État cible ne se rendait PAS DU TOUT (le `@elseif` n'était jamais atteint). Fix : restructuration en chaîne PLATE `@if wpkg / @elseif agent / @elseif state / @else Général` — le bloc `state` remonté au niveau des autres onglets, et le `@if ($deploySuccess…)` a retrouvé son `@endif` propre (`{{-- /card déploiement --}}`). La page PARC (`groups/[id]/index.blade.php`) était déjà correcte — non touchée. Tests (dans `MachineShowPageTest`) : `test_state_tab_wires_desired_state_component` renforcé (`assertSee('État cible du poste')` + `assertDontSee('Groupes logiques')`), `test_state_tab_renders_even_with_deployment_statuses` (poste avec `WorkstationApplicationStatus` ⇒ l'état cible se rend en tab=state, `assertDontSee('Déploiement des applications')`), `test_general_tab_still_shows_logical_groups` (non-régression du déplacement). Table `applications` ajoutée au bootstrap de la suite (le résumé de déploiement toujours-rendu eager-load `application`).
- **Résultats de tests (HÔTE, filtres ciblés)** : `WorkstationPackagesResolverExplainTest` 5/5, `WorkstationPackagesResolverTest` 6/6, `DesiredStateOriginServiceTest` 16/16, `MachineDesiredStateTabTest` 6/6, `GroupDesiredStateTabTest` 5/5, `MachineShowPageTest` 20/20, `GroupShowPageTest` 16/16 (run groupé : 73/73). Non-régression pipeline agent : `ApplicationsStateProviderTest|ShortcutsStateProviderTest|StateEndpointTest|UpstreamInstallOrderTest` 56/56.
- **Décisions review appliquées (dernière passe, 2026-07-03)** :
  - **#5 — `room_self` / « Cette salle »** : `shortcutsForGroup()`/`applicationsForGroup()` étiquettent la contribution propre `room_self` quand `is_physical` (badge-warning `fa-door-open` « Cette salle » dans le partial, rang identique à `group_self`), et le SFC parc conditionne l'encart d'intro et les états vides sur `is_physical` (« État cible de la salle » / « Cette salle n'assigne… »). Le `placeholder()` lazy garde un titre NEUTRE « État cible » (le groupe n'y est pas chargé). Tests : `physical_room_own_contribution_is_room_self` (service), `physical_room_page_says_cette_salle` + `physical_room_empty_states_are_salle_centric` (SFC) ; assertion du test de câblage `GroupShowPageTest` ajustée au placeholder neutre.
  - **#6b — mention UI de l'écart ordres amont `label`** : phrase ajoutée à l'encart d'intro du SFC parc (« Les ordres d'installation du contrat amont ciblés par label sont propres aux postes qui portent ce label — consultez les fiches des postes concernés. ») — texte seul, aucune logique. Assert `assertSee('ciblés par label')` ajouté.
  - **M1 — « Dépendance de \<nom d'affichage\> »** : `decorateAppOrigin()` hydrate `via` avec `Application::name` du parent via la map `app_id → Application` déjà chargée (helper `dependencyViaLabel()`, zéro requête de plus, fallback `app_id` brut si nom introuvable/vide). Fixture du test dépendance passée à `name='Parent Display Name'` ≠ `app_id` + assert sur le NOM.
  - Tests après cette passe : `DesiredStateOriginServiceTest` 17/17, `GroupDesiredStateTabTest` 7/7, `MachineDesiredStateTabTest` 6/6, `WorkstationPackagesResolverExplainTest` 5/5 (34/34 groupé) ; non-régression `MachineShowPageTest|GroupShowPageTest` 38/38.
- **Non traité** : #6a/M2/M3 assumés par la review (fleetContext, sprint-status, fail-safe default).

### File List

**Modifiés**
- `app/Wpkg/Deployment/Services/WorkstationPackagesResolver.php` — `explainPackages()` + `collectDependencyParents()` ; `computePackages()` ré-exprimée (byte-identique).
- `resources/views/pages/parc/machines/[id]/index.blade.php` — onglet `state` (`setTab` allowed + bouton + `@elseif` `@livewire`).
- `resources/views/pages/parc/groups/[id]/index.blade.php` — onglet `state` (`setTab` allowed inconditionnel + bouton + `@elseif` `@livewire`).
- `docs/qa/domains/parc.md` — nouvelle section « Story 37.1 » (scénarios 6.1-6.3 + checklist, append-only).

**Nouveaux**
- `app/Services/Agent/Reporting/DesiredStateOriginService.php` — projection lecture seule raccourcis/apps + origines.
- `resources/views/pages/parc/machines/[id]/_partials/desired-state-tab.blade.php` — SFC onglet État cible poste.
- `resources/views/pages/parc/groups/[id]/_partials/desired-state-tab.blade.php` — SFC onglet État cible parc.
- `resources/views/pages/parc/_partials/desired-state-origins.blade.php` — partial partagé de rendu des badges d'origine (helper des 2 SFC).
- `tests/Unit/Wpkg/Deployment/Services/WorkstationPackagesResolverExplainTest.php` — invariant explain/compute + origines.
- `tests/Unit/Services/Agent/DesiredStateOriginServiceTest.php` — origines AC4 + multi-origines + vides + invariants AC3.
- `tests/Feature/Livewire/Parc/MachineDesiredStateTabTest.php` — onglet fiche poste.
- `tests/Feature/Livewire/Parc/GroupDesiredStateTabTest.php` — onglet page parc.

### Change Log

- 2026-07-03 — Story 37.1 implémentée (onglet « État cible » fiche poste + page parc, service de projection lecture seule, `explainPackages()` byte-identique). Status → review.
