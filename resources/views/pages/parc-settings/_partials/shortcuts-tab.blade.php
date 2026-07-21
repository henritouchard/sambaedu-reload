<?php

use Livewire\Component;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\Shortcut;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use App\Components\Traits\WithToasts;

new class extends Component {
    use WithToasts;

    #[Url]
    public string $search = '';
    #[Url]
    public string $type = 'all';
    #[Url]
    public string $place = 'all';
    #[Url]
    public int $perPage = 20;
    #[Url]
    public int $currentPage = 1;

    public array $selectedShortcuts = [];

    /** @var Shortcut[] */
    public array $shortcuts = [];
    public ?array $pagination = null;
    public int $totalShortcuts = 0;
    public array $allowedPerPage = [10, 20, 50, 100];
    public array $filters = [];

    public function mount()
    {
        $this->filters = [
            'type' => [
                'all' => 'Tous les types',
                'url' => 'Sites web',
                'app' => 'Applications',
            ],
            'place' => [
                'all' => 'Tous les emplacements',
                'desktop' => 'Bureau',
                'startup' => 'Démarrage automatique',
                'taskbar' => 'Barre des tâches (seulement Linux)',
            ],
        ];
        $this->loadShortcuts();
    }

    public function updatedSearch()
    {
        $this->handleFilterUpdate();
    }

    public function updatedType()
    {
        $this->handleFilterUpdate();
    }

    public function updatedPlace()
    {
        $this->handleFilterUpdate();
    }

    public function updatedPerPage()
    {
        if (!in_array($this->perPage, $this->allowedPerPage)) {
            $this->perPage = 20;
        }
        $this->handleFilterUpdate();
    }

    private function handleFilterUpdate()
    {
        $this->resetPage();
        $this->loadShortcuts();
    }

    private function resetPage()
    {
        $this->currentPage = 1;
    }

    public function loadShortcuts()
    {
        try {
            $query = Shortcut::query();

            if (!empty($this->search)) {
                $query->search($this->search);
            }

            if ($this->type === 'url') {
                $query->whereNotNull('windows_args')->where('windows_args', 'LIKE', 'http%');
            } elseif ($this->type === 'app') {
                $query->where(function ($q) {
                    $q->whereNull('windows_args')
                      ->orWhere('windows_args', 'NOT LIKE', 'http%');
                });
            }

            if ($this->place !== 'all') {
                $query->byPlace($this->place);
            }

            $this->totalShortcuts = $query->count();

            $lastPage = max(1, (int) ceil($this->totalShortcuts / $this->perPage));
            $this->currentPage = min($this->currentPage, $lastPage);
            $offset = ($this->currentPage - 1) * $this->perPage;

            $this->shortcuts = $query->with(['workstationGroups', 'workstations'])->orderBy('name')
                ->skip($offset)
                ->take($this->perPage)
                ->get()
                ->all();

            $this->pagination = [
                'current_page' => $this->currentPage,
                'per_page' => $this->perPage,
                'total' => $this->totalShortcuts,
                'last_page' => $lastPage,
                'from' => $this->totalShortcuts > 0 ? $offset + 1 : 0,
                'to' => min($offset + $this->perPage, $this->totalShortcuts),
                'has_more_pages' => $this->currentPage < $lastPage,
            ];
        } catch (\Exception $e) {
            Log::error('ShortcutsTab loadShortcuts error: ' . $e->getMessage());
            $this->shortcuts = [];
            $this->pagination = null;
            $this->totalShortcuts = 0;
        }
    }

    public function goToPage($page)
    {
        $this->currentPage = max(1, min($page, $this->pagination['last_page'] ?? 1));
        $this->loadShortcuts();
    }

    public function nextPage()
    {
        if ($this->currentPage < ($this->pagination['last_page'] ?? 1)) {
            $this->currentPage++;
            $this->loadShortcuts();
        }
    }

    public function previousPage()
    {
        if ($this->currentPage > 1) {
            $this->currentPage--;
            $this->loadShortcuts();
        }
    }

    public function bulkDelete()
    {
        if (Gate::denies('bulkDelete-shortcut')) {
            $this->toast('error', 'Accès refusé', 'Vous n\'avez pas les droits pour supprimer des raccourcis');
            return;
        }

        if (empty($this->selectedShortcuts)) {
            $this->toast('warning', 'Attention', 'Aucun raccourci sélectionné');
            return;
        }

        try {
            $deletedCount = Shortcut::whereIn('key', $this->selectedShortcuts)
                ->where('is_global', false)
                ->delete();

            $globalCount = count($this->selectedShortcuts) - $deletedCount;
            $message = $deletedCount . ' raccourci(s) supprimé(s) avec succès';
            if ($globalCount > 0) {
                $message .= ". {$globalCount} raccourci(s) ControlHub ignoré(s).";
            }

            $this->toast('success', 'Suppression réussie', $message);
            $this->selectedShortcuts = [];
            $this->loadShortcuts();
        } catch (\Exception $e) {
            Log::error('ShortcutsTab bulkDelete error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la suppression des raccourcis');
        }
    }

    public function openBulkAssignmentModal(): void
    {
        if (Gate::denies('update-shortcut')) {
            $this->toast('error', 'Accès refusé', 'Vous n\'avez pas les droits pour assigner des raccourcis');
            return;
        }

        if (empty($this->selectedShortcuts)) {
            $this->toast('warning', 'Attention', 'Aucun raccourci sélectionné');
            return;
        }

        // Assignation groupée : les cibles déjà assignées diffèrent d'un raccourci
        // à l'autre, il n'y a donc pas de socle commun à exclure — la modale
        // propose tout, et `syncWithoutDetaching` rend l'ajout idempotent.
        $this->dispatch('open-shortcut-assignment-modal');
    }

    #[On('shortcut-assignments-confirmed')]
    public function onAssignmentsConfirmed(
        array $workstationGroupIds = [],
        array $workstationIds = [],
        array $adUsers = [],
        array $adUserGroups = []
    ): void {
        if (Gate::denies('update-shortcut')) {
            $this->toast('error', 'Accès refusé', 'Vous n\'avez pas les droits pour assigner des raccourcis');
            return;
        }

        if (empty($this->selectedShortcuts)) {
            return;
        }

        if (empty($workstationGroupIds) && empty($workstationIds) && empty($adUsers) && empty($adUserGroups)) {
            $this->toast('warning', 'Attention', 'Aucune cible sélectionnée');
            return;
        }

        try {
            $shortcuts = Shortcut::whereIn('key', $this->selectedShortcuts)->get();
            $assignedCount = 0;
            $globalCount = 0;

            foreach ($shortcuts as $shortcut) {
                // Les raccourcis ControlHub sont pilotés en amont : on les ignore
                // silencieusement plutôt que de faire échouer le lot.
                if ($shortcut->is_global) {
                    $globalCount++;
                    continue;
                }

                if (!empty($workstationGroupIds)) {
                    $shortcut->workstationGroups()->syncWithoutDetaching($workstationGroupIds);
                }

                if (!empty($workstationIds)) {
                    $shortcut->workstations()->syncWithoutDetaching($workstationIds);
                }

                $attributes = [];

                if (!empty($adUsers)) {
                    $attributes['ad_users'] = array_values(array_unique(
                        array_merge($shortcut->ad_users ?? [], $adUsers)
                    ));
                }

                if (!empty($adUserGroups)) {
                    $attributes['ad_user_groups'] = array_values(array_unique(
                        array_merge($shortcut->ad_user_groups ?? [], $adUserGroups)
                    ));
                }

                if (!empty($attributes)) {
                    $shortcut->update($attributes);
                }

                $assignedCount++;
            }

            $targetCount = count($workstationGroupIds) + count($workstationIds)
                + count($adUsers) + count($adUserGroups);

            $message = "{$targetCount} cible(s) assignée(s) à {$assignedCount} raccourci(s)";
            if ($globalCount > 0) {
                $message .= ". {$globalCount} raccourci(s) ControlHub ignoré(s).";
            }

            $this->toast('success', 'Assignations ajoutées', $message);
            $this->selectedShortcuts = [];
            $this->loadShortcuts();
        } catch (\Exception $e) {
            Log::error('ShortcutsTab onAssignmentsConfirmed error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de l\'assignation des raccourcis');
        }
    }

    public function deleteShortcut(string $key)
    {
        if (Gate::denies('delete-shortcut')) {
            $this->toast('error', 'Accès refusé', 'Vous n\'avez pas les droits pour supprimer ce raccourci');
            return;
        }

        try {
            $shortcut = Shortcut::findByKey($key);
            if (!$shortcut) {
                $this->toast('error', 'Erreur', 'Raccourci non trouvé');
                return;
            }

            if ($shortcut->is_global) {
                $this->toast('error', 'Erreur', 'Ce raccourci est géré par le ControlHub et ne peut pas être supprimé ici');
                return;
            }

            $shortcut->delete();
            $this->toast('success', 'Suppression réussie', 'Raccourci supprimé avec succès');
            $this->loadShortcuts();
        } catch (\Exception $e) {
            Log::error('ShortcutsTab deleteShortcut error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la suppression du raccourci');
        }
    }

    public function resetFilters()
    {
        $this->search = '';
        $this->type = 'all';
        $this->place = 'all';
        $this->currentPage = 1;
        $this->loadShortcuts();
    }

    public function getShortcutIconUrl(string $name): string
    {
        $iconPath = '/etc/sambaedu/applications/shortcuts/' . $name . '.png';
        if (file_exists($iconPath)) {
            return route('shortcuts.icon', ['name' => $name]);
        }
        return asset('elements/images/system-run.png');
    }
};
?>

