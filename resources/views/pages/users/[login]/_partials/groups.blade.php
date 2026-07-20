<!-- Card: Groupes (équipes) -->
<div
    class="bg-gradient-to-br from-info/10 via-info/5 to-primary/10 rounded-3xl border border-base-300 shadow-xl backdrop-blur-sm overflow-hidden flex flex-col h-full">
    <div class="p-6 flex flex-col flex-1 min-h-0">
        <div class="flex items-center gap-4 mb-6">
            <div
                class="w-10 h-10 bg-gradient-to-br from-info to-info/80 rounded-xl flex items-center justify-center shadow-lg ring-4 ring-info/20">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                    </path>
                </svg>
            </div>
            <div>
                <h2 class="text-xl font-bold text-base-content">Groupes</h2>
                <p class="text-sm text-base-content/60">Équipes et classes</p>
            </div>
        </div>

        @if (count($listCurrentGroups) > 0)
            <div class="space-y-2 flex-1 overflow-y-auto pr-1 min-h-0 max-h-72">
                @foreach ($listCurrentGroups as $group)
                    @php
                        $groupLabel = str_replace('_', ' ', $group);
                    @endphp
                    <div class="flex group items-center justify-between gap-3 p-2 bg-info/5 rounded-lg border border-info/10 w-full h-8 text-left hover:bg-info/10 transition-colors">
                        <span class="text-sm">{{ $groupLabel }}</span>
                        @can('update-user')
                            <button type="button" title="Retirer du groupe"
                                wire:click="removeFromGroup('{{ addslashes($group) }}')"
                                wire:confirm="Retirer {{ $groupLabel }} de cet utilisateur ?"
                                class="btn btn-xs btn-error hidden group-hover:flex items-center justify-center rounded-full size-6 text-base hover:bg-error/10 hover:text-error">
                                <i class="fas fa-trash text-xs"></i>
                            </button>
                        @endcan
                    </div>
                @endforeach
            </div>
            <div class="mt-3 text-xs text-base-content/50">
                {{ count($listCurrentGroups) }} groupe(s)
            </div>
        @else
            <div class="text-center py-6">
                <svg class="w-10 h-10 mx-auto mb-2 text-base-content/20" fill="none" stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                    </path>
                </svg>
                <p class="text-base-content/40 text-sm">Aucun groupe</p>
            </div>
        @endif

        @can('update-user')
            <div class="divider my-3"></div>
            <button type="button" @click="Livewire.dispatch('open-groups-drawer', { users: ['{{ $user->login }}'] })"
                class="btn btn-sm btn-outline btn-info w-full">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6">
                    </path>
                </svg>
                Gérer les groupes
            </button>
        @endcan
    </div>
</div>
