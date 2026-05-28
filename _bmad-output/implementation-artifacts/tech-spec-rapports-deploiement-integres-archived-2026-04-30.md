---
title: 'Rapports de déploiement intégrés aux pages existantes'
slug: 'rapports-deploiement-integres'
created: '2026-04-15'
status: 'completed'
stepsCompleted: [1, 2, 3, 4, 5, 6, 7, 8, 9]
tech_stack: ['Laravel', 'Livewire v3', 'Alpine.js', 'DaisyUI', 'PostgreSQL']
files_to_modify:
  - database/migrations/2026_04_15_add_message_to_workstation_application_status.php
  - app/Models/WorkstationApplicationStatus.php
  - app/Repositories/WorkstationGroupRepository.php
  - app/Services/AppProfile/AppProfileService.php
  - resources/views/pages/parc/_partials/machines-tab.blade.php
  - resources/views/pages/parc/machines/[id]/index.blade.php
  - resources/views/pages/parc-settings/_partials/applications-tab.blade.php
  - resources/views/pages/parc-settings/applications/index.blade.php
code_patterns:
  - 'Livewire SFC (PHP + Blade dans le même fichier .blade.php)'
  - 'Computed properties via getXxxProperty() pour lazy loading'
  - 'withCount() pour éviter N+1 dans les listes paginées'
  - 'DaisyUI <dialog> + Alpine.js x-data pour les modales'
  - 'WithToasts trait pour les notifications'
  - 'Pas de WPKG / windows-deploy dans les labels UI'
test_patterns: []
---

# Tech-Spec: Rapports de déploiement intégrés aux pages existantes

**Created:** 2026-04-15

## Overview

### Problem Statement

Les données de statut de déploiement des applications sur les postes (`workstation_application_status`) existent en base mais ne sont visibles que depuis une page standalone (`/app/windows-deploy/reports`) non référencée dans la navigation, avec une terminologie WPKG-spécifique. Les administrateurs qui gèrent les postes et les applications n'ont aucune visibilité sur l'état de déploiement depuis les interfaces qu'ils utilisent déjà.

### Solution

Intégrer les stats de déploiement directement dans les pages existantes, sans créer de nouvelles pages dédiées :
- **Liste des postes** : colonne "Déploiement" avec compteur installé/erreur
- **Détail du poste** : card avec onglets Succès/Échecs + modale détail par app
- **Liste des applications** : colonne "Déploiement" avec taux de réussite coloré
- **Détail de l'application** : card listant les postes avec leur statut + modale détail

### Scope

**In Scope:**
- Migration pour ajouter colonne `message` (nullable) à `workstation_application_status`
- Colonne déploiement dans la liste machines (`parc/_partials/machines-tab.blade.php`)
- Card déploiement + modale dans la page détail machine (`parc/machines/[id]/index.blade.php`)
- Colonne déploiement dans la liste applications (`parc-settings/_partials/applications-tab.blade.php`)
- Card déploiement + modale dans la page détail application (`parc-settings/applications/index.blade.php`)
- Suppression des pages `windows-deploy/reports/` devenues obsolètes et leur route

**Out of Scope:**
- Modification du client WPKG pour alimenter le champ `message`
- Création de nouvelles routes/pages dédiées aux rapports
- Modification de la sidebar
- Modification de l'ingestion des rapports (story 9.4/9.5 séparées)

---

## Context for Development

### Codebase Patterns

- **Livewire SFC** : PHP + Blade dans le même fichier. La classe PHP est déclarée avec `new class extends Component`. Les computed properties sont des méthodes `getXxxProperty()` accessibles via `$this->xxx` en PHP ou `$xxx` dans le blade.
- **Listes paginées via services/repos** : les listes paginées passent par un service qui délègue à un repository. Pour ajouter des compteurs sans N+1, on modifie la query dans le repository avec `withCount()`.
- **Modales** : pattern DaisyUI `<dialog class="modal">` avec Alpine.js `x-data` pour l'état. Pas de composant générique — chaque modal est inline avec `@teleport('body')` pour éviter les problèmes de z-index. Voir pattern dans `machines-tab.blade.php` (modal groupe).
- **Pas de WPKG dans l'UI** : aucun label "WPKG", "windows-deploy", "rapport WPKG" côté utilisateur. Utiliser "Déploiement", "Rapport d'installation", "Statut d'installation".
- **Terminologie statuts** : `installed` → "Installé" ✓, `error` → "Erreur" ✗, `not-installed` → "Non installé" ✗, `upgrading`/`downgrading` → "En cours", `unknown` → "Inconnu". Les statuts `upgrading`/`downgrading`/`unknown` sont exclus du calcul du taux de réussite.
- **Machines sans rapport** : si `applicationStatuses_count = 0` (aucun `WorkstationApplicationStatus` pour cette machine), afficher `—` dans la liste. Les machines sans rapport n'apparaissent pas dans les cards de déploiement.

