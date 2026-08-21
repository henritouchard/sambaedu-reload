{{--
    LES FICHES des dossiers et leur matrice.

    La vue ne DÉCIDE rien : cases cochées, cases grisées, explications, notes de
    dégradation et note de nœud mixte sont calculées par le composant, qui les
    obtient de la DÉCLARATION du backend. Aucune règle n'est recopiée ici, et
    aucune constante n'énumère les combinaisons exprimables.

    Il n'y a AUCUN champ de traversée, AUCUN champ d'interdiction, AUCUNE priorité :
    la traversée est dérivée par le backend, et un octroi est positif.

    Les fiches suivent l'ordre de l'ARBRE, pas celui du JSON stocké : c'est ce qui
    rend le clic depuis l'arbre lisible, et l'ordre stocké reste intact.
--}}
@php($nodesByIndex = collect($this->editorNodes)->keyBy('index'))

<div class="card bg-base-100 shadow-sm border border-base-300">
    <div class="card-body p-5 gap-4">

        <div>
            <h2 class="font-semibold flex items-center gap-2">
                <i class="fa-solid fa-folder-open text-primary"></i> Fiches des dossiers
            </h2>
            <p class="text-xs text-base-content/70 mt-1">
                Un dossier ne porte qu'un <strong>nom</strong> : sa place dans l'arbre lui donne son chemin. Le
                renommer emporte ses sous-dossiers.
            </p>
        </div>

        @foreach ($this->treeRows as $row)
            @php($node = $nodesByIndex[$row['index']] ?? null)
            @continue($node === null)

            @php($focused = $focusedNode === $node['index'])

            <div class="rounded-box border p-4 flex flex-col gap-3 scroll-mt-24 {{ $focused ? 'border-primary ring-2 ring-primary/20' : 'border-base-300' }}"
                wire:key="node-{{ $node['index'] }}-{{ $focused ? 'focus' : 'idle' }}"
                data-testid="node-{{ $node['index'] }}"
                @if ($focused) x-data x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'nearest' })" @endif>

                <div class="flex items-center gap-3 flex-wrap">
                    <i class="fa-solid fa-folder {{ $row['nature_tone'] }} opacity-70" aria-hidden="true"></i>
                    <span class="font-mono font-semibold">{{ $row['segment'] }}</span>
                    <span class="text-sm opacity-70">{{ $node['label'] }}</span>
                    <span class="badge badge-sm badge-ghost">{{ $node['nature_label'] }}</span>
                    @if ($focused)
                        <span class="badge badge-sm badge-info" data-testid="focused-{{ $node['index'] }}">
                            ciblé depuis l'arbre
                        </span>
                    @endif
                    <div class="ml-auto flex items-center gap-2">
                        <button type="button" class="btn btn-ghost btn-xs"
                            wire:click="addChildNode({{ $node['index'] }})"
                            data-testid="card-add-child-{{ $node['index'] }}">
                            <i class="fa-solid fa-plus"></i> Sous-dossier ici
                        </button>
                        <button type="button" class="btn btn-ghost btn-xs text-error"
                            wire:click="removeNode({{ $node['index'] }})"
                            data-testid="remove-node-{{ $node['index'] }}">
                            <i class="fa-solid fa-trash"></i> Retirer
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="flex flex-col gap-1">
                        <label class="label" for="node-name-{{ $node['index'] }}">
                            <span class="label-text font-medium">
                                Nom du dossier <span class="text-error" aria-hidden="true">*</span>
                            </span>
                        </label>
                        @if ($node['is_root'])
                            <span class="input input-bordered input-sm w-full bg-base-200 font-mono items-center"
                                data-testid="node-root-{{ $node['index'] }}">{{ $row['segment'] }}</span>
                            <span class="text-xs opacity-60">
                                C'est le dossier racine : son nom est le <strong>motif de chemin</strong> de la
                                recette, plus haut.
                            </span>
                        @else
                            <input id="node-name-{{ $node['index'] }}" type="text"
                                wire:key="node-name-{{ $node['index'] }}-{{ $node['segment'] }}"
                                value="{{ $node['segment'] }}"
                                wire:blur="renameSegment({{ $node['index'] }}, $event.target.value)"
                                class="input input-bordered input-sm w-full font-mono"
                                data-testid="node-name-{{ $node['index'] }}" />
                            <div class="flex flex-wrap gap-1 mt-1">
                                @foreach ($node['placeholders'] as $placeholder)
                                    <button type="button" class="btn btn-ghost btn-xs font-mono"
                                        title="{{ $placeholder['help'] }}"
                                        wire:click="insertPlaceholder({{ $node['index'] }}, '{{ $placeholder['token'] }}')"
                                        data-testid="node-{{ $node['index'] }}-placeholder-{{ $placeholder['token'] }}">
                                        &#123;{{ $placeholder['token'] }}&#125;
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-col gap-1">
                        <span class="label-text font-medium">Sous</span>
                        <span class="input input-bordered input-sm w-full bg-base-200 font-mono text-xs items-center overflow-x-auto whitespace-nowrap"
                            data-testid="node-parent-{{ $node['index'] }}">
                            {{ $node['parent_display'] !== '' ? $node['parent_display'] : '—' }}
                        </span>
                        <span class="text-xs opacity-60">
                            La place découle de l'arbre — profondeur {{ $node['depth'] }}.
                        </span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="label" for="node-label-{{ $node['index'] }}">
                            <span class="label-text font-medium">
                                Libellé <span class="text-error" aria-hidden="true">*</span>
                            </span>
                        </label>
                        <input id="node-label-{{ $node['index'] }}" type="text"
                            wire:model.blur="nodesSpec.{{ $node['index'] }}.label"
                            class="input input-bordered input-sm w-full"
                            data-testid="node-label-{{ $node['index'] }}" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="flex flex-col gap-1">
                        <label class="label" for="node-nature-{{ $node['index'] }}">
                            <span class="label-text font-medium">Nature</span>
                        </label>
                        <select id="node-nature-{{ $node['index'] }}"
                            wire:model.live="nodesSpec.{{ $node['index'] }}.nature"
                            class="select select-bordered select-sm w-full"
                            data-testid="node-nature-{{ $node['index'] }}">
                            @foreach ($this->natureOptions as $value => $natureLabel)
                                <option value="{{ $value }}">{{ $natureLabel }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs opacity-60">{{ $node['nature_help'] }}</span>
                    </div>

                    @if ($node['edge_role_offered'])
                        <div class="flex flex-col gap-1">
                            <label class="label" for="node-edge-{{ $node['index'] }}">
                                <span class="label-text font-medium">Membres énumérés</span>
                            </label>
                            <select id="node-edge-{{ $node['index'] }}"
                                wire:model.live="nodesSpec.{{ $node['index'] }}.edge_role"
                                class="select select-bordered select-sm w-full"
                                data-testid="node-edge-{{ $node['index'] }}">
                                <option value="">Choisir…</option>
                                @foreach ($this->edgeRoleOptions as $value => $edgeLabel)
                                    <option value="{{ $value }}">{{ $edgeLabel }}</option>
                                @endforeach
                            </select>
                            @if ($node['edge_role_stale'])
                                <span class="text-xs text-warning" data-testid="stale-edge-{{ $node['index'] }}">
                                    Ce dossier n'énumère plus de membres : ce rôle n'y a plus de sens.
                                    <button type="button" class="link link-warning"
                                        wire:click="clearEdgeRole({{ $node['index'] }})"
                                        data-testid="clear-edge-{{ $node['index'] }}">Le retirer</button>
                                </span>
                            @endif
                        </div>
                    @endif

                    <div class="flex flex-col gap-1">
                        <label class="label" for="node-plafond-{{ $node['index'] }}">
                            <span class="label-text font-medium">Plafond (octets)</span>
                        </label>
                        <input id="node-plafond-{{ $node['index'] }}" type="text"
                            wire:model.blur="nodesSpec.{{ $node['index'] }}.plafond"
                            class="input input-bordered input-sm w-full"
                            data-testid="node-plafond-{{ $node['index'] }}" />
                        @if ($node['plafond_human'] !== '')
                            <span class="text-xs opacity-60"
                                data-testid="node-plafond-human-{{ $node['index'] }}">
                                soit {{ $node['plafond_human'] }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- LA MATRICE : audiences × verbes. --}}
                <div class="overflow-x-auto">
                    <table class="table table-xs" data-testid="matrix-{{ $node['index'] }}">
                        <thead>
                            <tr>
                                <th>Audience</th>
                                @foreach (\App\Services\Filesystem\Plan\PlanGrant::VERBS as $verb)
                                    <th class="text-center">
                                        {{ \App\Services\Filesystem\PlanStateComparator::verbLabel($verb) }}
                                    </th>
                                @endforeach
                                <th class="text-center">Suspendable</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($node['columns'] as $column)
                                <tr wire:key="row-{{ $node['index'] }}-{{ $column['role'] }}">
                                    <td>
                                        <span class="font-medium">{{ $column['label'] }}</span>
                                        @if ($column['is_member_token'])
                                            <span class="badge badge-xs badge-ghost ml-1">nominatif</span>
                                        @endif
                                        @foreach ($column['notes'] as $note)
                                            <p class="text-xs text-warning mt-1"
                                                data-testid="grant-note-{{ $node['index'] }}-{{ $column['role'] }}">
                                                {{ $note }}
                                            </p>
                                        @endforeach
                                    </td>
                                    @foreach ($column['cells'] as $cell)
                                        <td class="text-center">
                                            <label class="inline-flex flex-col items-center gap-0"
                                                title="{{ $cell['reason'] }}">
                                                <input type="checkbox"
                                                    class="checkbox checkbox-xs checkbox-primary"
                                                    wire:key="cell-{{ $node['index'] }}-{{ $column['role'] }}-{{ $cell['verb'] }}-{{ $cell['checked'] ? 1 : 0 }}"
                                                    wire:click="toggleVerb({{ $node['index'] }}, '{{ $column['role'] }}', '{{ $cell['verb'] }}')"
                                                    @checked($cell['checked'])
                                                    @disabled($cell['disabled'])
                                                    data-testid="verb-{{ $node['index'] }}-{{ $column['role'] }}-{{ $cell['verb'] }}"
                                                    aria-label="{{ $cell['label'] }} — {{ $column['label'] }}" />
                                                @if ($cell['inexpressible'])
                                                    <span class="text-[0.65rem] text-warning leading-tight"
                                                        data-testid="inexpressible-{{ $node['index'] }}-{{ $column['role'] }}-{{ $cell['verb'] }}">
                                                        non exprimable
                                                    </span>
                                                @endif
                                            </label>
                                        </td>
                                    @endforeach
                                    <td class="text-center">
                                        @if ($column['suspendable_offered'])
                                            <input type="checkbox" class="checkbox checkbox-xs"
                                                wire:key="susp-{{ $node['index'] }}-{{ $column['role'] }}-{{ $column['suspendable'] ? 1 : 0 }}"
                                                wire:click="toggleSuspendable({{ $node['index'] }}, '{{ $column['role'] }}')"
                                                @checked($column['suspendable'])
                                                data-testid="suspendable-{{ $node['index'] }}-{{ $column['role'] }}"
                                                aria-label="Octroi suspendable — {{ $column['label'] }}" />
                                            @if ($column['suspendable_orphan'])
                                                <span class="text-[0.65rem] text-warning block leading-tight"
                                                    data-testid="orphan-suspendable-{{ $node['index'] }}-{{ $column['role'] }}">
                                                    sans effet ici
                                                </span>
                                            @endif
                                        @else
                                            <span class="opacity-30">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($node['columns'] === [])
                    <p class="text-xs opacity-50">Aucune audience déclarée : ajoutez-en une plus haut.</p>
                @endif

                @if ($node['node_note'] !== null)
                    <p class="text-xs text-warning" data-testid="node-note-{{ $node['index'] }}">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        {{ $node['node_note'] }}
                    </p>
                @endif
            </div>
        @endforeach

    </div>
</div>
