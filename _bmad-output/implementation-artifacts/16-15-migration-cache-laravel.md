# Story 16.15 : Migration cache Laravel

Status: review

> **Tech debt Phase 2** — refactor mécanique : remplacer les appels directs `apcu_store`/`apcu_fetch`/`apcu_delete` par l'abstraction `Illuminate\Support\Facades\Cache` sur **4 cibles directes** de la chaîne contexte applicatif GPO/AppCustomization/Wallpaper. **Pas de logique métier nouvelle**, pas de migration de données, pas de feature flag. Le store reste APCu par défaut (fallback explicite) ; le driver est piloté par `CACHE_DRIVER` dans `.env` (déjà câblé dans `config/cache.php:18` qui lit `env('CACHE_DRIVER', 'apc')`). Renommage symétrique des classes `Apcu*` → `Cache*` pour cohérence.
>
> **Scope strict** (5 fichiers source + 1 config + tests) :
> 1. `app/Services/AppCustomization/ApcuAppContextRepository.php` → `CacheAppContextRepository.php` (lecteur 4.8)
> 2. `app/Services/AppCustomization/ApcuAppContextWriter.php` → `CacheAppContextWriter.php` (écrivain 16.7)
> 3. `app/Services/Wallpaper/ApcuWallpaperContextRepository.php` → `CacheWallpaperContextRepository.php` (lecteur 4.7) — **inclus par symétrie** (cf. D-Q2)
> 4. `app/Http/Controllers/Gpo/ApplicationsScriptsController.php` (cache `scripts.$id`, lignes 169-187 + 325-343) — **lecture+écriture inline**, pas de classe à renommer
> 5. `app/Gpo/Services/ApplicationScriptsGenerator.php::fetchCached` (lignes 318-329) — **lecture inline**
> 6. `config/cache.php` (déjà partiellement aligné) + `.env.example` (doc `CACHE_DRIVER=apc`)
> 7. Tests : 4 fichiers à adapter + 1 nouveau test architecture
>
> **HORS-SCOPE confirmé** (Henri 2026-05-21) — restent en `apcu_*` direct :
> - `app/Doctor/Checks/Cache/ApcuCheck.php` (probe diagnostic dédié à APCu — pas pertinent de l'abstraire)
> - `app/Auth/V1/Services/LegacyBootstrapTokenValidator.php` (validator qui doit lire la clé APCu legacy pour interop avec PHP-FPM, pas de gain à migrer)
> - `app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php` (écriture token éphémère 16.11 — abstraction Cache n'apporte rien)
> - Tous les fichiers `legacy/*` (gpo/veyon_out.php, associations_out.php, ldap.inc.php, dhcp_shim.inc.php, bbb/launch.php, ipxe/…) — code PHP procédural legacy, hors refactoring Phase 2

---

## Encadré contexte

**Origine de la décision** : Tech debt identifiée post-review Story 16.7 (2026-05-13) — clarification cache APCu direct vs abstraction Laravel `Cache::store()`. Renumérotée 2026-05-15 (slot 16.8 réutilisé pour stabilisation Phase 1). Cadrage final 2026-05-21 (sprint-status.yaml ligne 271).

**Pré-requis** :
- 16.7 done ✅ (écrivain `ApcuAppContextWriter` livré, post-review #3 = ajout cache `scripts.$id` dans `ApplicationsScriptsController`)
- 4.7 done ✅ (lecteur `ApcuWallpaperContextRepository`)
- 4.8 done ✅ (lecteur `ApcuAppContextRepository`)
- 16.3b done ✅ (`NetworkOutController` + `VeyonOutController` consomment le repo via contrat)
- 16.3c done ✅ (`AssociationsOutController` consomme le repo via contrat)

**Pourquoi maintenant** : pas urgent, pas bloquant. Story d'abstraction propre permettant la future bascule sur `redis` (Phase 3+ si besoin multi-host shared cache) **sans toucher au code applicatif** — uniquement via `.env`. Lève aussi un risque diagnostic identifié au cadrage : si le driver devait basculer accidentellement sur `file`, le round-trip filesystem dégraderait fortement la latence — d'où le fallback explicite `apc` dans `config/cache.php`.

**État config cache actuel** (`config/cache.php`) :
- ✅ Default lit déjà `env('CACHE_DRIVER', 'apc')` — fallback `apc` correct (ligne 18)
- ✅ Store `apc` déjà déclaré (lignes 36-38)
- ✅ Préfixe global `CACHE_PREFIX` déjà câblé (ligne 109)
- ❌ `.env.example:46` : `CACHE_DRIVER=file` (incohérent avec fallback `apc`) — à passer à `CACHE_DRIVER=apc` + commentaire d'usage
- ⚠️ Aucun store dédié `app_context` ou `gpo_scripts` — on reste sur le default (= APCu en prod, `array` en testing) — pas besoin de complexifier.

---

## Décisions tranchées (D1-D12, ne pas re-débattre)

> Cadrage SM 2026-05-21 (Henri absent — SM tranche autant que possible pour permettre dev autonome). Le dev applique sans re-discuter ; en cas de blocage technique réel, il documente la difficulté dans Dev Agent Record et continue.

### D1 — Pattern de renommage : `Cache*` préfixe (pas `*Cache` suffixe)

- `ApcuAppContextRepository` → `CacheAppContextRepository`
- `ApcuAppContextWriter` → `CacheAppContextWriter`
- `ApcuWallpaperContextRepository` → `CacheWallpaperContextRepository`
- **Rationale** : pattern symétrique au préfixe `Apcu*` existant (lecture naturelle « cache-based AppContextRepository » = implémentation cache du contrat AppContextRepository). Évite la confusion avec un éventuel `*Cache` qui suggérerait une classe utilitaire cache. Iso-pattern de l'industrie Laravel (cf. `EloquentUserRepository`, `RedisQueue`, etc.).
- **Anti-pattern** : ne PAS introduire un suffixe `Backed` (`CacheBackedAppContextRepository`) — verbeux et inutile.
- Le namespace reste identique (`App\Services\AppCustomization\*`, `App\Services\Wallpaper\*`) — seul le basename change.

### D2 — Wallpaper INCLUS dans le scope par symétrie

- `ApcuWallpaperContextRepository` est migré + renommé en `CacheWallpaperContextRepository` dans la même story.
- **Rationale** : (1) il lit la **même clé** `apps.$id` que `ApcuAppContextRepository` (cf. story 4.7 AC3), (2) il sera mocké dans les tests via `Cache::shouldReceive('store')->andReturn(...)` aux côtés du repo App, (3) cohérence du runbook QA, (4) coût marginal (~20 lignes, 5 tests adaptés).
- Tests à modifier : `tests/Unit/Services/Wallpaper/ApcuWallpaperContextRepositoryTest.php` (renommer + adapter — voir Q1).

### D3 — Clés cache iso-legacy préservées : `apps.$id` et `scripts.$id`

- **Préserver les clés brutes existantes** : pas de préfixage `app_context:` ou `gpo:`. Les clés posées par Laravel `Cache::put('apps.' . $id, ...)` doivent être **lisibles** par les consommateurs legacy qui font encore `apcu_fetch('apps.' . $id, ...)` (cf. hors-scope `LegacyBootstrapTokenValidator`).
- **Note importante** : Laravel applique automatiquement le préfixe `CACHE_PREFIX` (cf. `config/cache.php:109`) **devant** les clés via le driver. Côté `apc`, la clé physique sera `<prefix>apps.<id>` — **ce qui casserait l'interop legacy**. Solution : **désactiver le préfixe** spécifiquement pour le store `app_context` en déclarant un store dédié avec `prefix => ''`, OU plus simple : **vider** `CACHE_PREFIX` au runtime via `.env` (`CACHE_PREFIX=` vide).
- **Décision finale** : option **store dédié avec prefix vide** (D5 ci-dessous) — n'impacte pas les autres usages cache de l'app (sessions, route cache, permissions).
- TTL préservés iso-legacy : `apps.$id` = 1800s, `scripts.$id` = 300s.

### D4 — Compat lecteur 4.8 / endpoints 16.3b/c préservée — pas de breaking change

- Les endpoints natifs `network_out`, `veyon_out`, `associations_out` (16.3b/c) consomment `AppContextRepository` via le contrat — ils restent intacts (juste l'implémentation change derrière l'interface).
- Le shim hors-scope `LegacyBootstrapTokenValidator` continue de lire `apcu_fetch('apps.' . $token, ...)` en direct — il doit retrouver les payloads écrits par `CacheAppContextWriter`. **Garantie par D3 + D5** (store dédié sans prefix Laravel, clé physique = clé applicative).
- Garde-fou tests : ajouter un test feature dans `AppContextChainTest.php` qui écrit via `CacheAppContextWriter` ET vérifie que `apcu_fetch('apps.' . $id)` direct retourne le même payload (compat bidirectionnelle).

### D5 — `config/cache.php` : store dédié `app_context` avec `prefix => ''`

- Ajouter un store **explicite** dans `config/cache.php`:
  ```php
  'app_context' => [
      'driver' => env('APP_CONTEXT_CACHE_DRIVER', env('CACHE_DRIVER', 'apc')),
      'prefix' => '', // iso-legacy : clés brutes `apps.$id`, `scripts.$id` pour interop legacy shim
  ],
  ```
- Le code applicatif consomme `Cache::store('app_context')` (PAS `Cache::store()` qui prendrait le default avec préfixe).
- L'env var `APP_CONTEXT_CACHE_DRIVER` permet de découpler le store d'app_context du store général (utile si Phase 3 on veut mettre le general sur redis mais l'app_context reste local apc pour interop legacy).
- Documenter dans `.env.example` : `CACHE_DRIVER=apc` + `# APP_CONTEXT_CACHE_DRIVER=apc # héritage auto si non défini`.

### D6 — Binding service container : `bind` (pas singleton), iso-existant

- Suivre **strictement** ce qui existe dans `AppCustomizationServiceProvider` et `WallpaperServiceProvider` : `$this->app->bind(AppContextRepository::class, ApcuAppContextRepository::class)` → simplement renommer `ApcuAppContextRepository::class` en `CacheAppContextRepository::class`. Idem writer + wallpaper.
- **Rationale** : pas de raison de changer la sémantique du binding (instance fraîche vs unique). Les classes sont stateless (pas de propriété cachée d'instance) — `bind` ou `singleton` se vaudraient mais on conserve l'existant pour minimiser la diff.
- **Anti-pattern** : ne PAS introduire un alias ou un facade-binding custom.

### D7 — Pas d'interface nouvelle, pas de méthode publique nouvelle

- Les interfaces `App\Services\AppCustomization\Contracts\AppContextRepository` et `AppContextWriter` existent déjà (4.8 + 16.7). Idem `App\Services\Wallpaper\Contracts\WallpaperContextRepository` (4.7).
- **Pas de nouvelle interface**. Pas d'ajout de méthode. Refactor purement interne aux implémentations concrètes.
- Anti-pattern : ne PAS créer une interface intermédiaire `CacheBackedRepository` — sur-engineering, le contrat fonctionnel existant suffit.

### D8 — Pas de feature flag, pas de fallback runtime

- Swap atomique en un seul commit. La compat est garantie par D3/D4/D5 (interop legacy via clés brutes + driver `apc` par défaut).
- **Pas de dual-write** APCu + Cache pendant une transition — inutile : `Cache::store('app_context')` avec driver `apc` et prefix vide **EST** physiquement équivalent à `apcu_store(...)` direct. Test feature D4 garde-fou la propriété.
- **Pas de feature flag `gpo.use_cache_abstraction`** — sur-engineering, le swap est trivial à rollback (revert d'un commit).

### D9 — Tests d'architecture : interdire `apcu_*` dans le scope migré

- Nouveau test : `tests/Architecture/CacheAbstractionArchitectureTest.php` (ou complément du test arch Gpo existant si présent).
- Règles :
  - **Aucun appel** à `apcu_store`, `apcu_fetch`, `apcu_delete`, `apcu_enabled`, `apcu_clear_cache` dans :
    - `app/Services/AppCustomization/Cache*Repository.php`
    - `app/Services/AppCustomization/Cache*Writer.php`
    - `app/Services/Wallpaper/Cache*Repository.php`
    - `app/Http/Controllers/Gpo/ApplicationsScriptsController.php`
    - `app/Gpo/Services/ApplicationScriptsGenerator.php`
  - Implémentation : `file_get_contents` + `assertDoesNotMatchRegularExpression('/\bapcu_(store|fetch|delete|enabled|clear_cache)\b/i', $content)` sur chaque fichier ciblé.
- **Hors scope du test** : tous les fichiers `app/Doctor/Checks/Cache/ApcuCheck.php`, `app/Auth/V1/*` (gardent leurs `apcu_*` directs par décision Henri).
- Garde-fou anti-régression : tout futur dev qui ajouterait `apcu_*` dans ces fichiers casserait le test arch.

### D10 — Tests unit/feature : Cache facade fake (pas d'extension PHP requise en CI)

- Pattern standard Laravel : `Cache::shouldReceive('store')->with('app_context')->andReturn($mockStore)` OU plus simple `Cache::store('app_context')->put(...)` réel sur store `array` en testing.
- **Configuration testing** : `phpunit.xml` (ou `tests/TestCase.php`) doit forcer `CACHE_DRIVER=array` OU surcharger le store `app_context` vers `array` quand `app()->environment('testing')`.
- **Choix simple** : utiliser `Cache::store('app_context')->getStore()` ET le mocker au niveau du contrat (Mockery sur AppContextRepository) plutôt que de jouer avec la facade — c'est ce que font déjà les tests existants (cf. `MigrationE2EScenarioTest.php:294`).
- **Tests à mettre à jour** (renommage classes uniquement + remplacement `apcu_store` direct par `Cache::store('app_context')->put`) :
  1. `tests/Unit/Services/AppCustomization/ApcuAppContextWriterTest.php` → renommer en `CacheAppContextWriterTest.php` (FQCN + nom de classe). Adapter setUp : plus de `apcu_clear_cache()`, utiliser `Cache::store('app_context')->flush()`.
  2. `tests/Unit/Services/AppCustomization/AppContextRepositoryTest.php` (existant) → adapter setUp/teardown sur Cache facade. Renommer méthodes test (`missing_apcu_payload_returns_null` → `missing_cache_payload_returns_null`).
  3. `tests/Unit/Services/Wallpaper/ApcuWallpaperContextRepositoryTest.php` — **ce test ne touche pas APCu directement** (il teste le DTO `WallpaperContext::fromApcuArray`). Renommer fichier + nom de méthode `fromApcuArray` du DTO si on veut être strict, OU laisser tel quel (le DTO `WallpaperContext::fromApcuArray` n'est pas du scope cache, c'est juste un parseur de structure). **Décision : laisser le DTO inchangé** (la méthode `fromApcuArray` est un nom historique — renommage trop intrusif pour la story).
  4. `tests/Feature/Gpo/ApplicationsScriptsEndpointTest.php` — adapter le test `it_writes_apcu_context_on_successful_resolution` (ligne 219) pour vérifier via `Cache::store('app_context')->get('apps.' . $id)` au lieu de `apcu_fetch`. Renommer la méthode `it_writes_cache_context_on_successful_resolution`.
  5. `tests/Feature/Gpo/AppContextChainTest.php` — adapter setUp (Cache flush au lieu de `apcu_clear_cache`). **Ajouter un test bi-compat** : payload écrit via `CacheAppContextWriter` est lisible par `apcu_fetch` direct (garantie D3/D4).
- **Pattern recommandé** dans les tests : préférer `$this->app->bind(AppContextRepository::class, fn () => new InMemoryAppContextRepository($fixture))` pour les tests feature plutôt que de toucher au cache réel — patterne déjà utilisée dans `AppPolicyLegacyEndpointTest.php:90`.

### D11 — Hors-scope explicitement protégé

- **NE PAS toucher** aux 4 fichiers/zones suivantes (cf. décision Henri 2026-05-21) :
  1. `app/Doctor/Checks/Cache/ApcuCheck.php` — probe diagnostique spécifique APCu, l'abstraction casserait son utilité.
  2. `app/Auth/V1/Services/LegacyBootstrapTokenValidator.php` — lit `apcu_fetch('apps.' . $token, ...)` en direct pour interop avec le PHP-FPM legacy ; la story 16.11 a tranché ce design.
  3. `app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php:281-296` — écriture éphémère du bootstrap token APCu (config `auth_v1.bootstrap_token.apcu_prefix`).
  4. Tous les fichiers `legacy/*` (gpo/veyon_out.php, gpo/associations_out.php, ldap.inc.php, dhcp_shim.inc.php, bbb/launch.php, ipxe/…) — code PHP procédural legacy, conserve `apcu_store` direct.
- **Tests architecture** : la liste ci-dessus est explicitement **whitelistée** dans le test arch D9 (le grep `apcu_*` ne se fait QUE sur les 5 fichiers du scope).

### D12 — Pas de migration de données, pas de warmup

- APCu est **volatile** par essence (vit dans la mémoire du pool PHP-FPM, perdu au restart). Aucune migration de cache existant nécessaire.
- Après déploiement : les clés `apps.$id`/`scripts.$id` se reposent naturellement au prochain hit (~secondes). Pas de commande artisan `cache:warm`.
- Pas de pré-population, pas de `php artisan cache:clear` requis post-deploy (le ServiceProvider chargera la nouvelle classe, c'est tout).

---

## Story

As a **dev backend Sambaedu**,
I want **migrer les appels APCu directs vers l'abstraction `Cache::store()` Laravel sur la chaîne contexte applicatif GPO**,
so that **le code applicatif soit découplé du driver cache (APCu / Redis / array en test), testable proprement via Cache facade, et la future bascule sur Redis (Phase 3) ne nécessite qu'un changement `.env` sans toucher au code**.

---

## Contexte

### Chaîne complète actuelle (avant 16.15)

```
┌──── ÉCRITURE ────────────────────────────────────────────────────┐
│ Story 16.7 (logon initial)                                       │
│  ApplicationLoggerService::logScripts                            │
│    → AppContextWriter (contrat)                                  │
│      → ApcuAppContextWriter::write('apps.' . $id, $ctx, 1800)   │
│        → apcu_store(...)                                         │
│                                                                  │
│ Story 16.7 post-review #3 (cache scripts assemblés)              │
│  ApplicationsScriptsController::handle                           │
│    → apcu_store('scripts.' . $id, $texts, 300)  ← direct call    │
└──────────────────────────────────────────────────────────────────┘
                                ↓
                       (APCu = mémoire PHP-FPM)
                                ↓
┌──── LECTURE ─────────────────────────────────────────────────────┐
│ Story 4.7 (wallpaper)                                            │
│  WallpaperController::legacyOut                                  │
│    → WallpaperContextRepository (contrat)                        │
│      → ApcuWallpaperContextRepository::findById                  │
│        → apcu_fetch('apps.' . $id, ...)                          │
│                                                                  │
│ Story 4.8 (firefox/thunderbird policies)                         │
│  AppPolicyController::legacyFirefoxOut                           │
│    → AppContextRepository (contrat)                              │
│      → ApcuAppContextRepository::findById                        │
│        → apcu_fetch('apps.' . $id, ...)                          │
│                                                                  │
│ Story 16.3b (network/veyon)                                      │
│  NetworkOutController, VeyonOutController                        │
│    → AppContextRepository                                        │
│                                                                  │
│ Story 16.3c (wine/associations)                                  │
│  AssociationsOutController                                       │
│    → AppContextRepository                                        │
│                                                                  │
│ Story 16.7 (regen scripts)                                       │
│  ApplicationScriptsGenerator::fetchCached                        │
│    → apcu_fetch('apps.' . $id, ...)  ← direct call               │
│                                                                  │
│ Story 16.7 post-review #3 (re-lecture scripts cached)            │
│  ApplicationsScriptsController                                   │
│    → apcu_fetch('scripts.' . $id, ...)  ← direct call            │
└──────────────────────────────────────────────────────────────────┘
```

### Cible après 16.15

Toutes les flèches `apcu_*` direct ↓ se transforment en `Cache::store('app_context')->put/get/forget(...)`. Les implémentations `Apcu*` deviennent `Cache*`. Les bindings dans `AppCustomizationServiceProvider` + `WallpaperServiceProvider` pointent vers les nouvelles classes. Le store `app_context` est déclaré dans `config/cache.php` avec `prefix => ''` (D5) → physiquement identique à un appel APCu direct (D3/D4).

### Pourquoi pas un drop-in plug du driver default Laravel

- Le driver default Laravel `apc` applique le préfixe global `CACHE_PREFIX` (cf. `config/cache.php:109` = `sambaedu_cache_`). Cela casserait la lisibilité des clés `apps.$id` côté legacy shim (`LegacyBootstrapTokenValidator`, code legacy non-portable).
- Solution : store dédié `app_context` avec `prefix => ''` qui contourne le préfixe global — voir D5.

---

## Acceptance Criteria

### AC1 — `config/cache.php` expose un store `app_context` + fallback explicite, `CACHE_DRIVER` documenté

- **AC1.1** : `config/cache.php` contient un store nommé `app_context` avec `driver => env('APP_CONTEXT_CACHE_DRIVER', env('CACHE_DRIVER', 'apc'))` et `prefix => ''` (cf. D5).
- **AC1.2** : Le store `apc` reste déclaré tel quel (lignes 36-38, déjà OK).
- **AC1.3** : Le default reste `env('CACHE_DRIVER', 'apc')` (déjà OK).
- **AC1.4** : `.env.example` ligne 46 passée de `CACHE_DRIVER=file` à `CACHE_DRIVER=apc` (cohérence avec fallback). Commentaire ajouté : `# Driver cache Laravel. apc = APCu local (défaut prod), redis = cache partagé multi-host (Phase 3+), array = tests, file = fallback CI sans APCu.`
- **AC1.5** : Une nouvelle ligne `# APP_CONTEXT_CACHE_DRIVER=apc # Override store app_context (héritage CACHE_DRIVER si non défini)` ajoutée dans `.env.example` juste après.

### AC2 — Renommage `ApcuAppContextRepository` → `CacheAppContextRepository` + implementation Cache

- **AC2.1** : Fichier `app/Services/AppCustomization/ApcuAppContextRepository.php` renommé en `CacheAppContextRepository.php`. Classe publique renommée `CacheAppContextRepository`. Namespace inchangé (`App\Services\AppCustomization`).
- **AC2.2** : Body de `findById($id)` réécrit pour utiliser `Cache::store('app_context')->get('apps.' . $id)` au lieu de `apcu_fetch`. La garde `preg_match('/^[a-f0-9]{32}$/i', $id)` reste en place. La dégradation gracieuse (retour null si rien ou si payload non array) est conservée.
- **AC2.3** : Plus aucun appel à `apcu_*` ni `function_exists('apcu_*')` dans la nouvelle classe.
- **AC2.4** : Binding dans `app/Providers/AppCustomizationServiceProvider.php` ligne 28 : `ApcuAppContextRepository::class` → `CacheAppContextRepository::class` (et import `use App\Services\AppCustomization\CacheAppContextRepository` ligne 8).
- **AC2.5** : Toute référence textuelle dans les **commentaires/docstrings** des fichiers du scope mise à jour (`ApcuAppContextRepository` → `CacheAppContextRepository`).

### AC3 — Renommage `ApcuAppContextWriter` → `CacheAppContextWriter` + implementation Cache

- **AC3.1** : Fichier `app/Services/AppCustomization/ApcuAppContextWriter.php` renommé en `CacheAppContextWriter.php`. Classe `final class CacheAppContextWriter implements AppContextWriter`. Namespace inchangé.
- **AC3.2** : Body de `write($id, $context, $ttl = 1800)` réécrit pour `Cache::store('app_context')->put('apps.' . $id, $context, $ttl)`. Validation md5 stricte préservée. Logs `Log::channel('gpo')` préservés (mêmes messages, juste prefix mis à jour : `[CacheAppContextWriter]` au lieu de `[ApcuAppContextWriter]`).
- **AC3.3** : Body de `forget($id)` réécrit pour `Cache::store('app_context')->forget('apps.' . $id)` + `Cache::store('app_context')->forget('scripts.' . $id)`. La méthode private `apcuAvailable()` est supprimée (plus utile — Cache facade dégrade gracieusement nativement, le store `array` ou `apc` non chargé ne lève pas).
- **AC3.4** : Plus aucun appel à `apcu_*` ni `function_exists('apcu_*')` dans la nouvelle classe.
- **AC3.5** : Binding dans `AppCustomizationServiceProvider` ligne 34 : `ApcuAppContextWriter::class` → `CacheAppContextWriter::class` (et import ligne 9).

### AC4 — Renommage `ApcuWallpaperContextRepository` → `CacheWallpaperContextRepository`

- **AC4.1** : Fichier `app/Services/Wallpaper/ApcuWallpaperContextRepository.php` renommé en `CacheWallpaperContextRepository.php`. Classe renommée. Namespace inchangé.
- **AC4.2** : Body de `findById` réécrit pour `Cache::store('app_context')->get('apps.' . $id)` au lieu de `apcu_fetch`. Garde md5 + dégradation gracieuse préservées.
- **AC4.3** : Binding dans `app/Providers/WallpaperServiceProvider.php` ligne 28 : `ApcuWallpaperContextRepository::class` → `CacheWallpaperContextRepository::class` (+ import ligne 8).
- **AC4.4** : **Le DTO `WallpaperContext::fromApcuArray` est conservé tel quel** (méthode du DTO, pas du scope cache — cf. D10).

### AC5 — `ApplicationsScriptsController` migré sur `Cache::store('app_context')`

- **AC5.1** : Lignes 169-187 : remplacer `function_exists('apcu_fetch') ? @apcu_fetch($scriptCacheKey) : false` par `Cache::store('app_context')->get($scriptCacheKey)` + remplacer `@apcu_store($scriptCacheKey, $texts, 300)` par `Cache::store('app_context')->put($scriptCacheKey, $texts, 300)`.
- **AC5.2** : Lignes 325-343 : même transformation (la fonction `handleSysprep` ou équivalent dupliquée — appliquer le même refactor).
- **AC5.3** : Import `use Illuminate\Support\Facades\Cache;` ajouté en haut du fichier si absent.
- **AC5.4** : Plus aucun appel `apcu_*` dans `ApplicationsScriptsController.php`. Le test arch D9 le garantit.
- **AC5.5** : TTL préservé à 300 secondes (parité legacy review #3 post-16.7).
- **AC5.6** : Clé préservée à `scripts.$id` (parité D3).

### AC6 — `ApplicationScriptsGenerator::fetchCached` migré sur `Cache::store('app_context')`

- **AC6.1** : Méthode `fetchCached(string $id): ?array` (lignes 318-329) réécrite : `Cache::store('app_context')->get('apps.' . $id)`. Garde md5 + retour `null` si payload non array préservés.
- **AC6.2** : Plus aucun appel `apcu_*` dans `app/Gpo/Services/ApplicationScriptsGenerator.php`.
- **AC6.3** : Import `use Illuminate\Support\Facades\Cache;` ajouté si absent.
- **AC6.4** : Pas d'autre modification du fichier (seule la méthode `fetchCached` change — préserver toute la logique métier de `generate()` intacte).

### AC7 — Tests unitaires et feature mis à jour

- **AC7.1** : `tests/Unit/Services/AppCustomization/ApcuAppContextWriterTest.php` renommé en `CacheAppContextWriterTest.php`. FQCN classe : `Tests\Unit\Services\AppCustomization\CacheAppContextWriterTest`. Imports `use App\Services\AppCustomization\CacheAppContextWriter;` + `use App\Services\AppCustomization\CacheAppContextRepository;`.
- **AC7.2** : `setUp()` du test ci-dessus utilise `Cache::store('app_context')->flush()` au lieu de `apcu_clear_cache()`. La méthode `apcuAvailable()` est supprimée du test (Cache facade fonctionne nativement en testing via store array).
- **AC7.3** : `tests/Unit/Services/AppCustomization/AppContextRepositoryTest.php` : méthodes test renommées (`missing_apcu_payload_returns_null` → `missing_cache_payload_returns_null`, `valid_apcu_payload_hydrates_context` → `valid_cache_payload_hydrates_context`). Setup `apcu_store(...)` remplacé par `Cache::store('app_context')->put('apps.' . $id, [...], 1800)`. Teardown `apcu_delete` → `Cache::store('app_context')->forget(...)`.
- **AC7.4** : `tests/Unit/Services/Wallpaper/ApcuWallpaperContextRepositoryTest.php` renommé en `CacheWallpaperContextRepositoryTest.php` (FQCN classe). **Note** : ce test teste le DTO, pas APCu — adaptation minimale (renommage classe + nom de fichier). Méthodes restent telles quelles (`parses_real_apcu_structure_user_and_machine_as_arrays` peut être renommé en `parses_real_cache_structure_user_and_machine_as_arrays` pour cohérence, OU laissé — pas de blocage fonctionnel).
- **AC7.5** : `tests/Feature/Gpo/ApplicationsScriptsEndpointTest.php` : méthode `it_writes_apcu_context_on_successful_resolution` (ligne 219) renommée `it_writes_cache_context_on_successful_resolution`. Assertion remplacée par `Cache::store('app_context')->get('apps.' . $id)` au lieu de `apcu_fetch`.
- **AC7.6** : `tests/Feature/Gpo/AppContextChainTest.php` : setUp `apcu_clear_cache()` → `Cache::store('app_context')->flush()`. **Nouveau test bi-compat ajouté** : `it_preserves_legacy_apcu_interop_when_writing_via_cache_writer` qui :
  1. écrit un payload via `CacheAppContextWriter::write($id, $ctx)`,
  2. assert `apcu_fetch('apps.' . $id)` (direct, sans abstraction) retourne le même payload (skip si APCu non disponible en CLI).
  Garantit D3/D4 (interop legacy shim).

### AC8 — Test architecture interdit `apcu_*` dans le scope migré

- **AC8.1** : Nouveau fichier `tests/Architecture/CacheAbstractionArchitectureTest.php` créé (namespace `Tests\Architecture`, hérite `Tests\TestCase`).
- **AC8.2** : Test `no_apcu_calls_in_migrated_files` itère sur la liste des 5 fichiers du scope :
  ```php
  $files = [
      'app/Services/AppCustomization/CacheAppContextRepository.php',
      'app/Services/AppCustomization/CacheAppContextWriter.php',
      'app/Services/Wallpaper/CacheWallpaperContextRepository.php',
      'app/Http/Controllers/Gpo/ApplicationsScriptsController.php',
      'app/Gpo/Services/ApplicationScriptsGenerator.php',
  ];
  foreach ($files as $f) {
      $content = file_get_contents(base_path($f));
      self::assertDoesNotMatchRegularExpression(
          '/\bapcu_(store|fetch|delete|enabled|clear_cache|exists|inc|dec|add|cas)\b/i',
          $content,
          "Fichier {$f} doit être 100% migré sur Cache::store() — aucun appel apcu_* direct toléré.",
      );
  }
  ```
- **AC8.3** : Test `apcu_check_still_uses_direct_apcu` (garde-fou hors-scope) : vérifie que `app/Doctor/Checks/Cache/ApcuCheck.php` contient TOUJOURS `apcu_store` et `apcu_fetch` (cf. D11 hors-scope intentionnel — anti-régression sur la frontière du scope).
- **AC8.4** : Test `legacy_bootstrap_validator_still_uses_direct_apcu` : idem pour `LegacyBootstrapTokenValidator.php`.

### AC9 — Runbook QA mis à jour dans `docs/qa/domains/gpo.md`

- **AC9.1** : Nouvelle section ajoutée à la fin de `docs/qa/domains/gpo.md` : `## Story 16.15 — Migration cache Laravel` avec 4-6 scénarios manuels :
  - **Scénario 16.15-1** : Verifier `php artisan cache:store-info app_context` (ou inspection manuelle `config('cache.stores.app_context')`) → driver = `apc`, prefix = `''`.
  - **Scénario 16.15-2** : Bascule `.env` `CACHE_DRIVER=array` puis hit `/gpo/applications.php` → endpoint doit toujours fonctionner (dégradation gracieuse, pas de fatale).
  - **Scénario 16.15-3** : Re-bascule `.env` `CACHE_DRIVER=apc` + `php artisan config:clear` + hit endpoint → payload visible en CLI via `php -r 'echo print_r(apcu_fetch("apps.<id>"), true);'` (interop legacy).
  - **Scénario 16.15-4** : Bench micro-régression : 100 hits `/gpo/applications.php` post-16.15 vs baseline post-16.7 → écart de latence < 5 % (acceptable overhead Cache facade vs apcu_* direct).
  - **Scénario 16.15-5** : `php artisan tinker` → `Cache::store('app_context')->put('apps.test', ['x' => 1], 60); apcu_fetch('apps.test');` retourne `['x' => 1]` (preuve interop bi-directionnelle).
- **AC9.2** : Référence ajoutée dans la section "Pré-requis communs" du fichier si pertinent (driver `apc` requis pour scénarios manuels).

### AC10 — Compat lecteur 4.8 / endpoints 16.3b/16.3c préservée (clés cache identiques, TTL identiques)

- **AC10.1** : Tests feature existants `tests/Feature/AppCustomization/AppPolicyLegacyEndpointTest.php`, `tests/Feature/Gpo/NetworkOutComparisonTest.php`, `tests/Feature/Gpo/AssociationsOutComparisonTest.php` passent **inchangés** (la signature du contrat `AppContextRepository::findById` est préservée).
- **AC10.2** : Test bi-compat AC7.6 (`AppContextChainTest::it_preserves_legacy_apcu_interop_...`) **passe** en VM avec APCu chargé.
- **AC10.3** : TTL conservé strictement : `apps.$id` = 1800s, `scripts.$id` = 300s (assertions explicites dans `CacheAppContextWriterTest`).

---

## Tasks / Subtasks

### Phase T0 — Setup & scaffolding (~0.5j)

- [x] T0.1 — Lire intégralement `config/cache.php` et `.env.example` + relire les 5 fichiers cibles (`Apcu*Repository.php`, `Apcu*Writer.php`, `ApplicationsScriptsController.php` lignes 150-200 + 320-350, `ApplicationScriptsGenerator.php` lignes 100-130 + 310-330)
- [x] T0.2 — Vérifier qu'aucun nouveau commit n'a modifié les fichiers cibles depuis 2026-05-21 (la story est cadrée sur le code à cette date) — `git log --since=2026-05-21 -- app/Services/AppCustomization app/Services/Wallpaper app/Http/Controllers/Gpo/ApplicationsScriptsController.php app/Gpo/Services/ApplicationScriptsGenerator.php`. Si conflit potentiel, escalader à Henri.
- [x] T0.3 — Confirmer dans `tests/TestCase.php` ou `phpunit.xml` que `CACHE_DRIVER=array` est forcé en testing (sinon l'ajouter dans `phpunit.xml` env block).

### Phase T1 — Config cache + .env (AC1) (~0.5j)

- [x] T1.1 — Éditer `config/cache.php` : ajouter le store `app_context` dans le tableau `stores` (juste après `apc`, avant `array`). Body iso D5.
- [x] T1.2 — Éditer `.env.example` : ligne 46 `CACHE_DRIVER=file` → `CACHE_DRIVER=apc`. Ajouter commentaire d'usage. Ajouter ligne `APP_CONTEXT_CACHE_DRIVER` commentée.
- [x] T1.3 — **Pas de modif `.env`** (fichier local de chaque serveur — Henri le ferra en prod si besoin).

### Phase T2 — Renommer + migrer classes du scope (AC2, AC3, AC4) (~1j)

- [x] T2.1 — `git mv app/Services/AppCustomization/ApcuAppContextRepository.php app/Services/AppCustomization/CacheAppContextRepository.php`. Renommer classe interne + adapter body iso AC2.
- [x] T2.2 — `git mv app/Services/AppCustomization/ApcuAppContextWriter.php app/Services/AppCustomization/CacheAppContextWriter.php`. Renommer + adapter body iso AC3. **Supprimer** la méthode privée `apcuAvailable()`.
- [x] T2.3 — `git mv app/Services/Wallpaper/ApcuWallpaperContextRepository.php app/Services/Wallpaper/CacheWallpaperContextRepository.php`. Renommer + adapter body iso AC4.
- [x] T2.4 — Adapter `app/Providers/AppCustomizationServiceProvider.php` (imports lignes 8-9 + bindings lignes 28+34).
- [x] T2.5 — Adapter `app/Providers/WallpaperServiceProvider.php` (import ligne 8 + binding ligne 28).
- [x] T2.6 — Grep global `ApcuAppContextRepository\|ApcuAppContextWriter\|ApcuWallpaperContextRepository` dans `app/`, `config/`, `routes/`, `bootstrap/`, `resources/views/`. Pour chaque occurrence dans un **commentaire/docstring**, remplacer par le nouveau nom. Pour chaque occurrence dans un **import/use** ou un **type-hint**, mettre à jour. Pour chaque occurrence dans `docs/`, mettre à jour. **Exclure** les occurrences dans `_bmad-output/implementation-artifacts/` (historique des stories — figé).

### Phase T3 — Migrer call sites controller + generator (AC5, AC6) (~0.5j)

- [x] T3.1 — Éditer `app/Http/Controllers/Gpo/ApplicationsScriptsController.php` : remplacer les 4 call sites `apcu_fetch`/`apcu_store` (lignes 170, 185-186, 326, 342-343) par `Cache::store('app_context')->get/put`. Ajouter `use Illuminate\Support\Facades\Cache;` si absent.
- [x] T3.2 — Éditer `app/Gpo/Services/ApplicationScriptsGenerator.php::fetchCached` (lignes 318-329) : remplacer par `Cache::store('app_context')->get('apps.' . $id)`. Ajouter import si absent.
- [x] T3.3 — Grep global `apcu_` dans les 5 fichiers du scope → doit retourner zéro résultat. Si une référence persiste, l'éliminer.

### Phase T4 — Tests unitaires & feature (AC7, AC10) (~1j)

- [x] T4.1 — `git mv tests/Unit/Services/AppCustomization/ApcuAppContextWriterTest.php tests/Unit/Services/AppCustomization/CacheAppContextWriterTest.php`. Adapter setUp + tests. Préserver les ≥4 cas de test existants (`write_persists_iso_legacy_structure`, `write_rejects_invalid_id`, `write_rejects_empty_id`, `forget_removes_keys_apps_and_scripts`).
- [x] T4.2 — Adapter `tests/Unit/Services/AppCustomization/AppContextRepositoryTest.php` (rename méthodes + remplacement `apcu_store` → `Cache::store('app_context')->put`).
- [x] T4.3 — `git mv tests/Unit/Services/Wallpaper/ApcuWallpaperContextRepositoryTest.php tests/Unit/Services/Wallpaper/CacheWallpaperContextRepositoryTest.php`. Renommer classe seulement (DTO inchangé).
- [x] T4.4 — Adapter `tests/Feature/Gpo/ApplicationsScriptsEndpointTest.php::it_writes_apcu_context_on_successful_resolution` (ligne 219).
- [x] T4.5 — Adapter `tests/Feature/Gpo/AppContextChainTest.php` (setUp + ajout test bi-compat AC7.6).
- [x] T4.6 — Grep global `tests/` pour `ApcuAppContextRepository\|ApcuAppContextWriter\|ApcuWallpaperContextRepository` → adapter chaque occurrence restante.
- [x] T4.7 — Vérifier que les tests **non concernés** (NetworkOutComparisonTest, AssociationsOutComparisonTest, AppPolicyLegacyEndpointTest, WallpaperResolverTest, etc.) restent intacts (la signature des contrats ne change pas).

### Phase T5 — Test architecture (AC8) (~0.5j)

- [x] T5.1 — Créer `tests/Architecture/CacheAbstractionArchitectureTest.php` avec les 3 tests AC8.2/AC8.3/AC8.4. Suivre le pattern de `tests/Architecture/Migration/MigrationModuleArchitectureTest.php`.
- [x] T5.2 — Vérifier mentalement que les regex `\bapcu_(store|fetch|delete|...)\b/i` matchent bien tous les call sites (le grep arch est strict).

### Phase T6 — Runbook QA (AC9) (~0.5j)

- [x] T6.1 — Éditer `docs/qa/domains/gpo.md` : ajouter section `## Story 16.15` avec les 5 scénarios AC9.1.
- [x] T6.2 — Ajouter une checklist rapide en fin de section (iso-pattern 16.1, 16.7).

### Phase T7 — Static delivery validation (~0.5j)

- [x] T7.1 — `composer dump-autoload` (pour s'assurer que PSR-4 picks up les nouveaux noms de fichiers). — Différé VM (pas de vendor/).
- [x] T7.2 — **Pas de pest/phpunit exécuté côté dev** (pas de sync VM en worktree — pattern iso 16.10/16.11/16.12/16.13/16.13bis). Le dev livre code + tests verts en static delivery.
- [x] T7.3 — Vérifier visuellement les fichiers livrés : pas de typo dans les imports, pas d'oubli de `use Cache;`, pas de classe orpheline. php -l OK 11 fichiers.
- [x] T7.4 — Compiler la liste exhaustive des fichiers touchés dans Dev Agent Record / File List.

---

## Dev Notes

### Pattern Cache::store dédié (D5) — exemple body cible

```php
// app/Services/AppCustomization/CacheAppContextRepository.php
namespace App\Services\AppCustomization;

use App\Dto\AppCustomization\AppContext;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Illuminate\Support\Facades\Cache;

class CacheAppContextRepository implements AppContextRepository
{
    public function findById(string $id): ?AppContext
    {
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return null;
        }

        $payload = Cache::store('app_context')->get('apps.' . $id);
        if (! is_array($payload)) {
            return null;
        }

        return AppContext::fromApcuArray($payload);
    }
}
```

### Pattern écrivain (D5 + AC3)

```php
// app/Services/AppCustomization/CacheAppContextWriter.php
namespace App\Services\AppCustomization;

use App\Services\AppCustomization\Contracts\AppContextWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class CacheAppContextWriter implements AppContextWriter
{
    public function write(string $id, array $context, int $ttl = 1800): void
    {
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            Log::channel('gpo')->warning('[CacheAppContextWriter] invalid id format', [
                'id_hash' => substr(hash('sha256', $id), 0, 12),
            ]);
            return;
        }

        Cache::store('app_context')->put('apps.' . $id, $context, $ttl);

        Log::channel('gpo')->info('[gpo] gpo.applications.context.put success', [
            'action_type' => 'gpo.applications.context.put',
            'id' => $id,
            'ttl' => $ttl,
            'keys' => array_keys($context),
        ]);
    }

    public function forget(string $id): void
    {
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return;
        }
        Cache::store('app_context')->forget('apps.' . $id);
        Cache::store('app_context')->forget('scripts.' . $id);
    }
}
```

### Fichiers exhaustifs touchés (résumé pour File List Dev Agent Record)

**Renommés (git mv)** :
1. `app/Services/AppCustomization/ApcuAppContextRepository.php` → `CacheAppContextRepository.php`
2. `app/Services/AppCustomization/ApcuAppContextWriter.php` → `CacheAppContextWriter.php`
3. `app/Services/Wallpaper/ApcuWallpaperContextRepository.php` → `CacheWallpaperContextRepository.php`
4. `tests/Unit/Services/AppCustomization/ApcuAppContextWriterTest.php` → `CacheAppContextWriterTest.php`
5. `tests/Unit/Services/Wallpaper/ApcuWallpaperContextRepositoryTest.php` → `CacheWallpaperContextRepositoryTest.php`

**Modifiés** :
6. `app/Providers/AppCustomizationServiceProvider.php` (imports + bindings)
7. `app/Providers/WallpaperServiceProvider.php` (import + binding)
8. `app/Http/Controllers/Gpo/ApplicationsScriptsController.php` (4 call sites + import)
9. `app/Gpo/Services/ApplicationScriptsGenerator.php` (1 méthode + import)
10. `config/cache.php` (store `app_context` ajouté)
11. `.env.example` (CACHE_DRIVER documenté)
12. `tests/Unit/Services/AppCustomization/AppContextRepositoryTest.php` (apcu → Cache)
13. `tests/Feature/Gpo/ApplicationsScriptsEndpointTest.php` (méthode `it_writes_apcu_context...` renommée)
14. `tests/Feature/Gpo/AppContextChainTest.php` (setUp + nouveau test bi-compat)
15. `docs/qa/domains/gpo.md` (nouvelle section 16.15)
16. `app/Gpo/README.md` (référence ApcuAppContextWriter → CacheAppContextWriter, ligne 231)

**Créés** :
17. `tests/Architecture/CacheAbstractionArchitectureTest.php` (3 tests)

**NON touchés (hors-scope, anti-régression test arch)** :
- `app/Doctor/Checks/Cache/ApcuCheck.php`
- `app/Auth/V1/Services/LegacyBootstrapTokenValidator.php`
- `app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php`
- `legacy/**/*` (intégralité)
- `app/Dto/AppCustomization/AppContext.php` (méthode `fromApcuArray` du DTO conservée — nom historique, pas du scope cache, cf. D10)
- `app/Dto/Wallpaper/WallpaperContext.php` (idem)

### Pattern testing — Cache::store en environment testing

`phpunit.xml` (ou `tests/TestCase.php`) doit définir :
```xml
<env name="CACHE_DRIVER" value="array"/>
<env name="APP_CONTEXT_CACHE_DRIVER" value="array"/>
```
Vérifier si déjà présent en T0.3. Sinon ajouter.

### Pièges à éviter

1. **`Cache::store()` sans argument** : prendrait le default avec préfixe → casserait l'interop legacy. **Toujours** appeler `Cache::store('app_context')` (D3).
2. **Oublier le `use Illuminate\Support\Facades\Cache;`** dans les 5 fichiers du scope → ParseError silencieux côté autoload (mais erreur runtime).
3. **Modifier `AppContext::fromApcuArray`** : tentation de renommer en `fromCacheArray` — **NE PAS LE FAIRE**. C'est un parseur de structure de données legacy posée par `applications.inc.php`, le nom est lié à l'origine du payload, pas au mécanisme de stockage.
4. **Test arch trop strict** : la regex `\bapcu_\b/i` doit ignorer les **commentaires** ? Non — au contraire, on veut interdire `apcu_*` même en commentaire dans les fichiers migrés (pollution conceptuelle). Garde-fou anti-confusion future.
5. **`Cache::store('app_context')->flush()` en testing** : Laravel array store reset par worker → setUp `flush()` recommandé pour les tests isolés.
6. **CACHE_PREFIX vide global** : tentation de mettre `CACHE_PREFIX=` vide globalement → casserait potentiellement d'autres stores (sessions, permissions cache). **NE PAS** modifier `CACHE_PREFIX` global ; mettre `prefix => ''` uniquement sur le store dédié `app_context` (D5).

### References

- [Source: sprint-status.yaml#L271] — Cadrage complet story 16.15 (décisions Henri 2026-05-21)
- [Source: config/cache.php] — Driver default + stores
- [Source: app/Providers/AppCustomizationServiceProvider.php:26-35] — Bindings à modifier
- [Source: app/Providers/WallpaperServiceProvider.php:26-30] — Binding wallpaper
- [Source: app/Services/AppCustomization/ApcuAppContextRepository.php] — Implémentation cible (4.8)
- [Source: app/Services/AppCustomization/ApcuAppContextWriter.php] — Implémentation cible (16.7)
- [Source: app/Services/Wallpaper/ApcuWallpaperContextRepository.php] — Implémentation cible (4.7)
- [Source: app/Http/Controllers/Gpo/ApplicationsScriptsController.php:169-187, 325-343] — Call sites direct apcu_*
- [Source: app/Gpo/Services/ApplicationScriptsGenerator.php:318-329] — Méthode fetchCached
- [Source: tests/Architecture/Migration/MigrationModuleArchitectureTest.php] — Pattern test architecture
- [Source: app/Gpo/README.md:231] — Référence ApcuAppContextWriter dans la doc Gpo
- [Source: docs/qa/domains/gpo.md] — Runbook QA cible (section 16.15 à ajouter)

---

## Dépendances

### Pré-requis (toutes done)

- **Story 4.7** ✅ done — `ApcuWallpaperContextRepository` livré
- **Story 4.8** ✅ done — `ApcuAppContextRepository` + contrats `AppContextRepository` livrés
- **Story 16.3b** ✅ done — `NetworkOutController` + `VeyonOutController` consomment via contrat
- **Story 16.3c** ✅ done — `AssociationsOutController` consomme via contrat
- **Story 16.7** ✅ done (status: review) — `ApcuAppContextWriter` + cache `scripts.$id` + `ApplicationScriptsGenerator::fetchCached` livrés

### Stories aval bloquées par 16.15 ?

**Aucune** — tech debt en parallèle Phase 2, pas dans la séquence critique. Pas de story aval dépendante. Phase 3 (futur multi-host avec redis shared cache) bénéficierait de cette abstraction mais n'est pas planifiée.

### Compat ascendante préservée

- Endpoints natifs (`network_out`, `veyon_out`, `associations_out`, `firefox_out`, `thunderbird_out`, `wallpaper_out`, `applications.php`) consomment les contrats `AppContextRepository` / `WallpaperContextRepository` — signature intacte.
- Shim hors-scope `LegacyBootstrapTokenValidator` lit `apcu_fetch('apps.' . $token, ...)` direct — interop préservée par D3 + D5 (store dédié `prefix => ''`).

---

## Risques

| ID | Risque | Sévérité | Mitigation |
|---|---|---|---|
| **R1** | Le préfixe global `CACHE_PREFIX` casse l'interop legacy (clés physiques `sambaedu_cache_apps.$id` au lieu de `apps.$id`) | **Critique** | D5 — store dédié `app_context` avec `prefix => ''`. Test bi-compat AC7.6 garde-fou. |
| **R2** | `Cache::store('app_context')` non résolvable en testing (driver `apc` non chargé en CI) | Moyenne | D5 fallback chain : `APP_CONTEXT_CACHE_DRIVER → CACHE_DRIVER → 'apc'`. `phpunit.xml` force `CACHE_DRIVER=array` (T0.3). |
| **R3** | Régression silencieuse des TTL (1800s/300s mal recopiés) | Moyenne | AC10.3 — assertions explicites dans `CacheAppContextWriterTest`. Code review attendu sur les valeurs de TTL. |
| **R4** | Oubli d'un call site `apcu_*` dans les 5 fichiers du scope | Faible | AC8 test arch grep `\bapcu_\b/i` sur la liste exhaustive — fail automatique. |
| **R5** | Tests existants cassés par renommage (FQCN classe modifié) | Faible | T4.6 — grep global `tests/` pour `ApcuAppContextRepository\|...` avant push. |
| **R6** | Performance dégradée par overhead Cache facade vs apcu_* direct | Très faible | Scénario QA AC9.1.4 — bench micro 100 hits, écart < 5 % acceptable. Driver `apc` reste le même physiquement, l'overhead est juste le routing Laravel facade (~1-5 µs par appel). |
| **R7** | `Cache::store('app_context')->forget()` ne supprime pas les deux clés (`apps.$id` ET `scripts.$id`) | Faible | AC3.3 — méthode `forget` appelle explicitement `forget` deux fois. Test existant `forget_removes_keys_apps_and_scripts` adapté en T4.1. |
| **R8** | Confusion conceptuelle si futur dev ajoute `apcu_*` direct dans un fichier migré (anti-pattern) | Faible | AC8 test arch + commentaire `@see CacheAbstractionArchitectureTest` dans les fichiers migrés. |
| **R9** | Comportement Cache facade avec `null` vs `false` (apcu retournait `false` sur miss + bool `$success`) | Faible | Cache facade retourne `null` sur miss → la garde `! is_array($payload)` couvre les deux cas (null OU non-array). Iso-pattern dans tous les `findById`. |

---

## Recommandation Modèle Dev

### Recommandation : **sonnet** (`claude-sonnet-4-5`)

**Justification (2 lignes)** : Refactor mécanique périmètre fermé (5 fichiers source + 4 tests + 1 config + 1 doc), zéro nouvelle logique métier, patterns Laravel standards (Cache facade, ServiceProvider binding, store config). Toutes les décisions tranchées en D1-D12, AC granulaire (~10 AC, ~30 sub-AC), risques mitigés, pas de surprise architecturale — profil sonnet typique (vélocité + qualité acceptable pour code mécanique).

**Critères qui auraient justifié opus** (absents ici) :
- ❌ Nouveau pattern architectural à inventer
- ❌ Plus de 3 fichiers à concevoir from scratch (ici : 1 seul nouveau fichier = test arch trivial)
- ❌ Logique métier complexe ou trade-offs subtils (ici : refactor 1-to-1, contrats préservés)
- ❌ Risque de régression élevé sur scope large (ici : 5 fichiers, scope clairement borné par D11)
- ❌ Manque de décisions tranchées en amont (ici : 12 décisions D1-D12 + 5 D-Q tranchées)

**Estimation charge** : **1.5-2 jours** (T0+T1: 1j, T2: 1j, T3-T7: 1j cumulé). Iso estimation Henri (sprint-status.yaml ligne 271 : "Estimation 1-2j").

---

## Dev Agent Record

### Agent Model Used

`claude-sonnet-4-6` (2026-05-21)

### Debug Log References

- T0.2 : `git log --since=2026-05-21` → aucun commit sur les fichiers cibles depuis cadrage SM. Pas de conflit.
- T0.3 : `phpunit.xml` avait déjà `CACHE_DRIVER=array` (ligne 33). `APP_CONTEXT_CACHE_DRIVER=array` ajouté.
- T3.3 : grep final `apcu_` sur 5 fichiers scope → 0 résultat.
- T4.6 : grep `ApcuAppContextRepository|ApcuAppContextWriter|ApcuWallpaperContextRepository` dans `tests/` → 0 résultat.
- T7.3 : `php -l` sur 11 fichiers PHP → 0 erreur de syntaxe.

### Completion Notes List

- Toutes les phases T0-T7 complètes. Aucune déviation vs plan SM D1-D12.
- `apcuAvailable()` méthode privée supprimée de `CacheAppContextWriter` (AC3.3) — Cache facade dégrade nativement.
- Test bi-compat `it_preserves_legacy_apcu_interop_when_writing_via_cache_writer` créé en AC7.6 — marqué `markTestSkipped` si APCu CLI non disponible, pas si APCu disponible (test VM obligatoire AC10.2).
- Test `write_uses_iso_legacy_ttl_1800s` ajouté dans `CacheAppContextWriterTest` (AC10.3 assertion TTL explicite).
- `app/Gpo/README.md` ligne 231 mis à jour (`Pose APCu` → `Pose cache`, ref classe mise à jour).
- `app/Services/AppCustomization/Contracts/AppContextWriter.php` @see mis à jour.
- `app/Gpo/Services/ApplicationScriptsAssembler.php` commentaire param mis à jour.
- Commentaires docblock avec `apcu_fetch`/`apcu_store` dans les fichiers migrés nettoyés (remplacés par références neutres).
- Hors-scope (`LegacyBootstrapTokenValidator`, `MigrationFragmentRenderer`, `auth_v1.php`) conservés tels quels — leurs références historiques à `ApcuAppContextWriter` dans des commentaires sont figées (documenter l'historique).
- `docs/qa/domains/gpo.md` enrichi append-only : section 16.15 + 5 scénarios numérotés stables + checklist 5 cases.
- **Items différés VM** (pattern static delivery) :
  - `composer install` (si provider rechargé)
  - `php artisan config:clear && php artisan cache:clear`
  - `./vendor/bin/pest tests/Unit/Services/AppCustomization/CacheAppContextWriterTest.php tests/Unit/Services/AppCustomization/AppContextRepositoryTest.php tests/Unit/Services/Wallpaper/CacheWallpaperContextRepositoryTest.php tests/Feature/Gpo/AppContextChainTest.php tests/Feature/Gpo/ApplicationsScriptsEndpointTest.php tests/Architecture/CacheAbstractionArchitectureTest.php`
  - Test bi-compat AC7.6 (`it_preserves_legacy_apcu_interop_when_writing_via_cache_writer`) — nécessite APCu CLI activé (apc.enable_cli=1 dans php.ini CLI)
  - Smoke scénarios 16.15-1 à 16.15-5 (docs/qa/domains/gpo.md)
  - Bench micro-régression 100 hits 16.15-4
- **Recommandation code-review : opus** (opposé de sonnet dev — pattern iso 16.10/16.11/16.12/16.13/16.13bis/16.14)

### File List

**Renommés (git mv)** :
1. `app/Services/AppCustomization/ApcuAppContextRepository.php` → `app/Services/AppCustomization/CacheAppContextRepository.php`
2. `app/Services/AppCustomization/ApcuAppContextWriter.php` → `app/Services/AppCustomization/CacheAppContextWriter.php`
3. `app/Services/Wallpaper/ApcuWallpaperContextRepository.php` → `app/Services/Wallpaper/CacheWallpaperContextRepository.php`
4. `tests/Unit/Services/AppCustomization/ApcuAppContextWriterTest.php` → `tests/Unit/Services/AppCustomization/CacheAppContextWriterTest.php`
5. `tests/Unit/Services/Wallpaper/ApcuWallpaperContextRepositoryTest.php` → `tests/Unit/Services/Wallpaper/CacheWallpaperContextRepositoryTest.php`

**Modifiés** :
6. `app/Providers/AppCustomizationServiceProvider.php` (imports + bindings → Cache*)
7. `app/Providers/WallpaperServiceProvider.php` (import + binding → Cache*)
8. `app/Http/Controllers/Gpo/ApplicationsScriptsController.php` (4 call sites apcu → Cache::store + import)
9. `app/Gpo/Services/ApplicationScriptsGenerator.php` (fetchCached → Cache::store + import)
10. `config/cache.php` (store `app_context` ajouté avec prefix '')
11. `.env.example` (CACHE_DRIVER=apc + commentaire + APP_CONTEXT_CACHE_DRIVER commentée)
12. `tests/Unit/Services/AppCustomization/AppContextRepositoryTest.php` (renommage méthodes + Cache::store)
13. `tests/Feature/Gpo/ApplicationsScriptsEndpointTest.php` (méthode renommée + import Cache)
14. `tests/Feature/Gpo/AppContextChainTest.php` (setUp Cache + test bi-compat + classes renommées)
15. `tests/Unit/Services/Wallpaper/CacheWallpaperContextRepositoryTest.php` (classe renommée)
16. `docs/qa/domains/gpo.md` (section 16.15 ajoutée append-only)
17. `phpunit.xml` (APP_CONTEXT_CACHE_DRIVER=array ajouté)
18. `app/Gpo/README.md` (ligne 231 — ref classe mise à jour)
19. `app/Services/AppCustomization/Contracts/AppContextWriter.php` (@see mis à jour)
20. `app/Gpo/Services/ApplicationScriptsAssembler.php` (commentaire param mis à jour)

**Créés** :
21. `tests/Architecture/CacheAbstractionArchitectureTest.php` (3 tests AC8.2/AC8.3/AC8.4)

**NON touchés (hors-scope D11)** :
- `app/Doctor/Checks/Cache/ApcuCheck.php`
- `app/Auth/V1/Services/LegacyBootstrapTokenValidator.php`
- `app/Auth/V1/Migration/Services/MigrationFragmentRenderer.php`
- `legacy/**/*`
- `app/Dto/AppCustomization/AppContext.php` (méthode `fromApcuArray` conservée — nom historique)
- `app/Dto/Wallpaper/WallpaperContext.php` (idem)
