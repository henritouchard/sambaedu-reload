<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Devrabiul\ToastMagic\Facades\ToastMagic;

new class extends Component {
    public bool $isOpen = false;
    public string $activeTab = 'groupes';
    public bool $resetGroups = false;
    public bool $removeMode = false;
    public string $search = '';
    public $availableGroups = [];
    public array $selectedGroups = [];
    public array $selectedUsers = [];

    public function mount($availableGroups = [])
    {
        $this->availableGroups = $availableGroups;
    }

    // Ouvrir le drawer avec les utilisateurs sélectionnés
    #[On('open-rights-drawer')]
    public function open(array $users = [])
    {
        $this->selectedUsers = $users;
        $this->js("console.log(" . json_encode($this->selectedUsers) . ")");

        $this->isOpen = true;
        $this->reset(['search', 'selectedGroups', 'resetGroups', 'removeMode', 'activeTab']);
    }

    // Fermer le drawer
    public function close()
    {
        $this->isOpen = false;
    }

    // Changer d'onglet
    public function switchTab(string $tab)
    {
        $this->activeTab = $tab;
    }

    // Toggle un groupe
    public function toggleGroup(string $groupCn)
    {
        if (in_array($groupCn, $this->selectedGroups)) {
            $this->selectedGroups = array_values(array_diff($this->selectedGroups, [$groupCn]));
        } else {
            $this->selectedGroups[] = $groupCn;
        }
    }

    // Nombre de groupes sélectionnés
    #[Computed]
    public function selectedCount(): int
    {
        return count($this->selectedGroups);
    }

    // Groupes filtrés
    #[Computed]
    public function filteredGroups()
    {
        // S'assurer que availableGroups est un tableau (peut être null avant mount())
        $groups = $this->availableGroups ?? [];

        if (empty($this->search)) {
            return collect($groups);
        }

        $search = strtolower($this->search);

        return collect($groups)->filter(function ($group) use ($search) {
            return str_contains(strtolower($group['name'] ?? ''), $search) || str_contains(strtolower($group['cn'] ?? ''), $search) || str_contains(strtolower($group['description'] ?? ''), $search);
        });
    }
};
?>


