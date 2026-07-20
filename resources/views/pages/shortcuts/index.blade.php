<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use App\Models\Shortcut;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use App\Components\Traits\WithToasts;

new #[Title('Raccourcis - Instance SE4FS')] class extends Component {
    use WithToasts;

    // Propriétés Livewire réactives - synchronisées avec l'URL
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

    // Sélection bulk
    public array $selectedShortcuts = [];

    // Données
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

            // Filtre recherche
            if (!empty($this->search)) {
                $query->search($this->search);
            }

            // Filtre par type
            if ($this->type === 'url') {
                $query->whereNotNull('windows_args')->where('windows_args', 'LIKE', 'http%');
            } elseif ($this->type === 'app') {
                $query->where(function ($q) {
                    $q->whereNull('windows_args')
                      ->orWhere('windows_args', 'NOT LIKE', 'http%');
                });
            }

            // Filtre par emplacement
            if ($this->place !== 'all') {
                $query->byPlace($this->place);
            }

            $this->totalShortcuts = $query->count();

            // Pagination
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
            Log::error('ShortcutsPage loadShortcuts error: ' . $e->getMessage());
            $this->shortcuts = [];
            $this->pagination = null;
            $this->totalShortcuts = 0;
        }
    }

    // Pagination
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

    // Actions groupées
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
            Log::error('ShortcutsPage bulkDelete error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la suppression des raccourcis');
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
            Log::error('ShortcutsPage deleteShortcut error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la suppression du raccourci');
        }
    }

    // Réinitialisation des filtres
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

    $emptyStateMessage = $hasFilters
        ? 'Aucun raccourci ne correspond aux critères de recherche.'
        : 'Il n\'y a aucun raccourci dans le système.';

    $placeLabels = [
        'desktop' => 'Bureau',
        'startup' => 'Démarrage auto',
        'taskbar' => 'Barre des tâches',
    ];
@endphp

