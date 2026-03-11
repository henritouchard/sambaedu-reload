<div class="flex flex-col gap-4 h-full">
    <!-- Barre d'actions -->
    <div class="flex-shrink-0 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <div class="form-control w-64">
                <input type="text" wire:model.live.debounce.300ms="groupSearch" class="input input-bordered input-sm"
                    placeholder="Rechercher un groupe..." />
            </div>
            <label class="label cursor-pointer gap-2">
                <input type="checkbox" wire:model.live="showInheritedGroups" class="toggle toggle-sm toggle-primary" />
                <span class="label-text text-sm">Afficher les groupes hérités</span>
            </label>
        </div>
        <button type="button" class="btn btn-primary btn-sm" wire:click="openAddGroupsModal">
            <i class="fa-solid fa-plus mr-1"></i>
            Ajouter des groupes
        </button>
    </div>

    <!-- Liste des groupes -->
    <div class="card bg-base-100 shadow-sm border border-base-200 flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="card-body p-0 flex flex-col flex-1 min-h-0">
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th>Groupe</th>
                            <th>Description</th>
                            <th class="text-center">Postes</th>
                            <th class="text-center">Sync AD</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->profileGroups as $group)
                            <tr wire:key="profile-group-{{ $group->id }}">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="avatar placeholder">
                                            <div class="bg-secondary/10 text-secondary rounded w-8 h-8">
                                                <i class="fa-solid fa-folder-tree text-sm"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <a href="{{ route('app.parc.groups.show', $group->id) }}"
                                                class="font-medium hover:text-primary">
                                                {{ $group->name }}
                                            </a>
                                            @if ($group->display_name && $group->display_name !== $group->name)
                                                <span class="text-xs text-base-content/60 block">
                                                    {{ $group->display_name }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-sm text-base-content/70 line-clamp-1">
                                        {{ $group->description ?? '-' }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-ghost badge-sm">
                                        {{ $group->workstations()->count() }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    @if ($group->isSyncedWithAd())
                                        <span class="badge badge-success badge-sm">
                                            {{ $group->getAdStatusLabel() }}
                                        </span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">Non</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <button type="button" class="btn btn-ghost btn-xs text-error"
                                        wire:click="removeGroup({{ $group->id }})"
                                        wire:confirm="Retirer ce groupe du profil ?" title="Retirer">
                                        <i class="fa-solid fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            @if (!$showInheritedGroups || $this->inheritedGroups->isEmpty())
                                <tr>
                                    <td colspan="5" class="text-center py-8 text-base-content/60">
                                        <i class="fa-solid fa-folder-tree text-4xl mb-2 opacity-30"></i>
                                        <p>Aucun groupe lié à ce profil</p>
                                        <button type="button" class="btn btn-primary btn-sm mt-2"
                                            wire:click="openAddGroupsModal">
                                            <i class="fa-solid fa-plus mr-1"></i>
                                            Ajouter des groupes
                                        </button>
                                    </td>
                                </tr>
                            @endif
                        @endforelse

                        {{-- Groupes hérités (descendants) --}}
                        @if ($showInheritedGroups && $this->inheritedGroups->isNotEmpty())
                            @foreach ($this->inheritedGroups as $group)
                                <tr wire:key="inherited-group-{{ $group->id }}" class="opacity-60 bg-base-200/30">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <div class="avatar placeholder">
                                                <div class="bg-base-300 text-base-content/50 rounded w-8 h-8">
                                                    <i class="fa-solid fa-folder text-sm"></i>
                                                </div>
                                            </div>
                                            <div>
                                                <a href="{{ route('app.parc.groups.show', $group->id) }}"
                                                    class="font-medium hover:text-primary">
                                                    {{ $group->name }}
                                                </a>
                                                <span class="text-xs text-base-content/50 block">
                                                    <i class="fa-solid fa-arrow-turn-down-right mr-1"></i>
                                                    Hérité de {{ $group->parent?->name ?? 'parent' }}
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-sm text-base-content/50 line-clamp-1">
                                            {{ $group->description ?? '-' }}
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-ghost badge-sm">
                                            {{ $group->workstations()->count() }}
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        @if ($group->isSyncedWithAd())
                                            <span class="badge badge-success badge-sm">
                                                {{ $group->getAdStatusLabel() }}
                                            </span>
                                        @else
                                            <span class="badge badge-ghost badge-sm">Non</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <span class="text-xs text-base-content/40 italic">Hérité</span>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>

            @if ($this->profileGroups instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->profileGroups->hasPages())
                <div class="border-t border-base-200 p-4">
                    {{ $this->profileGroups->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
