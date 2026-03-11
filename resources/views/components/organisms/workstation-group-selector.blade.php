<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use App\Models\WorkstationGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

new class extends Component {
    // État du drawer
    public bool $isOpen = false;
    public bool $isLoading = false;

    // Configuration
    public bool $unique = false;
    public string $title = '';
    public string $emptyMessage = 'Aucun groupe disponible';
    public string $buttonLabel = '';
    public string $buttonIcon = 'fa-plus';
    public string $buttonClass = 'btn-primary';
    public bool $showTypeLabel = true;
    public string $drawerId = '';

    // Recherche et sélection
    public string $search = '';
    public int|array|null $selected = null;

    // Groupes disponibles
    public array $availableGroups = [];

    public function mount(string $drawerId = '', bool $unique = false, string $title = '', string $emptyMessage = 'Aucun groupe disponible', string $buttonLabel = '', string $buttonIcon = 'fa-plus', string $buttonClass = 'btn-primary', bool $showTypeLabel = true): void
    {
        $this->drawerId = $drawerId ?: 'wgs-drawer-' . uniqid();
        $this->unique = $unique;
        $this->title = $title ?: ($unique ? 'Sélectionner une salle' : 'Sélectionner des groupes');
        $this->emptyMessage = $emptyMessage;
        $this->buttonLabel = $buttonLabel ?: ($unique ? 'Assigner' : 'Ajouter');
        $this->buttonIcon = $buttonIcon;
        $this->buttonClass = $buttonClass;
        $this->showTypeLabel = $showTypeLabel;
        $this->selected = $unique ? null : [];
    }

    /**
     * Ouvre le drawer avec les groupes fournis
     */
    #[On('open-workstation-group-selector')]
    public function open(string $drawerId, array $groups = []): void
    {
        if ($drawerId !== $this->drawerId) {
            return;
        }

        Log::info('WorkstationGroupSelector open()', ['drawerId' => $drawerId, 'groupsCount' => count($groups)]);

        $this->availableGroups = $groups;
        $this->isOpen = true;
        $this->search = '';
        $this->selected = $this->unique ? null : [];
    }

    /**
     * Ferme le drawer
     */
    public function close(): void
    {
        $this->isOpen = false;
    }

    /**
     * Toggle la sélection d'un groupe
     */
    public function toggleGroup(int $groupId): void
    {
        if ($this->unique) {
            $this->selected = $groupId;
        } else {
            $selected = is_array($this->selected) ? $this->selected : [];
            if (in_array($groupId, $selected)) {
                $this->selected = array_values(array_diff($selected, [$groupId]));
            } else {
                $this->selected = [...$selected, $groupId];
            }
        }
    }

    /**
     * Vérifie si un groupe est sélectionné
     */
    public function isSelected(int $groupId): bool
    {
        if ($this->unique) {
            return $this->selected === $groupId;
        }
        return is_array($this->selected) && in_array($groupId, $this->selected);
    }

    /**
     * Nombre de groupes sélectionnés
     */
    #[Computed]
    public function selectedCount(): int
    {
        if ($this->unique) {
            return $this->selected ? 1 : 0;
        }
        return is_array($this->selected) ? count($this->selected) : 0;
    }

    /**
     * Groupes filtrés par la recherche
     */
    #[Computed]
    public function filteredGroups(): Collection
    {
        $groups = collect($this->availableGroups);

        if (empty($this->search)) {
            return $groups;
        }

        $search = strtolower($this->search);
        return $groups->filter(function ($group) use ($search) {
            $name = is_array($group) ? $group['name'] ?? '' : $group->name ?? '';
            $description = is_array($group) ? $group['description'] ?? '' : $group->description ?? '';
            return str_contains(strtolower($name), $search) || str_contains(strtolower($description), $search);
        });
    }

    /**
     * Confirme la sélection et émet l'événement
     */
    public function confirm(): void
    {
        $this->dispatch('workstation-group-selected', drawerId: $this->drawerId, selected: $this->selected);
        $this->close();
    }

    /**
     * Sélectionne tous les groupes filtrés (mode multiple uniquement)
     */
    public function selectAll(): void
    {
        if ($this->unique) {
            return;
        }

        $this->selected = $this->filteredGroups
            ->map(function ($group) {
                return is_array($group) ? ($group['id'] ?? 0) : ($group->id ?? 0);
            })
            ->values()
            ->toArray();
    }

    /**
     * Désélectionne tous les groupes
     */
    public function deselectAll(): void
    {
        $this->selected = $this->unique ? null : [];
    }
};
?>

