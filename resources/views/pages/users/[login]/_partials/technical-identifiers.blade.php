<!-- Identifiants techniques -->
<div
    class="bg-gradient-to-br from-warning/10 via-orange-500/5 to-yellow-500/10 rounded-3xl border border-base-300 shadow-xl backdrop-blur-sm h-full overflow-hidden">
    <div class="p-8">
        <div class="flex items-center gap-4 mb-8">
            <div
                class="w-12 h-12 bg-gradient-to-br from-warning to-warning/80 rounded-2xl flex items-center justify-center shadow-lg ring-4 ring-warning/20 group-hover:scale-110 transition-transform duration-300">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
                    </path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
            </div>
            <div>
                <h2
                    class="text-2xl font-black text-base-content bg-gradient-to-r from-base-content to-base-content/80 bg-clip-text">
                    Identifiants techniques</h2>
                <p class="text-sm text-base-content/60 font-medium">Données système et externes</p>
            </div>
        </div>

        <div class="space-y-4">
            <div class="alert alert-info text-xs">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                        clip-rule="evenodd"></path>
                </svg>
                <span>Identifiants techniques et externes</span>
            </div>

            <div>
                <label class="label">
                    <span class="label-text font-medium text-base-content/70">ID AD</span>
                </label>
                <div class="text-xs font-mono bg-base-200 p-2 rounded text-base-content/80">
                    {{ $user->objectGuidDisplay ?? '-' }}
                </div>
            </div>

            @if ($user->idEnt)
                <div>
                    <label class="label">
                        <span class="label-text font-medium text-base-content/70">Id ENT</span>
                    </label>
                    <div class="text-sm font-mono bg-base-100 border border-base-300 p-2 rounded">
                        {{ $user->idEnt }}
                    </div>
                </div>
            @endif

            @if ($user->idAaf)
                <div>
                    <label class="label">
                        <span class="label-text font-medium text-base-content/70">Id AAF</span>
                    </label>
                    <div class="text-sm font-mono bg-base-100 border border-base-300 p-2 rounded">
                        {{ $user->idAaf }}
                    </div>
                </div>
            @endif

            @if ($user->idSiecle)
                <div>
                    <label class="label">
                        <span class="label-text font-medium text-base-content/70">Id Siecle</span>
                    </label>
                    <div class="text-sm font-mono bg-base-100 border border-base-300 p-2 rounded">
                        {{ $user->idSiecle }}
                    </div>
                </div>
            @endif

            @if ($user->idGpei)
                <div>
                    <label class="label">
                        <span class="label-text font-medium text-base-content/70">Id GPEI</span>
                    </label>
                    <div class="text-sm font-mono bg-base-100 border border-base-300 p-2 rounded">
                        {{ $user->idGpei }}
                    </div>
                </div>
            @endif

            @if ($user->idNc)
                <div>
                    <label class="label">
                        <span class="label-text font-medium text-base-content/70">Id NC</span>
                    </label>
                    <div class="text-sm font-mono bg-base-100 border border-base-300 p-2 rounded">
                        {{ $user->idNc }}
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
