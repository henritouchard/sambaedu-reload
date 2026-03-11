@php
    use App\Services\RightsService;

    // Calculer les droits effectifs à partir des groupes de droits
    $rightsService = app(RightsService::class);
    $rightsMask = $rightsService->calculateRights($listCurrentRights, $user->login);
    $effectiveRights = RightsService::getRightDetails($rightsMask);
@endphp

<!-- Card: Droits et permissions -->
<div
    class="bg-gradient-to-br from-warning/10 via-warning/5 to-error/10 rounded-3xl border border-base-200/50 shadow-xl backdrop-blur-sm overflow-hidden flex flex-col h-full">
    <div class="p-6 flex flex-col flex-1 min-h-0">
        <div class="flex items-center gap-4 mb-6">
            <div
                class="w-10 h-10 bg-gradient-to-br from-warning to-warning/80 rounded-xl flex items-center justify-center shadow-lg ring-4 ring-warning/20">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z">
                    </path>
                </svg>
            </div>
            <div>
                <h2 class="text-xl font-bold text-base-content">Permissions</h2>
                <p class="text-sm text-base-content/60">Droits d'administration</p>
            </div>
        </div>

        @if (count($effectiveRights) > 0)
            <div class="space-y-2 flex-1 overflow-y-auto  pr-1 min-h-0 max-h-72">
                @foreach ($effectiveRights as $rightMask => $rightInfo)
                    <div x-data="{ open: false }" class="relative">
                        <button type="button" @click="open = !open" @click.outside="open = false"
                            class="flex items-start gap-3 p-2 bg-warning/5 rounded-lg border border-warning/10 w-full text-left hover:bg-warning/10 transition-colors cursor-pointer">
                            <svg class="w-4 h-4 text-warning flex-shrink-0 mt-0.5" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="text-sm">{{ $rightInfo['label'] }}</span>
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
                                <code
                                    class="text-xs bg-base-200 px-2 py-1 rounded font-mono text-warning">{{ $rightInfo['name'] }}</code>
                                <span class="text-xs text-base-content/50">0x{{ dechex($rightMask) }}</span>
                            </div>
                            <p class="text-sm text-base-content/80">{{ $rightInfo['description'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-3 text-xs text-base-content/50">
                {{ count($effectiveRights) }} permission(s) active(s)
            </div>
        @else
            <div class="flex-1 flex items-center justify-center">
                <div class="text-center py-6">
                    <svg class="w-10 h-10 mx-auto mb-2 text-base-content/20" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                        </path>
                    </svg>
                    <p class="text-base-content/40 text-sm">Aucune permission spéciale</p>
                </div>
            </div>
        @endif

        @can('manage-rights')
            <div class="divider my-3"></div>
            <button type="button" @click="Livewire.dispatch('open-rights-drawer', { login: '{{ $user->login }}' })"
                class="btn btn-sm btn-outline btn-warning w-full">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
                    </path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Gérer les permissions
            </button>
        @endcan
    </div>
</div>
