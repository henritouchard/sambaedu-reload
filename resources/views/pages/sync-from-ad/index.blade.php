<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Services\UserSyncService;
use App\Services\UserGroupService;
use App\Jobs\SyncAllFromAdJob;
use App\Services\ShortcutsService;
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
                'status' => 'pending', // pending, running, success, error
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
        ];

        $this->stepLogs = [
            'users_establishment' => [],
            'user_groups' => [],
            'workstations' => [],
            'physical_groups' => [],
            'logical_groups' => [],
            'app_profiles' => [],
            'shortcuts' => [],
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
            }

            $this->steps[$stepId]['status'] = 'success';
            $this->addLog($stepId, 'success', 'Import terminé avec succès');
            $this->toastSuccess('Import terminé avec succès');
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
            $this->runStep($stepId);

            // Arrêter si une étape échoue
            if ($this->steps[$stepId]['status'] === 'error') {
                $this->toastError('Synchronisation interrompue suite à une erreur');
                break;
            }
        }
    }

    private function runUsersEstablishmentSync(): void
    {
        $this->ensureEstablishmentSelectedForScopedUsersImport();

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

    private function ensureEstablishmentSelectedForScopedUsersImport(): void
    {
        $establishmentCode = SEConfig::getCurrentEstablishmentCode();

        if ($establishmentCode === null || $establishmentCode === '') {
            throw new \RuntimeException('Aucun contexte établissement détecté. Sélectionnez un établissement ou "Domaine entier" puis relancez l\'étape.');
        }
    }

    private function runPhysicalGroupsSync(): void
    {
        // Importer les groupes physiques (OU dans OU=Computers)
        $workstationGroupService = app(\App\Services\Parc\WorkstationGroupService::class);

        $stats = $workstationGroupService->importFromAd(function (string $level, string $message) {
            $this->addLog('physical_groups', $level, $message);
        });

        $this->steps['physical_groups']['stats'] = $stats;
    }

    private function runLogicalGroupsSync(): void
    {
        // Importer les groupes logiques (CN dans OU=Parcs)
        $workstationGroupService = app(\App\Services\Parc\WorkstationGroupService::class);

        $stats = $workstationGroupService->importLogicalGroupsFromAd(function (string $level, string $message) {
            $this->addLog('logical_groups', $level, $message);
        });

        $this->steps['logical_groups']['stats'] = $stats;
    }

    private function runWorkstationsSync(): void
    {
        // Utiliser le service pour l'import
        $workstationService = app(\App\Services\WorkstationService::class);

        $stats = $workstationService->importFromAd(function (string $level, string $message) {
            $this->addLog('workstations', $level, $message);
        });

        $this->steps['workstations']['stats'] = $stats;
    }

    private function runAppProfilesSync(): void
    {
        // Utiliser le service pour l'import
        $appProfileService = app(\App\Services\AppProfile\AppProfileService::class);

        $stats = $appProfileService->importFromAd(function (string $level, string $message) {
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
                                        @if (isset($step['stats']['errors']) && count($step['stats']['errors']) > 0)
                                            <span class="badge badge-error badge-sm">{{ count($step['stats']['errors']) }}
                                                err</span>
                                        @endif
                                        @if (isset($step['stats']['admin_granted']) && $step['stats']['admin_granted'])
                                            <span class="badge badge-accent badge-sm">admin</span>
                                        @endif
                                    </div>
                                @endif

                                {{-- Run button --}}
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
            $completedSteps = collect($steps)->where('status', 'success')->count();
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
                                <p class="text-success font-medium">Synchronisation terminée avec succès !</p>
                            @else
                                <p class="text-base-content/70">{{ $completedSteps }} étape(s) sur {{ $totalSteps }}
                                    terminée(s)</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-organisms.page>
