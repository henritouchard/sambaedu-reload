<form wire:submit="save" class="space-y-6">
    <!-- Informations générales -->
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h3 class="card-title text-lg mb-4">
                <i class="fa-solid fa-info-circle text-primary"></i>
                Informations générales
            </h3>

            <!-- Nom -->
            <div class="form-control w-full">
                <label class="label py-2">
                    <span class="label-text font-medium">Nom du groupe <span class="text-error">*</span></span>
                </label>
                <input type="text" wire:model="display_name"
                    class="input input-bordered w-full @error('display_name') input-error @enderror"
                    placeholder="Ex: Salle Info 101, Parc Portables">
                @error('display_name')
                    <label class="label py-1">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>

            <!-- Description -->
            <div class="form-control w-full">
                <label class="label py-2">
                    <span class="label-text font-medium">Description</span>
                </label>
                <textarea wire:model="description"
                    class="textarea textarea-bordered w-full @error('description') textarea-error @enderror"
                    placeholder="Description optionnelle du groupe" rows="3"></textarea>
                @error('description')
                    <label class="label py-1">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                @enderror
            </div>

            <!-- Type de groupe -->
            <div class="form-control w-full">
                <label class="label py-2">
                    <x-atoms.tooltip label="Type de groupe" labelClass="label-text font-medium" icon="true"
                        iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                        Les groupes physiques sont synchronisés avec l'AD et appliquent les GPO selon la hiérarchie des
                        salles. Les groupes logiques sont gérés localement pour les applications, indépendamment de l'emplacement
                        physique.
                    </x-atoms.tooltip>
                    <span class="text-error">*</span>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Groupe physique -->
                    <label
                        class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ $is_physical ? 'ring-2 ring-primary' : '' }}">
                        <div class="card-body p-4">
                            <div class="flex items-start gap-3">
                                <input type="radio" wire:model.live="is_physical" value="1"
                                    class="radio radio-primary mt-1" />
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <i class="fa-solid fa-building text-info"></i>
                                        <span class="font-semibold">Groupe physique</span>
                                    </div>
                                    <p class="text-xs text-base-content/70 leading-relaxed">
                                        Salle ou bâtiment (OU dans Active Directory). Utilisé pour l'application des GPO
                                        et la hiérarchie des salles.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </label>

                    <!-- Groupe logique -->
                    <label
                        class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ !$is_physical ? 'ring-2 ring-primary' : '' }}">
                        <div class="card-body p-4">
                            <div class="flex items-start gap-3">
                                <input type="radio" wire:model.live="is_physical" value="0"
                                    class="radio radio-primary mt-1" />
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <i class="fa-solid fa-network-wired text-warning"></i>
                                        <span class="font-semibold">Groupe logique</span>
                                    </div>
                                    <p class="text-xs text-base-content/70 leading-relaxed">
                                        Parc de machines (CN dans OU=Parcs). Utilisé pour les applications et les permissions,
                                        indépendamment de l'emplacement physique.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Groupe parent -->
            <div class="form-control w-full">
                <label class="label py-2">
                    <span class="label-text font-medium">Groupe parent</span>
                </label>
                <select wire:model="parent_id" class="select select-bordered w-full">
                    <option value="">Aucun (groupe racine)</option>
                    @foreach ($availableParents as $parent)
                        <option value="{{ $parent->id }}">{{ $parent->display_name_or_name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Environnement / nature des postes (Story 26.1) -->
            <div class="form-control w-full">
                <label class="label py-2">
                    <x-atoms.tooltip label="Environnement des postes" labelClass="label-text font-medium" icon="true"
                        iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                        Détermine le comportement du bureau et des profils des postes. Un poste appartenant à plusieurs
                        parcs hérite du plus « fort » : <strong>nomade &gt; personnel &gt; partagé</strong>.
                    </x-atoms.tooltip>
                </label>
                <select wire:model="environment" class="select select-bordered w-full">
                    <option value="">— Non déclaré (partagé par défaut) —</option>
                    @foreach (\App\Enums\WorkstationEnvironment::cases() as $env)
                        <option value="{{ $env->value }}">{{ $env->label() }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Politique de gestion des fichiers — override par parc (décision Henri
                 2026-07-17). '' = hérite du défaut d'établissement. --}}
            <div class="form-control w-full">
                <label class="label py-2">
                    <x-atoms.tooltip label="Gestion des fichiers (override du parc)" labelClass="label-text font-medium"
                        icon="true" iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                        Surcharge la politique d'accès aux fichiers de l'établissement pour ce parc. Hors « Partages
                        réseau », l'agent ne monte <strong>aucun lecteur</strong> (home K: inclus) sur ses postes.
                        Laisser « Hériter » pour suivre le défaut global.
                    </x-atoms.tooltip>
                </label>
                <select wire:model.live="files_policy_mode" class="select select-bordered w-full">
                    <option value="">— Hériter du défaut de l'établissement —</option>
                    @foreach (\App\Enums\FilePolicyMode::cases() as $fpm)
                        <option value="{{ $fpm->value }}">{{ $fpm->label() }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Config Nextcloud spécifique au parc (optionnelle) — masquée si le parc
                 hérite ou reste en partages réseau. Consommée par le provisioning à venir. --}}
            @if ($files_policy_mode !== '' && $files_policy_mode !== \App\Enums\FilePolicyMode::Partages->value)
                <div class="form-control w-full">
                    <label class="label py-2">
                        <span class="label-text font-medium">URL serveur Nextcloud (spécifique au parc)</span>
                    </label>
                    <input type="text" wire:model="files_nextcloud_server_url"
                        placeholder="Laisser vide pour hériter du défaut"
                        class="input input-bordered w-full" />
                </div>
                <div class="form-control w-full">
                    <label class="label py-2">
                        <span class="label-text font-medium">URL web Nextcloud (spécifique au parc)</span>
                    </label>
                    <input type="text" wire:model="files_nextcloud_web_url"
                        placeholder="Laisser vide pour hériter du défaut"
                        class="input input-bordered w-full" />
                </div>
            @endif

            {{-- Label de contrat amont (Story 30.2) — masqué si pas de contrat actif (NFR3). --}}
            @if ($hasActiveContract)
                <div class="form-control w-full">
                    <label class="label py-2">
                        <x-atoms.tooltip label="Label de contrat amont" labelClass="label-text font-medium" icon="true"
                            iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                            Rattache ce parc à un label « libre » défini par l'autorité amont, pour cibler les
                            politiques imposées. Au plus un label par groupe. Les labels réservés à l'autorité amont ne
                            sont pas attribuables.
                        </x-atoms.tooltip>
                    </label>

                    @if ($reservedLabelHeld !== null)
                        {{-- Label réservé porté (cas 30.3) : lecture seule, jamais éditable par le refnum. --}}
                        <select class="select select-bordered w-full" disabled>
                            <option>{{ $reservedLabelHeld }}</option>
                        </select>
                        <label class="label py-1">
                            <span class="label-text-alt text-warning whitespace-normal">
                                <i class="fa-solid fa-lock text-xs"></i>
                                Label réservé — imposé par l'autorité amont, non modifiable.
                            </span>
                        </label>
                    @else
                        <select wire:model="controlhubLabel" class="select select-bordered w-full">
                            <option value="">Aucun</option>
                            @foreach ($freeLabelNames as $labelName)
                                <option value="{{ $labelName }}">{{ $labelName }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
            @endif
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
