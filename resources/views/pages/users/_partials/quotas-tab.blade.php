<?php

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\QuotaRule;
use App\Models\QuotaSetting;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

new class extends Component {
    private XfsQuotaService $quotaService;

    // Données chargées
    public bool $dataLoaded = false;
    public array $partitions = [];
    public array $defaultPolicies = [];
    public array $customRules = [];
    public array $partitionSettings = [];

    // Formulaire de politique par défaut
    public string $editingDefaultType = '';
    public string $editingDefaultPartition = '';
    public int $editingDefaultSoftMb = 0;
    public int $editingDefaultOveragePercent = 20;

    // Formulaire de règle personnalisée
    public bool $showAddRuleForm = false;
    public string $newRuleType = 'group';
    public string $newRuleTarget = '';
    public string $newRulePartition = '/home';
    public int $newRuleSoftMb = 500;
    public int $newRuleOveragePercent = 20;

    // Formulaire de période de grâce
    public string $editingGracePartition = '';
    public int $editingGraceDays = 7;

    public function boot(XfsQuotaService $quotaService)
    {
        $this->quotaService = $quotaService;
    }

    public function loadData()
    {
        if ($this->dataLoaded) {
            return;
        }

        try {
            $this->partitions = $this->quotaService->getSupportedPartitions();

            // Charger les politiques par défaut
            $defaults = $this->quotaService->listDefaultPolicies();
            $this->defaultPolicies = $defaults
                ->groupBy('partition')
                ->map(function ($items) {
                    return $items->keyBy('type')->toArray();
                })
                ->toArray();

            // Charger les règles personnalisées
            $custom = $this->quotaService->listCustomRules();
            $this->customRules = $custom->groupBy('partition')->toArray();

            // Charger les paramètres des partitions
            foreach (array_keys($this->partitions) as $partition) {
                $settings = $this->quotaService->getSettings($partition);
                $info = $this->quotaService->getPartitionInfo($partition);
                $this->partitionSettings[$partition] = [
                    'grace_period_days' => $settings->grace_period_days,
                    'default_overage_percent' => $settings->default_overage_percent,
                    'enabled' => $info['enabled'],
                    'fs_grace_days' => $info['grace_days'],
                ];
            }

            $this->dataLoaded = true;
        } catch (\Throwable $e) {
            Log::error('QuotasTab: Erreur chargement données', ['error' => $e->getMessage()]);
            session()->flash('error', 'Erreur lors du chargement des quotas: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // GESTION DES POLITIQUES PAR DÉFAUT
    // =========================================================================

    public function editDefaultPolicy(string $type, string $partition)
    {
        $this->editingDefaultType = $type;
        $this->editingDefaultPartition = $partition;

        // Charger les valeurs existantes
        $existing = QuotaRule::where('type', $type)->where('partition', $partition)->first();

        if ($existing) {
            $this->editingDefaultSoftMb = $existing->quota_soft_mb;
            $this->editingDefaultOveragePercent = $existing->getOveragePercent();
        } else {
            $this->editingDefaultSoftMb = 0;
            $this->editingDefaultOveragePercent = 20;
        }
    }

    public function saveDefaultPolicy()
    {
        $this->validate([
            'editingDefaultSoftMb' => 'required|integer|min:0',
            'editingDefaultOveragePercent' => 'required|integer|min:0|max:100',
        ]);

        try {
            $hardMb = $this->editingDefaultSoftMb === 0 ? 0 : (int) round($this->editingDefaultSoftMb * (1 + $this->editingDefaultOveragePercent / 100));

            $profile = match ($this->editingDefaultType) {
                QuotaRule::TYPE_DEFAULT_ELEVE => 'eleve',
                QuotaRule::TYPE_DEFAULT_PROF => 'prof',
                QuotaRule::TYPE_DEFAULT_ADMIN => 'admin',
                default => 'eleve',
            };

            $this->quotaService->setDefaultPolicy($profile, $this->editingDefaultPartition, $this->editingDefaultSoftMb, $hardMb, auth()->user()?->name ?? (session('login') ?? 'system'));

            session()->flash('success', 'Politique par défaut mise à jour');
            $this->cancelEditDefaultPolicy();
            $this->dataLoaded = false;
            $this->loadData();
        } catch (\Throwable $e) {
            session()->flash('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function cancelEditDefaultPolicy()
    {
        $this->editingDefaultType = '';
        $this->editingDefaultPartition = '';
    }

    // =========================================================================
    // GESTION DES RÈGLES PERSONNALISÉES
    // =========================================================================

    public function toggleAddRuleForm()
    {
        $this->showAddRuleForm = !$this->showAddRuleForm;
        if ($this->showAddRuleForm) {
            $this->resetNewRuleForm();
        }
    }

    private function resetNewRuleForm()
    {
        $this->newRuleType = 'group';
        $this->newRuleTarget = '';
        $this->newRulePartition = '/home';
        $this->newRuleSoftMb = 500;
        $this->newRuleOveragePercent = 20;
    }

    public function saveNewRule()
    {
        $this->validate([
            'newRuleType' => 'required|in:user,group',
            'newRuleTarget' => 'required|string|max:255',
            'newRulePartition' => 'required|in:/home,/var/sambaedu',
            'newRuleSoftMb' => 'required|integer|min:0',
            'newRuleOveragePercent' => 'required|integer|min:0|max:100',
        ]);

        try {
            $hardMb = $this->newRuleSoftMb === 0 ? 0 : (int) round($this->newRuleSoftMb * (1 + $this->newRuleOveragePercent / 100));

            $this->quotaService->setQuotaRule($this->newRuleType, $this->newRuleTarget, $this->newRulePartition, $this->newRuleSoftMb, $hardMb, auth()->user()?->name ?? (session('login') ?? 'system'));

            session()->flash('success', 'Règle de quota créée');
            $this->showAddRuleForm = false;
            $this->dataLoaded = false;
            $this->loadData();
        } catch (\Throwable $e) {
            session()->flash('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function deleteRule(int $ruleId)
    {
        try {
            $rule = QuotaRule::findOrFail($ruleId);
            $this->quotaService->deleteQuotaRule($rule, auth()->user()?->name ?? (session('login') ?? 'system'));

            session()->flash('success', 'Règle supprimée');
            $this->dataLoaded = false;
            $this->loadData();
        } catch (\Throwable $e) {
            session()->flash('error', 'Erreur: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // GESTION DE LA PÉRIODE DE GRÂCE
    // =========================================================================

    public function editGracePeriod(string $partition)
    {
        $this->editingGracePartition = $partition;
        $this->editingGraceDays = $this->partitionSettings[$partition]['grace_period_days'] ?? 7;
    }

    public function saveGracePeriod()
    {
        $this->validate([
            'editingGraceDays' => 'required|integer|min:1|max:365',
        ]);

        try {
            $result = $this->quotaService->setGracePeriod($this->editingGracePartition, $this->editingGraceDays, auth()->user()?->name ?? (session('login') ?? 'system'));

            if ($result['success']) {
                session()->flash('success', 'Période de grâce mise à jour');
            } else {
                session()->flash('error', 'Erreur: ' . $result['error']);
            }

            $this->cancelEditGracePeriod();
            $this->dataLoaded = false;
            $this->loadData();
        } catch (\Throwable $e) {
            session()->flash('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function cancelEditGracePeriod()
    {
        $this->editingGracePartition = '';
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function getDefaultTypeLabel(string $type): string
    {
        return match ($type) {
            QuotaRule::TYPE_DEFAULT_ELEVE => 'Élèves',
            QuotaRule::TYPE_DEFAULT_PROF => 'Professeurs',
            QuotaRule::TYPE_DEFAULT_ADMIN => 'Administrateurs',
            default => $type,
        };
    }

    public function formatQuota(int $softMb, int $hardMb): string
    {
        if ($softMb === 0 && $hardMb === 0) {
            return 'Illimité';
        }

        $soft = $softMb >= 1024 ? round($softMb / 1024, 1) . ' Go' : $softMb . ' Mo';

        if ($hardMb > $softMb) {
            $overage = round((($hardMb - $softMb) / $softMb) * 100);
            return "{$soft} (+{$overage}% grâce)";
        }

        return $soft;
    }
};
?>

<div class="space-y-6 overflow-y-auto flex-1 pb-6" wire:init="loadData">
    {{-- Messages flash --}}
    @if (session()->has('success'))
        <div class="alert alert-success">
            <i class="fa-solid fa-check-circle"></i>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="alert alert-error">
            <i class="fa-solid fa-exclamation-circle"></i>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    {{-- Loader --}}
    @if (!$dataLoaded)
        <div class="flex items-center justify-center py-12">
            <span class="loading loading-spinner loading-lg"></span>
            <span class="ml-3">Chargement des quotas...</span>
        </div>
    @else
        {{-- Boucle sur les partitions --}}
        @foreach ($partitions as $partition => $partitionLabel)
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body">
                    {{-- En-tête partition --}}
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="card-title text-lg">
                            <i class="fa-solid fa-hard-drive mr-2"></i>
                            {{ $partitionLabel }}
                            <code class="text-sm font-normal ml-2 opacity-70">{{ $partition }}</code>
                        </h3>

                        {{-- Statut et période de grâce --}}
                        <div class="flex items-center gap-4">
                            @php
                                $settings = $partitionSettings[$partition] ?? [];
                            @endphp

                            <span
                                class="badge {{ $settings['enabled'] ?? false ? 'badge-success' : 'badge-warning' }}">
                                {{ $settings['enabled'] ?? false ? 'Quotas actifs' : 'Quotas inactifs' }}
                            </span>

                            @if ($editingGracePartition === $partition)
                                <div class="flex items-center gap-2">
                                    <input type="number" wire:model="editingGraceDays"
                                        class="input input-sm input-bordered w-20" min="1" max="365">
                                    <span class="text-sm">jours</span>
                                    <button wire:click="saveGracePeriod" class="btn btn-sm btn-success">
                                        <i class="fa-solid fa-check"></i>
                                    </button>
                                    <button wire:click="cancelEditGracePeriod" class="btn btn-sm btn-ghost">
                                        <i class="fa-solid fa-times"></i>
                                    </button>
                                </div>
                            @else
                                <button wire:click="editGracePeriod('{{ $partition }}')"
                                    class="btn btn-sm btn-ghost gap-1">
                                    <i class="fa-solid fa-clock"></i>
                                    Grâce: {{ $settings['fs_grace_days'] ?? 7 }} jours
                                    <i class="fa-solid fa-pen text-xs opacity-50"></i>
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Politiques par défaut --}}
                    <div class="mb-6">
                        <h4 class="font-semibold mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-shield-halved text-primary"></i>
                            Politiques par défaut
                        </h4>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            @foreach ([QuotaRule::TYPE_DEFAULT_ELEVE, QuotaRule::TYPE_DEFAULT_PROF, QuotaRule::TYPE_DEFAULT_ADMIN] as $defaultType)
                                @php
                                    $policy = $defaultPolicies[$partition][$defaultType] ?? null;
                                    $isEditing =
                                        $editingDefaultType === $defaultType && $editingDefaultPartition === $partition;
                                @endphp

                                <div class="bg-base-200 rounded-lg p-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="font-medium">{{ $this->getDefaultTypeLabel($defaultType) }}</span>
                                        @if (!$isEditing)
                                            <button
                                                wire:click="editDefaultPolicy('{{ $defaultType }}', '{{ $partition }}')"
                                                class="btn btn-xs btn-ghost">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                        @endif
                                    </div>

                                    @if ($isEditing)
                                        <div class="space-y-2">
                                            <div class="form-control">
                                                <label class="label py-1">
                                                    <span class="label-text text-xs">Quota (Mo, 0 = illimité)</span>
                                                </label>
                                                <input type="number" wire:model="editingDefaultSoftMb"
                                                    class="input input-sm input-bordered" min="0">
                                            </div>
                                            <div class="form-control">
                                                <label class="label py-1">
                                                    <span class="label-text text-xs">Dépassement autorisé (%)</span>
                                                </label>
                                                <input type="number" wire:model="editingDefaultOveragePercent"
                                                    class="input input-sm input-bordered" min="0" max="100">
                                            </div>
                                            <div class="flex gap-2 mt-2">
                                                <button wire:click="saveDefaultPolicy"
                                                    class="btn btn-sm btn-success flex-1">
                                                    Enregistrer
                                                </button>
                                                <button wire:click="cancelEditDefaultPolicy"
                                                    class="btn btn-sm btn-ghost">
                                                    Annuler
                                                </button>
                                            </div>
                                        </div>
                                    @else
                                        <div class="text-2xl font-bold text-primary">
                                            @if ($policy)
                                                {{ $this->formatQuota($policy['quota_soft_mb'], $policy['quota_hard_mb']) }}
                                            @else
                                                <span class="text-base-content/50">Non défini</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Règles personnalisées --}}
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold flex items-center gap-2">
                                <i class="fa-solid fa-sliders text-secondary"></i>
                                Quotas personnalisés
                            </h4>
                            <button wire:click="toggleAddRuleForm" class="btn btn-sm btn-primary">
                                <i class="fa-solid fa-plus"></i>
                                Ajouter
                            </button>
                        </div>

                        {{-- Formulaire d'ajout --}}
                        @if ($showAddRuleForm && $newRulePartition === $partition)
                            <div class="bg-base-200 rounded-lg p-4 mb-4">
                                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                                    <div class="form-control">
                                        <label class="label py-1">
                                            <span class="label-text">Type</span>
                                        </label>
                                        <select wire:model="newRuleType" class="select select-sm select-bordered">
                                            <option value="group">Groupe</option>
                                            <option value="user">Utilisateur</option>
                                        </select>
                                    </div>
                                    <div class="form-control">
                                        <label class="label py-1">
                                            <span class="label-text">Nom</span>
                                        </label>
                                        <input type="text" wire:model="newRuleTarget"
                                            class="input input-sm input-bordered"
                                            placeholder="{{ $newRuleType === 'group' ? 'Classe_3A' : 'jdupont' }}">
                                    </div>
                                    <div class="form-control">
                                        <label class="label py-1">
                                            <span class="label-text">Quota (Mo)</span>
                                        </label>
                                        <input type="number" wire:model="newRuleSoftMb"
                                            class="input input-sm input-bordered" min="0">
                                    </div>
                                    <div class="form-control">
                                        <label class="label py-1">
                                            <span class="label-text">Dépassement (%)</span>
                                        </label>
                                        <input type="number" wire:model="newRuleOveragePercent"
                                            class="input input-sm input-bordered" min="0" max="100">
                                    </div>
                                    <div class="form-control justify-end">
                                        <div class="flex gap-2">
                                            <button wire:click="saveNewRule" class="btn btn-sm btn-success">
                                                <i class="fa-solid fa-check"></i>
                                            </button>
                                            <button wire:click="toggleAddRuleForm" class="btn btn-sm btn-ghost">
                                                <i class="fa-solid fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- Liste des règles --}}
                        @php
                            $rules = $customRules[$partition] ?? [];
                        @endphp

                        @if (empty($rules))
                            <div class="text-center py-6 text-base-content/50">
                                <i class="fa-solid fa-inbox text-3xl mb-2"></i>
                                <p>Aucun quota personnalisé sur cette partition</p>
                            </div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Nom</th>
                                            <th>Quota</th>
                                            <th>Dépassement</th>
                                            <th class="w-20">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($rules as $rule)
                                            <tr>
                                                <td>
                                                    @if ($rule['type'] === 'user')
                                                        <span class="badge badge-sm badge-info">
                                                            <i class="fa-solid fa-user mr-1"></i> Utilisateur
                                                        </span>
                                                    @else
                                                        <span class="badge badge-sm badge-secondary">
                                                            <i class="fa-solid fa-users mr-1"></i> Groupe
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="font-mono">{{ $rule['target'] }}</td>
                                                <td>
                                                    {{ $this->formatQuota($rule['quota_soft_mb'], $rule['quota_hard_mb']) }}
                                                </td>
                                                <td>
                                                    @if ($rule['quota_soft_mb'] > 0 && $rule['quota_hard_mb'] > $rule['quota_soft_mb'])
                                                        {{ round((($rule['quota_hard_mb'] - $rule['quota_soft_mb']) / $rule['quota_soft_mb']) * 100) }}%
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                                <td>
                                                    <button wire:click="deleteRule({{ $rule['id'] }})"
                                                        wire:confirm="Supprimer cette règle de quota ?"
                                                        class="btn btn-xs btn-ghost text-error">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    @endif
</div>
