# Story 29.7: Ne pas écraser `created_at` du pivot `capability_assignments`

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a **SE5 (le système)**,
I want **que la persistance d'un override de capacité par `workstationGroup` (`saveOverride()`) ne pose `created_at` sur le pivot `capability_assignments` QUE lors d'un INSERT, et jamais lors d'un UPDATE d'un override préexistant**,
so that **l'horodatage de création d'origine de la ligne pivot soit préservé (intégrité de l'historique du pivot) au lieu d'être réécrit à `now()` à chaque édition de la valeur d'override**.

> **Nature : correctif d'un bug pré-existant, faible sévérité, périmètre minimal.**
> Origine = follow-up **M1🟡** de la review 29.5 (`_bmad-output/codeReviews/29-5.md` lignes 28, 92-98), **signalé par le 2e avis opus, manqué par sonnet**. Le défaut est **PRÉ-EXISTANT — hérité de la story 27.12** (création de la surface `capabilities-tab` et de son `updateOrInsert`), **PAS une régression de 29.5** (29.5 n'a fait qu'envelopper l'`updateOrInsert` existant dans une `DB::transaction` sans toucher son jeu de valeurs).
> **Le correctif tient en ~5 lignes** : remplacer le tableau de valeurs de `updateOrInsert(...)` par une **closure** qui pose `created_at` uniquement à l'INSERT. Aucune décision d'architecture, aucune migration, aucun changement de schéma, aucun impact contrat/agent/compilé.

## Contexte du code (constat vérifié 2026-06-27)

### Le bug — `created_at` dans les VALEURS de `updateOrInsert`

`resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php`, méthode `saveOverride()`, au cœur de la `DB::transaction` (≈ L.333-340) :

```php
DB::table('capability_assignments')->updateOrInsert(
    [
        'capability_id' => $capability->id,
        'assignable_type' => WorkstationGroup::class,
        'assignable_id' => $parc->id,
    ],
    ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
);
```

`created_at => now()` est dans le **2e argument (les VALEURS)**. Sémantique Laravel : ce 2e argument est appliqué **aussi bien à l'INSERT qu'à l'UPDATE**. Sur l'UPDATE d'un override existant, `update(['value'=>…, 'updated_at'=>…, 'created_at'=>now()])` est exécuté → le `created_at` d'origine de la ligne pivot est **écrasé** par `now()`. L'historique de création de la ligne est perdu. (Sur un INSERT, c'est correct : la ligne naît à `now()`.)

### La signature Laravel qui permet le fix (VÉRIFIÉE dans `vendor`)

`vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php` L.4264-4281 (Laravel 12.x) :

```php
public function updateOrInsert(array $attributes, array|callable $values = [])
{
    $exists = $this->where($attributes)->exists();

    if ($values instanceof Closure) {
        $values = $values($exists);   // ← la closure reçoit bool $exists
    }

    if (! $exists) {
        return $this->insert(array_merge($attributes, $values));   // INSERT
    }
    if (empty($values)) {
        return true;
    }
    return (bool) $this->limit(1)->update($values);                // UPDATE
}
```

Le 2e argument **accepte une `Closure`** qui reçoit `bool $exists` et retourne le jeu de valeurs **adapté au cas** (INSERT vs UPDATE). C'est le « 3e argument » évoqué dans le commentaire de backlog (séparer les valeurs d'insert des valeurs d'update). **Atomicité préservée** : la closure est appelée à l'intérieur du même `updateOrInsert`, lui-même dans la même `DB::transaction`.

### Pourquoi la sévérité est faible 🟡 (ne pas sur-traiter)

- **N'affecte PAS le compilé** : `StateCompiler` lit la `value` du pivot, jamais `created_at` (l'item de desired-state est à 4 clés `{type,semantics,payload,hash}` — 27.8).
- **N'affecte PAS l'audit 29.5** : `CapabilityOverrideAuditLog` horodate sa **propre** trace (`created_at` via `useCurrent()` / `log()`) indépendamment du pivot. L'historique d'audit reste correct même avec le bug.
- **Le seul tort** : corruption de l'historique de **création** de la ligne `capability_assignments` (un override édité 5 fois « paraît » créé à la dernière édition). C'est un défaut d'intégrité de données, pas un défaut fonctionnel visible.

### Ce qui ne change PAS (et qu'il ne faut PAS toucher)

