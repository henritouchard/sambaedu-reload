<?php

use App\Services\BulkResetListingService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Bandeau persistant /users — story 2.6 (AC 9).
 *
 * Affiché uniquement tant qu'un listing actif existe côté cache pour
 * l'opérateur courant. Poll toutes les 30 s pour mettre à jour le TTL
 * restant et masquer le bandeau à expiration (ou purge manuelle).
 */
new class extends Component {
    public bool $hasListing = false;
    public ?string $token = null;
    public int $ttlRemaining = 0;
    public int $count = 0;
    public ?string $pdfUrl = null;
    public ?string $csvUrl = null;

    private BulkResetListingService $listingService;

    public function boot(BulkResetListingService $listingService): void
    {
        $this->listingService = $listingService;
    }

    public function mount(): void
    {
        $this->refreshStatus();
    }

    #[On('password-reset-done')]
    public function refreshStatus(): void
    {
        $operatorId = (int) (auth()->id() ?? 0);
        if ($operatorId === 0) {
            $this->hasListing = false;
            return;
        }

        $meta = $this->listingService->getActiveListingMeta($operatorId);
        if ($meta === null) {
            $this->hasListing = false;
            $this->token = null;
            $this->ttlRemaining = 0;
            $this->count = 0;
            $this->pdfUrl = null;
            $this->csvUrl = null;
            return;
        }

        $this->hasListing = true;
        $this->token = $meta['token'];
        $this->ttlRemaining = (int) $meta['ttl_remaining'];
        $this->count = (int) $meta['count'];
        $this->pdfUrl = $meta['pdf_url'];
        $this->csvUrl = $meta['csv_url'];
    }

    public function purgeNow(): void
    {
        $operatorId = (int) (auth()->id() ?? 0);
        if ($operatorId === 0 || $this->token === null) {
            return;
        }

        $this->listingService->purgeListing($operatorId, $this->token);
        $this->refreshStatus();
    }

    public function ttlRemainingHuman(): string
    {
        $minutes = (int) floor($this->ttlRemaining / 60);
        $seconds = $this->ttlRemaining % 60;
        return sprintf('%d min %02d s', $minutes, $seconds);
    }
};
?>

<div wire:poll.30s="refreshStatus">
    @if ($hasListing)
        <div class="alert alert-info shadow-md mb-4">
            <i class="fa-solid fa-circle-info"></i>
            <div class="flex-1">
                <div class="font-semibold">
                    Listing de réinitialisation disponible — {{ $count }} utilisateur(s)
                </div>
                <div class="text-xs">
                    Expire dans {{ $this->ttlRemainingHuman() }}.
                    Les mots de passe sont détenus chiffrés dans le cache serveur pour une durée
                    limitée.
                </div>
            </div>
            <div class="flex gap-2">
                <a href="{{ $pdfUrl }}" target="_blank" class="btn btn-sm btn-primary">
                    <i class="fa-solid fa-file-pdf"></i>
                    Télécharger PDF
                </a>
                <a href="{{ $csvUrl }}" target="_blank" class="btn btn-sm btn-outline">
                    <i class="fa-solid fa-file-csv"></i>
                    Télécharger CSV
                </a>
                <button type="button" class="btn btn-sm btn-ghost text-error" wire:click="purgeNow"
                    wire:confirm="Purger immédiatement le listing ? Les mots de passe ne seront plus récupérables.">
                    <i class="fa-solid fa-trash"></i>
                    Purger
                </button>
            </div>
        </div>
    @endif
</div>
