{{-- Story 24.7 / AC3, AC4, AC5 — Panneau conformité du groupe (onglet général).

     « Penser en règles » (décision n° 4) : un bloc par TYPE de ressource
     rapporté sur le périmètre → « n/N conformes » + la liste des SEULES
     exceptions (statut ≠ compliant, jamais rapporté, muet), datées et
     cliquables vers le détail poste. AUCUN poste conforme listé.

     Bouton « Forcer la synchro » du groupe (PULL) + wire:poll.15s BORNÉ à ce
     panneau (piège 12) pour le retour auto à compliant (AC4).
--}}
@php $cSummary = $this->conformitySummary; @endphp

@if (($cSummary['enrolled'] ?? 0) > 0)
    <div wire:poll.15s class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <div class="flex items-center justify-between mb-4">
                <h3 class="card-title text-base">
                    <i class="fa-solid fa-clipboard-check text-primary"></i>
                    Conformité agent
                    <span class="badge badge-ghost">{{ $cSummary['enrolled'] }} enrôlé(s)</span>
                    @if (($cSummary['exceptions'] ?? 0) > 0)
                        <span class="badge badge-error gap-1">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            {{ $cSummary['exceptions'] }} en écart
                        </span>
                    @endif
                </h3>

                @can('computer.control')
                <button type="button" class="btn btn-sm btn-outline btn-primary gap-2"
                        wire:click="forceSyncGroup"
                        wire:confirm="Forcer la resynchronisation de tous les postes enrôlés (hors quarantaine) de ce groupe ?">
                    <i class="fa-solid fa-rotate"></i>
                    Forcer la synchro du groupe
                </button>
                @endcan
            </div>

            {{-- Mini-compteurs du périmètre --}}
            <div class="flex flex-wrap gap-2 mb-4">
                <span class="badge badge-success gap-1">
                    <i class="fa-solid fa-circle-check"></i> {{ $cSummary['compliant'] ?? 0 }} conformes
                </span>
                @if (($cSummary['drifted_allowed'] ?? 0) > 0)
                    <span class="badge badge-info gap-1">
                        <i class="fa-solid fa-circle-info"></i> {{ $cSummary['drifted_allowed'] }} dérive tolérée
                    </span>
                @endif
                @if (($cSummary['silent'] ?? 0) > 0)
                    <span class="badge badge-warning gap-1">
                        <i class="fa-solid fa-volume-xmark"></i> {{ $cSummary['silent'] }} muet(s)
                    </span>
                @endif
                @if (($cSummary['never_reported'] ?? 0) > 0)
                    <span class="badge badge-ghost gap-1">
                        <i class="fa-solid fa-circle-question"></i> {{ $cSummary['never_reported'] }} jamais rapporté
                    </span>
                @endif
            </div>

            {{-- Un bloc par type de ressource rapporté --}}
            @php $byType = $this->conformityByType; @endphp
            @if (empty($byType))
                <p class="text-sm text-base-content/60">
                    Aucun état rapporté pour les postes enrôlés de ce groupe.
                </p>
            @else
                <div class="space-y-4">
                    @foreach ($byType as $block)
                        <div class="border border-base-200 rounded-lg p-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-mono font-semibold text-sm">{{ $block['type'] }}</span>
                                <span class="text-sm {{ $block['compliant'] === $block['total'] ? 'text-success' : 'text-base-content/70' }}">
                                    {{ $block['compliant'] }}/{{ $block['total'] }} conformes
                                </span>
                            </div>

                            @if (empty($block['exceptions']))
                                <p class="text-xs text-success">
                                    <i class="fa-solid fa-circle-check mr-1"></i>
                                    Tous les postes enrôlés sont conformes sur ce type.
                                </p>
                            @else
                                <ul class="divide-y divide-base-200">
                                    @foreach ($block['exceptions'] as $exc)
                                        <li class="flex items-center justify-between py-1.5">
                                            <a href="{{ route('app.parc.machines.show', $exc['workstation_id']) }}"
                                               class="flex items-center gap-2 hover:text-primary">
                                                <i class="fa-solid fa-computer text-base-content/40 text-xs"></i>
                                                <span class="text-sm font-medium">{{ $exc['name'] }}</span>
                                            </a>
                                            <div class="flex items-center gap-3">
                                                @if (!empty($exc['detail']))
                                                    <span class="text-xs text-base-content/50 max-w-xs truncate"
                                                          title="{{ $exc['detail'] }}">
                                                        {{ Str::limit($exc['detail'], 40) }}
                                                    </span>
                                                @endif
                                                @if ($exc['reported_at'])
                                                    <span class="text-xs text-base-content/40"
                                                          title="{{ $exc['reported_at'] }}">
                                                        {{ $exc['reported_at']->diffForHumans() }}
                                                    </span>
                                                @endif
                                                <x-atoms.conformity-badge :status="$exc['status']" />
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endif
