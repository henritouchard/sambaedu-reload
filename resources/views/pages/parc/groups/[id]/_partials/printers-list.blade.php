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
