{{--
    Les AUDIENCES de la recette (`roles_spec`) — les colonnes de la matrice.

    Elles ne se confondent pas avec le catalogue de rôles : l'ajout propose
    « tout le groupe » et les rôles ATTRIBUABLES dans ce type ; les entrées
    existantes s'affichent telles qu'elles sont écrites dans la recette, et ne
    sont jamais réécrites.
--}}
<div class="card bg-base-100 shadow-sm border border-base-300">
    <div class="card-body p-5 gap-4">

        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h2 class="font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-users text-primary"></i> Audiences
                </h2>
                <p class="text-xs text-base-content/70 mt-1">
                    Une audience désigne <strong>qui</strong> l'arborescence sert. Ce qu'elle peut faire se décide
                    ensuite, <strong>dossier par dossier</strong>.
                </p>
            </div>

            <div class="flex items-end gap-2">
                <div class="flex flex-col gap-1 min-w-64">
                    <label class="label" for="pending-audience">
                        <span class="label-text font-medium">Ajouter une audience</span>
                    </label>
                    <select id="pending-audience" wire:model.live="pendingAudience" class="select select-bordered select-sm w-full"
                        data-testid="field-pending-audience">
                        <option value="">Choisir…</option>
                        @foreach ($this->audienceOptions as $value => $audienceLabel)
                            <option value="{{ $value }}">{{ $audienceLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" class="btn btn-outline btn-sm" wire:click="addAudience" data-testid="add-audience">
                    <i class="fa-solid fa-plus"></i> Ajouter
                </button>
            </div>
        </div>

        <div class="flex flex-wrap gap-2" data-testid="audience-list">
            @forelse ($this->audienceRows as $audience)
                <span class="badge badge-lg badge-outline gap-2" wire:key="audience-{{ $audience['key'] }}">
                    {{ $audience['label'] }}
                    <code class="text-xs opacity-50">{{ $audience['key'] }}</code>
                    <button type="button" class="text-error"
                        wire:click="removeAudience('{{ $audience['key'] }}')"
                        data-testid="remove-audience-{{ $audience['key'] }}" aria-label="Retirer cette audience">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </span>
            @empty
                <span class="text-sm opacity-50">Aucune audience — ajoutez-en une avant de poser des droits.</span>
            @endforelse
        </div>

        @if ($this->undeclaredRoles !== [])
            {{-- Un rôle absent de la liste n'est pas un rôle qui n'existe pas :
                 c'est un rôle que CE TYPE n'a pas déclaré. On le DIT, avec le
                 chemin pour l'ajouter. --}}
            <p class="text-xs text-warning" data-testid="undeclared-roles-note">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                Ce type de groupe ne déclare pas
                {{ implode(', ', array_map(fn ($l) => '« ' . $l . ' »', $this->undeclaredRoles)) }} :
                ces rôles ne sont donc pas proposés ici. Pour les rendre disponibles, déclarez-les dans l'onglet
                <strong>« Types de groupes »</strong>.
            </p>
        @endif

    </div>
</div>