<div>
    <!-- Dialog pour la sélection de groupes de postes -->
    <dialog class="modal" x-data="{ open: @entangle('isOpen') }" :class="{ 'modal-open': open }" x-cloak>
        <div class="modal-box max-w-lg max-h-[80vh] flex flex-col p-0">

            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b border-base-300 shrink-0">
                <div>
                    <h3 class="text-lg font-semibold">{{ $title }}</h3>
                    <p class="text-sm text-base-content/60">
                        {{ count($availableGroups) }} groupe(s) disponible(s)
                    </p>
                </div>
                <button wire:click="close" class="btn btn-sm btn-circle btn-ghost">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Contenu principal -->
            <div class="flex-1 overflow-hidden flex flex-col p-4">

                <!-- Barre de recherche -->
                @if (count($availableGroups) > 3)
                    <div class="mb-3 shrink-0">
                        <label class="input input-bordered flex items-center gap-2 w-full">
                            <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                            <input type="text" wire:model.live.debounce.300ms="search"
                                placeholder="Rechercher un groupe..." class="grow" />
                            @if ($search)
                                <button type="button" wire:click="$set('search', '')"
                                    class="btn btn-ghost btn-xs btn-circle">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            @endif
                        </label>
                    </div>
                @endif

                <!-- Actions de sélection rapide (mode multiple) -->
                @if (!$unique)
                    <div class="flex gap-2 mb-3 shrink-0">
                        <button type="button" wire:click="selectAll" class="btn btn-xs btn-outline">
                            <i class="fa-solid fa-check-double"></i>
                            Tout sélectionner
                        </button>
                        <button type="button" wire:click="deselectAll" class="btn btn-xs btn-outline"
                            @disabled($this->selectedCount === 0)>
                            <i class="fa-solid fa-xmark"></i>
                            Tout désélectionner
                        </button>
                        @if ($this->selectedCount > 0)
                            <span class="badge badge-primary">{{ $this->selectedCount }} sélectionné(s)</span>
                        @endif
                    </div>
                @endif

                <!-- Liste des groupes -->
                <div class="flex-1 overflow-y-auto overflow-x-hidden min-h-0 border rounded-lg bg-base-100">
                    @if ($isLoading)
                        <div class="flex items-center justify-center h-32">
                            <span class="loading loading-spinner loading-lg"></span>
                        </div>
                    @elseif (count($this->filteredGroups) > 0)
                        <div class="divide-y divide-base-200">
                            @foreach ($this->filteredGroups as $group)
                                @php
                                    $groupId = is_array($group) ? ($group['id'] ?? 0) : ($group->id ?? 0);
                                    $groupName = is_array($group) ? ($group['name'] ?? '') : ($group->name ?? '');
                                    $groupDescription = is_array($group)
                                        ? $group['description'] ?? ''
                                        : $group->description ?? '';
                                    $isPhysicalRoom = is_array($group)
                                        ? $group['is_physical'] ?? false
                                        : $group->is_physical ?? false;
                                @endphp
                                <label wire:key="group-{{ $groupId }}"
                                    class="flex items-center gap-3 p-3 cursor-pointer hover:bg-base-200 transition-colors">
                                    @if ($unique)
                                        <input type="radio" wire:click="toggleGroup({{ $groupId }})"
                                            @checked($this->isSelected($groupId)) class="radio radio-primary radio-sm" />
                                    @else
                                        <input type="checkbox" wire:click="toggleGroup({{ $groupId }})"
                                            @checked($this->isSelected($groupId))
                                            class="checkbox checkbox-primary checkbox-sm" />
                                    @endif
                                    <div class="flex items-center gap-3 flex-1 min-w-0">
                                        <div
                                            class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0
                                                    {{ $isPhysicalRoom ? 'bg-warning/20' : 'bg-primary/20' }}">
                                            <i
                                                class="fa-solid {{ $isPhysicalRoom ? 'fa-door-open text-warning' : 'fa-layer-group text-primary' }}"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium truncate">{{ $groupName }}</div>
                                            @if ($showTypeLabel)
                                                <div class="text-xs text-base-content/60">
                                                    {{ $isPhysicalRoom ? 'Salle physique' : 'Groupe logique' }}
                                                    @if ($groupDescription)
                                                        • {{ Str::limit($groupDescription, 30) }}
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($this->isSelected($groupId))
                                        <i class="fa-solid fa-check text-primary"></i>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center h-32 text-base-content/60">
                            <i class="fa-solid fa-folder-open text-3xl mb-2"></i>
                            <span>{{ $search ? 'Aucun groupe trouvé' : $emptyMessage }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Footer avec actions -->
            <div class="p-4 border-t border-base-300 shrink-0">
                <div class="flex justify-between items-center">
                    <button type="button" class="btn btn-ghost" wire:click="close">
                        Annuler
                    </button>
                    <button type="button" wire:click="confirm" wire:loading.attr="disabled"
                        class="btn {{ $buttonClass }}" @disabled($this->selectedCount === 0)>
                        <span wire:loading wire:target="confirm" class="loading loading-spinner loading-sm"></span>
                        <i wire:loading.remove wire:target="confirm" class="fa-solid {{ $buttonIcon }}"></i>
                        {{ $buttonLabel }}
                        @if ($this->selectedCount > 0)
                            <span class="badge badge-sm">{{ $this->selectedCount }}</span>
                        @endif
                    </button>
                </div>
            </div>
        </div>

        <!-- Backdrop -->
        <form method="dialog" class="modal-backdrop">
            <button wire:click="close">close</button>
        </form>
    </dialog>
</div>
