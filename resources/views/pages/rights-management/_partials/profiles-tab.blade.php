{{--
    Onglet « Profils » de /app/rights-management.

    Story 7.2 — liste des rôles Spatie (seedés + customs), clic ligne →
    page d'édition `app.rights-management.profiles.show`.

    Story 49.1 (AC7 / D7) — DEUX sections :

      1. « Groupes porteurs » (principale) — les groupes qui portent un profil
         de droits. L'appartenance à l'un d'eux EST l'attribution du profil.
         Actions par ligne : changer / retirer le profil (re-projection des
         membres dans le même geste).

      2. « Profils non portés » (secondaire) — le tableau historique, filtré aux
         profils portés par AUCUN groupe. Sans lui, les profils de délégation
         (`user-admin`, `technicien`, customs) et la réserve de profils custom
         n'auraient plus aucun point d'entrée d'édition ou de suppression,
         alors que les drawers continuent de les attribuer.

    Les actions « Nouveau profil », « Donner des permissions à un groupe » et
    « Supprimer la sélection » sont dans le menu Actions de la page.
--}}

<div class="flex flex-col flex-1 min-h-0 gap-4 overflow-y-auto">

    {{-- ================================================================ --}}
    {{-- SECTION 1 — GROUPES PORTEURS (Story 49.1 AC7) --}}
    {{-- ================================================================ --}}
    <div class="card bg-base-100 border border-base-300 shadow-sm shrink-0">
        <div class="card-body p-0">
            <div class="px-4 pt-4 pb-2 flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h3 class="font-semibold text-base flex items-center gap-2">
                        <i class="fa-solid fa-users-rectangle text-primary"></i>
                        Groupes porteurs de permissions
                        <span class="badge badge-primary badge-sm">{{ count($carrierGroupsList) }}</span>
                    </h3>
                    <p class="text-sm text-base-content/60 mt-1">
                        Appartenir à l'un de ces groupes attribue automatiquement son profil de droits.
                    </p>
                </div>
                <button type="button" class="btn btn-primary btn-sm" wire:click="openAssignProfileModal">
                    <i class="fa-solid fa-user-plus mr-1"></i>
                    Donner des permissions à un groupe
                </button>
            </div>

            @if (empty($carrierGroupsList))
                <div class="text-center py-10 px-4">
                    <div class="text-3xl mb-3 opacity-20"><i class="fa-solid fa-users-rectangle"></i></div>
                    <p class="text-sm text-base-content/60 max-w-lg mx-auto">
                        Aucun groupe ne porte de permissions.
                        <strong>« Donner des permissions à un groupe »</strong> vous permettra d'attribuer un profil de
                        permissions à un groupe, de sorte qu'une simple
                        appartenance à ce groupe suffise à octroyer les permissions pour tous ses membres.
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Groupe</th>
                                <th>Profil porté</th>
                                <th class="text-center">Permissions</th>
                                <th class="text-center">Utilisateurs</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($carrierGroupsList as $carrier)
                                <tr wire:key="carrier-{{ $carrier['group_id'] }}">
                                    <td>
                                        <div class="font-medium">{{ $carrier['group_label'] }}</div>
                                        <div class="text-xs text-base-content/50 font-mono">
                                            {{ $carrier['group_name'] }}
                                            <span
                                                class="badge badge-ghost badge-xs ml-1">{{ $carrier['group_type'] }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        @if ($carrier['profile_id'])
                                            <a class="link link-hover font-medium"
                                                href="{{ route('app.rights-management.profiles.show', ['id' => $carrier['profile_id']]) }}">
                                                {{ $carrier['profile_label'] }}
                                            </a>
                                            <div class="mt-1">
                                                @if ($carrier['is_seeded'])
                                                    <span class="badge badge-info badge-xs"
                                                        title="Profil livré par défaut">
                                                        <i class="fa-solid fa-lock mr-1"></i> initial
                                                    </span>
                                                @else
                                                    <span class="badge badge-accent badge-xs">
                                                        <i class="fa-solid fa-wand-magic-sparkles mr-1"></i>
                                                        personnalisé
                                                    </span>
                                                @endif
                                                <span class="text-xs text-base-content/50 font-mono ml-1">
                                                    {{ $carrier['profile_name'] }}
                                                </span>
                                            </div>
                                        @else
                                            <span class="text-xs text-error">Profil introuvable</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span
                                            class="badge badge-ghost badge-sm">{{ $carrier['permissions_count'] }}</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-ghost badge-sm">{{ $carrier['users_count'] }}</span>
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <button type="button" class="btn btn-ghost btn-xs"
                                            wire:click="openChangeProfileModal({{ $carrier['group_id'] }})">
                                            <i class="fa-solid fa-right-left"></i>
                                            Changer
                                        </button>
                                        <button type="button" class="btn btn-ghost btn-xs text-error"
                                            wire:click="openRemoveProfileModal({{ $carrier['group_id'] }})">
                                            <i class="fa-solid fa-link-slash"></i>
                                            Retirer
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- SECTION 2 — PROFILS NON PORTÉS (D7) --}}
    {{-- ================================================================ --}}
    <div class="card bg-base-100 border border-base-300 shadow-sm shrink-0">
        <div class="card-body p-0">
            <div class="px-4 pt-4 pb-2">
                <h3 class="font-semibold text-base flex items-center gap-2">
                    <i class="fa-solid fa-id-card-clip text-secondary"></i>
                    Profils non portés
                    <span class="badge badge-secondary badge-sm">{{ count($unattachedProfilesList) }}</span>
                </h3>
                <p class="text-sm text-base-content/60 mt-1">
                    Profils qu'aucun groupe ne porte : ils s'attribuent individuellement (délégations)
                    depuis la gestion des droits d'un utilisateur.
                </p>
            </div>

            @if (empty($unattachedProfilesList))
                <div class="text-center py-8 px-4">
                    <p class="text-sm text-base-content/50">Tous les profils existants sont portés par un groupe.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th class="w-12">
                                    <x-molecules.select-all-checkbox class="checkbox-sm" :ids="array_column($unattachedProfilesList, 'name')"
                                        model="selectedProfiles" />
                                </th>
                                <th>Nom</th>
                                <th>Origine</th>
                                <th class="text-center">Permissions</th>
                                <th class="text-center">Utilisateurs</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($unattachedProfilesList as $profile)
                                <tr class="cursor-pointer" wire:key="unattached-{{ $profile['id'] }}"
                                    onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.rights-management.profiles.show', ['id' => $profile['id']]) }}'">
                                    <td class="checkbox-cell p-0">
                                        <label
                                            class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                            <input type="checkbox" class="checkbox checkbox-sm"
                                                wire:model.live="selectedProfiles" value="{{ $profile['name'] }}" />
                                        </label>
                                    </td>
                                    <td>
                                        <div class="font-medium">{{ $profile['label'] }}</div>
                                        <div class="text-xs text-base-content/50 font-mono">{{ $profile['name'] }}
                                        </div>
                                    </td>
                                    <td>
                                        @if ($profile['is_seeded'])
                                            <span class="badge badge-info badge-sm" title="Profil livré par défaut">
                                                <i class="fa-solid fa-lock mr-1"></i>
                                                initial
                                            </span>
                                        @else
                                            <span class="badge badge-accent badge-sm">
                                                <i class="fa-solid fa-wand-magic-sparkles mr-1"></i>
                                                personnalisé
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span
                                            class="badge badge-ghost badge-sm">{{ $profile['permissions_count'] }}</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-ghost badge-sm">{{ $profile['users_count'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