<div>
    <!-- Drawer pour la gestion des droits -->
    <div class="drawer drawer-end z-[60]" x-data="{ open: @entangle('isOpen') }" x-init="$wire.on('console-log', (event) => {
        console.log('📝 [LIVEWIRE]:', event);
    })">
        <input type="checkbox" class="drawer-toggle" :checked="open" />
        <div class="drawer-side z-[60]" x-show="open" x-cloak>
            <label class="drawer-overlay" wire:click="close"></label>
            <div class="bg-base-200 h-screen w-1/3 flex flex-col z-[60]">
                <!-- Header du drawer -->
                <div class="bg-base-100 p-4 border-b border-base-300 shrink-0">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Gestion des droits</h3>
                        <button wire:click="close" class="btn btn-sm btn-circle btn-ghost">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <!-- Tabs -->
                <div role="tablist" class="tabs tabs-border px-4 pt-2 shrink-0">
                    <a role="tab" class="tab {{ $activeTab === 'groupes' ? 'tab-active' : '' }}"
                        wire:click="switchTab('groupes')">Groupes</a>
                    <a role="tab" class="tab {{ $activeTab === 'individuels' ? 'tab-active' : '' }}"
                        wire:click="switchTab('individuels')">Individuels</a>
                </div>

                <!-- Contenu Groupes -->
                <form action="{{ route('app.users.bulk-assign-groups.store') }}" method="POST"
                    class="flex-1 overflow-hidden flex flex-col p-4 {{ $activeTab !== 'groupes' ? 'hidden' : '' }}">
                    
                    @csrf
                    
                    <!-- Champs cachés pour les utilisateurs sélectionnés -->
                    @foreach($selectedUsers as $user)
                        <input type="hidden" name="users[]" value="{{ $user }}" />
                    @endforeach
                    
                    @foreach($selectedGroups as $group)
                        <input type="hidden" name="groups[]" value="{{ $group }}" />
                    @endforeach
                    {{-- <input type="hidden" name="removeMode" value="{{ $removeMode ? true : false }}" />
                    <input type="hidden" name="resetMode" value="{{ $resetGroups ? true : false }}" /> --}}
                    <!-- Option de réinitialisation -->
                    <div class="flex gap-3 shrink-0 mb-4">
                        <input type="checkbox" name="resetMode" value="1" {{ $resetGroups ? 'checked' : '' }}
                            class="toggle border-primary checked:border-error/50 checked:bg-error/50 checked:text-error" />
                        <div class="flex-1">
                            <div class="font-medium">Réinitialiser avec ces groupes</div>
                            @if ($resetGroups)
                                <div class="text-error">
                                    Les utilisateurs se verront supprimer tous les groupes existants et attribuer ceux
                                    que vous avez sélectionnés
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Mode ajouter/retirer -->
                    <div class="flex gap-3 shrink-0 mb-4">
                        <input type="checkbox" name="removeMode" value="1" {{ $removeMode ? 'checked' : '' }}
                            class="toggle border-primary checked:border-error/50 checked:bg-error/50" />
                        <div class="flex-1">
                            <div class="font-medium">
                                {{ $removeMode ? 'Retirer les groupes sélectionnés' : 'Ajouter les groupes sélectionnés' }}
                            </div>
                        </div>
                    </div>

                    <!-- Filtre des groupes -->
                    <div class="mb-2 shrink-0">
                        <label class="input w-full">
                            <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                            <input type="text" wire:model.live.debounce.300ms="search"
                                placeholder="Rechercher un groupe" class="grow" />
                            @if ($search)
                                <button type="button" wire:click="$set('search', '')" class="btn btn-ghost btn-xs btn-circle">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            @endif
                        </label>
                    </div>

                    <!-- Sélection des groupes -->
                    <div class="flex-1 overflow-y-auto overflow-x-hidden min-h-0 mb-4 border rounded-lg">
                        @if (count($this->filteredGroups) > 0)
                            <div>
                                @foreach ($this->filteredGroups as $group)
                                    <div class="group-item" wire:key="group-{{ $group['cn'] }}">
                                        <label
                                            class="flex items-center gap-3 cursor-pointer hover:bg-base-100 p-2 rounded-lg {{ !empty($group['description']) ? 'tooltip tooltip-right' : '' }}"
                                            @if (!empty($group['description'])) title="{{ $group['description'] }}" @endif>
                                            <input type="checkbox" wire:click="toggleGroup('{{ $group['cn'] }}')"
                                                @checked(in_array($group['cn'], $selectedGroups)) class="checkbox checkbox-primary" />
                                            <div class="flex-1">
                                                <div class="font-medium">{{ $group['name'] }}</div>
                                            </div>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="alert alert-warning">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 15.5c-.77.833.192 2.5 1.732 2.5z">
                                    </path>
                                </svg>
                                <span>{{ $search ? 'Aucun groupe trouvé' : 'Aucun groupe disponible' }}</span>
                            </div>
                        @endif
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-between items-center shrink-0 pt-2 border-t border-base-300">
                        <button type="button" class="btn btn-ghost" wire:click="close">
                            Annuler
                        </button>
                        <button type="submit" class="btn {{ $removeMode ? 'btn-error' : 'btn-primary' }}"
                             @disabled(count($availableGroups) === 0)>
                            <i class="fa-solid {{ $removeMode ? 'fa-minus' : 'fa-check' }}"></i>
                            {{ $removeMode ? 'Retirer' : 'Assigner' }}
                            <span class="font-bold text-lg">{{ $this->selectedCount }}</span>
                            groupe(s)
                        </button>
                    </div>
                </form>

                <!-- Contenu Individuels -->
                <div
                    class="flex-1 overflow-hidden flex flex-col p-4 {{ $activeTab !== 'individuels' ? 'hidden' : '' }}">
                    <p class="text-base-content/60">Droits individuels - Fonctionnalité à venir</p>
                </div>
            </div>
        </div>
    </div>
</div>
