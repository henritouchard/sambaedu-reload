{{--
    Story 7.2 — Formulaire partagé entre les pages de création et d'édition
    d'un profil (rôle Spatie).

    Variables attendues :
      - $mode               : 'create' | 'edit'
      - $name               : string (modèle Livewire `name`)
      - $permissions        : array<string> (modèle Livewire `permissions`)
      - $groupedPermissions : array structuré par catégorie
      - $isSeeded           : bool — verrouille le formulaire si true (rôle seedé)
      - $usersCount         : int  — nombre d'utilisateurs portant ce rôle (edit uniquement)
--}}

@if ($mode === 'edit' && $isSeeded)
    <div class="alert alert-info mb-4">
        <i class="fa-solid fa-lock"></i>
        <div class="text-xs">
            <strong>Rôle seedé</strong> — permissions gérées par le système.
            Pour modifier les défauts, éditez
            <code>database/seeders/PermissionSeeder.php</code>
            puis relancez
            <code>php artisan sambaedu:app:update --resync-seeded-roles</code>
            (le simple <code>db:seed --force</code> ne re-synchronise PAS les rôles seedés
            existants — son <code>--force</code> sert seulement à bypasser le prompt prod).
        </div>
    </div>
@endif

<div class="space-y-4">
    <div class="lg:col-span-1">
        <div class="card bg-base-100 border border-base-300 shadow-sm">
            <div class="card-body">
                <h3 class="card-title text-base">
                    <i class="fa-solid fa-id-card-clip text-primary"></i>
                    Identité
                </h3>

                <div>
                    <label class="label text-sm font-medium" for="profile-name">Nom du profil</label>
                    <input
                        id="profile-name"
                        type="text"
                        class="input input-bordered w-full"
                        wire:model.live="name"
                        placeholder="ex: Animateur CDI"
                        @if ($isSeeded) readonly @endif
                    />
                    @if ($isSeeded)
                        <p class="text-xs text-base-content/50 mt-1">
                            Le nom d'un profil seedé ne peut pas être modifié.
                        </p>
                    @endif
                    @error('name')
                        <p class="text-error text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                @if ($mode === 'edit')
                    <div class="mt-3 text-xs text-base-content/60 space-y-1">
                        <div>
                            <i class="fa-solid fa-users mr-1"></i>
                            {{ $usersCount }} utilisateur(s) portent ce profil
                        </div>
                        @if ($isSeeded)
                            <div>
                                <span class="badge badge-info badge-xs">seeded</span>
                            </div>
                        @else
                            <div>
                                <span class="badge badge-accent badge-xs">custom</span>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="card bg-base-100 border border-base-300 shadow-sm">
            <div class="card-body">
                <h3 class="card-title text-base">
                    <i class="fa-solid fa-key text-primary"></i>
                    Permissions accordées
                    <span class="badge badge-primary badge-sm ml-2">{{ count($permissions) }}</span>
                </h3>

                <div class="space-y-4 mt-2">
                    @foreach ($groupedPermissions as $catKey => $cat)
                        <div>
                            <div class="text-xs uppercase tracking-wide text-base-content/60 font-semibold mb-1">
                                {{ $cat['label'] }}
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-1">
                                @foreach ($cat['permissions'] as $perm)
                                    <label class="label cursor-pointer justify-start gap-2 py-1"
                                        @if ($isSeeded) title="Rôle seedé — permissions verrouillées." @endif>
                                        <input
                                            type="checkbox"
                                            class="checkbox checkbox-sm checkbox-primary"
                                            value="{{ $perm['name'] }}"
                                            wire:model.live="permissions"
                                            @if ($isSeeded) disabled @endif
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
                @error('permissions')
                    <p class="text-error text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>
</div>
