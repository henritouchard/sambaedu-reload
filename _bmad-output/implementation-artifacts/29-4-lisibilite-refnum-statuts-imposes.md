# Story 29.4: Lisibilité refnum des statuts imposés

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **refnum** (administrateur local SE5),
I want **voir clairement, dans l'UI de configuration des capacités, un statut visible par item — imposé-verrouillé / imposé-permissif / local — et ne JAMAIS me retrouver devant un réglage qui « ne s'enregistre pas » sans explication visible**,
so that **je comprends pourquoi un réglage est désactivé (verrouillé amont) ou pourquoi mon override reprend la main (permissif surchargeable), au lieu de subir un échec silencieux (FR8)**.

> Story de **LISIBILITÉ UI pure**. Elle ne touche **aucune** mécanique d'enforcement : le verrou (`locked` → refus d'écriture) est livré par **29.2**, la relaxation (`permissive` → l'override par `workstationGroup` mord au compilé) est livrée par **29.3**. 29.4 **expose en lecture** le statut amont d'une capacité et le **rend lisible** sur les surfaces de configuration. Le seul code « non-vue » est une **exposition read-only** du statut `permissive` (29.2 n'a exposé que `locked`) — pas un changement de moteur, pas un nouveau Gate, pas une nouvelle migration/route/modèle.

> **Ce que 29.2 a DÉJÀ posé (à NE PAS réinventer)** : un flag `is_upstream_locked` (calculé via `UpstreamLockResolver::isCapabilityLocked()`) injecté dans les tableaux des deux surfaces, un badge « Verrouillé par contrat amont » (icône cadenas, `badge-neutral`) et un texte « Imposé par contrat amont » qui remplace les boutons d'action, plus un toast de refus. 29.4 **complète** ce dispositif binaire (verrouillé / non-verrouillé) en un **tri-état** (verrouillé / permissif / local). [Source vérifiée 2026-06-27 — voir « Contexte du code ».]

> **⚠️ Décision Henri (rappel) — pas de compat ascendante exigée** : aucun environnement de prod à préserver (seul invariant intangible = enrôlement controlHub). Les garde-fous « non-régression » visent le **bon design** (sans contrat amont, l'UI est strictement celle de 27.12 — aucun badge — NFR3), pas la rétrocompat d'appelants exotiques. [Source: mémoires projet — zero_prod_publish_is_test, no_legacy_transition_state]

## Contexte du code (constat vérifié 2026-06-27)

**Le dispositif de lisibilité EXISTANT (29.2) — binaire verrouillé/non, à RÉUTILISER et ÉTENDRE :**

- **Service de lecture du verrou** — `app/Services/ControlHub/UpstreamLockResolver.php` : singleton mémoïsé, court-circuit NFR3 (aucun contrat actif → **0 requête `items`**, ≤ 1 requête « contrat actif ? »). Lit le contrat `active`, sélectionne `type = registry` ∧ `enforcement_state = locked` ∧ `target_type = instance`, construit `lockedRegistryKeys` (set indexé par `exclusiveKey = strtolower(hive|path|name)`). Expose `isCapabilityLocked(Capability): bool` (au moins une clé de projection registry ∈ set verrouillé). **Ne connaît PAS `permissive`** (filtré `Locked` seul, L.167). C'est le trou read-only à combler. [Source: UpstreamLockResolver.php L.118-141 (`isCapabilityLocked`), L.147-175 (`ensureResolved` — `where('enforcement_state', Locked)` L.167)]
- **Surface A — override de capacité PAR PARC (= par `workstationGroup`)** — `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (Livewire SFC). Computed `overrides()` (L.94-114) injecte `'is_upstream_locked' => $lock->isCapabilityLocked($capability)` (L.111) ; computed `addableCapabilities()` (L.122-154) **exclut** (`reject`, L.144) les capacités verrouillées de la liste d'ajout. Rendu : badge « Verrouillé par contrat amont » (L.434-439, `data-testid="upstream-locked-{id}"`) + texte italique « Imposé par contrat amont » à la place des boutons Éditer/Retirer (L.449-450). Le pré-calcul du resolver se fait **une seule fois** par computed (L.92, L.133) pour éviter le N+1.
- **Surface B — défaut diffusé d'une capacité (INSTANCE)** — `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` (Livewire SFC). Computed `capabilities()` (L.44-68) injecte `'is_upstream_locked' => $lock->isCapabilityLocked($c)` (L.65). Rendu : badge « Verrouillé par contrat amont » (L.258-261) + toggle « Geler » désactivé (L.273) + texte « Imposé par contrat amont » (L.282-283).
- **La distinction `local` est déjà partiellement matérialisée** par les colonnes existantes (`override_display` vs `default_display`, présence/absence d'une ligne `capability_assignments`), MAIS **jamais sous forme de statut/badge explicite** : aujourd'hui un item non-verrouillé n'a **aucun** marqueur, qu'il soit permissif-amont ou purement local — c'est précisément l'ambiguïté FR8 à lever.

**Le statut `permissive` est résolu au COMPILÉ (29.3) mais PAS exposé à l'UI** : `UpstreamContractSource::ensureResolved()` aiguille `permissive → StateMaille::UpstreamPermissive` (rang 6, plancher) ; mais aucune surface d'écriture ne **dit** au refnum « cet item est imposé-permissif (surchargeable) ». [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php — injection divergente 29.3 ; StateMaille::UpstreamPermissive]

**Nuance de vérité à respecter dans le libellé permissif (héritée de 29.3, décision Henri)** : un item `permissive` est un **plancher** au rang le MOINS spécifique (`UpstreamPermissive = 6`, **sous** `Broadcast = 5`). Toute `Capability` émet un candidat `default_value` à la maille `Broadcast`. Donc pour une capacité adossée à un défaut diffusé, **le défaut diffusé bat déjà le permissif** ; ce que l'override par `workstationGroup` garantit, c'est que **la valeur du refnum prend effet pour son groupe** (29.3). Le badge permissif doit donc dire la **relaxabilité** (« vous pouvez surcharger, votre override s'applique »), **pas** « la valeur permissive amont sera servie en baseline » (faux pour une capacité à défaut diffusé). [Source: 29-3 Dev Agent Record — ANGLE MORT ; mémoire projet — project_permissive_floor_least_specific]

## Acceptance Criteria

1. **Given** un contrat amont **actif** impose un item `registry`/**`locked`**/`instance` correspondant à une projection d'une `Capability` C,
   **When** le refnum ouvre la surface override-par-parc (`capabilities-tab`) **ou** la surface défaut-instance (`parc-defaults` registre),
   **Then** C affiche le statut **imposé-verrouillé** (badge existant « Verrouillé par contrat amont » conservé, **inchangé**), et les contrôles d'écriture restent masqués/désactivés **avec** l'explication visible « Imposé par contrat amont » (comportement 29.2 préservé).

2. **Given** un contrat amont actif impose un item `registry`/**`permissive`**/`instance` correspondant à C,
   **When** le refnum ouvre l'une ou l'autre surface,
   **Then** C affiche un statut **imposé-permissif** distinct du verrouillé (badge dédié, libellé du type « Imposé permissif — surchargeable »), **et** les contrôles d'écriture (Éditer / Ajouter / Retirer override sur `capabilities-tab` ; Éditer le défaut sur `parc-defaults`) restent **actifs** (un permissif n'est PAS bloqué — 29.2/29.3),
   **And** une explication visible indique que l'override local **prend effet** pour le périmètre concerné (pas de réglage qui « ne s'enregistre pas » sans raison).

3. **Given** une capacité C **sans** contrainte amont (aucun item `locked` ni `permissive` la concernant) — qu'un contrat soit actif ou non,
   **When** le refnum la voit sur l'une des surfaces,
   **Then** C affiche le statut **local** (réglage propre au parc/à l'instance) — un marqueur visible distinct des deux statuts amont, de sorte que le tri-état imposé-verrouillé / imposé-permissif / local soit **toujours explicite** (FR8).

4. **Given** la règle de précédence d'affichage du statut,
   **When** une capacité serait théoriquement à la fois verrouillée et permissive (clés multiples d'enforcements différents),
   **Then** un **seul** badge est rendu selon la précédence **verrouillé > permissif > local** (le verrou prime — il est le plus contraignant ; cohérent avec `isCapabilityLocked` qui suffit déjà à interdire l'écriture).

5. **Given** l'exposition du statut permissif est un besoin de **donnée non encore exposée** (29.2 n'expose que `locked`),
   **When** elle est implémentée,
   **Then** elle l'est en **read-only** (lecture du contrat actif, aucune écriture, aucun nouveau Gate, aucun changement de la décision d'autorisation 29.2/29.3) **And** elle **réutilise la résolution mémoïsée existante** : le **court-circuit NFR3 est préservé** (aucun contrat actif → **aucune** requête `controlhub_contract_items`), et l'ajout du set permissif n'ajoute **pas** de 2ᵉ requête `items` (≤ 1 requête `items` au total, par bucketing `whereIn([locked, permissive])`).

6. **Given** aucun contrat amont actif (standalone), **OU** un item `absent`, **OU** un contrat `severed`,
   **When** le refnum ouvre les surfaces,
   **Then** **aucun** badge amont (ni verrouillé ni permissif) n'est rendu, le statut affiché est **local**, et l'UI est **strictement identique** à celle de 27.12 sans contrat (NFR3) — y compris l'absence de toute requête `items`.

7. **Given** la suite de tests HÔTE (php8.4 + sqlite, `RefreshDatabase`),
   **When** elle s'exécute,
   **Then** sont **couverts et verts** : (a) résolution read-only du permissif (item `permissive`/`instance`/`registry` matchant C → `true` ; `locked` → `false` pour la méthode permissive ; `absent`/`severed`/standalone → `false` ; court-circuit ≤ 1 requête `items` ; clé non matchante → `false`) ; (b) rendu des trois statuts sur les deux surfaces (Livewire `Livewire::test` : badge verrouillé / badge permissif / marqueur local, précédence verrouillé>permissif) ; (c) **non-régression** : les suites 29.2 (`CapabilitiesTabUpstreamLockTest`, `ParcDefaultsUpstreamLockTest`) et 29.3 (`CapabilitiesTabPermissiveOverrideTest`, `PermissiveOverrideResolutionTest`) restent vertes ; sans contrat, les suites capacités 27.12 / parc-defaults restent vertes (NFR3).

8. **Given** le garde-fou de vocabulaire R3,
   **When** on lit le code, les libellés UI et les identifiants introduits,
   **Then** **aucun** mot « central » n'apparaît : vocabulaire « amont » / `Upstream` / `ControlHub*` ; libellés utilisateur « imposé », « verrouillé », « permissif », « local ».

## Tasks / Subtasks

- [x] **T0 — Cadrage de la lisibilité tri-état (inventaire + décision d'exposition)** (AC: #1, #2, #3, #4, #5)
  - [x] Confirmer par lecture l'inventaire 29.2 (ci-dessus) : badge `locked` + flag `is_upstream_locked` sur les **deux** surfaces ; **absence** de tout marqueur permissif ou local. Ne **rien** réinventer du dispositif `locked`.
  - [x] **Décider l'approche d'exposition read-only** (documenter dans Dev Notes) : **étendre `UpstreamLockResolver`** pour exposer aussi le statut `permissive` (reco — réutilise la résolution mémoïsée et le court-circuit NFR3, évite une 2ᵉ source de vérité et une 2ᵉ requête). Alternative écartée : un 2ᵉ service qui re-requêterait le contrat (duplique le court-circuit, risque de divergence). **Aucun** nouveau Gate, **aucune** mutation : c'est de la lecture.
  - [x] **Définir le tri-état et sa précédence** : `imposed-locked > imposed-permissive > local`. Un seul statut rendu par item (AC #4). Le `local` est l'**absence** des deux contraintes amont (dérivable au rendu ou via une méthode de statut unifiée — au choix, documenter).
  - [x] **Scope** : type `registry`/capacités, `target_type = instance` (cohérent 29.2/29.3 ; `label` → Epic 30). Surfaces = `capabilities-tab` (parc) + `parc-defaults` registre. Pas de nouvelle surface, pas de modale ad hoc.

- [x] **T1 — Exposition read-only du statut permissif dans `UpstreamLockResolver`** (AC: #2, #4, #5, #6, #8)
  - [x] Étendre `ensureResolved()` (UpstreamLockResolver.php L.147-175) : élargir le filtre items de `where('enforcement_state', Locked)` à `whereIn('enforcement_state', [Locked, Permissive])` en **récupérant aussi `enforcement_state`** (`->get(['key', 'enforcement_state'])`), puis **bucketer** chaque item dans `lockedRegistryKeys` **ou** un nouveau `permissiveRegistryKeys` selon son état. **Garantir ≤ 1 requête `items`** et **conserver le court-circuit NFR3** (contrat actif `null` → return early, les deux sets vides, aucune requête `items`).
  - [x] Ajouter `isCapabilityPermissive(Capability): bool` — **strict miroir** de `isCapabilityLocked` (même expansion `registryProjections`/`specKeys`/`exclusiveKey`, mêmes gardes N+1 et court-circuit), testant l'appartenance à `permissiveRegistryKeys`. Réutiliser les helpers privés existants (ne pas dupliquer la normalisation de clé).
  - [x] (Optionnel mais recommandé pour un rendu propre) Ajouter une méthode de statut unifiée, p.ex. `capabilityUpstreamStatus(Capability): string` renvoyant `'locked'` / `'permissive'` / `'local'` en appliquant la précédence (AC #4) — un seul appel par item au rendu, évite deux tests côté Blade. Si non retenue, dériver la précédence au template (locked d'abord).
  - [x] R3 : PHPDoc « amont » / `permissif` ; aucun « central ». Mettre à jour le PHPDoc de classe pour acter que le resolver expose désormais **deux** statuts amont (verrou ET permissif read-only) — il reste l'unique lecteur read-only du statut amont d'une capacité.

- [x] **T2 — Tri-état sur la surface override-par-parc (`capabilities-tab`)** (AC: #1, #2, #3, #4)
  - [x] `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` : dans `overrides()` (L.94-114), ajouter `'is_upstream_permissive' => $lock->isCapabilityPermissive($capability)` (ou `'upstream_status' => $lock->capabilityUpstreamStatus($capability)`) à côté du `is_upstream_locked` existant (L.111), **sans** 2ᵉ instanciation du resolver (réutiliser `$lock` L.92). Idem pour `addableCapabilities()` (L.122-154) : **ne pas** rejeter les capacités permissives (elles restent addables — 29.3), mais exposer leur statut pour afficher « imposé permissif » dans le picker.
  - [x] Rendu (table des overrides, L.425-464) : ajouter le badge **imposé-permissif** (libellé du type « Imposé permissif — surchargeable », icône distincte du cadenas, p.ex. `fa-unlock`/`fa-pen`, classe `badge` non-neutral pour distinguer du verrou ; `data-testid="upstream-permissive-{id}"`) **et** le marqueur **local** (badge discret/`badge-ghost`, `data-testid="upstream-local-{id}"`) pour les items sans contrainte amont. Un **seul** badge par item (précédence verrouillé>permissif>local). Pour un item permissif, **conserver actifs** les boutons Éditer/Retirer (ne PAS les masquer comme pour `locked`) et ajouter une mention « Votre override s'applique à ce parc » (explication FR8 « pas de réglage qui ne s'enregistre pas »).
  - [x] Picker d'ajout (modale, L.485-511) : afficher le statut amont sur chaque capacité proposée (badge « Imposé permissif — surchargeable » sur les permissives ; les verrouillées restent exclues par `reject`).

- [x] **T3 — Tri-état sur la surface défaut-instance (`parc-defaults` registre)** (AC: #1, #2, #3, #4)
  - [x] `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` : dans `capabilities()` (L.44-68), ajouter `'is_upstream_permissive'` (ou `'upstream_status'`) à côté du `is_upstream_locked` (L.65), via le `$lock` déjà instancié (L.48).
  - [x] Rendu (zone badges/actions, autour L.258-283) : badge **imposé-permissif** + marqueur **local** (mêmes conventions/testids que T2), précédence verrouillé>permissif>local. Pour un permissif, le défaut reste **éditable** (29.2 ne bloque pas permissif) — afficher le badge sans désactiver le bouton « Éditer le défaut » ni le toggle « Geler », avec une mention que le réglage local s'applique.

- [x] **T4 — Tests HÔTE (php8.4 + sqlite, `RefreshDatabase`)** (AC: #2–#7)
  - [x] **Unit `tests/Unit/Services/ControlHub/UpstreamLockResolverTest.php`** (ÉTENDRE le fichier existant 29.2) : `isCapabilityPermissive` → `true` pour item `permissive`/`instance`/`registry` matchant une projection ; `false` pour `locked` (la méthode permissive ne doit PAS confondre), `absent`, `severed`, standalone (aucun contrat) **avec court-circuit ≤ 1 requête `items`** (compteur `DB::getQueryLog`/`DB::listen`), clé non matchante, `target_type=label` (Epic 30 → `false`). **Cas clé** : un même contrat avec un item `locked` ET un item `permissive` sur deux capacités → bucketing correct (`isCapabilityLocked` vrai pour l'une, `isCapabilityPermissive` vrai pour l'autre) **en une seule requête `items`** (assert compteur). Si méthode de statut unifiée retenue : tester la précédence verrouillé>permissif sur une capacité multi-clés. Réutiliser les factories `ControlHubContract*Factory` (états `active`/`permissive`/`absent`/`severed`/`forLabel`), `type=registry`, `key=hive|path|name`. Tester des **décisions** (booléens/chaînes), pas des bornes SQLite. [Source: mémoire projet — sqlite_tests_no_varchar_enforcement]
  - [x] **Feature override parc** `tests/Feature/Livewire/Parc/CapabilitiesTabStatusBadgeTest.php` (ou étendre `CapabilitiesTabUpstreamLockTest`) : contrat avec un item `permissive` → le rendu Livewire contient le badge permissif (`assertSeeHtml`/`data-testid="upstream-permissive-*"`) et les boutons d'écriture **présents** ; item `locked` → badge verrouillé + boutons masqués (non-régression 29.2) ; capacité sans contrainte → marqueur local ; standalone → aucun badge amont (NFR3).
  - [x] **Feature défaut instance** `tests/Feature/Livewire/Admin/ParcDefaultsStatusBadgeTest.php` (ou étendre `ParcDefaultsUpstreamLockTest`) : mêmes assertions de badges tri-état sur `parc-defaults` ; permissif → badge + bouton « Éditer le défaut » présent ; locked → badge + désactivé (non-régression 29.2).
  - [x] **Non-régression** : exécuter `CapabilitiesTabUpstreamLock`, `ParcDefaultsUpstreamLock`, `CapabilitiesTabPermissiveOverride`, `PermissiveOverrideResolution`, suites capacités 27.12 → **0 régression**. Anticiper le piège bootstrap tables Spatie en feature (cf. 29.1/29.2 : users mockés sans `HasRoles` ne déclenchent pas le before-hook Spatie → aucune table requise). [Source: 29-2 Dev Agent Record T5]

- [x] **T5 — Runbook QA (domaine `controlhub-contract`, Section 9)** (AC: #1–#4, #6)
  - [x] **Append** une **Section 9** à `docs/qa/domains/controlhub-contract.md` (29.2 = Section 7, 29.3 = Section 8 ; numérotation stable, scénarios `### Scénario 9.M`). Couvrir manuellement : ouverture de `capabilities-tab` et de `parc-defaults` avec un contrat imposant (a) un `locked` → badge « Verrouillé par contrat amont » + contrôles désactivés, (b) un `permissive` → badge « Imposé permissif — surchargeable » + contrôles **actifs** + mention que l'override s'applique, (c) une capacité libre → marqueur **local** ; standalone → **aucun** badge (UI 27.12). Enrichir le libellé du domaine `controlhub-contract` dans `docs/qa/README.md` (mention 29.4 — lisibilité tri-état FR8). [Patron : 29.3 → Section 8.]

- [x] **T6 — Validation finale**
  - [x] `php artisan test --filter "UpstreamLockResolver|CapabilitiesTab|ParcDefaults|PermissiveOverride|ControlHubContract|StatusBadge"` sur HÔTE → vert (statut permissif + rendu tri-état + non-régression 29.2/29.3).
  - [x] Relecture grep R3 : `grep -rin "central" app/Services/ControlHub/UpstreamLockResolver.php resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` → **0** (hors commentaires garde-fou).
  - [x] Vérifier le court-circuit NFR3 : sans contrat actif, aucune requête `controlhub_contract_items` au rendu des deux onglets ; UI byte-identique à 27.12 (aucun badge).
  - [x] `php -l` sur le PHP touché + compilation Blade des 2 partials modifiés.

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 29.4

**DANS** : l'**exposition read-only** du statut `permissive` dans `UpstreamLockResolver` (miroir de `isCapabilityLocked`, même résolution mémoïsée + court-circuit NFR3, ≤ 1 requête `items` par bucketing), et le **rendu d'un tri-état** (imposé-verrouillé / imposé-permissif / local) sur les **deux** surfaces de configuration des capacités (`capabilities-tab` parc + `parc-defaults` registre), avec une **explication visible** pour chaque cas (verrouillé : désactivé + « imposé » ; permissif : actif + « votre override s'applique » ; local). Tests HÔTE + runbook QA Section 9.

**HORS** (ne pas déborder) :
- **Mécanique d'enforcement** : 29.4 ne change **rien** au verrou (29.2) ni à la relaxation (29.3) — aucun nouveau Gate, aucune décision d'autorisation modifiée, aucune maille touchée. Le badge permissif **ne débloque rien** (un permissif était déjà éditable depuis 29.2). Le badge verrouillé conserve le masquage 29.2 **tel quel**.
- **Drift STRICT + audit des overrides** (NFR2/NFR5) → **Story 29.5**.
- **Ciblage par label / mapping label→WG** (FR12, `target_type = label`) → **Epic 30**. 29.4 reste `instance` (cohérent 29.2/29.3).
- **Types aggregate (`shortcuts`)** → couture documentée (le statut amont d'un item agrégé est ambigu, comme en 29.2/29.3). 29.4 ne signale que le type exclusive-par-clé (registry/capacités).
- **Aucune migration, aucun nouveau modèle, aucune route, aucune nouvelle modale.** Réutilisation pure : `UpstreamLockResolver` (29.2) + tables Epic 28 + capacités Epic 27 + les 2 partials existants. **Racine = projet Laravel** (pas de préfixe `laravel/`). [Source: mémoire projet — root_is_laravel]

### Le DELTA UI précis (appui sur le code réel)

| Élément | Surface A — `capabilities-tab` (parc) | Surface B — `parc-defaults` registre |
|---|---|---|
| Flag verrouillé (EXISTE 29.2) | `overrides()` L.111 ; badge L.434-439 ; texte L.449-450 | `capabilities()` L.65 ; badge L.258-261 ; toggle off L.273 ; texte L.282-283 |
| **Flag permissif (À AJOUTER)** | `overrides()` (+`is_upstream_permissive`) et `addableCapabilities()` (ne PAS `reject`) | `capabilities()` (+`is_upstream_permissive`) |
| **Badge imposé-permissif (À AJOUTER)** | table overrides (zone L.430-439) + picker (zone L.492-507) ; boutons d'écriture restent **actifs** | zone badges (L.258-261) ; « Éditer le défaut » reste **actif** |
| **Marqueur local (À AJOUTER)** | items sans contrainte amont (badge discret) | idem |
| Resolver instancié **une fois** | `$lock` L.92 (overrides), L.133 (addable) — réutiliser | `$lock` L.48 — réutiliser |

**Donnée non exposée à créer (read-only, T1)** : `UpstreamLockResolver::isCapabilityPermissive()` + bucketing `permissiveRegistryKeys` dans `ensureResolved()` (UpstreamLockResolver.php L.147-175). C'est l'unique code non-vue ; c'est de la **lecture du contrat actif**, pas un changement de moteur.

### Garde-fous projet CRITIQUES

- **NFR3 — court-circuit préservé à l'octet** : sans contrat actif, `ensureResolved()` `return` avant toute requête `items` ; les deux sets restent vides ; les surfaces n'affichent **aucun** badge → UI identique à 27.12. **≤ 1 requête `items`** même avec contrat (bucketing locked+permissive en une requête). Test révélateur obligatoire (compteur de requêtes). [Source: UpstreamLockResolver.php L.147-160]
- **Vérité du libellé permissif (NE PAS sur-promettre)** : dire « surchargeable / votre override s'applique » (relaxabilité — vrai), **PAS** « la valeur permissive amont sera servie en baseline » (faux pour une capacité à défaut diffusé, car `Broadcast=5` bat `UpstreamPermissive=6`). [Source: 29-3 ANGLE MORT ; project_permissive_floor_least_specific]
- **Précédence verrouillé > permissif > local** : un seul badge par item ; le verrou prime (le plus contraignant). Côté code, `isCapabilityLocked` testé en premier. [AC #4]
- **Ne pas confondre 3 axes de statut** : `is_upstream_locked` (verrou amont 29.2), `is_upstream_permissive` (relaxable amont 29.4/29.3) et `overrides_locked` (gel **LOCAL** 27.12, distinct, déjà rendu sur `parc-defaults` L.276) coexistent. Le marqueur « local » de FR8 = **absence de contrainte amont**, pas le gel local 27.12. Ne pas mélanger les libellés. [Source: Capability.php `overrides_locked` ; registry-tab.blade.php L.276]
- **Pas de nouveau Gate, pas d'écriture** : 29.4 lit. Si un dev est tenté de gater l'affichage permissif, c'est inutile (lecture) et hors scope.
- **Vocabulaire R3** : aucun « central » (code, libellés, identifiants). Libellés FR : « imposé », « verrouillé », « permissif », « local ». [Source: prd-contrat-manage-se5.md#R3]
- **Tests HÔTE uniquement** : php8.4 + `pdo_sqlite`, `RefreshDatabase`, **jamais la VM** (worktree git → interdit). SQLite n'applique pas varchar/enum PG : tester des décisions/rendus, pas des bornes. [Source: mémoires projet — phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement]

### Patrons de référence à IMITER (ne rien réinventer)

- **`UpstreamLockResolver::isCapabilityLocked()` + `ensureResolved()`** [UpstreamLockResolver.php L.118-175] : `isCapabilityPermissive` en est le **miroir exact** (mêmes helpers `registryProjections`/`specKeys`/`exclusiveKey`/`normalizeItemKey`). Le bucketing locked/permissive se fait dans `ensureResolved()` en une requête.
- **Le dispositif badge `locked` 29.2** [capabilities-tab L.434-450 ; registry-tab L.258-283] : calquer la structure (badge + `data-testid` + texte explicatif) pour le badge permissif et le marqueur local ; conserver le badge locked à l'identique.
- **Injection du flag dans les computed** [capabilities-tab `overrides()` L.94-114 / `addableCapabilities()` L.122-154 ; registry-tab `capabilities()` L.44-68] : ajouter le flag permissif **dans le même `map`**, via le `$lock` déjà résolu (pas de 2ᵉ `app(UpstreamLockResolver::class)`).
- **`WithToasts`** : déjà câblé sur les deux SFC (29.2). 29.4 n'ajoute pas de toast (lecture) ; le tri-état est purement visuel. [Source: capabilities-tab.blade.php ; registry-tab.blade.php]

### Architecture & conventions

- **Filesystem-router + Livewire SFC** : les deux surfaces sont des composants Livewire en tête de `.blade.php` sous `resources/views/pages/**` ; 29.4 ajoute un flag dans leurs computed et des badges dans le rendu — **aucune** mutation de méthode, **aucune** nouvelle route. [Source: CLAUDE.md — routing]
- **Modale réutilisable** : le picker d'ajout (`capabilities-tab`) utilise `<x-molecules.modal>` — 29.4 ajoute seulement un badge dans la liste existante, pas de nouvelle modale. [Source: CLAUDE.md — composants ; capabilities-tab.blade.php L.478-511]
- **DI / cycle de vie** : aucun nouveau service ; `UpstreamLockResolver` est déjà un singleton mémoïsé par-requête (AgentServiceProvider, 29.2) — l'extension permissive ne change pas son enregistrement. [Source: 29-2 — singleton AgentServiceProvider]
- **PHP-FPM = www-admin** : sans impact (tests HÔTE). [Source: mémoire projet — php_fpm_user_www_admin]

### Project Structure Notes

- **Modifiés** :
  - `app/Services/ControlHub/UpstreamLockResolver.php` (+`permissiveRegistryKeys`, +`isCapabilityPermissive`, bucketing dans `ensureResolved()`, +méthode de statut unifiée optionnelle ; PHPDoc).
  - `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (flag permissif dans `overrides()`/`addableCapabilities()` + badges tri-état au rendu).
  - `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php` (flag permissif dans `capabilities()` + badges tri-état au rendu).
  - `tests/Unit/Services/ControlHub/UpstreamLockResolverTest.php` (extension — `isCapabilityPermissive` + bucketing 1 requête).
  - `docs/qa/domains/controlhub-contract.md` (Section 9 append) + `docs/qa/README.md` (libellé domaine).
- **Nouveaux (ou extensions des tests 29.2/29.3)** : `tests/Feature/Livewire/Parc/CapabilitiesTabStatusBadgeTest.php`, `tests/Feature/Livewire/Admin/ParcDefaultsStatusBadgeTest.php` (ou extension de `*UpstreamLockTest`).
- **Aucune** migration, **aucun** nouveau modèle, **aucune** route, **aucune** nouvelle modale, **aucun** changement du contrat agent / golden. **Racine = projet Laravel**. [Source: mémoire projet — root_is_laravel]

### References

- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#Story 29.4 (L.207-218)] — AC d'origine (FR8) : statut visible imposé-verrouillé / imposé-permissif / local ; aucun réglage qui « ne s'enregistre pas » sans explication.
- [Source: _bmad-output/planning-artifacts/epics-contrat-manage-se5.md#FR8 (L.31, L.68)] — transparence UI refnum.
- [Source: app/Services/ControlHub/UpstreamLockResolver.php L.118-175] — `isCapabilityLocked` + `ensureResolved` (filtre `Locked` seul à élargir ; court-circuit NFR3 à préserver).
- [Source: resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php L.92, L.94-114, L.122-154, L.430-462, L.485-511] — surface A : computed + badge locked existant + picker.
- [Source: resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php L.44-68, L.258-283] — surface B : computed + badge locked + toggle gel local (`overrides_locked` L.276, distinct).
- [Source: app/Services/ControlHub/Resolution/UpstreamContractSource.php — injection divergente 29.3 ; app/Enums/StateMaille.php (UpstreamPermissive)] — pourquoi le permissif est « surchargeable » (relaxabilité, plancher sous Broadcast).
- [Source: _bmad-output/implementation-artifacts/29-2-refuser-modification-item-verrouille.md ; 29-3-surcharger-item-permissif-par-workstationgroup.md] — dispositif badge `locked`, gate `modify-capability`, relaxation permissive, ANGLE MORT plancher/Broadcast, pièges tests (bootstrap Spatie, SQLite).
- [Source: _bmad-output/codeReviews/29-2.md] — décisions de review 29.2 (message conditionné à `isCapabilityLocked`, assert toast, multi-clés → 1 projection registry/capacité via `spec.keys[]`).
- [Source: mémoires projet — phpunit_test_env_host_vs_vm, sqlite_tests_no_varchar_enforcement, root_is_laravel, php_fpm_user_www_admin, zero_prod_publish_is_test, no_legacy_transition_state, project_permissive_floor_least_specific].

## Dépendances

- **Amont (consommées — code livré, status `review`, PAS encore `done`)** :
  - **Story 29.2** (`review` / `to-validate`, `codeReviews/29-2.md`) — fournit `UpstreamLockResolver` (résolution mémoïsée + court-circuit NFR3 + `isCapabilityLocked`) et le **dispositif badge `locked`** sur les deux surfaces. **Dépendance dure** : 29.4 **étend** ce service et ce dispositif. Code sur la branche `worktree-contract-CH`.
  - **Story 29.3** (`review`) — fournit la **sémantique du permissif surchargeable** (`UpstreamPermissive`, plancher sous Broadcast) qui justifie le **libellé** du badge permissif (relaxabilité, pas valeur baseline). **Dépendance de cohérence sémantique** (29.4 ne touche pas la résolution). Code sur la branche.
  - **Epic 28** (28.1/28.2/28.3, `review`) — modèle `ControlHubContract*` + enums (`ControlHubEnforcementState::Permissive`) + factories (états `permissive`/`absent`/`severed`). **Dépendance dure** (lecture du contrat). Code sur `main`.
  - **Epic 27** (capacités) — `Capability`, `CapabilityProjection`, surfaces `capabilities-tab` / `parc-defaults`. **Livré et stable.**
- **Prérequis fourni à (aval)** :
  - **Story 29.5** (drift STRICT + audit) — indépendante (audit serveur) ; 29.4 ne la conditionne pas.
  - **Epic 30** (ciblage par label) — étendra le tri-état aux items `target_type = label` (29.4 = instance only).
- **Note de statut** : 29.2 et 29.3 sont en **`review`** (pas `done`) mais leur **code est livré** sur la branche courante ; 29.4 peut donc démarrer (elle lit/étend ce code). Si une correction de review 29.2/29.3 modifiait `UpstreamLockResolver` ou les deux partials, re-synchroniser avant merge.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, trait `RefreshDatabase`. **Jamais la VM.** [Source: mémoire projet — phpunit_test_env_host_vs_vm]
- Filtres ciblés : `php artisan test --filter "UpstreamLockResolver|CapabilitiesTab|ParcDefaults|PermissiveOverride|ControlHubContract|StatusBadge"`.
- Couverture obligatoire :
  - **Résolution read-only permissif** : `isCapabilityPermissive` vrai pour `permissive`/`instance`/`registry` matchant ; faux pour `locked` / `absent` / `severed` / standalone / clé non matchante / `label` ; **bucketing locked+permissive en ≤ 1 requête `items`** (compteur) ; court-circuit NFR3 (aucun contrat → 0 requête `items`).
  - **Rendu tri-état** (Livewire `Livewire::test`) sur les deux surfaces : badge verrouillé (boutons masqués — non-régr 29.2) / badge permissif (boutons actifs + mention) / marqueur local ; précédence verrouillé>permissif ; standalone → aucun badge amont.
  - **Non-régression** : 29.2 (`CapabilitiesTabUpstreamLockTest`, `ParcDefaultsUpstreamLockTest`), 29.3 (`CapabilitiesTabPermissiveOverrideTest`, `PermissiveOverrideResolutionTest`), capacités 27.12 → 0 régression ; UI standalone byte-identique (NFR3).
- **Pièges** : SQLite n'applique pas varchar/enum PG → tester décisions/rendus, pas bornes ; bootstrap tables Spatie possible en feature (users mockés sans `HasRoles` l'évitent — cf. 29.2) ; ne pas sur-promettre dans le libellé permissif (relaxabilité ≠ valeur baseline). [Source: mémoires projet — sqlite_tests_no_varchar_enforcement ; 29-2/29-3 Dev Agent Record]
- ⚠️ **VM** : aucune migration → sans impact. [Source: mémoire projet — vm_migrations_not_auto_applied]

## Recommandation Modèle Dev

**`sonnet`.**

Justification : story de **lisibilité UI** sans changement de moteur — aucune décision d'autorisation, aucune maille, aucune migration. Le code non-vue se réduit à un **miroir** d'une méthode existante (`isCapabilityPermissive` calqué sur `isCapabilityLocked`) plus un bucketing d'une requête déjà écrite, et le reste est du rendu Blade tri-état sur deux partials dont le patron `locked` est déjà posé par 29.2. Le jugement requis est **borné et déjà tranché** : la précédence (verrouillé>permissif>local) et la vérité du libellé permissif (relaxabilité, pas « valeur baseline » — l'ANGLE MORT 29.3 est documenté ici) sont explicités dans la story ; le court-circuit NFR3 est un invariant à **préserver**, pas à concevoir. Ce n'est pas du raisonnement d'architecte de sécurité/moteur (contrairement à 29.2/29.3 → opus) mais de l'exécution soignée multi-surfaces. Le dev-cycle routera la review vers le modèle opposé (opus) pour vérifier les angles morts (libellé sur-promettant, régression du masquage `locked`, 2ᵉ requête `items` cassant ≤ 1).

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6 (2026-06-27)

### Debug Log References

Aucun fichier de log externe. Tous les tests ont été exécutés directement via `php artisan test` sur l'hôte (php8.4 + pdo_sqlite). Résultat initial : **125/125 verts** (421 assertions). Après corrections post-review opus : **177/177 verts** (754 assertions, non-régression incluse).

### Completion Notes List

- **T0 — Cadrage** : inventaire 29.2 confirmé par lecture des deux partials et d'UpstreamLockResolver. Approche retenue : étendre `UpstreamLockResolver` (singleton mémoïsé, cour-circuit NFR3 préservé) avec un `permissiveRegistryKeys` bucketé dans la requête existante (whereIn au lieu de where). Méthode unifiée `capabilityUpstreamStatus()` retenue (un seul appel par item au rendu, évite deux tests Blade). Tri-état verrouillé > permissif > local.

- **T1 — UpstreamLockResolver** : `ensureResolved()` élargi à `whereIn('enforcement_state', [Locked, Permissive])` + `get(['key', 'enforcement_state'])` ; bucketing par valeur rawState en une seule requête items (NFR3 : ≤1 requête). `isCapabilityPermissive()` miroir strict de `isCapabilityLocked()`. `capabilityUpstreamStatus()` méthode unifiée (précédence locked > permissive > local). PHPDoc mis à jour, aucun mot « central ».

- **T2 — capabilities-tab** : `overrides()` remplace `'is_upstream_locked'` par tuple `['is_upstream_locked', 'upstream_status']` via `capabilityUpstreamStatus()`. `addableCapabilities()` : les permissifs restent addables (conformément à 29.3), leur `upstream_status` est exposé. Badges tri-état dans la table des overrides avec `data-testid`. Note permissif « Votre override s'applique à ce parc » (data-testid `upstream-permissive-note-{id}`). Badge « Modifiable » dans le picker. Arbitrage utilisateur appliqué : labels Verrouillé / Modifiable / Local, classes badge-neutral / badge-info / badge-ghost.

- **T3 — registry-tab** : closure complète dans `capabilities()` (remplacement du `fn` court), `capabilityUpstreamStatus()` appelé une fois par capacité. Même tri-état badge, note permissif « Votre réglage local s'applique ».

- **T4 — Tests** : `UpstreamLockResolverTest` étendu (+13 méthodes) couvrant `isCapabilityPermissive`, bucketing ≤1 requête items (DB::getQueryLog), `capabilityUpstreamStatus`, précédence multi-clés, court-circuit NFR3. `CapabilitiesTabStatusBadgeTest` (7 cas), `ParcDefaultsStatusBadgeTest` (5 cas). Piège apostrophe française corrigé : `assertSeeHtml("Votre override s'applique à ce parc")` avec apostrophe littérale (les deux autres variantes — escape=true et &#039; — ne matchaient pas). Piège unique-constraint contrat : test de précédence multi-clés utilise deux clés distinctes sur le même contrat, pas le même `(contract_id, type, key, target_type, target_label)`. Suite complète : 125/125, 0 régression.

- **T5 — QA** : Section 9 (scénarios 9.1–9.6 + checklist rapide) appendée à `docs/qa/domains/controlhub-contract.md`. `docs/qa/README.md` mis à jour avec mention 29.4 (tri-état FR8, Stories 29.4 ajoutée à la liste).

- **T6 — Validation finale** : `php artisan test --filter "UpstreamLockResolver|CapabilitiesTab|ParcDefaults|PermissiveOverride|ControlHubContract|StatusBadge"` → vert. Grep R3 → 0 occurrence « central » dans les 3 fichiers PHP/Blade modifiés. `php -l` propre sur tous les fichiers PHP modifiés.

- **Décision badge labels (arbitrage utilisateur)** : Verrouillé (badge-neutral + fa-lock) / Modifiable (badge-info + fa-pen) / Local (badge-ghost + fa-location-dot). Tooltips : « Amont — non modifiable. » / « Proposé par l'amont mais modifiable : votre réglage local prévaut. » / « Réglage propre à ce parc/groupe. » (capabilities-tab) ou « Défaut diffusé — aucune contrainte amont. » (registry-tab — correction #1).

- **ANGLE MORT 29.3 respecté** : libellé permissif centré sur la relaxabilité (« votre réglage local prévaut ») sans aucune mention de « valeur baseline » — cohérent avec UpstreamPermissive=6 sous Broadcast=5.

- **CORRECTIONS POST-REVIEW OPUS (2026-06-27)** : #1/#2/#3/#4/#6/#7/#9 appliquées. #5/#8 backloggés.
  - **#1 — Tooltip Local faux sur parc-defaults** : tooltip badge Local dans `registry-tab.blade.php` corrigé de « Réglage propre à ce parc/groupe. » → « Défaut diffusé — aucune contrainte amont. ». QA Section 9 mise à jour (intro + Scénario 9.3 + checklist).
  - **#2 — Vérité du libellé permissif verrouillée par test** : ajout dans les deux tests Feature permissif de `assertSee('votre réglage local prévaut')` + `assertDontSee('valeur amont')` (angle mort fermé).
  - **#3 — Badge Local en standalone → zéro badge** : `hasActiveContract(): bool` ajouté à `UpstreamLockResolver` (flag `$activeContract` positionné dans `ensureResolved()`, zéro requête supplémentaire). `#[Computed] hasUpstreamContract()` ajouté aux deux Livewire SFC. Badges gatés dans les deux partials : en standalone, AUCUN badge (y compris Local). Tests mis à jour : `local_*` → crée un contrat actif ; `standalone_*` → asserte aussi l'absence du badge Local. QA Section 9 mise à jour (Scénarios 9.3, 9.5, checklist).
  - **#4 — Double évaluation isCapabilityLocked** : dans `addableCapabilities()` de `capabilities-tab`, `$lock->capabilityUpstreamStatus($c)` remplacé par `$lock->isCapabilityPermissive($c) ? 'permissive' : 'local'` (locked déjà exclu par reject, double test évité).
  - **#6 — Test surface severed** : cas `severed_contract_renders_no_upstream_badges` ajouté aux deux tests Feature (contrat severed = plus actif = zéro badge, cohérent avec #3).
  - **#7 — Compteur de requêtes au rendu Livewire** : cas `standalone_no_contract_emits_zero_items_queries_on_render` ajouté aux deux tests Feature (DB::getQueryLog autour de Livewire::test → 0 requête controlhub_contract_items).
  - **#9 — Assertions fragiles** : `assertSee('Modifiable')` / `assertSee('Local')` / `assertSee('Verrouillé')` remplacés par `assertSeeHtml('</i> Modifiable')` / `assertSeeHtml('</i> Local')` / `assertSeeHtml('</i> Verrouillé')` (contexte badge, faux-positifs évités).
  - **Tests post-corrections** : 39/39 ciblés verts (107 assertions) ; 177/177 non-régression verts (754 assertions). `php -l` propre sur tous les PHP touchés.

### File List

**Modifiés :**
- `app/Services/ControlHub/UpstreamLockResolver.php`
- `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php`
- `resources/views/pages/admin/settings/parc-defaults/_partials/registry-tab.blade.php`
- `tests/Unit/Services/ControlHub/UpstreamLockResolverTest.php`
- `docs/qa/domains/controlhub-contract.md`
- `docs/qa/README.md`

**Créés :**
- `tests/Feature/Livewire/Parc/CapabilitiesTabStatusBadgeTest.php`
- `tests/Feature/Livewire/Admin/ParcDefaultsStatusBadgeTest.php`
