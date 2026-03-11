<!-- Authentification -->
<div
    class="bg-gradient-to-br from-accent/10 via-primary/5 to-secondary/10 rounded-3xl border border-base-200/50 shadow-xl backdrop-blur-sm overflow-hidden">
    <div class="p-8">
        <div class="flex items-center gap-4 mb-8">
            <div
                class="w-12 h-12 bg-gradient-to-br from-accent to-accent/80 rounded-2xl flex items-center justify-center shadow-lg ring-4 ring-accent/20">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z">
                    </path>
                </svg>
            </div>
            <div>
                <h2 class="text-2xl font-black text-base-content">Authentification</h2>
                <p class="text-sm text-base-content/60 font-medium">Mot de passe et informations de connexion</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Date de naissance -->
            <div>
                <label class="label">
                    <span class="label-text font-medium text-base-content/70">Date de naissance</span>
                </label>
                <input type="text" wire:model="naissance"
                    class="input input-bordered w-full @error('naissance') input-error @enderror" placeholder="YYYYMMDD"
                    maxlength="8">
                <p class="text-xs text-base-content/50 mt-1">Format : YYYYMMDD (ex: 20050315)</p>
                @error('naissance')
                    <span class="text-error text-sm">{{ $message }}</span>
                @enderror
            </div>

            <!-- Mot de passe -->
            <div>
                <label class="label">
                    <span class="label-text font-medium text-base-content/70">Mot de passe</span>
                </label>
                <input type="password" wire:model="password"
                    class="input input-bordered w-full @error('password') input-error @enderror"
                    placeholder="Généré automatiquement si vide" maxlength="13">
                <p class="text-xs text-base-content/50 mt-1">Minimum {{ $passwordPolicy['min_length'] }} caractères</p>
                @error('password')
                    <span class="text-error text-sm">{{ $message }}</span>
                @enderror
            </div>
        </div>

        <!-- Info politique de mot de passe -->
        <div class="alert alert-info mt-6">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <div class="font-bold text-sm">Politique de mot de passe</div>
                <div class="text-xs">{{ $passwordPolicy['description'] }}</div>
            </div>
        </div>
    </div>
</div>
