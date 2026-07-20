<div class="card bg-base-100 shadow-sm border border-base-300 h-full flex flex-col overflow-hidden">
    <div class="card-body p-0 flex flex-col flex-1 min-h-0">
        <!-- En-tête avec recherche et bouton ajout -->
        <div class="flex-shrink-0 flex justify-between items-center p-4 border-b border-base-300">
            <div class="form-control w-64">
                <input type="text" wire:model.live.debounce.300ms="workstationSearch"
                    class="input input-bordered input-sm" placeholder="Rechercher un poste..." />
            </div>
            <button type="button" class="btn btn-primary btn-sm" wire:click="openAddWorkstationsModal">
                <i class="fa-solid fa-plus mr-1"></i>
                Ajouter des postes
            </button>
        </div>

        <!-- Tableau des postes -->
        <div class="overflow-auto flex-1 min-h-0">
            <table class="table table-zebra table-pin-rows">
                <thead>
                    <tr>
                        <th>Poste</th>
                        <th>Système</th>
                        <th>Adresse IP</th>
                        <th>Dernière connexion</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->profileWorkstations as $workstation)
                        <tr wire:key="ws-{{ $workstation->id }}">
                            <td>
                                <div class="flex items-center gap-3">
                                        <i class="fa-solid fa-computer"></i>
                                <div>
                                        <span class="font-medium">{{ $workstation->name }}</span>
                                        @if ($workstation->ad_guid)
                                            <p class="text-xs text-base-content/60">
                                                {{ Str::limit($workstation->ad_guid, 20) }}
                                            </p>
                                        @endif
                                </div>
                            </td>
                            <td>
                                @if ($workstation->os)
                                    <span class="badge badge-ghost badge-sm">{{ $workstation->os }}</span>
                                @else
                                    <span class="text-base-content/40">-</span>
                                @endif
                            </td>
                            <td>
                                @if ($workstation->ip)
                                    <code
                                        class="text-xs bg-base-200 px-2 py-0.5 rounded">{{ $workstation->ip }}</code>
                                @else
                                    <span class="text-base-content/40">-</span>
                                @endif
                            </td>
                            <td>
                                @if ($workstation->date_rapport_poste)
                                    <span
                                        class="text-sm">{{ \Carbon\Carbon::parse($workstation->date_rapport_poste)->diffForHumans() }}</span>
                                @else
                                    <span class="text-base-content/40">Jamais</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-ghost btn-xs text-error"
                                    wire:click="removeWorkstation({{ $workstation->id }})"
                                    wire:confirm="Retirer ce poste du profil ?">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/60">
                                <i class="fa-solid fa-computer text-4xl mb-2 opacity-30"></i>
                                <p>Aucun poste dans ce profil</p>
                                <button type="button" class="btn btn-primary btn-sm mt-4"
                                    wire:click="openAddWorkstationsModal">
                                    <i class="fa-solid fa-plus mr-1"></i>
                                    Ajouter des postes
                                </button>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if (
            $this->profileWorkstations instanceof \Illuminate\Pagination\LengthAwarePaginator &&
                $this->profileWorkstations->hasPages())
            <div class="border-t border-base-300 p-4">
                {{ $this->profileWorkstations->links() }}
            </div>
        @endif
    </div>
</div>
