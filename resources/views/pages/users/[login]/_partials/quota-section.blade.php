<?php

use App\Components\Traits\WithToasts;
use App\Models\QuotaRule;
use App\Models\User as SqlUserModel;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Story 5.1b — Section Quota Livewire de la fiche user /users/[login].
 *
 * Remplace l'ancien partial Blade pur `quota-info.blade.php` (supprimé,
 * décision D9). Ajoute la réactivité nécessaire au bouton Refresh manuel
 * et au formulaire d'override user (modale).
 *
 * Sources de données :
 * - `users.quota_snapshot` (colonne JSON ajoutée par la migration 5.1b)
 *   → affichage par défaut, zéro shellout.
 * - `XfsQuotaService::getEffectiveQuota()` → breakdown de l'héritage
 *   (user / group / default / none) conservé.
 * - `XfsQuotaService::getDiskUsage()` → lecture live XFS UNIQUEMENT sur
 *   action utilisateur (bouton Actualiser ou post-override).
 *
 * Permissions (post code review 5.1b) :
 * - Affichage de la section : tout utilisateur accédant à la fiche.
 * - Bouton Actualiser + action `refreshSnapshot()` : réservé
 *   `server.admin` (le refresh déclenche des shellouts `sudo xfs_quota`
 *   coûteux, limité aux admins). Double guard Gate serveur + @can UI.
 * - Bouton "Modifier le quota" + action `applyOverride()` :
 *   `server.admin` — double guard UI (@can) + serveur (Gate::allows).
 */
