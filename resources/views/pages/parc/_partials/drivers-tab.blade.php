<?php

use App\Components\Traits\WithToasts;
use App\Models\PrinterDriver;
use App\Services\Print\Exceptions\KerberosTicketException;
use App\Services\Print\Exceptions\PrintDriverException;
use App\Services\Print\Exceptions\SambaUnavailableException;
use App\Services\Print\PrintDriverService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Story 6.2 — Onglet « Drivers » global dans /parc?tab=drivers (D14bis Option A).
 *
 * Listing fusionné `rpcclient enumdrivers` (Samba runtime) + enrichissement
 * SER (audit, source, rattachements imprimantes). Filtres : tous / avec
 * imprimante / orphans / source. Action : déclencher `printer-drivers:sync`.
 *
 * Guards : `Gate::denies('manage-printer')` au mount → abort 403 (cohérent
 * AC8). Pas de manage des drivers depuis cet onglet (les actions upload /
 * détacher / supprimer se font depuis la modale édit d'une imprimante,
 * pattern UX 6.1 cohérent — sauf "Synchroniser" qui ré-applique le sync).
 */
new class extends Component {
    use WithToasts;

    private PrintDriverService $driverService;

    /**
     * Liste des drivers fusionnée Samba + SER.
     *
     * @var list<array{driver_name:string,architecture:string,source:?string,orphan:?bool,attached_printers:list<string>,created_at:?string,notes:?string,is_in_samba:bool}>
     */
    public array $drivers = [];

    public bool $sambaAvailable = true;

    #[Url]
    public string $driverFilter = 'all'; // all|attached|unattached|orphans
    #[Url]
    public string $sourceFilter = '';   // ''|upload-w10|synced|manual-cli

    public function boot(PrintDriverService $driverService): void
    {
        $this->driverService = $driverService;
    }

    public function mount(): void
    {
        if (Gate::denies('manage-printer')) {
            abort(403);
        }
        $this->loadDrivers();
    }

    public function updatedDriverFilter(): void
    {
        $this->loadDrivers();
    }

    public function updatedSourceFilter(): void
    {
        $this->loadDrivers();
    }

    public function loadDrivers(): void
    {
        $sambaList = [];
        try {
            $sambaList = $this->driverService->listAllDrivers();
            $this->sambaAvailable = true;
        } catch (SambaUnavailableException $e) {
            $this->sambaAvailable = false;
            Log::warning('DriversTab: Samba injoignable', ['error' => $e->getMessage()]);
        } catch (KerberosTicketException $e) {
            $this->sambaAvailable = false;
            Log::warning('DriversTab: Kerberos KO', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->sambaAvailable = false;
            Log::error('DriversTab: erreur listage drivers Samba', ['error' => $e->getMessage()]);
        }

        // Index Samba par clé composite.
        $sambaIndex = [];
        foreach ($sambaList as $d) {
            $sambaIndex[$d['driver_name'] . '|' . $d['architecture']] = $d;
        }

        // Index SER avec rattachements groupés par driver/arch.
        $serRows = PrinterDriver::query()->orderBy('driver_name')->get();
        $serGrouped = [];
        foreach ($serRows as $row) {
            $key = $row->driver_name . '|' . $row->architecture;
            if (!isset($serGrouped[$key])) {
                $serGrouped[$key] = [
                    'driver_name' => $row->driver_name,
                    'architecture' => $row->architecture,
                    'source' => $row->source,
                    'orphan' => $row->orphan,
                    'notes' => $row->notes,
                    'created_at' => $row->created_at?->toDateTimeString(),
                    'attached_printers' => [],
                ];
            }
            $serGrouped[$key]['attached_printers'][] = $row->printer_cups_name;
        }

        // Fusion : un driver peut être en Samba sans ligne SER (orphan
        // inverse), en SER orphan (Samba l'a perdu), ou dans les deux.
        $rows = [];
        $allKeys = array_unique(array_merge(array_keys($sambaIndex), array_keys($serGrouped)));
        foreach ($allKeys as $key) {
            $samba = $sambaIndex[$key] ?? null;
            $ser = $serGrouped[$key] ?? null;
            $rows[] = [
                'driver_name' => $samba['driver_name'] ?? ($ser['driver_name'] ?? ''),
                'architecture' => $samba['architecture'] ?? ($ser['architecture'] ?? 'x64'),
                'source' => $ser['source'] ?? null,
                'orphan' => $ser['orphan'] ?? null,
                'attached_printers' => $ser['attached_printers'] ?? [],
                'created_at' => $ser['created_at'] ?? null,
                'notes' => $ser['notes'] ?? null,
                'is_in_samba' => $samba !== null,
            ];
        }

        // Application des filtres UI.
        if ($this->driverFilter === 'attached') {
            $rows = array_values(array_filter($rows, fn($r) => !empty($r['attached_printers'])));
        } elseif ($this->driverFilter === 'unattached') {
            $rows = array_values(array_filter($rows, fn($r) => empty($r['attached_printers']) && !$r['orphan']));
        } elseif ($this->driverFilter === 'orphans') {
            $rows = array_values(array_filter($rows, fn($r) => $r['orphan'] === true));
        }
        if ($this->sourceFilter !== '') {
            $rows = array_values(array_filter($rows, fn($r) => $r['source'] === $this->sourceFilter));
        }

        usort($rows, fn($a, $b) => strcmp($a['driver_name'], $b['driver_name']));

        $this->drivers = $rows;
    }

    public function triggerSync(): void
    {
        Gate::authorize('manage-printer');

        // Q4A — verrou anti-concurrence : un cron sync (03:35) ou un
        // autre admin ne doit pas pouvoir lancer deux sync en parallèle.
        $lock = Cache::lock('printer-drivers-sync', 60);
        if (!$lock->get()) {
            $this->toastWarning('Une synchronisation est déjà en cours. Réessayer dans quelques secondes.');
            return;
        }
        try {
            Artisan::call('printer-drivers:sync');
            $this->toastSuccess('Synchronisation drivers terminée.');
            $this->loadDrivers();
        } catch (\Throwable $e) {
            Log::error('DriversTab: erreur déclenchement sync', ['error' => $e->getMessage()]);
            $this->toastError('Erreur lors du déclenchement de la synchronisation.');
        } finally {
            $lock->release();
        }
    }
};
?>

<div class="flex-1 min-h-0 flex flex-col gap-4">
    @unless ($sambaAvailable)
        <div class="alert alert-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>Service Samba injoignable — drivers indisponibles. Vérifier le service `smbd` et le ticket Kerberos
                du compte machine `se4fs$`.</span>
        </div>
    @endunless

    <!-- Filtres + bouton sync -->
    <div class="flex-shrink-0 card bg-base-100 shadow-sm">
        <div class="card-body py-3">
            <div class="flex flex-wrap items-center gap-3 justify-between">
                <div role="tablist" class="tabs tabs-boxed bg-base-200 flex-wrap">
                    <button type="button" role="tab" class="tab {{ $driverFilter === 'all' ? 'tab-active' : '' }}"
                        wire:click="$set('driverFilter', 'all')">Tous</button>
                    <button type="button" role="tab"
                        class="tab {{ $driverFilter === 'attached' ? 'tab-active' : '' }}"
                        wire:click="$set('driverFilter', 'attached')">Avec imprimante</button>
                    <button type="button" role="tab"
                        class="tab {{ $driverFilter === 'unattached' ? 'tab-active' : '' }}"
                        wire:click="$set('driverFilter', 'unattached')">Sans imprimante</button>
                    <button type="button" role="tab"
                        class="tab {{ $driverFilter === 'orphans' ? 'tab-active' : '' }}"
                        wire:click="$set('driverFilter', 'orphans')">Orphans</button>
                </div>

                <div class="flex items-center gap-2">
                    <select wire:model.live="sourceFilter" class="select select-bordered select-sm">
                        <option value="">Toutes sources</option>
                        <option value="upload-w10">upload-w10</option>
                        <option value="synced">synced</option>
                        <option value="manual-cli">manual-cli</option>
                    </select>
                    @can('manage-printer')
                        <button type="button" class="btn btn-sm btn-outline" wire:click="triggerSync"
                            @if (!$sambaAvailable) disabled @endif>
                            <i class="fa-solid fa-rotate"></i>
                            Synchroniser
                        </button>
                    @endcan
                </div>
            </div>
        </div>
    </div>

    <!-- Tableau drivers -->
    <div class="card bg-base-100 shadow-sm flex-1 min-h-0 flex flex-col overflow-hidden">
        @if (empty($drivers))
            <div class="card-body flex flex-col items-center justify-center py-16">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-floppy-disk"></i>
                </div>
                <h3 class="text-xl font-semibold mb-3">Aucun driver Windows publié</h3>
                <p class="text-base-content/60 text-center max-w-md mb-6">
                    Téléverser un driver depuis l'onglet Imprimantes : ouvrir la modale d'édition d'une imprimante
                    puis section « Drivers Windows ».
                </p>
            </div>
        @else
            <div class="flex-1 min-h-0 overflow-y-auto">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th>Driver</th>
                            <th>Arch.</th>
                            <th>Source</th>
                            <th>Imprimantes rattachées</th>
                            <th>Statut</th>
                            <th>Auteur / date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($drivers as $d)
                            <tr wire:key="drv-{{ $d['driver_name'] }}-{{ $d['architecture'] }}">
                                <td class="text-xs font-mono">{{ $d['driver_name'] }}</td>
                                <td><span class="badge badge-ghost badge-sm">{{ $d['architecture'] }}</span></td>
                                <td class="text-xs">{{ $d['source'] ?? '—' }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($d['attached_printers'] as $cupsName)
                                            <a href="{{ route('app.parc', ['tab' => 'printers']) }}"
                                                class="badge badge-outline badge-sm">
                                                {{ $cupsName }}
                                            </a>
                                        @empty
                                            <span class="text-xs text-base-content/40">—</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td>
                                    @if ($d['orphan'])
                                        <span class="badge badge-error badge-sm">orphan SER</span>
                                    @elseif (!$d['is_in_samba'])
                                        <span class="badge badge-warning badge-sm">hors Samba</span>
                                    @else
                                        <span class="badge badge-success badge-sm">actif</span>
                                    @endif
                                </td>
                                <td class="text-xs">
                                    {{ $d['created_at'] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
