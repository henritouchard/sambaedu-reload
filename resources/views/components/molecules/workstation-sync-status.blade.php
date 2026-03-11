<?php

use Livewire\Component;
use App\Services\AdSync\AdSyncChecker;
use Illuminate\Support\Facades\Log;

new class extends Component {
    public array $status = [];
    public bool $isChecking = false;
    public bool $showModal = false;

    public function mount(): void
    {
        $this->checkSync();
    }

    public function checkSync(): void
    {
        $this->isChecking = true;

        try {
            $checker = app(AdSyncChecker::class);
            $this->status = $checker->checkWorkstations();
        } catch (\Exception $e) {
            Log::error('[WorkstationSyncStatus] Erreur: ' . $e->getMessage());
            $this->status = [
                'synced' => false,
                'error' => $e->getMessage(),
                'last_check' => now()->toIso8601String(),
            ];
        }

        $this->isChecking = false;
    }

    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function getTotalDivergencesProperty(): int
    {
        $count = 0;
        if (isset($this->status['missing_in_ad'])) {
            $count += collect($this->status['missing_in_ad'])->filter(fn($item) => empty($item['has_recommendation']))->count();
        }
        if (isset($this->status['missing_in_sql'])) {
            $count += collect($this->status['missing_in_sql'])->filter(fn($item) => empty($item['has_recommendation']))->count();
        }
        if (isset($this->status['recommendations'])) {
            $count += count($this->status['recommendations']);
        }
        return $count;
    }
};
?>

<div wire:poll.300s="checkSync">
    @if (isset($status['error']) && $status['error'])
        <div class="alert alert-error shadow-sm">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                <h3 class="font-bold">Erreur de vérification AD (Postes)</h3>
                <p class="text-sm">{{ $status['error'] }}</p>
            </div>
            <button type="button" class="btn btn-sm btn-ghost" wire:click="checkSync" wire:loading.attr="disabled">
                <i class="fa-solid fa-rotate" wire:loading.class="fa-spin"></i>
                Réessayer
            </button>
        </div>
    @elseif (isset($status['synced']) && $status['synced'])
        <div class="alert alert-success shadow-sm">
            <i class="fa-solid fa-circle-check"></i>
            <div>
                <span class="font-medium">Postes synchronisés</span>
                <span class="text-sm opacity-70">
                    ({{ $status['sql_count'] ?? 0 }} en base, {{ $status['ad_count'] ?? 0 }} dans AD)
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs opacity-60">
                    Vérifié
                    {{ isset($status['last_check']) ? \Carbon\Carbon::parse($status['last_check'])->diffForHumans() : 'jamais' }}
                </span>
                <button type="button" class="btn btn-sm btn-ghost" wire:click="checkSync" wire:loading.attr="disabled"
                    title="Revérifier maintenant">
                    <i class="fa-solid fa-rotate" wire:loading.class="fa-spin"></i>
                </button>
            </div>
        </div>
    @elseif ($this->totalDivergences > 0)
        <div class="alert alert-warning shadow-sm cursor-pointer hover:bg-warning/20 transition-colors"
            wire:click="openModal">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div class="flex-1">
                <span class="font-medium">{{ $this->totalDivergences }} divergence(s) détectée(s) pour les postes</span>
                <span class="text-sm opacity-70">
                    (AD: {{ $status['ad_count'] ?? 0 }} / SQL: {{ $status['sql_count'] ?? 0 }})
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs opacity-60">
                    {{ isset($status['last_check']) ? \Carbon\Carbon::parse($status['last_check'])->diffForHumans() : '' }}
                </span>
                <button type="button" class="btn btn-sm btn-ghost" wire:click.stop="checkSync"
                    wire:loading.attr="disabled" title="Revérifier">
                    <i class="fa-solid fa-rotate" wire:loading.class="fa-spin"></i>
                </button>
                <span class="text-xs opacity-60">Cliquez pour voir les détails</span>
            </div>
        </div>
    @else
        <div class="alert shadow-sm">
            <i class="fa-solid fa-spinner fa-spin"></i>
            <span>Vérification de la synchronisation des postes...</span>
        </div>
    @endif

    @if ($showModal)
        <div class="modal modal-open" wire:click.self="closeModal">
            <div class="modal-box max-w-4xl">
                <button type="button" class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2"
                    wire:click="closeModal">✕</button>

                <h3 class="font-bold text-lg mb-4">
                    <i class="fa-solid fa-computer mr-2"></i>
                    Divergences de synchronisation - Postes
                </h3>

                <div class="grid grid-cols-2 gap-4">
                    <div class="border rounded-lg p-4 bg-base-200/50">
                        <h4 class="font-semibold text-info mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-server"></i>
                            Active Directory
                            <span class="badge badge-info badge-sm">{{ count($status['missing_in_sql'] ?? []) }} élément(s)</span>
                        </h4>
                        <p class="text-xs text-base-content/60 mb-3">Postes présents dans AD mais absents en SQL</p>

                        @if (isset($status['missing_in_sql']) && count($status['missing_in_sql']) > 0)
                            <div class="max-h-80 overflow-y-auto space-y-2">
                                @foreach ($status['missing_in_sql'] as $item)
                                    <div class="bg-base-100 rounded p-2 text-sm {{ !empty($item['has_recommendation']) ? 'border-l-4 border-warning' : '' }}">
                                        <div class="font-medium">{{ $item['name'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center text-base-content/50 py-4">
                                <i class="fa-solid fa-check-circle text-success text-2xl mb-2"></i>
                                <p>Aucun poste orphelin dans AD</p>
                            </div>
                        @endif
                    </div>

                    <div class="border rounded-lg p-4 bg-base-200/50">
                        <h4 class="font-semibold text-error mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-database"></i>
                            Base de données SQL
                            <span class="badge badge-error badge-sm">{{ count($status['missing_in_ad'] ?? []) }} élément(s)</span>
                        </h4>
                        <p class="text-xs text-base-content/60 mb-3">Postes présents en SQL mais absents dans AD</p>

                        @if (isset($status['missing_in_ad']) && count($status['missing_in_ad']) > 0)
                            <div class="max-h-80 overflow-y-auto space-y-2">
                                @foreach ($status['missing_in_ad'] as $item)
                                    <div class="bg-base-100 rounded p-2 text-sm {{ !empty($item['has_recommendation']) ? 'border-l-4 border-warning' : '' }}">
                                        <div class="font-medium">{{ $item['name'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center text-base-content/50 py-4">
                                <i class="fa-solid fa-check-circle text-success text-2xl mb-2"></i>
                                <p>Aucun poste orphelin en SQL</p>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="modal-action">
                    <button type="button" class="btn btn-ghost" wire:click="closeModal">Fermer</button>
                    <button type="button" class="btn btn-primary" wire:click="checkSync" wire:loading.attr="disabled">
                        <i class="fa-solid fa-rotate" wire:loading.class="fa-spin"></i>
                        Actualiser
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
