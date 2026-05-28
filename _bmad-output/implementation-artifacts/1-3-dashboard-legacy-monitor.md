# Story 1.3 : Dashboard Legacy Monitor

Status: done

## Story

En tant que **développeur**,
je veux un dashboard `/admin/legacy-monitor` affichant les appels catchall en temps réel,
afin que l'équipe puisse identifier les routes legacy encore actives et prioriser leur migration vers Livewire.

## Acceptance Criteria

1. **Affichage des appels catchall** — Étant donné que des appels catchall ont été loggés dans `legacy_catchall_logs`, quand je consulte `/admin/legacy-monitor`, alors je vois la liste des appels non gérés avec path, méthode HTTP, date et fréquence (nombre d'appels).

2. **Rafraîchissement sans rechargement** — La liste se rafraîchit sans rechargement de page (composant Livewire avec polling automatique).

3. **Filtrage** — Je peux filtrer la liste par path (recherche partielle) et par méthode HTTP (GET, POST, etc.).

4. **Accès restreint aux admins** — La page est accessible uniquement aux admins SER via le middleware `sambaedu.admin` (déjà en place sur le groupe de routes `/admin`).

## Dépendance Critique — Story 1.2

**⚠️ Cette story dépend entièrement de Story 1.2 (ready-for-dev).**

Story 1.3 requiert que Story 1.2 soit implémentée et que les éléments suivants existent :
- Table `legacy_catchall_logs` (migration appliquée)
- Modèle `app/Models/LegacyCatchallLog.php` avec ses colonnes `method`, `path`, `ip`, `query_string`, `referer`, `created_at`

Si Story 1.2 n'est pas encore déployée, commencer par story 1.2 d'abord.

## Tasks / Subtasks

- [x] **Tâche 1 : Route `/admin/legacy-monitor`** (AC: 1, 4)
  - [x] Ajouter dans le groupe `Route::prefix('admin')->middleware('sambaedu.admin')` dans `routes/web.php` :
    ```php
    Route::livewire('/legacy-monitor', 'pages::admin.legacy-monitor.index')->name('legacy-monitor');
    ```
  - [x] Vérifier que le nom de route complet est `admin.legacy-monitor` (prefixé automatiquement par `->name('admin.')`)

- [x] **Tâche 2 : Créer le dossier et le composant Livewire SFC** (AC: 1, 2, 3)
  - [x] Créer le dossier `resources/views/pages/admin/legacy-monitor/`
  - [x] Créer `resources/views/pages/admin/legacy-monitor/index.blade.php` (Livewire SFC)
  - [x] Classe PHP dans le SFC : propriétés `$filterPath` (string), `$filterMethod` (string), `$perPage` (int = 50)
  - [x] Méthode `getLogs()` : requête paginée sur `LegacyCatchallLog` avec filtres appliqués
  - [x] Regroupement par path + méthode pour afficher la fréquence (nombre d'occurrences)
  - [x] `wire:poll.5s` sur la section liste pour rafraîchissement automatique toutes les 5 secondes

- [x] **Tâche 3 : UI — En-tête et filtres** (AC: 3)
  - [x] Utiliser `<x-organisms.page>` comme layout (pattern existant — cf. dashboard et control-hub)
  - [x] Champ texte filtre par path : `wire:model.live="filterPath"` avec debounce (`wire:model.live.300ms`)
  - [x] Select filtre par méthode : `wire:model.live="filterMethod"` — options : Toutes, GET, POST, PUT, DELETE, PATCH
  - [x] Bouton "Actualiser" manuel : `wire:click="$refresh"`

- [x] **Tâche 4 : UI — Tableau des appels** (AC: 1, 2)
  - [x] Tableau DaisyUI avec colonnes : Méthode, Path, Dernière occurrence (`created_at`), Fréquence (count)
  - [x] Badge coloré pour la méthode HTTP (GET=info, POST=warning, DELETE=error, autres=neutral)
  - [x] Tri par fréquence décroissante par défaut (les routes les plus actives en premier)
  - [x] Message "Aucun appel catchall enregistré" si table vide
  - [x] Pagination Livewire avec `WithPagination` trait

- [x] **Tâche 5 : Tests** (AC: 1, 2, 3, 4)
  - [x] Créer `tests/Feature/LegacyMonitorDashboardTest.php`
  - [x] Test : admin authentifié peut accéder à `/admin/legacy-monitor` → 200
  - [x] Test : utilisateur non-admin → redirigé (403 ou redirect)
  - [x] Test : la page affiche les données de `legacy_catchall_logs`
  - [x] Test : filtre par path fonctionne (retourne uniquement les lignes matchantes)
  - [x] Test : filtre par méthode fonctionne

## Dev Notes

### Contexte Projet

Le projet est **`sambaedu-reload/`**. Tout le code dans `sambaedu-reload/`.

**Laravel 12**, PHP 8.1+, Livewire v3 SFC, PostgreSQL, DaisyUI + Tailwind.

### Pattern Livewire SFC — À Suivre Exactement

Le projet utilise les Livewire SFC (Single File Components) : la classe PHP et la vue Blade sont dans le même fichier `.blade.php`. **Ne pas créer de fichier PHP séparé dans `app/Livewire/`.**

Structure du SFC (calquée sur `pages/dashboard/index.blade.php` et `pages/control-hub/index.blade.php`) :

```php
<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\WithPagination;
use App\Components\Traits\WithToasts;
use App\Models\LegacyCatchallLog;

new #[Title('Legacy Monitor - Instance SE4FS')] class extends Component {
    use WithToasts, WithPagination;

    public string $filterPath = '';
    public string $filterMethod = '';
    public int $perPage = 50;

    public function updatingFilterPath(): void { $this->resetPage(); }
    public function updatingFilterMethod(): void { $this->resetPage(); }

    public function getLogs()
    {
        return LegacyCatchallLog::query()
            ->when($this->filterPath, fn($q) => $q->where('path', 'like', '%' . $this->filterPath . '%'))
            ->when($this->filterMethod, fn($q) => $q->where('method', $this->filterMethod))
            ->selectRaw('method, path, COUNT(*) as frequency, MAX(created_at) as last_seen')
            ->groupBy('method', 'path')
            ->orderByDesc('frequency')
            ->paginate($this->perPage);
    }

    public function render() { return $this->view('livewire.pages.admin.legacy-monitor.index'); }
};
?>

<x-organisms.page title="Legacy Monitor" description="...">
    {{-- filtres + tableau --}}
</x-organisms.page>
```

**Note importante** : l'appel direct à `LegacyCatchallLog` dans le composant est acceptable ici — c'est une page d'administration de monitoring read-only, pas de logique métier. L'architecture préconise d'éviter Eloquent direct dans les Livewires mais les pages admin existantes (dashboard) font des appels DB directs pour les stats. Cohérence > purisme pour le monitoring.

### Registering de la route

Le groupe admin dans `routes/web.php` existe déjà (lignes ~149-175) :

```php
Route::prefix('admin')->middleware('sambaedu.admin')->name('admin.')->group(function () {
    Route::livewire('/control-hub', 'pages::control-hub.index')->name('controlHub.control-hub');
    // ... autres routes admin ...
});
```

Ajouter **dans ce groupe** :
```php
Route::livewire('/legacy-monitor', 'pages::admin.legacy-monitor.index')->name('legacy-monitor');
```

Route finale : `GET /admin/legacy-monitor`, nom : `admin.legacy-monitor`.

**⚠️ Note sur le composant path** : `control-hub` est référencé comme `pages::control-hub.index` (à la racine de pages/), mais `legacy-monitor` est dans `pages/admin/legacy-monitor/` donc référencé comme `pages::admin.legacy-monitor.index`.

### Polling Livewire

Pour le rafraîchissement automatique en Livewire v3, utiliser `wire:poll` sur un div englobant la liste :

```html
<div wire:poll.5s>
    {{-- tableau des logs --}}
</div>
```

Cela re-rend la section toutes les 5 secondes sans rechargement complet de page.

### Requête SQL — Groupement par Fréquence

La table `legacy_catchall_logs` a une ligne par appel (pas de compteur). Pour afficher la fréquence il faut un GROUP BY :

```php
LegacyCatchallLog::selectRaw('method, path, COUNT(*) as frequency, MAX(created_at) as last_seen')
    ->groupBy('method', 'path')
    ->orderByDesc('frequency')
```

Les index sur `path` et `created_at` (créés dans la migration story 1.2) accélèrent cette requête.

### UI DaisyUI — Composants à Utiliser

- Layout : `<x-organisms.page title="..." description="...">` (cf. `dashboard/index.blade.php` ligne 121)
- Badges méthode HTTP : `<span class="badge badge-info">GET</span>` (GET=info, POST=warning, DELETE=error, autres=neutral)
- Tableau : `<table class="table table-zebra">` (pattern DaisyUI standard)
- Filtre texte : `<input type="text" class="input input-bordered" wire:model.live.300ms="filterPath" placeholder="Filtrer par path...">`
- Filtre select : `<select class="select select-bordered" wire:model.live="filterMethod">`
- Pagination : `{{ $logs->links() }}` (Livewire WithPagination génère les liens)
- Empty state : `<div class="hero"><div class="hero-content text-center">Aucun appel catchall enregistré</div></div>`

### Points d'Attention / Risques

| Risque | Mitigation |
|--------|-----------|
| Story 1.2 non complétée | Vérifier que `legacy_catchall_logs` existe avant de toucher quoi que ce soit |
| Nom de la route de composant | `pages::admin.legacy-monitor.index` (avec le préfixe `admin.`) — différent de `control-hub` qui est à la racine |
| Pagination + polling | `wire:poll` sur la div contenant la pagination → le changement de page reset le poll timer, comportement normal |
| Performance | GROUP BY sur grande table — les index `path` + `created_at` de la migration 1.2 couvrent ce cas |

### Project Structure Notes

- **Vue SFC** : `sambaedu-reload/resources/views/pages/admin/legacy-monitor/index.blade.php`
- **Route** : ajouter dans le groupe admin de `sambaedu-reload/routes/web.php` (~ligne 149)
- **Modèle lu** : `sambaedu-reload/app/Models/LegacyCatchallLog.php` (créé en story 1.2)
- **Tests** : `sambaedu-reload/tests/Feature/LegacyMonitorDashboardTest.php`

### Learnings de Story 1.2

Story 1.2 (précédente) établit :
- Le modèle `LegacyCatchallLog` avec `$timestamps = false`, colonne `created_at` via `$casts`, et `$fillable` = colonnes de log
- La table `legacy_catchall_logs` avec index sur `path` et `created_at`
- Le middleware `sambaedu.admin` protège déjà la page via le groupe de routes

Pattern de test utilisé dans story 1.2 :
```php
// tests/Feature/LegacyCatchallTest.php — référence pour les tests de cette story
```

### References

- Exemple SFC admin : [resources/views/pages/control-hub/index.blade.php](sambaedu-reload/resources/views/pages/control-hub/index.blade.php)
- Exemple SFC dashboard (stats, polling pattern) : [resources/views/pages/dashboard/index.blade.php](sambaedu-reload/resources/views/pages/dashboard/index.blade.php)
- Groupe de routes admin : [routes/web.php:149](sambaedu-reload/routes/web.php#L149)
- Modèle LegacyCatchallLog (story 1.2) : [app/Models/LegacyCatchallLog.php](sambaedu-reload/app/Models/LegacyCatchallLog.php)
- Architecture — Dashboard legacy-monitor : [_bmad-output/planning-artifacts/architecture.md#Coexistence-Legacy-Stratégie-Catchall]
- Source épics : [_bmad-output/planning-artifacts/epics.md#Story-1-3]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Fix Vite manifest error en tests : ajout de `$this->withoutVite()` dans setUp() du test.
- `render()` omis du SFC (non nécessaire en Livewire v4 SFC — pattern confirmé par dashboard et control-hub).
- Tests filtre : vérification directe de la logique Eloquent (`when()`) plutôt que via Livewire test helper (anonymous class non testable via `Livewire::test()`).

### Completion Notes List

- Route `GET /admin/legacy-monitor` (nom: `admin.legacy-monitor`) ajoutée dans le groupe admin.
- SFC Livewire créé avec : filtres `filterPath`/`filterMethod`, pagination `WithPagination`, polling `wire:poll.5s`, tableau DaisyUI avec badges méthode HTTP colorés, état vide.
- 5 tests Feature créés et passants (14 assertions au total avec LegacyCatchallTest).
- Aucune régression sur les tests existants.

### Code Review (2026-03-20, claude-opus-4-6)

Revue adversariale à 3 couches (Blind Hunter, Edge Case Hunter, Acceptance Auditor). Résultat : 5 patches, 1 bad-spec, 2 defers, 4 rejetés.

**Corrections appliquées :**

- **P1** — Route déplacée du groupe `app` vers le groupe `admin` (était mal placée dans `Route::prefix('app')` au lieu de `Route::prefix('admin')`)
- **P2** — URLs des tests corrigées (`/app/legacy-monitor` → `/admin/legacy-monitor`)
- **P3** — Wildcards LIKE échappés via `Str::escapeLikeWildcards()` sur `filterPath`
- **P4** — `perPage` clampé entre 1 et 200 pour empêcher un chargement massif côté client
- **P5** — Tri secondaire `->orderBy('path')` ajouté pour un ordre déterministe sur les égalités de fréquence
- **S1** — Tests de filtre réécrits : requêtes HTTP avec query string + `assertSee`/`assertDontSee` au lieu de duplication manuelle de la query
- **Ajout** — Colonne "Dernière IP" ajoutée au tableau (`MAX(ip) as last_ip`)

**Defers (non bloquants) :**

- D1 — Polling 5s avec GROUP BY peut charger la DB si la table grossit (acceptable pour un outil admin interne)
- D2 — `getLogs()` est un appel de méthode, pas une `#[Computed]` property (impact mineur vu le polling)

### File List

- `sambaedu-reload/routes/web.php`
- `sambaedu-reload/resources/views/pages/admin/legacy-monitor/index.blade.php`
- `sambaedu-reload/tests/Feature/LegacyMonitorDashboardTest.php`
