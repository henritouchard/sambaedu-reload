<!-- Card: Groupes (équipes) -->
<div
    class="bg-gradient-to-br from-info/10 via-info/5 to-primary/10 rounded-3xl border border-base-300 shadow-xl backdrop-blur-sm overflow-hidden flex flex-col h-full">
    <div class="p-6 flex flex-col flex-1 min-h-0">
        <div class="flex items-center gap-4 mb-6">
            <div
                class="w-10 h-10 bg-gradient-to-br from-info to-info/80 rounded-xl flex items-center justify-center shadow-lg ring-4 ring-info/20">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                    </path>
                </svg>
            </div>
            <div>
                <h2 class="text-xl font-bold text-base-content">Groupes</h2>
                <p class="text-sm text-base-content/60">Équipes et classes</p>
            </div>
        </div>

        @php
            // Provenance transportée vers la fiche groupe : chemin RELATIF (voir
            // WithReturnBack — un absolu serait rejeté), et route() plutôt que
            // request()->fullUrl() qui vaudrait /livewire/update au re-render.
            $backToProfile = route('app.user.show', array_filter([
                'login' => $user->login,
                'from' => $from,
            ]), false);
        @endphp

        @if (count($groupDetails) > 0)
            {{-- Hauteur bornée à ~5-6 lignes puis scroll, en-tête sticky pour
                 rester lisible pendant le défilement. `table-fixed` + largeurs
                 explicites : sans elles, un nom long élargit la colonne ou passe
                 à la ligne (lignes mesurées jusqu'à 105 px) et le tableau
                 n'affichait plus que 3 lignes. --}}
            <div class="flex-1 overflow-auto min-h-0 max-h-80 rounded-xl border border-info/10 bg-base-100/60">
                <table class="table table-sm table-pin-rows table-fixed">
                    <thead>
                        <tr class="bg-base-200/50">
                            <th>Groupe</th>
                            <th class="w-24">Type</th>
                            <th class="w-24">Rôle</th>
                            <th class="w-20 text-right">Membres</th>
                            @can('update-user')
                                <th class="w-12 px-1"></th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($groupDetails as $group)
                            <tr wire:key="group-{{ $group['cn'] }}" class="group hover:bg-info/10">
                                <td>
                                    {{-- Lien vers la fiche groupe : seul endroit qui
                                         détaille membres, quota et partage.
                                         `truncate` (+ title) plutôt que le retour à
                                         la ligne : garde une hauteur de ligne
                                         constante, donc un nombre de lignes
                                         visibles prévisible. --}}
                                    @if ($group['id'])
                                        <a href="{{ route('app.users.groups.edit', ['id' => $group['id'], 'from' => $backToProfile]) }}"
                                            title="{{ $group['label'] }}"
                                            class="block font-medium truncate link link-hover">{{ $group['label'] }}</a>
                                    @else
                                        <span class="block font-medium truncate" title="{{ $group['label'] }}">{{ $group['label'] }}</span>
                                    @endif

                                    {{-- Le CN n'est rappelé que s'il diffère du nom
                                         affiché : sinon la ligne se répète. --}}
                                    @if ($group['label'] !== $group['cn'])
                                        <code class="block text-xs font-mono text-base-content/50 truncate">{{ $group['cn'] }}</code>
                                    @endif
                                </td>
                                <td>
                                    @if ($group['type_label'])
                                        <span class="badge badge-sm {{ $group['type_badge'] }}">{{ $group['type_label'] }}</span>
                                    @else
                                        <span class="text-base-content/30">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($group['edge_role_label'])
                                        <span class="badge badge-sm badge-outline gap-1">
                                            <i class="fa-solid fa-chalkboard-user text-[9px]"></i>
                                            {{ $group['edge_role_label'] }}
                                        </span>
                                    @else
                                        <span class="text-base-content/30">—</span>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    @if ($group['members_count'] !== null)
                                        {{ $group['members_count'] }}
                                    @else
                                        <span class="text-warning"
                                            title="Groupe présent dans l'annuaire mais absent du référentiel SE5">
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                            non référencé
                                        </span>
                                    @endif
                                </td>
                                @can('update-user')
                                    <td class="text-right px-1">
                                        <button type="button" title="Retirer du groupe"
                                            wire:click="removeFromGroup('{{ addslashes($group['cn']) }}')"
                                            wire:confirm="Retirer {{ $group['label'] }} de cet utilisateur ?"
                                            class="btn btn-ghost btn-xs btn-square text-error opacity-0 group-hover:opacity-100 transition-opacity">
                                            <i class="fas fa-trash text-xs"></i>
                                        </button>
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @php
                // Répartition par type, pour lire l'appartenance d'un coup d'œil
                // (« Classe ×3, Projet ×1 ») sans dérouler la liste.
                $byType = collect($groupDetails)
                    ->groupBy(fn($g) => $g['type_label'] ?? 'Non référencé')
                    ->map->count()
                    ->sortDesc();
            @endphp
            <div class="mt-3 flex items-center gap-1.5 flex-wrap text-xs text-base-content/50">
                <span>{{ count($groupDetails) }} groupe(s)</span>
                @foreach ($byType as $typeLabel => $count)
                    <span class="badge badge-xs badge-ghost">{{ $typeLabel }} ×{{ $count }}</span>
                @endforeach
            </div>
        @else
            <div class="text-center py-6">
                <svg class="w-10 h-10 mx-auto mb-2 text-base-content/20" fill="none" stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                    </path>
                </svg>
                <p class="text-base-content/40 text-sm">Aucun groupe</p>
            </div>
        @endif

        @can('update-user')
            <div class="divider my-3"></div>
            <button type="button" @click="Livewire.dispatch('open-groups-drawer', { users: ['{{ $user->login }}'] })"
                class="btn btn-sm btn-outline btn-info w-full">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6">
                    </path>
                </svg>
                Gérer les groupes
            </button>
        @endcan
    </div>
</div>