- `$hasExistingOverride` (calculé L.291-295 via `exists()`) sert à l'**audit** (dérivation `ACTION_CREATE` vs `ACTION_UPDATE`) — **rester strictement inchangé**. Le fix `updateOrInsert` calcule SON propre `$exists` en interne ; les deux coexistent sans interférence (la double lecture `exists()` est un nit déjà acté « non corrigé », hors-scope ici — cf. 29.5 #5).
- `removeOverride()` : c'est un `delete()`, **aucun** `updateOrInsert`, **aucun** `created_at` — **non concerné**, ne pas y toucher.
- La `DB::transaction` et l'appel `CapabilityOverrideAuditLog::log(...)` : **inchangés**.

## Acceptance Criteria

1. **Given** un override existe déjà en base pour `(capability_id, WorkstationGroup, assignable_id)`,
   **When** le refnum ré-édite sa valeur via `saveOverride()` (chemin UPDATE),
   **Then** la ligne `capability_assignments` conserve son `created_at` **d'origine inchangé** (pas réécrit à `now()`), et son `updated_at` est **rafraîchi** à `now()`.

2. **Given** aucun override n'existe encore pour la clé,
   **When** le refnum pose un premier override via `saveOverride()` (chemin INSERT),
   **Then** la ligne `capability_assignments` est créée avec `created_at` **ET** `updated_at` posés à `now()`.

3. **Given** les deux chemins ci-dessus,
   **When** l'écriture du pivot s'effectue,
   **Then** **aucune régression** : la `value` est correctement écrite (`$value`) dans les deux cas ; la trace `CapabilityOverrideAuditLog` (`action=create` à l'INSERT / `action=update` à l'UPDATE, dérivée de `$hasExistingOverride` côté serveur) est **inchangée** ; l'**atomicité acte ↔ trace** (29.5, AC#6 / NFR5) est **préservée** (pivot + audit dans la même `DB::transaction`, rollback solidaire en cas d'échec).

4. **Given** la suite de tests HÔTE (php8.4 + sqlite, `RefreshDatabase`),
   **When** elle s'exécute,
   **Then** un test couvre **explicitement** le scénario **UPDATE-préserve-`created_at`** (le scénario manqué par sonnet, le plus important) : on pose un override avec un `created_at` connu/figé dans le passé, on ré-édite, on **asserte** que `created_at` est **identique** à la valeur d'origine et que `updated_at` a **avancé** ; **et** le cas INSERT (`created_at` posé à la création). Le test suit la convention du composant existant (`tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php`).

## Tasks / Subtasks

