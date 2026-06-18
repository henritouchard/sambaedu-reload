<?php

use App\Components\Traits\WithToasts;
use App\Gpo\Dto\WpkgGpoSyncReport;
use App\Gpo\Services\WpkgGpoSynchronizer;
use App\Models\SystemSetting;
use App\Wpkg\Deployment\Rules\SafeIpCidrRule;
use App\Wpkg\Deployment\Services\WpkgDeploymentSettings;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — Hook GPO ↔ WPKG (Story 16.6 + Story 16.9).
 * Étendue en Story 15.6 : carte « Réglages de déploiement » (winget_enabled + allowed_ips).
 * Servie sous `/admin/settings/gpo/wpkg-deployment`.
 *
 * Affiche l'état de cohérence entre la GPO `se4_wpkg` qui déclenche
 * `cscript wpkg.js /server=...` côté postes Windows et les endpoints serveur
 * `/wpkg/hosts.xml` + `/wpkg/profiles.xml` (Story 15.2) + Bearer Phase 2
 * (Story 15.5 lecture seule).
 *
 * Actions exposées :
 *  - Re-auditer (lecture pure, no side effect)
 *  - Re-publier la GPO (write SYSVOL via shim `import_gpo` — modale
 *    confirmation D5 obligatoire)
 *  - Réglages de déploiement (Story 15.6) : toggle winget + allowlist IP (modale pour ajout CIDR)
 *
 * Permission : `server.admin` (D8 — middleware route + abort_unless mount).
 *
 * **PIÈGE AUDIT** : les mutations Livewire ne passent PAS par le middleware HTTP
 * → l'audit doit être émis explicitement depuis save() (Story 15.6 / D5 / AC5).
 */
