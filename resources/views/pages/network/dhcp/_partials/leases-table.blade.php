{{--
    Story 8.1 — Table des baux DHCP actifs (AC1).

    Variables attendues :
      - $leases : Collection<int,array{ip,mac,hostname,state,ends_at}>
      - $leasesAvailable : bool
--}}

@if (!$leasesAvailable)
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>Lecture des baux indisponible (service injoignable ou fichier `dhcpd.leases` introuvable).</span>
    </div>
@elseif ($leases->isEmpty())
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <span>Aucun bail DHCP actif détecté (hors réservations).</span>
    </div>
@else
    <div class="overflow-x-auto">
        <table class="table table-zebra w-full">
            <thead>
                <tr>
                    <th>Hostname</th>
                    <th>MAC</th>
                    <th>IP</th>
                    <th>État</th>
                    <th>Expire</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($leases as $lease)
                    <tr>
                        <td class="font-mono text-xs">{{ $lease['hostname'] ?? '—' }}</td>
                        <td class="font-mono text-xs">{{ $lease['mac'] }}</td>
                        <td class="font-mono">{{ $lease['ip'] }}</td>
                        <td>
                            <span class="badge {{ $lease['state'] === 'active' ? 'badge-success' : 'badge-ghost' }}">
                                {{ $lease['state'] }}
                            </span>
                        </td>
                        <td class="text-xs text-base-content/60">{{ $lease['ends_at'] ?? '—' }}</td>
                        <td class="text-right">
                            <button type="button"
                                wire:click="preFillFromLeaseByIndex({{ $loop->index }})"
                                class="btn btn-sm btn-outline btn-primary"
                                @cannot('manage-dhcp') disabled @endcannot>
                                <i class="fa-solid fa-bookmark"></i> Réserver ce bail
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
