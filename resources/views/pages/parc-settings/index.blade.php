<?php

use App\Components\Traits\WithToasts;
use App\Services\AppProfile\AppProfileService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Paramètres du Parc - SE4FS')] class extends Component
{
    use WithToasts;

    private AppProfileService $appProfileService;

    #[Url(keep: true)]
    public string $tab = 'profiles';

    // Stats (pour les badges d'onglets)
    public array $stats = [];

    public bool $statsLoaded = false;

    public function boot(AppProfileService $appProfileService): void
    {
        $this->appProfileService = $appProfileService;
    }

    public function mount(): void
    {
        if (session()->has('toast')) {
            $toastData = session('toast');
            $this->toast($toastData['type'] ?? 'info', $toastData['title'] ?? 'Notification', $toastData['message'] ?? '');
        }
    }

    public function loadStats(): void
    {
        if ($this->statsLoaded) {
            return;
        }

        try {
            $this->stats = $this->appProfileService->getStats();
            $this->statsLoaded = true;
        } catch (\Exception $e) {
            Log::error('[ParcSettings] Erreur chargement stats: '.$e->getMessage());
            $this->stats = [];
            $this->statsLoaded = true;
        }
    }

    public function setTab(string $tab): void
    {
        $this->redirect(route('app.parc-settings.index') . '?tab=' . $tab);
    }
};
?>

<x-organisms.page title="Applications" :scrollable="false"
    description="Gérez les profils applicatifs, le catalogue d'applications et les dépôts">

    <x-slot:actions>
        <div class="dropdown dropdown-end">
            <div tabindex="0" role="button" class="btn btn-primary">
                <i class="fa-solid fa-ellipsis-vertical"></i>
                Actions
                <i class="fa-solid fa-chevron-down text-xs"></i>
            </div>
            <ul tabindex="0"
                class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-56 border border-base-300 mt-2">
                @if ($tab === 'profiles')
                    <li>
                        <button type="button" wire:click="$dispatch('open-create-profile-modal')">
                            <i class="fa-solid fa-plus"></i>
                            Nouveau Profil
                        </button>
                    </li>
                @elseif ($tab === 'applications')
                    <li>
                        <button type="button" wire:click="$dispatch('open-app-store-modal')">
                            <i class="fa-solid fa-cloud-arrow-down"></i>
                            Ajouter des applications
                        </button>
                    </li>
                @elseif ($tab === 'depot')
                    <li>
                        <button type="button" wire:click="$dispatch('open-create-depot-modal')">
                            <i class="fa-solid fa-plus"></i>
                            Ajouter un dépôt
                        </button>
                    </li>
                    <li>
                        <button type="button" wire:click="$dispatch('sync-current-depot')">
                            <i class="fa-solid fa-sync"></i>
                            Synchroniser le dépôt
                        </button>
                    </li>
                    <li>
                        <button type="button" class="text-warning" wire:click="$dispatch('open-delete-depot-modal')">
                            <i class="fa-solid fa-eye-slash"></i>
                            Désactiver le dépôt
                        </button>
                    </li>
                @elseif ($tab === 'shortcuts')
                    @can('create-shortcut')
                        <li>
                            <a href="{{ route('app.shortcuts.new') }}">
                                <i class="fa-solid fa-plus"></i>
                                Nouveau raccourci
                            </a>
                        </li>
                    @endcan
                @endif
            </ul>
        </div>
    </x-slot:actions>

    <!-- Chargement asynchrone des stats -->
    <div wire:init="loadStats"></div>

    <div class="h-full flex flex-col gap-4">
        <!-- Onglets -->
        <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit">
            <button type="button" role="tab" class="tab {{ $tab === 'profiles' ? 'tab-active' : '' }}"
                wire:click="setTab('profiles')">
                <i class="fa-solid fa-layer-group mr-2"></i>
                Profils Applicatifs
                @if ($statsLoaded && isset($stats['profiles_count']))
                    <span class="badge badge-sm ml-2">{{ $stats['profiles_count'] }}</span>
                @endif
            </button>
            <button type="button" role="tab" class="tab {{ $tab === 'applications' ? 'tab-active' : '' }}"
                wire:click="setTab('applications')">
                <i class="fa-solid fa-cube mr-2"></i>
                Catalogue d'Applications
                @if ($statsLoaded && isset($stats['applications_count']))
                    <span class="badge badge-sm ml-2">{{ $stats['applications_count'] }}</span>
                @endif
            </button>
            <button type="button" role="tab" class="tab {{ $tab === 'depot' ? 'tab-active' : '' }}"
                wire:click="setTab('depot')">
                <i class="fa-solid fa-warehouse mr-2"></i>
                Dépôt
            </button>
            <button type="button" role="tab" class="tab {{ $tab === 'shortcuts' ? 'tab-active' : '' }}"
                wire:click="setTab('shortcuts')">
                <i class="fa-solid fa-arrow-up-right-from-square mr-2"></i>
                Raccourcis
            </button>
            <button type="button" role="tab" class="tab {{ $tab === 'environment' ? 'tab-active' : '' }}"
                wire:click="setTab('environment')">
                <i class="fa-solid fa-laptop-house mr-2"></i>
                Environnement
            </button>
        </div>

        <!-- Contenu des onglets -->
        <div class="flex-1 min-h-0 flex flex-col">
            @if ($tab === 'profiles')
                <livewire:pages::parc-settings._partials.profiles-tab />
            @elseif ($tab === 'applications')
                <livewire:pages::parc-settings._partials.applications-tab />
            @elseif ($tab === 'depot')
                <livewire:pages::parc-settings._partials.depot-tab />
            @elseif ($tab === 'shortcuts')
                <livewire:pages::parc-settings._partials.shortcuts-tab />
            @elseif ($tab === 'environment')
                <livewire:pages::parc-settings._partials.environment-tab />
            @endif
        </div>
    </div>
</x-organisms.page>
