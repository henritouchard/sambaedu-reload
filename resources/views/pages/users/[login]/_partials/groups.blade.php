<!-- Card: Groupes (équipes) -->
<div
    class="bg-gradient-to-br from-info/10 via-info/5 to-primary/10 rounded-3xl border border-base-200/50 shadow-xl backdrop-blur-sm overflow-hidden flex flex-col h-full">
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
                        // Extraire le CN du groupe
                        $groupCn = $group;
                        $groupDn = '';
                        $groupType = 'Groupe';

                        // Si c'est un DN complet, extraire le CN et déterminer le type
if (str_contains($group, ',')) {
    $groupDn = $group;
    if (preg_match('/^CN=([^,]+),/', $group, $matches)) {
        $groupCn = $matches[1];
    }
    // Déterminer le type de groupe selon la branche
    if (preg_match('/OU=([^,]+)/i', $group, $ouMatch)) {
        $ou = strtolower($ouMatch[1]);
        $groupType = match (true) {
            str_contains($ou, 'classe') => 'Classe',
            str_contains($ou, 'equipe') => 'Équipe pédagogique',
            str_contains($ou, 'matiere') => 'Matière',
            str_contains($ou, 'niveau') => 'Niveau',
            str_contains($ou, 'option') => 'Option',
            str_contains($ou, 'groupe') => 'Groupe de travail',
            default => 'Groupe',
        };
    }
} elseif (function_exists('ldap_dn2cn')) {
    $groupCn = ldap_dn2cn($group);
}

// Formater le nom pour l'affichage (remplacer _ par espaces)
                        $groupLabel = str_replace('_', ' ', $groupCn);
                    @endphp
                    <div x-data="{ open: false }" class="relative">
                        <button type="button" @click="open = !open" @click.outside="open = false"
                            class="flex items-start gap-3 p-2 bg-info/5 rounded-lg border border-info/10 w-full text-left hover:bg-info/10 transition-colors cursor-pointer">
                            <svg class="w-4 h-4 text-info flex-shrink-0 mt-0.5" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                                </path>
                            </svg>
                            <span class="text-sm">{{ $groupLabel }}</span>
                            <svg class="w-3 h-3 text-base-content/40 ml-auto flex-shrink-0 mt-1" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </button>
                        <!-- Popover -->
                        <div x-show="open" x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                            class="absolute z-50 left-0 right-0 mt-1 p-3 bg-base-100 rounded-lg shadow-xl border border-base-300"
                            @click.outside="open = false">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="badge badge-sm badge-info">{{ $groupType }}</span>
                            </div>
                            <div class="mb-2">
                                <code
                                    class="text-xs bg-base-200 px-2 py-1 rounded font-mono block break-all">{{ $groupCn }}</code>
                            </div>
                            @if ($groupDn)
                                <p class="text-xs text-base-content/50 break-all">{{ $groupDn }}</p>
                            @endif
                        </div>
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