new class extends Component {
    use WithToasts;

    #[Locked]
    public string $login = '';

    /** Snapshot JSON courant (structure cf. QuotaSnapshotCommand). */
    public ?array $snapshot = null;

    /** Breakdown héritage /home (user / group / default / none). */
    public array $effectiveHome = [];

    /** Breakdown héritage /var/sambaedu. */
    public array $effectiveSambaedu = [];

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

    public function mount(string $login): void
    {
        $this->login = $login;

        $user = $this->loadUserModel();

        $this->loadSnapshot($user);
        $this->loadEffectiveQuotas($user);
    }

    // =========================================================================
    // LECTURE
    // =========================================================================

    /**
     * Charge le User Eloquent + sa relation userGroups en UNE seule query
     * (évite le N+1 structurel à mount()). Retourne null si le user n'existe
     * pas en BDD (cas possible entre mount() et un click Refresh).
     */
    private function loadUserModel(): ?SqlUserModel
    {
        return SqlUserModel::query()
            ->where('login', $this->login)
            ->with('userGroups')
            ->first();
    }

    /**
     * Recharge `$this->snapshot` depuis `users.quota_snapshot`.
     */
    private function loadSnapshot(?SqlUserModel $user): void
    {
        $this->snapshot = $user ? $user->quota_snapshot : null;
    }

    /**
     * Calcule l'héritage effectif pour les 2 partitions. Ne touche pas XFS.
     */
    private function loadEffectiveQuotas(?SqlUserModel $user): void
    {
        $userGroups = $this->resolveUserGroups($user);
        $userProfile = $this->resolveUserProfile($userGroups);

        $this->effectiveHome = $this->quotaService->getEffectiveQuota(
            $this->login,
            QuotaRule::PARTITION_HOME,
            $userGroups,
            $userProfile,
        );

        $this->effectiveSambaedu = $this->quotaService->getEffectiveQuota(
            $this->login,
            QuotaRule::PARTITION_SAMBAEDU,
            $userGroups,
            $userProfile,
        );
    }

    /**
     * @return list<string>
     */
    private function resolveUserGroups(?SqlUserModel $user): array
    {
        if (!$user) {
            return [];
        }

        // `userGroups` est eager-loaded dans loadUserModel() — pas de query ici.
        return $user->userGroups
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $userGroups
     */
    private function resolveUserProfile(array $userGroups): string
    {
        foreach ($userGroups as $group) {
            $lower = mb_strtolower($group);
            if (str_contains($lower, 'admin') || str_contains($lower, 'domain admins')) {
                return 'admin';
            }
            if (str_contains($lower, 'prof') || str_contains($lower, 'enseignant')) {
                return 'prof';
            }
        }

        return 'eleve';
    }

    // =========================================================================
    // REFRESH MANUEL (AC 4, AC 7) — réservé server.admin post-review 5.1b
    // =========================================================================

    /**
     * Lit les quotas XFS en live pour ce user et persiste le snapshot.
     * Réservé `server.admin` — le refresh déclenche des shellouts `sudo
     * xfs_quota` potentiellement coûteux.
     */
    public function refreshSnapshot(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        // Garde-fou DoS : 5 refresh max / 60s par utilisateur.
        if (!RateLimiter::attempt(
            key: 'quota-refresh:' . (auth()->id() ?? 'anon'),
            maxAttempts: 5,
            callback: fn () => true,
            decaySeconds: 60,
        )) {
            $this->toastError('Trop de rafraîchissements. Réessayez dans un instant.');
            return;
        }

        $this->performRefresh();
    }

    /**
     * Exécute le refresh réel sans vérifier Gate ni rate-limit. Appelée :
     * - par `refreshSnapshot()` public (après vérifications)
     * - par `applyOverride()` qui a déjà validé `server.admin` en amont
     *   et veut refléter l'état post-apply (refresh interne silencieux).
     */
    private function performRefresh(): void
    {
        try {
            // Vérifie l'existence du user AVANT le shellout (évite 2 exec
            // `sudo xfs_quota` inutiles si le user a disparu entre mount
            // et le click).
            $user = $this->loadUserModel();
            if (!$user) {
                $this->toastError('Utilisateur introuvable.');
                return;
            }

            $usage = $this->quotaService->getDiskUsage($this->login);

            $snapshot = is_array($user->quota_snapshot) ? $user->quota_snapshot : [];

            foreach ([
                'home' => QuotaRule::PARTITION_HOME,
                'sambaedu' => QuotaRule::PARTITION_SAMBAEDU,
            ] as $key => $partition) {
                $partitionUsage = $usage[$key] ?? null;
                if (!is_array($partitionUsage) || ($partitionUsage['error'] ?? null) !== null) {
                    // On ne touche pas à la clé existante en cas d'erreur
                    // partition-spécifique (fail-soft AC 12).
                    continue;
                }

                $snapshot[$key] = $this->buildPartitionSnapshotFromUsage($partitionUsage);
            }

            $snapshot['captured_at'] = Carbon::now()->toIso8601String();

            $user->forceFill(['quota_snapshot' => $snapshot])->save();

            Log::info('QuotaService: refresh manuel', [
                'login' => $this->login,
                'performed_by' => auth()->user()?->login ?? 'system',
            ]);

            $this->loadSnapshot($user);
            $this->toastSuccess('Quota actualisé');
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec refresh snapshot', [
                'login' => $this->login,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Impossible de rafraîchir le quota. Consultez les logs.');
        }
    }

    /**
     * Construit le sous-document snapshot à partir d'une lecture
     * `XfsQuotaService::getDiskUsage()` (structure mb).
     *
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function buildPartitionSnapshotFromUsage(array $usage): array
    {
        $usedMb = (int) ($usage['used_mb'] ?? 0);
        $softMb = (int) ($usage['quota_soft_mb'] ?? 0);
        $hardMb = (int) ($usage['quota_hard_mb'] ?? 0);

        $usedKb = $usedMb * 1024;
        $softKb = $softMb * 1024;
        $hardKb = $hardMb * 1024;

        // Recalcul depuis kb bruts (cohérent avec QuotaSnapshotCommand) —
        // évite la perte de précision du double arrondi used_mb/soft_mb.
        $percent = $softKb > 0
            ? min(100, (int) round($usedKb / $softKb * 100))
            : 0;

        return [
            'used_kb' => $usedKb,
            'soft_kb' => $softKb,
            'hard_kb' => $hardKb,
            'used_mb' => $usedMb,
            'soft_mb' => $softMb,
            'hard_mb' => $hardMb,
            'percent' => $percent,
            'is_over_soft' => (bool) ($usage['is_over_soft'] ?? false),
            'is_over_hard' => (bool) ($usage['is_over_hard'] ?? false),
            'grace_days' => $usage['grace_days'] ?? null,
        ];
    }

    // =========================================================================
    // OVERRIDE QUOTA (AC 5, AC 6) — réservé server.admin
    // =========================================================================

    public function openOverrideModal(string $partition): void
    {
        if (!Gate::allows('server.admin')) {
            $this->toastAccessDenied();
            return;
        }

        $this->overridePartition = in_array(
            $partition,
            [QuotaRule::PARTITION_HOME, QuotaRule::PARTITION_SAMBAEDU],
            true,
        ) ? $partition : QuotaRule::PARTITION_HOME;

        // Pré-charge les valeurs courantes pour l'édition.
        $current = $this->overridePartition === QuotaRule::PARTITION_HOME
            ? $this->effectiveHome
            : $this->effectiveSambaedu;

        $this->overrideType = $current['source'] === 'user' ? 'custom' : 'inherited';
        $this->overrideSoftMb = max(10, (int) ($current['quota_soft_mb'] ?? 500));
        $this->overrideOveragePercent = 20;

        $this->showOverrideModal = true;
    }

    public function closeOverrideModal(): void
    {
        $this->showOverrideModal = false;
    }

    public function applyOverride(): void
    {
        // DOUBLE GUARD serveur — ne JAMAIS présumer que l'UI cache suffit.
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        $this->validate([
            'overridePartition' => 'required|in:/home,/var/sambaedu',
            'overrideType' => 'required|in:inherited,unlimited,custom',
            'overrideSoftMb' => 'nullable|integer|min:0',
            'overrideOveragePercent' => 'nullable|integer|min:0|max:100',
        ]);

        $partition = $this->overridePartition;
        $performedBy = auth()->user()?->login ?? 'system';

        try {
            if ($this->overrideType === 'inherited') {
                $rule = QuotaRule::query()
                    ->where('type', QuotaRule::TYPE_USER)
                    ->where('target', $this->login)
                    ->where('partition', $partition)
                    ->first();

                if ($rule) {
                    $this->quotaService->deleteQuotaRule($rule, $performedBy);
                }

                $this->toastSuccess("Quota {$partition} : retour à l'héritage.");
            } elseif ($this->overrideType === 'unlimited') {
                $this->quotaService->setQuotaRule(
                    QuotaRule::TYPE_USER,
                    $this->login,
                    $partition,
                    0,
                    0,
                    $performedBy,
                    applyImmediately: true,
                );
                $this->toastSuccess("Quota {$partition} : illimité.");
            } else {
                $softMb = max(0, (int) $this->overrideSoftMb);
                $overage = max(0, min(100, (int) $this->overrideOveragePercent));

                // Cohérent avec QuotaController::updateUserQuota l. 176.
                if ($partition === QuotaRule::PARTITION_HOME && $softMb > 0 && $softMb < 10) {
                    $this->addError('overrideSoftMb', 'Le quota sur /home doit être d\'au moins 10 Mo.');
                    return;
                }

                $hardMb = $softMb === 0 ? 0 : (int) round($softMb * (1 + $overage / 100));

                $this->quotaService->setQuotaRule(
                    QuotaRule::TYPE_USER,
                    $this->login,
                    $partition,
                    $softMb,
                    $hardMb,
                    $performedBy,
                    applyImmediately: true,
                );

                $label = $softMb >= 1024 ? round($softMb / 1024, 1) . ' Go' : $softMb . ' Mo';
                $this->toastSuccess("Quota {$partition} : {$label} (+{$overage}% grâce).");
            }

            // Refresh du breakdown héritage (la règle vient de changer).
            $user = $this->loadUserModel();
            $this->loadEffectiveQuotas($user);

            $this->showOverrideModal = false;

            // Refresh du snapshot post-apply, isolé dans son propre try/catch
            // pour ne pas transformer un succès override en toast d'erreur
            // si `getDiskUsage` échoue (xfs_quota indisponible par ex).
            // Utilise `performRefresh()` — pas de re-check Gate/rate-limit,
            // on est déjà dans un contexte server.admin validé.
            try {
                $this->performRefresh();
            } catch (\Throwable $e) {
                Log::warning('QuotaService: refresh post-override échoué', [
                    'login' => $this->login,
                    'error' => $e->getMessage(),
                ]);
                // Pas de toast — le success override a déjà été affiché.
            }
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec override user', [
                'login' => $this->login,
                'partition' => $partition,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Erreur lors de la mise à jour du quota. Consultez les logs.');
        }
    }

    // =========================================================================
    // HELPERS DE RENDU
    // =========================================================================

    public function formatQuotaMb(int $mb): string
    {
        if ($mb === 0) {
            return 'Illimité';
        }
        return $mb >= 1024 ? round($mb / 1024, 1) . ' Go' : $mb . ' Mo';
    }

    public function capturedAtFormatted(): ?string
    {
        $raw = $this->snapshot['captured_at'] ?? null;
        if (!$raw) {
            return null;
        }

        try {
            return Carbon::parse($raw)->translatedFormat('j M Y H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    public function getProgressClass(int $percent, bool $isOverSoft = false): string
    {
        if ($percent >= 90 || $isOverSoft) {
            return 'progress-error';
        }
        if ($percent >= 70) {
            return 'progress-warning';
        }
        return 'progress-success';
    }
};
?>

<div class="card bg-base-100 shadow-sm border border-base-300">
    <div class="card-body">
        <div class="flex items-center justify-between mb-4">
            <h3 class="card-title text-lg">
                <i class="fa-solid fa-hard-drive mr-2"></i>
                Quotas disque
            </h3>
            <div class="flex items-center gap-2">
                @if ($this->capturedAtFormatted())
                    <span class="text-xs opacity-60">
                        Snapshot du {{ $this->capturedAtFormatted() }}
                    </span>
                @else
                    <span class="text-xs opacity-60">Aucun snapshot</span>
                @endif

                @can('server.admin')
                    <button type="button" class="btn btn-sm btn-ghost gap-1"
                        wire:click="refreshSnapshot"
                        wire:loading.attr="disabled"
                        wire:target="refreshSnapshot">
                        <span wire:loading wire:target="refreshSnapshot" class="loading loading-spinner loading-xs"></span>
                        <i wire:loading.remove wire:target="refreshSnapshot" class="fa-solid fa-sync"></i>
                        Actualiser
                    </button>
                @endcan
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @php
                $partitions = [
                    [
                        'key' => 'home',
                        'partition' => '/home',
                        'label' => 'Espace personnel (K:)',
                        'effective' => $effectiveHome,
                    ],
                    [
                        'key' => 'sambaedu',
                        'partition' => '/var/sambaedu',
                        'label' => 'Partages (Classes/Docs)',
                        'effective' => $effectiveSambaedu,
                    ],
                ];
            @endphp

            @foreach ($partitions as $p)
                @php
                    $snap = $snapshot[$p['key']] ?? null;
                    $effective = $p['effective'];
                @endphp

                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ $p['label'] }}</span>
                        <code class="text-xs opacity-70">{{ $p['partition'] }}</code>
                    </div>

                    @if ($effective['is_unlimited'])
                        <div class="text-center py-4 bg-base-200 rounded-lg">
                            <span class="text-2xl font-bold text-success">Illimité</span>
                            @if ($snap)
                                <p class="text-sm opacity-70 mt-1">
                                    Utilisé : {{ $this->formatQuotaMb((int) ($snap['used_mb'] ?? 0)) }}
                                </p>
                            @endif
                        </div>
                    @elseif ($snap === null)
                        <div class="text-center py-4 bg-base-200 rounded-lg">
                            <span class="text-sm opacity-60">Aucun snapshot disponible.</span>
                            <p class="text-xs opacity-60 mt-1">Lancez un Refresh pour calculer en live.</p>
                        </div>
                    @else
                        @php
                            $usedMb = (int) ($snap['used_mb'] ?? 0);
                            $softMb = (int) ($effective['quota_soft_mb'] ?? 0);
                            $percent = (int) ($snap['percent'] ?? 0);
                            $isOverSoft = (bool) ($snap['is_over_soft'] ?? false);
                        @endphp
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span>
                                    {{ $this->formatQuotaMb($usedMb) }}
                                    /
                                    {{ $this->formatQuotaMb($softMb) }}
                                </span>
                                <span class="{{ $percent >= 70 ? 'text-warning' : '' }} {{ $percent >= 90 || $isOverSoft ? 'text-error' : '' }}">
                                    {{ $percent }}%
                                </span>
                            </div>
                            <progress class="progress {{ $this->getProgressClass($percent, $isOverSoft) }} w-full"
                                value="{{ $percent }}" max="100"></progress>

                            @if ($isOverSoft)
                                <div class="alert alert-warning py-2 text-sm">
                                    <i class="fa-solid fa-exclamation-triangle"></i>
                                    <span>
                                        Quota dépassé !
                                        @if (($snap['grace_days'] ?? null))
                                            Grâce : {{ $snap['grace_days'] }} jour(s)
                                        @else
                                            Écriture bloquée
                                        @endif
                                    </span>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="text-xs opacity-70">
                        Source :
                        @if (($effective['source'] ?? null) === 'user')
                            <span class="badge badge-xs badge-info">Quota personnel</span>
                        @elseif (($effective['source'] ?? null) === 'group')
                            <span class="badge badge-xs badge-secondary">{{ $effective['source_name'] ?? 'groupe' }}</span>
                        @elseif (($effective['source'] ?? null) === 'default')
                            <span class="badge badge-xs">{{ $effective['source_name'] ?? 'défaut' }}</span>
                        @else
                            <span class="badge badge-xs badge-ghost">Aucune règle</span>
                        @endif
                    </div>

                    @can('server.admin')
                        <button type="button"
                            class="btn btn-sm btn-outline w-full"
                            wire:click="openOverrideModal('{{ $p['partition'] }}')">
                            <i class="fa-solid fa-sliders"></i>
                            Modifier le quota
                        </button>
                    @endcan
                </div>
            @endforeach
        </div>

        {{-- Modale d'override user — visible uniquement server.admin --}}
        @can('server.admin')
            @teleport('body')
                <dialog class="modal"
                    x-data="{ open: @entangle('showOverrideModal') }"
                    :class="{ 'modal-open': open }"
                    x-cloak>
                    <div class="modal-box max-w-2xl">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold">Modifier le quota</h3>
                            <button type="button" class="btn btn-sm btn-circle btn-ghost" wire:click="closeOverrideModal">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <div class="space-y-4">
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">Partition</span>
                                </label>
                                <select wire:model.live="overridePartition" class="select select-bordered">
                                    <option value="/home">/home — Espace personnel (K:)</option>
                                    <option value="/var/sambaedu">/var/sambaedu — Partages</option>
                                </select>
                            </div>

                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">Type</span>
                                </label>
                                <div class="flex flex-col gap-2">
                                    <label class="flex gap-2 cursor-pointer">
                                        <input type="radio" wire:model.live="overrideType" value="inherited" class="radio radio-sm" />
                                        <span>Hériter (règle groupe ou défaut)</span>
                                    </label>
                                    <label class="flex gap-2 cursor-pointer">
                                        <input type="radio" wire:model.live="overrideType" value="unlimited" class="radio radio-sm" />
                                        <span>Illimité</span>
                                    </label>
                                    <label class="flex gap-2 cursor-pointer">
                                        <input type="radio" wire:model.live="overrideType" value="custom" class="radio radio-sm" />
                                        <span>Personnalisé</span>
                                    </label>
                                </div>
                            </div>

                            @if ($overrideType === 'custom')
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="form-control">
                                        <label class="label py-1">
                                            <span class="label-text text-xs">Quota soft (Mo)</span>
                                        </label>
                                        <input type="number" wire:model="overrideSoftMb"
                                            class="input input-bordered input-sm" min="0" />
                                        @error('overrideSoftMb')
                                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="form-control">
                                        <label class="label py-1">
                                            <span class="label-text text-xs">Dépassement (%)</span>
                                        </label>
                                        <input type="number" wire:model="overrideOveragePercent"
                                            class="input input-bordered input-sm" min="0" max="100" />
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="modal-action">
                            <button type="button" class="btn btn-ghost" wire:click="closeOverrideModal">Annuler</button>
                            <button type="button" class="btn btn-primary"
                                wire:click="applyOverride"
                                wire:loading.attr="disabled"
                                wire:target="applyOverride">
                                <span wire:loading wire:target="applyOverride" class="loading loading-spinner loading-xs"></span>
                                Appliquer
                            </button>
                        </div>
                    </div>
                    <form method="dialog" class="modal-backdrop">
                        <button type="button" wire:click="closeOverrideModal">close</button>
                    </form>
                </dialog>
            @endteleport
        @endcan
    </div>
</div>
