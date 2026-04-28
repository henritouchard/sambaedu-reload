<?php

use App\Components\Traits\WithToasts;
use App\Models\Printer;
use App\Models\WorkstationGroup;
use App\Services\PermissionService;
use App\Services\Print\CupsPrinterService;
use App\Services\Print\Exceptions\CupsCommandException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Story 6.1 — Onglet Imprimantes dans /parc.
 *
 * Liste fusionnée CUPS + SER : enrichit chaque imprimante CUPS détectée avec
 * les métadonnées SER (description_ser, rattachements parc) et inversement.
 * Les imprimantes orphan (SER seul) ne sont visibles que côté admin. Sous le
 * filtre « Toutes » (all), les orphans apparaissent avec un badge pour les admins
 * (fix #6 — solution 2).
 *
 * Guards : double-couche — `Gate::allows` UX douce sur les ouvertures de modale,
 * `Gate::authorize` sur les méthodes mutantes (abort 403 si payload forgé).
 */
new class extends Component {
    use WithToasts;

    private CupsPrinterService $cupsService;
    private PermissionService $permissionService;

    /** @var array<int, array{cups_name:string,uri:?string,state:string,description:?string,location:?string,model:?string,jobs_count:int,description_ser:?string,is_orphan:bool,is_attached:bool,workstation_groups:array<int, array{id:int,name:string}>}> */
    public array $printers = [];

    /** @var array<int, array{ppd:string,model:string}> */
    public array $availableDrivers = [];

    /** @var array<int, array{id:int,name:string,display_name:?string}> */
    public array $availableGroups = [];

    public bool $cupsAvailable = true;

    #[Url]
    public string $filter = 'all'; // all|attached|unattached|orphans

    // États modales
    public bool $showAddModal = false;
    public bool $showEditModal = false;
    public ?string $editingCupsName = null;

    // Form ajout
    public string $newName = '';
    public string $newUri = '';
    public string $newDescription = '';
    public string $newLocation = '';
    public ?string $newPpd = null;
    public string $newDescriptionSer = '';
    public array $newWorkstationGroupIds = [];

    // Form édition
    public string $editDescription = '';
    public string $editLocation = '';
    public ?string $editPpd = null;
    public string $editDescriptionSer = '';
    public array $editWorkstationGroupIds = [];
    public string $editUri = '';

    public function boot(CupsPrinterService $cupsService, PermissionService $permissionService): void
    {
        $this->cupsService = $cupsService;
        $this->permissionService = $permissionService;
    }

    public function mount(): void
    {
        $this->loadDrivers();
        $this->loadAvailableGroups();
        $this->loadPrinters();
    }

    public function updatedFilter(): void
    {
        $this->loadPrinters();
    }

    private function isAdmin(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->can('server.admin');
    }

    private function loadDrivers(): void
    {
        try {
            $this->availableDrivers = $this->cupsService->listAvailableDrivers();
        } catch (\Throwable $e) {
            Log::warning('PrintersTab: chargement drivers échoué', ['error' => $e->getMessage()]);
            $this->availableDrivers = [];
        }
    }

    private function loadAvailableGroups(): void
    {
        $user = auth()->user();
        if ($user === null) {
            $this->availableGroups = [];
            return;
        }

        if ($this->isAdmin()) {
            $groups = WorkstationGroup::physical()->active()->orderBy('name')->get();
        } else {
            $groups = $this->permissionService->getAuthorizedWorkstationGroups($user, 'server.admin');
        }

        $this->availableGroups = $groups->map(fn(WorkstationGroup $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'display_name' => $g->display_name,
        ])->values()->all();
    }

    public function loadPrinters(): void
    {
        $user = auth()->user();
        if ($user === null) {
            $this->printers = [];
            return;
        }

        if (!Gate::allows('viewAny-printer') && !$this->hasAnyDelegation($user)) {
            $this->printers = [];
            return;
        }

        // 1. Lecture CUPS (fail-soft — CupsDaemonDownException → cupsAvailable=false)
        $cupsByName = [];
        try {
            $cupsList = $this->cupsService->listPrinters();
            foreach ($cupsList as $row) {
                $cupsByName[$row['name']] = $row;
            }
            $this->cupsAvailable = true;
        } catch (\Throwable $e) {
            Log::error('PrintersTab: erreur lecture CUPS', ['error' => $e->getMessage()]);
            $this->cupsAvailable = false;
            $cupsByName = [];
        }

        // 2. Lecture SER scopée user
        $serQuery = Printer::query()->with('workstationGroups');

        if (!$this->isAdmin()) {
            $serQuery->forUser($user);
            $serQuery->nonOrphan();
        } else {
            // Fix #6 : filtre 'all' inclut les orphans (badge affiché dans la vue).
            // 'attached' et 'unattached' excluent les orphans (non rattachables en pratique).
            if ($this->filter === 'orphans') {
                $serQuery->orphans();
            } elseif ($this->filter !== 'all') {
                $serQuery->nonOrphan();
            }
            // 'all' → pas de scope → orphans inclus avec badge dans buildRow()
        }

        $serByName = $serQuery->get()->keyBy('cups_name');

        // 3. Fusion : pour admin → union CUPS ∪ SER ; pour lambda → SER scopé seulement
        $rows = [];

        if ($this->isAdmin()) {
            $allNames = collect(array_keys($cupsByName))->merge($serByName->keys())->unique();
            foreach ($allNames as $name) {
                $cups = $cupsByName[$name] ?? null;
                $ser = $serByName[$name] ?? null;
                $isOrphan = $ser?->orphan === true;
                $rows[] = $this->buildRow($name, $cups, $ser, $isOrphan);
            }
        } else {
            foreach ($serByName as $name => $ser) {
                $cups = $cupsByName[$name] ?? null;
                $rows[] = $this->buildRow($name, $cups, $ser, false);
            }
        }

        // 4. Filtres UI (admin only)
        if ($this->isAdmin()) {
            $rows = match ($this->filter) {
                'attached' => array_values(array_filter($rows, fn($r) => $r['is_attached'])),
                'unattached' => array_values(array_filter($rows, fn($r) => !$r['is_attached'] && !$r['is_orphan'])),
                'orphans' => array_values(array_filter($rows, fn($r) => $r['is_orphan'])),
                default => $rows, // 'all' — tout, orphans compris
            };
        }

        usort($rows, fn($a, $b) => strcmp($a['cups_name'], $b['cups_name']));

        $this->printers = $rows;
    }

    private function buildRow(string $name, ?array $cups, ?Printer $ser, bool $isOrphan): array
    {
        $groups = [];
        if ($ser !== null) {
            foreach ($ser->workstationGroups as $g) {
                $groups[] = ['id' => $g->id, 'name' => $g->display_name ?? $g->name];
            }
        }

        return [
            'cups_name' => $name,
            'uri' => $cups['uri'] ?? null,
            'state' => $cups['state'] ?? ($isOrphan ? 'orphan' : 'unknown'),
            'description' => $cups['description'] ?? null,
            'location' => $cups['location'] ?? null,
            'model' => $cups['model'] ?? null,
            'jobs_count' => $cups['jobs_count'] ?? 0,
            'description_ser' => $ser?->description_ser,
            'is_orphan' => $isOrphan,
            'is_attached' => count($groups) > 0,
            'workstation_groups' => $groups,
        ];
    }

    private function hasAnyDelegation($user): bool
    {
        if (!$user instanceof \App\Models\User) {
            return false;
        }
        return $this->permissionService->getAuthorizedWorkstationGroups($user, 'server.admin')->isNotEmpty();
    }

    // ========================================================================
    // ADD
    // ========================================================================

    public function openAddModal(): void
    {
        if (!Gate::allows('manage-printer')) {
            $this->toastAccessDenied();
            return;
        }
        $this->resetAddForm();
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
    }

    private function resetAddForm(): void
    {
        $this->newName = '';
        $this->newUri = '';
        $this->newDescription = '';
        $this->newLocation = '';
        $this->newPpd = null;
        $this->newDescriptionSer = '';
        $this->newWorkstationGroupIds = [];
        $this->resetErrorBag();
    }

    public function addPrinter(): void
    {
        Gate::authorize('manage-printer');

        $this->validate([
            'newName' => ['required', 'string', 'regex:' . CupsPrinterService::NAME_REGEX, 'max:15'],
            'newUri' => ['required', 'string', 'regex:' . CupsPrinterService::URI_REGEX, 'max:500'],
            'newDescription' => ['nullable', 'string', 'max:255'],
            'newLocation' => ['nullable', 'string', 'max:255'],
            'newPpd' => ['nullable', 'string', 'max:255'],
            'newDescriptionSer' => ['nullable', 'string', 'max:1000'],
            'newWorkstationGroupIds' => ['array'],
            'newWorkstationGroupIds.*' => ['integer', 'exists:workstation_groups,id'],
        ], [], [
            'newName' => 'nom de l\'imprimante',
            'newUri' => 'URI',
            'newWorkstationGroupIds.*' => 'parc',
        ]);

        $cupsCommitted = false;
        $sambaOk = true;

        try {
            DB::transaction(function () use (&$cupsCommitted, &$sambaOk) {
                $sambaOk = $this->cupsService->addPrinter(
                    $this->newName,
                    $this->newUri,
                    $this->newDescription !== '' ? $this->newDescription : null,
                    $this->newLocation !== '' ? $this->newLocation : null,
                    $this->newPpd !== '' ? $this->newPpd : null,
                );
                $cupsCommitted = true;

                $printer = Printer::create([
                    'cups_name' => $this->newName,
                    'created_by_user_id' => auth()->id(),
                    'orphan' => false,
                    'description_ser' => $this->newDescriptionSer !== '' ? $this->newDescriptionSer : null,
                ]);

                if (!empty($this->newWorkstationGroupIds)) {
                    $now = now();
                    $userId = auth()->id();
                    $pivot = [];
                    foreach ($this->newWorkstationGroupIds as $gid) {
                        $pivot[(int) $gid] = [
                            'attached_at' => $now,
                            'attached_by_user_id' => $userId,
                        ];
                    }
                    $printer->workstationGroups()->attach($pivot);
                }
            });
        } catch (CupsCommandException $e) {
            $this->toastError('Erreur CUPS : ' . $e->firstStderrLine());
            return;
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
            return;
        } catch (\Throwable $e) {
            // CUPS a commit mais SER a échoué → rollback CUPS best-effort (fix #1).
            if ($cupsCommitted) {
                Log::warning('PrintersTab: rollback CUPS après échec SER', [
                    'name' => $this->newName,
                    'error' => $e->getMessage(),
                ]);
                try {
                    $this->cupsService->deletePrinter($this->newName);
                } catch (\Throwable $rollback) {
                    Log::error('PrintersTab: rollback CUPS échoué', [
                        'name' => $this->newName,
                        'error' => $rollback->getMessage(),
                    ]);
                }
            }
            // Fix #1 : message générique — ne pas exposer les détails techniques.
            Log::error('PrintersTab: erreur ajout imprimante', [
                'name' => $this->newName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->toastError('Une erreur interne est survenue lors de l\'ajout.');
            return;
        }

        $this->toastSuccess("Imprimante {$this->newName} créée");
        // Fix #15 : avertissement si le reload Samba a échoué.
        if (!$sambaOk) {
            $this->toastWarning('Reload Samba échoué — les postes verront l\'imprimante au prochain redémarrage du service.');
        }
        $this->showAddModal = false;
        $this->resetAddForm();
        $this->loadPrinters();
    }

    // ========================================================================
    // EDIT
    // ========================================================================

    public function openEditModal(string $cupsName): void
    {
        $printer = Printer::with('workstationGroups')->find($cupsName);

        if ($printer === null) {
            if (!Gate::allows('manage-printer')) {
                $this->toastAccessDenied();
                return;
            }
        } elseif (!Gate::allows('manage-printer', $printer)) {
            $this->toastAccessDenied();
            return;
        }

        $cupsRow = collect($this->printers)->firstWhere('cups_name', $cupsName);
        if ($cupsRow === null) {
            $this->toastError("Imprimante {$cupsName} introuvable");
            return;
        }

        $this->editingCupsName = $cupsName;
        $this->editUri = (string) ($cupsRow['uri'] ?? '');
        $this->editDescription = (string) ($cupsRow['description'] ?? '');
        $this->editLocation = (string) ($cupsRow['location'] ?? '');
        $this->editPpd = null;
        $this->editDescriptionSer = (string) ($printer?->description_ser ?? '');
        $this->editWorkstationGroupIds = $printer
            ? $printer->workstationGroups->pluck('id')->all()
            : [];

        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingCupsName = null;
    }

    public function updatePrinter(): void
    {
        if ($this->editingCupsName === null) {
            return;
        }

        $printer = Printer::with('workstationGroups')->find($this->editingCupsName);
        Gate::authorize('manage-printer', $printer);

        $this->validate([
            'editUri' => ['required', 'string', 'regex:' . CupsPrinterService::URI_REGEX, 'max:500'],
            'editDescription' => ['nullable', 'string', 'max:255'],
            'editLocation' => ['nullable', 'string', 'max:255'],
            'editPpd' => ['nullable', 'string', 'max:255'],
            'editDescriptionSer' => ['nullable', 'string', 'max:1000'],
            'editWorkstationGroupIds' => ['array'],
            'editWorkstationGroupIds.*' => ['integer', 'exists:workstation_groups,id'],
        ], [], [
            'editUri' => 'URI',
            'editWorkstationGroupIds.*' => 'parc',
        ]);

        $sambaOk = true;

        try {
            DB::transaction(function () use ($printer, &$sambaOk) {
                // Fix #7 : ne passer à CUPS que les champs réellement modifiés.
                $cupsRow = collect($this->printers)->firstWhere('cups_name', $this->editingCupsName);
                $changes = [];

                if ($cupsRow !== null) {
                    if ($this->editUri !== ($cupsRow['uri'] ?? '')) {
                        $changes['uri'] = $this->editUri;
                    }
                    if ($this->editDescription !== ($cupsRow['description'] ?? '')) {
                        $changes['description'] = $this->editDescription;
                    }
                    if ($this->editLocation !== ($cupsRow['location'] ?? '')) {
                        $changes['location'] = $this->editLocation;
                    }
                } else {
                    $changes = [
                        'uri' => $this->editUri,
                        'description' => $this->editDescription,
                        'location' => $this->editLocation,
                    ];
                }

                if ($this->editPpd !== null && $this->editPpd !== '') {
                    $changes['ppd'] = $this->editPpd;
                }

                if (!empty($changes)) {
                    $sambaOk = $this->cupsService->updatePrinter($this->editingCupsName, $changes);
                }

                if ($printer === null) {
                    $printer = Printer::create([
                        'cups_name' => $this->editingCupsName,
                        'created_by_user_id' => auth()->id(),
                        'orphan' => false,
                        'description_ser' => $this->editDescriptionSer !== '' ? $this->editDescriptionSer : null,
                    ]);
                } else {
                    $printer->description_ser = $this->editDescriptionSer !== '' ? $this->editDescriptionSer : null;
                    $printer->save();
                }

                $now = now();
                $userId = auth()->id();
                $syncMap = [];
                foreach ($this->editWorkstationGroupIds as $gid) {
                    $syncMap[(int) $gid] = [
                        'attached_at' => $now,
                        'attached_by_user_id' => $userId,
                    ];
                }
                $printer->workstationGroups()->sync($syncMap);
            });
        } catch (CupsCommandException $e) {
            $this->toastError('Erreur CUPS : ' . $e->firstStderrLine());
            return;
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
            return;
        }

        $this->toastSuccess('Configuration mise à jour');
        // Fix #15 : avertissement reload Samba.
        if (!$sambaOk) {
            $this->toastWarning('Reload Samba échoué — les postes verront la modification au prochain redémarrage du service.');
        }
        $this->showEditModal = false;
        $this->editingCupsName = null;
        $this->loadPrinters();
    }

    // ========================================================================
    // DELETE
    // ========================================================================

    public function deletePrinter(string $cupsName): void
    {
        $printer = Printer::find($cupsName);
        Gate::authorize('manage-printer', $printer);

        $sambaOk = true;

        try {
            DB::transaction(function () use ($cupsName, $printer, &$sambaOk) {
                $sambaOk = $this->cupsService->deletePrinter($cupsName);
                $printer?->delete();
            });
        } catch (CupsCommandException $e) {
            $this->toastError('Erreur CUPS : ' . $e->firstStderrLine());
            return;
        }

        $this->toastSuccess("Imprimante {$cupsName} supprimée");
        // Fix #15 : avertissement reload Samba.
        if (!$sambaOk) {
            $this->toastWarning('Reload Samba échoué — les postes verront la suppression au prochain redémarrage du service.');
        }
        $this->loadPrinters();
    }

    // ========================================================================
    // TOGGLE ENABLE/DISABLE
    // ========================================================================

    public function togglePrinterState(string $cupsName): void
    {
        $printer = Printer::find($cupsName);
        Gate::authorize('manage-printer', $printer);

        // Fix #19 : refetch l'état live depuis CUPS plutôt que la cache mémoire.
        try {
            $liveCupsRow = $this->cupsService->getPrinter($cupsName);
        } catch (\Throwable $e) {
            Log::error('PrintersTab: erreur lecture état CUPS pour toggle', [
                'cups_name' => $cupsName,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Service CUPS injoignable — état actuel inconnu.');
            return;
        }

        if ($liveCupsRow === null) {
            $this->toastError("Imprimante {$cupsName} introuvable dans CUPS");
            return;
        }

        try {
            if ($liveCupsRow['state'] === 'disabled') {
                $this->cupsService->enablePrinter($cupsName);
                $this->toastSuccess("Imprimante {$cupsName} activée");
            } else {
                $this->cupsService->disablePrinter($cupsName);
                $this->toastSuccess("Imprimante {$cupsName} désactivée");
            }
        } catch (CupsCommandException $e) {
            $this->toastError('Erreur CUPS : ' . $e->firstStderrLine());
            return;
        }

        $this->loadPrinters();
    }
};
?>

@php
    $isAdmin = auth()->user()?->can('server.admin') ?? false;
@endphp

<div class="flex-1 min-h-0 flex flex-col gap-4">
    <!-- Banner info migration -->
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <span>Cette interface remplace l'ancienne page de gestion des imprimantes.</span>
    </div>

    @unless ($cupsAvailable)
        <div class="alert alert-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>Service CUPS injoignable — les états et la file d'attente ne sont pas à jour.</span>
        </div>
    @endunless

    <!-- Filtres + bouton ajout -->
    <div class="flex-shrink-0 card bg-base-100 shadow-sm">
        <div class="card-body py-3">
            <div class="flex flex-wrap items-center gap-3 justify-between">
                @if ($isAdmin)
                    <div role="tablist" class="tabs tabs-boxed bg-base-200">
                        <button type="button" role="tab" class="tab {{ $filter === 'all' ? 'tab-active' : '' }}"
                            wire:click="$set('filter', 'all')">Toutes</button>
                        <button type="button" role="tab" class="tab {{ $filter === 'attached' ? 'tab-active' : '' }}"
                            wire:click="$set('filter', 'attached')">Rattachées</button>
                        <button type="button" role="tab" class="tab {{ $filter === 'unattached' ? 'tab-active' : '' }}"
                            wire:click="$set('filter', 'unattached')">Non rattachées</button>
                        <button type="button" role="tab" class="tab {{ $filter === 'orphans' ? 'tab-active' : '' }}"
                            wire:click="$set('filter', 'orphans')">Orphelines</button>
                    </div>
                @else
                    <div></div>
                @endif

                @can('manage-printer')
                    <button type="button" class="btn btn-primary btn-sm" wire:click="openAddModal">
                        <i class="fa-solid fa-plus"></i>
                        Ajouter une imprimante
                    </button>
                @endcan
            </div>
        </div>
    </div>

    <!-- Tableau imprimantes -->
    <div class="card bg-base-100 shadow-sm flex-1 min-h-0 flex flex-col overflow-hidden">
        @if (empty($printers))
            <div class="card-body flex flex-col items-center justify-center py-16">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-print"></i>
                </div>
                <h3 class="text-xl font-semibold mb-3">Aucune imprimante</h3>
                <p class="text-base-content/60 text-center max-w-md mb-6">
                    @if (!$isAdmin)
                        Aucune imprimante disponible pour vos parcs.
                    @else
                        Aucune imprimante n'est enregistrée pour ce filtre.
                    @endif
                </p>
            </div>
        @else
            <div class="flex-1 min-h-0 overflow-y-auto">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>URI</th>
                            <th>État</th>
                            <th>File</th>
                            <th>Lieu</th>
                            <th>Modèle</th>
                            <th>Parcs</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($printers as $p)
                            <tr wire:key="printer-{{ $p['cups_name'] }}">
                                <td class="font-mono">
                                    {{ $p['cups_name'] }}
                                    @if ($p['is_orphan'])
                                        <span class="badge badge-error badge-sm ml-1">orphan</span>
                                    @elseif (!$p['is_attached'] && $isAdmin)
                                        <span class="badge badge-warning badge-sm ml-1">non rattachée</span>
                                    @endif
                                </td>
                                <td class="text-xs font-mono truncate max-w-xs"
                                    title="{{ $p['uri'] }}">{{ $p['uri'] ?: '—' }}</td>
                                <td>
                                    @if ($p['state'] === 'idle')
                                        <span class="badge badge-success">idle</span>
                                    @elseif ($p['state'] === 'printing')
                                        <span class="badge badge-warning">printing</span>
                                    @elseif ($p['state'] === 'disabled')
                                        <span class="badge badge-error">disabled</span>
                                    @else
                                        <span class="badge badge-ghost">{{ $p['state'] }}</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $p['jobs_count'] }}</td>
                                <td>{{ $p['location'] ?: '—' }}</td>
                                <td class="text-xs">{{ $p['model'] ?: '—' }}</td>
                                <td>
                                    @forelse ($p['workstation_groups'] as $g)
                                        <span class="badge badge-outline badge-sm mr-1 mb-1">{{ $g['name'] }}</span>
                                    @empty
                                        <span class="text-xs text-base-content/40">—</span>
                                    @endforelse
                                </td>
                                <td class="text-right">
                                    @if (!$p['is_orphan'])
                                        @php
                                            $modelForGate = \App\Models\Printer::find($p['cups_name']);
                                        @endphp
                                        @can('manage-printer', $modelForGate)
                                            <div class="join">
                                                <button type="button" class="join-item btn btn-xs btn-ghost"
                                                    wire:click="openEditModal('{{ $p['cups_name'] }}')"
                                                    title="Configurer">
                                                    <i class="fa-solid fa-gear"></i>
                                                </button>
                                                <button type="button" class="join-item btn btn-xs btn-ghost"
                                                    wire:click="togglePrinterState('{{ $p['cups_name'] }}')"
                                                    title="{{ $p['state'] === 'disabled' ? 'Activer' : 'Désactiver' }}">
                                                    @if ($p['state'] === 'disabled')
                                                        <i class="fa-solid fa-play text-success"></i>
                                                    @else
                                                        <i class="fa-solid fa-pause text-warning"></i>
                                                    @endif
                                                </button>
                                                <button type="button" class="join-item btn btn-xs btn-ghost text-error"
                                                    wire:click="deletePrinter('{{ $p['cups_name'] }}')"
                                                    wire:confirm="Supprimer définitivement l'imprimante {{ $p['cups_name'] }} ?"
                                                    title="Supprimer">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </div>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Modale ajout -->
    @teleport('body')
        <x-molecules.modal wire:model="showAddModal" title="Nouvelle imprimante" closeMethod="closeAddModal"
            size="max-w-3xl" height="h-auto max-h-[90vh]">

            <x-molecules.modal.section title="Configuration CUPS">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="form-control">
                        <label class="label py-1"><span class="label-text">Nom *</span></label>
                        <input type="text" wire:model="newName" class="input input-bordered input-sm font-mono"
                            placeholder="ex: imp-salle-a" maxlength="15" />
                        @error('newName')
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                        <span class="text-xs text-base-content/60 mt-1">Lettres, chiffres, _ et -. Max 15 caractères.</span>
                    </div>
                    <div class="form-control">
                        <label class="label py-1"><span class="label-text">URI *</span></label>
                        <input type="text" wire:model="newUri" class="input input-bordered input-sm font-mono"
                            placeholder="socket://192.168.1.10:9100" />
                        @error('newUri')
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                        <span class="text-xs text-base-content/60 mt-1">socket:// ipp:// ipps:// lpd:// http://
                            https://</span>
                    </div>
                    <div class="form-control">
                        <label class="label py-1"><span class="label-text">Description CUPS</span></label>
                        <input type="text" wire:model="newDescription" class="input input-bordered input-sm" />
                    </div>
                    <div class="form-control">
                        <label class="label py-1"><span class="label-text">Lieu</span></label>
                        <input type="text" wire:model="newLocation" class="input input-bordered input-sm" />
                    </div>
                    <div class="form-control md:col-span-2">
                        <label class="label py-1"><span class="label-text">Modèle (PPD)</span></label>
                        <select wire:model="newPpd" class="select select-bordered select-sm">
                            <option value="">— aucun (raw) —</option>
                            @foreach ($availableDrivers as $drv)
                                <option value="{{ $drv['ppd'] }}">{{ $drv['model'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Métadonnées SER">
                <div class="form-control">
                    <label class="label py-1"><span class="label-text">Description SER (interne)</span></label>
                    <textarea wire:model="newDescriptionSer" class="textarea textarea-bordered textarea-sm" rows="2"></textarea>
                </div>
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Rattachement aux parcs">
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 max-h-48 overflow-y-auto">
                    @forelse ($availableGroups as $g)
                        <label class="cursor-pointer flex items-center gap-2">
                            <input type="checkbox" wire:model="newWorkstationGroupIds" value="{{ $g['id'] }}"
                                class="checkbox checkbox-sm" />
                            <span class="text-sm">{{ $g['display_name'] ?? $g['name'] }}</span>
                        </label>
                    @empty
                        <p class="text-sm text-base-content/60">Aucun parc disponible.</p>
                    @endforelse
                </div>
            </x-molecules.modal.section>

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="closeAddModal">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="addPrinter">
                    <i class="fa-solid fa-check"></i>
                    Créer
                </button>
            </x-slot:footer>
        </x-molecules.modal>
    @endteleport

    <!-- Modale édition -->
    @teleport('body')
        <x-molecules.modal wire:model="showEditModal" title="Configurer l'imprimante" closeMethod="closeEditModal"
            size="max-w-3xl" height="h-auto max-h-[90vh]">

            @if ($editingCupsName)
                <x-molecules.modal.section title="Configuration CUPS">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="form-control md:col-span-2">
                            <label class="label py-1"><span class="label-text">Nom (verrouillé)</span></label>
                            <input type="text" value="{{ $editingCupsName }}" disabled
                                class="input input-bordered input-sm font-mono" />
                            <span class="text-xs text-base-content/60 mt-1">Pour renommer, supprimer puis recréer.</span>
                        </div>
                        <div class="form-control md:col-span-2">
                            <label class="label py-1"><span class="label-text">URI *</span></label>
                            <input type="text" wire:model="editUri" class="input input-bordered input-sm font-mono" />
                            @error('editUri')
                                <span class="text-xs text-error mt-1">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="form-control">
                            <label class="label py-1"><span class="label-text">Description CUPS</span></label>
                            <input type="text" wire:model="editDescription" class="input input-bordered input-sm" />
                        </div>
                        <div class="form-control">
                            <label class="label py-1"><span class="label-text">Lieu</span></label>
                            <input type="text" wire:model="editLocation" class="input input-bordered input-sm" />
                        </div>
                        <div class="form-control md:col-span-2">
                            <label class="label py-1"><span class="label-text">Changer le modèle PPD</span></label>
                            <select wire:model="editPpd" class="select select-bordered select-sm">
                                <option value="">— ne pas changer —</option>
                                @foreach ($availableDrivers as $drv)
                                    <option value="{{ $drv['ppd'] }}">{{ $drv['model'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </x-molecules.modal.section>

                <x-molecules.modal.section title="Métadonnées SER">
                    <div class="form-control">
                        <label class="label py-1"><span class="label-text">Description SER (interne)</span></label>
                        <textarea wire:model="editDescriptionSer" class="textarea textarea-bordered textarea-sm" rows="2"></textarea>
                    </div>
                </x-molecules.modal.section>

                <x-molecules.modal.section title="Rattachement aux parcs">
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2 max-h-48 overflow-y-auto">
                        @forelse ($availableGroups as $g)
                            <label class="cursor-pointer flex items-center gap-2">
                                <input type="checkbox" wire:model="editWorkstationGroupIds" value="{{ $g['id'] }}"
                                    class="checkbox checkbox-sm" />
                                <span class="text-sm">{{ $g['display_name'] ?? $g['name'] }}</span>
                            </label>
                        @empty
                            <p class="text-sm text-base-content/60">Aucun parc disponible.</p>
                        @endforelse
                    </div>
                </x-molecules.modal.section>
            @endif

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="closeEditModal">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="updatePrinter">
                    <i class="fa-solid fa-check"></i>
                    Enregistrer
                </button>
            </x-slot:footer>
        </x-molecules.modal>
    @endteleport
</div>
