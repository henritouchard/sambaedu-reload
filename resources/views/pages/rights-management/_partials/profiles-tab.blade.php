{{--
    Story 7.2 — Onglet "Profils" (5ᵉ) dans /app/rights-management.

    Fournit le CRUD complet des rôles Spatie :
      - Rôles seedés (SambaRole enum) : édition des permissions uniquement,
        renommage / suppression désactivés.
      - Rôles custom (UI ou rapatriement AD) : create / edit / duplicate / delete.
      - Garde-fou suppression : échoue si users assignés.

    Invalide le cache Spatie après chaque mutation
    (app(PermissionRegistrar::class)->forgetCachedPermissions()).

    Modale native <dialog class="modal"> + wire:model.entangle (pattern projet).
--}}

<div class="space-y-4">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-lg font-bold">Profils de droits</h2>
            <p class="text-sm text-base-content/60">
                Rôles Spatie — seedés (livrés par défaut) ou personnalisés.
                Les profils seedés sont protégés contre la suppression et le
                renommage.
            </p>
        </div>
        <button class="btn btn-primary btn-sm" wire:click="openCreateProfileModal">
            <i class="fa-solid fa-plus mr-1"></i>
            Nouveau profil
        </button>
    </div>

    {{-- Liste des profils --}}
    @if (empty($profilesList))
        <div class="card bg-base-100 border border-base-300 shadow-sm">
            <div class="card-body text-center py-12">
                <span class="loading loading-spinner loading-md text-primary mb-2"></span>
                <p class="text-sm text-base-content/60">Chargement des profils…</p>
            </div>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Origine</th>
                            <th class="text-center">Permissions</th>
                            <th class="text-center">Utilisateurs</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($profilesList as $profile)
                            <tr class="hover:bg-base-200/30">
                                <td>
                                    <div class="font-medium">{{ $profile['label'] }}</div>
                                    <div class="text-xs text-base-content/50 font-mono">{{ $profile['name'] }}</div>
                                </td>
                                <td>
                                    @if ($profile['is_seeded'])
                                        <span class="badge badge-info badge-sm" title="Profil livré par défaut">
                                            <i class="fa-solid fa-lock mr-1"></i>
                                            seeded
                                        </span>
                                    @else
                                        <span class="badge badge-accent badge-sm">
                                            <i class="fa-solid fa-wand-magic-sparkles mr-1"></i>
                                            custom
                                        </span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-ghost badge-sm">{{ $profile['permissions_count'] }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-ghost badge-sm">{{ $profile['users_count'] }}</span>
                                </td>
                                <td class="text-right space-x-1">
                                    <button class="btn btn-ghost btn-xs"
                                        wire:click="openEditProfileModal('{{ $profile['name'] }}')"
                                        title="Éditer les permissions">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <button class="btn btn-ghost btn-xs"
                                        wire:click="duplicateProfile('{{ $profile['name'] }}')"
                                        title="Dupliquer">
                                        <i class="fa-solid fa-copy"></i>
                                    </button>
                                    @if (!$profile['is_seeded'])
                                        <button class="btn btn-ghost btn-xs text-error"
                                            wire:click="deleteProfile('{{ $profile['name'] }}')"
                                            wire:confirm="Supprimer ce profil ? Cette action est irréversible."
                                            title="Supprimer">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    @else
                                        <button class="btn btn-ghost btn-xs text-base-content/30"
                                            disabled
                                            title="Profil seedé — non-supprimable">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Modale création / édition de profil --}}
    <dialog class="modal" x-data="{ open: @entangle('profileModalOpen') }" :class="{ 'modal-open': open }" x-cloak>
        <div class="modal-box max-w-3xl max-h-[85vh] overflow-y-auto">
            <h3 class="font-bold text-lg mb-4">
                @if ($profileModalMode === 'create')
                    <i class="fa-solid fa-plus text-primary mr-1"></i>
                    Créer un nouveau profil
                @else
                    <i class="fa-solid fa-pen text-primary mr-1"></i>
                    Éditer le profil
                    @if ($editingProfileIsSeeded)
                        <span class="badge badge-info badge-sm ml-2">seeded</span>
                    @endif
                @endif
            </h3>

            {{-- Nom + description --}}
            <div class="space-y-3 mb-4">
                <div>
                    <label class="label text-sm font-medium" for="profile-name">Nom du profil</label>
                    <input
                        id="profile-name"
                        type="text"
                        class="input input-bordered w-full"
                        wire:model="profileFormName"
                        placeholder="ex: Animateur CDI"
                        @if ($editingProfileIsSeeded) readonly @endif
                    />
                    @if ($editingProfileIsSeeded)
                        <p class="text-xs text-base-content/50 mt-1">Le nom d'un profil seedé ne peut pas être modifié.</p>
                    @endif
                    @error('profileFormName')<p class="text-error text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            {{-- Permissions groupées par catégorie --}}
            <div>
                <h4 class="font-semibold text-sm mb-2">Permissions accordées</h4>
                {{-- Review 7.2 #M3 — Bannière quand on édite un rôle seedé. --}}
                @if ($profileModalMode === 'edit' && $editingProfileIsSeeded)
                    <div class="alert alert-info alert-sm mb-2">
                        <i class="fa-solid fa-lock"></i>
                        <span class="text-xs">
                            Rôle seedé — permissions gérées par le système.
                            Pour modifier les défauts, éditez <code>database/seeders/PermissionSeeder.php</code>
                            puis relancez <code>php artisan db:seed --class=PermissionSeeder --force</code>.
                        </span>
                    </div>
                @endif
                <div class="space-y-3 border rounded-lg p-3 bg-base-200/30">
                    @foreach ($groupedPermissions as $catKey => $cat)
                        <div>
                            <div class="text-xs uppercase tracking-wide text-base-content/60 font-semibold mb-1">
                                {{ $cat['label'] }}
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-1">
                                @foreach ($cat['permissions'] as $perm)
                                    <label class="label cursor-pointer justify-start gap-2 py-1"
                                        @if ($profileModalMode === 'edit' && $editingProfileIsSeeded)
                                            title="Rôle seedé — permissions gérées par le système, relancez PermissionSeeder pour modifier les défauts"
                                        @endif>
                                        <input
                                            type="checkbox"
                                            class="checkbox checkbox-sm checkbox-primary"
                                            value="{{ $perm['name'] }}"
                                            wire:model="profileFormPermissions"
                                            @if ($profileModalMode === 'edit' && $editingProfileIsSeeded) disabled @endif
                                        />
                                        <div class="flex-1">
                                            <div class="text-sm">{{ $perm['label'] }}</div>
                                            <div class="text-xs text-base-content/50 font-mono">{{ $perm['name'] }}</div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                @error('profileFormPermissions')<p class="text-error text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="modal-action">
                <button type="button" class="btn btn-ghost" wire:click="closeProfileModal">Annuler</button>
                {{-- Review 7.2 #M3 — En mode édition seedé, le bouton Enregistrer
                    est désactivé : les checkboxes ne sont pas modifiables et le
                    serveur abort(403) de toute façon. Le bouton Annuler reste
                    accessible et la duplication reste autorisée depuis la liste. --}}
                <button type="button" class="btn btn-primary" wire:click="saveProfile"
                    @if ($profileModalMode === 'edit' && $editingProfileIsSeeded) disabled @endif
                >
                    <i class="fa-solid fa-floppy-disk mr-1"></i>
                    @if ($profileModalMode === 'create') Créer @else Enregistrer @endif
                </button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button type="button" wire:click="closeProfileModal">close</button>
        </form>
    </dialog>
</div>
