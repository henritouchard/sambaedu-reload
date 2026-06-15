{{-- Liste des imprimantes attachées à ce groupe (pivot printer_workstation_group) --}}
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h3 class="card-title text-base">
        <i class="fa-solid fa-print text-primary"></i>
        Imprimantes du groupe
        <span class="badge badge-ghost">{{ $group->printers->count() }}</span>
    </h3>
    <a href="{{ route('app.parc.index') }}#printers" class="btn btn-ghost btn-sm">
        <i class="fa-solid fa-gear"></i>
        Gérer toutes les imprimantes
    </a>
</div>

@if ($group->printers->isEmpty())
    <div class="flex flex-col items-center justify-center py-12 text-center">
        <div class="text-5xl mb-4 opacity-20">
            <i class="fa-solid fa-print"></i>
        </div>
        <h4 class="text-lg font-semibold mb-2">Aucune imprimante</h4>
        <p class="text-base-content/60 max-w-sm">
            Aucune imprimante n'est rattachée à ce groupe. Rattachez-en une depuis la gestion des imprimantes
            (onglet « Imprimantes » de la page parc).
        </p>
    </div>
@else
    <div class="overflow-x-auto">
        <table class="table table-zebra">
            <thead>
                <tr>
                    <th>Nom CUPS</th>
                    <th>Description</th>
                    <th>Localisation</th>
                    <th>URI</th>
                    <th class="text-center" title="Imprimante poussée par défaut sur les postes de ce groupe (agent desired-state)">
                        Par défaut
                    </th>
                    <th class="w-20"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group->printers as $printer)
                    <tr>
                        <td>
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-print text-base-content/40"></i>
                                <span class="font-mono">{{ $printer->cups_name }}</span>
                            </div>
                        </td>
                        <td>{{ $printer->description ?? '—' }}</td>
                        <td>{{ $printer->location ?? '—' }}</td>
                        <td>
                            <span class="font-mono text-xs text-base-content/60" title="{{ $printer->uri }}">
                                {{ \Illuminate\Support\Str::limit($printer->uri, 50) }}
                            </span>
                        </td>
                        {{-- Story 27.2 — drapeau « imprimante par défaut » (pivot is_default).
                             Réglé par WG (physique comme logique) ; l'agent pousse
                             SetDefaultPrinter sur l'item marqué. La résolution inter-WG
                             (physique > logique) est faite côté serveur à la compilation. --}}
                        <td class="text-center">
                            {{-- Garde UI : le toggle appelle `toggleDefaultPrinter` qui exige
                                 `manage-printer` (= server.admin global, PrinterPolicy). Sans cette
                                 garde, un délégué scopé (accès `view` au groupe) verrait un toggle
                                 cliquable qui lèverait un 403. On rend l'état en lecture seule. --}}
                            @can('manage-printer')
                                <input type="checkbox"
                                    class="toggle toggle-sm toggle-primary"
                                    @checked((bool) (int) ($printer->pivot->is_default ?? 0))
                                    wire:click="toggleDefaultPrinter('{{ $printer->cups_name }}')"
                                    title="Définir comme imprimante par défaut de ce groupe" />
                            @else
                                @if ((bool) (int) ($printer->pivot->is_default ?? 0))
                                    <i class="fa-solid fa-circle-check text-primary"
                                        title="Imprimante par défaut de ce groupe"></i>
                                @else
                                    <span class="text-base-content/40">—</span>
                                @endif
                            @endcan
                        </td>
                        <td>
                            <a href="{{ route('app.parc.index') }}#printers"
                                class="btn btn-ghost btn-xs" title="Gérer">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
