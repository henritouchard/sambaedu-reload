{{-- Story 62.3 — le select de rôle au rattachement lit la DONNÉE.

     Avant, ses deux `<option>` étaient EN DUR : « Élève » et « Prof », quel que
     soit le type du groupe — sur un projet, on proposait donc « Élève »/« Prof »
     pour ce qui allait s'afficher « Membre »/« Porteur » une ligne plus bas.

     DIVERGENCE NOMMÉE ET ASSUMÉE : sur une classe, « Prof » devient
     « Enseignant ». « Prof » était un raccourci écrit dans cette vue et nulle part
     ailleurs ; le libellé canonique déclaré pour `classe`×`manager` — celui que la
     table des membres, la fiche utilisateur et l'aperçu de partage affichent tous
     — est « Enseignant ».

     `owner` est EXCLU : D5 (« jamais professeur principal au rattachement ») est
     déjà appliquée côté serveur par `setPendingRole()`. L'exclusion est ici pour
     que l'écran ne propose pas ce que le serveur refusera. --}}
@php($pendingRoleOptions = \App\Support\RoleCatalog::options($type ?? null))
@php($pendingAssignableRoles = array_values(array_filter(
    \App\Support\RoleCatalog::assignableKeys($type ?? null),
    static fn (string $roleKey): bool => $roleKey !== \App\Models\Pivot\UserGroupUserPivot::ROLE_OWNER,
)))
<div class="max-w-4xl">
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body space-y-4">
            <div class="grid md:grid-cols-2 gap-4">
                <div class="form-control">
                    <label class="label"><span class="label-text">Nom technique</span></label>
                    <input type="text" class="input input-bordered" wire:model="name" />
                    @error('name')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text">Nom affiché</span></label>
                    <input type="text" class="input input-bordered" wire:model="displayName" />
                    @error('displayName')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <div class="form-control max-w-xs">
                <label class="label"><span class="label-text">Type</span></label>
                <select class="select select-bordered" wire:model="type">
                    <option value="custom">Personnalisé</option>
                    <option value="classe">Classe</option>
                    <option value="cours">Cours</option>
                    <option value="matiere">Matière</option>
                    <option value="projet">Projet</option>
                    <option value="equipe">Équipe</option>
                </select>
                @error('type')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-control" x-data="{ search: '' }">
                <label class="label"><span class="label-text">Ajouter des membres</span></label>
                <div class="border border-base-300 rounded-xl bg-base-100">
                    <div class="border-b border-base-300/70 p-2">
                        <input type="text" x-model="search"
                            class="input input-sm w-full"
                            placeholder="Rechercher un utilisateur...">
                    </div>
                    <ul class="max-h-60 overflow-y-auto p-1.5">
                        @forelse ($this->availableUsers as $option)
                            <li x-show="search === '' || '{{ strtolower(addslashes($option['label'] . ' ' . $option['hint'])) }}'.includes(search.toLowerCase())"
                                wire:key="available-user-{{ $option['value'] }}"
                                class="px-2 py-1">
                                <label class="flex items-center gap-2 cursor-pointer hover:bg-base-200 rounded px-2 py-1">
                                    <input type="checkbox" value="{{ $option['value'] }}"
                                        class="checkbox checkbox-sm checkbox-primary"
                                        @checked(in_array($option['value'], $selectedUserIds))
                                        wire:click="toggleUser({{ $option['value'] }})" />
                                    <span class="truncate">{{ $option['label'] }}</span>
                                    @if ($option['hint'] !== '')
                                        <span class="text-xs text-base-content/55 ml-auto flex-shrink-0">{{ $option['hint'] }}</span>
                                    @endif
                                </label>
                                {{-- Story 42.3 (D5/T3.3) — user NOUVELLEMENT coché : select du
                                     rôle proposé (défaut dérivé, surchargeable Élève/Prof —
                                     jamais owner au rattachement). Label au-dessus, pas de hint
                                     décoratif, wrapper flex flex-col + w-full (piège DaisyUI 5,
                                     pas de nouveau .form-control). --}}
                                @if (in_array($option['value'], $selectedUserIds))
                                    <div class="pl-8 pt-1 flex flex-col w-full max-w-[12rem]">
                                        <label class="label-text text-xs opacity-70">Rôle</label>
                                        <select wire:key="pending-role-{{ $option['value'] }}"
                                            wire:change="setPendingRole({{ $option['value'] }}, $event.target.value)"
                                            class="select select-bordered select-xs w-full">
                                            @php($currentPendingRole = $pendingRoles[$option['value']] ?? $option['default_role'])
                                            @foreach ($pendingAssignableRoles as $pendingAssignableRole)
                                                <option value="{{ $pendingAssignableRole }}" @selected($currentPendingRole === $pendingAssignableRole)>{{ $pendingRoleOptions[$pendingAssignableRole] ?? $pendingAssignableRole }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            </li>
                        @empty
                            <li class="px-3 py-2 text-sm text-base-content/60">Tous les utilisateurs sont déjà membres</li>
                        @endforelse
                    </ul>
                </div>
                @error('selectedUserIds')
                    <span class="text-error text-xs mt-1">{{ $message }}</span>
                @enderror
            </div>

            <div class="pt-2 flex items-center gap-2">
                <button type="button" class="btn btn-primary" wire:click="save">
                    <i class="fa-solid fa-save"></i>
                    Enregistrer
                </button>
                <button type="button" class="btn btn-ghost" wire:click="cancelEditing">
                    Annuler
                </button>
            </div>
        </div>
    </div>
</div>
