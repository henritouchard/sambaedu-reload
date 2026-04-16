<?php

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\WorkstationApplicationStatus;
use App\Services\Windows\WorkstationLogReader;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Log;

new class extends Component {
    use WithToasts;

    private WorkstationLogReader $logReader;

    public bool $visible = false;
    public ?int $statusId = null;
    public ?string $installLogContent = null;
    public bool $installLogMissing = false;
    public bool $installLogTruncated = false;
    public ?string $installLogFilename = null;

    // Métadonnées hydratées en propriétés publiques (évite re-SELECT à chaque render)
    public ?string $wstName = null;
    public ?string $appName = null;
    public ?int $appId = null;
    public ?string $statusLabel = null;
    public ?string $statusBadge = null;
    public ?string $installedVersion = null;
    public ?string $reportedAt = null;
    public bool $rebootRequired = false;

    public function boot(WorkstationLogReader $logReader): void
    {
        $this->logReader = $logReader;
    }

    #[On('open-install-log-modal')]
    public function open(int $statusId): void
    {
        $this->resetState();
        $this->statusId = $statusId;

        $status = WorkstationApplicationStatus::with(['workstation', 'application'])->find($statusId);

        if (!$status) {
            $this->installLogMissing = true;
            $this->visible = true;
            return;
        }

        if (!$status->workstation) {
            $this->installLogMissing = true;
            $this->visible = true;
            return;
        }

        // Hydrater les métadonnées en propriétés publiques
        $this->wstName = $status->workstation->name ?? '—';
        $this->appName = $status->application?->name ?? $status->application?->app_id ?? '—';
        $this->appId = $status->application?->id;
        $this->statusLabel = match ($status->status) {
            'error'         => 'Erreur',
            'not-installed' => 'Non installé',
            default         => $status->status ?? '—',
        };
        $this->statusBadge = $status->status === 'error' ? 'badge-error' : 'badge-warning';
        $this->installedVersion = $status->installed_version;
        $this->reportedAt = $status->reported_at?->format('d/m/Y H:i');
        $this->rebootRequired = (bool) $status->reboot_required;

        try {
            $result = $this->logReader->read($status->workstation);
            $this->installLogContent = $result->content;
            $this->installLogMissing = $result->missing;
            $this->installLogTruncated = $result->truncated;
            $this->installLogFilename = $result->filename;
        } catch (\Throwable $e) {
            Log::error('[InstallLogModal] Erreur lecture log', [
                'status_id'      => $statusId,
                'workstation_id' => $status->workstation->id,
                'error'          => $e->getMessage(),
            ]);
            $this->installLogMissing = true;
        }

        $this->visible = true;
    }

    public function close(): void
    {
        $this->resetState();
    }

    public function notifyLogCopied(): void
    {
        $this->toastSuccess('Log copié dans le presse-papier');
    }

    public function notifyLogCopyFailed(): void
    {
        $this->toastError('Impossible de copier le log (contexte non sécurisé ?)');
    }

    private function resetState(): void
    {
        $this->visible = false;
        $this->statusId = null;
        $this->installLogContent = null;
        $this->installLogMissing = false;
        $this->installLogTruncated = false;
        $this->installLogFilename = null;
        $this->wstName = null;
        $this->appName = null;
        $this->appId = null;
        $this->statusLabel = null;
        $this->statusBadge = null;
        $this->installedVersion = null;
        $this->reportedAt = null;
        $this->rebootRequired = false;
    }
};
?>

