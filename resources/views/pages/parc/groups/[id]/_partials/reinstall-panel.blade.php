{{--
    Story 3.11 — Panneau des réinstallations armées / en cours pour les postes
    de la salle/groupe. Le composant hôte expose le computed
    `reinstallActiveRequests` (Collection<WorkstationReinstallRequest>) et la
    méthode `cancelReinstallRequest(int $id)`.
--}}
@php
    $requests = $this->reinstallActiveRequests;
    // Traduction des valeurs techniques (enum OS, statut) en libellés affichables.
    $reinstallService = app(\App\Services\Parc\WorkstationReinstallService::class);
@endphp
@if ($requests->isNotEmpty())
    <div class="card bg-base-100 shadow-sm border border-warning/40">
        <div class="card-body">
            <h3 class="card-title text-base">
                <i class="fa-solid fa-arrows-rotate text-warning"></i>
                Réinstallations en cours ({{ $requests->count() }})
            </h3>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Poste</th>
                            <th>OS</th>
                            <th>État</th>
                            <th>Planifiée</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $req)
                            <tr wire:key="reinstall-req-{{ $req->id }}">
                                <td class="font-medium">{{ $req->workstation?->name ?? '—' }}</td>
                                <td>{{ $reinstallService->labelFor($req->target_action) }}</td>
                                <td><span class="badge badge-warning badge-sm">{{ $req->statusLabel() }}</span></td>
                                <td class="text-sm text-base-content/60">
                                    {{ $req->scheduled_at?->format('d/m/Y H:i') ?? 'immédiat' }}
                                </td>
                                <td class="text-right">
                                    @can('computer.install')
                                        @if ($req->isCancelable())
                                            <button type="button" class="btn btn-xs btn-ghost"
                                                wire:click="cancelReinstallRequest({{ $req->id }})"
                                                wire:confirm="Annuler la réinstallation de ce poste ?">
                                                Annuler
                                            </button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
