<?php

use App\Components\Traits\WithToasts;
use App\Models\QuotaRule;
use App\Models\QuotaSetting;
use App\Models\SystemSetting;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Story 5.1c (initial) + 5.1d (extension) — Onglet "Quotas & FS" de
 * `/admin/settings`.
 *
 * 3 sections (cards) :
 *   1. Defaults par profil   — élève / prof / admin / itinérant × /home + /var/sambaedu
 *      (soft Mo, overage %, hard Mo calculé read-only). Persisté via
 *      SystemSetting::set('quota.defaults', [...]). La règle itinérante est
 *      consommée par `XfsQuotaService::getEffectiveQuota` depuis 5.1d.
 *   2. Période de grâce      — 1 input par partition. Persiste dans
 *      `quota_settings` (existant) ET tente d'appliquer
 *      `XfsQuotaService::setGracePeriod` synchrone post-save (D4=A).
 *   3. Corbeille             — TTL jours + toggle purge auto, persistés via
 *      SystemSetting::set('quota.trash', [...]). Bouton "Purger maintenant"
 *      ajouté en 5.1d → `Artisan::call('trash:purge')` synchrone (D3=A).
 *
 * Sécurité : double guard sur chaque méthode publique (Gate `server.admin` +
 * abort(403) en première ligne).
 *
 * Toasts : trait `WithToasts` — toastSuccess/toastError génériques (jamais
 * `$e->getMessage()` exposé — leçon 5.1b post-review #4).
 */
new class extends Component {
    use WithToasts;

    /**
     * @var array<string, array<string, array{soft_mb:int, overage_percent:int}>>
     *   Structure : [profile][partition_key] = ['soft_mb' => int, 'overage_percent' => int]
     *   profile ∈ {eleve, prof, admin, itinerant}
     *   partition_key ∈ {home, sambaedu}
     */
    public array $defaults = [];

    /**
     * @var array{home:int, sambaedu:int}
     */
    public array $grace = ['home' => 7, 'sambaedu' => 7];

    /**
     * @var array{ttl_days:int, purge_auto:bool}
     */
    public array $trash = ['ttl_days' => 30, 'purge_auto' => false];

    /** Snapshots pour détecter les changements (active/désactive le bouton Enregistrer). */
    public array $originalDefaults = [];
    public array $originalGrace = [];
    public array $originalTrash = [];

    private XfsQuotaService $quotaService;

    public function boot(XfsQuotaService $quotaService): void
    {
        $this->quotaService = $quotaService;
    }

    public function mount(): void
    {
        // Double guard mount — paranoïa vs payload Livewire forgé.
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        $this->loadDefaults();
        $this->loadGrace();
        $this->loadTrash();

        $this->snapshotOriginals();
    }

    private function snapshotOriginals(): void
    {
        $this->originalDefaults = $this->defaults;
        $this->originalGrace = $this->grace;
        $this->originalTrash = $this->trash;
    }

    public function getDefaultsDirtyProperty(): bool
    {
        // Comparaison normalisée (cast int) pour ignorer "10" vs 10 venant de l'input texte.
        return $this->normalizeDefaults($this->defaults) != $this->normalizeDefaults($this->originalDefaults);
    }

    public function getGraceDirtyProperty(): bool
    {
        return [(int) ($this->grace['home'] ?? 0), (int) ($this->grace['sambaedu'] ?? 0)]
            != [(int) ($this->originalGrace['home'] ?? 0), (int) ($this->originalGrace['sambaedu'] ?? 0)];
    }

    public function getTrashDirtyProperty(): bool
    {
        return [(int) ($this->trash['ttl_days'] ?? 0), (bool) ($this->trash['purge_auto'] ?? false)]
            != [(int) ($this->originalTrash['ttl_days'] ?? 0), (bool) ($this->originalTrash['purge_auto'] ?? false)];
    }

    private function normalizeDefaults(array $src): array
    {
        $out = [];
        foreach (['eleve', 'prof', 'admin', 'itinerant'] as $profile) {
            foreach (['home', 'sambaedu'] as $partKey) {
                $out[$profile][$partKey] = [
                    'soft_mb' => (int) ($src[$profile][$partKey]['soft_mb'] ?? 0),
                    'overage_percent' => (int) ($src[$profile][$partKey]['overage_percent'] ?? 20),
                ];
            }
        }
        return $out;
    }

    // =========================================================================
    // CHARGEMENT
    // =========================================================================

    private function loadDefaults(): void
    {
        $this->defaults = SystemSetting::get('quota.defaults', $this->defaultsScaffold());
    }

    /**
     * Structure par défaut si aucun setting persisté.
     *
     * @return array<string, array<string, array{soft_mb:int, overage_percent:int}>>
     */
    private function defaultsScaffold(): array
    {
        $emptyPair = [
            'home' => ['soft_mb' => 0, 'overage_percent' => 20],
            'sambaedu' => ['soft_mb' => 0, 'overage_percent' => 20],
        ];

        return [
            'eleve' => $emptyPair,
            'prof' => $emptyPair,
            'admin' => $emptyPair,
            'itinerant' => $emptyPair,
        ];
    }

    private function loadGrace(): void
    {
        $home = QuotaSetting::forPartition(QuotaRule::PARTITION_HOME);
        $sambaedu = QuotaSetting::forPartition(QuotaRule::PARTITION_SAMBAEDU);

        $this->grace = [
            'home' => (int) $home->grace_period_days,
            'sambaedu' => (int) $sambaedu->grace_period_days,
        ];
    }

    private function loadTrash(): void
    {
        $stored = SystemSetting::get('quota.trash', null);

        if (is_array($stored)) {
            $this->trash = [
                'ttl_days' => (int) ($stored['ttl_days'] ?? 30),
                'purge_auto' => (bool) ($stored['purge_auto'] ?? false),
            ];
        }
    }

    // =========================================================================
    // PERSISTENCE — DEFAULTS
    // =========================================================================

    public function saveDefaults(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        // Normalise input "" → 0 avant validation (champ texte = illimité).
        foreach (['eleve', 'prof', 'admin', 'itinerant'] as $profile) {
            foreach (['home', 'sambaedu'] as $partKey) {
                foreach (['soft_mb', 'overage_percent'] as $field) {
                    $val = $this->defaults[$profile][$partKey][$field] ?? 0;
                    $this->defaults[$profile][$partKey][$field] = (int) $val;
                }
            }
        }

        // Validation soft >= 10 Mo sur /home (cohérent QuotaController:84).
        $rules = [];
        foreach (['eleve', 'prof', 'admin', 'itinerant'] as $profile) {
            $rules["defaults.$profile.home.soft_mb"] = ['required', 'integer', 'min:0'];
            $rules["defaults.$profile.home.overage_percent"] = ['required', 'integer', 'min:0', 'max:100'];
            $rules["defaults.$profile.sambaedu.soft_mb"] = ['required', 'integer', 'min:0'];
            $rules["defaults.$profile.sambaedu.overage_percent"] = ['required', 'integer', 'min:0', 'max:100'];
        }
        try {
            $this->validate($rules);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toastError('Valeurs invalides — corrigez les champs en rouge.');
            throw $e;
        }

        // Soft check >= 10 Mo si > 0 sur /home (0 = illimité accepté).
        foreach (['eleve', 'prof', 'admin', 'itinerant'] as $profile) {
            $softHome = (int) ($this->defaults[$profile]['home']['soft_mb'] ?? 0);
            if ($softHome > 0 && $softHome < 10) {
                $this->addError("defaults.$profile.home.soft_mb", "Le quota /home pour le profil $profile doit être d'au moins 10 Mo (ou 0 pour illimité).");
                $this->toastError("Le quota /home pour le profil $profile doit être ≥ 10 Mo (ou 0 pour illimité).");
                return;
            }
        }

        try {
            // Normalise la structure persistée (entiers + clés stables).
            $normalized = [];
            foreach (['eleve', 'prof', 'admin', 'itinerant'] as $profile) {
                foreach (['home', 'sambaedu'] as $partKey) {
                    $soft = max(0, (int) ($this->defaults[$profile][$partKey]['soft_mb'] ?? 0));
                    $overage = max(0, min(100, (int) ($this->defaults[$profile][$partKey]['overage_percent'] ?? 20)));
                    $normalized[$profile][$partKey] = [
                        'soft_mb' => $soft,
                        'overage_percent' => $overage,
                    ];
                }
            }

            SystemSetting::set('quota.defaults', $normalized);
            $this->defaults = $normalized;
            $this->originalDefaults = $normalized;

            $this->toastSuccess('Réglages enregistrés');
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec save defaults', [
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Impossible d\'enregistrer les valeurs par défaut. Consultez les logs.');
        }
    }

    // =========================================================================
    // PERSISTENCE — GRACE
    // =========================================================================

    public function saveGrace(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        try {
            $this->validate([
                'grace.home' => ['required', 'integer', 'min:0', 'max:30'],
                'grace.sambaedu' => ['required', 'integer', 'min:0', 'max:30'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toastError('Période de grâce invalide (doit être entre 0 et 30 jours).');
            throw $e;
        }

        $performedBy = auth()->user()?->login ?? 'system';

        try {
            // Persistance BDD (toujours).
            $homeSetting = QuotaSetting::forPartition(QuotaRule::PARTITION_HOME);
            $homeSetting->grace_period_days = (int) $this->grace['home'];
            $homeSetting->save();

            $sambaeduSetting = QuotaSetting::forPartition(QuotaRule::PARTITION_SAMBAEDU);
            $sambaeduSetting->grace_period_days = (int) $this->grace['sambaedu'];
            $sambaeduSetting->save();

            // D4=A : application synchrone sur le filesystem (best effort).
            // Échec n'invalide pas la persistance BDD — on log + toast info.
            $applyOk = true;
            try {
                $resHome = $this->quotaService->setGracePeriod(QuotaRule::PARTITION_HOME, (int) $this->grace['home'], $performedBy);
                $resSamba = $this->quotaService->setGracePeriod(QuotaRule::PARTITION_SAMBAEDU, (int) $this->grace['sambaedu'], $performedBy);
                $applyOk = ($resHome['success'] ?? false) && ($resSamba['success'] ?? false);
            } catch (\Throwable $e) {
                Log::warning('QuotaService: setGracePeriod sur filesystem échoué (BDD persistée)', [
                    'error' => $e->getMessage(),
                ]);
                $applyOk = false;
            }

            $this->originalGrace = $this->grace;

            if ($applyOk) {
                $this->toastSuccess('Période de grâce mise à jour.');
            } else {
                $this->toastInfo('Période de grâce enregistrée (application filesystem reportée — consultez les logs).');
            }
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec save grace', [
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Impossible d\'enregistrer la période de grâce. Consultez les logs.');
        }
    }

    // =========================================================================
    // PERSISTENCE — TRASH
    // =========================================================================

    public function saveTrash(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        try {
            $this->validate([
                'trash.ttl_days' => ['required', 'integer', 'min:1', 'max:365'],
                'trash.purge_auto' => ['required', 'boolean'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toastError('Configuration corbeille invalide (TTL doit être entre 1 et 365 jours).');
            throw $e;
        }

        try {
            $payload = [
                'ttl_days' => (int) $this->trash['ttl_days'],
                'purge_auto' => (bool) $this->trash['purge_auto'],
            ];
            SystemSetting::set('quota.trash', $payload);
            $this->trash = $payload;
            $this->originalTrash = $payload;

            $this->toastSuccess('Configuration corbeille enregistrée.');
        } catch (\Throwable $e) {
            Log::error('QuotaService: échec save trash', [
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Impossible d\'enregistrer la configuration corbeille. Consultez les logs.');
        }
    }

    /**
     * Story 5.1d — Bouton "Purger maintenant" (D3=A : sync Artisan::call).
     *
     * Sécurité : double guard `Gate::allows('server.admin')` cohérent
     * saveDefaults/saveGrace/saveTrash. Le payload Livewire forgé par un user
     * non-admin déclenche `abort(403)` avant tout I/O.
     *
     * Exécution synchrone via `Artisan::call('trash:purge')` — purge légère
     * (≤50 dossiers en moyenne sur SER) acceptable inline. Si volume prod
     * monte, basculer async via Job dans une story future.
     *
     * Le compteur "Purgé : N" est extrait de l'output via regex tolérante
     * (singulier "dossier" ET pluriel "dossier(s)"), avec fallback à 0 si le
     * format évolue.
     */
    public function purgeNow(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        // Pré-check TTL : la commande retourne SUCCESS + count=0 quand TTL <= 0
        // (no-op safe D2=A). Sans ce garde-fou côté UI, l'admin verrait un toast
        // VERT "Corbeille purgée — 0 dossier supprimé" alors qu'aucune purge n'a
        // eu lieu (faux succès trompeur — review #5).
        $cfg = SystemSetting::get('quota.trash', null);
        $ttlDays = is_array($cfg) ? (int) ($cfg['ttl_days'] ?? 0) : 0;
        if ($ttlDays <= 0) {
            $this->toastError('Corbeille non purgée — TTL non configuré (saisir un TTL > 0 dans la section Corbeille).');
            return;
        }

        try {
            $performedBy = auth()->user()?->login ?? 'admin';
            // Trace l'origine de la purge dans QuotaAuditLog : utile pour distinguer
            // les purges manuelles (UI) des purges automatiques (cron).
            $exitCode = Artisan::call('trash:purge', [
                '--performed-by' => 'ui:' . $performedBy,
            ]);
            $output = Artisan::output();

            $count = preg_match('/Purgé\s*:\s*(\d+)/u', $output, $m) ? (int) $m[1] : 0;
            $errors = preg_match('/Erreurs\s*:\s*(\d+)/u', $output, $me) ? (int) $me[1] : 0;

            if ($exitCode === 0) {
                if ($errors > 0) {
                    $this->toastInfo(sprintf(
                        'Corbeille purgée — %d dossier(s) supprimé(s), %d erreur(s). Consultez les logs.',
                        $count,
                        $errors,
                    ));
                } else {
                    $this->toastSuccess(sprintf('Corbeille purgée — %d dossier(s) supprimé(s).', $count));
                }
            } else {
                $this->toastError('Échec de la purge — voir les logs.');
            }
        } catch (\Throwable $e) {
            Log::error('QuotaService: purgeNow échec', [
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Échec de la purge — voir les logs.');
        }
    }

    // =========================================================================
    // HELPERS RENDU
    // =========================================================================

    public function calculateHard(int $softMb, int $overagePercent): int
    {
        if ($softMb === 0) {
            return 0;
        }
        return (int) round($softMb * (1 + $overagePercent / 100));
    }

    /**
     * @return array<int, array{key:string, label:string}>
     */
    public function profilesList(): array
    {
        return [['key' => 'eleve', 'label' => 'Élève'], ['key' => 'prof', 'label' => 'Professeur'], ['key' => 'admin', 'label' => 'Administrateur'], ['key' => 'itinerant', 'label' => 'Itinérant']];
    }

    /**
     * @return array<int, array{key:string, label:string, partition:string}>
     */
    public function partitionsList(): array
    {
        return [['key' => 'home', 'label' => '/home', 'partition' => QuotaRule::PARTITION_HOME], ['key' => 'sambaedu', 'label' => '/var/sambaedu', 'partition' => QuotaRule::PARTITION_SAMBAEDU]];
    }
};
?>

<div class="space-y-6">
    {{-- ===================================================================
         Section 1 — Defaults par profil
         =================================================================== --}}
    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <div class="flex items-center justify-between mb-2">
                <h3 class="card-title text-lg">
                    <i class="fa-solid fa-sliders mr-2"></i>
                    Quotas par défaut (par profil)
                </h3>
            </div>
            <p class="text-sm opacity-70 mb-4">
                Valeurs appliquées à tout utilisateur sans règle plus spécifique
                (groupe ou personnel). <strong>Limite</strong> = quota nominal,
                <strong>Tolérance</strong> = dépassement autorisé (%),
                <strong>Blocage</strong> = seuil au-delà duquel l'écriture est interdite (calculé).
            </p>

            <form wire:submit.prevent="saveDefaults" class="space-y-6">
                @foreach ($this->profilesList() as $profile)
                    <div class="border border-base-300 rounded-lg p-4">
                        <h4 class="font-semibold mb-3 text-base">
                            {{ $profile['label'] }}
                        </h4>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach ($this->partitionsList() as $partition)
                                @php
                                    $pkey = $partition['key'];
                                    $soft = (int) ($defaults[$profile['key']][$pkey]['soft_mb'] ?? 0);
                                    $overage = (int) ($defaults[$profile['key']][$pkey]['overage_percent'] ?? 20);
                                    $hard = $this->calculateHard($soft, $overage);
                                @endphp
                                <div class="space-y-2 rounded-lg p-3 border-l-4 border-primary/60 bg-base-100">
                                    <div class="flex items-center gap-2 pb-2 mb-1 border-b border-base-300">
                                        <i
                                            class="fa-solid {{ $pkey === 'home' ? 'fa-house' : 'fa-server' }} text-primary"></i>
                                        <code class="text-sm font-bold tracking-wide">{{ $partition['label'] }}</code>
                                        <span class="text-xs opacity-60 ml-auto">
                                            {{ $pkey === 'home' ? 'Espace personnel' : 'Partages communs' }}
                                        </span>
                                    </div>

                                    <div class="grid grid-cols-3 gap-2 items-start">
                                        <div class="bg-base-200 space-y-2 rounded-lg p-2">
                                            <label class="label text-center py-1">
                                                <span class="label-text text-xs">Limite (Mo)</span>
                                            </label>
                                            <div class="relative" x-data="{ focused: false }">
                                                <input type="text" inputmode="numeric" pattern="[0-9]*"
                                                    x-on:focus="focused = true; if ($event.target.value === '0') $event.target.value = ''"
                                                    x-on:blur="focused = false; if ($event.target.value === '') $event.target.value = '0'"
                                                    wire:model.live.debounce.500ms="defaults.{{ $profile['key'] }}.{{ $pkey }}.soft_mb"
                                                    class="input input-bordered input-sm w-full text-center"
                                                    :class="{{ $soft === 0 ? "{ 'text-transparent': !focused }" : '{}' }}" />
                                                @if ($soft === 0)
                                                    <span x-show="!focused"
                                                        class="pointer-events-none absolute inset-0 flex items-center justify-center text-xs font-semibold text-success">
                                                        <i class="fa-solid fa-infinity mr-1"></i>Illimité
                                                    </span>
                                                @endif
                                            </div>
                                            @error("defaults.{$profile['key']}.{$pkey}.soft_mb")
                                                <span class="text-xs text-error mt-1 block">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="bg-base-200 space-y-2 rounded-lg p-2">
                                            <label class="label py-1">
                                                <span class="label-text text-xs">Tolérance (%)</span>
                                            </label>
                                            <input type="text" inputmode="numeric" pattern="[0-9]*"
                                                x-on:focus="if ($event.target.value === '0') $event.target.value = ''"
                                                x-on:blur="if ($event.target.value === '') $event.target.value = '0'"
                                                wire:model.live.debounce.500ms="defaults.{{ $profile['key'] }}.{{ $pkey }}.overage_percent"
                                                @disabled($soft === 0)
                                                class="input input-bordered input-sm w-full text-center" />
                                        </div>
                                        <div class="bg-base-200 space-y-2 rounded-lg p-2">
                                            <label class="label py-1">
                                                <span class="label-text text-xs">Blocage</span>
                                            </label>
                                            <div
                                                class="relative h-8 flex items-center justify-center rounded border border-base-300 bg-base-300/30 text-sm font-semibold">
                                                @if ($soft === 0)
                                                    <span
                                                        class="absolute inset-0 flex items-center justify-center text-base-content/50">
                                                        Aucun
                                                    </span>
                                                @else
                                                    <span>
                                                        {{ number_format($hard, 0, ',', ' ') }} Mo
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="flex justify-end">
                    <button type="submit" class="btn btn-primary"
                        @disabled(!$this->defaultsDirty)
                        wire:loading.attr="disabled" wire:target="saveDefaults">
                        <span wire:loading wire:target="saveDefaults" class="loading loading-spinner loading-xs"></span>
                        <i wire:loading.remove wire:target="saveDefaults" class="fa-solid fa-save"></i>
                        Enregistrer les defaults
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===================================================================
         Section 2 — Grace period par partition
         =================================================================== --}}
    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <div class="flex items-center justify-between mb-2">
                <h3 class="card-title text-lg">
                    <i class="fa-solid fa-clock mr-2"></i>
                    Période de grâce
                </h3>
            </div>
            <p class="text-sm opacity-70 mb-4">
                Délai (en jours) accordé à un utilisateur après dépassement du
                <x-atoms.tooltip label="quota souple" labelClass="font-medium" icon="true"
                    iconClass="fa-solid fa-circle-info text-base-content/40 text-xs ml-1">
                    Quota souple : non bloquant, l'utilisateur est invité à se conformer dans le
                    délai ci-dessous. À la différence du quota dur, qui interdit immédiatement
                    l'écriture au-delà de la limite.
                </x-atoms.tooltip>
                avant que l'écriture ne soit bloquée. Appliquée aussi
                sur le filesystem XFS si la commande est disponible.
            </p>

            <form wire:submit.prevent="saveGrace" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control rounded-lg p-3 border-l-4 border-primary/60 bg-base-100">
                        <div class="flex items-center gap-2 pb-2 mb-2 border-b border-base-300">
                            <i class="fa-solid fa-house text-primary"></i>
                            <code class="text-sm font-bold tracking-wide">/home</code>
                            <span class="text-xs opacity-60 ml-auto">Espace personnel</span>
                        </div>
                        <label class="label py-1">
                            <span class="label-text text-xs">Délai (jours)</span>
                        </label>
                        <input type="number" min="0" max="30"
                            wire:model.live.debounce.500ms="grace.home"
                            class="input input-bordered input-sm" />
                        @error('grace.home')
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="form-control rounded-lg p-3 border-l-4 border-primary/60 bg-base-100">
                        <div class="flex items-center gap-2 pb-2 mb-2 border-b border-base-300">
                            <i class="fa-solid fa-server text-primary"></i>
                            <code class="text-sm font-bold tracking-wide">/var/sambaedu</code>
                            <span class="text-xs opacity-60 ml-auto">Partages communs</span>
                        </div>
                        <label class="label py-1">
                            <span class="label-text text-xs">Délai (jours)</span>
                        </label>
                        <input type="number" min="0" max="30"
                            wire:model.live.debounce.500ms="grace.sambaedu"
                            class="input input-bordered input-sm" />
                        @error('grace.sambaedu')
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="btn btn-primary"
                        @disabled(!$this->graceDirty)
                        wire:loading.attr="disabled" wire:target="saveGrace">
                        <span wire:loading wire:target="saveGrace" class="loading loading-spinner loading-xs"></span>
                        <i wire:loading.remove wire:target="saveGrace" class="fa-solid fa-save"></i>
                        Enregistrer la période de grâce
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===================================================================
         Section 3 — Corbeille /home/trash (TTL + toggle purge auto)
         =================================================================== --}}
    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <div class="flex items-center justify-between mb-2">
                <h3 class="card-title text-lg">
                    <i class="fa-solid fa-trash mr-2"></i>
                    Corbeille (/home/trash)
                </h3>
            </div>
            <p class="text-sm opacity-70 mb-4">
                Configuration de la rétention des fichiers dans la corbeille
                utilisateurs.
            </p>

            <form wire:submit.prevent="saveTrash" class="space-y-4">
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">TTL avant purge définitive (jours)</span>
                    </label>
                    <input type="number" min="1" max="365"
                        wire:model.live.debounce.500ms="trash.ttl_days"
                        class="input input-bordered max-w-xs" />
                    @error('trash.ttl_days')
                        <span class="text-xs text-error mt-1">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-control">
                    <label class="label cursor-pointer max-w-xs justify-start gap-3">
                        <input type="checkbox" wire:model.live="trash.purge_auto" class="toggle toggle-primary" />
                        <span class="label-text">Purge automatique (cron 02h00)</span>
                    </label>
                </div>

                <div class="flex justify-between items-center gap-2 flex-wrap">
                    {{-- Story 5.1d — Bouton "Purger maintenant" (sync Artisan::call). --}}
                    <button type="button" class="btn btn-outline btn-warning"
                        wire:click="purgeNow"
                        wire:confirm="Purger maintenant la corbeille ? Les dossiers /home/trash plus vieux que le TTL configuré seront supprimés définitivement."
                        wire:loading.attr="disabled" wire:target="purgeNow">
                        <span wire:loading wire:target="purgeNow" class="loading loading-spinner loading-xs"></span>
                        <i wire:loading.remove wire:target="purgeNow" class="fa-solid fa-broom"></i>
                        Purger maintenant
                    </button>

                    <button type="submit" class="btn btn-primary"
                        @disabled(!$this->trashDirty)
                        wire:loading.attr="disabled" wire:target="saveTrash">
                        <span wire:loading wire:target="saveTrash" class="loading loading-spinner loading-xs"></span>
                        <i wire:loading.remove wire:target="saveTrash" class="fa-solid fa-save"></i>
                        Enregistrer la configuration corbeille
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