<x-organisms.page title="Gestion des raccourcis" :scrollable="false"
    description="Recherchez, ajoutez, modifiez et supprimez des raccourcis">

    <x-slot:actions>
        <div class="flex gap-2 items-center">
            {{-- Breadcrumb de retour GPO (Story 16.3a, AC4.2) — affiché uniquement si ?from_gpo présent --}}
            <x-molecules.gpo-back-link />
            @can('create-shortcut')
                <a href="{{ route('app.shortcuts.new') }}" class="btn highlight btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Nouveau raccourci
                </a>
            @endcan
        </div>
    </x-slot:actions>

    <!-- Liste des raccourcis -->
    <div id="shortcuts-list" class="h-full">
        <div class="flex flex-col h-full">
            <!-- Barre de recherche et filtres -->
            <div class="space-y-3">
                <!-- Champ de recherche -->
                <div class="flex-1 min-w-48">
                    <x-atoms.searchInput wire:model.live.debounce.500ms="search" id="searchInput"
                        placeholder="Rechercher (par nom, propriétaire...)" icon="fa-magnifying-glass" class="w-full" />
                </div>

                <div class="flex flex-wrap gap-4 items-end">

                    <!-- Filtre par type -->
                    <div class="form-control min-w-40">
                        <select wire:model.live="type" class="select select-bordered">
                            @foreach ($filters['type'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Filtre par emplacement -->
                    <div class="form-control">
                        <select wire:model.live="place" class="select select-bordered">
                            @foreach ($filters['place'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Bouton reset -->
                    <button type="button" class="btn btn-circle btn-error" title="Effacer tous les filtres"
                        wire:click="resetFilters" @disabled(!$hasFilters)>
                        <i class="fa-solid fa-filter-circle-xmark"></i>
                    </button>
                </div>

                <!-- Badges des filtres actifs -->
                @if ($hasFilters)
                    <div id="activeFiltersBadges" class="flex flex-wrap gap-2">
                        @if (!empty($search))
                            <div class="badge badge-primary gap-2">
                                <span class="text-xs opacity-70">Recherche:</span>
                                <span>{{ $search }}</span>
                            </div>
                        @endif
                        @if ($type !== 'all')
                            <div class="badge badge-primary gap-2">
                                <span class="text-xs opacity-70">Type:</span>
                                <span>{{ $filters['type'][$type] ?? $type }}</span>
                            </div>
                        @endif
                        @if ($place !== 'all')
                            <div class="badge badge-primary gap-2">
                                <span class="text-xs opacity-70">Emplacement:</span>
                                <span>{{ $filters['place'][$place] ?? $place }}</span>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            @if (count($shortcuts) > 0)
                <!-- Actions rapides -->
                <div class="flex justify-between items-center my-4">
                    <div class="flex items-center gap-4">
                        <span class="text-base-content/70">{{ $totalShortcuts }} raccourci(s) trouvé(s)</span>
                    </div>

                    <!-- Actions groupées -->
                    @can('bulkDelete-shortcut')
                        <div class="flex items-center gap-4" x-show="$wire.selectedShortcuts.length > 0" x-transition>
                            <span class="text-base-content/70">
                                <span x-text="$wire.selectedShortcuts.length"></span> raccourci(s) sélectionné(s)
                            </span>
                            <button type="button" class="btn btn-error btn-sm" wire:click="bulkDelete"
                                wire:confirm="Êtes-vous sûr de vouloir supprimer les raccourcis sélectionnés ?">
                                <i class="fa-regular fa-trash-can"></i>
                                Supprimer
                            </button>
                        </div>
                    @endcan
                </div>

                {{-- Tableau des raccourcis --}}
                <x-organisms.data-table
                    colgroup="<colgroup><col style='width: 3rem'><col style='width: 35%'><col style='width: 15%'><col style='width: 18%'><col style='width: auto'></colgroup>">
                    <x-slot:header>
                        <th>
                            <label>
                                <x-molecules.select-all-checkbox :ids="collect($shortcuts)->pluck('key')" model="selectedShortcuts" />
                            </label>
                        </th>
                        <th>Raccourci</th>
                        <th>Type</th>
                        <th>Emplacement</th>
                        <th>Cibles</th>
                    </x-slot:header>
                    @foreach ($shortcuts as $shortcut)
                        <tr wire:key="shortcut-{{ $shortcut->key }}" class="cursor-pointer"
                            onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.shortcuts.show', $shortcut->key) }}'">
                            <td class="checkbox-cell p-0">
                                <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                    <input type="checkbox" class="checkbox shortcut-checkbox"
                                        wire:model.live="selectedShortcuts" value="{{ $shortcut->key }}">
                                </label>
                            </td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <img src="{{ $this->getShortcutIconUrl($shortcut->name) }}"
                                        alt="{{ $shortcut->name }}" class="w-8 h-8 rounded"
                                        onerror="this.src='/elements/images/system-run.png'">
                                    <div>
                                        <div class="font-bold">{{ $shortcut->name }}</div>
                                        @if ($shortcut->isUrlShortcut())
                                            <div class="text-sm opacity-50">
                                                <a href="{{ $shortcut->windows_args ?? ($shortcut->linux_args ?? '') }}"
                                                    target="_blank" class="link link-primary">
                                                    {{ Str::limit($shortcut->windows_args ?? ($shortcut->linux_args ?? ''), 50) }}
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @if ($shortcut->isUrlShortcut())
                                        <div class="badge badge-info">Site web</div>
                                    @else
                                        <div class="badge badge-success">Application</div>
                                    @endif
                                    @if ($shortcut->is_global)
                                        <div class="badge badge-warning" title="Géré par ControlHub">
                                            <i class="fa-solid fa-lock text-xs mr-1"></i>Global
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span
                                    class="text-sm">{{ $placeLabels[$shortcut->place ?? 'desktop'] ?? 'Bureau' }}</span>
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
                    @endforeach
                </x-organisms.data-table>

                <!-- Pagination -->
                @if ($pagination)
                    <x-molecules.pagination :currentPage="$pagination['current_page']" :lastPage="$pagination['last_page']" :total="$pagination['total']" :from="$pagination['from']"
                        :to="$pagination['to']" :perPage="$perPage" :allowedPerPage="$allowedPerPage" onPageChange="goToPage"
                        perPageModel="perPage" itemLabel="raccourci" itemLabelPlural="raccourcis" />
                @endif
            @else
                <!-- État vide -->
                <div id="shortcuts-list-empty" class="card flex-1 flex flex-col justify-center items-center mt-8">
                    <div class="card-body flex-col justify-center items-center flex-0 py-16">
                        <svg class="w-16 h-16 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1">
                            </path>
                        </svg>
                        <h3 class="text-lg font-semibold mb-2">Aucun raccourci trouvé</h3>
                        <p class="text-base-content/60 text-base mb-6">
                            {{ $emptyStateMessage }}
                        </p>
                        <div class="text-center">
                            @if ($hasFilters)
                                <button type="button" class="btn btn-outline" wire:click="resetFilters">Effacer les
                                    filtres</button>
                            @endif
                            <a href="{{ route('app.shortcuts.new') }}" class="btn highlight btn-primary ml-2">Nouveau
                                raccourci</a>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-organisms.page>
