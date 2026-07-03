# Story 35.4 : Geste UI — override de capacité par groupe d'utilisateurs

Status: ready-for-dev

<!-- Source d'autorité : _bmad-output/planning-artifacts/epics-capacites-v2.md (Epic 35 ne figure PAS dans epics.md). -->

## Story

En tant que **référent numérique**,
je veux **armer une capacité pour un groupe d'utilisateurs (élèves, direction, vie scolaire)**,
afin **d'utiliser réellement les capacités CD95 ciblées déjà seedées (Outlook, regedit) et à venir (exécutables interdits)**.

## Contexte & intention

**Trou PRODUIT, pas moteur** (Overview de l'epic) : le ciblage d'une capacité par groupe d'utilisateurs est **déjà supporté de bout en bout côté données et moteur** — le pivot `capability_assignments` est polymorphe (`Capability::userGroups()` existe), `AbstractCapabilityStateProvider::resolveOverrides()` requête déjà `assignable_type = UserGroup::class` sur `$ctx->userGroupIds`, `mailleFor()` étiquette déjà `StateMaille::UserGroup`, et `StateCompiler::specificity()` classe déjà `UserGroup` (rang 1) au-dessus de `Workstation`/`LogicalGroup`/`PhysicalGroup`/`Broadcast`. **Aucun geste UI ne l'expose** : les capacités CD95 ciblées (`outlook_disable_o365_account_creation` pour les personnels, `registry_editing_disabled` pour les élèves) sont seedées avec `default_value = 'unmanaged'` — elles sont **inarmables** aujourd'hui.

**Ce que la story livre** : une section « Capacités » sur la **page d'un groupe d'utilisateurs** (convention projet : une propriété par-groupe vit sur la page du groupe, PAS sur une page capacités centrale — mémoire `feedback_per_group_property_belongs_on_group_pages`), transposition fidèle de l'onglet « Options / Capacités » des parcs (`capabilities-tab`, 27.12→29.8) : poser / modifier / retirer un override, sélecteur piloté par `value_type`/`options`, warning confirmé, gel `overrides_locked`, audit `capability_override_audit_logs`, gate scopé serveur-autoritatif.

**Ce que la story ne touche PAS** : le compilateur (D2 — la maille existe), les providers, l'agent (`agent/**`), le contrat, les golden files. Story serveur/UI **pure** — zéro bump de version agent.

## ⚠️ Pièges & tensions (lire AVANT de coder)

1. **Piège #1 — anti-piège délégation WPKG (29.1) : pas de Gate global non scopé, et pas de FUITE de délégation par-salle.** L'infrastructure de délégation du projet est **par-WorkstationGroup uniquement** (`Delegation.workstation_group_id`, `PermissionService::canOnWorkstationGroup()`) — il n'existe AUCUNE délégation par-UserGroup ni par-établissement. Conséquence à double tranchant :
   - un refnum qui ne détient `app.customize` **que par délégation scopée sur une salle** (aucun droit Spatie global) ne doit PAS pouvoir écrire d'overrides par groupe d'utilisateurs (sinon la délégation par-salle fuiterait sur toute la population de l'établissement — exactement le piège WPKG inversé) ;
   - le contrôle passe par un **gate nommé de policy** (`customize-userGroup`, nouvelle entrée de `GroupPolicy::$gates`) exigeant le droit Spatie **global** `app.customize` — PAS un `auth()->user()->can('app.customize')` inline éparpillé dans le composant. Le « scopé à l'établissement du groupe » de l'epic est satisfait par construction : la base SE5 est **par-établissement** (un serveur = un établissement, `Etablissement` n'existe qu'en config/LDAP, `user_groups` n'a pas de colonne etab) — le droit global sur CETTE instance EST le droit sur l'établissement du groupe. Documenter ce raisonnement dans le docblock du gate : c'est LE point d'extension unique si une délégation par-UserGroup naît un jour.
2. **Piège #2 — périmètre serveur-autoritatif (29.6) : `#[Locked] int $groupId` + garde dans `mount()` ET dans CHAQUE mutation.** En Livewire, toute propriété publique est hydratée depuis le client. Sans `#[Locked]`, un payload falsifié re-cible un autre groupe ; sans re-garde dans `saveOverride()`/`removeOverride()`, un rejeu contourne le garde du mount. Transposer `guardCustomize()` du `capabilities-tab` à l'identique (assigner `groupId` AVANT le garde dans `mount()`).
3. **Piège #3 — les gardes front ne suffisent JAMAIS (leçon 27.12).** `is_active`/`overrides_locked` doivent être re-validés dans `saveOverride()` (recharger la capacité filtrée `is_active`), et « nouvel override » se dérive de l'**EXISTENCE EN BASE** (`$hasExistingOverride`), jamais du flag client `isEditing`. Une capacité gelée (`overrides_locked`) déjà overridée reste éditable ; aucune gelée ne peut RECEVOIR un nouvel override — c'est la sémantique exacte du parc, à transposer.
4. **Piège #4 — audit atomique, jamais de trace fantôme (29.5).** `old_value` lue AVANT la mutation ; mutation du pivot + `CapabilityOverrideAuditLog::log()` dans une **MÊME `DB::transaction()`** ; `removeOverride()` sur un override inexistant (rejeu) = **aucun acte, aucune trace** ; l'`action` (`create`/`update`/`delete`) dérivée de l'existence en base. Le modèle est append-only (`save()` lève sur UPDATE).
5. **Piège #5 — `updateOrInsert` avec closure pour ne PAS écraser `created_at` (29.7).** Sur UPDATE d'un override existant, `created_at` du pivot n'est PAS réécrit ; sur INSERT il est posé à `now()`. Copier la closure `fn (bool $exists) => …` du `capabilities-tab` telle quelle.
6. **Piège #6 — capacités « assignables » ≠ toutes les capacités actives.** Un override par UserGroup ne mord qu'à travers le **provider Session** (`RegistryUserCapabilityProvider`, ruche HKCU) : le contexte user (`TargetContext::$userGroupIds`) n'existe qu'en session. Proposer une capacité **machine-only** (clés HKLM uniquement, ex. `cached_logons_count`) au ciblage par groupe poserait un override **inerte** — piège produit silencieux. Filtre : « assignable par groupe d'utilisateurs » = capacité active dont la projection `windows`/`registry` porte **≥ 1 clé `hive: HKCU`** dans sa `spec` (les deux cibles CD95 — Outlook et regedit — sont 100 % HKCU, vérifié au seed). NB : `HKU` (35.3) ne compte PAS — cette ruche est portée machine, « pas de ciblage par utilisateur » (AC 35.3). Le filtre se calcule en PHP sur les projections eager-loadées (pas de JSON query SQLite-hostile).
7. **Piège #7 — la modale et la directive `@livewire`.** Les crochets `[id]` du chemin SFC **cassent la tag-syntax Blade** : inclure le partial via `@livewire('pages::users.groups.[id]._partials.capabilities-section', ['groupId' => $groupId], key('capabilities-' . $groupId))` (précédent : `class-share-section`, `group-quota-section` dans `index.blade.php`). Modale = `x-molecules.modal` réutilisable (wire:model + closeMethod), toasts = trait `WithToasts` — conventions projet non négociables.
8. **Piège #8 — test de précédence : le Broadcast de `registry_editing_disabled` n'émet RIEN.** Son défaut seedé est `'unmanaged'` et sa map `['on' => 1]` : la sentinelle UNMANAGED ⇒ aucun candidat Broadcast pour cette clé. « L'override UserGroup bat le Broadcast » se prouve donc en DEUX volets (cf. AC5) : (a) sur **données réelles seedées** — membre du groupe overridé ⇒ item `DisableRegistryTools = 1` compilé en session ; user non-membre ⇒ AUCUN item pour cette clé (le Broadcast unmanaged n'émet rien) ; (b) sur **fixture synthétique** où les DEUX mailles émettent des valeurs divergentes sur la même clé ⇒ la valeur UserGroup gagne au compilé (miroir du test parc existant de `CapabilityRegistryCompilationTest`, StateCompiler INTOUCHÉ).
9. **Piège #9 — verrou amont (29.2/29.8) : réutiliser, pas réinventer.** Le gate `modify-capability` est **instance-wide** (verrou amont seul depuis 29.8, aucun plancher de droit) : il s'applique à TOUTE surface d'override, maille UserGroup comprise (un override UserGroup sur une capacité `locked` serait de toute façon battu au compilé — `Upstream` rang -1). Transposer `authorizeUpstream()` (Gate::authorize + toast explicite) en defense-in-depth. L'`upstream_status` de l'audit (`permissive`|`local`) se résout par le même `UpstreamLockResolver` (court-circuit NFR3 sans contrat). Les badges tri-état 29.4 sont HORS scope (surface parc) — ne pas les transposer.
10. **Piège #10 — tests HÔTE, schéma de permissions.** Tests Feature Livewire sur l'HÔTE (php8.4 + sqlite, filtres ciblés — jamais de run massif). Le schéma Spatie se pose via `CreatesPermissionSchema` + `PermissionSeeder` (pattern `ClassShareSectionTest`) ; les observers AD se désactivent (`UserGroupObserver::disableSync()` / `UserGroupUserPivotObserver::disableSync()`) sinon jobs LDAP dispatchés. Zéro AD/LdapRecord/APCu dans les chemins serveur.
11. **Piège #11 — UX forms (mémoire `feedback_form_label_above_input_tooltip_hints`).** Label AU-DESSUS de l'input, hints en tooltip (pas de texte d'aide inutile sous les champs), étoile sur le champ obligatoire (la valeur d'override). Le markup de la modale parc respecte déjà « label au-dessus » — le transposer en y ajoutant l'étoile sur « Valeur pour ce groupe ».

## Décisions de design (tranchées)

1. **Emplacement** : nouveau partial Livewire SFC `resources/views/pages/users/groups/[id]/_partials/capabilities-section.blade.php`, inclus dans `index.blade.php` (mode consultation, après `group-quota-section`), pour **tous les types de groupes** (classe, custom, équipe… — les cibles CD95 sont « élèves » = classes ET « direction/vie scolaire » = groupes custom). Rendu conditionné `@can('customize-userGroup')` côté page (UI) + garde 403 dans le SFC (serveur).
2. **Présentation** (AC epic, ≠ onglet parc qui ne liste QUE les overrides) : la section liste **TOUTES les capacités actives assignables** (filtre HKCU du piège #6) avec, pour ce groupe : la valeur d'override si elle existe (libellé d'option), sinon « Suit le défaut » avec le **libellé d'option du défaut** (`optionLabel(default_value)` — ex. « Non géré » pour `registry_editing_disabled`). Ligne avec override → actions « Éditer » / « Retirer » ; ligne sans → action « Dévier » (refusée si `overrides_locked` : bouton absent + mention lecture seule ; refusée si verrou amont : toast 29.2).
3. **Gate** : `customize-userGroup` ajouté à `GroupPolicy::$gates` → méthode `customize(?Authenticatable $user): bool` = `$this->hasPermission($user, 'app.customize')` (permission capacités EXISTANTE — aucune permission nouvelle). Docblock : rationale « établissement = instance » + refus explicite du délégué par-salle (piège #1) + point d'extension futur.
4. **Audit** : `CapabilityOverrideAuditLog` réutilisé **TEL QUEL** (aucune migration) — la table est déjà polymorphe (`assignable_type`/`assignable_id` + `scope_label` dénormalisé). `assignableType: UserGroup::class`, `scopeLabel: $group->display_name ?? $group->name`, `upstream_status` résolu comme au parc.
5. **Sémantique du retrait** : identique au parc — retirer l'override = **revenir au défaut au cycle suivant** (re-convergence), PAS « cesser de gérer ». Toast explicite (transposer le message parc).
6. **Pas de sur-conception** (mémoire `feedback_no_overengineered_choices`) : pas de badges tri-état amont (29.4, surface parc), pas de délégation par-UserGroup, pas de pagination, pas d'historique d'audit affiché (l'audit s'écrit, il ne se consulte pas ici).

## Acceptance Criteria

### AC1 — Section « Capacités » sur la page d'un groupe d'utilisateurs

**Given** la page d'édition d'un groupe d'utilisateurs (`/app/users/groups/{id}`, mode consultation)
**When** un admin (droit global `app.customize`) ouvre la section « Capacités »
**Then** il voit les capacités **actives assignables** (filtre : projection `windows`/`registry` avec ≥ 1 clé HKCU) avec, pour CE groupe : la valeur d'override si elle existe (libellé d'option), sinon « Suit le défaut » accompagné du **libellé d'option du défaut**
**And** il peut **poser** un override (modale `x-molecules.modal`, sélecteur piloté par `value_type`/`options` — select si `hasOptions()`, champ texte sinon — pré-rempli avec le défaut), le **modifier** (pré-rempli avec l'override courant), le **retirer** (toast : retour au défaut au cycle suivant, PAS « cesser de gérer »)
**And** la valeur saisie est **re-validée SERVEUR** contre `value_type`/`options` (`allowedOptionValues()`, scalaire non vide — SQLite n'applique aucune contrainte)
**And** si la capacité porte un `warning`, l'encart est affiché dans la modale et la persistance exige la case de confirmation cochée (erreur de validation sinon)
**And** UX forms : label au-dessus du champ, étoile sur le champ obligatoire, hints en tooltip uniquement.

### AC2 — Capacité `overrides_locked` : ajout refusé

**Given** une capacité active avec `overrides_locked = true`
**Then** l'action « Dévier » n'est pas proposée pour cette ligne (lecture seule, mention explicite du gel)
**And** au niveau SERVEUR, `saveOverride()` refuse un NOUVEL override sur une capacité gelée (toast d'erreur, dérivé de l'existence en base — pas du flag client), même si l'UI est contournée (rejeu Livewire)
**And** un override EXISTANT sur une capacité gelée reste éditable/retirable (sémantique 27.12, iso parc).

### AC3 — Audit `capability_override_audit_logs`

**Given** un override posé / modifié / retiré via cette section
**Then** une ligne `capability_override_audit_logs` est écrite dans la **MÊME transaction** que la mutation du pivot : `action` (`create`/`update`/`delete` — dérivée de l'existence en base), acteur (`actor_user_id` + `actor_login` dénormalisé), `assignable_type = App\Models\UserGroup`, `assignable_id`, `scope_label` (display_name ou name du groupe), `old_value` (lue AVANT la mutation) / `new_value` (null au delete), `upstream_status` (`permissive`|`local` via `UpstreamLockResolver`)
**And** le retrait d'un override inexistant (rejeu) n'écrit AUCUNE trace (pas de trace fantôme)
**And** sur UPDATE, le `created_at` du pivot n'est PAS réécrit (closure `updateOrInsert`, 29.7)
**And** le format est **cohérent avec l'audit des overrides par parc** : même modèle, même fabrique `::log()`, mêmes constantes — seuls `assignable_type`/`scope_label` diffèrent.

### AC4 — Délégation : gate scopé, pas de fuite

**Given** le geste d'écriture (poser/modifier/retirer)
**Then** il est gaté par le gate nommé `customize-userGroup` (GroupPolicy) exigeant la permission capacités EXISTANTE `app.customize` (droit Spatie global sur cette instance = l'établissement du groupe — la base SE5 est par-établissement)
**And** le garde est SERVEUR-AUTORITATIF : `#[Locked] int $groupId`, garde 403 dans `mount()` ET dans chaque mutation — jamais seulement masqué en UI
**And** un refnum détenant `app.customize` **uniquement par délégation scopée sur une salle** (aucun droit global) est REFUSÉ (403) sur cette section — la délégation par-salle ne fuite pas sur les groupes d'utilisateurs (anti-piège WPKG 29.1), test à l'appui
**And** une capacité verrouillée par contrat amont est refusée à l'écriture (gate `modify-capability` en defense-in-depth, toast explicite — 29.2) ; sans contrat amont actif, court-circuit NFR3 (aucune requête `controlhub_contract_items`).

### AC5 — Précédence : compilateur intouché, test d'intégration sur le lot CD95

**Given** `StateCompiler` et les providers de capacités
**Then** AUCUNE ligne n'y est modifiée (la maille `UserGroup` existe déjà : `resolveOverrides()`/`mailleFor()`/`specificity()`)
**And** un test d'intégration sur **données réelles seedées** prouve l'armement de `registry_editing_disabled` (capacité Session du lot CD95) : un override `on` posé sur un UserGroup ⇒ pour un **user membre**, l'état compilé session contient `DisableRegistryTools = 1` (HKCU, `Policies\System`) ; pour un **user non-membre**, AUCUN item pour cette clé (le Broadcast `unmanaged` n'émet rien)
**And** un test compilateur sur fixture synthétique prouve la précédence quand les DEUX mailles émettent : Broadcast `off` → valeur X et override UserGroup `on` → valeur Y sur la MÊME clé ⇒ Y gagne au compilé (et l'inverse), miroir du test parc existant.

## Tasks / Subtasks

- [ ] **Task 1 — Gate `customize-userGroup` (AC4)**
  - [ ] 1.1 `app/Policies/GroupPolicy.php` : ajouter `'customize-userGroup' => 'customize'` à `$gates` + méthode `customize(?Authenticatable $user): bool` = `$this->hasPermission($user, 'app.customize')`. Docblock complet : permission capacités existante, rationale « instance = établissement du groupe », refus assumé du délégué par-salle (pas de délégation par-UserGroup dans le modèle — point d'extension unique si elle naît), référence anti-piège 29.1.
  - [ ] 1.2 Test policy (dans le test Feature de la Task 3 ou `tests/Unit/Policies/`) : admin global passe ; user `app.customize` global passe ; user avec SEULEMENT `grantDelegation($user, 'app.customize', $salle)` (pattern `CapabilitiesTabCustomizeScopingTest`) est refusé ; invité refusé.
- [ ] **Task 2 — SFC `capabilities-section` (AC1, AC2, AC3, AC4)**
  - [ ] 2.1 Créer `resources/views/pages/users/groups/[id]/_partials/capabilities-section.blade.php` (Livewire SFC anonyme, `new class extends Component`) en **transposant** `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` : `use WithToasts`, `#[Locked] public int $groupId`, propriétés modale (`showOverrideModal`, `editingCapabilityId`, `isEditing`, `formValue`, `warningAcknowledged`), `mount()` (groupId AVANT garde), `guardCustomize()` → `abort_unless(auth()->check() && Gate::allows('customize-userGroup'), 403, …)`, `authorizeUpstream()` (Gate `modify-capability` + toasts) repris tel quel.
  - [ ] 2.2 Computed `capabilities()` : capacités `is_active` filtrées « assignables » (≥ 1 clé `hive === 'HKCU'` — insensible à la casse — dans la `spec` de la projection windows/registry eager-loadée), jointes aux overrides du groupe (`capability_assignments` where `assignable_type = UserGroup::class and assignable_id = $groupId`) ; chaque ligne : label, description, catégorie, `has_override`, `override_display` (optionLabel), `default_display` (optionLabel du défaut), `has_warning`, `overrides_locked`. Tri par label. Rejeter les capacités verrouillées amont du geste « Dévier » (iso `addableCapabilities` parc, resolver mémoïsé pré-instancié hors boucle — pas de N+1).
  - [ ] 2.3 Mutations `openAdd()`/`openEdit()`/`closeModal()`/`saveOverride()`/`removeOverride()` : transposer le parc en remplaçant `WorkstationGroup::class` → `UserGroup::class` et le garde scopé → `guardCustomize()` de 2.1. Conserver : re-validation serveur `is_active` + gel dérivé de l'existence en base (piège #3), `validatedValue()` (options/scalaire), warning exigé, `old_value` avant mutation, `DB::transaction` mutation+audit, closure `updateOrInsert` created_at (piège #5), pas de trace fantôme au remove, `resolveActor()` (id + login dénormalisé), `upstream_status` via `UpstreamLockResolver`, `unset()` des computed après mutation. `scopeLabel` = `display_name ?? name`.
  - [ ] 2.4 Markup : tableau des capacités (colonnes Capacité / Catégorie / Valeur pour ce groupe / Défaut / Actions), encart d'intro expliquant « override par groupe d'utilisateurs — retirer = revenir au défaut », modale `x-molecules.modal` (étapes : pas de picker séparé — le « Dévier » de chaque ligne ouvre directement la modale pré-remplie), sélecteur `value_type`/`options`, encart warning + checkbox, `data-testid` sur chaque contrôle (pattern parc : `open-add-…`, `override-select`, `ack-warning`, `save-override`, `edit-override-{id}`, `remove-override-{id}`). UX : label au-dessus, étoile sur « Valeur pour ce groupe * », hints en tooltip (piège #11).
  - [ ] 2.5 `resources/views/pages/users/groups/[id]/index.blade.php` : inclure la section en mode consultation (après `group-quota-section`), pour tous les types de groupes, gatée `@can('customize-userGroup')`, via la directive `@livewire(…, key('capabilities-' . $groupId))` (piège #7 — les crochets cassent la tag-syntax).
- [ ] **Task 3 — Tests Feature Livewire (AC1, AC2, AC3, AC4)**
  - [ ] 3.1 Créer `tests/Feature/Livewire/Users/GroupCapabilitiesSectionTest.php` (pattern `ClassShareSectionTest` : `CreatesPermissionSchema` + `PermissionSeeder`, observers AD désactivés, `componentPath()` = `pages::users.groups.[id]._partials.capabilities-section`) couvrant :
    (a) listing : capacité HKCU active affichée avec « Suit le défaut » + libellé du défaut ; capacité machine-only (HKLM) ABSENTE ; capacité inactive absente ;
    (b) pose d'un override (`registry_editing_disabled` → `on`) : pivot écrit (`assignable_type = UserGroup::class`), toast, valeur affichée ;
    (c) édition : valeur mise à jour, `created_at` du pivot préservé ;
    (d) retrait : pivot supprimé, message « retour au défaut » ; retrait sur inexistant = aucun audit ;
    (e) validation : valeur hors `options` rejetée ; warning non confirmé rejeté ;
    (f) `overrides_locked` : nouvel override refusé (toast), override existant toujours éditable ;
    (g) audit : lignes `create`/`update`/`delete` complètes (acteur, groupe, old/new, scope_label, upstream_status=local) — pattern d'asserts de `CapabilitiesOverrideAuditTest` ;
    (h) scoping : sans `app.customize` → 403 au mount ; délégué par-salle SEULEMENT → 403 (Task 1.2) ; mutation directe sans droit → 403.
- [ ] **Task 4 — Test d'intégration précédence (AC5)**
  - [ ] 4.1 `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` (données réelles seedées, pattern Task 4.3 de 35.1) : poser un override `on` de `registry_editing_disabled` sur un UserGroup ; user MEMBRE (via `user_group_user`) ⇒ les items du `RegistryUserCapabilityProvider` compilés (`StateCompiler`) contiennent `{hive: HKCU, path: …\Policies\System, name: DisableRegistryTools, value: 1}` ; user NON-membre ⇒ aucun item pour cette clé (Broadcast `unmanaged` n'émet rien — piège #8).
  - [ ] 4.2 `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php` : test synthétique « override UserGroup bat le Broadcast » quand les DEUX émettent — capacité `default 'off'` → valeur X, override UserGroup `'on'` → valeur Y sur la même clé HKCU, le compilé porte Y (et le miroir : override `off`, broadcast `on`). Réutiliser les helpers du fichier (`makeCapability`, pattern du test parc l.157) et `TargetContext::for($ws, $user)` avec membership réel. `StateCompiler` INTOUCHÉ.
- [ ] **Task 5 — Validation finale**
  - [ ] 5.1 Tests HÔTE ciblés (php8.4 + sqlite, JAMAIS de run massif) : `php artisan test --filter='GroupCapabilitiesSectionTest'`, `--filter='CapabilitiesSchemaAndSeedTest'`, `--filter='CapabilityRegistryCompilationTest|CapabilityRegistryProviderTest'`, et non-régression `--filter='CapabilitiesTabTest|CapabilitiesOverrideAuditTest|CapabilitiesTabCustomizeScopingTest'` (surface parc intouchée) + `--filter='GroupShowPageTest|GroupShowMembersTabsTest'` (page groupe).
  - [ ] 5.2 Vérifier zéro modif : `app/Services/Agent/StateCompiler.php`, providers, `agent/**`, `tests/Fixtures/Agent/` (golden), migrations existantes.

## Dev Notes

### Fichiers à toucher (exhaustif prévu)

| Fichier | Nature |
|---|---|
| `resources/views/pages/users/groups/[id]/_partials/capabilities-section.blade.php` | NOUVEAU — SFC Livewire, transposition de `capabilities-tab` |
| `resources/views/pages/users/groups/[id]/index.blade.php` | modifié — inclusion `@livewire` de la section (mode consultation) |
| `app/Policies/GroupPolicy.php` | modifié — gate `customize-userGroup` → `customize()` (`app.customize` global) |
| `tests/Feature/Livewire/Users/GroupCapabilitiesSectionTest.php` | NOUVEAU — CRUD/validation/gel/audit/scoping |
| `tests/Feature/Migrations/CapabilitiesSchemaAndSeedTest.php` | modifié — test d'intégration `registry_editing_disabled` membre/non-membre |
| `tests/Unit/Services/Agent/CapabilityRegistryCompilationTest.php` | modifié — précédence UserGroup > Broadcast (deux sens) |

**NE PAS TOUCHER** : `app/Services/Agent/StateCompiler.php` (D2), `app/Services/Agent/Providers/*` (la maille existe — `resolveOverrides`/`mailleFor` couvrent déjà UserGroup), `agent/**` + `agent/shared/version.go` (story serveur/UI pure — zéro bump), `tests/Fixtures/Agent/` (golden files), `app/Models/CapabilityOverrideAuditLog.php` + sa migration (réutilisés tels quels), la surface parc (`capabilities-tab.blade.php`, `WorkstationGroupPolicy`), `CapabilityPolicy` (29.8 : le gate `modify-capability` ne porte pas de droit — le droit vit dans la surface), le seed `2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php`, `routes/web.php` (la route `/users/groups/{id}` existe), `sprint-status.yaml` / `backlog.data.js` / `backlog.html` (orchestrateur).

### Patron à transposer (le cœur de la story)

`resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (27.12→29.8) est le **modèle de référence** — la story est une transposition disciplinée, pas une réinvention :

| Aspect | Parc (existant) | UserGroup (cette story) |
|---|---|---|
| `assignable_type` du pivot | `WorkstationGroup::class` | `UserGroup::class` |
| Garde de droit | `Gate::allows('customize-workstationGroup', WorkstationGroup::find($groupId))` (délégation par-salle) | `Gate::allows('customize-userGroup')` (global `app.customize` — pas de délégation par-UserGroup) |
| Listing | overrides SEULS + picker d'ajout | TOUTES les capacités assignables (« Suit le défaut » sinon) — exigence AC epic |
| Filtre d'assignabilité | aucun (toute capacité active non gelée) | ≥ 1 clé HKCU dans la spec registry (piège #6) |
| Verrou amont | `authorizeUpstream()` (`modify-capability`) | identique (instance-wide) |
| Audit | `CapabilityOverrideAuditLog::log(…, WorkstationGroup::class, …, $parc->name, …)` | identique avec `UserGroup::class` + `display_name ?? name` |
| Badges tri-état 29.4 | oui | NON (hors scope) |

À conserver à l'identique : `#[Locked]`, ordre mount (groupId puis garde), re-validation serveur `is_active`/gel, `validatedValue()`, warning + `warningAcknowledged`, `resolveActor()`, transaction acte↔trace, closure `updateOrInsert`, pas de trace fantôme, `unset()` des computed.

### Chaîne moteur déjà en place (ne rien coder, s'y appuyer)

- `app/Models/Capability.php` : `userGroups()` (MorphToMany pivot `capability_assignments`), `hasOptions()`, `allowedOptionValues()`, `optionLabel()`, `hasWarning()`, `overrides_locked`.
- `app/Services/Agent/Providers/AbstractCapabilityStateProvider.php` : `resolveOverrides()` requête déjà `UserGroup::class` sur `$ctx->userGroupIds` ; `mailleFor()` → `StateMaille::UserGroup`.
- `app/Services/Agent/StateCompiler.php::specificity()` : `User(0) > UserGroup(1) > Workstation(2) > LogicalGroup(3) > PhysicalGroup(4) > Broadcast(5)`.
- `app/Services/Agent/TargetContext.php::for()` : `userGroupIds` = `$user->groups()->pluck('user_groups.id')` (membership SQL `user_group_user`).
- Preuve existante côté provider : `CapabilityRegistryProviderTest::targets_workstation_and_user_group_mailles_too` (l.454) — l'override UserGroup émerge à la maille `UserGroup`. Le manque comblé par la Task 4 : la preuve au COMPILÉ.
- Cible du test d'intégration : `registry_editing_disabled` (seed CD95 l.251) — Session, toggle `unmanaged|on`, clé `HKCU\Software\Microsoft\Windows\CurrentVersion\Policies\System\DisableRegistryTools` (REG_DWORD, map `['on' => 1]`). L'autre cible armée par la story : `outlook_disable_o365_account_creation` (HKCU aussi).

### Rappels transverses (garde-fous epic)

- StateCompiler INTOUCHÉ ; zéro modif `agent/**` ni golden — pas de bump `version.go`.
- Zéro AD/LdapRecord/APCu dans les chemins serveur (critère Keycloak) ; observers AD désactivés dans les tests.
- Drift STRICT (27.8) inchangé — rien à faire, rien à altérer.
- Tests sur l'HÔTE (php8.4 + sqlite), filtres ciblés uniquement.
- Aucune migration dans cette story → rien à rejouer sur /vm (le seed CD95 y est déjà).

### Project Structure Notes

- Routing filesystem-based : la page `/app/users/groups/{id}` existe (`routes/web.php` l.115 → `pages::users.groups.[id].index`) — AUCUNE route nouvelle.
- Partials de page : `resources/views/pages/users/groups/[id]/_partials/` (précédents SFC : `class-share-section`, `group-quota-section`, `head-teacher-section`) — inclusion par directive `@livewire` (crochets vs tag-syntax).
- Composants transverses : modale `x-molecules.modal` (+ `x-molecules.modal.section`), toasts `App\Components\Traits\WithToasts`.

### References

- [Source: _bmad-output/planning-artifacts/epics-capacites-v2.md#Story-35.4 + #Overview (trou produit) + #Garde-fous-d'epic] — autorité de cadrage (Epic 35 absent d'epics.md)
- [Source: resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php] — patron intégral à transposer (27.12→29.8)
- [Source: app/Models/Capability.php#userGroups()/optionLabel()/allowedOptionValues() ; app/Models/CapabilityOverrideAuditLog.php#log() (append-only, 29.5)]
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php#resolveOverrides()/mailleFor() ; app/Enums/StateMaille.php ; app/Services/Agent/StateCompiler.php#specificity()] — maille UserGroup existante
- [Source: app/Policies/GroupPolicy.php + app/Policies/Traits/RegistersGates.php ; app/Policies/WorkstationGroupPolicy.php#customize() (29.6) ; app/Policies/CapabilityPolicy.php (29.2/29.8)]
- [Source: database/migrations/2026_07_02_100000_seed_capabilities_gpo_cd95_lot.php#registry_editing_disabled (l.251) + #outlook_disable_o365_account_creation (l.237)]
- [Source: tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php + CapabilitiesTabCustomizeScopingTest.php ; tests/Feature/Livewire/Users/ClassShareSectionTest.php (setup schéma permissions + componentPath)]
- [Source: tests/Unit/Services/Agent/CapabilityRegistryProviderTest.php#targets_workstation_and_user_group_mailles_too + CapabilityRegistryCompilationTest.php (précédence au compilé)]
- [Source: _bmad-output/implementation-artifacts/29-5-drift-strict-et-audit-des-overrides.md, 29-6-scoper-app-customize-par-workstationgroup.md, 29-7-ne-pas-ecraser-created-at-pivot-capability-assignments.md] — leçons transposées

## Dépendances

- **En amont (intra-epic) : AUCUNE.** 35.4 est indépendante de 35.1/35.2/35.3/35.5 (parallélisable) — elle n'expose qu'un geste UI sur une mécanique 27.x/29.x déjà livrée : pivot polymorphe (27.12), maille UserGroup au provider/compilateur (27.3/27.12), audit d'overrides (29.5), discipline de garde scopé (29.6), préservation created_at (29.7), gate verrou amont sans plancher (29.8), seed CD95 palier A (`2026_07_02_100000`).
- **En aval** : 35.2 (`blocked_executables`, « cible = override UserGroup élèves ») et 35.6 gated (`rdp_denied_for_group`) **consomment ce geste UI** pour être armées ; sans 35.4 elles seraient inarmables comme Outlook/regedit aujourd'hui.

## Recommandation Modèle Dev

**opus** — prescription explicite de l'epic (garde-fous : « opus pour l'UI (35.4) »), confirmée par l'analyse : story UI/serveur pure (Livewire SFC + policy + tests), zéro Go, zéro contrat/golden, et un patron existant complet à transposer (`capabilities-tab`) — le risque principal est la discipline de transposition (gardes serveur, audit atomique), largement balisée par les pièges ci-dessus.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
