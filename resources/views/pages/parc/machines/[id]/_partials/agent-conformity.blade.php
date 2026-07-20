{{-- Story 24.7 / AC2, AC4, AC5 — Conformité agent de la fiche poste.

     Extension de la card Agent (23.2) : état rapporté PAR TYPE (3 statuts —
     Story 27.8 : `drifted_allowed` retiré), derniers événements datés, états
     dérivés (jamais rapporté / muet), bouton « Forcer la synchro » (PULL) +
     état de la demande pendante.

     wire:poll.15s BORNÉ à ce bloc (piège 12) : relit agent_resource_states
     → retour auto à compliant (AC4) + disparition de la demande au solde
     (AC5). Le poll n'est rendu que pour un poste ENRÔLÉ.
--}}
@if ($workstation->isAgentEnrolled())
    <div @if ($workstation->isAgentEnrolled() && !$workstation->isAgentQuarantined()) wire:poll.15s @endif
         class="mt-6 pt-6 border-t border-base-200">

        {{-- En-tête + bouton « Forcer la synchro » --}}
        <div class="flex items-center justify-between mb-4">
            <h4 class="font-semibold text-sm flex items-center gap-2">
                <i class="fa-solid fa-clipboard-check text-primary"></i>
                État rapporté par type
            </h4>

            @php $syncPending = $workstation->hasAgentSyncPending(); @endphp
            @can('computer.control')
            @if ($workstation->isAgentQuarantined())
                <button type="button" class="btn btn-sm btn-outline gap-2" disabled
                        title="Poste en quarantaine : ne rapporte pas, la synchro ne peut pas être forcée">
                    <i class="fa-solid fa-rotate"></i>
                    Forcer la synchro
                </button>
            @else
                <button type="button" class="btn btn-sm btn-outline btn-primary gap-2"
                        wire:click="forceSyncWorkstation"
                        wire:confirm="Forcer la resynchronisation de ce poste ? L'état complet lui sera re-servi au prochain check-in (≤ 1 cycle agent)."
                        @disabled($syncPending)>
                    <i class="fa-solid fa-rotate"></i>
                    {{ $syncPending ? 'Synchro demandée' : 'Forcer la synchro' }}
                </button>
            @endif
            @endcan
        </div>

        {{-- État de la demande pendante (AC5) --}}
        @if ($workstation->hasAgentSyncPending())
            <div class="alert alert-info py-2 mb-4 text-sm">
                <i class="fa-solid fa-hourglass-half"></i>
                <span>
                    Synchro demandée le {{ $workstation->agent_sync_requested_at?->format('d/m/Y H:i') }} —
                    en attente du prochain check-in du poste.
                </span>
            </div>
        @endif

        {{-- Poste muet (état dérivé, décision n° 7) --}}
        @if ($workstation->isAgentSilent())
            <div class="alert alert-warning py-2 mb-4 text-sm">
                <i class="fa-solid fa-volume-xmark"></i>
                <span>
                    Poste muet : aucun check-in récent
                    (dernier le {{ $workstation->agent_last_checkin_at?->format('d/m/Y H:i') }}).
                </span>
            </div>
        @endif

        {{-- Table état par type --}}
        @if ($this->agentStates->isEmpty())
            <p class="text-sm text-base-content/60 mb-4">
                <i class="fa-solid fa-circle-question mr-1"></i>
                Jamais rapporté : ce poste est enrôlé mais n'a encore remonté aucun état.
            </p>
        @else
            <div class="overflow-x-auto mb-4">
                <table class="table table-sm">
                    <thead>
                        <tr class="bg-base-200">
                            <th>Type</th>
                            <th class="text-center">Statut</th>
                            {{-- Ancienneté du statut COURANT : un écart de quelques
                                 minutes est une convergence en cours (le premier
                                 passage d'un poste réinstallé est non conforme par
                                 construction) ; un écart de plusieurs jours est
                                 installé. La politique STRICT (27.8) ne distinguant
                                 pas les deux par le statut, c'est cette colonne qui
                                 le fait. --}}
                            <th>Depuis</th>
                            <th>Rapporté</th>
                            <th>Détail</th>
                            <th>Hash</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->agentStates as $state)
                            <tr class="hover">
                                <td class="font-mono text-sm">{{ $state->type }}</td>
                                <td class="text-center">
                                    <x-atoms.conformity-badge :status="$state->status->value" />
                                </td>
                                @php
                                    // La transition ne vaut « depuis » que si elle a
                                    // mené au statut AFFICHÉ — sinon l'événement est
                                    // antérieur au statut courant et mentirait.
                                    $held = $this->agentStatusHeldSince->get($state->type);
                                    $heldSince = $held && $held->status === $state->status
                                        ? $held->created_at
                                        : null;
                                @endphp
                                <td class="text-sm" title="{{ $heldSince ?? 'Aucune transition enregistrée' }}">
                                    {{ $heldSince?->diffForHumans() ?? '—' }}
                                </td>
                                <td class="text-sm" title="{{ $state->reported_at }}">
                                    {{ $state->reported_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td class="text-sm text-base-content/70">
                                    {{ $state->detail ? Str::limit($state->detail, 80) : '—' }}
                                </td>
                                {{-- Hash OPAQUE, tronqué, jamais interprété (piège 9). --}}
                                <td class="font-mono text-xs text-base-content/40"
                                    title="Hash opaque (non interprété)">
                                    {{ Str::limit($state->hash, 10, '…') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Derniers événements (10, datés) --}}
        @if ($this->agentEvents->isNotEmpty())
            <h4 class="font-semibold text-sm flex items-center gap-2 mb-2">
                <i class="fa-solid fa-clock-rotate-left text-base-content/60"></i>
                Derniers événements
            </h4>
            <div class="overflow-x-auto">
                <table class="table table-xs">
                    <thead>
                        <tr class="bg-base-200">
                            <th>Quand</th>
                            <th>Type</th>
                            <th>Transition</th>
                            <th>Détail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->agentEvents as $event)
                            <tr class="hover">
                                <td class="text-xs" title="{{ $event->created_at }}">
                                    {{ $event->created_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td class="font-mono text-xs">{{ $event->type }}</td>
                                <td class="text-xs">
                                    <span class="text-base-content/50">
                                        {{ $event->previous_status?->value ?? '∅' }}
                                    </span>
                                    <i class="fa-solid fa-arrow-right-long mx-1 text-base-content/30"></i>
                                    <span class="font-medium">{{ $event->status->value }}</span>
                                </td>
                                <td class="text-xs text-base-content/70">
                                    {{ $event->detail ? Str::limit($event->detail, 60) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
