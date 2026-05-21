<?php

use App\Components\Traits\WithToasts;
use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Ipxe\Iso\Exceptions\WindowsIsoLockException;
use App\Ipxe\Iso\Exceptions\WindowsIsoValidationException;
use App\Ipxe\Iso\Services\WindowsIsoDownloadOrchestrator;
use App\Ipxe\Iso\Services\WindowsIsoSourcesReader;
use App\Ipxe\Iso\Services\WindowsIsoUrlValidator;
use App\Models\WindowsIsoDownload;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 3.6 — D2 / D10 / AC5.* — Page admin web SE5 Livewire SFC qui porte
 * `sambaedu/ipxe/Win10/win_iso.php` (110 LOC legacy).
 *
 * Convention iso `/admin/sync-from-ad` :
 *  - Trait `WithToasts` pour les notifs success/error/info.
 *  - Polling Livewire `wire:poll.60s` conditionnel sur la card "en cours".
 *  - Modale réutilisable `<x-molecules.confirm-modal>` pour la confirmation.
 *
 * Sécurité (D3) : middleware `sambaedu.auth + sambaedu.admin + can:server.admin`
 * en amont (routes/web.php) + double-check dans `mount()` (defense in depth
 * parité Wine SE5).
 *
 * Validation URL : 2 couches (D5) :
 *  1. Couche 1 — règles Livewire `rules()` (regex basique + max:2048).
 *  2. Couche 2 — service `WindowsIsoUrlValidator` (allowlist host + extract
 *     iso_name + détection version) déléguée à `WindowsIsoDownloadOrchestrator`.
 */
