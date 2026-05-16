<?php

use App\Components\Traits\WithToasts;
use App\Gpo\Dto\WpkgGpoSyncReport;
use App\Gpo\Services\WpkgGpoSynchronizer;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — Hook GPO ↔ WPKG (Story 16.6 + Story 16.9).
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
 *
 * Permission : `server.admin` (D8 — middleware route + abort_unless mount).
 */
new #[Title('Hook GPO ↔ WPKG - SE4FS')] class extends Component {
    use WithToasts;

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

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $this->audit();
    }

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
        $force = $this->forceFlag;
        $this->isPublishing = true;
        $this->isPublishModalOpen = false;

        try {
            $r = $this->sync->publish($force);
            $this->report = $r->toArray();
            $this->expectedHostsXmlUrl = $r->expectedHostsXmlUrl;
            $this->expectedProfilesXmlUrl = $r->expectedProfilesXmlUrl;
            $this->toast(
                'success',
                'GPO re-publiée',
                'Statut : ' . strtoupper($r->severity->value) . '. Le prochain `gpupdate /force` poste propage les changements.',
            );
        } catch (\Throwable $e) {
            $this->toast('error', 'Échec de re-publication', $e->getMessage());
            $this->audit(); // refresh état AD réel.
        } finally {
            $this->isPublishing = false;
            $this->forceFlag = false;
        }
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

    {{-- Modale confirmation Re-publier (D5) --}}
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

</x-organisms.page>