@php
    $hasFilters = !empty($search) || $type !== 'all' || $place !== 'all';

    $placeLabels = [
        'desktop' => 'Bureau',
        'startup' => 'Démarrage auto',
        'taskbar' => 'Barre des tâches',
    ];
@endphp

<div class="flex flex-col gap-3 flex-1 min-h-0">
    <!-- Filtres -->
    <x-molecules.filter-bar reset="resetFilters" :reset-disabled="!$hasFilters">
        <div class="flex-1 min-w-[200px]">
            <x-atoms.search-input model="search" placeholder="Nom, propriétaire..." />
        </div>

        {{-- 3 options à libellés courts → segmenté. --}}
        <x-molecules.filter-toggle name="type" :active="$type" :options="$filters['type']" />

        {{-- 4 options, mais libellés longs (« Barre des tâches (seulement Linux) ») :
             en segmenté la rangée déborderait — dropdown malgré le seuil. --}}
        <x-molecules.filter-select model="place" :options="$filters['place']" :placeholder="null"
            width="w-56" />
    </x-molecules.filter-bar>

    <!-- Tableau des raccourcis -->
    <div class="card bg-base-100 shadow-sm border border-base-300 flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="card-body p-0 flex flex-col flex-1 min-h-0">
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <x-molecules.select-all-checkbox class="checkbox-sm" :ids="collect($shortcuts)->pluck('key')"
                                    model="selectedShortcuts" />
                            </th>
                            <th>Raccourci</th>
                            <th>Type</th>
                            <th>Emplacement</th>
                            <th>Cibles</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($shortcuts as $shortcut)
                            <tr wire:key="shortcut-{{ $shortcut->key }}" class="hover cursor-pointer"
                                onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.shortcuts.show', $shortcut->key) }}'">
                                <td class="checkbox-cell p-0">
                                    <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                        <input type="checkbox" class="checkbox checkbox-sm"
                                            wire:model.live="selectedShortcuts" value="{{ $shortcut->key }}" />
                                    </label>
                                </td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <img src="{{ $this->getShortcutIconUrl($shortcut->name) }}"
                                            alt="{{ $shortcut->name }}" class="w-8 h-8 rounded"
                                            onerror="this.src='/elements/images/system-run.png'">
                                        <div>
                                            <span class="font-medium">{{ $shortcut->name }}</span>
                                            @if ($shortcut->isUrlShortcut())
                                                <div class="text-xs text-base-content/60">
                                                    {{ Str::limit($shortcut->windows_args ?? ($shortcut->linux_args ?? ''), 50) }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @if ($shortcut->isUrlShortcut())
                                            <span class="badge badge-info badge-sm">Site web</span>
                                        @else
                                            <span class="badge badge-success badge-sm">Application</span>
                                        @endif
                                        @if ($shortcut->is_global)
                                            <span class="badge badge-warning badge-sm" title="Géré par ControlHub">
                                                <i class="fa-solid fa-lock text-xs mr-1"></i>Global
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span class="text-sm">{{ $placeLabels[$shortcut->place ?? 'desktop'] ?? 'Bureau' }}</span>
                                </td>
                                <td>
                                    @php
                                        $wgCount = $shortcut->workstationGroups->count();
                                        $wsCount = $shortcut->workstations->count();
                                        $ugCount = count($shortcut->ad_user_groups ?? []);
                                        $uCount = count($shortcut->ad_users ?? []);
                                        $totalTargets = $wgCount + $wsCount + $ugCount + $uCount;
                                    @endphp

                                    @if ($totalTargets === 0)
                                        <span class="text-sm text-base-content/40">Aucune cible</span>
                                    @elseif ($totalTargets <= 3)
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($shortcut->workstationGroups as $wg)
                                                <span class="badge badge-sm {{ $wg->is_physical ? 'badge-warning' : 'badge-primary' }}">
                                                    <i class="fa-solid {{ $wg->is_physical ? 'fa-door-open' : 'fa-layer-group' }} text-xs mr-1"></i>{{ $wg->display_name ?? $wg->name }}
                                                </span>
                                            @endforeach
                                            @foreach ($shortcut->workstations as $ws)
                                                <span class="badge badge-sm badge-info">
                                                    <i class="fa-solid fa-computer text-xs mr-1"></i>{{ $ws->name }}
                                                </span>
                                            @endforeach
                                            @foreach ($shortcut->ad_user_groups ?? [] as $cn)
                                                <span class="badge badge-sm badge-secondary">
                                                    <i class="fa-solid fa-users text-xs mr-1"></i>{{ $cn }}
                                                </span>
                                            @endforeach
                                            @foreach ($shortcut->ad_users ?? [] as $cn)
                                                <span class="badge badge-sm badge-accent">
                                                    <i class="fa-solid fa-user text-xs mr-1"></i>{{ $cn }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <x-atoms.tooltip position="top">
                                            <x-slot name="trigger">
                                                <div class="cursor-pointer space-y-0.5 text-xs">
                                                    @if ($wgCount > 0)
                                                        <div class="flex items-center gap-1">
                                                            <i class="fa-solid fa-layer-group text-primary"></i>
                                                            <span>{{ $wgCount }} groupe(s)</span>
                                                        </div>
                                                    @endif
                                                    @if ($wsCount > 0)
                                                        <div class="flex items-center gap-1">
                                                            <i class="fa-solid fa-computer text-info"></i>
                                                            <span>{{ $wsCount }} poste(s)</span>
                                                        </div>
                                                    @endif
                                                    @if ($ugCount > 0)
                                                        <div class="flex items-center gap-1">
                                                            <i class="fa-solid fa-users text-secondary"></i>
                                                            <span>{{ $ugCount }} grp. AD</span>
                                                        </div>
                                                    @endif
                                                    @if ($uCount > 0)
                                                        <div class="flex items-center gap-1">
                                                            <i class="fa-solid fa-user text-accent"></i>
                                                            <span>{{ $uCount }} util. AD</span>
                                                        </div>
                                                    @endif
                                                </div>
                                            </x-slot>
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($shortcut->workstationGroups as $wg)
                                                    <span class="badge badge-sm {{ $wg->is_physical ? 'badge-warning' : 'badge-primary' }}">
                                                        <i class="fa-solid {{ $wg->is_physical ? 'fa-door-open' : 'fa-layer-group' }} text-xs mr-1"></i>{{ $wg->display_name ?? $wg->name }}
                                                    </span>
                                                @endforeach
                                                @foreach ($shortcut->workstations as $ws)
                                                    <span class="badge badge-sm badge-info">
                                                        <i class="fa-solid fa-computer text-xs mr-1"></i>{{ $ws->name }}
                                                    </span>
                                                @endforeach
                                                @foreach ($shortcut->ad_user_groups ?? [] as $cn)
                                                    <span class="badge badge-sm badge-secondary">
                                                        <i class="fa-solid fa-users text-xs mr-1"></i>{{ $cn }}
                                                    </span>
                                                @endforeach
                                                @foreach ($shortcut->ad_users ?? [] as $cn)
                                                    <span class="badge badge-sm badge-accent">
                                                        <i class="fa-solid fa-user text-xs mr-1"></i>{{ $cn }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </x-atoms.tooltip>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-8 text-base-content/60">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-4xl mb-2 opacity-30"></i>
                                    <p>Aucun raccourci trouvé</p>
                                    @if ($hasFilters)
                                        <button type="button" class="btn btn-ghost btn-sm mt-2"
                                            wire:click="resetFilters">
                                            Réinitialiser les filtres
                                        </button>
                                    @else
                                        <a href="{{ route('app.shortcuts.new') }}" class="btn btn-primary btn-sm mt-2">
                                            <i class="fa-solid fa-plus"></i>
                                            Nouveau raccourci
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if ($pagination && count($shortcuts) > 0)
                <x-molecules.pagination :currentPage="$pagination['current_page']" :lastPage="$pagination['last_page']" :total="$pagination['total']" :from="$pagination['from']"
                    :to="$pagination['to']" :perPage="$perPage" :allowedPerPage="$allowedPerPage" onPageChange="goToPage"
                    perPageModel="perPage" itemLabel="raccourci" itemLabelPlural="raccourcis" />
            @endif
        </div>
    </div>

    <!-- Actions groupées -->
    @php
        $canBulkAssign = \Illuminate\Support\Facades\Gate::allows('update-shortcut');
        $canBulkDelete = \Illuminate\Support\Facades\Gate::allows('bulkDelete-shortcut');
    @endphp

    @if (count($selectedShortcuts) > 0 && ($canBulkAssign || $canBulkDelete))
        <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
            <div class="card bg-base-100 shadow-xl border border-base-300">
                <div class="card-body py-3 px-4 flex-row items-center gap-4">
                    <span class="text-sm font-medium">
                        {{ count($selectedShortcuts) }} raccourci(s) sélectionné(s)
                    </span>
                    <div class="divider divider-horizontal m-0"></div>
                    <div class="dropdown dropdown-top dropdown-end">
                        <div tabindex="0" role="button" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-bolt"></i>
                            Actions
                            <i class="fa-solid fa-chevron-down text-xs"></i>
                        </div>
                        <ul tabindex="0"
                            class="dropdown-content menu bg-base-100 rounded-box z-[1] w-56 p-2 shadow-lg border border-base-300">
                            @if ($canBulkAssign)
                                <li>
                                    <button type="button"
                                        @click="$wire.openBulkAssignmentModal(); document.activeElement.blur();">
                                        <i class="fa-solid fa-bullseye"></i>
                                        Assigner des cibles
                                    </button>
                                </li>
                            @endif
                            @if ($canBulkDelete)
                                <li @class(['border-t border-base-300 mt-1 pt-1' => $canBulkAssign])>
                                    <button type="button" class="text-error" wire:click="bulkDelete"
                                        wire:confirm="Êtes-vous sûr de vouloir supprimer les raccourcis sélectionnés ?">
                                        <i class="fa-regular fa-trash-can"></i>
                                        Supprimer
                                    </button>
                                </li>
                            @endif
                        </ul>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('selectedShortcuts', [])">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Modale d'assignation réutilisée depuis la page raccourci -->
    <livewire:organisms.shortcut-assignment-modal />
</div>
