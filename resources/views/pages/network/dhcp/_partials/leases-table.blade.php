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
    <div class="card bg-base-100 border border-base-300">
        <div class="card-body items-center text-center py-10">
            <div class="text-4xl mb-3 opacity-20">
                <i class="fa-solid fa-plug-circle-xmark"></i>
            </div>
            <p class="text-base-content/60 text-sm">Aucun bail DHCP actif détecté (hors réservations).</p>
        </div>
    </div>
@else
    <div class="card bg-base-100 border border-base-300 overflow-hidden">
        <div class="overflow-auto max-h-[55vh]">
            <table class="table table-zebra w-full">
                <thead class="sticky top-0 z-10 highlight">
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
    </div>
@endif
