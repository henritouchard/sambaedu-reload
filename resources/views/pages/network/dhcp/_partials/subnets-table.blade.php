{{--
    Story 8.3 — Table des sous-réseaux DHCP (VLAN) + carte sous-réseau par défaut.

    Variables attendues :
      - $subnets       : Illuminate\Support\Collection de App\Models\DhcpSubnet (triés vlan_id)
      - $defaultSubnet : array{network,netmask,gateway,begin_range,end_range} (lecture seule)
--}}

<div class="space-y-4">
    {{-- Carte : sous-réseau par défaut (lecture seule) --}}
    <div class="card bg-base-100 border border-base-300">
        <div class="card-body py-4">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <h3 class="font-semibold flex items-center gap-2">
                    <i class="fa-solid fa-house-signal text-base-content/50"></i>
                    Sous-réseau par défaut
                </h3>
                <span class="badge badge-ghost">géré par l'autoconf serveur</span>
            </div>
            @if (($defaultSubnet['network'] ?? '') !== '')
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm mt-2">
                    <div>
                        <div class="text-xs text-base-content/50">Réseau</div>
                        <div class="font-mono">{{ $defaultSubnet['network'] }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/50">Masque</div>
                        <div class="font-mono">{{ $defaultSubnet['netmask'] ?: '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/50">Passerelle</div>
                        <div class="font-mono">{{ $defaultSubnet['gateway'] ?: '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/50">Plage</div>
                        <div class="font-mono">
                            @if (($defaultSubnet['begin_range'] ?? '') !== '')
                                {{ $defaultSubnet['begin_range'] }} → {{ $defaultSubnet['end_range'] }}
                            @else
                                —
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <p class="text-sm text-base-content/50 mt-2">
                    Aucun sous-réseau par défaut détecté dans la configuration serveur
                    (<code>dhcp.conf</code>).
                </p>
            @endif
        </div>
    </div>

    {{-- Table des VLAN gérés --}}
    @if ($subnets->isEmpty())
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body items-center text-center py-12">
                <div class="text-5xl mb-4 opacity-20">
                    <i class="fa-solid fa-sitemap"></i>
                </div>
                <h3 class="text-lg font-semibold mb-1">Aucun sous-réseau (VLAN) géré</h3>
                <p class="text-base-content/60 text-sm">
                    Créez un sous-réseau pour desservir un VLAN supplémentaire (routage inter-VLAN).
                </p>
            </div>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300 overflow-hidden">
            <div class="overflow-auto max-h-[60vh]">
                <table class="table table-zebra w-full">
                    <thead class="sticky top-0 z-10 highlight">
                        <tr>
                            <th>VLAN</th>
                            <th>Réseau (CIDR)</th>
                            <th>Passerelle</th>
                            <th>Plage(s) dynamique(s)</th>
                            <th>Description</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($subnets as $subnet)
                            <tr>
                                <td class="font-mono font-semibold">{{ $subnet->vlan_id }}</td>
                                <td class="font-mono">{{ $subnet->network }}</td>
                                <td class="font-mono">{{ $subnet->gateway }}</td>
                                <td class="text-xs">
                                    @foreach ((array) $subnet->ranges as $range)
                                        <div class="font-mono">{{ $range['begin'] ?? '?' }} → {{ $range['end'] ?? '?' }}</div>
                                    @endforeach
                                </td>
                                <td class="max-w-xs truncate" title="{{ $subnet->description }}">
                                    {{ $subnet->description }}
                                </td>
                                <td class="text-right">
                                    <div class="flex gap-1 justify-end">
                                        <button type="button" wire:click="openEditSubnetModal({{ $subnet->id }})"
                                            class="btn btn-ghost btn-sm" @cannot('manage-dhcp') disabled @endcannot
                                            title="Modifier">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <button type="button" wire:click="confirmDeleteSubnet({{ $subnet->id }})"
                                            class="btn btn-ghost btn-sm text-error"
                                            @cannot('manage-dhcp') disabled @endcannot
                                            title="Supprimer">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
