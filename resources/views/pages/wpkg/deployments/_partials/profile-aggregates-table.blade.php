{{--
    Story 15.5 / AC3.4 — Vue agrégée par profil (app_profiles).

    Variables attendues :
      $profileAggregates : list<array{profile_id, profile_name, total, success, partial, failed}>
--}}
<div class="card bg-base-100 shadow-sm" data-test="profile-aggregates">
    <div class="card-body">
        <h2 class="card-title text-lg">
            <i class="fa-solid fa-cubes mr-2"></i>
            Statut par profil applicatif
        </h2>

        @if (empty($profileAggregates))
            <p class="text-base-content/60 text-sm py-2">Aucun profil à afficher.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Profil</th>
                            <th class="text-center">Postes ciblés</th>
                            <th class="text-center">Sains</th>
                            <th class="text-center">Partiels</th>
                            <th class="text-center">Échec</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($profileAggregates as $row)
                            <tr data-test="profile-row" data-profile-id="{{ $row['profile_id'] }}">
                                <td class="font-medium">{{ $row['profile_name'] }}</td>
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
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
