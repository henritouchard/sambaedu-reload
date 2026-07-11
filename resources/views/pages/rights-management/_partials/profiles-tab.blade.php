{{--
    Story 7.2 — Onglet "Profils" (4ᵉ) dans /app/rights-management.

    Liste des rôles Spatie (seedés + customs). Au clic sur une ligne →
    page d'édition `app.rights-management.profiles.show`. Les actions
    "Nouveau profil" et "Supprimer la sélection" sont dans le menu Actions
    de la page (slot top-right). La création/édition se fait sur des pages
    dédiées, plus de modale.
--}}

<div class="flex flex-col flex-1 min-h-0 space-y-3">
    @if (empty($profilesList))
        <div class="card bg-base-100 border border-base-300 shadow-sm">
            <div class="card-body text-center py-12">
                <span class="loading loading-spinner loading-md text-primary mb-2"></span>
                <p class="text-sm text-base-content/60">Chargement des profils…</p>
            </div>
        </div>
    @else
        <div class="card bg-base-100 shadow-sm flex-1 min-h-0 flex flex-col overflow-hidden">
            <x-organisms.data-table
                colgroup="<colgroup><col style='width: 3rem'><col style='width: auto'><col style='width: 8rem'><col style='width: 9rem'><col style='width: 9rem'></colgroup>">
                <x-slot:header>
                    <th>
                        <label>
                            <input type="checkbox"
                                class="checkbox checkbox-sm"
                                @checked(count($selectedProfiles) === count($profilesList) && count($profilesList) > 0)
                                onclick="
                                    const checked = this.checked;
                                    document.querySelectorAll('.profile-row-checkbox').forEach(cb => {
                                        if (cb.checked !== checked) cb.click();
                                    });
                                " />
                        </label>
                    </th>
                    <th>Nom</th>
                    <th>Origine</th>
                    <th class="text-center">Permissions</th>
                    <th class="text-center">Utilisateurs</th>
                </x-slot:header>

                @foreach ($profilesList as $profile)
                    <tr class="hover:bg-sky-50 cursor-pointer"
                        onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.rights-management.profiles.show', ['id' => $profile['id']]) }}'">
                        <td class="checkbox-cell p-0">
                            <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                <input type="checkbox"
                                    class="checkbox checkbox-sm profile-row-checkbox"
                                    wire:model.live="selectedProfiles"
                                    value="{{ $profile['name'] }}" />
                            </label>
                        </td>
                        <td>
                            <div class="font-medium">{{ $profile['label'] }}</div>
                            <div class="text-xs text-base-content/50 font-mono">{{ $profile['name'] }}</div>
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
                            <span class="badge badge-ghost badge-sm">{{ $profile['permissions_count'] }}</span>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-ghost badge-sm">{{ $profile['users_count'] }}</span>
                        </td>
                    </tr>
                @endforeach
            </x-organisms.data-table>
        </div>
    @endif
</div>