- [x] **T1 — Corriger `saveOverride()` : closure `updateOrInsert`** (AC: #1, #2, #3)
  - [x] Dans `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php`, remplacer le tableau de valeurs de l'`updateOrInsert` (≈ L.333-340) par une **closure** :
    ```php
    DB::table('capability_assignments')->updateOrInsert(
        [
            'capability_id' => $capability->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $parc->id,
        ],
        fn (bool $exists) => $exists
            ? ['value' => $value, 'updated_at' => now()]
            : ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
    );
    ```
  - [x] **Ne pas** modifier `$hasExistingOverride`, l'appel `CapabilityOverrideAuditLog::log(...)`, la `DB::transaction`, ni `removeOverride()`. La closure capture `$value` (déjà dans le `use (...)` de la transaction). Vérifier que `$value` est bien dans la portée capturée.
  - [x] `php -l` sur le fichier modifié (le SFC Blade est validé par compilation au passage des tests Livewire).

- [x] **T2 — Test de régression UPDATE-préserve-`created_at` + INSERT-pose-`created_at`** (AC: #4)
  - [x] Dans `tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php` (convention existante du composant) ou un test dédié `CapabilitiesTabCreatedAtPreservationTest.php` si plus lisible : 
    - **INSERT** : `saveOverride()` sur une clé neuve → la ligne `capability_assignments` a `created_at` non nul (≈ `now()`).
    - **UPDATE** (cœur) : pré-insérer un override avec `created_at` figé dans le **passé** (ex. `now()->subDays(3)`) ; déclencher une ré-édition via `saveOverride()` ; **asserter** `created_at` **strictement inchangé** (égal à la valeur passée) **et** `updated_at` **postérieur** au `created_at`. Lire la ligne via `DB::table('capability_assignments')->where(...)->first()`.
  - [x] Réutiliser le harnais du test sœur (vrai `User` avec permission `app.customize` seedée pour satisfaire la FK acteur + passer le guard ; piège bootstrap Spatie cf. 29.5/29.6). SQLite n'applique pas les types PG → tester le **contenu** des colonnes datetime, pas des bornes.

- [x] **T3 — Runbook QA (domaine `rights-management`)** (AC: #1, #2)
  - [x] Consulter `docs/qa/README.md` (convention : append-only, section `## Section N — …`, scénarios `### Scénario N.M` à numéros stables, pas de fichier par story).
  - [x] **Append** une nouvelle **Section 13** à `docs/qa/domains/rights-management.md` (Section 12 = 29.6 ; numérotation stable) : « Préservation de `created_at` du pivot lors d'un override édité (Story 29.7) ». Couvrir : (a) premier override → ligne créée, `created_at` = maintenant ; (b) ré-édition de l'override → `created_at` inchangé, `updated_at` avance ; (c) note : l'audit 29.5 et le compilé ne sont pas concernés (la trace d'audit reste correcte indépendamment). **Ne PAS** créer de fichier de domaine ni renuméroter les sections existantes.

- [x] **T4 — Validation finale** (AC: #1–#4)
  - [x] `php artisan test --filter "CapabilitiesOverrideAudit|CapabilitiesTab"` sur HÔTE → vert (le nouveau test + non-régression du composant : audit create/update/delete, scoping, verrou amont, badges). **45 tests verts, 0 rouge.**
  - [x] Confirmer **0 régression** : la trace d'audit (`action`, `old_value`/`new_value`, `upstream_status`) et le scoping 29.6 / verrou 29.2 sont intacts.
  - [x] Vérifier qu'**aucun** fichier contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1` / migration n'est touché (`git status` : seuls le SFC, le test et la doc QA changent).

## Dev Notes

### Périmètre — ce qui EST / N'EST PAS dans 29.7

**DANS** :
- Correction **chirurgicale** de `saveOverride()` : closure `updateOrInsert` posant `created_at` uniquement à l'INSERT.
- **1 test** de régression (UPDATE préserve `created_at` ; INSERT le pose).
- **1 section** de runbook QA (append `rights-management.md`).

**HORS** (ne pas déborder) :
- **Toucher `removeOverride()`** : c'est un `delete()` sans `created_at` — non concerné.
- **Fusionner la double lecture `exists()`/`value()`** (nit 29.5 #5, footgun `value` nullable) — hors-scope, laissé tel quel.
- **Toute migration / changement de schéma** sur `capability_assignments` — aucun.
- **Toute modification du contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1` / `StateCompiler`** — le pivot `created_at` n'entre pas dans le compilé.
- **Refonte de l'audit 29.5** — la trace `CapabilityOverrideAuditLog` est déjà correcte (horodatage propre), inchangée.

### Décisions de conception

- **Closure plutôt que double appel** : la closure de `updateOrInsert` (3e voie idiomatique Laravel 12) est le moyen le plus simple et atomique de différencier valeurs d'INSERT vs d'UPDATE. Pas besoin de scinder en `insert()`/`update()` manuels (plus verbeux, deux lectures de plus). [Source: vendor Builder.php L.4264-4281]
- **Atomicité préservée** : la closure s'exécute dans `updateOrInsert`, lui-même dans la `DB::transaction` existante (29.5 AC#6). Le couple pivot+audit reste tout-ou-rien.
- **`$hasExistingOverride` reste l'oracle de l'audit** : il est calculé une fois (L.291-295) **avant** la transaction et drive `ACTION_CREATE`/`ACTION_UPDATE`. La closure recalcule son propre `$exists` en interne ; les deux décisions concordent (même clé, même transaction) mais ne sont **pas** couplées dans le code — ne pas tenter de les unifier (gold-plating + footgun).
- **Test : figer `created_at` dans le passé** avant la ré-édition est la seule façon de **prouver** la non-réécriture (sinon `now()` ≈ `now()` masquerait le bug — exactement l'angle mort de sonnet en review 29.5).

### Garde-fous projet

- **Tests HÔTE uniquement** : php8.4 + `pdo_sqlite`, `RefreshDatabase`, **jamais la VM** (worktree git → interdit). [Source: mémoires projet — phpunit_test_env_host_vs_vm, worktree_no_vm_sync]
- **Piège bootstrap Spatie en feature** : un vrai `User` avec `app.customize` seedée satisfait la FK acteur et passe le guard `customize-workstationGroup` (29.6) ; mocks `Authenticatable` sans `HasRoles` évitent le before-hook (cf. 29.2/29.4/29.5). [Source: 29-5 Dev Notes — resolveActor]
- **Vocabulaire R3** : aucun « central » (sans objet ici, mais à respecter dans les libellés/commentaires/doc). [Source: prd-contrat-manage-se5.md#R3]
- **Racine = projet Laravel** (pas de préfixe `laravel/`). [Source: mémoire projet — root_is_laravel]
- **VM** : 29.7 n'ajoute **aucune** migration → rien à jouer côté VM. [Source: mémoire projet — vm_migrations_not_auto_applied]

### Patrons de référence

- **Closure `updateOrInsert`** : `vendor/.../Query/Builder.php::updateOrInsert(array $attributes, array|callable $values)` — la closure reçoit `bool $exists`. C'est l'API documentée/stable de Laravel 12.x du projet.
- **Test sœur** : `tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php` (29.5) — harnais Livewire `Livewire::test`, vrai `User`, assertions sur `capability_assignments` + `capability_override_audit_logs`. Calque pour le nouveau test (focalisé sur les colonnes datetime du pivot).

### Project Structure Notes

- **Modifiés** :
  - `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (closure `updateOrInsert` dans `saveOverride()` — ~5 lignes)
  - `docs/qa/domains/rights-management.md` (Section 13 append)
- **Nouveau (ou modifié)** :
  - `tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php` (test UPDATE-préserve-`created_at` + INSERT) — **ou** `CapabilitiesTabCreatedAtPreservationTest.php` dédié si plus lisible.
- **Inchangés (à NE PAS toucher)** : `removeOverride()`, migration `capability_assignments`, `CapabilityOverrideAuditLog` (modèle + migration audit), `StateCompiler`, contrat agent / golden / `FROZEN_STATE_HASH` / `ContractV1`, `$hasExistingOverride`, la `DB::transaction`.

### References

- [Source: _bmad-output/codeReviews/29-5.md — M1 (L.28, L.92-98)] — origine du follow-up : `updateOrInsert` réécrit `created_at` du pivot à chaque update ; pré-existant 27.12 ; tracé backlog.
- [Source: _bmad-output/implementation-artifacts/sprint-status.yaml L.436] — entrée 29-7 + scope proposé (poser `created_at` uniquement à l'INSERT, 3e argument de `updateOrInsert`).
- [Source: resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php L.291-357] — `saveOverride()` : `$hasExistingOverride`, `DB::transaction`, `updateOrInsert` fautif (L.333-340), `CapabilityOverrideAuditLog::log()`.
- [Source: vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php L.4264-4281] — `updateOrInsert(array $attributes, array|callable $values)` : la closure reçoit `bool $exists`, valeurs appliquées à l'INSERT (`array_merge`) ou à l'UPDATE.
- [Source: _bmad-output/implementation-artifacts/29-5-drift-strict-et-audit-des-overrides.md] — story sœur : contexte `capability_assignments`, audit append-only, atomicité acte↔trace (AC#6), pièges tests (bootstrap Spatie, SQLite, lecture avant mutation).
- [Source: tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php] — convention de test du composant à suivre.
- [Source: docs/qa/README.md ; docs/qa/domains/rights-management.md (Section 12 = 29.6)] — convention runbook append-only, numérotation stable des sections/scénarios.
- [Source: mémoires projet — phpunit_test_env_host_vs_vm, worktree_no_vm_sync, root_is_laravel, vm_migrations_not_auto_applied, drift_policy_strict_only].

## Dépendances

- **Amont (consommées — code livré sur la branche `worktree-contract-CH`)** :
  - **Story 27.12** (création de `capabilities-tab` + `capability_assignments` + l'`updateOrInsert` porteur du défaut). Dépendance de fond (c'est le code corrigé). **Livré.**
  - **Story 29.5** (`DB::transaction` + audit `CapabilityOverrideAuditLog` autour de l'`updateOrInsert`). 29.7 corrige **à l'intérieur** de cette transaction sans en changer la sémantique. `review`/`to-validate`.
  - **Story 29.6** (`#[Locked] groupId` + `guardCustomize()` scopé). Le chemin d'écriture passe par ce guard ; inchangé par 29.7. `review`.
- **Note de statut** : 29.5/29.6 sont en `review` mais leur **code est livré** sur la branche courante ; 29.7 peut démarrer. Re-synchroniser si une correction de review modifie `saveOverride()`/la transaction.

## Testing

- **Cible d'exécution : HÔTE** (php8.4 + `pdo_sqlite`), `DB_CONNECTION=sqlite`, trait `RefreshDatabase`. **Jamais la VM.**
- Filtre ciblé : `php artisan test --filter "CapabilitiesOverrideAudit|CapabilitiesTab"`.
- Couverture obligatoire :
  - **UPDATE préserve `created_at`** (cœur, AC#4) : `created_at` figé dans le passé → inchangé après ré-édition ; `updated_at` avance.
  - **INSERT pose `created_at`** : ligne neuve → `created_at` ≈ `now()`.
  - **Non-régression** : audit (`action` create/update, old/new, `upstream_status`), scoping 29.6, verrou amont 29.2 → 0 régression.
- **Pièges** : SQLite ne contraint pas les types datetime PG → comparer le **contenu** des colonnes, pas des bornes ; **figer** le `created_at` initial dans le passé (sinon `now()`≈`now()` masque le bug) ; vrai `User` + permission seedée pour la FK acteur et le guard.

## Recommandation Modèle Dev

**`sonnet`.**

Justification : la story est **volumétriquement et conceptuellement minimale** — 1 fichier applicatif modifié de ~5 lignes (substitution d'un tableau par une closure), 1 test focalisé, 1 section de runbook. La solution est **connue, idiomatique et vérifiée** (closure `updateOrInsert`, signature confirmée dans `vendor`), **zéro décision d'architecture**, zéro migration, zéro impact contrat/compilé. Le seul point de vigilance — **figer `created_at` dans le passé** pour prouver la non-réécriture — est explicité dans les AC et les Dev Notes. C'est un correctif d'exécution propre, idéal pour `sonnet`. Le dev-cycle routera la review vers le modèle **opposé (opus)** — cohérent avec l'historique 29.x où opus a précisément **détecté** ce défaut (M1) que sonnet avait manqué : la review opus vérifiera les angles morts (closure ne capturant pas `$value`, `removeOverride` touché par mégarde, audit altéré, atomicité rompue, test masquant le bug par `now()`≈`now()`).

## Dev Agent Record

### Agent Model Used

**sonnet (claude-sonnet-4-6)**

### Debug Log References

Aucun — implémentation conforme au plan, aucun écart ou debug nécessaire.

### Completion Notes List

- T1 : closure `updateOrInsert` appliquée sur ~10 lignes (commentaire inclus). `php -l` vert. `$value` bien dans la portée capturée (closure parente de transaction utilise `use ($capability, $parc, $value, …)`).
- T2 : 2 tests ajoutés à `CapabilitiesOverrideAuditTest` : `inserting_a_new_override_sets_created_at` (AC INSERT) + `re_editing_an_override_preserves_original_created_at` (AC UPDATE cœur, `created_at` figé à `now()->subDays(3)`, prouvé INCHANGÉ). Tous deux verts.
- T3 : Section 13 appendée à `docs/qa/domains/rights-management.md` (4 scénarios : 13.1–13.4). Aucun scénario existant renuméroté.
- T4 : `php artisan test --filter "CapabilitiesOverrideAudit|CapabilitiesTab"` → **45 tests verts, 0 rouge**. Scope vérifié (`git status` : 3 fichiers modifiés, aucun contrat/golden/migration touché).
- `$hasExistingOverride`, `removeOverride()`, `DB::transaction`, `CapabilityOverrideAuditLog::log()` : inchangés.

### File List

- `resources/views/pages/parc/groups/_partials/capabilities-tab.blade.php` (T1 — closure `updateOrInsert` dans `saveOverride()`)
- `tests/Feature/Livewire/Parc/CapabilitiesOverrideAuditTest.php` (T2 — 2 tests AC INSERT + AC UPDATE-préserve-`created_at`)
- `docs/qa/domains/rights-management.md` (T3 — Section 13 append, 4 scénarios 13.1–13.4)
