{{--
    Story 8.1 — Tableau détaillé du rapport d'import CSV DHCP (AC5).

    Variable attendue :
      - $report : App\Services\Network\Data\ImportReport
--}}

@if (count($report->rows) === 0)
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <span>Aucune ligne à afficher.</span>
    </div>
@else
    <div class="overflow-x-auto">
        <table class="table table-zebra table-sm w-full">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nom</th>
                    <th>MAC</th>
                    <th>IP</th>
                    <th>Statut</th>
                    <th>Action</th>
                    <th>Raison / Détail</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->rows as $row)
                    <tr>
                        <td class="font-mono text-xs">{{ $row->line ?: '—' }}</td>
                        <td class="font-mono">{{ $row->name }}</td>
                        <td class="font-mono text-xs">{{ $row->mac }}</td>
                        <td class="font-mono">{{ $row->ip }}</td>
                        <td>
                            <span class="badge badge-sm
                                @switch($row->status)
                                    @case('ok') badge-success @break
                                    @case('error') badge-error @break
                                    @case('skipped') badge-ghost @break
                                @endswitch
                            ">
                                {{ $row->status }}
                            </span>
                        </td>
                        <td class="text-xs">{{ $row->action }}</td>
                        <td class="text-xs">{{ $row->reason }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
