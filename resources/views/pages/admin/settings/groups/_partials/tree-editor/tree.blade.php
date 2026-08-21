{{--
    L'ARBRE — la carte de l'arborescence.

    Il ne porte AUCUN champ : il montre la hiérarchie, résume les octrois en
    quatre pastilles par audience, et sert de point d'entrée vers la fiche du
    dossier (« viser ») et vers la création d'un enfant (« sous-dossier »).

    Tout ce qu'il affiche vient de `treeRows`, calculé par le composant. La
    profondeur, les initiales des verbes et la teinte de nature y sont DÉRIVÉES
    des autorités (chemin, libellé de verbe, enum de nature) — aucune n'est
    recopiée ici.
--}}
<div class="card bg-base-100 shadow-sm border border-base-300">
    <div class="card-body p-0">

        <div class="flex items-start justify-between gap-4 p-5 pb-4">
            <div>
                <h2 class="font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-sitemap text-primary"></i> Arborescence
                </h2>
                <p class="text-xs text-base-content/70 mt-1">
                    Survolez un dossier pour lire ses droits en clair. Cliquez-le pour ouvrir sa fiche en dessous.
                </p>
            </div>
            <button type="button" class="btn btn-outline btn-sm" wire:click="addNode" data-testid="add-node">
                <i class="fa-solid fa-plus"></i> Dossier à la racine
            </button>
        </div>

        @forelse ($this->treeRows as $row)
            <div class="group relative flex items-center gap-2 px-5 py-2 border-t border-base-300 hover:bg-base-200 {{ $focusedNode === $row['index'] ? 'bg-primary/10' : '' }}"
                wire:key="tree-{{ $row['index'] }}" data-testid="tree-node-{{ $row['index'] }}">

                <button type="button" class="flex items-center gap-2 min-w-0 grow text-left"
                    wire:click="focusNode({{ $row['index'] }})"
                    data-testid="focus-node-{{ $row['index'] }}"
                    aria-label="Voir la fiche du dossier {{ $row['segment'] }}">
                    <span class="shrink-0" style="width: {{ $row['depth'] * 22 }}px"></span>
                    @if ($row['depth'] > 0)
                        <span class="shrink-0 w-3 h-3 -mt-3 border-l border-b border-base-300 rounded-bl" aria-hidden="true"></span>
                    @endif
                    <i class="fa-solid fa-folder {{ $row['nature_tone'] }} opacity-70 shrink-0" aria-hidden="true"></i>
                    <span class="font-mono text-sm font-semibold truncate">{{ $row['segment'] }}</span>
                    <span class="text-xs text-base-content/60 truncate hidden xl:inline">{{ $row['label'] }}</span>
                    @if (! $row['is_root'])
                        <span class="badge badge-xs badge-ghost shrink-0 hidden 2xl:inline-flex">{{ $row['nature_label'] }}</span>
                    @endif
                </button>

                <div class="flex items-center gap-4 shrink-0">
                    @foreach ($row['audiences'] as $audience)
                        <span class="flex items-center gap-1.5 {{ $audience['has_grant'] ? '' : 'opacity-40' }}"
                            wire:key="tree-{{ $row['index'] }}-{{ $audience['role'] }}">
                            <span class="text-[0.65rem] text-base-content/60 max-w-24 truncate hidden lg:inline">{{ $audience['label'] }}</span>
                            <span class="flex gap-0.5" data-testid="marks-{{ $row['index'] }}-{{ $audience['role'] }}">
                                @foreach ($audience['marks'] as $mark)
                                    <span class="w-4 h-4 rounded-sm font-mono text-[0.6rem] font-bold flex items-center justify-center {{ $mark['on'] ? ($audience['suspendable'] ? 'bg-warning text-warning-content' : 'bg-primary text-primary-content') : 'bg-base-300 text-base-content/40' }}"
                                        aria-hidden="true">{{ $mark['letter'] }}</span>
                                @endforeach
                            </span>
                        </span>
                    @endforeach

                    <button type="button" class="btn btn-ghost btn-xs"
                        wire:click="addChildNode({{ $row['index'] }})"
                        data-testid="add-child-{{ $row['index'] }}">
                        <i class="fa-solid fa-plus"></i> sous-dossier
                    </button>
                </div>

                {{-- Le survol dit en toutes lettres ce que les pastilles abrègent.
                     La première ligne l'ouvre vers le BAS : au-dessus d'elle il n'y
                     a que l'en-tête de la carte. --}}
                <div class="hidden group-hover:flex group-focus-within:flex flex-col gap-2 absolute right-5 z-20 w-80 rounded-box bg-neutral text-neutral-content p-3 shadow-lg text-xs {{ $loop->first ? 'top-full mt-1' : 'bottom-full mb-1' }}"
                    role="tooltip" data-testid="tree-tip-{{ $row['index'] }}">
                    <span class="font-mono text-[0.65rem] uppercase tracking-wider opacity-60">
                        {{ $row['is_root'] ? $row['segment'] : $row['path'] }}
                    </span>
                    @foreach ($row['audiences'] as $audience)
                        <span class="flex gap-2">
                            <span class="font-semibold shrink-0 w-28 truncate">{{ $audience['label'] }}</span>
                            <span class="opacity-80">
                                {{ $audience['summary'] !== '' ? $audience['summary'] : 'ne reçoit rien ici' }}
                                @if ($audience['suspendable'])
                                    <span class="opacity-70">(suspendable)</span>
                                @endif
                            </span>
                        </span>
                    @endforeach
                    <span class="border-t border-neutral-content/20 pt-2 opacity-80">{{ $row['nature_label'] }}</span>
                </div>
            </div>
        @empty
            <p class="text-sm opacity-50 px-5 pb-5 border-t border-base-300 pt-4" data-testid="no-node">
                Aucun dossier déclaré. L'arborescence se résumera à son dossier racine.
            </p>
        @endforelse

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-base-content/70 bg-info/10 px-5 py-3 border-t border-base-300">
            <span>
                @foreach (\App\Services\Filesystem\Plan\PlanGrant::VERBS as $verb)
                    @php($verbLabel = \App\Services\Filesystem\PlanStateComparator::verbLabel($verb))
                    <strong class="font-mono">{{ mb_strtoupper(mb_substr($verbLabel, 0, 1)) }}</strong>
                    {{ mb_strtolower($verbLabel) }}@if (! $loop->last) · @endif
                @endforeach
            </span>
            <span>En <span class="text-warning font-medium">orange</span>, un octroi <strong>suspendable</strong>.</span>
            <span>Une audience toute éteinte ne reçoit rien sur ce dossier — sa clôture est
                <strong>calculée</strong>, jamais un refus écrit.</span>
        </div>

    </div>
</div>
