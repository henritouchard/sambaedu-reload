<?php

use App\Components\Traits\WithToasts;
use App\Models\SystemSetting;
use App\Wpkg\Deployment\Rules\SafeIpCidrRule;
use App\Wpkg\Deployment\Services\WpkgDeploymentSettings;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — Réglages de déploiement WPKG (Story 15.6).
 * Servie sous `/admin/settings/gpo/wpkg-deployment`.
 *
 * Story 27.14 — l'AUDIT de cohérence de la GPO `se4_wpkg` (story 16.6, via
 * `WpkgGpoSynchronizer`) a été RETIRÉ avec l'extinction du canal de config
 * legacy : la GPO `se4_wpkg` n'est plus un transport (27.5 — l'agent déclenche
 * `wpkg-client.vbs`) et les endpoints `/wpkg/hosts.xml` + `/wpkg/profiles.xml`
 * ont été supprimés (27.5). Il ne reste que la carte « Réglages de
 * déploiement » (toggle winget + allowlist IP) qui gate les endpoints WPKG
 * `linux_out`/`winget_out` (HORS scope 27.14, conservés).
 *
 * Permission : `server.admin` (middleware route + abort_unless mount).
 *
 * **PIÈGE AUDIT** : les mutations Livewire ne passent PAS par le middleware HTTP
 * → l'audit des réglages doit être émis explicitement depuis les actions
 * (Story 15.6 / AC5).
 */
new #[Title('Réglages de déploiement WPKG - SE4FS')] class extends Component {
    use WithToasts;

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

    // Story 27.14 — la partie AUDIT GPO `se4_wpkg` (story 16.6 : `audit()`,
    // `refresh()`, modale re-publish, `getSeverityClassProperty`,
    // `getBearerSummaryProperty`) a été SUPPRIMÉE avec `WpkgGpoSynchronizer` et
    // l'extinction du canal de config legacy. Seuls les réglages de déploiement
    // (toggle winget + allowlist IP) subsistent.
};
?>

<x-organisms.page title="Réglages de déploiement WPKG" :scrollable="true"
    description="Toggle canal winget + allowlist IP des endpoints WPKG (linux_out / winget_out).">

    <x-slot:actions>
        <div class="flex flex-wrap gap-2 items-center">
            <a href="{{ route('admin.gpo.index') }}" class="btn btn-ghost btn-sm">
                <i class="fa-solid fa-list"></i>
                Liste des GPOs
            </a>
        </div>
    </x-slot:actions>

    <div class="space-y-6">

        {{-- ===================================================================
             Encart « L'agent déclenche WPKG » (Story 27.5 / 27.6)
             =================================================================== --}}
        <div class="card bg-base-100 shadow-sm border border-info/40" data-testid="agent-trigger-explainer">
            <div class="card-body">
                <h2 class="card-title text-lg flex items-center gap-2">
                    <i class="fa-solid fa-robot text-info"></i>
                    L'agent déclenche WPKG
                </h2>
                <p class="text-sm text-base-content/80">
                    Le <strong>canal desired-state de l'agent</strong> (handler <code>applications</code>, moteur
                    machine/SYSTEM) est désormais le <strong>seul déclencheur</strong> de WPKG sur les postes.
                    La GPO <code>se4_wpkg</code> qui lançait <code>cscript wpkg.js</code> au boot
                    <strong>n'est plus publiée</strong> par SE5.
                </p>
                <ul class="text-sm space-y-1 list-disc list-inside text-base-content/80 mt-1">
                    <li>
                        L'agent dépose <code>profiles.xml</code> / <code>hosts.xml</code> <em>localement</em> sur le poste,
                        puis <code>wpkg-client.vbs</code> applique le <strong>bundle WPKG natif</strong> servi en statique
                        par Apache.
                    </li>
                    <li>
                        Le catalogue du bundle est régénéré automatiquement à chaque ajout/retrait d'application au
                        catalogue (source unique — Story 27.6).
                    </li>
                    <li>
                        Les sections d'audit ci-dessous (GPO résiduelle, liaisons, couverture Bearer) sont conservées
                        <strong>à titre de diagnostic</strong> : elles n'influencent plus la livraison WPKG.
                    </li>
                </ul>
            </div>
        </div>

        {{-- ===================================================================
             Carte « Réglages de déploiement » (Story 15.6) — toujours actifs
             =================================================================== --}}
        <div class="card bg-base-100 shadow-sm border border-base-300" data-testid="deployment-settings-card">
            <div class="card-body">
                <h2 class="card-title text-lg flex items-center gap-2">
                    <i class="fa-solid fa-sliders text-primary"></i>
                    Réglages de déploiement
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

    </div>
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
