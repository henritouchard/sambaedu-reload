{{--
    Story 15.5 / AC3.3 — Vue agrégée par parc (workstation_groups).

    Variables attendues :
      $groupAggregates : list<array{group_id, group_name, total, success, partial, failed, silent}>
--}}
<div class="card bg-base-100 shadow-sm" data-test="group-aggregates">
    <div class="card-body">
        <h2 class="card-title text-lg">
            <i class="fa-solid fa-layer-group mr-2"></i>
            Statut par parc
        </h2>

        @if (empty($groupAggregates))
            <p class="text-base-content/60 text-sm py-2">Aucun parc à afficher.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Parc</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Sains</th>
                            <th class="text-center">Partiels</th>
                            <th class="text-center">Échec</th>
                            <th class="text-center">Silencieux</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($groupAggregates as $row)
                            <tr data-test="group-row" data-group-id="{{ $row['group_id'] }}">
                                <td class="font-medium">{{ $row['group_name'] }}</td>
                                <td class="text-center">{{ $row['total'] }}</td>
                                <td class="text-center">
                                    <span class="badge badge-success badge-sm">{{ $row['success'] }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-warning badge-sm">{{ $row['partial'] }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-error badge-sm">{{ $row['failed'] }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-ghost badge-sm">{{ $row['silent'] }}</span>
                                </td>
                                <td>
                                    <a href="{{ route('app.parc.groups.show', ['id' => $row['group_id']]) }}"
                                       class="btn btn-xs btn-ghost"
                                       title="Voir le parc">
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