new #[Title('Hook GPO ↔ WPKG - SE4FS')] class extends Component {
    use WithToasts;

    // =========================================================================
    // Partie audit GPO existante (Story 16.6 + 16.9) — NE PAS MODIFIER
    // =========================================================================

    public ?array $report = null;
    public bool $hasError = false;

    // --- Modale confirmation publish (D5) ---
    public bool $isPublishModalOpen = false;
    public bool $forceFlag = false;
    public bool $isPublishing = false;

    #[Locked]
    public string $expectedHostsXmlUrl = '';
    #[Locked]
    public string $expectedProfilesXmlUrl = '';

    private WpkgGpoSynchronizer $sync;

    public function boot(WpkgGpoSynchronizer $sync): void
    {
        $this->sync = $sync;
    }

    // =========================================================================
    // Partie réglages déploiement (Story 15.6)
    // =========================================================================

    /** Valeur courante du toggle winget (résolue DB > env > défaut). */
    public bool $wingetEnabled = false;

    /** Source de la valeur winget_enabled ('db' | 'env'). */
    public string $wingetSource = 'env';

    /** Liste des IP/CIDR autorisées (affichage). */
    public array $allowedIps = [];

    /** Source de la valeur allowed_ips ('db' | 'env'). */
    public string $allowedIpsSource = 'env';

    /** Nouvelle entrée IP/CIDR en cours de saisie. */
    public string $newIpEntry = '';

    /** Erreur de validation pour la saisie d'IP. */
    public ?string $newIpError = null;

    /** Contrôle la modale de confirmation élargissement allowlist (D8). */
    public bool $isAddCidrModalOpen = false;

    /** IP/CIDR en attente de confirmation dans la modale. */
    public string $pendingCidr = '';

    // =========================================================================
    // Mount
    // =========================================================================

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $this->audit();
        $this->loadDeploymentSettings();
    }

    // =========================================================================
    // Chargement des réglages déploiement
    // =========================================================================

    private function loadDeploymentSettings(): void
    {
        /** @var WpkgDeploymentSettings $settings */
        $settings = app(WpkgDeploymentSettings::class);

        // winget_enabled
        $this->wingetEnabled = $settings->wingetEnabled();
        $this->wingetSource = SystemSetting::get('wpkg.winget_enabled') !== null ? 'db' : 'env';

        // allowed_ips
        $this->allowedIps = $settings->allowedIps();
        $this->allowedIpsSource = SystemSetting::get('wpkg.allowed_ips') !== null ? 'db' : 'env';
    }

    // =========================================================================
    // Actions réglages déploiement (Story 15.6)
    // =========================================================================

    /**
     * Toggle winget_enabled (Story 15.6 / AC4.2).
     *
     * Persiste via SystemSetting + toast + audit explicite (D5 / AC5).
     * Pas de modale (action simple — D8).
     */
    public function toggleWinget(): void
    {
        abort_unless(auth()->check() && auth()->user()->can('server.admin'), 403);

        $oldValue = $this->wingetEnabled;
        $newValue = ! $oldValue;

        SystemSetting::set('wpkg.winget_enabled', $newValue);
        $this->wingetEnabled = $newValue;
        $this->wingetSource = 'db';

        // Audit explicite (AC5 — le middleware HTTP ne voit pas les mutations Livewire).
        $this->emitSettingChangedAudit(
            setting: 'wpkg.winget_enabled',
            old: $oldValue,
            new: $newValue,
        );

        $this->toastSuccess(
            $newValue ? 'Canal winget activé' : 'Canal winget désactivé',
            'La modification prend effet immédiatement (sans config:cache).',
        );
    }

    /**
     * Démarre l'ajout d'un CIDR : valide la syntaxe puis ouvre la modale (D8).
     *
     * La modale rappelle que l'endpoint est non authentifié.
     */
    public function prepareAddCidr(): void
    {
        abort_unless(auth()->check() && auth()->user()->can('server.admin'), 403);

        $this->newIpError = null;
        $entry = trim($this->newIpEntry);

        if ($entry === '') {
            $this->newIpError = 'Veuillez saisir une adresse IP ou un CIDR.';
            return;
        }

        // Doublon.
        if (in_array($entry, $this->allowedIps, true)) {
            $this->newIpError = 'Cette entrée est déjà dans la liste.';
            return;
        }

        // Validation via SafeIpCidrRule.
        $error = $this->validateIpCidrEntry($entry);
        if ($error !== null) {
            $this->newIpError = $error;
            return;
        }

        $this->pendingCidr = $entry;
        $this->isAddCidrModalOpen = true;
    }

    /**
     * Confirmation de l'ajout CIDR depuis la modale (AC4.3).
     */
    public function confirmAddCidr(): void
    {
        abort_unless(auth()->check() && auth()->user()->can('server.admin'), 403);

        $this->isAddCidrModalOpen = false;
        $entry = $this->pendingCidr;
        $this->pendingCidr = '';

        if ($entry === '') {
            return;
        }

        $oldIps = $this->allowedIps;
        $newIps = array_values(array_unique([...$this->allowedIps, $entry]));

        SystemSetting::set('wpkg.allowed_ips', $newIps);
        $this->allowedIps = $newIps;
        $this->allowedIpsSource = 'db';
        $this->newIpEntry = '';
        $this->newIpError = null;

        // Audit explicite (AC5).
        $this->emitSettingChangedAudit(
            setting: 'wpkg.allowed_ips',
            old: $oldIps,
            new: $newIps,
        );

        $this->toastSuccess('Entrée ajoutée', "\"$entry\" ajouté à l'allowlist WPKG.");
    }

    /**
     * Ferme la modale d'ajout CIDR sans effet.
     */
    public function closeAddCidrModal(): void
    {
        $this->isAddCidrModalOpen = false;
        $this->pendingCidr = '';
    }

    /**
     * Suppression d'une entrée de l'allowlist (AC4.3 — pas de modale au retrait).
     */
    public function removeIp(string $ip): void
    {
        abort_unless(auth()->check() && auth()->user()->can('server.admin'), 403);

        $oldIps = $this->allowedIps;
        $newIps = array_values(array_filter($this->allowedIps, static fn ($e) => $e !== $ip));

        SystemSetting::set('wpkg.allowed_ips', $newIps);
        $this->allowedIps = $newIps;
        $this->allowedIpsSource = 'db';

        // Audit explicite (AC5).
        $this->emitSettingChangedAudit(
            setting: 'wpkg.allowed_ips',
            old: $oldIps,
            new: $newIps,
        );

        $this->toastSuccess('Entrée retirée', "\"$ip\" retiré de l'allowlist WPKG.");
    }

    /**
     * Émet l'audit explicite du changement de réglage (AC5.1 + AC5.2).
     *
     * - Log structuré sur le channel `wpkg-deploy` (AC5.2).
     * - Pas de table DB dédiée : le log structuré est le pattern projet pour
     *   cet événement (cf. WorkstationOptionsService qui log de même façon).
     */
    private function emitSettingChangedAudit(string $setting, mixed $old, mixed $new): void
    {
        $userId = auth()->id();

        Log::channel('wpkg-deploy')->info('[WpkgDeploymentSettings] réglage modifié', [
            'event' => 'wpkg_deployment_setting_changed',
            'setting' => $setting,
            'old' => $old,
            'new' => $new,
            'user_id' => $userId,
        ]);
    }

    /**
     * Valide une entrée IP/CIDR via SafeIpCidrRule.
     * Retourne le message d'erreur ou null si valide.
     */
    private function validateIpCidrEntry(string $entry): ?string
    {
        $rule = new SafeIpCidrRule();
        $error = null;

        $rule->validate('ip', $entry, function (string $message) use (&$error, $entry): void {
            // Remplace le placeholder :input par la valeur.
            $error = str_replace(':input', $entry, $message);
        });

        return $error;
    }

    // =========================================================================
    // Partie audit GPO (Story 16.6 + 16.9) — inchangée
    // =========================================================================

    /**
     * Recharge le rapport d'audit (lecture pure, no side effect).
     */
    public function audit(): void
    {
        $this->hasError = false;
        try {
            $r = $this->sync->audit();
            $this->report = $r->toArray();
            $this->expectedHostsXmlUrl = $r->expectedHostsXmlUrl;
            $this->expectedProfilesXmlUrl = $r->expectedProfilesXmlUrl;
        } catch (\Throwable $e) {
            $this->hasError = true;
            $this->report = null;
            $this->toast('error', 'Audit impossible', $e->getMessage());
        }
    }

    public function refresh(): void
    {
        $this->audit();
        if (! $this->hasError) {
            $this->toast('success', 'Audit rechargé', 'État de cohérence rafraîchi.');
        }
    }

    // -------------------------------------------------------------------------
    // Modale "Re-publier la GPO" (D5)
    // -------------------------------------------------------------------------

    public function openPublishModal(): void
    {
        $this->forceFlag = false;
        $this->isPublishModalOpen = true;
    }

    public function closePublishModal(): void
    {
        $this->isPublishModalOpen = false;
        $this->forceFlag = false;
    }

    public function close(): void
    {
        $this->closePublishModal();
    }

    public function confirmPublish(): void
    {
        // Story 27.5 (D2) — la PUBLICATION de la GPO `se4_wpkg` est RETIRÉE :
        // l'AGENT est désormais le SEUL déclencheur de WPKG (handler
        // `applications` → `wpkg-client.vbs`), à la place de la GPO. SE5 cesse de
        // publier `se4_wpkg` (pas de double déclenchement / collision). L'action
        // de re-publication devient un NO-OP informatif + re-audit (lecture
        // seule) ; on ne touche plus SYSVOL. La GPO résiduelle côté lab est
        // déliée hors worktree (action Henri).
        $this->isPublishModalOpen = false;
        $this->isPublishing = false;
        $this->forceFlag = false;

        $this->audit(); // refresh état AD réel (lecture seule, jamais de publish).
        $this->toast(
            'info',
            'Publication GPO retirée (Story 27.5)',
            'La GPO `se4_wpkg` n\'est plus publiée par SE5 : l\'agent déclenche désormais WPKG (canal desired-state). Aucune action SYSVOL effectuée.',
        );
    }

    // -------------------------------------------------------------------------
    // Computed accessors pour la vue
    // -------------------------------------------------------------------------

    public function getSeverityClassProperty(): string
    {
        $sev = $this->report['severity'] ?? 'ok';
        return match ($sev) {
            'ok' => 'badge-success',
            'info' => 'badge-info',
            'warning' => 'badge-warning',
            'error' => 'badge-error',
            default => 'badge-neutral',
        };
    }

    public function getBearerSummaryProperty(): string
    {
        if (! is_array($this->report)) {
            return '(n/a)';
        }
        if (($this->report['bearerTableAvailable'] ?? false) === false) {
            return 'Table absente (Story 15.5 Phase 2)';
        }
        $coverage = $this->report['bearerCoverage'] ?? [];
        if (! is_array($coverage) || $coverage === []) {
            return 'Aucun poste évalué';
        }
        $covered = count(array_filter($coverage));
        return sprintf('%d/%d postes couverts', $covered, count($coverage));
    }
};
?>

