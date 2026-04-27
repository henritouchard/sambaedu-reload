<?php

use App\Components\Traits\WithToasts;
use App\Models\QuotaRule;
use App\Models\QuotaSetting;
use App\Models\UserGroup;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Story 5.1c — Section Quota Livewire de la fiche groupe /app/users/groups/[id].
 *
 * Décalqué 1:1 sur `pages/users/[login]/_partials/quota-section.blade.php`
 * (5.1b post-review) : modale `<dialog class="modal">` + `@teleport('body')`
 * + `@entangle` + `modal-backdrop` + double guard server.admin + toasts
 * génériques (pas `$e->getMessage()` — leçon 5.1b post-review #4).
 *
 * Sources de données :
 * - `QuotaRule::where('type', TYPE_GROUP)->where('target', $groupName)` →
 *   règles spécifiques au groupe (1 row par partition au max).
 * - Si aucune règle : label "Hérité" (fallback default profil ou règle parente).
 *
 * Permissions :
 * - Affichage section (lecture seule) : tout utilisateur accédant à la fiche groupe.
 * - Bouton "Modifier" + action `applyOverride()` : double guard `server.admin`
 *   (UI `@can` + serveur `Gate::allows`).
 *
 * Side-effects : `XfsQuotaService::setQuotaRule(TYPE_GROUP, ...)` et
 * `deleteQuotaRule()` dispatchent automatiquement `RecalculateGroupQuotaJob`
 * via `dispatchRecalculateGroupJob()` interne (XfsQuotaService:365). Le SFC ne
 * doit PAS redispatcher manuellement.
 */
new class extends Component {
    use WithToasts;

    #[Locked]
    public int $groupId = 0;

    #[Locked]
    public string $groupName = '';

    public ?array $homeRule = null;

    public ?array $sambaeduRule = null;

    // ----- Override modal state -----
    public bool $showOverrideModal = false;

    public string $overridePartition = '/home';

    public string $overrideType = 'inherited';

    public int $overrideSoftMb = 500;

    public int $overrideOveragePercent = 20;

    private XfsQuotaService $quotaService;

    public function boot(XfsQuotaService $quotaService): void
    {
        $this->quotaService = $quotaService;
    }

    public function mount(int $groupId): void
    {
        $this->groupId = $groupId;

        // Aligner sur le pattern de la page parente (groups/[id]/index.blade.php
        // qui abort 404 en mount). Un payload Livewire forgé avec un groupId
        // invalide doit recevoir le même 404, pas une page silencieuse.
        $group = UserGroup::find($groupId);
        if ($group === null) {
            abort(404);
        }

        $this->groupName = (string) $group->name;
        $this->loadRules();
    }

    // =========================================================================
    // LECTURE
    // =========================================================================

    private function loadRules(): void
    {
        if ($this->groupName === '') {
            $this->homeRule = null;
            $this->sambaeduRule = null;
            return;
        }

        $home = QuotaRule::query()->where('type', QuotaRule::TYPE_GROUP)->where('target', $this->groupName)->where('partition', QuotaRule::PARTITION_HOME)->first();

        $sambaedu = QuotaRule::query()->where('type', QuotaRule::TYPE_GROUP)->where('target', $this->groupName)->where('partition', QuotaRule::PARTITION_SAMBAEDU)->first();

        // Snapshots READ-ONLY destinés uniquement à l'affichage (badges/labels)
        // et au pré-remplissage initial de la modale d'override. Les writes
        // (`applyOverride`) reconstruisent le payload exclusivement à partir des
        // form fields validés `$override*` — ces snapshots ne sont JAMAIS
        // consommés par les mutations (cf. review 5.1c #10).
        $this->homeRule = $home?->only(['id', 'partition', 'quota_soft_mb', 'quota_hard_mb', 'is_active']);
        $this->sambaeduRule = $sambaedu?->only(['id', 'partition', 'quota_soft_mb', 'quota_hard_mb', 'is_active']);
    }

    // =========================================================================
    // OVERRIDE — réservé server.admin (double guard)
    // =========================================================================

    public function openOverrideModal(string $partition): void
    {
        if (!Gate::allows('server.admin')) {
            $this->toastAccessDenied();
            return;
        }

        $this->overridePartition = in_array($partition, [QuotaRule::PARTITION_HOME, QuotaRule::PARTITION_SAMBAEDU], true) ? $partition : QuotaRule::PARTITION_HOME;

        $current = $this->overridePartition === QuotaRule::PARTITION_HOME ? $this->homeRule : $this->sambaeduRule;

        if ($current === null) {
            $this->overrideType = 'inherited';
            $this->overrideSoftMb = 500;
            $this->overrideOveragePercent = 20;
        } elseif ((int) ($current['quota_soft_mb'] ?? 0) === 0 && (int) ($current['quota_hard_mb'] ?? 0) === 0) {
            $this->overrideType = 'unlimited';
            $this->overrideSoftMb = 500;
            $this->overrideOveragePercent = 20;
        } else {
            $soft = (int) ($current['quota_soft_mb'] ?? 500);
            $hard = (int) ($current['quota_hard_mb'] ?? $soft);
            $this->overrideType = 'custom';
            $this->overrideSoftMb = max(0, $soft);
            $this->overrideOveragePercent = $soft > 0 ? max(0, min(100, (int) round((($hard - $soft) / $soft) * 100))) : 20;
        }

        $this->showOverrideModal = true;
    }

    public function closeOverrideModal(): void
    {
        $this->showOverrideModal = false;
    }

    public function applyOverride(): void
    {
        // DOUBLE GUARD serveur — paranoïa vs payload Livewire forgé.
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        $this->validate([
            'overridePartition' => 'required|in:/home,/var/sambaedu',
            'overrideType' => 'required|in:inherited,unlimited,custom',
            'overrideSoftMb' => 'nullable|integer|min:0',
            'overrideOveragePercent' => 'nullable|integer|min:0|max:100',
        ]);

        if ($this->groupName === '') {
            $this->toastError('Groupe introuvable.');
            return;
        }

        $partition = $this->overridePartition;
        $performedBy = auth()->user()?->login ?? 'system';

        try {
            if ($this->overrideType === 'inherited') {
                $rule = QuotaRule::query()->where('type', QuotaRule::TYPE_GROUP)->where('target', $this->groupName)->where('partition', $partition)->first();

                if ($rule) {
                    // deleteQuotaRule dispatch automatiquement le recalcul
                    // groupe (XfsQuotaService:365) — ne PAS redispatcher.
                    $this->quotaService->deleteQuotaRule($rule, $performedBy);
                }

                $this->toastSuccess("Quota {$partition} : retour à l'héritage.");
            } elseif ($this->overrideType === 'unlimited') {
                $this->quotaService->setQuotaRule(QuotaRule::TYPE_GROUP, $this->groupName, $partition, 0, 0, $performedBy, applyImmediately: true);
                $this->toastSuccess("Quota {$partition} : illimité.");
            } else {
                $softMb = max(0, (int) $this->overrideSoftMb);
                $overage = max(0, min(100, (int) $this->overrideOveragePercent));

                // Le type "Personnalisé" avec soft=0 est ambigu : la convention
                // projet `0 = illimité` ferait croire à l'utilisateur qu'il a
                // illimité, alors qu'il a sélectionné "Personnalisé". Forcer
                // l'utilisation explicite du type "Illimité" (cf. review 5.1c #6).
                if ($softMb === 0) {
                    $this->addError('overrideSoftMb', 'Pour un quota illimité, sélectionnez le type "Illimité".');
                    return;
                }

                // Cohérent avec QuotaController::updateGroupQuota:84.
                if ($partition === QuotaRule::PARTITION_HOME && $softMb < 10) {
                    $this->addError('overrideSoftMb', "Le quota sur /home doit être d'au moins 10 Mo.");
                    return;
                }

                $hardMb = (int) round($softMb * (1 + $overage / 100));

                // setQuotaRule dispatch automatiquement le recalcul groupe en
                // interne (XfsQuotaService:339 → dispatchApplyJob, et la
                // logique de recalcul groupe est portée par le job consumer).
                $this->quotaService->setQuotaRule(QuotaRule::TYPE_GROUP, $this->groupName, $partition, $softMb, $hardMb, $performedBy, applyImmediately: true);

                $label = $softMb >= 1024 ? round($softMb / 1024, 1) . ' Go' : $softMb . ' Mo';
                $this->toastSuccess("Quota {$partition} : {$label} (+{$overage}% grâce).");
            }

            // Refresh des règles affichées.
            $this->loadRules();
            $this->showOverrideModal = false;
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec override groupe', [
                'group' => $this->groupName,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Erreur lors de la mise à jour du quota groupe. Consultez les logs.');
        }
    }

    // =========================================================================
    // HELPERS RENDU
    // =========================================================================

    public function formatQuotaMb(int $mb): string
    {
        if ($mb === 0) {
            return 'Illimité';
        }
        return $mb >= 1024 ? round($mb / 1024, 1) . ' Go' : $mb . ' Mo';
    }

    /**
     * Retourne un descripteur pour la partition donnée :
     *   - ['kind' => 'inherited']                  → aucune règle groupe
     *   - ['kind' => 'unlimited']                  → soft=0 && hard=0
     *   - ['kind' => 'custom', 'soft' => …, 'overage' => …]
     */
    public function describeRule(?array $rule): array
    {
        if ($rule === null) {
            return ['kind' => 'inherited'];
        }

        $soft = (int) ($rule['quota_soft_mb'] ?? 0);
        $hard = (int) ($rule['quota_hard_mb'] ?? 0);

        if ($soft === 0 && $hard === 0) {
            return ['kind' => 'unlimited'];
        }

        $overage = $soft > 0 ? max(0, (int) round((($hard - $soft) / $soft) * 100)) : 0;

        return [
            'kind' => 'custom',
            'soft' => $soft,
            'overage' => $overage,
        ];
    }
};
?>

<div class="card bg-base-100 shadow-sm border border-base-300">
    <div class="card-body">
        <div class="flex items-center justify-between mb-4">
            <h3 class="card-title text-lg">
                <i class="fa-solid fa-hard-drive mr-2"></i>
                Quota du groupe
            </h3>
        </div>

        @if ($groupName === '')
            <div class="alert alert-warning text-sm">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Groupe introuvable.
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @php
                    $partitions = [
                        [
                            'key' => 'home',
                            'partition' => '/home',
                            'label' => 'Espace personnel (K:)',
                            'rule' => $homeRule,
                        ],
                        [
                            'key' => 'sambaedu',
                            'partition' => '/var/sambaedu',
                            'label' => 'Partages (Classes/Docs)',
                            'rule' => $sambaeduRule,
                        ],
                    ];
                @endphp

                @foreach ($partitions as $p)
                    @php $desc = $this->describeRule($p['rule']); @endphp
                    {{-- flex flex-col + flex-1 sur le carré état pour que les
                         deux colonnes s'alignent à la hauteur du plus grand
                         (sinon "Hérité (défaut)" + paragraphe explicatif est
                         visiblement plus haut que "Illimité" seul). --}}
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ $p['label'] }}</span>
                            <code class="text-xs opacity-70">{{ $p['partition'] }}</code>
                        </div>

                        <div
                            class="bg-base-200 rounded-lg py-3 px-4 text-center flex-1 flex flex-col justify-center items-center">
                            @if ($desc['kind'] === 'inherited')
                                <span class="badge badge-ghost">Hérité (défaut)</span>
                                <p class="text-xs opacity-70 mt-1">
                                    Aucune règle groupe — les utilisateurs héritent
                                    du quota par défaut de leur profil.
                                </p>
                            @elseif ($desc['kind'] === 'unlimited')
                                <span class="badge badge-success"> <i
                                        class="fa-solid fa-infinity mr-2"></i>Illimité</span>
                            @else
                                <span class="badge badge-info">
                                    <i class="fa-solid fa-scale-balanced mr-2"></i>
                                    {{ $this->formatQuotaMb($desc['soft']) }}
                                    (+{{ $desc['overage'] }}%)
                                </span>
                            @endif
                        </div>

                        @can('server.admin')
                            <button type="button" class="btn btn-sm btn-outline w-full"
                                wire:click="openOverrideModal('{{ $p['partition'] }}')">
                                <i class="fa-solid fa-sliders"></i>
                                Modifier
                            </button>
                        @endcan
                    </div>
                @endforeach
            </div>

            {{-- Modale d'override groupe — visible uniquement server.admin. --}}
            @can('server.admin')
                <x-organisms.quota-override-modal title="Modifier le quota du groupe"
                    inheritedLabel="Hériter (utiliser le défaut profil)" :overridePartition="$overridePartition" :overrideType="$overrideType" />
            @endcan
        @endif
    </div>
</div>