<div>
    @if ($visible)
        @teleport('body')
            <dialog class="modal modal-open" aria-labelledby="ilm-title">
                <div class="modal-box max-w-3xl w-full flex flex-col max-h-[90vh]">

                    {{-- Header --}}
                    <div class="flex items-start justify-between mb-4 flex-shrink-0">
                        <div>
                            <h3 id="ilm-title" class="font-bold text-lg">
                                <i class="fa-solid fa-computer mr-2 text-primary"></i>
                                {{ $wstName ?? '—' }}
                            </h3>
                            <p class="text-sm text-base-content/60">
                                <i class="fa-solid fa-cube mr-1"></i>
                                {{ $appName ?? '—' }}
                            </p>
                        </div>
                        <button type="button" wire:click="close" class="btn btn-sm btn-circle btn-ghost">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    {{-- Métadonnées --}}
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 flex-shrink-0">
                        <div class="bg-base-200 rounded-lg p-3">
                            <p class="text-xs text-base-content/60">Version installée</p>
                            <p class="font-mono font-medium text-sm">{{ $installedVersion ?: '—' }}</p>
                        </div>
                        <div class="bg-base-200 rounded-lg p-3">
                            <p class="text-xs text-base-content/60">Dernier rapport</p>
                            <p class="font-medium text-sm">{{ $reportedAt ?? '—' }}</p>
                        </div>
                        <div class="bg-base-200 rounded-lg p-3">
                            <p class="text-xs text-base-content/60">Statut</p>
                            <span class="badge badge-sm {{ $statusBadge ?? 'badge-warning' }}">{{ $statusLabel ?? '—' }}</span>
                        </div>
                        @if ($rebootRequired)
                            <div class="bg-warning/10 rounded-lg p-3">
                                <p class="text-xs text-base-content/60">Redémarrage</p>
                                <p class="font-medium text-warning text-sm">
                                    <i class="fa-solid fa-rotate-right mr-1"></i>Requis
                                </p>
                            </div>
                        @else
                            <div class="bg-base-200 rounded-lg p-3">
                                <p class="text-xs text-base-content/60">Redémarrage</p>
                                <p class="text-base-content/50 text-sm">Non requis</p>
                            </div>
                        @endif
                    </div>

                    {{-- Bloc log --}}
                    <div class="flex-1 min-h-0 flex flex-col">
                        <div class="flex items-center justify-between mb-2 flex-shrink-0">
                            <p class="text-xs text-base-content/60 uppercase tracking-wide font-semibold">
                                Log d'installation WPKG
                                @if ($installLogTruncated)
                                    <span class="badge badge-xs badge-warning ml-1">tronqué à 256 KB</span>
                                @endif
                            </p>

                            @if (!$installLogMissing && $installLogContent !== null && $installLogContent !== '')
                                @php
                                    $downloadFilename = $installLogFilename ?? ($wstName ? strtolower($wstName) . '.log' : 'wpkg.log');
                                @endphp
                                <div class="flex gap-2">
                                    {{-- Bouton Copier --}}
                                    <button
                                        type="button"
                                        class="btn btn-xs btn-ghost gap-1"
                                        title="Copier le log"
                                        x-on:click="
                                            if (navigator.clipboard && window.isSecureContext) {
                                                navigator.clipboard.writeText($wire.installLogContent)
                                                    .then(() => $wire.notifyLogCopied())
                                                    .catch(() => $wire.notifyLogCopyFailed());
                                            } else {
                                                try {
                                                    const el = document.createElement('textarea');
                                                    el.value = $wire.installLogContent;
                                                    document.body.appendChild(el);
                                                    el.select();
                                                    document.execCommand('copy');
                                                    document.body.removeChild(el);
                                                    $wire.notifyLogCopied();
                                                } catch(e) {
                                                    $wire.notifyLogCopyFailed();
                                                }
                                            }
                                        ">
                                        <i class="fa-solid fa-copy"></i>
                                        Copier
                                    </button>

                                    {{-- Bouton Télécharger --}}
                                    <button
                                        type="button"
                                        class="btn btn-xs btn-ghost gap-1"
                                        title="Télécharger le log"
                                        x-on:click="
                                            const blob = new Blob([$wire.installLogContent], { type: 'text/plain;charset=utf-8' });
                                            const url = URL.createObjectURL(blob);
                                            const a = document.createElement('a');
                                            a.href = url;
                                            a.download = {{ Js::from($downloadFilename) }};
                                            document.body.appendChild(a);
                                            a.click();
                                            document.body.removeChild(a);
                                            URL.revokeObjectURL(url);
                                        ">
                                        <i class="fa-solid fa-download"></i>
                                        Télécharger
                                    </button>
                                </div>
                            @endif
                        </div>

                        @if ($installLogMissing)
                            <div class="bg-base-200 rounded-lg p-4 text-sm text-base-content/60 italic">
                                <i class="fa-solid fa-triangle-exclamation text-warning mr-2"></i>
                                Log d'installation non disponible pour ce poste — sera généré au prochain rapport WPKG.
                            </div>
                        @elseif ($installLogContent === '' || $installLogContent === null)
                            <div class="bg-base-200 rounded-lg p-4 text-sm text-base-content/60 italic">
                                <i class="fa-solid fa-file-circle-xmark mr-2"></i>
                                Log d'installation vide.
                            </div>
                        @else
                            <div class="flex-1 min-h-0 overflow-auto bg-base-300 rounded-lg">
                                <pre
                                    role="log"
                                    aria-label="Contenu du log d'installation WPKG"
                                    class="text-xs font-mono whitespace-pre p-4 leading-relaxed"
                                >{{ $installLogContent }}</pre>
                            </div>
                        @endif
                    </div>

                </div>

                {{-- Backdrop --}}
                <form method="dialog" class="modal-backdrop" wire:click="close">
                    <button>close</button>
                </form>
            </dialog>
        @endteleport
    @endif
</div>
