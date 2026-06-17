<form wire:submit="save" class="space-y-6">
    <!-- Informations de base -->
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h3 class="card-title text-lg mb-4">
                <i class="fa-solid fa-info-circle text-primary"></i>
                Informations générales
            </h3>

            <!-- Nom -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-medium">Nom du groupe <span class="text-error">*</span></span>
                </label>
                <input type="text" wire:model="name"
                    class="input input-bordered @error('name') input-error @enderror"
                    placeholder="Ex: Salle-Info-101, Parc-Portables">
                @error('name')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>

            <!-- Description -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-medium">Description</span>
                </label>
                <textarea wire:model="description" class="textarea textarea-bordered @error('description') textarea-error @enderror"
                    placeholder="Description optionnelle du groupe" rows="3"></textarea>
                @error('description')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>

            <!-- Type de groupe -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-medium">Type de groupe <span class="text-error">*</span></span>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Groupe physique -->
                    <label class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ $is_physical ? 'ring-2 ring-primary' : '' }}">
                        <div class="card-body p-4">
                            <div class="flex items-start gap-3">
                                <input type="radio" wire:model.live="is_physical" value="1" class="radio radio-primary mt-1" />
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <i class="fa-solid fa-building text-info"></i>
                                        <span class="font-semibold">Groupe physique</span>
                                    </div>
                                    <p class="text-xs text-base-content/70 leading-relaxed">
                                        Salle ou bâtiment (OU dans Active Directory). Utilisé pour l'application des GPO et la hiérarchie des salles.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </label>

                    <!-- Groupe logique -->
                    <label class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ !$is_physical ? 'ring-2 ring-primary' : '' }}">
                        <div class="card-body p-4">
                            <div class="flex items-start gap-3">
                                <input type="radio" wire:model.live="is_physical" value="0" class="radio radio-primary mt-1" />
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <i class="fa-solid fa-network-wired text-warning"></i>
                                        <span class="font-semibold">Groupe logique</span>
                                    </div>
                                    <p class="text-xs text-base-content/70 leading-relaxed">
                                        Parc de machines (CN dans OU=Parcs). Utilisé pour WPKG et les permissions, indépendamment de l'emplacement physique.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </label>
                </div>
                <label class="label py-1">
                    <span class="label-text-alt text-base-content/60 whitespace-normal">
                        <i class="fa-solid fa-info-circle mr-1"></i>
                        Les groupes physiques sont synchronisés avec l'AD et appliquent les GPO. Les groupes logiques sont gérés localement pour WPKG.
                    </span>
                </label>
            </div>

            <!-- Groupe parent -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-medium">Groupe parent</span>
                </label>
                <select wire:model="parent_id" class="select select-bordered">
                    <option value="">Aucun (groupe racine)</option>
                    @foreach ($availableParents as $parent)
                        <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Environnement / nature des postes (Story 26.1) -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-medium">Environnement des postes</span>
                </label>
                <select wire:model="environment" class="select select-bordered">
                    <option value="">— Non déclaré (partagé par défaut) —</option>
                    @foreach (\App\Enums\WorkstationEnvironment::cases() as $env)
                        <option value="{{ $env->value }}">{{ $env->label() }}</option>
                    @endforeach
                </select>
                <label class="label py-1">
                    <span class="label-text-alt text-base-content/60 whitespace-normal">
                        <i class="fa-solid fa-info-circle mr-1"></i>
                        Détermine le comportement du bureau et des profils des postes. Un poste appartenant à
                        plusieurs parcs hérite du plus « fort » : <strong>nomade &gt; personnel &gt; partagé</strong>.
                    </span>
                </label>
            </div>
        </div>
    </div>

    <!-- Informations système (lecture seule) -->
    <div class="card bg-base-200/50 shadow-sm">
        <div class="card-body">
            <h3 class="card-title text-base mb-4 text-base-content/70">
                <i class="fa-solid fa-cog"></i>
                Informations système
            </h3>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-base-content/60">UUID</span>
                    <p class="font-mono text-xs">{{ $group->uuid }}</p>
                </div>
                <div>
                    <span class="text-base-content/60">Sync AD</span>
                    <p class="font-mono text-xs">{{ $group->getAdStatusLabel() }}</p>
                </div>
                <div>
                    <span class="text-base-content/60">Créé le</span>
                    <p>{{ $group->created_at->format('d/m/Y H:i') }}</p>
                </div>
                <div>
                    <span class="text-base-content/60">Modifié le</span>
                    <p>{{ $group->updated_at->format('d/m/Y H:i') }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="flex justify-end gap-3">
        <a href="{{ route('app.parc.groups.show', $id) }}" class="btn btn-ghost">
            Annuler
        </a>
        <button type="submit" class="btn btn-primary">
            <span wire:loading.remove wire:target="save">
                <i class="fa-solid fa-check"></i>
                Enregistrer
            </span>
            <span wire:loading wire:target="save" class="loading loading-spinner loading-sm"></span>
        </button>
    </div>
</form>
