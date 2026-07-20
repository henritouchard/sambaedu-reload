<!-- Administration locale -->
{{-- @if ($isOwnProfile && $localAdminInfo) --}}
<div
    class="bg-gradient-to-br from-primary/10 via-secondary/5 to-accent/10 rounded-3xl border border-base-300 shadow-xl backdrop-blur-sm overflow-hidden">
    <div class="p-8">
        <div class="flex items-center gap-4 mb-8">
            <div
                class="w-12 h-12 bg-gradient-to-br from-secondary to-secondary/80 rounded-2xl flex items-center justify-center shadow-lg ring-4 ring-secondary/20 group-hover:scale-110 transition-transform duration-300">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z">
                    </path>
                </svg>
            </div>
            <div>
                <h2
                    class="text-2xl font-black text-base-content bg-gradient-to-r from-base-content to-base-content/80 bg-clip-text">
                    Administration locale</h2>
                <p class="text-sm text-base-content/60 font-medium">Droits d'administration spécifiques</p>
            </div>
        </div>

        <div class="alert alert-info">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                    d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                    clip-rule="evenodd"></path>
            </svg>
            <span>{!! $localAdminInfo['texte'] !!}</span>
        </div>
    </div>
</div>
{{-- @endif --}}
