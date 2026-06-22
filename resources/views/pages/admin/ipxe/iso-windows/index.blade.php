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

    // ---- Dépôt manuel (upload chunké) --------------------------------------

    /**
     * Étape 1 du dépôt manuel : pré-validation serveur (nom, version, taille,
     * feature, pas d'opération en cours) puis ouverture de la modale de
     * confirmation (le fichier réel reste côté navigateur — l'upload chunké
     * démarre après confirmation via `beginUpload()` → event JS).
     */
    public function openUploadConfirm(string $filename, int $sizeBytes, string $version): void
    {
        if (! (bool) config('ipxe.iso_management.upload_enabled', true)
            || ! (bool) config('ipxe.iso_management.enabled', true)) {
            $this->toastError("Le dépôt manuel d'ISO est désactivé.");

            return;
        }
        if ($this->currentRunning !== null) {
            $this->toastError("Une opération ISO est déjà en cours — attendez sa fin ou annulez-la.");

            return;
        }

        try {
            $validated = app(WindowsIsoUrlValidator::class)->validateUploadFilename($filename, $version);
        } catch (WindowsIsoValidationException $e) {
            $this->toastError($e->getMessage(), 'Dépôt invalide');

            return;
        }

        $maxBytes = (int) config('ipxe.iso_management.upload_max_total_bytes', 10 * 1024 * 1024 * 1024);
        if ($sizeBytes < 1 || $sizeBytes > $maxBytes) {
            $maxGo = round($maxBytes / (1024 * 1024 * 1024), 1);
            $this->toastError("Fichier vide ou trop volumineux (limite {$maxGo} Go).");

            return;
        }

        $sizeGo = round($sizeBytes / (1024 * 1024 * 1024), 2);
        $this->dispatch(
            'open-confirm-modal',
            title: 'Confirmer le dépôt de l\'ISO Windows',
            message: 'Déposer « ' . $validated['iso_name'] . ' » (' . $sizeGo . ' Go) comme source Windows '
                . $validated['version_num'] . ' ? Le fichier sera téléversé puis extrait, remplaçant la version '
                . 'courante (l\'ancienne sera renommée en `-old`).',
            confirmText: 'Téléverser et déployer',
            cancelText: 'Annuler',
            variant: 'warning',
            method: 'beginUpload',
            params: [],
            wireId: $this->getId(),
        );
    }

    /**
     * Confirmation modale du dépôt — signale au front (event JS) de démarrer
     * l'upload chunké du fichier sélectionné.
     */
    public function beginUpload(): void
    {
        if ($this->currentRunning !== null) {
            $this->toastError("Une opération ISO est déjà en cours.");

            return;
        }

        $this->dispatch('iso-start-upload');
    }

    /**
     * Étape finale du dépôt manuel — appelée par le JS une fois TOUS les
     * chunks téléversés. Vérifie le `.part` réassemblé puis délègue à
     * l'orchestrator (rename atomique + dispatch Job extraction).
     */
    public function finalizeUpload(string $uploadId, string $version, WindowsIsoDownloadOrchestrator $orchestrator): void
    {
        if (! (bool) config('ipxe.iso_management.upload_enabled', true)
            || ! (bool) config('ipxe.iso_management.enabled', true)) {
            $this->toastError("Le dépôt manuel d'ISO est désactivé.");

            return;
        }

        // Defense in depth : `uploadId` DOIT être un UUID (anti path-traversal).
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uploadId) !== 1) {
            $this->toastError("Identifiant de dépôt invalide.");

            return;
        }

        $dir      = rtrim((string) config('ipxe.iso_management.upload_tmp_path', storage_path('install/iso/.uploads')), '/');
        $partPath = $dir . '/' . $uploadId . '.part';
        $metaPath = $dir . '/' . $uploadId . '.json';

        $metaRaw = is_file($metaPath) ? @file_get_contents($metaPath) : false;
        $meta    = $metaRaw !== false ? json_decode((string) $metaRaw, true) : null;
        if (! is_array($meta) || ! is_file($partPath)) {
            $this->toastError("Dépôt introuvable ou incomplet — relancez le dépôt.");

            return;
        }
        $total    = (int) ($meta['totalChunks'] ?? 0);
        $received = (int) ($meta['received'] ?? 0);
        if ($total < 1 || $received < $total) {
            $this->toastError("Le dépôt n'est pas complet — relancez le dépôt.");

            return;
        }

        try {
            $download = $orchestrator->submitUpload(
                assembledPath: $partPath,
                filename: (string) ($meta['filename'] ?? ''),
                version: $version,
                initiatedByUserId: (int) Auth::id(),
                hostIp: (string) (request()->ip() ?? ''),
            );

            @unlink($metaPath);  // le `.part` a été renommé par l'orchestrator.
            $this->toastSuccess("Dépôt accepté pour « {$download->iso_name} » — extraction lancée, suivi en bas de page.");
            $this->lastTerminalNotified = null;
            $this->dispatch('iso-upload-reset');
            $this->refreshData();
        } catch (WindowsIsoValidationException $e) {
            $this->toastError($e->getMessage(), 'Dépôt invalide');
        } catch (WindowsIsoLockException $e) {
            $this->toastError($e->getMessage(), 'Opération déjà en cours');
        } catch (\Throwable $e) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->error('ipxe.iso.upload.finalize.exception', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'user_id'   => Auth::id(),
            ]);
            $this->toastError("Erreur inattendue lors de la finalisation du dépôt. Consultez les logs.");
        }
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
            'source'         => $d->source,
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
                        Source :
                        @if (($currentRunning['source'] ?? 'url') === 'upload')
                            <span class="badge badge-ghost badge-sm"><i class="fa-solid fa-upload"></i> Fichier déposé</span>
                        @else
                            <span class="font-mono">{{ \Illuminate\Support\Str::limit($currentRunning['source_url'] ?? '', 100) }}</span>
                        @endif
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
             Card "Nouvelle source Windows" — URL Microsoft OU dépôt fichier
             (onglets, switch client Alpine).
             ============================================================ --}}
        @php($urlEnabled = config('ipxe.iso_management.enabled', true))
        @php($uploadEnabled = config('ipxe.iso_management.upload_enabled', true) && config('ipxe.iso_management.enabled', true))
        @php($uploadMaxGo = round(((int) config('ipxe.iso_management.upload_max_total_bytes', 10 * 1024 * 1024 * 1024)) / (1024 * 1024 * 1024), 1))
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body space-y-4" x-data="{ mode: 'url' }">
                <h2 class="card-title text-lg">
                    <i class="fa-solid fa-plus text-primary"></i>
                    Nouvelle source Windows
                </h2>

                @if (! $urlEnabled)
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>La gestion des ISO Windows est désactivée (<code>IPXE_ISO_MANAGEMENT_ENABLED=false</code>).</span>
                    </div>
                @endif

                {{-- Bandeau d'état serveur partagé (hors wire:ignore — reflète le polling). --}}
                @if ($currentRunning !== null)
                    <div class="alert alert-warning text-xs">
                        <i class="fa-solid fa-info-circle"></i>
                        <span>Une opération ISO est déjà en cours — attendez sa fin ou annulez-la avant d'en lancer une nouvelle.</span>
                    </div>
                @endif

                {{-- Onglets URL / dépôt fichier. --}}
                <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit">
                    <button type="button" role="tab" class="tab" :class="mode === 'url' ? 'tab-active' : ''" @click="mode = 'url'">
                        <i class="fa-solid fa-link mr-1"></i> Par URL Microsoft
                    </button>
                    <button type="button" role="tab" class="tab" :class="mode === 'upload' ? 'tab-active' : ''" @click="mode = 'upload'">
                        <i class="fa-solid fa-upload mr-1"></i> Déposer un fichier
                    </button>
                </div>

                {{-- ---- Panneau URL ----------------------------------------------- --}}
                <div x-show="mode === 'url'" class="space-y-4">
                    <p class="text-sm text-base-content/70">
                        Copiez l'URL obtenue sur le site officiel Microsoft (allowlist :
                        <code>*.download.prss.microsoft.com</code>,
                        <code>software-download.microsoft.com</code>,
                        <code>download.microsoft.com</code>). Schéma <strong>HTTPS</strong> obligatoire.
                        La query string signée (<code>?t=…</code>) est conservée pour le téléchargement.
                    </p>

                    <div class="form-control">
                        <label class="label">
                            <span class="label-text font-medium">URL de l'ISO Microsoft</span>
                        </label>
                        <input type="url" wire:model="url"
                            class="input input-bordered font-mono text-sm"
                            placeholder="https://software-static.download.prss.microsoft.com/.../Win11_24H2.iso"
                            @disabled($currentRunning !== null || ! $urlEnabled)
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
                            @disabled($currentRunning !== null || ! $urlEnabled)
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
                    </div>

                    <p class="text-xs text-base-content/60">
                        <i class="fa-solid fa-shield"></i>
                        Validation 2 couches : sanity check Livewire + service `WindowsIsoUrlValidator`
                        (anti-SSRF). Le téléchargement (curl + extraction) tourne en arrière-plan via Laravel Queue.
                    </p>
                </div>

                {{-- ---- Panneau dépôt fichier (upload chunké) --------------------- --}}
                {{-- `style="display:none"` initial : évite le flash avant init Alpine
                     (pas de support x-cloak global). x-show prend le relais ensuite. --}}
                <div x-show="mode === 'upload'" style="display:none" class="space-y-4">
                    @if (! $uploadEnabled)
                        <div class="alert alert-warning">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>Le dépôt manuel d'ISO est désactivé (<code>IPXE_ISO_UPLOAD_ENABLED=false</code>).</span>
                        </div>
                    @endif

                    <p class="text-sm text-base-content/70">
                        Déposez directement le fichier ISO (jusqu'à {{ $uploadMaxGo }} Go). Le fichier est téléversé
                        par morceaux (reprise automatique en cas de coupure) puis extrait comme un téléchargement
                        classique. Choisissez la version Windows cible.
                    </p>

                    {{-- wire:ignore : un re-render Livewire (Rafraichir, polling) ne doit
                         pas réinitialiser la barre de progression d'un upload en cours. --}}
                    <div wire:ignore class="space-y-3" @if (! $uploadEnabled) style="opacity:.5;pointer-events:none" @endif>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div class="form-control md:col-span-1">
                                <label class="label"><span class="label-text font-medium">Version cible</span></label>
                                <select id="iso-up-version" class="select select-bordered">
                                    <option value="Win11">Windows 11</option>
                                    <option value="Win10">Windows 10</option>
                                </select>
                            </div>
                            <div class="md:col-span-2 flex items-end">
                                <p class="text-xs text-base-content/60">
                                    <i class="fa-solid fa-shield"></i>
                                    Le nom de fichier doit se terminer par <code>.iso</code> (caractères
                                    <code>A-Z a-z 0-9 . _ -</code>). La version courante sera archivée en <code>-old</code>.
                                </p>
                            </div>
                        </div>

                        <div id="iso-up-drop"
                            class="border-2 border-dashed border-base-300 rounded-lg p-6 text-center cursor-pointer hover:border-primary transition-colors">
                            <i class="fa-solid fa-cloud-arrow-up text-3xl text-base-content/40"></i>
                            <p class="mt-2 text-sm">Glissez l'ISO ici ou <span class="link link-primary">parcourez</span></p>
                            <p id="iso-up-filename" class="mt-1 text-xs font-mono text-base-content/70"></p>
                            <input id="iso-up-input" type="file" accept=".iso,application/octet-stream" class="hidden" />
                        </div>

                        {{-- Barre de progression --}}
                        <div id="iso-up-progress" class="hidden space-y-1">
                            <div class="flex justify-between text-xs">
                                <span id="iso-up-phase">Téléversement…</span>
                                <span id="iso-up-pct">0 %</span>
                            </div>
                            <progress id="iso-up-bar" class="progress progress-primary w-full" value="0" max="100"></progress>
                        </div>

                        <p id="iso-up-status" class="text-xs hidden"></p>

                        <div>
                            <button id="iso-up-start" type="button" class="btn btn-primary" disabled>
                                <i class="fa-solid fa-upload"></i>
                                Téléverser et déployer
                            </button>
                        </div>
                    </div>
                </div>
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
                                        <td class="font-mono text-xs">
                                            {{ $d['iso_name'] }}
                                            @if (($d['source'] ?? 'url') === 'upload')
                                                <span class="tooltip" data-tip="Fichier déposé"><i class="fa-solid fa-upload text-base-content/40 ml-1"></i></span>
                                            @endif
                                        </td>
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

    {{-- Pas de modale locale ici. Le layout global (layouts/app.blade.php) rend
         deja une <x-molecules.confirm-modal /> (id confirm-modal-dialog). En rendre
         une seconde sur la page creait un DOUBLON d id qui cassait la fermeture.
         Le dispatch open-confirm-modal est un event window : la modale globale le
         capte et s ouvre, quel que soit son emplacement DOM. --}}

    {{-- ============================================================
         Uploader chunké (dépôt manuel d'ISO).
         Pattern projet : <script> + @js() + Livewire.find(id).call(...)
         (cf. confirm-modal). Découpe le fichier en chunks POSTés en raw
         octet-stream au controller, puis appelle finalizeUpload().
         ============================================================ --}}
    <script>
        (function () {
            const UPLOAD_URL = @js(route('admin.ipxe.iso-windows.upload-chunk'));
            const CHUNK      = @js((int) config('ipxe.iso_management.upload_chunk_bytes', 5 * 1024 * 1024));
            const MAX_TOTAL  = @js((int) config('ipxe.iso_management.upload_max_total_bytes', 10 * 1024 * 1024 * 1024));
            const WIRE_ID    = @js($this->getId());

            const $ = (id) => document.getElementById(id);
            const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
            const wire = () => (window.Livewire ? window.Livewire.find(WIRE_ID) : null);
            const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const uuid = () => (crypto.randomUUID
                ? crypto.randomUUID()
                : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                    const r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
                    return v.toString(16);
                }));

            function fmtBytes(b) {
                if (b >= 1024 * 1024 * 1024) return (b / (1024 * 1024 * 1024)).toFixed(2) + ' Go';
                if (b >= 1024 * 1024) return (b / (1024 * 1024)).toFixed(1) + ' Mo';
                return Math.round(b / 1024) + ' Ko';
            }

            let selectedFile = null;
            let uploading = false;

            function setStatus(msg, isError) {
                const s = $('iso-up-status');
                if (!s) return;
                s.textContent = msg || '';
                s.classList.toggle('hidden', !msg);
                s.classList.toggle('text-error', !!isError);
                s.classList.toggle('text-base-content/70', !isError);
            }

            function setProgress(frac, received, total) {
                const wrap = $('iso-up-progress'), bar = $('iso-up-bar'), pct = $('iso-up-pct');
                if (!wrap) return;
                const p = Math.max(0, Math.min(100, Math.round(frac * 100)));
                wrap.classList.toggle('hidden', frac <= 0 && !uploading);
                if (bar) bar.value = p;
                if (pct) pct.textContent = total ? (p + ' % (' + received + '/' + total + ')') : (p + ' %');
            }

            function setControls(disabled) {
                ['iso-up-start', 'iso-up-input', 'iso-up-version'].forEach((id) => {
                    const e = $(id);
                    if (e) e.disabled = disabled;
                });
                const drop = $('iso-up-drop');
                if (drop) drop.style.pointerEvents = disabled ? 'none' : '';
            }

            function resetUi() {
                selectedFile = null;
                uploading = false;
                const input = $('iso-up-input');
                if (input) input.value = '';
                const fn = $('iso-up-filename');
                if (fn) fn.textContent = '';
                setProgress(0, 0, 0);
                const wrap = $('iso-up-progress');
                if (wrap) wrap.classList.add('hidden');
                setStatus('', false);
                setControls(false);
                const start = $('iso-up-start');
                if (start) start.disabled = true;
            }

            function onPick(file) {
                if (!file) return;
                selectedFile = file;
                const fn = $('iso-up-filename');
                if (fn) fn.textContent = file.name + ' — ' + fmtBytes(file.size);
                setProgress(0, 0, 0);
                $('iso-up-progress')?.classList.add('hidden');
                setStatus('', false);
                const start = $('iso-up-start');
                if (start) start.disabled = false;
            }

            async function postChunk(qs, blob) {
                let attempt = 0;
                while (true) {
                    attempt++;
                    try {
                        const r = await fetch(UPLOAD_URL + '?' + qs.toString(), {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/octet-stream' },
                            body: blob,
                        });
                        let body = {};
                        try { body = await r.json(); } catch (_) {}
                        if (r.status >= 500 && attempt < 5) { await sleep(800 * attempt); continue; }
                        return { ok: r.ok, status: r.status, body };
                    } catch (netErr) {
                        if (attempt >= 6) throw netErr;
                        await sleep(800 * attempt);
                    }
                }
            }

            async function doUpload() {
                if (!selectedFile || uploading) return;
                uploading = true;
                setControls(true);
                const file = selectedFile;
                const version = $('iso-up-version')?.value || 'Win11';
                const uploadId = uuid();
                const total = Math.max(1, Math.ceil(file.size / CHUNK));
                let received = 0;
                setProgress(0, 0, total);
                setStatus('Téléversement en cours…', false);
                try {
                    while (received < total) {
                        const i = received;
                        const startByte = i * CHUNK;
                        const blob = file.slice(startByte, Math.min(startByte + CHUNK, file.size));
                        const qs = new URLSearchParams({
                            uploadId, index: i, total, chunkSize: CHUNK, filename: file.name, version,
                        });
                        const resp = await postChunk(qs, blob);
                        if (resp.status === 409 && Number.isInteger(resp.body.received)) {
                            received = resp.body.received;
                            continue;
                        }
                        if (!resp.ok || !resp.body.ok) {
                            throw new Error(resp.body.error || ('HTTP ' + resp.status));
                        }
                        received = Number.isInteger(resp.body.received) ? resp.body.received : received + 1;
                        setProgress(received / total, received, total);
                        if (resp.body.complete) break;
                    }
                    setStatus('Finalisation…', false);
                    const w = wire();
                    if (w) await w.call('finalizeUpload', uploadId, version);
                    // En cas de succès, le serveur émet `iso-upload-reset` (→ resetUi).
                    // Sinon (toast d'erreur serveur), on réactive les contrôles.
                    uploading = false;
                    setControls(false);
                } catch (e) {
                    setStatus('Échec du dépôt : ' + (e && e.message ? e.message : e), true);
                    uploading = false;
                    setControls(false);
                }
            }

            function bind() {
                const input = $('iso-up-input');
                const drop = $('iso-up-drop');
                const start = $('iso-up-start');
                if (!input || !start || start.dataset.bound) return;
                start.dataset.bound = '1';

                input.addEventListener('change', (e) => onPick(e.target.files[0]));
                if (drop) {
                    drop.addEventListener('click', () => input.click());
                    ['dragover', 'dragenter'].forEach((ev) => drop.addEventListener(ev, (e) => {
                        e.preventDefault(); drop.classList.add('border-primary');
                    }));
                    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => {
                        e.preventDefault(); drop.classList.remove('border-primary');
                    }));
                    drop.addEventListener('drop', (e) => { if (e.dataTransfer.files[0]) onPick(e.dataTransfer.files[0]); });
                }
                start.addEventListener('click', () => {
                    if (uploading) return;
                    if (!selectedFile) { setStatus('Sélectionnez d\'abord un fichier ISO.', true); return; }
                    if (selectedFile.size > MAX_TOTAL) { setStatus('Fichier trop volumineux (limite ' + fmtBytes(MAX_TOTAL) + ').', true); return; }
                    const w = wire();
                    if (w && w.get && w.get('currentRunning')) { setStatus('Une opération ISO est déjà en cours.', true); return; }
                    const version = $('iso-up-version')?.value || 'Win11';
                    // Pré-validation serveur + ouverture de la modale de confirmation.
                    if (w) w.call('openUploadConfirm', selectedFile.name, selectedFile.size, version);
                });
            }

            // Le dernier rendu gagne : on stocke les handlers sur window pour
            // éviter les closures obsolètes après une navigation wire:navigate
            // (les listeners window, eux, ne sont posés qu'une fois).
            window.__isoUpload = { doUpload, resetUi };
            if (!window.__isoUploadBound) {
                window.__isoUploadBound = true;
                window.addEventListener('iso-start-upload', () => window.__isoUpload?.doUpload());
                window.addEventListener('iso-upload-reset', () => window.__isoUpload?.resetUi());
            }

            bind();
            document.addEventListener('livewire:navigated', bind);
        })();
    </script>
</x-organisms.page>