### Files to Reference

| File | Purpose |
| ---- | ------- |
| `app/Models/WorkstationApplicationStatus.php` | Modèle statut déploiement — champs : `workstation_id`, `application_id`, `installed_version`, `status`, `reboot_required`, `reported_at`, `message` (à ajouter) |
| `app/Models/Application.php` | Relation `workstationStatuses(): HasMany` vers `WorkstationApplicationStatus` |
| `app/Models/Workstation.php` | Relation `applicationStatuses(): HasMany` vers `WorkstationApplicationStatus` (ligne 308) |
| `app/Repositories/WorkstationGroupRepository.php` | `getMachines()` ligne 132 — requête Workstation à enrichir avec `withCount` |
| `app/Services/AppProfile/AppProfileService.php` | `listApplications()` ligne 324 — requête Application à enrichir avec `withCount` |
| `resources/views/pages/parc/_partials/machines-tab.blade.php` | Liste machines — ajouter colonne déploiement |
| `resources/views/pages/parc/machines/[id]/index.blade.php` | Page détail machine — ajouter card déploiement |
| `resources/views/pages/parc-settings/_partials/applications-tab.blade.php` | Liste applications — ajouter colonne déploiement |
| `resources/views/pages/parc-settings/applications/index.blade.php` | Page détail application — ajouter card déploiement |
| `resources/views/pages/windows-deploy/reports/index.blade.php` | Page à supprimer |
| `resources/views/pages/windows-deploy/reports/[workstation]/index.blade.php` | Page à supprimer |
| `routes/web.php` | Supprimer le groupe `windows-deploy` (lignes 143-146) |

### Technical Decisions

1. **Colonne `message` nullable** : aucun champ de message d'erreur dans `workstation_application_status`. Ajout via migration. La colonne sera `NULL` jusqu'à ce que l'ingestion des rapports (story 9.5) l'alimente. La modale affiche "Aucun détail disponible" si NULL.

2. **`withCount` dans les repositories** : pour éviter le N+1 sur les listes paginées, on ajoute les `withCount` directement dans les queries du repository/service, pas dans le blade. Les attributs résultants seront `installed_apps_count`, `error_apps_count` sur `Workstation`, et `installed_count`, `error_count`, `in_progress_count` sur `Application`.

3. **Onglets Succès/Échecs sur la page machine** : Succès = `status = 'installed'`. Échecs = `status IN ('error', 'not-installed')`. En cours = `status IN ('upgrading', 'downgrading')` — affiché séparément, non inclus dans les onglets. L'onglet Échecs est pré-sélectionné si `error_apps_count > 0`.

4. **Taux de réussite sur la liste apps** : `installed_count / (installed_count + error_count + not_installed_count) * 100`. Couleur : vert si 100%, orange si > 0% et < 100%, rouge si 0%. N/A si `total_with_report = 0` (aucun statut terminé).

5. **Modale déploiement** : état Livewire `public ?int $deploymentModalStatusId = null`. La modale charge les données de `WorkstationApplicationStatus::find($deploymentModalStatusId)->load('application')`. Pattern inline DaisyUI `<dialog>` + `@entangle`.

6. **Card déploiement page machine** : données chargées via computed property `getDeploymentStatusesProperty()` qui requête `WorkstationApplicationStatus` avec relation `application` chargée. Onglet actif = propriété Livewire `$deploymentTab = 'errors'|'success'`.

7. **Card déploiement page application** : données chargées via computed property `getWorkstationDeploymentsProperty()` qui requête `WorkstationApplicationStatus` avec relation `workstation` chargée, uniquement les statuts terminés (`NOT IN ('upgrading', 'downgrading', 'unknown')`).

---

## Implementation Plan

### Tasks

