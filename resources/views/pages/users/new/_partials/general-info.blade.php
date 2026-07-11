<!-- Informations générales -->
<div
    class="bg-gradient-to-br from-primary/10 via-secondary/5 to-accent/10 rounded-3xl border border-base-200/50 shadow-xl backdrop-blur-sm overflow-hidden">
    <div class="p-8">
        <div class="flex items-center gap-4 mb-8">
            <div
                class="w-12 h-12 bg-gradient-to-br from-primary to-primary/80 rounded-2xl flex items-center justify-center shadow-lg ring-4 ring-primary/20">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
            </div>
            <div>
                <h2 class="text-2xl font-black text-base-content">Informations générales</h2>
                <p class="text-sm text-base-content/60 font-medium">Identité et catégorie de l'utilisateur</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Nom -->
            <div>
                <label class="label">
                    <span class="label-text font-medium text-base-content/70">Nom <span
                            class="text-error">*</span></span>
                </label>
                <input type="text" wire:model="nom"
                    class="input input-bordered w-full @error('nom') input-error @enderror" placeholder="Dupont">
                @error('nom')
                    <span class="text-error text-sm">{{ $message }}</span>
                @enderror
            </div>

            <!-- Prénom -->
            <div>
                <label class="label">
                    <span class="label-text font-medium text-base-content/70">Prénom <span
                            class="text-error">*</span></span>
                </label>
                <input type="text" wire:model="prenom"
                    class="input input-bordered w-full @error('prenom') input-error @enderror" placeholder="Jean">
                @error('prenom')
                    <span class="text-error text-sm">{{ $message }}</span>
                @enderror
            </div>

            <!-- Établissement -->
            <div class="flex flex-col">
                <label class="label">
                    <span class="label-text font-medium text-base-content/70">Établissement</span>
                </label>
                <div class="badge badge-neutral badge-lg font-bold">{{ $etabName }}</div>
            </div>

            <!-- Catégorie -->
            <div>
                <label class="label">
                    <span class="label-text font-medium text-base-content/70">Catégorie <span
                            class="text-error">*</span></span>
                </label>
                <select wire:model.live="categorie" class="select select-bordered w-full">
                    <option value="Eleves">Élève</option>
                    <option value="Profs">Professeur</option>
                    <option value="Administratifs">Administratif</option>
                </select>
            </div>

            <!-- Login suggéré -->
            <div>
                <label class="label">
                    <x-atoms.tooltip position="top" icon="true">
                        <x-slot name="label">Login</x-slot>
                        Généré automatiquement si vide
                    </x-atoms.tooltip>
                </label>
                <input type="text" wire:model="login"
                    placeholder="Ex: jean.dupont"
                    class="input input-bordered w-full @error('login') input-error @enderror" placeholder="">
                @error('login')
                    <span class="text-error text-sm">{{ $message }}</span>
                @enderror
            </div>

            <!-- Fonction (conditionnelle) -->
            @if ($categorie === 'Administratifs' || $categorie === 'Profs')
                <div>
                    <label class="label">
                        <span class="label-text font-medium text-base-content/70">
                            Fonction
                            @if ($categorie === 'Administratifs')
                                <span class="text-error">*</span>
                            @endif
                        </span>
                    </label>
                    <select wire:model="fonction"
                        class="select select-bordered w-full @error('fonction') select-error @enderror">
                        <option value="">Sélectionnez une fonction</option>
                        @foreach ($fonctions as $f)
                            <option value="{{ $f }}">{{ $f }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-base-content/50 mt-1">
                        {{ $categorie === 'Administratifs' ? 'Obligatoire pour les administratifs' : 'Optionnel pour les professeurs' }}
                    </p>
                    @error('fonction')
                        <span class="text-error text-sm">{{ $message }}</span>
                    @enderror
                </div>
            @endif
        </div>
    </div>
</div>
