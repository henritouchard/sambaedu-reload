{{--
    Story 8.1 — Table des réservations DHCP (AC1, AC3).

    Variables attendues :
      - $reservations : Illuminate\Pagination\LengthAwarePaginator de DhcpReservation
--}}

@if ($reservations->isEmpty())
    <div class="card bg-base-100 border border-base-300">
        <div class="card-body items-center text-center py-12">
            <div class="text-5xl mb-4 opacity-20">
                <i class="fa-solid fa-network-wired"></i>
            </div>
            <h3 class="text-lg font-semibold mb-1">Aucune réservation DHCP</h3>
            <p class="text-base-content/60 text-sm">
                Créez une réservation ou importez un fichier CSV pour démarrer.
            </p>
        </div>
    </div>
@else
    <div class="card bg-base-100 border border-base-300 overflow-hidden">
        <div class="overflow-auto max-h-[60vh]">
            <table class="table table-zebra w-full">
                <thead class="sticky top-0 z-10 highlight">
                    <tr>
                        <th>Nom</th>
                        <th>MAC</th>
                        <th>IP</th>
                        <th>Description</th>
                        <th>Machine liée</th>
                        <th>Source</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reservations as $reservation)
                        <tr class="hover:bg-sky-50">
                            <td class="font-mono">{{ $reservation->name }}</td>
                            <td class="font-mono text-xs">{{ $reservation->mac }}</td>
                            <td class="font-mono">{{ $reservation->ip }}</td>
                            <td class="max-w-xs truncate" title="{{ $reservation->description }}">
                                {{ $reservation->description }}
                            </td>
                            <td>
                                @if ($reservation->workstation_id && $reservation->workstation)
                                    <a href="{{ route('app.parc.machines.show', ['id' => $reservation->workstation_id]) }}"
                                        class="link link-primary">
                                        <i class="fa-solid fa-desktop"></i> {{ $reservation->workstation->name }}
                                    </a>
                                @else
                                    <span class="text-base-content/40">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-sm
                                    @switch($reservation->source)
                                        @case('manual') badge-primary @break
                                        @case('import') badge-info @break
                                        @case('legacy-migration') badge-warning @break
                                        @default badge-ghost
                                    @endswitch
                                ">
                                    {{ $reservation->source }}
                                </span>
                            </td>
                            <td class="text-right">
                                <div class="flex gap-1 justify-end">
                                    <button type="button" wire:click="openEditModal({{ $reservation->id }})"
                                        class="btn btn-ghost btn-sm" @cannot('manage-dhcp') disabled @endcannot
                                        title="Modifier">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <button type="button" wire:click="confirmDelete({{ $reservation->id }})"
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

        <x-molecules.pagination :paginator="$reservations" itemLabel="réservation" itemLabelPlural="réservations" />
    </div>
@endif