Les tâches sont ordonnées du plus bas niveau vers le plus haut (backend d'abord, UI ensuite).

#### T1 — Migration : ajout colonne `message`
**Fichier :** `database/migrations/2026_04_15_add_message_to_workstation_application_status.php` (nouveau)

```php
Schema::table('workstation_application_status', function (Blueprint $table) {
    $table->text('message')->nullable()->after('reboot_required');
});
```

#### T2 — Modèle : mise à jour `WorkstationApplicationStatus`
**Fichier :** `app/Models/WorkstationApplicationStatus.php`

- Ajouter `'message'` dans `$fillable`
- Ajouter `'message' => 'string'` dans `$casts` (ou laisser implicite)

#### T3 — Repository : `withCount` sur `getMachines()`
**Fichier :** `app/Repositories/WorkstationGroupRepository.php`, méthode `getMachines()` ligne 138

Modifier la query pour ajouter :
```php
$query->withCount([
    'applicationStatuses as installed_apps_count' => fn($q) => $q->where('status', 'installed'),
    'applicationStatuses as error_apps_count' => fn($q) => $q->whereIn('status', ['error', 'not-installed']),
]);
```

#### T4 — Service : `withCount` sur `listApplications()`
**Fichier :** `app/Services/AppProfile/AppProfileService.php`, méthode `listApplications()` ligne 330

Ajouter après `Application::query()->with('depot')` :
```php
->withCount([
    'workstationStatuses as deployed_total_count' => fn($q) => $q->whereIn('status', ['installed', 'error', 'not-installed']),
    'workstationStatuses as deployed_installed_count' => fn($q) => $q->where('status', 'installed'),
    'workstationStatuses as deployed_error_count' => fn($q) => $q->whereIn('status', ['error', 'not-installed']),
])
```

#### T5 — Vue liste machines : colonne Déploiement
**Fichier :** `resources/views/pages/parc/_partials/machines-tab.blade.php`

Ajouter dans le `<thead>` après `<th>Statut</th>` :
```html
<th class="text-center">Déploiement</th>
```

Ajouter dans le `<tbody>` après la cellule Statut :
```html
<td class="text-center" onclick="event.stopPropagation()">
    @if ($machine->installed_apps_count > 0 || $machine->error_apps_count > 0)
        <span class="font-mono text-sm">
            <span class="text-success">{{ $machine->installed_apps_count }} ✓</span>
            @if ($machine->error_apps_count > 0)
                <span class="text-error ml-1">{{ $machine->error_apps_count }} ✗</span>
            @endif
        </span>
    @else
        <span class="text-base-content/30">—</span>
    @endif
</td>
```

Note : `onclick="event.stopPropagation()"` pour ne pas déclencher la navigation de la ligne.

#### T6 — Page détail machine : card déploiement
**Fichier :** `resources/views/pages/parc/machines/[id]/index.blade.php`

**Dans la classe PHP** (après les propriétés existantes), ajouter :
```php
public string $deploymentTab = 'errors'; // 'errors' | 'success'
public ?int $deploymentModalStatusId = null;

public function getDeploymentStatusesProperty(): array
{
    if (!$this->workstation) return ['success' => collect(), 'errors' => collect(), 'in_progress' => collect()];

    $statuses = WorkstationApplicationStatus::query()
        ->with('application')
        ->where('workstation_id', $this->workstation->id)
        ->get();

    return [
        'success'     => $statuses->filter(fn($s) => $s->status === 'installed'),
        'errors'      => $statuses->filter(fn($s) => in_array($s->status, ['error', 'not-installed'])),
        'in_progress' => $statuses->filter(fn($s) => in_array($s->status, ['upgrading', 'downgrading'])),
    ];
}

public function initDeploymentTab(): void
{
    $deployment = $this->deploymentStatuses;
    $this->deploymentTab = $deployment['errors']->isNotEmpty() ? 'errors' : 'success';
}

public function openDeploymentModal(int $statusId): void
{
    $this->deploymentModalStatusId = $statusId;
}

public function closeDeploymentModal(): void
{
    $this->deploymentModalStatusId = null;
}
```

**Dans le `mount()`** : ajouter `$this->initDeploymentTab();` après `$this->loadMachine()`.

**Ajouter l'import** en haut du fichier : `use App\Models\WorkstationApplicationStatus;`

**Dans le blade**, dans la grille `grid-cols-1 lg:grid-cols-3`, changer le layout pour inclure la card sous les deux colonnes existantes :
```html
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    @include('pages.parc.machines.[id]._partials.machine-info')
    @include('pages.parc.machines.[id]._partials.groups-list')
</div>

{{-- Card déploiement (pleine largeur) --}}
@include('pages.parc.machines.[id]._partials.deployment-card')
```

**Créer** `resources/views/pages/parc/machines/[id]/_partials/deployment-card.blade.php` :

```blade
@php $deployment = $this->deploymentStatuses; @endphp

@if ($deployment['success']->isNotEmpty() || $deployment['errors']->isNotEmpty() || $deployment['in_progress']->isNotEmpty())
<div class="card bg-base-100 shadow-sm border border-base-200">
    <div class="card-body">
        <div class="flex items-center justify-between mb-4">
            <h3 class="card-title text-lg">
                <i class="fa-solid fa-chart-bar mr-2"></i>
                Déploiement des applications
            </h3>
            {{-- Indicateur En cours --}}
            @if ($deployment['in_progress']->isNotEmpty())
                <span class="badge badge-info">
                    <span class="loading loading-spinner loading-xs mr-1"></span>
                    {{ $deployment['in_progress']->count() }} en cours
                </span>
            @endif
        </div>

        {{-- Onglets --}}
        <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit mb-4">
            <button type="button" role="tab"
                class="tab {{ $deploymentTab === 'success' ? 'tab-active' : '' }}"
                wire:click="$set('deploymentTab', 'success')">
                <i class="fa-solid fa-check mr-2 text-success"></i>
                Succès
                <span class="badge badge-sm ml-2 badge-success">{{ $deployment['success']->count() }}</span>
            </button>
            <button type="button" role="tab"
                class="tab {{ $deploymentTab === 'errors' ? 'tab-active' : '' }}"
                wire:click="$set('deploymentTab', 'errors')">
                <i class="fa-solid fa-xmark mr-2 text-error"></i>
                Échecs
                @if ($deployment['errors']->isNotEmpty())
                    <span class="badge badge-sm ml-2 badge-error">{{ $deployment['errors']->count() }}</span>
                @else
                    <span class="badge badge-sm ml-2 badge-ghost">0</span>
                @endif
            </button>
        </div>

        {{-- Contenu onglets --}}
        @php $items = $deploymentTab === 'success' ? $deployment['success'] : $deployment['errors']; @endphp
        @if ($items->isEmpty())
            <p class="text-base-content/50 text-sm py-4 text-center">Aucune application dans cette catégorie.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr class="bg-base-200">
                            <th>Application</th>
                            <th>Version installée</th>
                            <th class="text-center">Statut</th>
                            <th>Dernier rapport</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $status)
                            <tr class="hover">
                                <td>
                                    <div class="font-medium">{{ $status->application?->name ?? $status->application?->app_id ?? '—' }}</div>
                                    <div class="text-xs text-base-content/50 font-mono">{{ $status->application?->app_id ?? '' }}</div>
                                </td>
                                <td class="font-mono text-sm">{{ $status->installed_version ?: '—' }}</td>
                                <td class="text-center">
                                    @if ($status->status === 'installed')
                                        <span class="badge badge-success badge-sm">Installé</span>
                                    @elseif ($status->status === 'error')
                                        <button type="button"
                                            class="badge badge-error badge-sm cursor-pointer hover:badge-outline"
                                            wire:click="openDeploymentModal({{ $status->id }})">
                                            Erreur ↗
                                        </button>
                                    @else
                                        <button type="button"
                                            class="badge badge-warning badge-sm cursor-pointer hover:badge-outline"
                                            wire:click="openDeploymentModal({{ $status->id }})">
                                            Non installé ↗
                                        </button>
                                    @endif
                                </td>
                                <td class="text-sm text-base-content/60">
                                    {{ $status->reported_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

{{-- Modale détail statut --}}
@if ($deploymentModalStatusId)
    @php $modalStatus = \App\Models\WorkstationApplicationStatus::with('application')->find($deploymentModalStatusId); @endphp
    @teleport('body')
        <dialog class="modal modal-open">
            <div class="modal-box max-w-lg">
                {{-- Header --}}
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="font-bold text-lg">{{ $modalStatus?->application?->name ?? '—' }}</h3>
                        <p class="text-sm text-base-content/60 font-mono">{{ $modalStatus?->application?->app_id ?? '' }}</p>
                    </div>
                    <button type="button" wire:click="closeDeploymentModal" class="btn btn-sm btn-circle btn-ghost">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                {{-- Infos --}}
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="bg-base-200 rounded-lg p-3">
                        <p class="text-xs text-base-content/60">Version installée</p>
                        <p class="font-mono font-medium">{{ $modalStatus?->installed_version ?: '—' }}</p>
                    </div>
                    <div class="bg-base-200 rounded-lg p-3">
                        <p class="text-xs text-base-content/60">Dernier rapport</p>
                        <p class="font-medium">{{ $modalStatus?->reported_at?->format('d/m/Y H:i') ?? '—' }}</p>
                    </div>
                    <div class="bg-base-200 rounded-lg p-3">
                        <p class="text-xs text-base-content/60">Statut</p>
                        <span class="badge {{ $modalStatus?->status === 'error' ? 'badge-error' : 'badge-warning' }}">
                            {{ $modalStatus?->status_label ?? $modalStatus?->status }}
                        </span>
                    </div>
                    @if ($modalStatus?->reboot_required)
                    <div class="bg-warning/10 rounded-lg p-3">
                        <p class="text-xs text-base-content/60">Redémarrage</p>
                        <p class="font-medium text-warning">
                            <i class="fa-solid fa-rotate-right mr-1"></i>Requis
                        </p>
                    </div>
                    @endif
                </div>

                {{-- Message d'erreur --}}
                <div class="bg-base-200 rounded-lg p-3 max-h-48 overflow-y-auto">
                    <p class="text-xs text-base-content/60 mb-1">Détail</p>
                    @if ($modalStatus?->message)
                        <pre class="text-xs font-mono whitespace-pre-wrap break-all">{{ $modalStatus->message }}</pre>
                    @else
                        <p class="text-sm text-base-content/50 italic">Aucun détail disponible.</p>
                    @endif
                </div>
            </div>
            <form method="dialog" class="modal-backdrop" wire:click="closeDeploymentModal">
                <button>close</button>
            </form>
        </dialog>
    @endteleport
@endif
@else
{{-- Aucun rapport --}}
<div class="card bg-base-100 shadow-sm border border-base-200">
    <div class="card-body py-6 flex flex-col items-center text-center">
        <i class="fa-solid fa-chart-bar text-3xl text-base-content/20 mb-2"></i>
        <p class="text-sm text-base-content/50">Aucun rapport d'installation disponible pour ce poste.</p>
    </div>
</div>
@endif
```

#### T7 — Vue liste applications : colonne Déploiement
**Fichier :** `resources/views/pages/parc-settings/_partials/applications-tab.blade.php`

Ajouter dans le `<thead>` (ligne ~401, après `<th class="text-center">Dépôt</th>`) :
```html
<th class="text-center">Déploiement</th>
```

Ajouter dans le `<tbody>` après la cellule Dépôt :
```html
<td class="text-center" onclick="event.stopPropagation()">
    @php
        $total = ($app->deployed_installed_count ?? 0) + ($app->deployed_error_count ?? 0);
        $installed = $app->deployed_installed_count ?? 0;
    @endphp
    @if ($total > 0)
        @php $rate = round(($installed / $total) * 100); @endphp
        <span class="text-sm font-medium {{ $rate === 100 ? 'text-success' : ($rate === 0 ? 'text-error' : 'text-warning') }}">
            {{ $installed }}/{{ $total }}
        </span>
        <span class="text-xs text-base-content/50 ml-1">({{ $rate }}%)</span>
    @else
        <span class="text-base-content/30 text-sm">N/A</span>
    @endif
</td>
```

#### T8 — Page détail application : card déploiement
**Fichier :** `resources/views/pages/parc-settings/applications/index.blade.php`

**Dans la classe PHP**, ajouter :
```php
public ?int $deploymentModalStatusId = null;

public function getWorkstationDeploymentsProperty()
{
    return WorkstationApplicationStatus::query()
        ->with('workstation')
        ->where('application_id', $this->applicationId)
        ->whereIn('status', ['installed', 'error', 'not-installed'])
        ->orderByRaw("CASE WHEN status = 'error' THEN 0 WHEN status = 'not-installed' THEN 1 ELSE 2 END")
        ->get();
}

public function openDeploymentModal(int $statusId): void
{
    $this->deploymentModalStatusId = $statusId;
}

public function closeDeploymentModal(): void
{
    $this->deploymentModalStatusId = null;
}
```

**Ajouter l'import** : `use App\Models\WorkstationApplicationStatus;`

**Dans le blade**, dans la sidebar (`.space-y-6` à droite), ajouter après la card "Profils applicatifs" :
```html
{{-- Card déploiement postes --}}
@php $deployments = $this->workstationDeployments; @endphp
@if ($deployments->isNotEmpty())
<div class="card bg-base-100 shadow-sm border border-base-200">
    <div class="card-body">
        <h3 class="card-title text-base mb-3">
            <i class="fa-solid fa-computer mr-2"></i>
            Déploiement sur les postes
        </h3>
        @php
            $total = $deployments->count();
            $installed = $deployments->where('status', 'installed')->count();
            $rate = $total > 0 ? round(($installed / $total) * 100) : 0;
        @endphp
        <div class="flex items-center gap-2 mb-3">
            <progress class="progress {{ $rate === 100 ? 'progress-success' : ($rate === 0 ? 'progress-error' : 'progress-warning') }} flex-1"
                value="{{ $rate }}" max="100"></progress>
            <span class="text-sm font-semibold {{ $rate === 100 ? 'text-success' : ($rate === 0 ? 'text-error' : 'text-warning') }}">
                {{ $installed }}/{{ $total }} ({{ $rate }}%)
            </span>
        </div>
        <div class="space-y-1 max-h-64 overflow-y-auto">
            @foreach ($deployments as $status)
                <div class="flex items-center justify-between p-2 rounded-lg hover:bg-base-200 transition-colors">
                    <span class="text-sm font-medium">{{ $status->workstation?->name ?? '—' }}</span>
                    @if ($status->status === 'installed')
                        <span class="badge badge-success badge-sm">Installé</span>
                    @elseif ($status->status === 'error')
                        <button type="button"
                            class="badge badge-error badge-sm cursor-pointer hover:badge-outline"
                            wire:click="openDeploymentModal({{ $status->id }})">
                            Erreur ↗
                        </button>
                    @else
                        <button type="button"
                            class="badge badge-warning badge-sm cursor-pointer hover:badge-outline"
                            wire:click="openDeploymentModal({{ $status->id }})">
                            Non installé ↗
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif

{{-- Modale détail déploiement (même structure que T6) --}}
@if ($deploymentModalStatusId)
    @php $modalStatus = \App\Models\WorkstationApplicationStatus::with('workstation')->find($deploymentModalStatusId); @endphp
    @teleport('body')
        <dialog class="modal modal-open">
            <div class="modal-box max-w-lg">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="font-bold text-lg">{{ $modalStatus?->workstation?->name ?? '—' }}</h3>
                        <p class="text-sm text-base-content/60">{{ $modalStatus?->workstation?->os ?? '' }}</p>
                    </div>
                    <button type="button" wire:click="closeDeploymentModal" class="btn btn-sm btn-circle btn-ghost">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="bg-base-200 rounded-lg p-3">
                        <p class="text-xs text-base-content/60">Version installée</p>
                        <p class="font-mono font-medium">{{ $modalStatus?->installed_version ?: '—' }}</p>
                    </div>
                    <div class="bg-base-200 rounded-lg p-3">
                        <p class="text-xs text-base-content/60">Dernier rapport</p>
                        <p class="font-medium">{{ $modalStatus?->reported_at?->format('d/m/Y H:i') ?? '—' }}</p>
                    </div>
                </div>
                <div class="bg-base-200 rounded-lg p-3 max-h-48 overflow-y-auto">
                    <p class="text-xs text-base-content/60 mb-1">Détail</p>
                    @if ($modalStatus?->message)
                        <pre class="text-xs font-mono whitespace-pre-wrap break-all">{{ $modalStatus->message }}</pre>
                    @else
                        <p class="text-sm text-base-content/50 italic">Aucun détail disponible.</p>
                    @endif
                </div>
            </div>
            <form method="dialog" class="modal-backdrop" wire:click="closeDeploymentModal">
                <button>close</button>
            </form>
        </dialog>
    @endteleport
@endif
```

#### T9 — Suppression pages `windows-deploy` et route

1. Supprimer `resources/views/pages/windows-deploy/` (tout le dossier)
2. Dans `routes/web.php`, supprimer le groupe (lignes 140-146) :
```php
// ========================================
// Déploiement Windows - Rapports WPKG
// ========================================
Route::prefix('windows-deploy')->name('windows-deploy.')->group(function () {
    Route::livewire('/reports', 'pages::windows-deploy.reports.index')->name('reports.index');
    Route::livewire('/reports/{workstation}', 'pages::windows-deploy.reports.[workstation].index')->name('reports.show');
});
```

---

### Acceptance Criteria

**AC1 — Colonne déploiement dans la liste machines**
- **Given** une machine avec des rapports d'installation
- **When** l'admin navigue vers `app/parc` > onglet Postes
- **Then** la colonne "Déploiement" affiche `N ✓` en vert et `M ✗` en rouge si erreurs, rien si N=0 et M=0
- **And** si la machine n'a aucun rapport, la cellule affiche `—`

**AC2 — Card déploiement sur la page machine**
- **Given** une machine avec des statuts d'installation
- **When** l'admin navigue vers `app/parc/machines/{id}`
- **Then** une card "Déploiement des applications" est visible
- **And** elle affiche deux onglets : "Succès" et "Échecs"
- **And** l'onglet "Échecs" est sélectionné par défaut si `error_count > 0`, sinon "Succès"
- **And** si des apps sont `upgrading`/`downgrading`, un badge "N en cours" est affiché dans le header de la card

**AC3 — Modale détail depuis la page machine**
- **Given** une app avec statut `error` ou `not-installed` dans la card déploiement
- **When** l'admin clique sur le badge de statut
- **Then** une modale s'ouvre avec le nom de l'app, la version, la date du rapport
- **And** si `message` n'est pas null, le message est affiché dans la zone scrollable
- **And** si `message` est null, "Aucun détail disponible" est affiché

**AC4 — Colonne taux de réussite dans la liste applications**
- **Given** des applications avec des rapports de déploiement postes
- **When** l'admin navigue vers `app/parc-settings` > onglet Catalogue
- **Then** la colonne "Déploiement" affiche `X/Y (Z%)` en vert si 100%, orange si entre 0 et 100, rouge si 0
- **And** si aucune donnée de déploiement n'existe, la cellule affiche `N/A`

**AC5 — Card déploiement sur la page application**
- **Given** une application déployée sur plusieurs postes
- **When** l'admin navigue vers `app/parc-settings/applications/{id}`
- **Then** une card "Déploiement sur les postes" est visible dans la sidebar avec la liste des postes et leur statut
- **And** une barre de progression colorée indique le taux de réussite global
- **And** les postes en erreur/non installés ont un bouton cliquable ouvrant la modale de détail

**AC6 — Suppression des pages windows-deploy**
- **Given** les URLs `/app/windows-deploy/reports` et `/app/windows-deploy/reports/{id}`
- **When** un utilisateur tente de les visiter
- **Then** une 404 est retournée (les routes n'existent plus)

---

## Additional Context

### Dependencies

- Aucune dépendance externe. Tout repose sur les données existantes en base.
- Le champ `message` sera vide (NULL) jusqu'à la mise à jour de l'ingestion des rapports (story 9.5).

### Testing Strategy

- Vérifier manuellement les pages `parc/` et `parc-settings/` avec des données de test en base.
- Vérifier que les listes ne font pas de requêtes N+1 (vérifier les requêtes dans le profiler si disponible).
- Vérifier la modale s'ouvre et se ferme correctement.
- Vérifier que les machines sans rapport affichent `—` et non `0/0 (0%)`.

### Notes

- Le `status_label` utilisé dans la modale (T6) correspond à `getStatusLabelAttribute()` du modèle `WorkstationApplicationStatus` — attention à la casse (retourne "Echec" sans accent, "Installe" sans accent). Utiliser directement un `match` en blade si besoin d'une meilleure UX.
- La suppression du dossier `windows-deploy/` doit se faire avec `trash` et non `rm -rf` (convention projet).
- La colonne `deployed_total_count` dans T4 est utile pour différencier "0 total" de "N total avec 0 installé".