new #[Title('Gestion ISO Windows - SE5')] class extends Component {
    use WithToasts;

    // ---- État du formulaire URL ---------------------------------------------

    /** URL Microsoft saisie par l'admin. */
    public string $url = '';

    // ---- État de la page ----------------------------------------------------

    /**
     * Sources déployées sous `/var/sambaedu/unattended/install/os/Win{10,11}{,-old}/`.
     *
     * @var array{
     *     win10: array{current: ?string, old: ?string},
     *     win11: array{current: ?string, old: ?string},
     * }
     */
    public array $sources = [
        'win10' => ['current' => null, 'old' => null],
        'win11' => ['current' => null, 'old' => null],
    ];

    /**
     * Historique des 10 derniers téléchargements (sérialisé pour Livewire).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $downloads = [];

    /**
     * Téléchargement en cours (non-terminal) — null si aucun. Pilote le
     * polling Livewire conditionnel `wire:poll.60s` sur la card dédiée.
     *
     * @var array<string, mixed>|null
     */
    public ?array $currentRunning = null;

    /**
     * Permet de notifier UNE seule fois en cas de transition vers un état
     * terminal pendant le polling. Stocké sous forme `iso_name:status`.
     */
    public ?string $lastTerminalNotified = null;

    // ---- Authentification garde-fou ----------------------------------------

    protected function rules(): array
    {
        return [
            'url' => [
                'required',
                'string',
                'max:2048',
                // #6 (post-review 2026-05-21) — tightening : référence la
                // constante publique `WindowsIsoUrlValidator::URL_PATH_REGEX`
                // au lieu d'une regex laxiste `.iso` générique. Source unique
                // partagée avec la couche 2 service (= drift impossible).
                // La couche 2 reste responsable de l'allowlist host + de la
                // détection version + des garde-fous anti-control-char.
                'regex:' . WindowsIsoUrlValidator::URL_PATH_REGEX,
            ],
        ];
    }

    public function mount(WindowsIsoSourcesReader $sourcesReader): void
    {
        abort_unless(
            Auth::check() && Auth::user()?->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $this->refreshData($sourcesReader);
    }

    /**
     * Recharge filesystem sources + historique + current_running. Appelé
     * en `mount()` + après chaque submit/cancel + à chaque tick polling.
     */
    public function refreshData(?WindowsIsoSourcesReader $sourcesReader = null): void
    {
        $sourcesReader ??= app(WindowsIsoSourcesReader::class);
        $this->sources = $sourcesReader->list();

        // Notification "fin" si transition observée pendant le polling.
        $previousRunning = $this->currentRunning;

        /** @var WindowsIsoDownload|null $current */
        $current = WindowsIsoDownload::query()
            ->whereIn('status', [
                WindowsIsoDownloadStatus::Pending->value,
                WindowsIsoDownloadStatus::Downloading->value,
                WindowsIsoDownloadStatus::Extracting->value,
            ])
            ->latest('id')
            ->first();

        $this->currentRunning = $current ? $this->serializeDownload($current) : null;

        // Détection transition terminal pendant polling — toast UNIQUE.
        if ($previousRunning !== null && $this->currentRunning === null) {
            /** @var WindowsIsoDownload|null $lastById */
            $lastById = WindowsIsoDownload::query()->find($previousRunning['id']);
            $terminalKey = $lastById ? ($lastById->iso_name . ':' . $lastById->status->value) : null;
            if ($lastById && $terminalKey !== null && $terminalKey !== $this->lastTerminalNotified) {
                $this->lastTerminalNotified = $terminalKey;
                match ($lastById->status) {
                    WindowsIsoDownloadStatus::Success   => $this->toastSuccess("ISO « {$lastById->iso_name} » déployée avec succès."),
                    WindowsIsoDownloadStatus::Failed    => $this->toastError("Échec du téléchargement de « {$lastById->iso_name} » (exit {$lastById->exit_code}). Consultez l'historique."),
                    WindowsIsoDownloadStatus::Cancelled => $this->toastInfo("Téléchargement de « {$lastById->iso_name} » annulé."),
                    default => null,
                };
            }
        }

        $historyLimit = (int) config('ipxe.iso_management.history_limit', 10);
        $this->downloads = WindowsIsoDownload::query()
            ->with('initiatedBy:id,login,fullname')
            ->orderByDesc('created_at')
            ->limit($historyLimit)
            ->get()
            ->map(fn (WindowsIsoDownload $d): array => $this->serializeDownload($d))
            ->all();
    }

    /**
     * Première étape de soumission — ouvre la modale de confirmation.
     * (UX critique : le download remplace la version courante Win{N} →
     * action irréversible — AC5.6.)
     *
     * #6 (post-review 2026-05-21) — le re-check regex `submitDownload`
     * historique est SUPPRIMÉ : la couche 1 Livewire (`rules()` avec
     * `WindowsIsoUrlValidator::URL_PATH_REGEX`) valide déjà strictement
     * que le path est `Win(10|11)*.iso`. On extrait uniquement le `iso_name`
     * + le `version_num` via la constante `ISO_NAME_REGEX` pour le message
     * de confirmation modale — la validation finale (allowlist host,
     * anti-control-char, etc.) reste à la couche 2 service.
     */
    public function submitDownload(): void
    {
        $this->validate();

        $path = (string) (parse_url($this->url, PHP_URL_PATH) ?: '');
        // La regex `rules()` vient de valider l'URL — `ISO_NAME_REGEX` matche
        // donc obligatoirement. Garde-fou ultime : si le path échoue (cas
        // exotique parse_url), on stop avec un toast utilisateur clair.
        if (! preg_match(WindowsIsoUrlValidator::ISO_NAME_REGEX, $path, $m)) {
            $this->toastError("URL invalide : le fichier doit s'appeler `Win10*.iso` ou `Win11*.iso`.");

            return;
        }
        $isoName = $m[1];
        // L'extraction de la version est garantie par la regex ISO_NAME_REGEX
        // qui capture déjà 10|11. Re-extract pour le message modale.
        preg_match('/^Win(10|11)/', $isoName, $mv);
        $versionNum = $mv[1] ?? '11';

        $this->dispatch(
            'open-confirm-modal',
            title: 'Confirmer le téléchargement de l\'ISO Windows',
            message: 'Êtes-vous sûr de vouloir télécharger « ' . $isoName . ' » ? '
                . 'Cela remplacera la version courante de Windows ' . $versionNum . ' (l\'ancienne sera renommée en `-old`). '
                . 'L\'opération dure ~30 minutes à 2 heures selon le réseau.',
            confirmText: 'Lancer le téléchargement',
            cancelText: 'Annuler',
            variant: 'warning',
            method: 'confirmDownload',
            params: [],
            wireId: $this->getId(),
        );
    }

    /**
     * Confirmation modale — délègue à l'orchestrator.
     */
    public function confirmDownload(WindowsIsoDownloadOrchestrator $orchestrator): void
    {
        if (! (bool) config('ipxe.iso_management.enabled', true)) {
            $this->toastError("La gestion des ISO Windows est désactivée (config `ipxe.iso_management.enabled=false`).");

            return;
        }

        try {
            $download = $orchestrator->submit(
                url: $this->url,
                initiatedByUserId: (int) Auth::id(),
                hostIp: (string) (request()->ip() ?? ''),
            );

            $this->toastSuccess("Téléchargement lancé pour « {$download->iso_name} » — suivi en bas de page.");
            $this->url = '';
            $this->lastTerminalNotified = null;  // permet de re-notifier la fin du nouveau download
            $this->refreshData();
        } catch (WindowsIsoValidationException $e) {
            $this->toastError($e->getMessage(), 'URL invalide');
        } catch (WindowsIsoLockException $e) {
            $this->toastError($e->getMessage(), 'Téléchargement déjà en cours');
        } catch (\Throwable $e) {
            // Pas d'exposition du détail interne (path, stack, etc.).
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->error('ipxe.iso.submit.exception', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'user_id'   => Auth::id(),
            ]);
            $this->toastError("Erreur inattendue lors du lancement du téléchargement. Consultez les logs.");
        }
    }

    /**
     * Annule un téléchargement non-terminal (idempotent — no-op si déjà
     * terminal).
     */
    public function cancelDownload(int $id, WindowsIsoDownloadOrchestrator $orchestrator): void
    {
        /** @var WindowsIsoDownload|null $download */
        $download = WindowsIsoDownload::query()->find($id);
        if ($download === null) {
            $this->toastError("Téléchargement introuvable.");

            return;
        }

        if ($download->isTerminal()) {
            $this->toastInfo("Le téléchargement est déjà terminé (" . $download->status->label() . ").");

            return;
        }

        $cancelled = $orchestrator->cancel($download, (int) Auth::id());

        if ($cancelled) {
            $this->toastInfo("Téléchargement annulé. Le process en cours continuera jusqu'à sa fin naturelle.");
        }

        $this->refreshData();
    }

    /**
     * Méthode publique pour le polling Livewire — alias `refreshData()`.
     */
    public function refresh(): void
    {
        $this->refreshData();
    }

    /**
     * Sérialisation Livewire-safe d'une row `WindowsIsoDownload` (les
     * propriétés Livewire doivent être array/scalar, pas des Models
     * Eloquent — patron iso 16.9).
     *
     * @return array<string, mixed>
     */
    private function serializeDownload(WindowsIsoDownload $d): array
    {
        return [
            'id'             => $d->id,
            'version'        => $d->version,
            'iso_name'       => $d->iso_name,
            'source_url'     => $d->source_url,
            'status'         => $d->status->value,
            'status_label'   => $d->status->label(),
            'status_badge'   => $d->status->badgeClass(),
            'is_running'     => $d->isRunning(),
            'is_terminal'    => $d->isTerminal(),
            'started_at'     => $d->started_at?->format('Y-m-d H:i:s'),
            'completed_at'   => $d->completed_at?->format('Y-m-d H:i:s'),
            'exit_code'      => $d->exit_code,
            'error'          => $d->error,
            'initiated_by'   => $d->initiatedBy ? ($d->initiatedBy->fullname ?? $d->initiatedBy->login) : null,
            'host_ip'        => $d->host_ip,
            'created_at_str' => $d->created_at?->format('Y-m-d H:i'),
        ];
    }
};
?>

