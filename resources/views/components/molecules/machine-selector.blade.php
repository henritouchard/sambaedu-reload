@props([
    'machines' => [],
    'maxHeight' => '400px',
])

@php
    // Charger les machines via le modèle si non fournies
    $machinesList = $machines;
    if (empty($machinesList)) {
        $machinesList = \App\Models\Workstation::active()
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(
                fn($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'ip' => $m->ip,
                    'os' => $m->os,
                ],
            )
            ->toArray();
    }
@endphp

<div x-data="{
    search: '',
    selectedIds: @entangle($attributes->wire('model')).live,
    machines: {{ Js::from($machinesList) }},

    toggleMachine(id) {
        const index = this.selectedIds.indexOf(id);
        if (index === -1) {
            this.selectedIds.push(id);
        } else {
            this.selectedIds.splice(index, 1);
        }
    },

    isSelected(id) {
        return this.selectedIds.includes(id);
    },

    selectAll() {
        this.selectedIds = this.filteredMachines.map(m => m.id);
    },

    deselectAll() {
        this.selectedIds = [];
    },

    get filteredMachines() {
        if (!this.search) return this.machines;
        const s = this.search.toLowerCase();
        return this.machines.filter(m =>
            m.name.toLowerCase().includes(s) ||
            (m.ip && m.ip.includes(s)) ||
            (m.os && m.os.toLowerCase().includes(s))
        );
    },

    get selectedCount() {
        return this.selectedIds.length;
    }
}" class="flex flex-col">

    <!-- Barre de recherche et actions -->
    <div class="flex items-center gap-2 mb-3">
        <div class="form-control flex-1">
            <div class="input-group">
                <span class="bg-base-200">
                    <i class="fa-solid fa-search text-base-content/50"></i>
                </span>
                <input type="text" x-model.debounce.300ms="search" placeholder="Rechercher une machine..."
                    class="input input-bordered input-sm w-full" />
            </div>
        </div>
        <div class="flex gap-1">
            <button type="button" class="btn btn-ghost btn-xs" @click="selectAll()" title="Tout sélectionner">
                <i class="fa-solid fa-check-double"></i>
            </button>
            <button type="button" class="btn btn-ghost btn-xs" @click="deselectAll()" title="Tout désélectionner">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>

    <!-- Compteur de sélection -->
    <div class="text-sm text-base-content/60 mb-2" x-show="selectedCount > 0">
        <span x-text="selectedCount"></span> machine(s) sélectionnée(s)
    </div>

    <!-- Liste des machines -->
    <div class="border border-base-300 rounded-lg overflow-y-auto" style="max-height: {{ $maxHeight }}">
        <!-- Empty state -->
        <template x-if="filteredMachines.length === 0">
            <div class="flex flex-col items-center justify-center py-8 text-base-content/50">
                <i class="fa-solid fa-desktop text-3xl mb-2"></i>
                <p x-show="search">Aucune machine ne correspond à la recherche</p>
                <p x-show="!search">Aucune machine disponible</p>
            </div>
        </template>

        <!-- Machine list -->
        <template x-if="filteredMachines.length > 0">
            <ul class="divide-y divide-base-200">
                <template x-for="machine in filteredMachines" :key="machine.id">
                    <li class="hover:bg-base-100 transition-colors cursor-pointer"
                        :class="{ 'bg-primary/5': isSelected(machine.id) }" @click="toggleMachine(machine.id)">
                        <div class="flex items-center gap-3 p-3">
                            <input type="checkbox" class="checkbox checkbox-sm checkbox-primary"
                                :checked="isSelected(machine.id)" @click.stop="toggleMachine(machine.id)" />
                            <div class="w-8 h-8 rounded-lg bg-base-200 flex items-center justify-center">
                                <i class="fa-solid fa-desktop text-base-content/50 text-sm"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium truncate" x-text="machine.name"></div>
                                <div class="text-xs text-base-content/50 flex gap-2">
                                    <span x-show="machine.ip" x-text="machine.ip"></span>
                                    <span x-show="machine.os" class="badge badge-ghost badge-xs"
                                        x-text="machine.os"></span>
                                </div>
                            </div>
                            <div x-show="isSelected(machine.id)">
                                <i class="fa-solid fa-check text-primary"></i>
                            </div>
                        </div>
                    </li>
                </template>
            </ul>
        </template>
    </div>
</div>
