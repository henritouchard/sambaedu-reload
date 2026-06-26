# Story 29.2: Refuser la modification d'un item verrouillé

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **refnum** (administrateur local SE5),
I want **que toute tentative de modifier localement un item imposé en état « verrouillé » par le contrat amont soit refusée explicitement (côté serveur, pas seulement masquée dans l'UI)**,
so that **je ne puisse pas défaire ce que l'autorité amont impose, et que je comprenne pourquoi le réglage ne s'enregistre pas (FR3)**.

> Story **d'enforcement** : elle pose le **verrou d'écriture** par-dessus la résolution livrée en 28.3. Rappel du modèle (28.3) : un item amont `locked` **gagne déjà au compilé** (`StateCompiler`, maille `Upstream`) — mais **rien n'empêche aujourd'hui** le refnum d'éditer sa config locale ; l'édition est simplement **silencieusement défaite** au prochain cycle. 29.2 transforme ce « défait en silence » en **refus explicite à l'écriture** (service + Gate + message). [Source: 28-3-resolution-amont-local-statecompiler.md L.84 « 28.3 ne bloque aucune édition »]

> **Périmètre du verrou en 29.2 = items ciblant l'instance, état `locked` UNIQUEMENT.** C'est exactement le sous-ensemble qu'injecte déjà `UpstreamContractSource` au compilé (`target_type = instance`, `label` différé Epic 30). Un item `permissive` **n'est pas** bloqué ici — sa surcharge par `workstationGroup` est précisément la **Story 29.3** (FR4). Un item `absent` n'impose rien. Ne **sur-bloquez pas** : seul `locked` refuse. [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php L.129-139]

> **Réutilisation du patron 29.1 (gate registration), PAS de sa logique de périmètre.** 29.1 a livré l'infrastructure d'enregistrement de gate scopé (`WorkstationGroupPolicy` + trait `RegistersGates` + argument modèle). 29.2 **réutilise ce patron d'enregistrement** pour exposer un gate `@can(...)` consommable en Blade ET en `Gate::authorize(...)` serveur. **Attention** : en 29.2 le verrou est **instance-wide** (l'item cible l'instance) — il ne dépend PAS d'une délégation par-salle ni d'une résolution de périmètre comme la 29.1. Le scoping par label/parc viendra en Epic 30. Ne réimportez donc PAS `canOnWorkstationGroup` ici : le verrou ne se résout pas par délégation mais par **présence d'un item `locked` au contrat actif**.

> **⚠️ Décision Henri (2026-06-26) — pas de compat ascendante exigée** : aucun environnement de prod à préserver (seul invariant intangible = enrôlement controlHub). Les garde-fous « non-régression » ci-dessous visent le **bon design** (sans contrat amont, le comportement 27.12/parc-defaults est strictement inchangé — NFR3), pas la rétrocompat d'appelants exotiques. [Source: mémoires projet — zero_prod_publish_is_test, no_legacy_transition_state]

## Contexte du code (constat vérifié 2026-06-27)

**Ce qui existe déjà (à RÉUTILISER, ne rien réinventer) :**

- **Modèle de réception** du contrat amont (Epic 28) : `ControlHubContract` (singleton « ≤ 1 actif », `link_state = active`) → `items()` de type `ControlHubContractItem` `{type, key, value, enforcement_state, target_type, target_label}`. [Source: app/Models/ControlHubContract.php ; app/Models/ControlHubContractItem.php]
- **Enums** : `ControlHubEnforcementState` (`Locked` / `Permissive` / `Absent`), `ControlHubContractTarget` (`Instance` / `Label`), `ControlHubLinkState` (`Active` / `Severed`). [Source: app/Enums/ControlHubEnforcementState.php ; app/Enums/ControlHubContractTarget.php ; app/Enums/ControlHubLinkState.php]
- **Lecture du contrat actif + sélection des items à enforcer** : `UpstreamContractSource::ensureResolved()` lit le contrat `active`, filtre `target_type = instance` et `enforcement_state IN (locked, permissive)`, court-circuit NFR3 si aucun contrat actif (≤ 1 requête, jamais la table `items`). C'est le **patron de lecture exact** que la résolution de verrou doit imiter (mais en ne retenant QUE `locked`). [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php L.114-156]
- **Identité de clé registre** (le pont item↔capacité) : un item amont `registry` a `key = "hive|path|name[|type]"` ; un candidat registre local a la clé d'exclusivité `strtolower("hive|path|name")`. **C'est la même identité** des deux côtés (c'est par elle que l'amont « gagne sur la même clé »). [Source: app/Services/ControlHub/Resolution/RegistryUpstreamAdapter.php L.89-99 ; app/Services/Agent/Providers/AbstractCapabilityStateProvider.php L.97-103]
- **Capacité ↔ registre** : une `Capability` se matérialise via ses `CapabilityProjection` (`mechanism = registry`), dont `spec = { "keys": [ {hive, path, name, type, value}, … ] }`. Pour chaque clé, l'`exclusiveKey` est `strtolower(hive|path|name)`. C'est le mapping qui relie une capacité éditable localement à une clé éventuellement verrouillée en amont. [Source: app/Models/CapabilityProjection.php L.43-58 ; app/Services/Agent/Providers/AbstractCapabilityStateProvider.php L.205-241]

**Les surfaces d'écriture LOCALE d'un item (où il faut REFUSER) :**

1. **Override de capacité PAR PARC** — `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (composant Livewire SFC). Méthodes mutantes : `openAdd()` (L.149), `openEdit()` (L.166), `saveOverride()` (L.195, `DB::table('capability_assignments')->updateOrInsert(...)`), `removeOverride()` (L.259). Garde actuelle : `guardCustomize()` (L.314, `app.customize`) — **aucun** contrôle de verrou amont.
2. **Défaut diffusé d'une capacité (niveau INSTANCE)** — `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` (Livewire SFC). Méthodes mutantes : `saveDefault()` (L.102, écrit `capabilities.default_value`), `toggleLock()` (L.87, `overrides_locked`). Garde actuelle : `guardAdmin()` (`server.admin`) — **aucun** contrôle de verrou amont. C'est la surface « source » : un item `locked` ciblant l'instance interdit aussi de changer le **défaut** de la capacité correspondante.

`overrides_locked` (Epic 27) est un **gel LOCAL** (l'admin SE5 gèle une capacité pour ses propres parcs) — **distinct** du verrou AMONT de cette story (le contrat controlHub gèle une capacité pour le refnum). Les deux coexistent ; ne pas confondre, ne pas réutiliser le flag local pour porter le verrou amont. [Source: app/Models/Capability.php L.34 `overrides_locked` ; capabilities-tab.blade.php L.227]

**Pourquoi centrer 29.2 sur les capacités/registre :** parmi les deux types amont démontrés en 28.3, `registry` est **exclusive-par-clé** (un item `locked` désigne précisément UNE clé → sémantique de verrou nette) tandis que `shortcuts` est **aggregate/union** (jamais d'effacement de raccourci local → la notion de « verrou » d'un item agrégé est ambiguë). 29.2 enforce donc les surfaces capacité/registre (verrou propre) et expose une **primitive générique** réutilisable ; l'enforcement des types aggregate est documenté comme couture (voir Dépendances / ambiguïtés). [Source: app/Services/ControlHub/Resolution/ShortcutsUpstreamAdapter.php L.12-21]

## Acceptance Criteria

1. **Given** un contrat amont **actif** impose un item `registry` en état **`locked`** ciblant l'**instance**, dont la clé `hive|path|name` correspond à une projection registre d'une `Capability` C,
   **When** le refnum tente d'**ajouter ou d'éditer un override de C sur un parc** (`openAdd` / `openEdit` / `saveOverride` de l'onglet capacités du groupe),
   **Then** l'opération est **refusée côté serveur** (le `saveOverride` n'écrit RIEN dans `capability_assignments`)
   **And** un message explicite indique que l'item est **verrouillé par un contrat amont** (toast / erreur visible), pas un échec silencieux.

2. **Given** le même item `locked` ciblant l'instance pour la capacité C,
   **When** le refnum tente de **modifier le défaut diffusé de C** (`saveDefault` de l'onglet `parc-defaults` registre),
   **Then** l'opération est **refusée côté serveur** (aucune écriture de `capabilities.default_value`)
   **And** le même message explicite « verrouillé par un contrat amont » est rendu.

3. **Given** un contrat amont actif impose la capacité C en état **`permissive`** (et non `locked`),
   **When** le refnum ajoute/édite un override de C sur un parc,
   **Then** l'opération est **autorisée** (un item permissif **n'est pas** un verrou — sa surcharge relève de la Story 29.3, jamais bloquée ici).

4. **Given** un contrat amont actif impose la capacité C en état **`absent`**, **OU** aucun contrat actif n'existe (standalone), **OU** le contrat est `severed`,
   **When** le refnum édite un override de C ou son défaut,
   **Then** l'opération est **autorisée** — comportement **strictement identique** au comportement 27.12 / parc-defaults sans contrat (NFR3) ; et dans le cas « aucun contrat actif », la table `controlhub_contract_items` n'est **pas** requêtée (court-circuit, ≤ 1 requête).

5. **Given** l'enforcement passe par un **Gate** enregistré (patron 29.1 / `RegistersGates`), p.ex. `modify-capability`,
   **When** une surface UI (boutons « Ajouter / Éditer » de l'onglet capacités du groupe et de `parc-defaults`) s'affiche pour une capacité **verrouillée amont**,
   **Then** le contrôle d'écriture est **masqué/désactivé** via `@can('modify-capability', $capability)`, avec une mention visible « Verrouillé par contrat amont » (pas un bouton qui « ne fait rien »).

6. **Given** l'enforcement est posé **au niveau service ET Gate** (defense-in-depth, « pas seulement masquée »),
   **When** une mutation de capacité verrouillée est appelée **côté serveur** malgré l'UI (rejeu Livewire, propriété publique hydratée, appel direct),
   **Then** le serveur **refuse** (lève `AuthorizationException` via `Gate::authorize('modify-capability', $capability)`), et la décision de verrou est portée par un **service dédié** (`UpstreamLockResolver` ou équivalent) unit-testable indépendamment de l'UI.

7. **Given** la suite de tests HÔTE (php8.4 + sqlite, `RefreshDatabase`),
   **When** elle s'exécute,
   **Then** sont **couverts et verts** : (a) résolution de verrou (locked→refus, permissive/absent/severed/standalone→OK, court-circuit sans contrat) ; (b) gate `modify-capability` (deny si locked, allow sinon, deny si pas `app.customize`) ; (c) refus en feature sur les deux surfaces (override parc + défaut instance) **sans écriture** ; (d) **non-régression** : sans contrat, les suites capacités 27.12 / parc-defaults restent vertes (NFR3) ; et aucune régression des suites Epic 28 (`ControlHubContract*`).

8. **Given** le garde-fou de vocabulaire R3,
   **When** on lit le code, les messages utilisateur et les noms d'identifiants introduits,
   **Then** **aucun** mot « central » n'apparaît : vocabulaire « amont » / `Upstream` / `ControlHub*`.

## Tasks / Subtasks

- [x] **T0 — Cadrage du verrou et de l'identité de clé** (AC: #1, #3, #4)
  - [x] Confirmer par lecture de code que les items à enforcer en 29.2 = `enforcement_state = locked` **ET** `target_type = instance` **ET** contrat `link_state = active` (sous-ensemble strict de ce que lit `UpstreamContractSource`, qui retient locked **+** permissive). [Ancrage : UpstreamContractSource.php L.129-139.]
  - [x] Confirmer l'identité de clé registre commune : item amont `key = hive|path|name[|type]` ↔ projection `spec.keys[].{hive,path,name}` ↔ `exclusiveKey = strtolower(hive|path|name)`. Décider la **normalisation** (lowercase, trim) et l'aligner **à l'octet** sur `AbstractCapabilityStateProvider::exclusiveKey()` (L.97-103) et `RegistryUpstreamAdapter::parts()` (L.89-99) — ne pas inventer une 3ᵉ normalisation.
  - [x] **Décision de scope** (documenter dans Dev Notes) : 29.2 enforce les types **exclusive-par-clé** (registry via capacités). Les types **aggregate** (`shortcuts`) sont hors verrou 29.2 (sémantique de lock ambiguë) — la primitive reste générique pour les accueillir plus tard. Ne pas sur-scoper.

- [x] **T1 — Service de résolution de verrou `UpstreamLockResolver`** (AC: #1, #3, #4, #6, #8)
  - [x] Créer `app/Services/ControlHub/UpstreamLockResolver.php` (singleton, mémoïsé par-requête — **imiter** le patron `UpstreamContractSource::ensureResolved()` : résolution UNE fois, court-circuit NFR3 si aucun contrat actif → **aucune** requête `items`).
  - [x] `lockedRegistryKeys(): array<string,true>` (set indexé par `exclusiveKey` normalisé) : lit le contrat **actif**, sélectionne `type = registry` **ET** `enforcement_state = locked` **ET** `target_type = instance`, normalise chaque `key` en `hive|path|name` (réutiliser la décomposition de `RegistryUpstreamAdapter::parts()` / l'algèbre de clé — ne pas dupliquer la logique, l'extraire si nécessaire). `label` ignoré proprement (Epic 30) ; `permissive`/`absent` exclus (AC #3/#4).
  - [x] `isCapabilityLocked(Capability $capability): bool` : expanse les projections `mechanism = registry` de la capacité → ensemble d'`exclusiveKey` (via `spec.keys[]`, filtrer le hive pertinent comme le provider) ; renvoie `true` si **au moins une** clé ∈ `lockedRegistryKeys()`. Eager-load `projections` (filtre mechanism=registry) pour éviter le N+1.
  - [x] `isLocked(string $type, string $key): bool` (primitive générique, extensible Epic 33) : pour `type = registry`, normalise `$key` et teste l'appartenance au set ; pour un type non démontré, renvoie `false` (couture, jamais d'exception).
  - [x] Court-circuit **NFR3 (CRITIQUE)** : si `ControlHubContract` actif est `null`, **toutes** les méthodes renvoient « non verrouillé » sans toucher `items` (test révélateur ≤ 1 requête).
  - [x] R3 : aucun « central » ; PHPDoc « amont » / `Upstream`. Enregistrer le singleton dans `app/Providers/AgentServiceProvider.php` (à côté de `UpstreamContractSource`, L.108) **ou** `AppServiceProvider` — choisir cohérent et documenter.

- [x] **T2 — Gate `modify-capability` (patron 29.1 / `RegistersGates`)** (AC: #5, #6)
  - [x] Créer `app/Policies/CapabilityPolicy.php` (calquée structurellement sur `AppCustomizationPolicy` : traits `RegistersGates` + `ChecksPermissions`, propriété `$gates`). Injecter `UpstreamLockResolver` au constructeur (le container instancie la policy → DI possible, comme l'enregistrement `[static::class, $method]` du trait).
  - [x] Méthode `modify(?Authenticatable $user, ?Capability $capability = null): bool` : `true` ssi `hasPermission($user, 'app.customize')` **ET** (`$capability === null` **OU** `! $resolver->isCapabilityLocked($capability)`). Le `null` (cas générique) retombe sur le seul droit (pas de capacité ⇒ pas de verrou applicable).
  - [x] Enregistrer le gate : `protected static array $gates = ['modify-capability' => 'modify'];` + ajouter `CapabilityPolicy::registerGates();` dans `app/Providers/AuthServiceProvider.php::boot()` (à la suite des `registerGates()` existants, L.52-65).
  - [x] PHPDoc : préciser que le verrou est **instance-wide** (item `locked` au contrat) et **distinct** de la délégation 29.1 (ne PAS réutiliser `canOnWorkstationGroup`) et du gel local `overrides_locked` (27.12).

- [x] **T3 — Brancher le refus sur l'override PAR PARC (onglet capacités du groupe)** (AC: #1, #5, #6)
  - [x] `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` : ajouter un garde serveur `Gate::authorize('modify-capability', $capability)` **en tête** des mutations après résolution de la capacité — dans `saveOverride()` (après le chargement `$capability` L.212-219), `openAdd()` (L.149), `openEdit()` (L.166), et `removeOverride()` (résoudre la `Capability` d'abord). Conserver le `guardCustomize()` (`app.customize`) existant (le gate l'inclut déjà, mais le garde explicite reste comme defense-in-depth/lisibilité — au choix, documenter).
  - [x] Capturer l'`AuthorizationException` au niveau du composant et émettre un toast explicite via `WithToasts` : « Cette capacité est verrouillée par un contrat amont et ne peut pas être modifiée localement. » (cohérent avec le `toastError` existant des autres gardes).
  - [x] UI : dans la liste des overrides et la liste « ajouter une capacité », masquer/désactiver les boutons d'écriture pour une capacité verrouillée via `@can('modify-capability', $capabilityModel)` + badge « Verrouillé par contrat amont ». **Exposer le modèle `Capability`** au Blade (les computed `overrides`/`addableCapabilities` renvoient des tableaux : ajouter un flag `is_upstream_locked` calculé via le resolver, ou résoudre le gate dans la boucle — éviter le N+1 en pré-calculant le set verrouillé une fois).
  - [x] **`removeOverride` sur item verrouillé** : décider et documenter — retirer un override d'un item verrouillé est *effectivement* inerte (l'amont gagne au compilé de toute façon), MAIS pour une UX « refus explicite » cohérente, **bloquer aussi** le retrait avec le même message (le refnum ne « touche » pas un item verrouillé). Choix par défaut = bloquer ; si le dev juge le retrait souhaitable comme nettoyage, documenter le contre-choix.

- [x] **T4 — Brancher le refus sur le DÉFAUT INSTANCE (parc-defaults registre)** (AC: #2, #5, #6)
  - [x] `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` : ajouter `Gate::authorize('modify-capability', $capability)` **en tête** de `saveDefault()` (après résolution `$capability`, autour L.102-120) et de `toggleLock()` (L.87) — éditer le défaut OU (dé)geler localement une capacité **verrouillée amont** doit être refusé. Conserver `guardAdmin()` (`server.admin`).
  - [x] Toast/erreur explicite identique (« verrouillé par un contrat amont »).
  - [x] UI : masquer/désactiver « Éditer le défaut » et « Geler » pour une capacité verrouillée amont via `@can('modify-capability', $c)` + badge ; pré-calculer le set verrouillé une fois (computed `capabilities()` L.42) pour éviter le N+1.

- [x] **T5 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #1–#8)
  - [x] **Unit `tests/Unit/Services/ControlHub/UpstreamLockResolverTest.php`** : (a) aucun contrat actif → `isCapabilityLocked() === false` **et** `DB::connection()` n'exécute qu'≤ 1 requête (court-circuit NFR3 — assert via `DB::listen`/compteur) ; (b) item `locked`+`instance`+`registry` dont la clé matche une projection de C → `true` ; (c) même item en `permissive` → `false` (AC #3) ; (d) `absent` → `false` ; (e) contrat `severed` → `false` ; (f) clé amont ne matchant aucune projection → `false` ; (g) item `locked` mais `target_type=label` → `false` (différé Epic 30). Réutiliser les **factories** `ControlHubContract*Factory` (états `active`/`severed`/`permissive`/`absent`/`forLabel`). Piège SQLite : tester des **décisions** (booléens), pas des bornes de colonnes. [Source: mémoire projet — sqlite_tests_no_varchar_enforcement]
  - [x] **Unit/Feature `tests/Unit/Policies/CapabilityPolicyTest.php`** : `modify` deny si capacité verrouillée (même avec `app.customize`) ; allow si permissive/absent/sans contrat (avec `app.customize`) ; deny si pas `app.customize` ; `modify(user, null)` = droit seul.
  - [x] **Feature override parc** `tests/Feature/.../CapabilitiesTabUpstreamLockTest.php` (Livewire `Livewire::test(...)` du SFC) : capacité verrouillée → `saveOverride` lève/toast et **aucune ligne** `capability_assignments` écrite ; capacité non verrouillée → écrit normalement (non-régression 27.12).
  - [x] **Feature défaut instance** `tests/Feature/.../ParcDefaultsUpstreamLockTest.php` : capacité verrouillée → `saveDefault` refusé, `capabilities.default_value` inchangé ; non verrouillée → OK.
  - [x] **Non-régression** : exécuter les suites capacités existantes (27.12 onglet capacités, parc-defaults) + Epic 28 (`ControlHubContract*`) → 0 régression. **Standalone byte-identique** : sans contrat, le comportement d'écriture est strictement celui d'avant (NFR3).
  - [x] Bootstrap des tables Spatie si la feature touche un before-hook de permission (cf. piège rencontré en 29.1 : `ParcGroupWpkgPageTest` a dû bootstrapper les 5 tables Spatie + stub gate). Anticiper le même besoin pour les features capacités. [Source: 29-1 Dev Agent Record — régression transitoire corrigée]

- [x] **T6 — Runbook QA (domaine `contrat-amont` ou `rights-management`)** (AC: #1–#6)
  - [x] **Append** une section dédiée 29.2 au runbook QA du domaine concerné (suivre `docs/qa/README.md` — sections numérotées stables, scénarios `### Scénario N.M`). Couvrir manuellement : édition d'override d'une capacité verrouillée (refus + message), édition du défaut (refus), capacité permissive (édition OK → 29.3), standalone (aucun changement), masquage UI des boutons. Localiser le bon fichier (`docs/qa/domains/*`) et mettre à jour le libellé du domaine dans `docs/qa/README.md`. [Patron : 29.1 a appendé « Section 11 » à `docs/qa/domains/rights-management.md`.]

- [x] **T7 — Validation finale**
  - [x] `php artisan test --filter "UpstreamLock|CapabilityPolicy|Capabilit|ParcDefaults|ControlHubContract"` sur HÔTE → vert (cas positifs + négatifs + non-régression).
  - [x] Relecture grep R3 : `grep -rin "central" app/Services/ControlHub app/Policies/CapabilityPolicy.php` → **0**.
  - [x] Vérifier le court-circuit NFR3 : sans contrat actif, aucune requête `controlhub_contract_items` au rendu des onglets capacités / parc-defaults.
  - [x] `php -l` sur les fichiers PHP touchés + compilation Blade des 2 partials modifiés.

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 29.2

**DANS** : un service `UpstreamLockResolver` (lecture du contrat actif, set des clés registre `locked`/`instance`, mapping capacité→clés), un Gate `modify-capability` (policy `CapabilityPolicy` + `RegistersGates`, enregistré dans `AuthServiceProvider`), le branchement du refus serveur + masquage UI sur les **deux** surfaces d'écriture de capacité (override parc + défaut instance), les tests HÔTE, le runbook QA. Verrou = `locked` + `instance` UNIQUEMENT.

**HORS** (ne pas déborder) :
- **Override permissif par `workstationGroup`** (FR4) → **Story 29.3**. 29.2 ne doit JAMAIS bloquer un item `permissive` (AC #3). La relaxation permissive est le sujet de 29.3.
- **Ciblage par label / résolution par périmètre** (FR12, mapping label→WG) → **Epic 30**. 29.2 n'enforce que `target_type = instance` (cohérent avec ce qu'injecte 28.3).
- **Drift STRICT + audit des overrides** (NFR2/NFR5) → **Story 29.5**. 29.2 refuse l'écriture ; il ne réapplique pas une valeur dérivée et n'est pas tenu d'auditer le refus (un log optionnel est un bonus, pas un AC).
- **Lisibilité refnum complète (badges imposé/verrouillé/permissif partout)** (FR8) → **Story 29.4**. 29.2 livre le **strict minimum** d'UI (masquage + message explicite « verrouillé amont ») exigé par « pas seulement masquée » ; le système de badges généralisé est 29.4.
- **Enforcement des types aggregate (`shortcuts`)** → couture documentée. La primitive `isLocked(type,key)` les accueillera ; 29.2 ne câble que le type exclusive-par-clé (registry/capacités).
- **Bornage de l'install au catalogue amont** (FR5) → Epic 31.
- **Aucune migration, aucun nouveau modèle de données.** Réutilisation pure des tables Epic 28 + capacités Epic 27.

### Patrons de référence à IMITER (ne rien réinventer)

- **`UpstreamContractSource::ensureResolved()`** [app/Services/ControlHub/Resolution/UpstreamContractSource.php L.114-156] : patron exact de lecture du contrat actif + mémoïsation + court-circuit NFR3. `UpstreamLockResolver` en est l'analogue côté écriture (ne retenir que `locked`).
- **`RegistryUpstreamAdapter::parts()` / `scopeFor()`** [RegistryUpstreamAdapter.php L.45-99] : décomposition `hive|path|name[|type]` et routage hive. Réutiliser cette algèbre de clé (l'extraire en helper partagé si la duplication gêne — DRY).
- **`AbstractCapabilityStateProvider::exclusiveKey()` + `expand()`** [L.97-103, L.205-241] : normalisation `strtolower(hive|path|name)` et lecture de `spec.keys[]`. La **même** identité doit servir le mapping capacité→clé verrouillée (sinon le verrou ne matcherait pas ce qui gagne au compilé).
- **`AppCustomizationPolicy`** [app/Policies/AppCustomizationPolicy.php] : structure minimale d'une policy à gates (`RegistersGates` + `ChecksPermissions` + `$gates`). `CapabilityPolicy` la calque, en ajoutant la DI du resolver.
- **`WorkstationGroupPolicy::assignWpkg` + `$gates` (Story 29.1)** [app/Policies/WorkstationGroupPolicy.php L.30-38] : patron d'**enregistrement** de gate scopé consommé par `@can($name, $model)` / `Gate::authorize($name, $model)`. **Réutiliser le patron d'enregistrement**, PAS la logique de délégation (le verrou 29.2 n'est pas une délégation).
- **`capabilities-tab.blade.php` `saveOverride()`** [L.195-256] : le geste d'écriture à garder. Noter le garde EXISTANT `overrides_locked` (gel LOCAL, L.227) — c'est un **précédent de refus à l'écriture d'une capacité**, mais sur un axe différent (local, pas amont). Ne pas les confondre ; les deux refus coexistent.

### Garde-fous projet CRITIQUES

- **NE bloquer QUE `locked`** : confondre `permissive` avec `locked` casserait la Story 29.3 par anticipation et violerait FR4. `permissive` et `absent` = écriture autorisée (AC #3/#4).
- **NFR3 — standalone strictement inchangé** : sans contrat amont actif, `UpstreamLockResolver` renvoie « jamais verrouillé » **sans requêter `items`** ; toutes les surfaces capacité se comportent comme en 27.12. Test révélateur obligatoire (≤ 1 requête). [Source: UpstreamContractSource.php L.30-37 (NFR3) ; 28-3 story]
- **Identité de clé alignée à l'octet** : le verrou doit matcher EXACTEMENT la clé qui gagne au compilé (`strtolower(hive|path|name)`). Une normalisation divergente = verrou qui « ne mord pas » (faux négatif) ou mord trop large (faux positif).
- **`overrides_locked` (27.12) ≠ verrou amont (29.2)** : flags distincts, ne pas réutiliser l'un pour l'autre. [Source: Capability.php L.34]
- **Verrou instance-wide, pas par-délégation** : ne PAS réimporter `PermissionService::canOnWorkstationGroup` (29.1). Le verrou se résout par présence d'un item `locked` au contrat actif, indépendamment de l'utilisateur et du parc. Le scoping par label viendra en Epic 30.
- **Vocabulaire R3** : aucun « central » (code, messages, identifiants). « amont » / `Upstream` / `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
- **Tests HÔTE uniquement** : php8.4 + `pdo_sqlite`, `RefreshDatabase`, **jamais la VM** (worktree git → interdit). SQLite n'applique pas varchar/enum PG : tester des décisions, pas des bornes. [Source: mémoires projet — phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement]

### Architecture & conventions

- **Filesystem-router + Livewire SFC** : les surfaces d'écriture sont des composants Livewire en tête de `.blade.php` sous `resources/views/pages/**`. Modification ponctuelle de leurs méthodes mutantes + des boucles de rendu (masquage `@can`). [Source: CLAUDE.md — routing]
- **Toasts** : trait `WithToasts` (déjà utilisé par `capabilities-tab`) pour le message explicite ; `parc-defaults` utilise `Gate::allows`/`abort` — homogénéiser le retour utilisateur (toast ou message d'erreur visible). [Source: capabilities-tab.blade.php L.3, L.35]
- **Modale réutilisable** : l'onglet capacités utilise déjà `<x-molecules.modal>` — réutiliser pour tout encart de verrou si nécessaire (pas de nouvelle modale ad hoc). [Source: CLAUDE.md — composants ; capabilities-tab.blade.php L.407]
- **Enregistrement DI** : singleton `UpstreamLockResolver` à enregistrer là où `UpstreamContractSource` l'est (`AgentServiceProvider`, L.108) pour cohérence du cycle de vie par-requête (mémoïsation == par-compilation/par-requête). [Source: AgentServiceProvider.php L.108]
- **PHP-FPM = www-admin** : sans impact (tests HÔTE) ; pertinent seulement si exécution VM (interdite ici). [Source: mémoire projet — php_fpm_user_www_admin]

### Project Structure Notes

- **Nouveaux** :
  - `app/Services/ControlHub/UpstreamLockResolver.php` (service de verrou).
  - `app/Policies/CapabilityPolicy.php` (gate `modify-capability`).
  - `tests/Unit/Services/ControlHub/UpstreamLockResolverTest.php`, `tests/Unit/Policies/CapabilityPolicyTest.php`, `tests/Feature/.../CapabilitiesTabUpstreamLockTest.php`, `tests/Feature/.../ParcDefaultsUpstreamLockTest.php`.
- **Modifiés** :
  - `app/Providers/AuthServiceProvider.php` (+`CapabilityPolicy::registerGates()`).
  - `app/Providers/AgentServiceProvider.php` (+singleton `UpstreamLockResolver`).
  - `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (gardes serveur + masquage UI).
  - `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` (gardes serveur + masquage UI).
  - `docs/qa/domains/*.md` + `docs/qa/README.md` (runbook QA append).
- **Aucune** migration, **aucun** nouveau modèle de données, **aucune** route nouvelle. **Racine = projet Laravel** (pas de préfixe `laravel/`). [Source: mémoire projet — root_is_laravel]

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 29.2] — AC d'origine (FR3).
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#FR3, #NFR1, #NFR2, #NFR3] — refus service+gate ; gates scopés ; drift STRICT (29.5) ; standalone inchangé.
- [Source: _bmad-output/implementation-artifacts/28-3-resolution-amont-local-statecompiler.md#L.84-85] — « 28.3 ne bloque aucune édition » ; couture relaxation permissive → Epic 29.
- [Source: app/Models/ControlHubContract.php ; app/Models/ControlHubContractItem.php] — modèle de réception (items {type,key,value,enforcement_state,target_type}).
- [Source: app/Enums/ControlHubEnforcementState.php ; ControlHubContractTarget.php ; ControlHubLinkState.php] — locked/permissive/absent ; instance/label ; active/severed.
- [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php L.114-156] — patron de lecture du contrat actif + court-circuit NFR3 (à imiter).
- [Source: app/Services/ControlHub/Resolution/RegistryUpstreamAdapter.php L.45-99] — décomposition `hive|path|name[|type]` + routage hive.
- [Source: app/Services/Agent/Providers/AbstractCapabilityStateProvider.php L.97-103, L.205-241] — `exclusiveKey()` + lecture `spec.keys[]` (identité de clé commune).
- [Source: app/Models/Capability.php ; app/Models/CapabilityProjection.php] — capacité, projections registry, `overrides_locked` (gel LOCAL distinct).
- [Source: resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php L.149,166,195,259,314] — surface override par parc (à garder).
- [Source: resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php L.87,102] — surface défaut instance (à garder).
- [Source: app/Policies/AppCustomizationPolicy.php ; app/Policies/WorkstationGroupPolicy.php L.30-38 ; app/Policies/Traits/RegistersGates.php ; ChecksPermissions.php] — patron policy/gate à calquer.
- [Source: app/Providers/AuthServiceProvider.php L.52-65 ; app/Providers/AgentServiceProvider.php L.108] — enregistrement gate + singleton.
- [Source: _bmad-output/implementation-artifacts/29-1-scoper-gate-wpkg-par-perimetre.md] — patron 29.1 (gate registration) + piège bootstrap tables Spatie en feature test.
- [Source: mémoires projet — phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement, root_is_laravel, php_fpm_user_www_admin, zero_prod_publish_is_test].

## Dépendances

- **Amont (consommées, status `review`)** :
  - **Story 29.1** (`review`) — fournit le **patron d'enregistrement de gate scopé** (`RegistersGates` + policy + argument modèle) que 29.2 réutilise pour `modify-capability`. **Dépendance de patron, pas de code dur** : 29.2 ne consomme ni `assignWpkg` ni `canOnWorkstationGroup` (axe différent : verrou amont ≠ délégation). Le code 29.1 est sur `main`.
  - **Epic 28** (28.1/28.2/28.3, `review`) — fournit le **modèle du contrat amont** (`ControlHubContract*`, enums), la garantie « ≤ 1 contrat actif », et la **sémantique de résolution** (locked/permissive/instance/label, court-circuit NFR3) que `UpstreamLockResolver` imite. **Dépendance dure** : sans le modèle Epic 28, pas de verrou à lire. Code sur `main`.
- **Prérequis fourni à (aval)** :
  - **Story 29.3** (override permissif par WG) — 29.2 doit laisser `permissive` modifiable (ne pas le bloquer) ; 29.3 ajoutera la relaxation contrôlée.
  - **Story 29.4** (lisibilité refnum) — généralisera le badge/statut que 29.2 amorce (message « verrouillé amont »).
  - **Story 29.5** (drift STRICT + audit) — réapplication + audit complémentaires au refus d'écriture.
  - **Epic 30** (ciblage par label) — étendra le verrou aux items `target_type = label` (29.2 = instance only).
- **Réutilise** : capacités Epic 27 (`Capability`, `CapabilityProjection`, `capability_assignments`), policies/gates (`RegistersGates`, `ChecksPermissions`) — **livrés et stables**.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, trait `RefreshDatabase`. **Jamais la VM.** [Source: mémoire projet — phpunit_test_env_host_vs_vm]
- Filtres ciblés : `php artisan test --filter "UpstreamLock|CapabilityPolicy|Capabilit|ParcDefaults|ControlHubContract"`.
- Couverture obligatoire :
  - **Résolution de verrou** : locked+instance+registry matchant une projection → verrouillé ; permissive → non ; absent → non ; severed → non ; standalone (aucun contrat) → non **+ court-circuit ≤ 1 requête** ; clé non matchante → non ; locked+label → non (Epic 30).
  - **Gate `modify-capability`** : deny si verrouillé (même avec `app.customize`) ; allow sinon (avec droit) ; deny sans `app.customize` ; `null` = droit seul.
  - **Feature override parc** : verrouillé → refus serveur **sans écriture** `capability_assignments` + message ; non verrouillé → écrit (non-régression 27.12).
  - **Feature défaut instance** : verrouillé → refus, `default_value` inchangé ; non verrouillé → OK.
  - **Non-régression** : suites capacités 27.12 / parc-defaults + Epic 28 (`ControlHubContract*`) vertes ; standalone byte-identique (NFR3).
- **Pièges** : SQLite n'applique pas varchar/enum PG → tester décisions (booléens/exceptions), pas bornes ; bootstrap des tables Spatie possible en feature (cf. 29.1) ; aligner la normalisation de clé à l'octet sur l'existant. [Source: mémoires projet — sqlite_tests_no_varchar_enforcement ; 29-1 Dev Agent Record]
- ⚠️ **VM** : migrations non auto-jouées par le dev-cycle (SQLite uniquement) — sans impact (aucune migration). [Source: mémoire projet — vm_migrations_not_auto_applied]

## Recommandation Modèle Dev

**`opus`.**

Justification : story d'**enforcement de sécurité** (verrou d'autorité amont) au cœur du modèle « Contrat Managé SE5 ». Le risque dominant n'est pas la mécanique mais le **jugement de cadrage** : ne bloquer QUE `locked` (pas `permissive`, sous peine de casser 29.3/FR4), aligner **à l'octet** l'identité de clé (`strtolower(hive|path|name)`) entre le verrou et ce qui gagne au compilé (un écart = verrou qui ne mord pas ou mord trop), préserver le **court-circuit NFR3** (standalone byte-identique), ne pas confondre trois refus distincts (`overrides_locked` local 27.12, délégation 29.1, verrou amont 29.2), et couvrir **deux surfaces** d'écriture (override parc + défaut instance) à la fois côté service (`AuthorizationException`) ET côté Gate/UI sans laisser de chemin serveur non gardé. C'est un raisonnement d'architecte de sécurité multi-fichiers (service + policy + 2 partials Livewire + DI + tests), pas une recette. Le dev-cycle routera la review vers le modèle opposé (sonnet/fable) pour une 2ᵉ paire d'yeux sur les angles morts (faux négatifs de matching de clé, sur-blocage de permissif).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- `php artisan test --filter "UpstreamLockResolver|CapabilityPolicy|CapabilitiesTabUpstreamLock|ParcDefaultsUpstreamLock"` → **22 passed** (32 assertions) — les 4 fichiers de tests neufs de la story.
- `php artisan test --filter "UpstreamLock|CapabilityPolicy|Capabilit|ParcDefaults|ControlHubContract"` → **136 passed** (551 assertions), 0 failed — couvre les suites capacités 27.12/27.17 + Epic 28 ControlHubContract* en non-régression.
- Smoke `--filter "StateCompiler|UpstreamContractResolution|Wpkg"` → 9 échecs **PRÉEXISTANTS** confirmés identiques sur baseline `git stash` : `StateCompilerTest` (2, RuntimeException env — fail aussi sans mes changements), `WpkgDeploymentSettingsPageTest` (4, ViewException Vite), `WpkgReportApiTest` (1, 403 IP), `EloquentFirstChemiCritiqueTest` (2, shim legacy /wpkg/*.xml). **0 régression introduite.**
- R3 : `grep -rin "central" app/Services/ControlHub/UpstreamLockResolver.php app/Policies/CapabilityPolicy.php` → uniquement les 2 commentaires garde-fou « aucun mot central » (zéro identifiant/message).
- `php -l` OK sur les 4 PHP touchés ; compilation Blade OK sur les 2 partials.

### Completion Notes List

- **T0** — Cadrage confirmé par lecture : verrou 29.2 = `type=registry` ∧ `enforcement_state=locked` ∧ `target_type=instance` ∧ contrat `link_state=active` (sous-ensemble strict de `UpstreamContractSource` qui retient locked+permissive). Identité de clé `strtolower(hive|path|name)` réutilisée à l'octet de `AbstractCapabilityStateProvider::exclusiveKey()` ; décomposition `hive|path|name[|type]` iso `RegistryUpstreamAdapter::parts()` (3 premiers segments). Scope = exclusive-par-clé (registry) UNIQUEMENT ; `shortcuts` (aggregate) hors verrou, primitive `isLocked(type,key)` générique le réserve (renvoie false, couture, jamais d'exception).
- **T1** — `app/Services/ControlHub/UpstreamLockResolver.php` : singleton mémoïsé (`$resolved`/`$lockedRegistryKeys`), court-circuit NFR3 (contrat actif null → 0 requête items, set vide, toutes méthodes « jamais verrouillé »). `lockedRegistryKeys()`, `isLocked(type,key)`, `isCapabilityLocked(Capability)` (expanse les projections registry — relation déjà chargée réutilisée+filtrée mechanism=registry pour éviter le N+1, sinon requête ciblée ; toutes ruches HKLM∪HKCU). N'ouvre JAMAIS `overrides_locked` (gel local 27.12 distinct).
- **T2** — `app/Policies/CapabilityPolicy.php` calquée sur `AppCustomizationPolicy` (`RegistersGates`+`ChecksPermissions`+`$gates=['modify-capability'=>'modify']`), DI `UpstreamLockResolver` au constructeur (résolu par le container quand le gate est invoqué). `modify(?user, ?cap)` = `app.customize` ∧ (`cap===null` ∨ `!isCapabilityLocked`). Enregistrée dans `AuthServiceProvider::boot()` ; singleton dans `AgentServiceProvider` à côté de `UpstreamContractSource`.
- **T3** — `capabilities-tab.blade.php` (override par parc) : garde serveur `authorizeUpstream()` (try `Gate::authorize('modify-capability',$cap)` → toast « verrouillée par un contrat amont » + arrêt) ajouté en tête de `openAdd`/`openEdit`/`saveOverride`/`removeOverride`. `guardCustomize()` (app.customize) conservé en amont (defense-in-depth). UI : `is_upstream_locked` pré-calculé une fois dans `overrides()` (eager-load projections + resolver), capacités verrouillées exclues de `addableCapabilities()` (`reject`), badge « Verrouillé par contrat amont » + masquage Éditer/Retirer (« Imposé par contrat amont »). **`removeOverride` BLOQUÉ** (décision validée — symétrie du refus, retrait de toute façon inerte au compilé).
- **T4** — `parc-defaults/_partials/registry-tab.blade.php` (défaut instance) : `authorizeUpstream()` en tête de `openEdit`/`saveDefault`/`toggleLock` ; `guardAdmin()` (server.admin) conservé. UI : `is_upstream_locked` dans `capabilities()` (eager-load + resolver), badge + masquage « Éditer le défaut » et toggle « Geler » désactivé.
- **T5** — 4 fichiers de tests HÔTE (RefreshDatabase). Résolution : standalone court-circuit ≤1 requête / 0 items (compteur `DB::getQueryLog`), locked→verrou, permissive/absent/severed→libre, clé non matchante→libre, label→libre (Epic 30), casse insensible + alignement octet vs `RegistryUserCapabilityProvider::exclusiveKey()`, primitive `isLocked` (registry oui / shortcuts non), item non-registry n'alimente pas le set. Gate : deny si locked même avec droit, allow permissive/sans contrat, deny sans app.customize, null=droit seul. Feature override parc + défaut instance : refus sans écriture + non-régression. **Piège Spatie évité sans bootstrap** : les users mockés (Authenticatable+Authorizable, sans trait HasRoles) ne déclenchent pas le before-hook Spatie (`method_exists(checkPermissionTo)===false`) → aucune table Spatie requise. Le test parc-defaults utilise un `Gate::before` CIBLÉ (`server.admin` → true, `null` sinon) pour ne PAS court-circuiter `modify-capability`.
- **T6** — `docs/qa/domains/controlhub-contract.md` : **Section 7** appendée (scénarios 7.1→7.6 + checklist Story 29.2), numérotation existante préservée. Libellé du domaine `controlhub-contract` enrichi dans `docs/qa/README.md` (mention 28.3+29.2).
- **T7** — R3 0 identifiant « central » ; court-circuit NFR3 vérifié (test révélateur) ; `php -l` + compilation Blade OK.

**Décision de cadrage notable (attention reviewer)** : le gate `modify-capability` a pour plancher `app.customize` (story T2/AC#7b). Sur la surface `parc-defaults` (gardée `server.admin`), cela COUPLE l'édition du défaut à `app.customize` en plus de `server.admin` — `server.admin` et `app.customize` sont des permissions DISTINCTES (`SambaPermission`, bits différents). Un admin ayant `server.admin` mais PAS `app.customize` verrait `saveDefault`/`toggleLock` refusés même sans contrat amont. C'est le design littéral de la story et acceptable sous la décision « zéro prod / pas de compat ascendante » (l'admin/SuperAdmin réel porte les deux droits) ; documenté ici comme angle mort potentiel si un rôle server.admin-only sans app.customize existait.

### File List

**Nouveaux :**
- `app/Services/ControlHub/UpstreamLockResolver.php`
- `app/Policies/CapabilityPolicy.php`
- `tests/Unit/Services/ControlHub/UpstreamLockResolverTest.php`
- `tests/Unit/Policies/CapabilityPolicyTest.php`
- `tests/Feature/Livewire/Parc/CapabilitiesTabUpstreamLockTest.php`
- `tests/Feature/Livewire/Admin/ParcDefaultsUpstreamLockTest.php`

**Modifiés :**
- `app/Providers/AuthServiceProvider.php` — import + `CapabilityPolicy::registerGates()`.
- `app/Providers/AgentServiceProvider.php` — import + singleton `UpstreamLockResolver`.
- `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` — gardes serveur `authorizeUpstream` + masquage UI + `is_upstream_locked`.
- `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` — gardes serveur `authorizeUpstream` + masquage UI + `is_upstream_locked`.
- `docs/qa/domains/controlhub-contract.md` — Section 7 (append).
- `docs/qa/README.md` — libellé domaine `controlhub-contract`.