<x-organisms.page title="Gestion ISO Windows"
    description="Téléchargement et déploiement des sources d'installation Windows (Win10 / Win11) consommées par le firmware iPXE.">

    <x-slot:actions>
        <div class="flex gap-2">
            <button type="button" wire:click="refresh" class="btn btn-outline btn-sm" wire:loading.attr="disabled" wire:target="refresh">
                <span wire:loading.remove wire:target="refresh">
                    <i class="fa-solid fa-rotate-right"></i>
                    Rafraichir
                </span>
                <span wire:loading wire:target="refresh">
                    <span class="loading loading-spinner loading-xs"></span>
                </span>
            </button>
        </div>
    </x-slot:actions>

    <div id="ipxe-iso-windows" class="space-y-6">

        {{-- ============================================================
             Bandeau d'info
             ============================================================ --}}
        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div class="text-sm space-y-1">
                <p class="font-medium">Mise en place des sources d'installation Windows</p>
                <p class="opacity-90">
                    Cette page permet de télécharger une nouvelle ISO Microsoft (Windows 10 / Windows 11) puis de
                    l'extraire dans <code>/var/sambaedu/unattended/install/os/Win{10,11}/</code>. La version courante est
                    automatiquement archivée en <code>Win{N}-old</code>. Le téléchargement et l'extraction se font en
                    arrière-plan ; vous serez notifié à la fin via un toast.
                </p>
                <p class="opacity-90 text-xs">
                    Récupérez l'URL d'une ISO sur le
                    <a href="https://www.microsoft.com/fr-fr/software-download/windows11" target="_blank" rel="noopener" class="link link-hover">
                        site officiel Microsoft (Windows 11)
                    </a>
                    ou
                    <a href="https://www.microsoft.com/fr-fr/software-download/windows10" target="_blank" rel="noopener" class="link link-hover">
                        Windows 10
                    </a>.
                </p>
            </div>
        </div>

        {{-- ============================================================
             Card "Versions Windows déployées" (sources)
             ============================================================ --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body space-y-2">
                <h2 class="card-title text-lg">
                    <i class="fa-solid fa-server text-primary"></i>
                    Versions Windows déployées
                </h2>
                <p class="text-sm text-base-content/70">
                    Sources actuellement disponibles pour l'installation iPXE Win10/Win11.
                </p>

                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Slot</th>
                                <th>ISO source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge badge-info">Windows 10</span></td>
                                <td>Courante</td>
                                <td>
                                    @if ($sources['win10']['current'])
                                        <span class="font-mono text-sm">{{ $sources['win10']['current'] }}</span>
                                    @else
                                        <span class="badge badge-ghost">non déployée</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-info">Windows 10</span></td>
                                <td>Ancienne</td>
                                <td>
                                    @if ($sources['win10']['old'])
                                        <span class="font-mono text-sm opacity-70">{{ $sources['win10']['old'] }}</span>
                                    @else
                                        <span class="badge badge-ghost">non déployée</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-primary">Windows 11</span></td>
                                <td>Courante</td>
                                <td>
                                    @if ($sources['win11']['current'])
                                        <span class="font-mono text-sm">{{ $sources['win11']['current'] }}</span>
                                    @else
                                        <span class="badge badge-ghost">non déployée</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-primary">Windows 11</span></td>
                                <td>Ancienne</td>
                                <td>
                                    @if ($sources['win11']['old'])
                                        <span class="font-mono text-sm opacity-70">{{ $sources['win11']['old'] }}</span>
                                    @else
                                        <span class="badge badge-ghost">non déployée</span>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ============================================================
             Card "Téléchargement en cours" — polling conditionnel
             ============================================================ --}}
        @if ($currentRunning)
            {{-- Q4 Henri 2026-05-21 : polling 60s (au lieu de 5s).
                 Décision : « sur 30 min ça ne me parait pas insensé ».
                 Réduit la charge DB ×12 (vs 5s) en respectant le UX :
                 sur un téléchargement 30 min - 2h, 60s de latence avant
                 mise à jour visuelle est acceptable. --}}
            <div class="card bg-base-100 shadow-sm border border-warning" wire:poll.60s="refresh">
                <div class="card-body space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="card-title text-lg">
                            <span class="loading loading-spinner loading-sm text-warning"></span>
                            Téléchargement en cours
                        </h2>
                        <span class="badge {{ $currentRunning['status_badge'] }}">
                            {{ $currentRunning['status_label'] }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                        <div>
                            <span class="opacity-60">ISO :</span>
                            <span class="font-mono">{{ $currentRunning['iso_name'] }}</span>
                        </div>
                        <div>
                            <span class="opacity-60">Version :</span>
                            <span>{{ $currentRunning['version'] }}</span>
                        </div>
                        <div>
                            <span class="opacity-60">Démarré :</span>
                            <span>{{ $currentRunning['started_at'] ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="opacity-60">Initié par :</span>
                            <span>{{ $currentRunning['initiated_by'] ?? '—' }}</span>
                        </div>
                    </div>

                    <div class="text-xs opacity-60">
                        Source : <span class="font-mono">{{ \Illuminate\Support\Str::limit($currentRunning['source_url'], 100) }}</span>
                    </div>

                    <div class="flex gap-2 mt-2">
                        <button type="button" wire:click="cancelDownload({{ $currentRunning['id'] }})"
                            class="btn btn-sm btn-error btn-outline"
                            wire:loading.attr="disabled" wire:target="cancelDownload">
                            <i class="fa-solid fa-ban"></i>
                            Annuler
                        </button>
                    </div>

                    <div class="alert alert-warning text-xs">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>
                            L'annulation n'interrompt PAS le process en cours (curl/install-win-iso.sh continuera
                            jusqu'à sa fin naturelle ou son timeout). Le téléchargement sera simplement marqué
                            « annulé » et ne déclenchera pas le rollover Win{N} → Win{N}-old.
                        </span>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============================================================
             Card "Nouveau téléchargement" (formulaire URL)
             ============================================================ --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body space-y-4">
                <h2 class="card-title text-lg">
                    <i class="fa-solid fa-cloud-arrow-down text-primary"></i>
                    Nouveau téléchargement
                </h2>

                @if (! config('ipxe.iso_management.enabled', true))
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>
                            La gestion des ISO Windows est désactivée
                            (<code>IPXE_ISO_MANAGEMENT_ENABLED=false</code>). Le formulaire reste affiché en
                            lecture pour information, mais le bouton « Télécharger » est désactivé.
                        </span>
                    </div>
                @endif

                <p class="text-sm text-base-content/70">
                    Copiez l'URL obtenue sur le site officiel Microsoft (allowlist :
                    <code>software-static.download.prss.microsoft.com</code>,
                    <code>software-download.microsoft.com</code>,
                    <code>download.microsoft.com</code>). Schéma <strong>HTTPS</strong> obligatoire.
                </p>

                <div class="form-control">
                    <label class="label">
                        <span class="label-text font-medium">URL de l'ISO Microsoft</span>
                    </label>
                    <input type="url" wire:model="url"
                        class="input input-bordered font-mono text-sm"
                        placeholder="https://software-static.download.prss.microsoft.com/.../Win11_24H2.iso"
                        @disabled($currentRunning !== null || ! config('ipxe.iso_management.enabled', true))
                        data-testid="iso-url-input" />
                    @error('url')
                        <label class="label">
                            <span class="label-text-alt text-error">{{ $message }}</span>
                        </label>
                    @enderror
                </div>

                <div class="flex flex-wrap gap-3 items-center">
                    <button type="button" wire:click="submitDownload"
                        class="btn btn-primary"
                        wire:loading.attr="disabled"
                        @disabled($currentRunning !== null || ! config('ipxe.iso_management.enabled', true))
                        data-testid="iso-submit-button">
                        <span wire:loading.remove wire:target="submitDownload,confirmDownload">
                            <i class="fa-solid fa-download"></i>
                            Télécharger l'ISO
                        </span>
                        <span wire:loading wire:target="submitDownload,confirmDownload">
                            <span class="loading loading-spinner loading-xs"></span>
                            Préparation…
                        </span>
                    </button>

                    @if ($currentRunning !== null)
                        <span class="text-xs text-warning">
                            <i class="fa-solid fa-info-circle"></i>
                            Un téléchargement est déjà en cours — attendez sa fin ou annulez-le.
                        </span>
                    @endif
                </div>

                <p class="text-xs text-base-content/60">
                    <i class="fa-solid fa-shield"></i>
                    Validation 2 couches : sanity check Livewire + service `WindowsIsoUrlValidator`
                    (anti-SSRF). Le processus serveur (curl + extraction) tourne en arrière-plan via
                    Laravel Queue, 1 instance vivante à la fois.
                </p>
            </div>
        </div>

        {{-- ============================================================
             Card "Historique" (10 derniers téléchargements)
             ============================================================ --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body space-y-3">
                <h2 class="card-title text-lg">
                    <i class="fa-solid fa-clock-rotate-left text-primary"></i>
                    Historique (10 derniers)
                </h2>

                @if (count($downloads) === 0)
                    <p class="text-sm text-base-content/60">
                        Aucun téléchargement enregistré pour l'instant.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Version</th>
                                    <th>ISO</th>
                                    <th>Status</th>
                                    <th>Exit</th>
                                    <th>Initié par</th>
                                    <th>Détails</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($downloads as $d)
                                    <tr>
                                        <td class="text-xs">{{ $d['created_at_str'] }}</td>
                                        <td><span class="badge badge-outline">{{ $d['version'] }}</span></td>
                                        <td class="font-mono text-xs">{{ $d['iso_name'] }}</td>
                                        <td>
                                            <span class="badge {{ $d['status_badge'] }}">{{ $d['status_label'] }}</span>
                                        </td>
                                        <td class="font-mono text-xs">
                                            {{ $d['exit_code'] === null ? '—' : $d['exit_code'] }}
                                        </td>
                                        <td class="text-xs">{{ $d['initiated_by'] ?? '—' }}</td>
                                        <td>
                                            @if ($d['error'])
                                                <div class="tooltip tooltip-left" data-tip="{{ \Illuminate\Support\Str::limit($d['error'], 200) }}">
                                                    <i class="fa-solid fa-circle-exclamation text-error"></i>
                                                </div>
                                            @endif
                                            @if ($d['is_running'])
                                                <button type="button"
                                                    wire:click="cancelDownload({{ $d['id'] }})"
                                                    class="btn btn-xs btn-error btn-outline"
                                                    wire:loading.attr="disabled" wire:target="cancelDownload">
                                                    <i class="fa-solid fa-ban"></i>
                                                </button>
                                            @endif
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

    {{-- Modale de confirmation réutilisable (cf. CLAUDE.md). --}}
    <x-molecules.confirm-modal />
</x-organisms.page>