<x-organisms.page title="Hook GPO ↔ WPKG" :scrollable="true"
    description="Cohérence entre la GPO `se4_wpkg` et les endpoints `/wpkg/hosts.xml` + `/wpkg/profiles.xml` (Story 15.2 / Story 15.5 / Story 16.6).">

    <x-slot:actions>
        <div class="flex flex-wrap gap-2 items-center">
            <button type="button" class="btn btn-outline btn-sm" wire:click="refresh"
                wire:loading.attr="disabled" data-testid="re-audit">
                <i class="fa-solid fa-arrows-rotate"></i>
                Re-auditer
            </button>
            <a href="{{ route('admin.gpo.index') }}" class="btn btn-ghost btn-sm">
                <i class="fa-solid fa-list"></i>
                Liste des GPOs
            </a>
        </div>
    </x-slot:actions>

    <div class="space-y-6">

        {{-- ===================================================================
             Carte « Réglages de déploiement » (Story 15.6) — en tête de page
             =================================================================== --}}
        <div class="card bg-base-100 shadow-sm border border-base-200" data-testid="deployment-settings-card">
            <div class="card-body">
                <h2 class="card-title text-lg flex items-center gap-2">
                    <i class="fa-solid fa-sliders text-primary"></i>
                    Réglages de déploiement
                    <span class="badge badge-ghost badge-sm">Story 15.6</span>
                </h2>
                <p class="text-sm text-base-content/70 mb-4">
                    Ces réglages s'appliquent immédiatement sans rebuild de cache ni accès SSH.
                    Source <span class="badge badge-primary badge-sm">DB</span> = override runtime ;
                    source <span class="badge badge-ghost badge-sm">env</span> = défaut bootstrap (<code>.env</code>).
                </p>

                {{-- Section 1 : Toggle winget_enabled --}}
                <div class="border border-base-300 rounded-lg p-4 space-y-3" data-testid="winget-toggle-section">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div>
                            <h3 class="font-semibold flex items-center gap-2">
                                <i class="fa-brands fa-windows text-info"></i>
                                Canal winget
                                @if ($wingetSource === 'db')
                                    <span class="badge badge-primary badge-sm" data-testid="winget-source-badge">DB</span>
                                @else
                                    <span class="badge badge-ghost badge-sm" data-testid="winget-source-badge">env</span>
                                @endif
                            </h3>
                            <p class="text-xs text-base-content/60 mt-1">
                                Active l'endpoint <code>/wpkg/winget_out.php</code>. Si désactivé → 400 (postes n'installent rien via winget).
                            </p>
                        </div>
                        <label class="label cursor-pointer gap-3" data-testid="winget-toggle-label">
                            <span class="label-text text-sm">
                                @if ($wingetEnabled)
                                    <span class="text-success font-medium">Activé</span>
                                @else
                                    <span class="text-error font-medium">Désactivé</span>
                                @endif
                            </span>
                            <input type="checkbox"
                                class="toggle toggle-primary"
                                wire:click="toggleWinget"
                                @checked($wingetEnabled)
                                data-testid="winget-toggle" />
                        </label>
                    </div>
                </div>

                {{-- Section 2 : Allowlist IP/CIDR --}}
                <div class="border border-base-300 rounded-lg p-4 space-y-3 mt-3" data-testid="allowed-ips-section">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h3 class="font-semibold flex items-center gap-2">
                            <i class="fa-solid fa-shield-halved text-warning"></i>
                            Allowlist IP / CIDR
                            @if ($allowedIpsSource === 'db')
                                <span class="badge badge-primary badge-sm" data-testid="ips-source-badge">DB</span>
                            @else
                                <span class="badge badge-ghost badge-sm" data-testid="ips-source-badge">env</span>
                            @endif
                            <span class="badge badge-neutral badge-sm">{{ count($allowedIps) }} entrée(s)</span>
                        </h3>
                    </div>
                    <p class="text-xs text-base-content/60">
                        IPs / CIDRs autorisés à consommer les endpoints WPKG. <strong>127.0.0.1</strong> et <strong>::1</strong>
                        sont toujours autorisés en dur (immuables). Ces endpoints ne sont <em>pas</em> authentifiés — l'allowlist est la frontière de sécurité.
                    </p>

                    {{-- Liste courante --}}
                    @if (count($allowedIps) > 0)
                        <ul class="space-y-1" data-testid="allowed-ips-list">
                            @foreach ($allowedIps as $ip)
                                <li class="flex items-center justify-between gap-2 bg-base-200 rounded px-3 py-1">
                                    <code class="text-sm font-mono">{{ $ip }}</code>
                                    <button type="button"
                                        class="btn btn-ghost btn-xs text-error"
                                        wire:click="removeIp('{{ $ip }}')"
                                        data-testid="remove-ip-{{ $ip }}">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-xs text-base-content/40 italic" data-testid="allowed-ips-empty">
                            Aucune entrée — seul localhost (127.0.0.1 / ::1) est autorisé.
                        </p>
                    @endif

                    {{-- Saisie nouvelle entrée --}}
                    <div class="flex items-start gap-2 flex-wrap mt-2">
                        <div class="flex-1 min-w-48">
                            <input type="text"
                                wire:model.live="newIpEntry"
                                placeholder="192.168.1.0/24 ou 10.0.0.1"
                                class="input input-bordered input-sm w-full font-mono @error('newIpEntry') input-error @enderror"
                                data-testid="new-ip-input" />
                            @if ($newIpError)
                                <p class="text-xs text-error mt-1" data-testid="new-ip-error">{{ $newIpError }}</p>
                            @endif
                        </div>
                        <button type="button"
                            class="btn btn-warning btn-sm"
                            wire:click="prepareAddCidr"
                            data-testid="add-cidr-btn">
                            <i class="fa-solid fa-plus"></i>
                            Ajouter
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Reste de la page : audit GPO (inchangé) --}}

        @if ($hasError || $report === null)
            <div class="alert alert-error" data-testid="audit-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <p class="font-medium">Audit impossible</p>
                    <p class="text-sm opacity-80">
                        Consultez les logs `storage/logs/gpo/gpo-*.log` (channel `gpo`, action_type
                        `gpo.wpkg.sync.start` / `gpo.wpkg.sync.end`).
                    </p>
                </div>
            </div>
        @else
            {{-- Badge sévérité principal --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body py-4">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <h2 class="card-title flex items-center gap-3">
                            <i class="fa-solid fa-circle-nodes text-primary"></i>
                            Statut global
                            <span class="badge {{ $this->severityClass }} badge-lg uppercase font-mono"
                                data-testid="severity-badge">
                                {{ strtoupper($report['severity']) }}
                            </span>
                        </h2>
                        <button type="button" class="btn btn-error btn-sm" wire:click="openPublishModal"
                            wire:loading.attr="disabled" data-testid="open-publish-modal">
                            <i class="fa-solid fa-upload"></i>
                            Re-publier la GPO `se4_wpkg`
                        </button>
                    </div>
                    @if (! empty($report['operationId']))
                        <p class="text-xs text-base-content/60 font-mono">
                            operation_id : {{ $report['operationId'] }}
                        </p>
                    @endif
                </div>
            </div>

            {{-- Tableau 1 : État GPO --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h3 class="card-title text-lg flex items-center gap-2">
                        <i class="fa-solid fa-server text-secondary"></i>
                        État GPO `se4_wpkg`
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="table table-sm" data-testid="gpo-state-table">
                            <tbody>
                                <tr>
                                    <td class="font-medium">Existe dans l'AD ?</td>
                                    <td>
                                        @if ($report['gpoExists'])
                                            <span class="badge badge-success">Oui</span>
                                        @else
                                            <span class="badge badge-error">Non — publication initiale requise</span>
                                        @endif
                                    </td>
                                </tr>
                                @if ($report['gpoGuid'])
                                    <tr>
                                        <td class="font-medium">GUID</td>
                                        <td class="font-mono text-xs">{{ $report['gpoGuid'] }}</td>
                                    </tr>
                                @endif
                                @if ($report['gpoPath'])
                                    <tr>
                                        <td class="font-medium">Path SYSVOL</td>
                                        <td class="font-mono text-xs">{{ $report['gpoPath'] }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td class="font-medium">Template officiel</td>
                                    <td class="font-mono text-xs">{{ $report['templatePath'] }}</td>
                                </tr>
                                <tr>
                                    <td class="font-medium">Template présent ?</td>
                                    <td>
                                        @if ($report['templateExists'])
                                            <span class="badge badge-success">Oui</span>
                                        @else
                                            <span class="badge badge-error">Absent</span>
                                        @endif
                                    </td>
                                </tr>
                                @if (! empty($report['templateLastModified']))
                                    <tr>
                                        <td class="font-medium">Template mtime</td>
                                        <td class="font-mono text-xs">{{ $report['templateLastModified'] }}</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Tableau 2 : Liaisons --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h3 class="card-title text-lg flex items-center gap-2">
                        <i class="fa-solid fa-link text-info"></i>
                        Liaisons OU
                        <span class="badge badge-neutral badge-sm">{{ count($report['linkedOus'] ?? []) }}</span>
                    </h3>
                    @if (empty($report['linkedOus']))
                        <div class="alert alert-warning" data-testid="unlinked-warning">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <div>
                                <p class="font-medium">GPO non liée — aucun poste ne déclenchera `wpkg.js`.</p>
                                @if ($report['gpoGuid'])
                                    <a href="{{ route('admin.gpo.links', ['guid' => trim((string) $report['gpoGuid'], '{}')]) }}"
                                        class="btn btn-primary btn-sm mt-2" data-testid="link-now-cta">
                                        <i class="fa-solid fa-plus"></i>
                                        Lier maintenant
                                    </a>
                                @endif
                            </div>
                        </div>
                    @else
                        <ul class="text-sm space-y-1" data-testid="linked-ous">
                            @foreach ($report['linkedOus'] as $dn)
                                <li class="font-mono text-xs">
                                    <i class="fa-solid fa-folder-tree text-info"></i>
                                    {{ $dn }}
                                </li>
                            @endforeach
                        </ul>
                        @if ($report['gpoGuid'])
                            <a href="{{ route('admin.gpo.links', ['guid' => trim((string) $report['gpoGuid'], '{}')]) }}"
                                class="btn btn-ghost btn-sm mt-3">
                                <i class="fa-solid fa-pen-to-square"></i>
                                Gérer les liaisons
                            </a>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Tableau 3 : URLs serveur attendues --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h3 class="card-title text-lg flex items-center gap-2">
                        <i class="fa-solid fa-globe text-accent"></i>
                        URLs serveur attendues côté postes
                    </h3>
                    <p class="text-sm text-base-content/70">
                        Une fois la GPO publiée et liée, les postes Windows interrogent ces URLs au boot
                        (`cscript wpkg.js /server=&lt;SE4FS_NAME&gt; /profile=&lt;hostname&gt;`).
                    </p>
                    <div class="overflow-x-auto mt-2">
                        <table class="table table-sm" data-testid="urls-table">
                            <thead>
                                <tr>
                                    <th>Endpoint</th>
                                    <th>URL</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="font-medium">hosts.xml</td>
                                    <td class="font-mono text-xs break-all">{{ $report['expectedHostsXmlUrl'] }}</td>
                                </tr>
                                <tr>
                                    <td class="font-medium">profiles.xml</td>
                                    <td class="font-mono text-xs break-all">{{ $report['expectedProfilesXmlUrl'] }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Tableau 4 : Couverture Bearer --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h3 class="card-title text-lg flex items-center gap-2">
                        <i class="fa-solid fa-key text-warning"></i>
                        Couverture Bearer Phase 2 (Story 15.5)
                        <span class="badge badge-ghost badge-sm">{{ $this->bearerSummary }}</span>
                    </h3>
                    @if (! ($report['bearerTableAvailable'] ?? false))
                        <p class="text-sm text-base-content/70">
                            La table `workstation_api_secrets` (Story 15.5 Phase 2) n'est pas migrée sur ce
                            serveur. L'auth Bearer côté reports clients est en mode Phase 1 (IP allowlist
                            `EnsureLocalRequest`). Pas d'impact bloquant.
                        </p>
                    @elseif (empty($report['bearerCoverage']))
                        <p class="text-sm text-base-content/70" data-testid="bearer-empty">
                            Aucun poste à évaluer pour le moment (GPO non liée ou pas de poste actif dans
                            les OUs liées).
                        </p>
                    @else
                        @php
                            $missing = array_filter($report['bearerCoverage'], fn ($v) => $v === false);
                        @endphp
                        @if (count($missing) === 0)
                            <p class="text-sm text-success">Tous les postes liés ont un secret Bearer actif.</p>
                        @else
                            <details class="text-sm">
                                <summary class="cursor-pointer">
                                    {{ count($missing) }} poste(s) sans Bearer — voir détail
                                </summary>
                                <ul class="mt-2 ml-4 list-disc">
                                    @foreach (array_keys($missing) as $name)
                                        <li class="font-mono text-xs">{{ $name }}</li>
                                    @endforeach
                                </ul>
                                <p class="text-xs text-base-content/60 mt-2">
                                    Provisionnez via `php artisan wpkg:provision-secrets` (Story 15.5).
                                </p>
                            </details>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Messages diagnostiques --}}
            @if (! empty($report['messages']))
                <div class="card bg-base-100 shadow-sm border border-base-200">
                    <div class="card-body">
                        <h3 class="card-title text-lg flex items-center gap-2">
                            <i class="fa-solid fa-circle-info text-primary"></i>
                            Diagnostics
                        </h3>
                        <ul class="text-sm space-y-1 list-disc list-inside" data-testid="diagnostic-messages">
                            @foreach ($report['messages'] as $msg)
                                <li>{{ $msg }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        @endif
    </div>

    {{-- Modale confirmation Re-publier (D5) — inchangée --}}
    <x-molecules.modal wire:model="isPublishModalOpen" size="max-w-2xl" height="h-auto"
        title="Re-publier la GPO `se4_wpkg`" icon="fa-shield-halved text-error">
        <x-molecules.modal.section dense>
            <div class="alert alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <p class="font-medium">Cette action écrase la GPO `se4_wpkg` dans SYSVOL.</p>
                    <p class="text-sm">
                        Elle re-importe le template officiel `/usr/share/sambaedu/gpo/se4_wpkg.zip`, spécialise
                        les placeholders (`###_SE4FS_NAME_###`, etc.) et pousse l'archive sur SYSVOL via
                        `samba-tool`/`smbclient`. Au prochain reboot, les postes Windows liés appliqueront
                        le nouveau script `.cmd` startup → `cscript wpkg.js /server=&lt;SE4FS_NAME&gt;`.
                    </p>
                </div>
            </div>
            <div class="form-control mt-3">
                <label class="label cursor-pointer justify-start gap-3">
                    <input type="checkbox" wire:model.live="forceFlag" class="checkbox checkbox-sm"
                        data-testid="force-flag" />
                    <span class="label-text">Forcer même si la GPO est déjà à jour (équivalent `--force`).</span>
                </label>
            </div>
            <p class="text-xs text-base-content/60 mt-2">
                Note : aucune liaison automatique aux OUs n'est créée. Après publication, allez sur
                <code class="font-mono">/admin/settings/gpo/{guid}/links</code> pour lier la GPO aux OUs ciblées.
            </p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="closePublishModal"
                data-testid="modal-cancel">
                Annuler
            </button>
            <button type="button" class="btn btn-error btn-sm" wire:click="confirmPublish"
                wire:loading.attr="disabled" data-testid="modal-confirm-publish">
                <span wire:loading.remove wire:target="confirmPublish">
                    <i class="fa-solid fa-upload"></i>
                    Confirmer la re-publication
                </span>
                <span wire:loading wire:target="confirmPublish">
                    <i class="fa-solid fa-circle-notch fa-spin"></i>
                    Import SYSVOL en cours…
                </span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- Modale confirmation ajout CIDR allowlist (Story 15.6 / D8) --}}
    <x-molecules.modal wire:model="isAddCidrModalOpen" size="max-w-lg" height="h-auto"
        title="Confirmer l'élargissement de l'allowlist WPKG" icon="fa-shield-halved text-warning">
        <x-molecules.modal.section dense>
            <div class="alert alert-warning" data-testid="cidr-modal-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <p class="font-medium">Ouverture d'accès aux endpoints WPKG non authentifiés</p>
                    <p class="text-sm mt-1">
                        L'ajout de <code class="font-mono font-bold">{{ $pendingCidr }}</code> autorisera toutes les
                        machines de ce périmètre à consommer <code>/wpkg/winget_out.php</code> et
                        <code>/wpkg/linux_out.php</code>.
                    </p>
                    <p class="text-sm mt-1">
                        Ces endpoints <strong>ne sont pas authentifiés</strong> (auth iso-legacy : postes non enrôlés
                        au boot/install). L'allowlist IP est la frontière de sécurité primaire.
                    </p>
                </div>
            </div>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="closeAddCidrModal"
                data-testid="cidr-modal-cancel">
                Annuler
            </button>
            <button type="button" class="btn btn-warning btn-sm" wire:click="confirmAddCidr"
                data-testid="cidr-modal-confirm">
                <i class="fa-solid fa-shield-halved"></i>
                Confirmer l'ajout
            </button>
        </x-slot:footer>
    </x-molecules.modal>

</x-organisms.page>
