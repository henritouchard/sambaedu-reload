# Story 26.1: Enum WorkstationEnvironment — la nature du poste devient une donnée du domaine

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

En tant qu'**admin d'établissement**,
je veux **déclarer par parc si les postes sont partagés, personnels ou nomades**,
afin que **les handlers de l'Epic 27 (bureau, profils navigateur, clean_profiles) adaptent leur comportement à la nature du poste**.

## Acceptance Criteria

1. **Enum `App\Enums\WorkstationEnvironment`** existe, backed string, trois cases aux **identifiants figés (NFR12)** : `shared_local` / `personal_local` / `nomade`. Docblock riche documentant la sémantique des trois valeurs (style `StateScope`, `ResourceSemantics`, `StateMaille`).

2. **Colonne Postgres** portant l'environnement sur la table `workstation_groups` (applicable à un groupe **logique OU physique** — résolu côté serveur). Migration idempotente (`Schema::hasColumn`), `down()` symétrique. **Cast enum** ajouté sur `App\Models\WorkstationGroup` (`$casts`) + colonne dans `$fillable`.

3. **Service de domaine de résolution** : étant donné une machine (`Workstation`) appartenant à N groupes, il résout **un** `WorkstationEnvironment` avec **précédence `nomade` > `personal_local` > `shared_local`**, **défaut `shared_local`** (poste sans groupe, ou groupes sans valeur). La résolution **lit Postgres, JAMAIS l'AD/APCu**. Le service est **consommable par les StateProviders de l'Epic 27** (signature et style alignés sur le contrat `App\Services\Agent\Contracts\StateProvider` et l'hydratation `TargetContext`).

4. **Sémantique documentée** : `shared_local` = poste partagé (bureau réseau, profils redirigés) ; `personal_local` = modèle perdir/direction (bureau local, données sur le home réseau) ; `nomade` = tout local avec sync (story 26.2). Documentation dans `docs/agent/` + docblock de l'enum.

5. **UI parc-settings** : l'admin sélectionne l'environnement d'un parc via un composant **Livewire SFC** utilisant le trait `WithToasts`. Écriture en base via le modèle (la sélection persiste, toast de succès, Gate cohérent avec les autres actions parc).

6. **Tests** couvrant : la **précédence multi-groupes** (toutes les combinaisons utiles), le **défaut** (poste sans groupe / groupes à null), le cast enum sur le modèle, et l'écriture UI (Livewire). Suite ciblée verte + non-régression `--filter Agent`.

**Note de transition (CRITIQUE) :** AUCUN retrofit dans le canal legacy. `App\Services\...\ApplicationScriptsGenerator`, `ShortcutCompilerService` et le pansement Bug C (commit `4e5a152`) restent **INTOUCHÉS** — ils meurent en bloc avec le canal legacy (Epic 27.6). Le **Bug C est corrigé définitivement par le handler raccourcis (Story 27.1)**, qui consommera ce service de résolution. Ne PAS brancher le service sur le canal legacy.

## Tasks / Subtasks

- [x] **T1 — Enum `WorkstationEnvironment`** (AC: #1, #4)
  - [x] Créer `app/Enums/WorkstationEnvironment.php` : `enum WorkstationEnvironment: string` avec `declare(strict_types=1)`, namespace `App\Enums`, cases `SharedLocal = 'shared_local'`, `PersonalLocal = 'personal_local'`, `Nomade = 'nomade'`.
  - [x] Docblock riche en tête (calqué sur `app/Enums/StateScope.php` et `ResourceSemantics.php`) : décrire les 3 valeurs (cf. AC4) + mention « identifiants figés (NFR12), une valeur publiée ne se renomme jamais ».
  - [x] **Décision D1** appliquée : la précédence vit dans le **service de résolution**, PAS dans l'enum (parallèle `StateMaille`). Méthode `label(): string` lisible UI ajoutée sur l'enum.

- [x] **T2 — Migration + cast modèle** (AC: #2)
  - [x] Migration `database/migrations/2026_06_13_150000_add_environment_to_workstation_groups.php` (timestamp postérieur à `…140000…`). Style calqué sur `2026_06_13_140000_add_agent_reported_version_to_workstations.php` : docblock story, `Schema::hasColumn` avant `add`, `down()` symétrique conditionnel.
  - [x] Colonne `environment` VARCHAR(32) **nullable** (D2 : pas de default SQL). `->comment(...)` daté story. Type varchar simple → compatible SQLite des tests.
  - [x] `app/Models/WorkstationGroup.php` : `'environment'` dans `$fillable` ; `'environment' => WorkstationEnvironment::class` dans `$casts` ; `@property \App\Enums\WorkstationEnvironment|null $environment` ajouté au docblock.
  - [x] Migration appliquée sur la VM (`php artisan migrate --force`) — colonne `environment` vérifiée présente (`Schema::hasColumn` = true, `character varying(32)` nullable).

- [x] **T3 — Service de résolution de l'environnement** (AC: #3)
  - [x] `app/Services/Agent/WorkstationEnvironmentResolver.php` (`final readonly`, `declare(strict_types=1)`, voisin de `TargetContext`/`StateCompiler`).
  - [x] `resolve(Workstation $workstation)` + `resolveForGroupIds(array $workstationGroupIds)` consommable depuis `TargetContext::workstationGroupIds()` (alignement Epic 27, pas de re-requête côté provider).
  - [x] Lecture Postgres uniquement (`WorkstationGroup::whereIn('id', $ids)->whereNotNull('environment')->pluck('environment')`), précédence `nomade > personal_local > shared_local`, défaut `shared_local`. JAMAIS d'AD/LdapRecord/APCu.
  - [x] Docblock riche : précédence, défaut, interdiction AD, point de consommation Epic 27, note de transition (pas de câblage legacy).
  - [x] Singleton enregistré dans `AgentServiceProvider::register()`.

- [x] **T4 — UI parc-settings (Livewire SFC)** (AC: #5)
  - [x] **Décision D3 = option (a)** : onglet « Environnement » dans `parc-settings/index.blade.php` rendu via `<livewire:pages::parc-settings._partials.environment-tab />`, pattern `setTab()`/`tabs-boxed`. Liste les parcs **logiques ET physiques** (`scopeActive` + `scopeNotArchived`), un `<select>` par parc.
  - [x] SFC `resources/views/pages/parc-settings/_partials/environment-tab.blade.php` : `use WithToasts`, propriété `selection`, action `save(int $groupId)` qui persiste via le modèle (vide → null, D2 ; `tryFrom` défensif) puis `toastSuccess(...)`. `WorkstationEnvironment::cases()` + `label()` peuplent le `<select>`.
  - [x] Gate : `update-workstationGroup` (policy `WorkstationGroupPolicy::update` → `canAdminComputers` → `computer.install`, **droit admin GLOBAL, sans délégation scopée**) — MÊME gate que l'édition d'un parc (`parc/groups/[id]`). Aucune gate inventée. (Correction review 26.1 P1 : `manage-workstationGroup` porte `computer.control`+scopé mais n'est PAS utilisée ici ; choix `update` vs `manage` = question Henri ouverte.)
  - [x] `<select>` inline (pas de modale nécessaire : édition directe in-place avec `wire:change`).

- [x] **T5 — Documentation sémantique** (AC: #4)
  - [x] Section « Environnement de poste (`WorkstationEnvironment`) — Story 26.1 » ajoutée à `docs/agent/state-providers.md` (append) : sémantique des 3 valeurs, précédence multi-parcs, défaut, lecture Postgres-only, point de consommation Epic 27, note de transition legacy, UI.

- [x] **T6 — Tests** (AC: #6)
  - [x] `tests/Unit/Services/Agent/WorkstationEnvironmentResolverTest.php` : précédence (nomade > tout, personal_local > shared_local, shared_local seul), défaut sans groupe, défaut tous null, mixte null+valeur, `resolveForGroupIds` (liste vide + ids), binding singleton.
  - [x] `tests/Unit/Models/WorkstationGroupEnvironmentCastTest.php` : round-trip enum, null → null, mass-assignable.
  - [x] `tests/Feature/Livewire/ParcSettings/EnvironmentTabTest.php` : sélection → persistance + toast succès, remise à null, mount prefill, groupe inconnu → toast erreur.
  - [x] Suite ciblée : **17 passed (23 assertions)**. Non-régression `php artisan test --filter Agent` : **354 passed (1290 assertions)**, 0 failed. Suite Go non touchée.

## Dev Notes

### Architecture, patterns et contraintes

- **Code à la RACINE** : `app/`, `resources/`, `database/`, `tests/` — PAS sous `laravel/`.
- **Enums style maison** : backed string, `declare(strict_types=1)`, docblock pédagogique en tête expliquant chaque case + mention NFR12. Références verbatim : `app/Enums/StateScope.php`, `app/Enums/ResourceSemantics.php`, `app/Enums/StateMaille.php`.
- **Précédence hors de l'enum (D1)** : `StateMaille` documente explicitement « AUCUNE méthode de rang ici » — la précédence vit dans le compilateur seul. Appliquer le même principe : la précédence environnement vit dans le **service**, pas l'enum. Cela garde l'enum réutilisable et évite la fuite de logique métier.
- **Lecture Postgres exclusive** : `TargetContext` documente « Hydratation exclusivement Postgres … jamais d'APCu ni de LdapRecord (critère Keycloak, NFR7) ». Le resolver suit la même règle absolue. Ne PAS toucher le `WallpaperContext` legacy (cache APCu).
- **Migration agent style** : varchar simple (pas d'enum Postgres natif) pour compat SQLite des tests sans branche driver ; `Schema::hasColumn` idempotent ; `down()` symétrique ; `->comment()` daté story. Référence : `database/migrations/2026_06_13_140000_add_agent_reported_version_to_workstations.php`.
- **`null` vs default SQL (D2)** : colonne nullable, défaut résolu **côté service** (`shared_local`). Permet de distinguer « parc non encore configuré » de « parc explicitement partagé », et évite une migration de backfill.
- **Consommation Epic 27** : la signature doit pouvoir être appelée depuis un `StateProvider::itemsFor(TargetContext $ctx)`. `TargetContext` expose déjà `physicalGroupIds`, `logicalGroupIds`, et (vérifier) un accesseur `allWorkstationGroupIds()`/`workstationGroupIds()`. Préférer une méthode du resolver qui prend une **liste d'ids** pour rester dans la discipline « pas de re-requête des appartenances par le provider ».

### Project Structure Notes

- Enum → `app/Enums/WorkstationEnvironment.php`.
- Service → `app/Services/Agent/WorkstationEnvironmentResolver.php` (voisin de `TargetContext.php`, `StateCompiler.php`). Enregistré dans `app/Providers/AgentServiceProvider.php`.
- Migration → `database/migrations/2026_06_13_HHMMSS_add_environment_to_workstation_groups.php`.
- Modèle touché → `app/Models/WorkstationGroup.php` (`$fillable`, `$casts`, docblock).
- UI → onglet dans `resources/views/pages/parc-settings/index.blade.php` + `resources/views/pages/parc-settings/_partials/environment-tab.blade.php` (SFC Livewire).
- Doc → `docs/agent/` (state-providers.md ou agent.md).
- Tests → `tests/` (Feature pour Livewire + cast, Unit pour resolver).

### Hors-scope (NE PAS faire)

- Le **mode nomade offline** lui-même (Folder Redirection / Offline Files / désactivation clean_profiles) = **Story 26.2**.
- Les **handlers Epic 27** (raccourcis, profils navigateur, etc.) — 26.1 ne fait que produire la donnée + le service. Aucun handler, aucun StateProvider « environment » n'est exigé ici (le service est juste prêt à être consommé).
- **Aucun retrofit legacy** : ne pas toucher `ApplicationScriptsGenerator`, `ShortcutCompilerService`, le pansement `4e5a152`, ni les tags `list_*` (ex-22.3 annulée, FR30 → Epic 27).

### Dépendances

| Story | Rôle pour 26.1 | Statut (sprint-status.yaml) | Bloquant ? |
|-------|----------------|------------------------------|------------|
| 23.4 — StateCompiler & StateProvider contract | Fournit le contrat `StateProvider` + `TargetContext` que le resolver devra servir (consommation Epic 27) | `done` | Non (lecture du contrat suffit) |
| 23.5 — GET /state | Confirme l'hydratation `TargetContext` côté serveur | `done` | Non |
| 24.x — agent MVP boucle fermée | Établit le pattern provider/service agent | `done` (epic-24 `done`) | Non |
| 25.x — gestion de flotte / UI parc-settings/agent | Établit le pattern Livewire SFC parc-settings (`releases-rings`, `WithToasts`, Gate, onglets) | `25-1 done` ; `25-2/25-3/25-4/25-5 review` | **Non** — les 25.x en `review` (Henri teste les lots ensemble) n'affectent pas le domaine Postgres + enum + UI de 26.1. Les partials 25.5 servent de modèle de style. |

26.1 est essentiellement du **domaine Postgres + enum + UI**, parallélisable, prérequis du handler raccourcis 27.1. Aucune dépendance dure non satisfaite.

### References

- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#Story 26.1] — AC d'origine, note de transition.
- [Source: _bmad-output/planning-artifacts/epics-agent-desired-state.md#FR28] — `WorkstationEnvironment` rescopé, consommateurs = handlers Epic 27.
- [Source: app/Enums/StateScope.php / ResourceSemantics.php / StateMaille.php] — style enum + principe « précédence hors enum ».
- [Source: app/Models/WorkstationGroup.php] — `$fillable`, `$casts`, scopes `physical`/`logical`, relation `workstations()`.
- [Source: app/Models/Workstation.php#groups] — relation pivot `workstation_group_workstation`.
- [Source: app/Services/Agent/TargetContext.php] — discipline lecture Postgres-only, listes d'ids résolues.
- [Source: app/Services/Agent/Contracts/StateProvider.php] — contrat consommateur Epic 27.
- [Source: app/Providers/AgentServiceProvider.php#register] — enregistrement singleton.
- [Source: database/migrations/2026_06_13_140000_add_agent_reported_version_to_workstations.php] — style migration idempotente.
- [Source: resources/views/pages/parc-settings/index.blade.php + _partials/agent/releases-rings.blade.php] — pattern onglets + Livewire SFC + WithToasts.
- [Source: app/Components/Traits/WithToasts.php] — toasts.

## Questions pour Henri (à trancher avant/pendant le dev)

1. **Emplacement UI** : onglet « Environnement » dans `parc-settings/index.blade.php` (proposé, D3) OU sur l'écran d'édition d'un groupe ? Et : exposer pour les parcs logiques seulement, ou aussi les groupes physiques (salles) ?
2. **Gate** : quelle autorisation pour la sélection d'environnement d'un parc (réutiliser laquelle des policies parc existantes) ?

## Dev Agent Record

### Agent Model Used

opus (Opus 4.8 1M context)

### Debug Log References

- Migration appliquée sur VM : `2026_06_13_150000_add_environment_to_workstation_groups … 31.21ms DONE`. Colonne vérifiée : `environment varchar, nullable … character varying(32)` ; `Schema::hasColumn('workstation_groups','environment')` = true.
- Échec transitoire des 2 tests Livewire `save()` : `Gate::before` n'est PAS évalué pour un invité (Laravel saute les before-hooks sans user authentifié). Corrigé en ajoutant un `actingAs(User)` dans `setUp()` du test (le `Gate::before` n'autorise alors que `update-workstationGroup`). Confirmé via tinker (`Gate::allows` passe de `false` à `true` une fois loggé).

### Completion Notes List

- **Gate retenue** : `update-workstationGroup` → `WorkstationGroupPolicy::update` → `canAdminComputers` → permission Spatie **`computer.install` (droit admin GLOBAL, SANS délégation scopée)**. C'est exactement l'autorisation déjà employée pour l'édition d'un parc dans `resources/views/pages/parc/groups/[id]/index.blade.php` (deux usages existants `Gate::authorize('update-workstationGroup', $this->group)`). Aucune gate inventée. ⚠️ **Correction review 26.1 (P1)** : la note initiale prétendait à tort `computer.control` + délégation scopée — c'est `manage-workstationGroup` qui porte cette sémantique, et elle n'est PAS utilisée ici. Le choix fonctionnel `update` (admin global, défaut retenu, cohérent avec l'édition de parc) vs `manage` (activerait la délégation scopée) est laissé en **question ouverte à Henri** (cf. `_bmad-output/codeReviews/26-1.md`).
- **Décision D1 (précédence hors enum)** appliquée strictement : la constante `PRECEDENCE` et la logique vivent dans `WorkstationEnvironmentResolver` ; l'enum n'expose qu'un `label()` UI. Parallèle exact à `StateMaille`/`StateCompiler`.
- **Décision D2 (null vs default SQL)** : colonne nullable sans default SQL ; le défaut `shared_local` est résolu côté service. L'UI permet de remettre « Non déclaré » (écrit null). Distinction « parc non configuré » / « parc explicitement partagé » préservée, pas de backfill.
- **Décision D3 (emplacement UI)** = option (a) : onglet « Environnement » dans `parc-settings/index.blade.php`, SFC Livewire. Exposé pour les parcs **logiques ET physiques** (le resolver traite les deux mailles ; les logiques sont listés en premier via `orderBy('is_physical')`).
- **Consommation Epic 27** : `resolveForGroupIds(array $ids)` est le point d'entrée privilégié — il consomme directement `TargetContext::workstationGroupIds()` sans re-requêter les appartenances (discipline `TargetContext`). `resolve(Workstation)` est la commodité côté UI/CLI.
- **Note de transition respectée** : ZÉRO retrofit legacy. `ApplicationScriptsGenerator`, `ShortcutCompilerService`, pansement Bug C (`4e5a152`) non touchés ; le service n'est branché sur aucun chemin legacy (vérifié : seules références = enregistrement DI + tests).
- **Discipline Postgres-only** vérifiée : le resolver ne fait que des requêtes Eloquent sur `workstation_groups` ; aucun appel AD/LdapRecord/APCu.
- `pluck('environment')` retourne des instances d'enum (le cast modèle s'applique) → la comparaison `$declared->contains($candidate)` opère sur des enums, robuste.
- Tests : 17 passed sur la suite ciblée, 354 passed (1290 assertions) sur `--filter Agent` (inclut le resolver test). Aucune régression.

### File List

**Créés :**
- `app/Enums/WorkstationEnvironment.php`
- `app/Services/Agent/WorkstationEnvironmentResolver.php`
- `database/migrations/2026_06_13_150000_add_environment_to_workstation_groups.php`
- `resources/views/pages/parc-settings/_partials/environment-tab.blade.php`
- `tests/Unit/Services/Agent/WorkstationEnvironmentResolverTest.php`
- `tests/Unit/Models/WorkstationGroupEnvironmentCastTest.php`
- `tests/Feature/Livewire/ParcSettings/EnvironmentTabTest.php`

**Modifiés :**
- `app/Models/WorkstationGroup.php` (`$fillable`, `$casts`, `@property` docblock)
- `app/Providers/AgentServiceProvider.php` (import + singleton `WorkstationEnvironmentResolver`)
- `resources/views/pages/parc-settings/index.blade.php` (onglet « Environnement » + rendu du SFC)
- `docs/agent/state-providers.md` (section « Environnement de poste »)
- `docs/qa/domains/parc.md` (Section 3 — runbook QA Story 26.1, append-only)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (26-1 → review)
- `_bmad-output/implementation-artifacts/26-1-enum-workstation-environment.md` (cette story)

## Recommandation Modèle Dev

**Recommandation : `opus`.**

Justification : malgré l'apparence « petite » (un enum + une colonne), la story touche **plusieurs fichiers transverses** (enum, migration Postgres + cast modèle, nouveau service de domaine, enregistrement DI, UI Livewire, doc, tests) et porte des **décisions de logique métier critiques** : précédence multi-groupes correcte, défaut, et surtout la **discipline architecturale stricte** (lecture Postgres-only jamais l'AD ; précédence hors de l'enum façon `StateMaille`/`StateCompiler` ; identifiants figés NFR12 ; signature pensée pour la consommation par les StateProviders de l'Epic 27). Le **piège majeur** est le retrofit legacy interdit (ne pas brancher le service sur `ApplicationScriptsGenerator`/Bug C) : un modèle moins rigoureux risque de « rendre service » en câblant le legacy. Ces contraintes anti-régression et la projection vers Epic 27 justifient `opus`.
