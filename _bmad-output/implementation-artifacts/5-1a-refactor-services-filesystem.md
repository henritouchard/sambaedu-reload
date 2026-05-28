# Story 5.1a : Refactor Services Filesystem (extraction HomeDirService + XfsQuotaService)

Status: done

> **Origine :** Epic 5 — Système de Fichiers SER. La Story 5.1 parent a été **splittée le 2026-04-22** en 4 sous-stories (brainstorm Henri + investigation du legacy `sambaedu/includes/quotas.inc.php`). Cette story 5.1a est la **fondation refactor** : on isole le code filesystem déjà existant dans des services dédiés avant de faire évoluer le domaine (snapshot BDD en 5.1b, UI groupes + settings en 5.1c, gaps produit en 5.1d).
>
> **Scope :** **pur refactoring à iso-comportement**. Aucune nouvelle feature utilisateur, aucune modification d'API publique, aucune migration DB. L'objectif unique est de **déplacer** le code existant vers une structure propre (`app/Services/Filesystem/`) et de **supprimer** le cache 5 min devenu obsolète (il sera remplacé par un snapshot BDD en 5.1b — mais ce remplacement n'est PAS dans le scope 5.1a).
>
> **Épic :** Epic 5 — Système de Fichiers SER.
>
> **Dépendances amont :** aucune.
>
> **Stories avales bloquées par 5.1a :** 5.1b, 5.1c, 5.1d (toutes consomment `HomeDirService` / `XfsQuotaService` via DI).

---

## Story

En tant que **développeur SER**,
je veux que les responsabilités filesystem (gestion des home directories XFS et gestion des quotas XFS) soient isolées dans des services dédiés `App\Services\Filesystem\HomeDirService` et `App\Services\Filesystem\XfsQuotaService`,
afin que `UserService` (2513 lignes aujourd'hui) soit allégé, que le domaine FS soit extensible indépendamment des autres domaines, et que les stories 5.1b/c/d disposent d'un socle propre pour ajouter snapshot BDD, UI groupes et flash over-quota sans devoir composer avec du code historique dispersé.

---

## Contexte & Motivation

### Pourquoi ce refactor avant 5.1b/c/d

L'Epic 5 ajoute plusieurs évolutions non triviales au domaine Filesystem :

- **5.1b** — migration du cache 5 min → snapshot BDD `users.quota_snapshot` JSONB, commande `quota:snapshot` planifiée 03h00, section UI fiche user avec breakdown d'héritage.
- **5.1c** — quota par groupe éditable depuis `/app/users/groups/[id]`, scaffold `/admin/settings` (onglet Quotas & FS), toast over-quota au login via `WithToasts`.
- **5.1d** — `default_itinerant` override si `User::isExternal()`, commande `trash:purge`, commande `quota:seed-from-legacy`.

Empiler ces évolutions **par-dessus** du code FS dispersé dans `UserService` + `QuotaService` racine rendrait ces stories inutilement intriquées (grosses classes fourre-tout, dépendances croisées). 5.1a sort le code dans une structure cible stable (`Services/Filesystem/`) documentée en architecture (cf. [architecture.md:447](../planning-artifacts/architecture.md) — `Filesystem/ # À créer — HomeDirService, XfsQuotaService, AclService`).

### Investigation 2026-04-22 (état réel du code)

**Inventaire des méthodes home dans `UserService.php` (2513 lignes) :**

| Méthode | Visibilité | Lignes réelles | Appelée par |
|---|---|---|---|
| `createHomeDirectory(string $login): void` | `public` | **1568-1627** | `UserService::postCreationOperations()` l. **1266** + tests `UserServiceCreateTest` |
| `archiveHomeDirectory(string $login): bool` | `private` | **2106-2138** | `UserService::disableUser()` l. **1927** |
| `restoreHomeDirectory(string $login): bool` | `private` | **2143-2166** | `UserService::enableUser()` l. **2002** |
| `deleteHomeDirectoryPermanently(string $login): bool` | `private` | **2172-2194** | `UserService::deleteUserPermanently()` l. **2065** |
| `hasArchivedHome(string $login): bool` | `private` | **2199-2205** | `UserService::enableUser()` l. **2001** |

> Bloc groupé sous le commentaire `// ============================================ GESTION FILESYSTEM HOME DIRECTORY (private) // ============================================` (l. 2099-2101). Méthode publique `createHomeDirectory` historiquement placée **avant** ce bloc (l. 1568) — le refactor consolide les 5 méthodes dans `HomeDirService`.
>
> Toutes les méthodes utilisent `exec(sudo …)` avec `escapeshellarg` + regex login `/^[a-zA-Z0-9._-]+$/` comme garde anti-injection. Dépendances : `Illuminate\Support\Facades\Log` uniquement (pas de repo, pas de config).

**Fichier `QuotaService.php` (737 lignes — à déplacer intégralement) :**

- Namespace actuel : `App\Services\QuotaService`.
- Dépendances : `App\Models\QuotaRule`, `App\Models\QuotaAuditLog`, `App\Models\QuotaSetting`, `App\Jobs\ApplyQuotaJob`, `Illuminate\Support\Facades\Cache`, `Illuminate\Support\Facades\Log`, `Illuminate\Support\Collection`.
- Fonctions legacy globales utilisées (via `function_exists()`) : `get_config()`, `search_user()`, `search_people_group()` (chargées par `loadLegacyConfig()` depuis `includes/config.inc.php`).
- Constantes internes : `CACHE_PREFIX = 'quota_'`, `CACHE_TTL = 300`.
- Cache Laravel `Cache::remember(...)` dans `getDiskUsage` (l. 163) et `getPartitionInfo` (l. 253). `Cache::forget` dans `setGracePeriod` (l. 482) et `invalidateCache` (l. 629).

**Cache 5 min — à supprimer dans 5.1a (AC 2) :**

| Usage | Emplacement | Comportement cible |
|---|---|---|
| `Cache::remember(key, CACHE_TTL, …)` dans `getDiskUsage` | `QuotaService.php:161-169` → `XfsQuotaService` | Lecture directe XFS (plus de cache). Le coût par appel est acceptable pour la durée de 5.1a → 5.1b ; le snapshot BDD quotidien de 5.1b remplacera définitivement l'optimisation. |
| `Cache::remember(key, CACHE_TTL, …)` dans `getPartitionInfo` | `QuotaService.php:251-276` → `XfsQuotaService` | Idem : lecture directe XFS. |
| `Cache::forget(key)` dans `setGracePeriod` | `QuotaService.php:482` | Devient inutile (aucun cache à invalider) → ligne supprimée. |
| `Cache::forget(key)` dans `invalidateCache` | `QuotaService.php:626-632` | La méthode privée `invalidateCache()` devient no-op et peut être supprimée complètement, ou laissée vide en attendant 5.1b (décision du dev : préférer la suppression nette + nettoyage des appels). |
| Constantes `CACHE_PREFIX` + `CACHE_TTL` | `QuotaService.php:24-25` | Supprimées. Import `Illuminate\Support\Facades\Cache` retiré si plus aucun usage. |

**Commande `RefreshQuotaCacheCommand` — à supprimer (AC 2) :**

- Fichier : `app/Console/Commands/RefreshQuotaCacheCommand.php` (57 lignes).
- Signature : `quota:refresh-cache {--partition=}`.
- Planification : `app/Console/Kernel.php:31-34` — `$schedule->command('quota:refresh-cache')->everyFiveMinutes()->withoutOverlapping()->runInBackground();`.
- Aucun test de non-régression ne vérifie cette planification (`KernelScheduleTest.php` ne teste que `users:sync-from-ad`, `user-groups:sync-from-ad`, `parc:execute-group-schedules`, `parc:prune-group-schedule-runs`) — la suppression est donc sans risque côté tests.

### Cartographie des appelants (grep exhaustif sur `app/`, `tests/`, `routes/`, `resources/`, `config/`)

Fichiers référençant `QuotaService` (tous à mettre à jour) :

| Fichier | Usage | Action refactor |
|---|---|---|
| `app/Services/QuotaService.php` | Classe à déplacer | → `app/Services/Filesystem/XfsQuotaService.php` |
| `app/Jobs/ApplyQuotaJob.php` | `use App\Services\QuotaService;` + DI dans `handle(QuotaService $quotaService)` (l. 38) | Remplacer par `XfsQuotaService` (import + typehint) |
| `app/Http/Controllers/QuotaController.php` | `use App\Services\QuotaService;` + DI constructeur (l. 16) | Remplacer par `XfsQuotaService` |
| `app/Services/Wallpaper/WallpaperResolver.php` | `use … QuotaService;` + DI optionnelle `?QuotaService $quotaService` (l. 41) | Remplacer par `?XfsQuotaService` |
| `app/Services/Wallpaper/WallpaperComposer.php` | `use … QuotaService;` + DI optionnelle (l. 35) | Remplacer par `?XfsQuotaService` |
| `app/Console/Commands/WallpaperPreviewCommand.php` | `Mockery::mock(QuotaService::class)` (l. 60, 65) + `use` (l. 9) | Remplacer par `XfsQuotaService` |
| `app/Console/Commands/RefreshQuotaCacheCommand.php` | Toute la commande | **Supprimer** (fichier + entrée Kernel) |
| `app/Console/Kernel.php` | `$schedule->command('quota:refresh-cache')…` (l. 31-34) | **Supprimer** ce bloc (lignes 30-34 incluant le commentaire "Rafraîchissement du cache…") |
| `resources/views/pages/users/_partials/quotas-tab.blade.php` | SFC Livewire — `use App\Services\QuotaService;` (l. 7), propriété `private QuotaService $quotaService;` (l. 12), `public function boot(QuotaService $quotaService)` (l. 39) | Remplacer `QuotaService` par `XfsQuotaService` (import + typehint + propriété) |
| `resources/views/pages/users/[login]/_partials/quota-info.blade.php` | Blade pur — `use App\Services\QuotaService;` (l. 2), `app(QuotaService::class)` (l. 5) | Remplacer par `XfsQuotaService` |
| `resources/views/components/quotas/group-quota-management.blade.php` | Blade pur — `use App\Services\QuotaService;` (l. 2), `app(QuotaService::class)` (l. 5) | Remplacer par `XfsQuotaService` |
| `tests/Unit/Services/Wallpaper/WallpaperResolverTest.php` | `use App\Services\QuotaService;` (l. 13), `Mockery::mock(QuotaService::class)` (l. 388), typehint `?QuotaService` (l. 100) | Remplacer par `XfsQuotaService` |
| `tests/Unit/Services/Wallpaper/WallpaperComposerTest.php` | `use App\Services\QuotaService;` (l. 9), mocks (l. 88, 176), typehint (l. 81, 86) | Remplacer par `XfsQuotaService` |

Fichiers référençant les méthodes home de `UserService` :

| Fichier | Usage | Action refactor |
|---|---|---|
| `app/Services/UserService.php` l. **1266** (`postCreationOperations`) | `$this->createHomeDirectory($login);` | Remplacer par `$this->homeDirService->createHomeDirectory($login);` après ajout DI |
| `app/Services/UserService.php` l. **1927** (`disableUser`) | `$this->archiveHomeDirectory($login);` | Remplacer par `$this->homeDirService->archiveHomeDirectory($login);` |
| `app/Services/UserService.php` l. **2001** (`enableUser`) | `if ($this->hasArchivedHome($login))` | Remplacer par `$this->homeDirService->hasArchivedHome($login)` |
| `app/Services/UserService.php` l. **2002** (`enableUser`) | `$this->restoreHomeDirectory($login);` | Remplacer par `$this->homeDirService->restoreHomeDirectory($login);` |
| `app/Services/UserService.php` l. **2065** (`deleteUserPermanently`) | `$this->deleteHomeDirectoryPermanently($login);` | Remplacer par `$this->homeDirService->deleteHomeDirectoryPermanently($login);` |
| `tests/Unit/Services/UserServiceCreateTest.php` l. **75, 88** | `$this->service->createHomeDirectory(…)` (tests validation injection) | Option simple : basculer sur `HomeDirService` direct instancié dans `setUp()` (PREFERRED — aligne le SUT). Option fallback : garder un wrapper `UserService::createHomeDirectory()` qui délègue (déconseillé — contre l'objectif du refactor). |
| `tests/Unit/Services/UserServiceCreateTest.php` l. **270-281** (`skel_copy_uses_dot_not_glob_star`) | `file_get_contents(app_path('Services/UserService.php'))` + `assertStringContainsString("escapeshellarg(\$skelPath) . \"/. \"")` | Remplacer le chemin source par `app_path('Services/Filesystem/HomeDirService.php')`. La chaîne assertée reste identique car le code est simplement déplacé. |

### Décision clé à acter par le dev : alias `QuotaService` vs renommage global

L'instruction produit 5.1 (epics.md) laisse le choix : « Garder `QuotaService` racine en alias/facade si trop d'appelants, sinon renommer les usages ».

**Audit de la décision — 9 fichiers applicatifs + 3 tests référencent `QuotaService` (liste ci-dessus). Le volume est raisonnable.**

**Recommandation SM (à valider par le dev) — renommage global (pas d'alias).**

Raisons :

1. **Volume limité** : 12 fichiers seulement, tous identifiés par grep exhaustif.
2. **Clarté sémantique** : `XfsQuotaService` nomme le service par son implémentation réelle (XFS user quotas). Garder le nom `QuotaService` comme alias perpétue une ambiguïté : d'autres backends quota (project quotas XFS, ZFS, dovecot…) pourraient apparaître plus tard.
3. **Pas de dette de migration** : un alias `class_alias('App\Services\Filesystem\XfsQuotaService', 'App\Services\QuotaService')` ferait survivre des imports obsolètes dans les reviews de code et les stories 5.1b/c/d.
4. **IDE & refactoring tools** : le rename est mécanique et trivial avec un IDE ou un `grep -rln | xargs sed -i` bien ciblé.

**Si le dev identifie un couplage imprévu pendant l'exécution** (extension via package, binding custom indirect, façade Laravel custom) : il peut revenir sur la décision et introduire un alias **temporaire** avec un TODO explicite et un ticket de suppression pour 5.1d. Documenter ce choix dans les Dev Notes.

### Couplages et points d'attention (découverts pendant l'audit)

1. **`QuotaService` utilise des fonctions legacy globales** (`get_config`, `search_user`, `search_people_group`) chargées depuis `sambaedu/includes/config.inc.php` via `loadLegacyConfig()` (l. 37-47). Ce couplage est préservé **à l'identique** en 5.1a — il sera éventuellement ré-encapsulé en 5.1d mais pas ici.
2. **Aucun ServiceProvider ne binde `QuotaService`** — résolution par auto-discovery Laravel (réflexion sur le constructeur sans argument). Le nouveau `XfsQuotaService` fonctionnera identiquement sans binding explicite (le constructeur reste sans argument obligatoire). *Rappel : si un binding est ajouté dans un ServiceProvider pendant l'audit, le déplacer (cf. `AppServiceProvider::register()` l. 63-117, actuellement aucun binding quota).*
3. **`WallpaperResolver` / `WallpaperComposer` injectent `QuotaService` en paramètre optionnel `?QuotaService`** — la nullabilité doit être préservée (`?XfsQuotaService`). C'est un vrai choix d'architecture (Story 4.7 AC 5 ter : composer peut fonctionner sans quota).
4. **`ApplyQuotaJob` est un job queué** (`ShouldQueue`, queue `quotas`, `tries=3`, `backoff=10`). Il reçoit `XfsQuotaService` via `handle()` (service injection) — pas via `__construct()`, donc **aucun job sérialisé en queue ne contient le FQN** et il n'y a pas de risque de jobs pendants avec l'ancien namespace. ✅
5. **Méthodes `getUserQuota()` / `isQuotaExceeded()` dans `UserService`** (l. 451-468) — **stubs TODO** indépendants qui renvoient des valeurs aléatoires (`rand(1000, 4500)`). **Hors scope 5.1a** : elles ne consomment ni `QuotaService` ni les méthodes home ; ne pas les toucher.
6. **Test `skel_copy_uses_dot_not_glob_star`** (`UserServiceCreateTest.php:270-281`) charge physiquement `Services/UserService.php` en texte et assert une chaîne ; il faut **impérativement** mettre à jour le chemin vers `Services/Filesystem/HomeDirService.php`. Ce test sert de garde anti-régression (dotfiles du skel) : sa chaîne cible (`"escapeshellarg(\$skelPath) . \"/. \""`) doit rester présente dans le code déplacé.
7. **Pas de façade Laravel / alias dans `config/app.php`** pour `QuotaService` — aucun `'Quota' => QuotaService::class` n'existe. ✅ Pas de façade à renommer.
8. **Pas de référence `QuotaService` dans `routes/web.php`** (seul `QuotaController` est référencé, ligne 81 et 84 — et le contrôleur importera le nouveau service). ✅
9. **`QuotaAuditLog` et `QuotaRule` et `QuotaSetting`** restent dans `App\Models\*` sans renommage. Le préfixe `Quota*` des modèles est cohérent avec le domaine (les modèles sont indépendants du namespace du service). ✅
10. **`QuotaController`** (`app/Http/Controllers/QuotaController.php`) n'est PAS renommé (pas demandé par la story et pas dans l'architecture cible — le contrôleur Laravel reste dans `Http/Controllers/`, c'est le service qui migre). ✅

---

## Acceptance Criteria

**AC 1 — Extraction `HomeDirService`**

**Given** les 5 méthodes home (`createHomeDirectory`, `archiveHomeDirectory`, `restoreHomeDirectory`, `deleteHomeDirectoryPermanently`, `hasArchivedHome`) situées dans `app/Services/UserService.php` aux lignes citées dans le contexte
**When** la story 5.1a est livrée
**Then** le fichier `app/Services/Filesystem/HomeDirService.php` existe avec le namespace `App\Services\Filesystem`
**And** les 5 méthodes y sont déplacées **à iso-comportement** (regex login, `escapeshellarg`, `exec sudo`, logs `Log::error`/`Log::warning`/`Log::info` inchangés)
**And** les 4 méthodes historiquement `private` (archive/restore/deletePermanently/hasArchivedHome) deviennent `public` pour être consommables via DI depuis `UserService`
**And** `UserService` ne contient plus aucune de ces 5 méthodes ni leurs commentaires d'en-tête (`// === GESTION FILESYSTEM HOME DIRECTORY ===`)
**And** `UserService` injecte `HomeDirService` via son constructeur et route les 5 appels internes vers `$this->homeDirService->…`

**AC 2 — Extraction `XfsQuotaService` et suppression du cache 5 min**

**Given** le fichier `app/Services/QuotaService.php` (737 lignes) et le cache Laravel 5 min utilisé par `getDiskUsage` et `getPartitionInfo`
**When** la story 5.1a est livrée
**Then** le fichier `app/Services/Filesystem/XfsQuotaService.php` existe avec le namespace `App\Services\Filesystem`
**And** tout le contenu de `QuotaService` y est déplacé **à iso-comportement** excepté les points ci-dessous
**And** les appels `Cache::remember(…)` de `getDiskUsage` (l. 161-169) et `getPartitionInfo` (l. 251-276) sont supprimés — les méthodes retournent directement le résultat de leur corps lambda
**And** les constantes `CACHE_PREFIX` et `CACHE_TTL` sont supprimées
**And** les appels `Cache::forget(…)` de `setGracePeriod` (l. 482) et `invalidateCache` (l. 626-632) sont supprimés ; la méthode `invalidateCache()` est supprimée et ses 3 appelants internes (`setQuotaRule` l. 343, `deleteQuotaRule` l. 371) sont nettoyés
**And** l'import `use Illuminate\Support\Facades\Cache;` est retiré du nouveau fichier
**And** le fichier `app/Services/QuotaService.php` est **supprimé** (cf. AC 4 pour la décision alias vs renommage — la recommandation SM est la suppression + renommage global)

**AC 3 — Suppression de `RefreshQuotaCacheCommand` et de sa planification**

**Given** la commande `quota:refresh-cache` planifiée toutes les 5 minutes dans `Console/Kernel.php`
**When** la story 5.1a est livrée
**Then** le fichier `app/Console/Commands/RefreshQuotaCacheCommand.php` est supprimé
**And** le bloc `$schedule->command('quota:refresh-cache')->everyFiveMinutes()…` dans `app/Console/Kernel.php` (l. 30-34, commentaire inclus) est supprimé
**And** `php artisan schedule:list` n'affiche plus `quota:refresh-cache`
**And** `php artisan list quota` n'affiche plus `quota:refresh-cache`

**AC 4 — Mise à jour de tous les appelants (DI + imports)**

**Given** l'audit exhaustif qui a identifié 9 fichiers applicatifs + 3 fichiers tests référençant `App\Services\QuotaService`
**When** la story 5.1a est livrée
**Then** `ApplyQuotaJob`, `QuotaController`, `WallpaperResolver`, `WallpaperComposer`, `WallpaperPreviewCommand`, les 3 fichiers Blade (`quotas-tab.blade.php`, `quota-info.blade.php`, `group-quota-management.blade.php`), et les 2 tests Wallpaper importent désormais `App\Services\Filesystem\XfsQuotaService` à la place de `App\Services\QuotaService`
**And** aucune référence textuelle résiduelle à `App\Services\QuotaService` (hors éventuel alias explicitement documenté) n'est détectable par `grep -rn "App\\\\Services\\\\QuotaService\|use App\\\\Services\\\\QuotaService" app tests routes resources config`
**And** les typehints nullables `?QuotaService` dans `WallpaperResolver`/`WallpaperComposer` sont préservés en `?XfsQuotaService`
**And** si le dev choisit de conserver un alias de compatibilité (voir Tâches section « Décision alias »), celui-ci est documenté dans les Dev Notes et un ticket de suppression est référencé dans le backlog 5.1d

**AC 5 — Mise à jour des tests existants (sans modification fonctionnelle)**

**Given** les tests existants couvrant les méthodes home et `QuotaService`
**When** la story 5.1a est livrée
**Then** `tests/Unit/Services/UserServiceCreateTest.php` instancie un `HomeDirService` pour tester `createHomeDirectory`, `createHomeDirectory_rejects_invalid_login_with_special_chars`, `createHomeDirectory_rejects_login_with_path_traversal`, `createHomeDirectory_accepts_valid_login_formats` (appels `$this->homeDirService->createHomeDirectory(…)` ou adaptation équivalente)
**And** le test `skel_copy_uses_dot_not_glob_star` charge `app_path('Services/Filesystem/HomeDirService.php')` au lieu de `Services/UserService.php` et valide la même chaîne `escapeshellarg(\$skelPath) . "/. "`
**And** `tests/Unit/Services/Wallpaper/WallpaperResolverTest.php` et `WallpaperComposerTest.php` mockent `XfsQuotaService::class` au lieu de `QuotaService::class` (imports + `Mockery::mock`)
**And** `vendor/bin/phpunit` exécuté à la racine du projet passe **sans régression** : les tests existants doivent rester verts (nombre de tests verts ≥ baseline actuelle, 0 test nouvellement rouge)
**And** aucun nouveau test fonctionnel n'est ajouté par cette story (conformément au scope refactor à iso-comportement)

**AC 6 — Gestion d'erreurs préservée (pas de comportement silencieux)**

**Given** une méthode de `HomeDirService` ou `XfsQuotaService` qui échoue (login invalide, répertoire manquant, `sudo` refusé, commande `xfs_quota` en erreur…)
**When** l'erreur survient
**Then** elle est loggée avec le même contexte que dans le code actuel (`login`, `path`, `partition`, `output` de la commande selon le cas) via `Log::error` / `Log::warning` / `Log::info` — **aucun changement de niveau ni de format**
**And** la valeur de retour (`bool`, `array{success, error}`, `void`) est identique à l'actuelle — aucun nouvel `throw` introduit, aucun `catch` retiré
**And** les gardes regex de login (`/^[a-zA-Z0-9._-]+$/`) sont préservées dans chaque méthode déplacée de `HomeDirService`

**AC 7 — Architecture conforme au mapping `architecture.md`**

**Given** l'architecture cible documentée dans `_bmad-output/planning-artifacts/architecture.md:447` qui prévoit `Filesystem/ # À créer — HomeDirService, XfsQuotaService, AclService`
**When** la story 5.1a est livrée
**Then** l'arborescence `app/Services/Filesystem/` existe et contient exactement 2 fichiers : `HomeDirService.php` et `XfsQuotaService.php`
**And** aucun service racine `app/Services/QuotaService.php` ne subsiste (sauf alias temporaire documenté — cf. AC 4)
**And** aucune régression sur `FileManagerService`, `ImageManagerService` et autres services racine non concernés

---

## Tasks / Subtasks

### Phase 1 — Audit & préparation (vérification avant de modifier)

- [x] **Tâche 1.1** — Refaire le grep exhaustif juste avant de commencer (le code peut avoir bougé entre le moment où la story est créée et le moment où le dev démarre) (AC 4)
  - [x] `grep -rn "App\\\\Services\\\\QuotaService\|use App\\\\Services\\\\QuotaService" app tests routes resources config` → résultats conformes au tableau du Contexte, aucun fichier supplémentaire découvert
  - [x] `grep -rn "createHomeDirectory\|archiveHomeDirectory\|restoreHomeDirectory\|deleteHomeDirectoryPermanently\|hasArchivedHome" app tests routes resources config` → idem
  - [x] `grep -rn "quota:refresh-cache\|RefreshQuotaCache" app tests routes resources config` → idem
- [x] **Tâche 1.2** — Baseline : **44 tests verts** (44/44, 120 assertions, suite ciblée), **887 verts** (suite complète — 1 incomplete, 56 skipped inchangés)
- [x] **Tâche 1.3** — **Décision : renommage global** (recommandation SM validée). Aucun couplage imprévu trouvé. Pas d'alias.

### Phase 2 — Création de `HomeDirService`

- [x] **Tâche 2.1** — Créé `app/Services/Filesystem/HomeDirService.php` avec namespace `App\Services\Filesystem`, import `Illuminate\Support\Facades\Log`, docblock de classe (AC 1)
- [x] **Tâche 2.2** — Déplacé `createHomeDirectory(string $login): void` — corps identique (AC 1, AC 6)
- [x] **Tâche 2.3** — Déplacé `archiveHomeDirectory(string $login): bool` `private` → `public` (AC 1)
- [x] **Tâche 2.4** — Déplacé `restoreHomeDirectory(string $login): bool` `private` → `public` (AC 1)
- [x] **Tâche 2.5** — Déplacé `deleteHomeDirectoryPermanently(string $login): bool` `private` → `public` (AC 1)
- [x] **Tâche 2.6** — Déplacé `hasArchivedHome(string $login): bool` `private` → `public` (AC 1)
- [x] **Tâche 2.7** — Ajouté `private HomeDirService $homeDirService = new HomeDirService()` dans le constructeur de `UserService` (paramètre promu avec valeur par défaut — PHP 8.2 supporté) (AC 1)
- [x] **Tâche 2.8** — Remplacé les 5 appels internes dans `UserService` :
  - [x] `postCreationOperations` : `$this->homeDirService->createHomeDirectory($login)`
  - [x] `disableUser` : `$this->homeDirService->archiveHomeDirectory($login)`
  - [x] `enableUser` : `$this->homeDirService->hasArchivedHome($login)`
  - [x] `enableUser` : `$this->homeDirService->restoreHomeDirectory($login)`
  - [x] `deleteUserPermanently` : `$this->homeDirService->deleteHomeDirectoryPermanently($login)`
- [x] **Tâche 2.9** — Supprimé le bloc commentaire `// === GESTION FILESYSTEM HOME DIRECTORY (private) ===` et toutes les 5 méthodes de `UserService`
- [x] **Tâche 2.10** — Aucun import inutile à retirer dans `UserService` (tout est déjà utilisé ailleurs)

### Phase 3 — Création de `XfsQuotaService`

- [x] **Tâche 3.1** — Créé `app/Services/Filesystem/XfsQuotaService.php` avec namespace `App\Services\Filesystem`, imports corrects — sans `Illuminate\Support\Facades\Cache` (AC 2)
- [x] **Tâche 3.2** — Contenu de `QuotaService` copié et adapté :
  - [x] Classe renommée `XfsQuotaService`
  - [x] Constantes `CACHE_PREFIX` et `CACHE_TTL` supprimées
  - [x] `getDiskUsage()` : `Cache::remember(...)` supprimé, retour direct
  - [x] `getPartitionInfo()` : `Cache::remember(...)` supprimé, exécution directe
  - [x] `setGracePeriod()` : `Cache::forget(...)` supprimé
  - [x] `setQuotaRule()` : `$this->invalidateCache($target)` supprimé
  - [x] `deleteQuotaRule()` : `$this->invalidateCache($rule->target)` supprimé
  - [x] Méthode `invalidateCache()` supprimée dans son intégralité
- [x] **Tâche 3.3** — Préfixe de log `QuotaService:` conservé tel quel (décision SM validée)

### Phase 4 — Mise à jour des appelants applicatifs

- [x] **Tâche 4.1** — `app/Jobs/ApplyQuotaJob.php` : import + typehint `handle()` mis à jour
- [x] **Tâche 4.2** — `app/Http/Controllers/QuotaController.php` : import + constructeur mis à jour
- [x] **Tâche 4.3** — `app/Services/Wallpaper/WallpaperResolver.php` : import + `?XfsQuotaService` constructeur
- [x] **Tâche 4.4** — `app/Services/Wallpaper/WallpaperComposer.php` : import + `?XfsQuotaService` constructeur
- [x] **Tâche 4.5** — `app/Console/Commands/WallpaperPreviewCommand.php` : import + 2 `Mockery::mock(XfsQuotaService::class)`
- [x] **Tâche 4.6** — `resources/views/pages/users/_partials/quotas-tab.blade.php` : import + propriété + typehint `boot`
- [x] **Tâche 4.7** — `resources/views/pages/users/[login]/_partials/quota-info.blade.php` : import + `app(XfsQuotaService::class)`
- [x] **Tâche 4.8** — `resources/views/components/quotas/group-quota-management.blade.php` : import + `app(XfsQuotaService::class)`
- [x] **Tâche 4.9** — Grep final : 0 référence résiduelle à `App\Services\QuotaService` (sauf une ligne de commentaire dans `XfsQuotaService.php` — docblock historique non-fonctionnel)

### Phase 5 — Suppression commande + planification cache

- [x] **Tâche 5.1** — `app/Console/Commands/RefreshQuotaCacheCommand.php` supprimé via `gio trash` (AC 3)
- [x] **Tâche 5.2** — Bloc `quota:refresh-cache` supprimé dans `app/Console/Kernel.php` (lignes commentaire + schedule) (AC 3)
- [x] **Tâche 5.3** — Grep confirme 0 référence résiduelle à `quota:refresh-cache` (AC 3)

### Phase 6 — Suppression `QuotaService.php` + éventuel alias

- [x] **Tâche 6.1** — `app/Services/QuotaService.php` supprimé via `gio trash` (renommage global — décision SM validée) (AC 2, AC 7)
- [ ] **Tâche 6.2** — N/A (alias non choisi)

### Phase 7 — Mise à jour des tests

- [x] **Tâche 7.1** — `tests/Unit/Services/UserServiceCreateTest.php` : (AC 5)
  - [x] Option A appliquée : `$this->homeDirService = new HomeDirService()` dans `setUp()` + `HomeDirService` injecté dans constructeur `UserService` ; 3 tests `createHomeDirectory_*` appellent `$this->homeDirService->createHomeDirectory(...)`
  - [x] `skel_copy_uses_dot_not_glob_star` : chemin mis à jour vers `Services/Filesystem/HomeDirService.php`
- [x] **Tâche 7.2** — `tests/Unit/Services/Wallpaper/WallpaperResolverTest.php` : import + typehint + mock mis à jour (`XfsQuotaService`)
- [x] **Tâche 7.3** — `tests/Unit/Services/Wallpaper/WallpaperComposerTest.php` : import + 2 typehints + 2 mocks mis à jour (`XfsQuotaService`)
- [x] **Tâche 7.4** — **44/44 tests verts** (suite ciblée), **887/887 verts** (suite complète) — identique à la baseline. 0 régression.

### Phase 8 — Validation finale

- [x] **Tâche 8.1** — Re-grep final de non-régression :
  - [x] `App\Services\QuotaService` : 0 résultat fonctionnel (1 ligne de commentaire docblock dans `XfsQuotaService` — non-fonctionnel)
  - [x] `RefreshQuotaCache|quota:refresh-cache` : 0 résultat
  - [x] Méthodes home dans `UserService.php` : exactement 5 appels de délégation vers `$this->homeDirService->…`
- [x] **Tâche 8.2** — Routes vérifiées via `php artisan route:list | grep -i quota` (2 routes `QuotaController` présentes) ; smoke test VM non réalisé (hors scope tests unitaires — à vérifier manuellement en review)
- [x] **Tâche 8.3** — `app/Services/QuotaService.php` absent, `app/Console/Commands/RefreshQuotaCacheCommand.php` absent ; `app/Services/Filesystem/HomeDirService.php` et `app/Services/Filesystem/XfsQuotaService.php` présents
- [x] **Tâche 8.4** — Dev Notes finales rédigées ci-dessous

---

## Fichiers concernés

### Fichiers créés

- `app/Services/Filesystem/HomeDirService.php` *(nouveau — ~100 lignes, agrège les 5 méthodes home)*
- `app/Services/Filesystem/XfsQuotaService.php` *(nouveau — ~690 lignes après suppression du cache, copie de `QuotaService`)*

### Fichiers modifiés

- `app/Services/UserService.php` — retrait des 5 méthodes home (-~140 lignes) + injection DI `HomeDirService` + mise à jour 5 call sites
- `app/Jobs/ApplyQuotaJob.php` — import + typehint
- `app/Http/Controllers/QuotaController.php` — import + typehint constructeur
- `app/Services/Wallpaper/WallpaperResolver.php` — import + typehint constructeur
- `app/Services/Wallpaper/WallpaperComposer.php` — import + typehint constructeur
- `app/Console/Commands/WallpaperPreviewCommand.php` — import + 2 `Mockery::mock`
- `app/Console/Kernel.php` — suppression bloc planification `quota:refresh-cache` (l. 30-34)
- `resources/views/pages/users/_partials/quotas-tab.blade.php` — import + propriété + typehint `boot`
- `resources/views/pages/users/[login]/_partials/quota-info.blade.php` — import + `app(…)` resolve
- `resources/views/components/quotas/group-quota-management.blade.php` — import + `app(…)` resolve
- `tests/Unit/Services/UserServiceCreateTest.php` — tests `createHomeDirectory_*` + `skel_copy_uses_dot_not_glob_star`
- `tests/Unit/Services/Wallpaper/WallpaperResolverTest.php` — import + mocks
- `tests/Unit/Services/Wallpaper/WallpaperComposerTest.php` — import + mocks

### Fichiers supprimés

- `app/Services/QuotaService.php` *(contenu déplacé intégralement vers `XfsQuotaService`)*
- `app/Console/Commands/RefreshQuotaCacheCommand.php` *(plus nécessaire sans cache 5 min)*

### Fichiers NON touchés (vérification explicite)

- `app/Models/QuotaRule.php`, `app/Models/QuotaAuditLog.php`, `app/Models/QuotaSetting.php` *(modèles indépendants du service)*
- `app/Http/Controllers/QuotaController.php` *(le contrôleur conserve son nom ; seule la dépendance change)*
- `routes/web.php` *(les 2 routes `/users/groups/{groupCn}/quota` + `/users/{login}/quota` pointent sur `QuotaController` dont la classe n'est pas renommée)*
- `config/app.php` *(pas de façade `Quota` enregistrée — vérifié via grep)*
- `app/Providers/*` *(aucun binding `QuotaService` — auto-discovery Laravel)*
- Tous les autres tests ne référençant pas `QuotaService` ni les méthodes home (~2 500 tests)

---

## Dev Notes

### Patterns et conventions à suivre

- **Convention de namespace Services** : suivre le pattern des autres sous-dossiers existants (`App\Services\Parc\MachinePowerService`, `App\Services\AdSync\AdSyncService`, `App\Services\Wallpaper\WallpaperResolver`) — la classe racine est déclarée dans le namespace sous-domaine correspondant. Pas de `namespace App\Services\Filesystem;` dans `HomeDirService.php` et `XfsQuotaService.php`.
- **Logs** : préserver le wording historique `Log::…('QuotaService: …')` et les clés de contexte (`login`, `partition`, `output`, `error`) — c'est ce que les opérateurs VM grep dans `/var/log/`. Alternative future (5.1d) : renommer en `Filesystem/XfsQuotaService:` si jugé utile, mais hors scope 5.1a.
- **Visibilité** : passer les méthodes home de `private` à `public` est nécessaire pour l'injection via `HomeDirService`. C'est un élargissement délibéré : `HomeDirService` est typé et injecté, il n'y a pas de risque de fuite.
- **DI Laravel** : `HomeDirService` et `XfsQuotaService` ont des constructeurs sans argument obligatoire (ou seulement `Log` résolu par façade) → aucun binding ServiceProvider nécessaire (auto-discovery via réflexion).
- **Blade SFC Livewire** (`quotas-tab.blade.php`) : l'injection via `public function boot(XfsQuotaService $quotaService)` Livewire fonctionne en résolvant le service du container — identique au pattern actuel avec `QuotaService`.
- **Sécurité exec** : les gardes `/^[a-zA-Z0-9._-]+$/` et `escapeshellarg()` sont critiques (cf. tests `rejects_invalid_login_with_special_chars` et `rejects_login_with_path_traversal`). Ne **jamais** les supprimer — même un déplacement doit les préserver mot pour mot.

### Référence produit

- **[Scope 5.1a — epics.md:1538-1575](../planning-artifacts/epics.md)** : scope + AC originaux.
- **[Architecture cible — architecture.md:447](../planning-artifacts/architecture.md)** : `Filesystem/ # À créer — HomeDirService, XfsQuotaService, AclService`.
- **[FR13-16 — prd.md:305-308](../planning-artifacts/prd.md)** : home dirs + quotas XFS + partages ACLs + suppression en 2 temps (contexte fonctionnel).

### Project Structure Notes

- L'arborescence `app/Services/Filesystem/` est créée ex nihilo. Elle anticipe l'ajout de `AclService` (Story 5.2) et potentiellement d'autres services FS (Story 5.1d `trash:purge`).
- Les `Models/Quota*` restent au namespace racine `App\Models` — pas de déplacement vers `App\Models\Filesystem` (non demandé, coût de refactor disproportionné).
- Le contrôleur `QuotaController` reste dans `Http/Controllers/` — cohérent avec le reste des contrôleurs du projet (pas de sous-namespace `Http\Controllers\Filesystem\`).

### Points d'attention / risques

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| **Imports oubliés** dans les 9 fichiers applicatifs | Moyenne | Fatal error au runtime | Grep final Tâche 8.1 + suite de tests verte Tâche 7.4 |
| **Visibilité `private` → `public`** casse un appel interne inattendu | Faible | Aucun (élargissement) | N/A — les 4 méthodes n'étaient appelées que depuis `UserService`, le grep l'a confirmé |
| **Alias non documenté** laissé en place | Moyenne (si dev choisit option B) | Dette technique silencieuse | Forcer décision explicite en Tâche 1.3 + Dev Notes |
| **Tests Wallpaper mocks** : `Mockery::mock(QuotaService::class)` échoue silencieusement si l'alias n'est plus résolu | Moyenne | Test skipped, pas rouge | Vérification Tâche 8.1 + compter les tests verts avant/après (Tâche 1.2 vs 7.4) |
| **Cache Laravel persistant** : entrées `quota_usage_*` / `quota_partition_info_*` créées avant le refactor peuvent traîner dans Redis/file | Faible | Aucun (pas lu) | Post-déploiement VM : `php artisan cache:clear` (documenté dans Dev Notes finales) |
| **Job sérialisé en queue** avec ancien FQN `App\Services\QuotaService` | Très faible | Job replay en erreur | Non concerné — `ApplyQuotaJob` ne sérialise pas le service (injection via `handle()`) ✅ |
| **Test `skel_copy_uses_dot_not_glob_star`** qui charge un fichier par son chemin | Haute si oublié | Test rouge | Tâche 7.1 explicite |
| **Blade `quotas-tab` SFC Livewire** : `boot(QuotaService …)` doit devenir `boot(XfsQuotaService …)` | Haute si oublié | Erreur DI runtime (service not found) | Tâche 4.6 explicite |
| **Route `/users/[login]/_partials/quota-info.blade.php`** utilisée dans la fiche user : smoke test VM après deploy (Tâche 8.2) | Moyenne | Page user 500 | Tâche 8.2 explicite |

### Testing Strategy

**Stratégie : non-régression pure.** Aucun nouveau test fonctionnel n'est ajouté par 5.1a — le scope est un refactor à iso-comportement.

- **Baseline** : capturer le nombre total de tests verts avant de commencer (Tâche 1.2). Cible : inchangé ou +1/+2 si les tests adaptés révèlent un cas marginal qui passait fortuitement.
- **Tests mis à jour** : uniquement par mise à jour mécanique des imports/typehints (Tâches 7.1-7.3). Aucun nouvel assert métier.
- **Couverture déjà garantie** :
  - Injection commandes (`createHomeDirectory_rejects_*`) via `UserServiceCreateTest`
  - Pattern `skel/. ` (dotfiles) via `skel_copy_uses_dot_not_glob_star`
  - Comportement quota over (`isUserOverQuota`, `getOverQuotaPartitionsFormatted`) via `WallpaperResolverTest` + `WallpaperComposerTest` (story 4.7)
- **Test optionnel à ajouter si le dev souhaite sécuriser AC 3** : dans `tests/Feature/Console/KernelScheduleTest.php`, ajouter un assert négatif `it_does_not_schedule_quota_refresh_cache` qui vérifie `! str_contains($event->command, 'quota:refresh-cache')` sur tous les schedules. À évaluer en Tâche 5.3.

### References

- [Source: epics.md#Story-5.1a](../planning-artifacts/epics.md) — scope refactor, AC originaux, note d'implémentation
- [Source: architecture.md#Services/Filesystem](../planning-artifacts/architecture.md) — mapping architectural (l. 447)
- [Source: prd.md#FR13-FR16](../planning-artifacts/prd.md) — Functional Requirements Epic 5
- [Source: idempotency.md#1bis.19-infos](../planning-artifacts/idempotency.md) — mention quota_fixer/quota_visu à traiter plus tard (Epic 2/5)
- [Source: sprint-status.yaml#epic-5](sprint-status.yaml) — state lock Epic 5

---

## Recommandation Modèle Dev

### Choix : **sonnet** (Claude Sonnet 4.6 ou version courante)

### Justification

Ce refactor est **mécanique et à périmètre fermé** :

1. **Scope physiquement délimité** — 2 nouveaux fichiers, 11 fichiers modifiés, 2 supprimés. Tous identifiés par grep exhaustif dans ce fichier.
2. **Pas de logique métier à reconcevoir** — on déplace du code sans modifier son comportement. Zéro nouveau test fonctionnel attendu.
3. **Pas de couplage subtil découvert** — l'audit a levé tous les points d'attention :
   - Pas de façade Laravel
   - Pas de binding ServiceProvider custom
   - Pas de job sérialisé avec ancien FQN (handle() injection)
   - Pas de référence réflexion dynamique (hors test texte `skel_copy_uses_dot_not_glob_star` explicitement documenté)
4. **Patterns déjà établis** dans le repo — les autres sous-namespaces Services (`Parc/`, `AdSync/`, `Wallpaper/`, `SE4/`, `Legacy/`, `Windows/`, `AppCustomization/`, `ControlHub/`, `AppProfile/`, `AppStore/`, `Wallpaper/`) fournissent un modèle de structure directement réutilisable.
5. **Risques bien identifiés et facilement détectables** — un grep final + une suite de tests verte suffisent à valider la non-régression. Pas de raisonnement multi-couches ni de décision d'architecture inédite.
6. **Tâches détaillées quasi-exécutables** — chaque tâche pointe la ligne exacte du fichier cible. Le dev déroule, il ne conçoit pas.

**Alternative opus justifiée uniquement si** le dev découvre pendant la Tâche 1.1 un couplage non identifié (ex. : plugin Composer qui lit `App\Services\QuotaService`, ou un test E2E non inventorié qui instancie par nom, ou un binding custom dans un ServiceProvider d'un package tiers). Dans ce cas, escalader vers opus avec une Dev Note explicite.

Modèle recommandé final : **`sonnet`** (claude-sonnet-4-6 / modèle sonnet actuel).

---

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6 (2026-04-22)

### Debug Log References

Aucun debug log particulier. Toutes les modifications sont mécaniques et ont passé les tests au premier run.

### Completion Notes List

1. **Décision alias vs renommage** : renommage global appliqué (recommandation SM validée). Aucun couplage imprévu (pas de façade, pas de binding ServiceProvider, pas de job sérialisé avec ancien FQN). Une seule ligne de commentaire historique subsiste dans le docblock de `XfsQuotaService.php` — non-fonctionnel, intentionnel pour la traçabilité.

2. **Delta de tests** : baseline 887 verts → post-refactor 887 verts. Suite ciblée : 44/44 → 44/44. 0 régression, 0 test nouvellement rouge. 1 incomplete et 56 skipped inchangés (non liés à cette story).

3. **Préfixe de logs** : préfixe `QuotaService:` conservé tel quel dans `XfsQuotaService` (décision SM — les opérateurs VM greppent les logs historiques). Renommage en `XfsQuotaService:` reporté à 5.1d si jugé utile.

4. **Constructeur `UserService`** : paramètre `HomeDirService` ajouté avec valeur par défaut `new HomeDirService()` (PHP 8.1+ "new in initializer"). Le test `UserServiceCreateTest` injecte explicitement `$this->homeDirService` dans le constructeur — alignement SUT optimal (option A recommandée).

5. **Couplages imprévus** : aucun. L'audit SM était exhaustif. Pas de `class_alias` nécessaire.

6. **Smoke test VM** : routes quota vérifiées via `php artisan route:list`. Le smoke test UI `/app/users/[login]` est à réaliser manuellement en phase de review (hors scope automatisé).

### File List

**Créés :**
- `app/Services/Filesystem/HomeDirService.php` (~180 lignes — 5 méthodes home extraites de `UserService`)
- `app/Services/Filesystem/XfsQuotaService.php` (~480 lignes — contenu de `QuotaService` sans cache 5 min)

**Modifiés :**
- `app/Services/UserService.php` — import `HomeDirService`, DI constructeur, 5 call sites → `$this->homeDirService->…`, suppression des 5 méthodes home et du bloc commentaire (~140 lignes retirées)
- `app/Jobs/ApplyQuotaJob.php` — import + typehint `handle()`
- `app/Http/Controllers/QuotaController.php` — import + typehint constructeur
- `app/Services/Wallpaper/WallpaperResolver.php` — import + `?XfsQuotaService` constructeur
- `app/Services/Wallpaper/WallpaperComposer.php` — import + `?XfsQuotaService` constructeur
- `app/Console/Commands/WallpaperPreviewCommand.php` — import + 2 `Mockery::mock(XfsQuotaService::class)`
- `app/Console/Kernel.php` — suppression bloc planification `quota:refresh-cache`
- `resources/views/pages/users/_partials/quotas-tab.blade.php` — import + propriété + typehint `boot`
- `resources/views/pages/users/[login]/_partials/quota-info.blade.php` — import + `app(XfsQuotaService::class)`
- `resources/views/components/quotas/group-quota-management.blade.php` — import + `app(XfsQuotaService::class)`
- `tests/Unit/Services/UserServiceCreateTest.php` — import `HomeDirService`, `$this->homeDirService` dans setUp + tests + skel_copy
- `tests/Unit/Services/Wallpaper/WallpaperResolverTest.php` — import + typehint + mock `XfsQuotaService`
- `tests/Unit/Services/Wallpaper/WallpaperComposerTest.php` — import + 2 typehints + 2 mocks `XfsQuotaService`

**Supprimés :**
- `app/Services/QuotaService.php` (contenu déplacé vers `XfsQuotaService`)
- `app/Console/Commands/RefreshQuotaCacheCommand.php` (plus nécessaire sans cache 5 min)
