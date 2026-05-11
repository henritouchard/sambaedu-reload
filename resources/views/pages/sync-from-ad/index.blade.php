<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Services\UserSyncService;
use App\Services\UserGroupService;
use App\Services\ShortcutsService;
use App\Services\Permissions\RightsMigrationService;
use App\Facades\SEConfig;
use App\Repositories\EstablishmentRepository;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Log;

new #[Title('Synchronisation depuis l\'AD - SE4FS')] class extends Component {
    use WithToasts;

    // État des étapes
    public array $steps = [];

    // Logs par étape
    public array $stepLogs = [];

    // État global
    public bool $isRunning = false;
    public ?string $currentStep = null;

    // Sélection établissement
    public array $availableEstablishments = [];
    public string $currentEstablishmentCode = '0';

    private EstablishmentRepository $establishmentRepository;

    public function boot(EstablishmentRepository $establishmentRepository): void
    {
        $this->establishmentRepository = $establishmentRepository;
    }

    public function mount(): void
    {
        $this->loadEstablishments();
        $this->initializeSteps();
    }

    private function loadEstablishments(): void
    {
        $this->availableEstablishments = $this->establishmentRepository->getAll();

        $current = session('etab', null);
        if ($current === null || $current === '') {
            $current = SEConfig::getCurrentEstablishmentCode() ?? '0';
        }

        $this->currentEstablishmentCode = (string) $current;
    }

    public function updatedCurrentEstablishmentCode(string $value): void
    {
        $selected = trim($value) === '' ? '0' : trim($value);
        session()->put('etab', $selected);
        $this->currentEstablishmentCode = $selected;

        $this->initializeSteps();
        $this->toastInfo('Contexte LDAP mis à jour pour la synchronisation');
    }

    private function initializeSteps(): void
    {
        $this->steps = [
            'users_establishment' => [
                'id' => 'users_establishment',
                'title' => '1. Importer les utilisateurs de l\'établissement',
                'description' => 'Importe tous les utilisateurs rattachés ou liés à l\'établissement (arborescence + memberOf)',
                'status' => 'pending', // pending, running, success, skipped, error
                'stats' => null,
                'error' => null,
                'expanded' => false,
            ],
            'user_groups' => [
                'id' => 'user_groups',
                'title' => '2. Importer les groupes utilisateurs',
                'description' => 'Synchronise les groupes utilisateurs directement depuis l\'AD vers SQL',
                'status' => 'pending',
                'stats' => null,
                'error' => null,
                'expanded' => false,
            ],
            'workstations' => [
                'id' => 'workstations',
                'title' => '3. Importer les postes de travail',
                'description' => 'Importe les machines depuis OU=Computers',
                'status' => 'pending',
                'stats' => null,
                'error' => null,
                'expanded' => false,
            ],
            'physical_groups' => [
                'id' => 'physical_groups',
                'title' => '4. Importer les groupes physiques (salles)',
                'description' => 'Importe les OU depuis OU=Computers et crée les liens avec les postes',
                'status' => 'pending',
                'stats' => null,
                'error' => null,
                'expanded' => false,
            ],
            'logical_groups' => [
                'id' => 'logical_groups',
                'title' => '5. Importer les groupes logiques (parcs)',
                'description' => 'Importe les CN depuis OU=Parcs (ignore les groupes physiques existants)',
                'status' => 'pending',
                'stats' => null,
                'error' => null,
                'expanded' => false,
            ],
            'app_profiles' => [
                'id' => 'app_profiles',
                'title' => '6. Importer les profils applicatifs',
                'description' => 'Importe les AppProfiles depuis OU=Parcs',
                'status' => 'pending',
                'stats' => null,
                'error' => null,
                'expanded' => false,
            ],
            'shortcuts' => [
                'id' => 'shortcuts',
                'title' => '7. Importer les raccourcis',
                'description' => 'Importe les raccourcis depuis le fichier JSON vers la base de données',
                'status' => 'pending',
                'stats' => null,
                'error' => null,
                'expanded' => false,
            ],
            // Story 7.2 — AC4 : rapatriement non-destructif des profils LDAP custom.
            'rights_profiles' => [
                'id' => 'rights_profiles',
                'title' => '8. Rapatrier les profils LDAP custom',
                'description' => 'Scanne la branche Rights (rights_rdn) et crée côté SER les profils custom absents (non-destructif, n\'écrase jamais un profil existant)',
                'status' => 'pending',
                'stats' => null,
                'error' => null,
                'expanded' => false,
            ],
            // Story 7.3 — migration one-shot bitmask → Spatie (rôles + délégations scopées).
            // L'étape affiche deux boutons : Aperçu (dry-run) et Exécuter.
            // Dans « Tout exécuter », seul le dry-run est lancé automatiquement.
            'rights_migration' => [
                'id' => 'rights_migration',
                'title' => '9. Migrer les droits legacy → Spatie',
                'description' => 'Migration one-shot : lit les assignations bitmask de l\'AD (rights_rdn + delegations_rdn) et les pose dans Spatie. Lancez d\'abord l\'Aperçu, puis Exécuter pour appliquer.',
                'status' => 'pending',
                'stats' => null,
                'error' => null,
                'expanded' => false,
            ],
        ];

        $this->stepLogs = [
            'users_establishment' => [],
            'user_groups' => [],
            'workstations' => [],
            'physical_groups' => [],
            'logical_groups' => [],
            'app_profiles' => [],
            'shortcuts' => [],
            'rights_profiles' => [],
            'rights_migration' => [],
        ];
    }

    public function toggleExpanded(string $stepId): void
    {
        if (isset($this->steps[$stepId])) {
            $this->steps[$stepId]['expanded'] = !$this->steps[$stepId]['expanded'];
        }
    }

    public function runStep(string $stepId): void
    {
        if ($this->isRunning) {
            $this->toastWarning('Une synchronisation est déjà en cours');
            return;
        }

        $this->isRunning = true;
        $this->currentStep = $stepId;
        $this->steps[$stepId]['status'] = 'running';
        $this->steps[$stepId]['error'] = null;
        $this->steps[$stepId]['stats'] = null;
        $this->stepLogs[$stepId] = [];

        try {
            $this->addLog($stepId, 'info', 'Démarrage de l\'import...');

            switch ($stepId) {
                case 'users_establishment':
                    $this->runUsersEstablishmentSync();
                    break;
                case 'user_groups':
                    $this->runUserGroupsSync();
                    break;
                case 'physical_groups':
                    $this->runPhysicalGroupsSync();
                    break;
                case 'logical_groups':
                    $this->runLogicalGroupsSync();
                    break;
                case 'workstations':
                    $this->runWorkstationsSync();
                    break;
                case 'app_profiles':
                    $this->runAppProfilesSync();
                    break;
                case 'shortcuts':
                    $this->runShortcutsSync();
                    break;
                case 'rights_profiles':
                    $this->runRightsProfilesSync();
                    break;
            }

            if (!empty($this->steps[$stepId]['stats']['already_imported'])) {
                $this->steps[$stepId]['status'] = 'skipped';
                $this->toastInfo('Étape déjà exécutée — sautée');
            } else {
                $this->steps[$stepId]['status'] = 'success';
                $this->addLog($stepId, 'success', 'Import terminé avec succès');
                $this->toastSuccess('Import terminé avec succès');
            }
        } catch (\Exception $e) {
            $this->steps[$stepId]['status'] = 'error';
            $this->steps[$stepId]['error'] = $e->getMessage();
            $this->addLog($stepId, 'error', 'Erreur: ' . $e->getMessage());
            $this->toastError('Erreur lors de l\'import: ' . $e->getMessage());
            Log::error('[SyncFromAD] Erreur étape ' . $stepId, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            $this->isRunning = false;
            $this->currentStep = null;
            $this->steps[$stepId]['expanded'] = true;
        }
    }

    public function runAllSteps(): void
    {
        if ($this->isRunning) {
            $this->toastWarning('Une synchronisation est déjà en cours');
            return;
        }

        $this->initializeSteps();

        foreach (array_keys($this->steps) as $stepId) {
            // Étape 9 : dry-run automatique en mode « Tout exécuter »,
            // l'opérateur doit cliquer « Exécuter » manuellement après vérification.
            if ($stepId === 'rights_migration') {
                $this->executeMigrationStep(dryRun: true);
            } else {
                $this->runStep($stepId);
            }

            // Arrêter si une étape échoue
            if ($this->steps[$stepId]['status'] === 'error') {
                $this->toastError('Synchronisation interrompue suite à une erreur');
                break;
            }
        }
    }

    public function runMigrationDryRun(): void
    {
        $this->executeMigrationStep(dryRun: true);
    }

    public function runMigrationExecute(): void
    {
        $this->executeMigrationStep(dryRun: false);
    }

    private function executeMigrationStep(bool $dryRun): void
    {
        if ($this->isRunning) {
            $this->toastWarning('Une synchronisation est déjà en cours');
            return;
        }

        $stepId = 'rights_migration';
        $this->isRunning = true;
        $this->currentStep = $stepId;
        $this->steps[$stepId]['status'] = 'running';
        $this->steps[$stepId]['error'] = null;
        $this->steps[$stepId]['stats'] = null;
        $this->stepLogs[$stepId] = [];

        try {
            $this->addLog($stepId, 'info', $dryRun ? 'Aperçu (dry-run) en cours…' : 'Migration en cours…');
            $this->runRightsMigrationSync($dryRun);

            if ($dryRun) {
                $this->steps[$stepId]['status'] = 'dry_run_done';
                $this->addLog($stepId, 'info', 'Aperçu terminé — vérifiez les résultats puis cliquez « Exécuter » pour appliquer.');
                $this->toastInfo('Aperçu terminé');
            } else {
                $this->steps[$stepId]['status'] = 'success';
                $this->addLog($stepId, 'success', 'Migration appliquée avec succès');
                $this->toastSuccess('Migration des droits terminée');
            }
        } catch (\Exception $e) {
            $this->steps[$stepId]['status'] = 'error';
            $this->steps[$stepId]['error'] = $e->getMessage();
            $this->addLog($stepId, 'error', 'Erreur : ' . $e->getMessage());
            $this->toastError('Erreur lors de la migration : ' . $e->getMessage());
            Log::error('[SyncFromAD] Erreur étape ' . $stepId, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            $this->isRunning = false;
            $this->currentStep = null;
            $this->steps[$stepId]['expanded'] = true;
        }
    }

    private function runRightsMigrationSync(bool $dryRun): void
    {
        $stepId = 'rights_migration';
        $service = app(RightsMigrationService::class);

        $report = $service->migrate(dryRun: $dryRun);

        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->addLog($stepId, 'info', "{$prefix}Utilisateurs scannés : {$report['users_scanned']}");
        $this->addLog($stepId, 'info', "{$prefix}Rôles assignés : {$report['roles_assigned']}");
        $this->addLog($stepId, 'info', "{$prefix}Délégations positives : {$report['delegations_created']}");

        if ($report['negatives_created'] > 0) {
            $this->addLog($stepId, 'info', "{$prefix}Délégations négatives : {$report['negatives_created']}");
        }

        if ($report['fallbacks_ignored'] > 0) {
            $this->addLog($stepId, 'warning', "{$prefix}Fallbacks buggés ignorés : {$report['fallbacks_ignored']}");
        }

        foreach ($report['warnings'] as $warning) {
            $this->addLog($stepId, 'warning', "{$prefix}{$warning}");
        }

        if (!empty($report['unmappable'])) {
            $this->addLog($stepId, 'warning', "{$prefix}Cas non mappables : " . count($report['unmappable']));
            foreach ($report['unmappable'] as $item) {
                $this->addLog($stepId, 'warning', "  [{$item['kind']}] {$item['reason']}");
            }
        }

        $this->steps[$stepId]['stats'] = $report;
    }

    private function runUsersEstablishmentSync(): void
    {
        $this->ensureEstablishmentContextSelected();

        $userSyncService = app(UserSyncService::class);

        $stats = $userSyncService->importFromAd(function (string $level, string $message) {
            $this->addLog('users_establishment', $level, $message);
        }, 'all');

        $this->steps['users_establishment']['stats'] = $stats;
    }

    private function runUserGroupsSync(): void
    {
        $this->addLog('user_groups', 'info', 'Import des groupes utilisateurs depuis l\'AD...');

        $userGroupService = app(UserGroupService::class);

        $stats = $userGroupService->importFromUsersAdGroups(function (string $level, string $message): void {
            $this->addLog('user_groups', $level, $message);
        });

        $this->addLog('user_groups', 'success', "Groupes utilisateurs importés: {$stats['created']} créés, {$stats['updated']} mis à jour, {$stats['linked_users']} liaison(s)");

        $this->steps['user_groups']['stats'] = $stats;
    }

    private function ensureEstablishmentContextSelected(): void
    {
        $establishmentCode = SEConfig::getCurrentEstablishmentCode();

        if ($establishmentCode === null || $establishmentCode === '') {
            throw new \RuntimeException('Aucun contexte établissement détecté. Sélectionnez un établissement ou "Domaine entier" puis relancez l\'étape.');
        }
    }

    private function runPhysicalGroupsSync(): void
    {
        $this->ensureEstablishmentContextSelected();

        $workstationGroupService = app(\App\Services\Parc\WorkstationGroupService::class);

        $stats = $workstationGroupService->importFromAd(function (string $level, string $message) {
            $this->addLog('physical_groups', $level, $message);
        });

        $this->steps['physical_groups']['stats'] = $stats;
    }

    private function runLogicalGroupsSync(): void
    {
        $this->ensureEstablishmentContextSelected();

        $workstationGroupService = app(\App\Services\Parc\WorkstationGroupService::class);

        $stats = $workstationGroupService->importLogicalGroupsFromAd(function (string $level, string $message) {
            $this->addLog('logical_groups', $level, $message);
        });

        $this->steps['logical_groups']['stats'] = $stats;
    }

    private function runWorkstationsSync(): void
    {
        $this->ensureEstablishmentContextSelected();

        $workstationService = app(\App\Services\WorkstationService::class);

        $stats = $workstationService->importFromAd(function (string $level, string $message) {
            $this->addLog('workstations', $level, $message);
        });

        $this->steps['workstations']['stats'] = $stats;
    }

    private function runAppProfilesSync(): void
    {
        $this->ensureEstablishmentContextSelected();

        $importer = app(\App\Services\AppProfile\AppProfileAdImporter::class);

        $stats = $importer->importFromAd(function (string $level, string $message) {
            $this->addLog('app_profiles', $level, $message);
        });

        $this->steps['app_profiles']['stats'] = $stats;
    }

    private function runShortcutsSync(): void
    {
        $shortcutsService = app(ShortcutsService::class);

        $this->addLog('shortcuts', 'info', 'Lecture du fichier JSON des raccourcis...');
        $stats = $shortcutsService->importFromJson();

        $this->addLog('shortcuts', 'info', "{$stats['created']} créé(s), {$stats['updated']} mis à jour, {$stats['errors']} erreur(s)");
        $this->steps['shortcuts']['stats'] = $stats;
    }

    /**
     * Story 7.2 — AC4 : rapatriement non-destructif des profils LDAP custom.
     * Les profils seedés (5 profils livrés) sont ignorés — gérés par le
     * `PermissionSeeder`. Les profils historiques (`sovajon_is_admin`, …)
     * sont mappés vers leur rôle Spatie équivalent. Les profils custom
     * "Animateur CDI" & co. sont créés côté SER s'ils n'existent pas, sans
     * écraser les profils déjà en base.
     */
    private function runRightsProfilesSync(): void
    {
        $this->addLog('rights_profiles', 'info', 'Scan de la branche Rights de l\'AD…');

        $permissionService = app(\App\Services\PermissionService::class);
        $stats = $permissionService->importCustomProfilesFromAd(function (string $level, string $message): void {
            $this->addLog('rights_profiles', $level, $message);
        });

        if (!empty($stats['already_imported'])) {
            $this->addLog(
                'rights_profiles',
                'info',
                'Import déjà effectué — étape sautée (les profils sont gérés en SQL).'
            );
        } else {
            $this->addLog(
                'rights_profiles',
                'success',
                sprintf(
                    '%d profils scannés, %d seedés ignorés, %d historiques mappés, %d nouveaux custom, %d custom inchangés',
                    $stats['scanned'], $stats['seeded_skipped'], $stats['historic_mapped'],
                    $stats['custom_new'], $stats['custom_unchanged']
                )
            );
        }
        $this->steps['rights_profiles']['stats'] = $stats;
    }

    private function addLog(string $stepId, string $level, string $message): void
    {
        $this->stepLogs[$stepId][] = [
            'time' => now()->format('H:i:s'),
            'level' => $level,
            'message' => $message,
        ];
    }

    public function resetAll(): void
    {
        $this->initializeSteps();
        $this->toastInfo('Réinitialisé');
    }
};
?>

<x-organisms.page title="Synchronisation depuis l'AD" :scrollable="true"
    description="Assistant de mise en place Laravel - Import des données depuis Active Directory">

    <x-slot:actions>
        <div class="flex gap-2">
            <div class="min-w-80">
                <label class="label py-0">
                    <span class="label-text text-xs">Contexte de synchronisation</span>
                </label>
                <select wire:model.live="currentEstablishmentCode" class="select select-bordered select-sm w-full">
                    @foreach ($availableEstablishments as $code => $label)
                        <option value="{{ (string) $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" wire:click="runAllSteps" class="btn btn-primary" wire:loading.attr="disabled"
                wire:target="runAllSteps, runStep" {{ $isRunning ? 'disabled' : '' }}>
                <span wire:loading.remove wire:target="runAllSteps">
                    <i class="fa-solid fa-play"></i>
                    Tout exécuter
                </span>
                <span wire:loading wire:target="runAllSteps">
                    <span class="loading loading-spinner loading-sm"></span>
                    Exécution...
                </span>
            </button>
            <button type="button" wire:click="resetAll" class="btn btn-outline" wire:loading.attr="disabled"
                {{ $isRunning ? 'disabled' : '' }}>
                <i class="fa-solid fa-rotate-left"></i>
                Réinitialiser
            </button>
        </div>
    </x-slot:actions>

    <div class="space-y-4">
        {{-- Info card --}}
        <div class="alert alert-info">
            <i class="fa-solid fa-circle-info text-lg"></i>
            <div>
                <h3 class="font-bold">Assistant de synchronisation</h3>
                <p class="text-sm">
                    Cet assistant permet d'importer les données depuis l'Active Directory vers la base de données
                    Laravel.
                    Exécutez les étapes dans l'ordre ou cliquez sur "Tout exécuter" pour lancer l'import complet.
                </p>
            </div>
        </div>

        {{-- Steps --}}
        <div class="space-y-3">
            @foreach ($steps as $stepId => $step)
                <div class="card bg-base-100 shadow-sm border border-base-300">
                    <div class="card-body p-4">
                        {{-- Header --}}
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                {{-- Status icon --}}
                                @switch($step['status'])
                                    @case('pending')
                                        <div class="w-10 h-10 rounded-full bg-base-200 flex items-center justify-center">
                                            <i class="fa-solid fa-circle text-base-content/30"></i>
                                        </div>
                                    @break

                                    @case('running')
                                        <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
                                            <span class="loading loading-spinner loading-sm text-primary"></span>
                                        </div>
                                    @break

                                    @case('success')
                                        <div class="w-10 h-10 rounded-full bg-success/10 flex items-center justify-center">
                                            <i class="fa-solid fa-check text-success"></i>
                                        </div>
                                    @break

                                    @case('skipped')
                                        <div class="w-10 h-10 rounded-full bg-info/10 flex items-center justify-center" title="Étape déjà exécutée — sautée">
                                            <i class="fa-solid fa-forward text-info"></i>
                                        </div>
                                    @break

                                    @case('dry_run_done')
                                        <div class="w-10 h-10 rounded-full bg-warning/10 flex items-center justify-center" title="Aperçu effectué — cliquez Exécuter pour appliquer">
                                            <i class="fa-solid fa-magnifying-glass text-warning"></i>
                                        </div>
                                    @break

                                    @case('error')
                                        <div class="w-10 h-10 rounded-full bg-error/10 flex items-center justify-center">
                                            <i class="fa-solid fa-xmark text-error"></i>
                                        </div>
                                    @break
                                @endswitch

                                {{-- Title & description --}}
                                <div>
                                    <h3 class="font-semibold">{{ $step['title'] }}</h3>
                                    <p class="text-sm text-base-content/60">{{ $step['description'] }}</p>
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-2">
                                {{-- Stats badges --}}
                                @if ($step['stats'])
                                    <div class="flex gap-1">
                                        @if (isset($step['stats']['total_ad']) && $step['stats']['total_ad'] > 0)
                                            <span class="badge badge-neutral badge-sm">{{ $step['stats']['total_ad'] }}
                                                AD</span>
                                        @endif
                                        @if (isset($step['stats']['etab_tree']) && $step['stats']['etab_tree'] > 0)
                                            <span class="badge badge-primary badge-sm">Etab/arbo
                                                {{ $step['stats']['etab_tree'] }}</span>
                                        @endif
                                        @if (isset($step['stats']['etab_member_of']) && $step['stats']['etab_member_of'] > 0)
                                            <span class="badge badge-secondary badge-sm">Etab/memberOf
                                                {{ $step['stats']['etab_member_of'] }}</span>
                                        @endif
                                        @if (isset($step['stats']['etab_excluded']) && $step['stats']['etab_excluded'] > 0)
                                            <span class="badge badge-ghost badge-sm">Exclus
                                                {{ $step['stats']['etab_excluded'] }}</span>
                                        @endif
                                        @if (isset($step['stats']['created']) && $step['stats']['created'] > 0)
                                            <span
                                                class="badge badge-success badge-sm">+{{ $step['stats']['created'] }}</span>
                                        @endif
                                        @if (isset($step['stats']['updated']) && $step['stats']['updated'] > 0)
                                            <span
                                                class="badge badge-info badge-sm">~{{ $step['stats']['updated'] }}</span>
                                        @endif
                                        @if (isset($step['stats']['linked']) && $step['stats']['linked'] > 0)
                                            <span
                                                class="badge badge-warning badge-sm">🔗{{ $step['stats']['linked'] }}</span>
                                        @endif
                                        @if (isset($step['stats']['linked_groups']) && $step['stats']['linked_groups'] > 0)
                                            <span
                                                class="badge badge-warning badge-sm">🔗{{ $step['stats']['linked_groups'] }}</span>
                                        @endif
                                        @if (isset($step['stats']['errors']) && (is_array($step['stats']['errors']) ? count($step['stats']['errors']) : $step['stats']['errors']) > 0)
                                            <span class="badge badge-error badge-sm">{{ is_array($step['stats']['errors']) ? count($step['stats']['errors']) : $step['stats']['errors'] }}
                                                err</span>
                                        @endif
                                        @if (isset($step['stats']['admin_granted']) && $step['stats']['admin_granted'])
                                            <span class="badge badge-accent badge-sm">admin</span>
                                        @endif
                                        {{-- Badges spécifiques étape 9 (migration rights) --}}
                                        @if ($stepId === 'rights_migration')
                                            @if (isset($step['stats']['users_scanned']) && $step['stats']['users_scanned'] > 0)
                                                <span class="badge badge-neutral font-bold h-12">{{ $step['stats']['users_scanned'] }} users</span>
                                            @endif
                                            @if (isset($step['stats']['roles_assigned']) && $step['stats']['roles_assigned'] > 0)
                                                <span class="badge badge-success font-bold h-12">+{{ $step['stats']['roles_assigned'] }} rôles</span>
                                            @endif
                                            @if (isset($step['stats']['delegations_created']) && $step['stats']['delegations_created'] > 0)
                                                <span class="badge badge-info font-bold h-12">+{{ $step['stats']['delegations_created'] }} délég.</span>
                                            @endif
                                            @if (isset($step['stats']['negatives_created']) && $step['stats']['negatives_created'] > 0)
                                                <span class="badge badge-warning font-bold h-12">{{ $step['stats']['negatives_created'] }} excl.</span>
                                            @endif
                                            @if (isset($step['stats']['unmappable']) && count($step['stats']['unmappable']) > 0)
                                                <span class="badge badge-error font-bold h-12">{{ count($step['stats']['unmappable']) }} non mappés</span>
                                            @endif
                                        @endif
                                    </div>
                                @endif

                                {{-- Run button (ou deux boutons pour les étapes 9 et 10) --}}
                                @if ($stepId === 'rights_migration')
                                    <button type="button" wire:click="runMigrationDryRun"
                                        class="btn btn-sm btn-outline btn-warning" wire:loading.attr="disabled"
                                        wire:target="runMigrationDryRun, runMigrationExecute" {{ $isRunning ? 'disabled' : '' }}
                                        title="Aperçu sans écriture">
                                        <span wire:loading.remove wire:target="runMigrationDryRun">
                                            <i class="fa-solid fa-magnifying-glass"></i> Aperçu
                                        </span>
                                        <span wire:loading wire:target="runMigrationDryRun">
                                            <span class="loading loading-spinner loading-xs"></span>
                                        </span>
                                    </button>
                                    <button type="button" wire:click="runMigrationExecute"
                                        class="btn btn-sm btn-outline btn-primary" wire:loading.attr="disabled"
                                        wire:target="runMigrationDryRun, runMigrationExecute" {{ $isRunning ? 'disabled' : '' }}
                                        title="Appliquer la migration">
                                        <span wire:loading.remove wire:target="runMigrationExecute">
                                            <i class="fa-solid fa-play"></i> Exécuter
                                        </span>
                                        <span wire:loading wire:target="runMigrationExecute">
                                            <span class="loading loading-spinner loading-xs"></span>
                                        </span>
                                    </button>
                                @else
                                    <button type="button" wire:click="runStep('{{ $stepId }}')"
                                        class="btn btn-sm btn-outline btn-primary" wire:loading.attr="disabled"
                                        wire:target="runStep('{{ $stepId }}')" {{ $isRunning ? 'disabled' : '' }}>
                                        <span wire:loading.remove wire:target="runStep('{{ $stepId }}')">
                                            <i class="fa-solid fa-play"></i>
                                        </span>
                                        <span wire:loading wire:target="runStep('{{ $stepId }}')">
                                            <span class="loading loading-spinner loading-xs"></span>
                                        </span>
                                    </button>
                                @endif

                                {{-- Toggle logs --}}
                                <button type="button" wire:click="toggleExpanded('{{ $stepId }}')"
                                    class="btn btn-sm btn-ghost" title="Voir les logs">
                                    <i class="fa-solid fa-chevron-{{ $step['expanded'] ? 'up' : 'down' }}"></i>
                                </button>
                            </div>
                        </div>

                        {{-- Error message --}}
                        @if ($step['error'])
                            <div class="alert alert-error mt-3 py-2">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <span class="text-sm">{{ $step['error'] }}</span>
                            </div>
                        @endif

                        {{-- Logs dropdown --}}
                        @if ($step['expanded'] && !empty($stepLogs[$stepId]))
                            <div class="mt-3 bg-base-200 rounded-lg p-3 max-h-60 overflow-y-auto">
                                <div class="font-mono text-xs space-y-1">
                                    @foreach ($stepLogs[$stepId] as $log)
                                        <div class="flex gap-2">
                                            <span class="text-base-content/50">{{ $log['time'] }}</span>
                                            @switch($log['level'])
                                                @case('success')
                                                    <span class="text-success">✓</span>
                                                @break

                                                @case('error')
                                                    <span class="text-error">✗</span>
                                                @break

                                                @case('warning')
                                                    <span class="text-warning">⚠</span>
                                                @break

                                                @default
                                                    <span class="text-info">ℹ</span>
                                            @endswitch
                                            <span
                                                class="{{ $log['level'] === 'error' ? 'text-error' : ($log['level'] === 'success' ? 'text-green-600' : '') }}">
                                                {{ $log['message'] }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @elseif ($step['expanded'])
                            <div class="mt-3 bg-base-200 rounded-lg p-3 text-center text-sm text-base-content/50">
                                Aucun log disponible. Exécutez l'étape pour voir les logs.
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Summary --}}
        @php
            $completedSteps = collect($steps)->whereIn('status', ['success', 'skipped'])->count();
            $skippedSteps = collect($steps)->where('status', 'skipped')->count();
            $totalSteps = count($steps);
            $hasErrors = collect($steps)->where('status', 'error')->count() > 0;
        @endphp

        @if ($completedSteps > 0 || $hasErrors)
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body p-4">
                    <h3 class="font-semibold mb-2">Résumé</h3>
                    <div class="flex items-center gap-4">
                        <div class="radial-progress text-{{ $hasErrors ? 'error' : ($completedSteps === $totalSteps ? 'success' : 'primary') }}"
                            style="--value:{{ round(($completedSteps / $totalSteps) * 100) }}; --size:4rem;">
                            {{ $completedSteps }}/{{ $totalSteps }}
                        </div>
                        <div>
                            @if ($hasErrors)
                                <p class="text-error font-medium">Synchronisation interrompue suite à une erreur</p>
                            @elseif ($completedSteps === $totalSteps)
                                <p class="text-success font-medium">Synchronisation terminée avec succès !
                                    @if ($skippedSteps > 0)
                                        <span class="text-info text-sm">({{ $skippedSteps }} étape(s) sautée(s))</span>
                                    @endif
                                </p>
                            @else
                                <p class="text-base-content/70">{{ $completedSteps }} étape(s) sur {{ $totalSteps }}
                                    terminée(s)
                                    @if ($skippedSteps > 0)
                                        <span class="text-info">— dont {{ $skippedSteps }} sautée(s)</span>
                                    @endif
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-organisms.page>
