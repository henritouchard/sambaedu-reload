<?php

use App\Components\Traits\WithToasts;
use App\Models\Printer;
use App\Models\PrinterDriver;
use App\Models\WorkstationGroup;
use App\Services\PermissionService;
use App\Services\Print\CupsPrinterService;
use App\Services\Print\Exceptions\CupsCommandException;
use App\Services\Print\Exceptions\KerberosTicketException;
use App\Services\Print\Exceptions\PrintDriverException;
use App\Services\Print\Exceptions\SambaUnavailableException;
use App\Services\Print\Exceptions\WindowsPivotUnreachableException;
use App\Services\Print\PrintDriverService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
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
    private PrintDriverService $driverService;

    /** @var array<int, array{cups_name:string,uri:?string,state:string,description:?string,location:?string,model:?string,jobs_count:int,description_ser:?string,is_orphan:bool,is_attached:bool,workstation_groups:array<int, array{id:int,name:string}>}> */
    public array $printers = [];

    /** @var array<int, array{ppd:string,model:string}> */
    public array $availableDrivers = [];

    /** @var array<int, array{id:int,name:string,display_name:?string,description:?string,workstations_count:int}> */
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

    // ===== Story 6.2 — Drivers Windows =====
    /** @var array{samba: ?array{smb_name:string,smb_driver:string,smb_comment:string}, ser: list<array<string,mixed>>} */
    public array $printerDrivers = ['samba' => null, 'ser' => []];

    public bool $sambaAvailable = true;
    public bool $showUploadDriverModal = false;
    public string $newDriverPivot = '';
    public string $newDriverName = '';
    public string $newDriverDisplayName = '';

    /** @var array<int, array{smb_name:string,smb_driver:string,smb_comment:string}> */
    public array $availableDriversOnPivot = [];

    /**
     * Q3A — état partiel récupérable après registerDriver OK + étape attach KO.
     * Quand non-null, l'UI propose un bouton « Réessayer association »
     * dans la section drivers de la modale d'édit.
     *
     * @var array{driver_name:string,display_name:string}|null
     */
    public ?array $pendingAttachDriver = null;

    // ===== Panneau global « Pilotes Windows publiés » (ex-onglet Drivers) =====
    // Inventaire global des pilotes publiés sur Samba, fusionné avec l'audit
    // SER (orphelins, sources, rattachements). Réservé admin (manage-printer).
    // Repliable + lazy-load : le listing `rpcclient enumdrivers` n'est déclenché
    // qu'à la première ouverture, pour ne pas alourdir chaque visite de l'onglet.

    /**
     * Liste fusionnée Samba + SER, listée globalement.
     *
     * @var list<array{driver_name:string,architecture:string,source:?string,orphan:?bool,attached_printers:list<string>,created_at:?string,notes:?string,is_in_samba:bool}>
     */
    public array $publishedDrivers = [];

    public bool $driversPanelOpen = false;
    public bool $driversPanelLoaded = false;
    /** Disponibilité Samba pour le panneau global — distinct de `$sambaAvailable`
     *  (celui-ci ne concerne que la section drivers de la modale d'édition). */
    public bool $driversPanelSambaOk = true;

    #[Url]
    public string $driverFilter = 'all'; // all|attached|unattached|orphans
    #[Url]
    public string $sourceFilter = '';    // ''|upload-w10|synced|manual-cli

    public function boot(
        CupsPrinterService $cupsService,
        PermissionService $permissionService,
        PrintDriverService $driverService,
    ): void {
        $this->cupsService = $cupsService;
        $this->permissionService = $permissionService;
        $this->driverService = $driverService;
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

        // Comptage des postes en une seule requête (pas de N+1 par ligne).
        $groups->loadCount('workstations');

        $this->availableGroups = $groups->map(fn(WorkstationGroup $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'display_name' => $g->display_name,
            'description' => $g->description,
            'workstations_count' => $g->workstations_count,
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

    /**
     * Ouvre la modale d'ajout. Déclenchable depuis le dropdown d'actions
     * de la page parent (onglet Imprimantes) via l'événement `parc-add-printer`.
     */
    #[On('parc-add-printer')]
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

        // Story 6.2 — charger la section drivers Windows. Fail-soft sur
        // SambaUnavailableException : la modale s'ouvre, banner affiché,
        // actions désactivées (cohérent fix #1 6.1).
        $this->loadPrinterDrivers($cupsName);

        $this->showEditModal = true;
    }

    /**
     * Story 6.2 — Charge la liste des drivers Samba+SER pour l'imprimante éditée.
     */
    private function loadPrinterDrivers(string $cupsName): void
    {
        try {
            $this->printerDrivers = $this->driverService->listDriversForPrinter($cupsName);
            $this->sambaAvailable = true;
        } catch (SambaUnavailableException $e) {
            Log::warning('PrintersTab: Samba injoignable pour drivers', ['cups_name' => $cupsName]);
            $this->printerDrivers = ['samba' => null, 'ser' => []];
            $this->sambaAvailable = false;
        } catch (KerberosTicketException $e) {
            Log::warning('PrintersTab: Kerberos KO pour drivers', ['cups_name' => $cupsName]);
            $this->printerDrivers = ['samba' => null, 'ser' => []];
            $this->sambaAvailable = false;
        } catch (\Throwable $e) {
            Log::error('PrintersTab: erreur lecture drivers', [
                'cups_name' => $cupsName,
                'error' => $e->getMessage(),
            ]);
            $this->printerDrivers = ['samba' => null, 'ser' => []];
            $this->sambaAvailable = false;
        }
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingCupsName = null;
        $this->pendingAttachDriver = null;
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

    // ========================================================================
    // STORY 6.2 — UPLOAD / DETACH / DELETE DRIVERS WINDOWS
    // ========================================================================

    public function openUploadDriverModal(): void
    {
        if (!Gate::allows('manage-printer')) {
            $this->toastAccessDenied();
            return;
        }
        if ($this->editingCupsName === null) {
            $this->toastError("Sélectionner d'abord une imprimante.");
            return;
        }
        $this->resetUploadDriverForm();
        $this->showUploadDriverModal = true;
    }

    public function closeUploadDriverModal(): void
    {
        $this->showUploadDriverModal = false;
        $this->resetUploadDriverForm();
    }

    private function resetUploadDriverForm(): void
    {
        $this->newDriverPivot = '';
        $this->newDriverName = '';
        $this->newDriverDisplayName = '';
        $this->availableDriversOnPivot = [];
        $this->resetErrorBag(['newDriverPivot', 'newDriverName', 'newDriverDisplayName']);
    }

    public function listDriversOnPivot(): void
    {
        if (!Gate::allows('manage-printer')) {
            $this->toastAccessDenied();
            return;
        }
        $this->validate([
            'newDriverPivot' => [
                'required',
                'string',
                'regex:' . PrintDriverService::HOSTNAME_REGEX,
            ],
        ], [], ['newDriverPivot' => 'hostname du poste pivot']);

        try {
            $this->availableDriversOnPivot = $this->driverService->listPrintersOnPivot($this->newDriverPivot);
            if (empty($this->availableDriversOnPivot)) {
                $this->toastInfo("Aucune imprimante partagée détectée sur {$this->newDriverPivot}.");
            }
        } catch (WindowsPivotUnreachableException $e) {
            $this->toastError("Poste pivot {$this->newDriverPivot} injoignable — vérifier qu'il est allumé.");
            $this->availableDriversOnPivot = [];
        } catch (KerberosTicketException $e) {
            $this->toastError('Authentification Samba expirée — contacter l\'admin système.');
            $this->availableDriversOnPivot = [];
        } catch (SambaUnavailableException $e) {
            $this->toastError('Service Samba injoignable — impossible de lister les drivers du pivot.');
            $this->availableDriversOnPivot = [];
        } catch (PrintDriverException $e) {
            $this->toastError('Erreur Samba : ' . $e->firstStderrLine());
            $this->availableDriversOnPivot = [];
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('PrintersTab: erreur listage drivers pivot', [
                'pivot' => $this->newDriverPivot,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Une erreur interne est survenue lors du listage des drivers du pivot.');
        }
    }

    public function uploadDriver(): void
    {
        // Fix #15 — re-valider l'imprimante cible avant tout. Un
        // editingCupsName forgé via $wire.set sur un printer supprimé
        // entretemps doit échouer tôt et lisiblement (pas un INSERT
        // silencieusement catché en générique).
        if ($this->editingCupsName === null) {
            $this->toastError("Aucune imprimante cible sélectionnée.");
            return;
        }
        $printer = Printer::find($this->editingCupsName);
        if ($printer === null) {
            $this->toastError("Imprimante {$this->editingCupsName} introuvable.");
            return;
        }
        Gate::authorize('manage-printer', $printer);

        $this->validate([
            'newDriverPivot' => ['required', 'string', 'regex:' . PrintDriverService::HOSTNAME_REGEX],
            'newDriverName' => ['required', 'string', 'regex:' . PrintDriverService::DRIVER_NAME_REGEX, 'max:255'],
            'newDriverDisplayName' => ['nullable', 'string', 'max:255'],
        ], [], [
            'newDriverPivot' => 'hostname du poste pivot',
            'newDriverName' => 'nom du driver',
        ]);

        // Q4A — verrou anti-concurrence inter-admins par imprimante cible
        // (deux uploads simultanés sur la même imprimante = race condition
        // sur les fichiers + Samba).
        $lock = Cache::lock('printer-drivers-upload-' . $this->editingCupsName, 120);
        if (!$lock->get()) {
            $this->toastWarning('Un autre téléversement de driver est déjà en cours pour cette imprimante.');
            return;
        }

        $copiedFiles = [];
        $driverRegistered = false;
        $registeredDriverName = null;
        $driverDisplayName = $this->newDriverDisplayName;

        try {
            // Étape 1 — lecture définition driver sur pivot.
            $driverDef = $this->driverService->getDriverDefinition($this->newDriverPivot, $this->newDriverName);

            // Étape 2 — copie fichiers depuis pivot vers /var/lib/samba/printers/x64/.
            $filesToCopy = array_filter([
                $driverDef['Driver Path'] ?? null,
                $driverDef['Datafile'] ?? null,
                $driverDef['Configfile'] ?? null,
                $driverDef['Helpfile'] ?? null,
            ], fn($f) => is_string($f) && $f !== '' && $f !== 'NULL');
            foreach ($driverDef['Dependentfiles'] ?? [] as $dep) {
                if (is_string($dep) && $dep !== '' && $dep !== 'NULL') {
                    $filesToCopy[] = $dep;
                }
            }
            $filesToCopy = array_values(array_unique($filesToCopy));

            foreach ($filesToCopy as $file) {
                $this->driverService->copyDriverFile($this->newDriverPivot, $file);
                $copiedFiles[] = $file;
            }

            // Étape 3 — registerDriver côté Samba.
            $this->driverService->registerDriver($driverDef);
            $driverRegistered = true;
            $registeredDriverName = $driverDef['Driver Name'];

            // Étapes 4-5 — INSERT SER puis attach, transactionnels. Si
            // attach échoue, la transaction roll-back l'INSERT et on
            // tombe dans le catch — l'état partiel (driver Samba
            // enregistré sans association) est exposé via
            // `$pendingAttachDriver` pour bouton « Réessayer ».
            DB::transaction(function () use ($driverDef) {
                PrinterDriver::create([
                    'printer_cups_name' => $this->editingCupsName,
                    'architecture' => 'x64',
                    'driver_name' => $driverDef['Driver Name'],
                    'source' => 'upload-w10',
                    'orphan' => false,
                    'notes' => $this->newDriverDisplayName !== '' ? $this->newDriverDisplayName : null,
                    'created_by_user_id' => auth()->id(),
                ]);
                $this->driverService->attachDriverToPrinter($this->editingCupsName, $driverDef['Driver Name']);
            });

            $this->toastSuccess("Driver {$driverDef['Driver Name']} téléversé et associé à {$this->editingCupsName}.");
            $this->closeUploadDriverModal();
            $this->loadPrinterDrivers($this->editingCupsName);
            $this->refreshDriversPanelIfLoaded();
        } catch (WindowsPivotUnreachableException $e) {
            $this->driverService->unlinkDriverFiles($copiedFiles);
            $this->toastError("Poste pivot {$this->newDriverPivot} injoignable — vérifier qu'il est allumé.");
        } catch (KerberosTicketException $e) {
            if ($driverRegistered && $registeredDriverName !== null) {
                $this->offerRetryAttach($registeredDriverName, $driverDisplayName);
                return;
            }
            $this->driverService->unlinkDriverFiles($copiedFiles);
            $this->toastError('Authentification Samba expirée — contacter l\'admin système.');
        } catch (SambaUnavailableException $e) {
            if ($driverRegistered && $registeredDriverName !== null) {
                $this->offerRetryAttach($registeredDriverName, $driverDisplayName);
                return;
            }
            $this->driverService->unlinkDriverFiles($copiedFiles);
            $this->toastError('Service Samba injoignable — téléversement annulé.');
        } catch (PrintDriverException $e) {
            if ($driverRegistered && $registeredDriverName !== null) {
                $this->offerRetryAttach($registeredDriverName, $driverDisplayName);
                return;
            }
            $this->driverService->unlinkDriverFiles($copiedFiles);
            $this->toastError('Erreur Samba : ' . $e->firstStderrLine());
        } catch (\InvalidArgumentException $e) {
            $this->driverService->unlinkDriverFiles($copiedFiles);
            $this->toastError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('PrintersTab: erreur upload driver', [
                'pivot' => $this->newDriverPivot,
                'driver' => $this->newDriverName,
                'cups_name' => $this->editingCupsName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            if ($driverRegistered && $registeredDriverName !== null) {
                $this->offerRetryAttach($registeredDriverName, $driverDisplayName);
                return;
            }
            $this->driverService->unlinkDriverFiles($copiedFiles);
            $this->toastError('Une erreur interne est survenue lors du téléversement du driver.');
        } finally {
            $lock->release();
        }
    }

    /**
     * Q3A — bascule en état partiel récupérable : driver enregistré côté
     * Samba mais étape 4-5 (INSERT SER + attach) échouée. L'UI affiche
     * un bouton « Réessayer association » dans la section drivers de
     * la modale d'édit.
     */
    private function offerRetryAttach(string $driverName, string $displayName): void
    {
        $this->pendingAttachDriver = [
            'driver_name' => $driverName,
            'display_name' => $displayName,
        ];
        $this->toastWarning(sprintf(
            'Driver « %s » enregistré côté Samba mais association à %s échouée. Utilisez « Réessayer association » dans la section drivers.',
            $driverName,
            $this->editingCupsName,
        ));
        $this->closeUploadDriverModal();
        if ($this->editingCupsName !== null) {
            $this->loadPrinterDrivers($this->editingCupsName);
        }
        $this->refreshDriversPanelIfLoaded();
    }

    /**
     * Q3A — réessaie l'INSERT SER + attach pour un driver dans l'état
     * partiel `$pendingAttachDriver`. Idempotent : si la ligne SER
     * existe déjà (race), on log et on tente quand même `attachDriverToPrinter`.
     */
    public function retryAttachDriver(): void
    {
        if ($this->pendingAttachDriver === null || $this->editingCupsName === null) {
            return;
        }
        $printer = Printer::find($this->editingCupsName);
        if ($printer === null) {
            $this->toastError("Imprimante {$this->editingCupsName} introuvable.");
            $this->pendingAttachDriver = null;
            return;
        }
        Gate::authorize('manage-printer', $printer);

        $driverName = $this->pendingAttachDriver['driver_name'];
        $displayName = $this->pendingAttachDriver['display_name'];

        try {
            DB::transaction(function () use ($driverName, $displayName) {
                $existing = PrinterDriver::query()
                    ->where('printer_cups_name', $this->editingCupsName)
                    ->where('architecture', 'x64')
                    ->where('driver_name', $driverName)
                    ->first();
                if ($existing === null) {
                    PrinterDriver::create([
                        'printer_cups_name' => $this->editingCupsName,
                        'architecture' => 'x64',
                        'driver_name' => $driverName,
                        'source' => 'upload-w10',
                        'orphan' => false,
                        'notes' => $displayName !== '' ? $displayName : null,
                        'created_by_user_id' => auth()->id(),
                    ]);
                }
                $this->driverService->attachDriverToPrinter($this->editingCupsName, $driverName);
            });
            $this->toastSuccess("Driver {$driverName} associé à {$this->editingCupsName}.");
            $this->pendingAttachDriver = null;
            $this->loadPrinterDrivers($this->editingCupsName);
            $this->refreshDriversPanelIfLoaded();
        } catch (KerberosTicketException $e) {
            $this->toastError('Authentification Samba expirée — contacter l\'admin système.');
        } catch (SambaUnavailableException $e) {
            $this->toastError('Service Samba injoignable — réessai annulé.');
        } catch (PrintDriverException $e) {
            $this->toastError('Erreur Samba : ' . $e->firstStderrLine());
        } catch (\Throwable $e) {
            Log::error('PrintersTab: retry attach échoué', [
                'driver' => $driverName,
                'cups_name' => $this->editingCupsName,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Réessai association échoué.');
        }
    }

    /**
     * Story 6.2 — Détache un driver de l'imprimante éditée (`setdriver
     * "<printer>" ""`). Supprime la ligne SER `printer_drivers`
     * correspondante (le driver Samba reste publié — il peut être
     * réattaché ailleurs).
     */
    public function detachDriver(string $driverName, string $architecture = 'x64'): void
    {
        $printer = $this->editingCupsName !== null
            ? Printer::find($this->editingCupsName)
            : null;
        Gate::authorize('manage-printer', $printer);

        if ($this->editingCupsName === null) {
            $this->toastError("Aucune imprimante cible sélectionnée.");
            return;
        }

        try {
            $this->driverService->detachDriverFromPrinter($this->editingCupsName);

            // Supprime la ligne SER (par PK composite — Query Builder).
            PrinterDriver::query()
                ->where('printer_cups_name', $this->editingCupsName)
                ->where('architecture', $architecture)
                ->where('driver_name', $driverName)
                ->delete();

            $this->toastSuccess("Driver détaché de {$this->editingCupsName}.");
            $this->loadPrinterDrivers($this->editingCupsName);
            $this->refreshDriversPanelIfLoaded();
        } catch (KerberosTicketException $e) {
            $this->toastError('Authentification Samba expirée — contacter l\'admin système.');
        } catch (SambaUnavailableException $e) {
            $this->toastError('Service Samba injoignable — détachement annulé.');
        } catch (PrintDriverException $e) {
            $this->toastError('Erreur Samba : ' . $e->firstStderrLine());
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('PrintersTab: erreur détachement driver', [
                'driver' => $driverName,
                'cups_name' => $this->editingCupsName,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Une erreur interne est survenue lors du détachement du driver.');
        }
    }

    /**
     * Story 6.2 — Supprime un driver Samba via `rpcclient deldriver`.
     * Protection D8 : refuse si le driver est rattaché à ≥ 1 imprimante
     * dans la table SER `printer_drivers` (l'admin doit détacher d'abord).
     */
    public function deleteDriver(string $driverName, string $architecture = 'x64'): void
    {
        Gate::authorize('manage-printer');

        // D8 — protection rattachements.
        $attached = PrinterDriver::query()
            ->where('driver_name', $driverName)
            ->where('architecture', $architecture)
            ->pluck('printer_cups_name')
            ->all();
        if (!empty($attached)) {
            $list = implode(', ', $attached);
            $this->toastError("Détacher d'abord le driver de toutes les imprimantes : {$list}");
            return;
        }

        try {
            // Récupération de la définition driver (pour connaître les
            // fichiers à `unlink` post-deldriver). Best-effort — si
            // getdriver échoue, on supprime quand même côté Samba sans
            // unlink (le sync rattrapera l'orphan).
            $files = [];
            try {
                $def = $this->driverService->getDriverDefinitionFromSe4fs($driverName);
                foreach (['Driver Path', 'Datafile', 'Configfile', 'Helpfile'] as $key) {
                    if (!empty($def[$key]) && $def[$key] !== 'NULL') {
                        $files[] = $def[$key];
                    }
                }
                foreach ($def['Dependentfiles'] ?? [] as $dep) {
                    if ($dep !== '' && $dep !== 'NULL') {
                        $files[] = $dep;
                    }
                }
                $files = array_values(array_unique($files));
            } catch (\Throwable $e) {
                Log::warning('PrintersTab: lecture definition driver avant delete échouée', [
                    'driver' => $driverName,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->driverService->deleteDriver($driverName, $architecture, $files);
            $this->toastSuccess("Driver {$driverName} supprimé.");

            if ($this->editingCupsName !== null) {
                $this->loadPrinterDrivers($this->editingCupsName);
            }
            $this->refreshDriversPanelIfLoaded();
        } catch (KerberosTicketException $e) {
            $this->toastError('Authentification Samba expirée — contacter l\'admin système.');
        } catch (SambaUnavailableException $e) {
            $this->toastError('Service Samba injoignable — suppression driver annulée.');
        } catch (PrintDriverException $e) {
            $this->toastError('Erreur Samba : ' . $e->firstStderrLine());
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('PrintersTab: erreur suppression driver', [
                'driver' => $driverName,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Une erreur interne est survenue lors de la suppression du driver.');
        }
    }

    // ========================================================================
    // PANNEAU GLOBAL « PILOTES WINDOWS PUBLIÉS » (ex-onglet Drivers)
    // ========================================================================

    /**
     * Replie / déplie le panneau global. Lazy-load au premier dépliage :
     * on ne déclenche le `rpcclient enumdrivers` qu'à la demande.
     */
    public function toggleDriversPanel(): void
    {
        if (!Gate::allows('manage-printer')) {
            $this->toastAccessDenied();
            return;
        }

        $this->driversPanelOpen = !$this->driversPanelOpen;

        if ($this->driversPanelOpen && !$this->driversPanelLoaded) {
            $this->loadPublishedDrivers();
            $this->driversPanelLoaded = true;
        }
    }

    public function updatedDriverFilter(): void
    {
        if ($this->driversPanelLoaded) {
            $this->loadPublishedDrivers();
        }
    }

    public function updatedSourceFilter(): void
    {
        if ($this->driversPanelLoaded) {
            $this->loadPublishedDrivers();
        }
    }

    /**
     * Construit le listing global fusionné `rpcclient enumdrivers` (Samba
     * runtime) + enrichissement SER (audit, source, rattachements). Fusion sur
     * clé composite driver_name|architecture : un driver peut être en Samba
     * sans ligne SER (orphan inverse), en SER orphan (Samba l'a perdu), ou dans
     * les deux. Fail-soft : Samba injoignable → banner + liste SER seule.
     */
    public function loadPublishedDrivers(): void
    {
        $sambaList = [];
        try {
            $sambaList = $this->driverService->listAllDrivers();
            $this->driversPanelSambaOk = true;
        } catch (SambaUnavailableException $e) {
            $this->driversPanelSambaOk = false;
            Log::warning('PrintersTab: Samba injoignable (panneau drivers)', ['error' => $e->getMessage()]);
        } catch (KerberosTicketException $e) {
            $this->driversPanelSambaOk = false;
            Log::warning('PrintersTab: Kerberos KO (panneau drivers)', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->driversPanelSambaOk = false;
            Log::error('PrintersTab: erreur listage drivers Samba (panneau)', ['error' => $e->getMessage()]);
        }

        // Index Samba par clé composite.
        $sambaIndex = [];
        foreach ($sambaList as $d) {
            $sambaIndex[$d['driver_name'] . '|' . $d['architecture']] = $d;
        }

        // Index SER avec rattachements groupés par driver/arch.
        $serGrouped = [];
        foreach (PrinterDriver::query()->orderBy('driver_name')->get() as $row) {
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

        // Filtres UI.
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

        $this->publishedDrivers = $rows;
    }

    /**
     * Relance manuellement la réconciliation SER ↔ Samba (`printer-drivers:sync`).
     * Verrou anti-concurrence avec le cron (03:35) et les autres admins.
     */
    public function triggerSync(): void
    {
        Gate::authorize('manage-printer');

        $lock = Cache::lock('printer-drivers-sync', 60);
        if (!$lock->get()) {
            $this->toastWarning('Une synchronisation est déjà en cours. Réessayer dans quelques secondes.');
            return;
        }
        try {
            Artisan::call('printer-drivers:sync');
            $this->toastSuccess('Synchronisation drivers terminée.');
            $this->loadPublishedDrivers();
        } catch (\Throwable $e) {
            Log::error('PrintersTab: erreur déclenchement sync drivers', ['error' => $e->getMessage()]);
            $this->toastError('Erreur lors du déclenchement de la synchronisation.');
        } finally {
            $lock->release();
        }
    }

    /**
     * Rafraîchit le panneau global s'il est déjà chargé — appelé après chaque
     * mutation par-imprimante (upload / détache / suppression) pour garder
     * l'inventaire global cohérent sans forcer un reload à froid.
     */
    private function refreshDriversPanelIfLoaded(): void
    {
        if ($this->driversPanelLoaded) {
            $this->loadPublishedDrivers();
        }
    }

};
?>

@php
    $isAdmin = auth()->user()?->can('server.admin') ?? false;
@endphp

<div class="flex-1 min-h-0 flex flex-col gap-4">
    @unless ($cupsAvailable)
        <div class="alert alert-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>Service CUPS injoignable — les états et la file d'attente ne sont pas à jour.</span>
        </div>
    @endunless

    <!-- Filtres + bouton ajout -->
    <div class="flex-shrink-0 border-b border-base-300 pb-3">
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

    {{-- Panneau global « Pilotes Windows publiés » (ex-onglet Drivers, admin) --}}
    @can('manage-printer')
        <div class="flex-shrink-0 card bg-base-100 shadow-sm">
            {{-- En-tête cliquable (replie / déplie) --}}
            <div role="button" tabindex="0" wire:click="toggleDriversPanel"
                class="card-body py-3 flex-row items-center justify-between gap-3 cursor-pointer select-none">
                <div class="flex items-center gap-2">
                    <i class="fa-solid {{ $driversPanelOpen ? 'fa-chevron-down' : 'fa-chevron-right' }} text-xs opacity-60"></i>
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span class="font-semibold">Pilotes Windows publiés</span>
                    @if ($driversPanelLoaded)
                        <span class="badge badge-ghost badge-sm">{{ count($publishedDrivers) }}</span>
                    @endif
                </div>
                <span class="text-xs text-base-content/50 hidden sm:inline">Inventaire global · orphelins · synchronisation</span>
            </div>

            @if ($driversPanelOpen)
                <div class="card-body pt-0 gap-3">
                    @unless ($driversPanelSambaOk)
                        <div class="alert alert-warning">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>Service Samba injoignable — drivers indisponibles. Vérifier le service `smbd` et le
                                ticket Kerberos du compte machine `se4fs$`.</span>
                        </div>
                    @endunless

                    {{-- Filtres + bouton sync --}}
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
                            <button type="button" class="btn btn-sm btn-outline" wire:click="triggerSync"
                                @if (!$driversPanelSambaOk) disabled @endif>
                                <i class="fa-solid fa-rotate"></i>
                                Synchroniser
                            </button>
                        </div>
                    </div>

                    {{-- Tableau drivers --}}
                    @if (empty($publishedDrivers))
                        <div class="flex flex-col items-center justify-center py-10">
                            <div class="text-4xl mb-3 opacity-20">
                                <i class="fa-solid fa-floppy-disk"></i>
                            </div>
                            <p class="text-base-content/60 text-center max-w-md">
                                Aucun driver Windows publié. Pour en téléverser un : ouvrir la modale d'édition d'une
                                imprimante ci-dessus puis la section « Drivers Windows ».
                            </p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table table-sm table-zebra">
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
                                    @foreach ($publishedDrivers as $d)
                                        <tr wire:key="pub-drv-{{ $d['driver_name'] }}-{{ $d['architecture'] }}">
                                            <td class="text-xs font-mono">{{ $d['driver_name'] }}</td>
                                            <td><span class="badge badge-ghost badge-sm">{{ $d['architecture'] }}</span></td>
                                            <td class="text-xs">{{ $d['source'] ?? '—' }}</td>
                                            <td>
                                                <div class="flex flex-wrap gap-1">
                                                    @forelse ($d['attached_printers'] as $cupsName)
                                                        <span class="badge badge-outline badge-sm">{{ $cupsName }}</span>
                                                    @empty
                                                        <span class="text-xs text-base-content/40">—</span>
                                                    @endforelse
                                                </div>
                                            </td>
                                            <td>
                                                @if ($d['orphan'])
                                                    <span class="badge badge-error badge-sm">orphelin</span>
                                                @elseif (!$d['is_in_samba'])
                                                    <span class="badge badge-warning badge-sm">hors Samba</span>
                                                @else
                                                    <span class="badge badge-success badge-sm">actif</span>
                                                @endif
                                            </td>
                                            <td class="text-xs">{{ $d['created_at'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endcan

    <!-- Modale ajout -->
    @teleport('body')
        <x-molecules.modal wire:model="showAddModal" title="Nouvelle imprimante" closeMethod="closeAddModal"
            size="max-w-3xl" height="h-auto max-h-[90vh]">

            <x-molecules.modal.section title="Configuration CUPS">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="form-control w-full">
                        <label class="label py-1"><span class="label-text font-medium">Nom <span class="text-error">*</span></span></label>
                        <input type="text" wire:model="newName" class="input input-bordered input-sm w-full font-mono"
                            placeholder="ex: imp-salle-a" maxlength="15" />
                        @error('newName')
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                        <span class="text-xs text-base-content/60 mt-1">Lettres, chiffres, _ et -. Max 15 caractères.</span>
                    </div>
                    <div class="form-control w-full">
                        <label class="label py-1"><span class="label-text font-medium">URI <span class="text-error">*</span></span></label>
                        <input type="text" wire:model="newUri" class="input input-bordered input-sm w-full font-mono"
                            placeholder="socket://192.168.1.10:9100" />
                        @error('newUri')
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                        <span class="text-xs text-base-content/60 mt-1">socket:// ipp:// ipps:// lpd:// http://
                            https://</span>
                    </div>
                    <div class="form-control w-full">
                        <label class="label py-1"><span class="label-text font-medium">Description</span></label>
                        <input type="text" wire:model="newDescription" class="input input-bordered input-sm w-full" />
                    </div>
                    <div class="form-control w-full">
                        <label class="label py-1"><span class="label-text font-medium">Lieu</span></label>
                        <input type="text" wire:model="newLocation" class="input input-bordered input-sm w-full" />
                    </div>
                    <div class="form-control w-full md:col-span-2">
                        <label class="label py-1"><span class="label-text font-medium">Modèle (PPD)</span></label>
                        <select wire:model="newPpd" class="select select-bordered select-sm w-full">
                            <option value="">— aucun (raw) —</option>
                            @foreach ($availableDrivers as $drv)
                                <option value="{{ $drv['ppd'] }}">{{ $drv['model'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Rattachement aux parcs">
                @include('pages.parc._partials.parc-attach-table', [
                    'availableGroups' => $availableGroups,
                    'model' => 'newWorkstationGroupIds',
                ])
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
                        <div class="form-control w-full md:col-span-2">
                            <label class="label py-1"><span class="label-text font-medium">Nom (verrouillé)</span></label>
                            <input type="text" value="{{ $editingCupsName }}" disabled
                                class="input input-bordered input-sm w-full font-mono" />
                            <span class="text-xs text-base-content/60 mt-1">Pour renommer, supprimer puis recréer.</span>
                        </div>
                        <div class="form-control w-full md:col-span-2">
                            <label class="label py-1"><span class="label-text font-medium">URI <span class="text-error">*</span></span></label>
                            <input type="text" wire:model="editUri" class="input input-bordered input-sm w-full font-mono" />
                            @error('editUri')
                                <span class="text-xs text-error mt-1">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="form-control w-full">
                            <label class="label py-1"><span class="label-text font-medium">Description</span></label>
                            <input type="text" wire:model="editDescription" class="input input-bordered input-sm w-full" />
                        </div>
                        <div class="form-control w-full">
                            <label class="label py-1"><span class="label-text font-medium">Lieu</span></label>
                            <input type="text" wire:model="editLocation" class="input input-bordered input-sm w-full" />
                        </div>
                        <div class="form-control w-full md:col-span-2">
                            <label class="label py-1"><span class="label-text font-medium">Changer le modèle PPD</span></label>
                            <select wire:model="editPpd" class="select select-bordered select-sm w-full">
                                <option value="">— ne pas changer —</option>
                                @foreach ($availableDrivers as $drv)
                                    <option value="{{ $drv['ppd'] }}">{{ $drv['model'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </x-molecules.modal.section>

                <x-molecules.modal.section title="Rattachement aux parcs">
                    @include('pages.parc._partials.parc-attach-table', [
                        'availableGroups' => $availableGroups,
                        'model' => 'editWorkstationGroupIds',
                    ])
                </x-molecules.modal.section>

                {{-- Story 6.2 — Drivers Windows (section dans modale édit) --}}
                @can('manage-printer')
                    <x-molecules.modal.section title="Drivers Windows">
                        @unless ($sambaAvailable)
                            <div class="alert alert-warning mb-3">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <span>Samba injoignable — drivers indisponibles. Vérifier l'état du service et le ticket
                                    Kerberos du compte machine.</span>
                            </div>
                        @endunless

                        {{-- Q3A — état partiel récupérable après registerDriver OK + attach KO --}}
                        @if ($pendingAttachDriver !== null)
                            <div class="alert alert-warning mb-3">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <div class="flex-1">
                                    <p>
                                        Driver <span class="font-mono">{{ $pendingAttachDriver['driver_name'] }}</span>
                                        enregistré côté Samba mais non associé à
                                        <span class="font-mono">{{ $editingCupsName }}</span>.
                                    </p>
                                    <button type="button" class="btn btn-sm btn-warning mt-2"
                                        @if (!$sambaAvailable) disabled @endif
                                        wire:click="retryAttachDriver">
                                        <i class="fa-solid fa-rotate"></i>
                                        Réessayer association
                                    </button>
                                </div>
                            </div>
                        @endif

                        @php
                            $samba = $printerDrivers['samba'] ?? null;
                            $ser = $printerDrivers['ser'] ?? [];
                        @endphp

                        @if ($sambaAvailable && empty($ser) && $samba === null)
                            <p class="text-sm text-base-content/60 mb-3">
                                Aucun driver Windows associé — utilisez « Téléverser un driver » pour permettre
                                l'installation automatique sur les postes Windows.
                            </p>
                        @endif

                        @if ($samba !== null)
                            <div class="text-xs mb-2 font-mono text-base-content/70">
                                <span class="font-semibold">Samba :</span>
                                driver actif côté SE4FS = « {{ $samba['smb_driver'] }} »
                            </div>
                        @endif

                        @if (!empty($ser))
                            <div class="overflow-x-auto">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Nom Samba</th>
                                            <th>Arch.</th>
                                            <th>Source</th>
                                            <th>Statut</th>
                                            <th class="text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($ser as $drv)
                                            <tr
                                                wire:key="drv-{{ $drv['driver_name'] }}-{{ $drv['architecture'] }}">
                                                <td class="text-xs font-mono">{{ $drv['driver_name'] }}</td>
                                                <td><span class="badge badge-ghost badge-sm">{{ $drv['architecture'] }}</span></td>
                                                <td class="text-xs">{{ $drv['source'] }}</td>
                                                <td>
                                                    @if ($drv['orphan'])
                                                        <span class="badge badge-error badge-sm">orphan</span>
                                                    @else
                                                        <span class="badge badge-success badge-sm">actif</span>
                                                    @endif
                                                </td>
                                                <td class="text-right">
                                                    <div class="join">
                                                        <button type="button"
                                                            class="join-item btn btn-xs btn-ghost"
                                                            @if (!$sambaAvailable) disabled @endif
                                                            wire:click="detachDriver('{{ $drv['driver_name'] }}', '{{ $drv['architecture'] }}')"
                                                            wire:confirm="Détacher le driver {{ $drv['driver_name'] }} de l'imprimante {{ $editingCupsName }} ?"
                                                            title="Détacher de cette imprimante">
                                                            <i class="fa-solid fa-link-slash"></i>
                                                        </button>
                                                        <button type="button"
                                                            class="join-item btn btn-xs btn-ghost text-error"
                                                            @if (!$sambaAvailable) disabled @endif
                                                            wire:click="deleteDriver('{{ $drv['driver_name'] }}', '{{ $drv['architecture'] }}')"
                                                            wire:confirm="Supprimer définitivement le driver {{ $drv['driver_name'] }} de Samba ? Cette action est irréversible."
                                                            title="Supprimer le driver de Samba">
                                                            <i class="fa-solid fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <div class="mt-3">
                            <button type="button" class="btn btn-sm btn-outline" wire:click="openUploadDriverModal"
                                @if (!$sambaAvailable) disabled @endif>
                                <i class="fa-solid fa-upload"></i>
                                Téléverser un driver
                            </button>
                        </div>
                    </x-molecules.modal.section>
                @endcan
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

    {{-- Story 6.2 — Modale upload driver (partial dédié) --}}
    @include('pages.parc._partials.upload-driver-modal')
</div>
