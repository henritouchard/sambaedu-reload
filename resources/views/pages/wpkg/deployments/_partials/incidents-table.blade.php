{{--
    Story 15.5 / AC3.5 — Tableau des incidents (24h) — paginé + filtres.

    Variables attendues :
      $incidents : LengthAwarePaginator
      $severityFilter : string ('', 'partial', 'failed', 'silent')
--}}
<div class="card bg-base-100 shadow-sm" data-test="incidents-table">
    <div class="card-body">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="card-title text-lg">
                <i class="fa-solid fa-bell mr-2"></i>
                Incidents (24 dernières heures)
            </h2>

            <div class="flex flex-wrap gap-2">
                <select wire:model.live="severityFilter" class="select select-bordered select-sm" data-test="filter-severity">
                    <option value="">Toutes sévérités</option>
                    <option value="failed">En échec</option>
                    <option value="partial">Partiels</option>
                    <option value="unknown">Inconnu</option>
                </select>
            </div>
        </div>

        @if ($incidents->isEmpty())
            <div class="hero min-h-[160px]">
                <div class="hero-content text-center">
                    <div>
                        <i class="fa-solid fa-circle-check text-4xl text-success mb-3"></i>
                        <p>Aucun incident sur les 24 dernières heures.</p>
                    </div>
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Poste</th>
                            <th>Statut</th>
                            <th>Apps en échec</th>
                            <th>Reporté</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($incidents as $incident)
                            <tr data-test="incident-row">
                                <td class="font-medium">{{ $incident->workstation_name ?? 'N/A' }}</td>
                                <td>
                                    @php
                                        $badge = match ($incident->client_status) {
                                            'success' => 'badge-success',
                                            'partial' => 'badge-warning',
                                            'failed' => 'badge-error',
                                            'unknown' => 'badge-ghost',
                                            default => 'badge-ghost',
                                        };
                                    @endphp
                                    <span class="badge {{ $badge }} badge-sm">{{ $incident->client_status }}</span>
                                </td>
                                <td class="text-sm">
                                    @php
                                        $details = is_string($incident->details ?? null)
                                            ? json_decode($incident->details, true)
                                            : ($incident->details ?? null);
                                        $failed = $details['counters']['failed'] ?? 0;
                                    @endphp
                                    {{ $failed }}
                                </td>
                                <td class="text-sm text-base-content/70">
                                    {{ $incident->client_reported_at
                                        ? \Illuminate\Support\Carbon::parse($incident->client_reported_at)->diffForHumans()
                                        : '—' }}
                                </td>
                                <td>
                                    @if (! empty($incident->workstation_id))
                                        <a href="{{ route('app.wpkg.deployments.workstation', ['workstation' => $incident->workstation_id]) }}"
                                           class="btn btn-xs btn-ghost"
                                           data-test="incident-drilldown"
                                           title="Voir le détail">
                                            <i class="fa-solid fa-arrow-right"></i>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $incidents->links() }}
            </div>
        @endif
    </div>
</div>
