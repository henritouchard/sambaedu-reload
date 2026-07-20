<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use App\Services\AppProfile\AppProfileService;
use App\Components\Traits\WithToasts;
use App\Components\Traits\WithReturnBack;
use App\Enums\AppKind;
use App\Enums\ApplicationStatus;
use App\Models\AppCustomization;
use App\Models\Application;
use App\Models\InstallationLog;
use App\Models\WorkstationApplicationStatus;
use Illuminate\Support\Facades\Log;

new #[Title('Détails de l\'application - SE4FS')] class extends Component {
    use WithToasts;
    use WithReturnBack;

    private AppProfileService $appProfileService;

    public int $applicationId;
    public ?Application $application = null;
    public string $deploymentTab = 'errors';

    // Onglet d'origine (URL relative) pour le bouton retour — voir WithReturnBack.
    #[Url]
    public ?string $from = null;

    /** URL de retour : provenance dynamique, repli sur le catalogue d'applications. */
    public function backUrl(): string
    {
        return $this->resolveBack(route('app.parc-settings.index', ['tab' => 'applications']));
    }

    public function boot(AppProfileService $appProfileService): void
    {
        $this->appProfileService = $appProfileService;
    }

    public function mount(int $id): void
    {
        $this->applicationId = $id;
        $this->loadApplication();

        if (!$this->application) {
            abort(404, 'Application non trouvée');
        }

        $this->initDeploymentTab();
    }

    public function loadApplication(): void
    {
        $this->application = $this->appProfileService->getApplication($this->applicationId);
    }

    public function getLatestLogProperty(): ?InstallationLog
    {
        return $this->application?->installationLogs()->first();
    }

    public function getWorkstationDeploymentsProperty()
    {
        return WorkstationApplicationStatus::query()
            ->with('workstation')
            ->where('application_id', $this->applicationId)
            ->get();
    }

    /**
     * AppKind correspondant à cette application (null si non personnalisable
     * via le système 4.8). Match sur `app_id` normalisé (`firefox`, `thunderbird`).
     */
    public function getCustomizableKindProperty(): ?AppKind
    {
        if (! $this->application) {
            return null;
        }
        return AppKind::tryFrom(strtolower((string) $this->application->app_id));
    }

    /** Customization établissement (default) si déjà définie. */
    public function getCustomizationProperty(): ?AppCustomization
    {
        $kind = $this->customizableKind;
        if ($kind === null) {
            return null;
        }
        return AppCustomization::query()->ofKind($kind)->defaults()->first();
    }

    public function initDeploymentTab(): void
    {
        $deployments = $this->workstationDeployments;
        if ($deployments->whereIn('status', ['error', 'not-installed'])->isNotEmpty()) {
            $this->deploymentTab = 'errors';
        } elseif ($deployments->whereIn('status', ['upgrading', 'downgrading'])->isNotEmpty()) {
            $this->deploymentTab = 'in_progress';
        } else {
            $this->deploymentTab = 'success';
        }
    }

};
?>

<x-organisms.page title="Détails de l'application" :scrollable="false"
    backUrl="{{ $this->backUrl() }}" backText="Retour au catalogue">

    @php
        $customizableKind = $this->customizableKind;
        $existingCustomization = $this->customization;
    @endphp

    @if ($customizableKind && auth()->user()?->can('app.customize'))
        <x-slot:actions>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-primary">
                    <i class="fa-solid fa-ellipsis-vertical"></i>
                    Actions
                    <i class="fa-solid fa-chevron-down text-xs"></i>
                </div>
                <ul tabindex="0"
                    class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-60 border border-base-300 mt-2">
                    <li>
                        <button type="button"
                            wire:click="$dispatch('open-app-customize-modal', { appKind: '{{ $customizableKind->value }}' })">
                            <i class="fa-solid fa-sliders"></i>
                            <span class="flex-1 text-left">Paramétrer</span>
                            @if ($existingCustomization)
                                <span class="badge badge-xs badge-success">Personnalisé</span>
                            @endif
                        </button>
                    </li>
                </ul>
            </div>
        </x-slot:actions>
    @endif

    @if ($application)
        @php $latestLog = $this->latestLog; @endphp

        <div class="space-y-6">

        {{-- Card header : identité + statut + infos techniques --}}
        <div class="card bg-base-100 shadow-sm border border-base-300">
            <div class="card-body">
                {{-- En-tête identité --}}
                <div class="flex items-start gap-4 mb-6">
                    <div class="{{ $application->status === ApplicationStatus::Error ? 'bg-error/10 text-error' : 'bg-primary/10 text-primary' }} rounded-xl w-16 h-16 flex items-center justify-center">
                        <i class="fa-solid {{ $application->status === ApplicationStatus::Error ? 'fa-triangle-exclamation' : 'fa-cube' }} text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-2xl font-bold">{{ $application->name }}</h2>
                        <p class="text-base-content/60 mt-0.5">
                            <code class="bg-base-200 px-2 py-0.5 rounded">{{ $application->app_id }}</code>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge badge-lg badge-primary">v{{ $application->version }}</span>
                        <span class="badge badge-outline">{{ $application->branch ?? 'stable' }}</span>
                        @if ($application->status === ApplicationStatus::Error)
                            <span class="badge badge-error">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                Erreur d'installation
                            </span>
                        @elseif ($application->status === ApplicationStatus::Downloading)
                            <span class="badge badge-warning">
                                <span class="loading loading-spinner loading-xs mr-1"></span>
                                Installation en cours
                            </span>
                        @elseif ($application->status === ApplicationStatus::Installed)
                            <span class="badge badge-success">
                                <i class="fa-solid fa-circle-check mr-1"></i>
                                Installée
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Message d'erreur / progression si pertinent --}}
                @if ($application->status === ApplicationStatus::Error && $latestLog?->message)
                    <div class="bg-error/10 text-error rounded-lg px-4 py-3 text-sm mb-6">
                        <p class="font-medium mb-0.5">Détail de l'erreur</p>
                        <p>{{ $latestLog->message }}</p>
                        @if ($latestLog->completed_at)
                            <p class="text-xs opacity-70 mt-1">{{ $latestLog->completed_at->format('d/m/Y H:i:s') }}</p>
                        @endif
                    </div>
                @elseif ($application->status === ApplicationStatus::Downloading && $latestLog?->message)
                    <div class="bg-warning/10 text-warning-content rounded-lg px-4 py-3 text-sm mb-6">
                        <p>{{ $latestLog->message }} ({{ $latestLog->progress ?? 0 }}%)</p>
                    </div>
                @endif

                {{-- Grille infos techniques --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <span class="text-xs text-base-content/60 uppercase tracking-wide">Catégorie</span>
                        <p class="font-medium mt-0.5">{{ $application->category ?? '-' }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-base-content/60 uppercase tracking-wide">Dépôt</span>
                        <p class="font-medium mt-0.5">{{ $application->depot?->name ?? '-' }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-base-content/60 uppercase tracking-wide">Compatibilité</span>
                        <p class="font-medium mt-0.5">{{ $application->compatibility ?? '-' }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-base-content/60 uppercase tracking-wide">Mis à jour le</span>
                        <p class="font-medium mt-0.5">{{ $application->updated_at?->format('d/m/Y H:i') ?? '-' }}</p>
                    </div>
                </div>

                @if ($application->xml_sha)
                    <div class="mt-4">
                        <span class="text-xs text-base-content/60 uppercase tracking-wide">SHA XML</span>
                        <p class="font-mono text-xs bg-base-200 p-2 rounded mt-1 break-all">{{ $application->xml_sha }}</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Card profils applicatifs --}}
        <div class="card bg-base-100 shadow-sm border border-base-300">
            <div class="card-body">
                <h3 class="card-title text-lg mb-4">
                    <i class="fa-solid fa-layer-group mr-2"></i>
                    Profils applicatifs
                </h3>
                @if ($application->appProfiles->count() > 0)
                    <div class="flex flex-wrap gap-2">
                        @foreach ($application->appProfiles as $profile)
                            <a href="{{ route('app.parc-settings.profiles.show', $profile->id) }}"
                                class="flex items-center gap-2 px-3 py-2 rounded-lg border border-base-300 hover:bg-base-200 transition-colors">
                                <i class="fa-solid fa-layer-group text-secondary text-sm"></i>
                                <span class="font-medium text-sm">{{ $profile->display_name ?? $profile->name }}</span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-base-content/60 text-sm">Cette application n'est dans aucun profil.</p>
                @endif
            </div>
        </div>

                {{-- Card déploiement postes --}}
                @php
                    $deployments = $this->workstationDeployments;
                    $deploySuccess    = $deployments->where('status', 'installed');
                    $deployErrors     = $deployments->whereIn('status', ['error', 'not-installed']);
                    $deployInProgress = $deployments->whereIn('status', ['upgrading', 'downgrading']);
                    $deployFinished   = $deploySuccess->count() + $deployErrors->count();
                    $deployRate       = $deployFinished > 0 ? round(($deploySuccess->count() / $deployFinished) * 100) : 0;
                @endphp
                @if ($deployments->isNotEmpty())
                <div class="card bg-base-100 shadow-sm border border-base-300">
                    <div class="card-body">
                        <h3 class="card-title text-base mb-3">
                            <i class="fa-solid fa-computer mr-2"></i>
                            Déploiement sur les postes
                        </h3>

                        {{-- Barre de progression --}}
                        @if ($deployFinished > 0)
                        <div class="flex items-center gap-2 mb-3">
                            <progress class="progress {{ $deployRate === 100 ? 'progress-success' : ($deployRate === 0 ? 'progress-error' : 'progress-warning') }} flex-1"
                                value="{{ $deployRate }}" max="100"></progress>
                            <span class="text-sm font-semibold {{ $deployRate === 100 ? 'text-success' : ($deployRate === 0 ? 'text-error' : 'text-warning') }}">
                                {{ $deploySuccess->count() }}/{{ $deployFinished }} ({{ $deployRate }}%)
                            </span>
                        </div>
                        @endif

                        {{-- Onglets --}}
                        <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit mb-3 tabs-sm">
                            <button type="button" role="tab"
                                class="tab {{ $deploymentTab === 'success' ? 'tab-active' : '' }}"
                                aria-selected="{{ $deploymentTab === 'success' ? 'true' : 'false' }}"
                                wire:click="$set('deploymentTab', 'success')">
                                <i class="fa-solid fa-check mr-1 text-success"></i>
                                Succès
                                <span class="badge badge-xs ml-1 {{ $deploySuccess->isNotEmpty() ? 'badge-success' : 'badge-ghost' }}">{{ $deploySuccess->count() }}</span>
                            </button>
                            <button type="button" role="tab"
                                class="tab {{ $deploymentTab === 'errors' ? 'tab-active' : '' }}"
                                aria-selected="{{ $deploymentTab === 'errors' ? 'true' : 'false' }}"
                                wire:click="$set('deploymentTab', 'errors')">
                                <i class="fa-solid fa-xmark mr-1 text-error"></i>
                                Échecs
                                <span class="badge badge-xs ml-1 {{ $deployErrors->isNotEmpty() ? 'badge-error' : 'badge-ghost' }}">{{ $deployErrors->count() }}</span>
                            </button>
                            <button type="button" role="tab"
                                class="tab {{ $deploymentTab === 'in_progress' ? 'tab-active' : '' }}"
                                aria-selected="{{ $deploymentTab === 'in_progress' ? 'true' : 'false' }}"
                                wire:click="$set('deploymentTab', 'in_progress')">
                                <i class="fa-solid fa-rotate mr-1 text-info"></i>
                                En cours
                                <span class="badge badge-xs ml-1 {{ $deployInProgress->isNotEmpty() ? 'badge-info' : 'badge-ghost' }}">{{ $deployInProgress->count() }}</span>
                            </button>
                        </div>

                        {{-- Contenu onglet --}}
                        @php
                            $deployItems = match($deploymentTab) {
                                'success'     => $deploySuccess,
                                'in_progress' => $deployInProgress,
                                default       => $deployErrors,
                            };
                        @endphp
                        @if ($deployItems->isEmpty())
                            <p class="text-base-content/50 text-sm py-2 text-center">Aucun poste dans cette catégorie.</p>
                        @else
                            <div class="space-y-1 max-h-64 overflow-y-auto">
                                @foreach ($deployItems as $status)
                                    <div class="flex items-center justify-between p-2 rounded-lg hover:bg-base-200 transition-colors">
                                        @if ($status->workstation)
                                            <a href="{{ route('app.parc.machines.show', $status->workstation->id) }}"
                                                class="text-sm font-medium hover:underline">
                                                {{ $status->workstation->name }}
                                            </a>
                                        @else
                                            <span class="text-sm font-medium">—</span>
                                        @endif
                                        @if ($status->status === 'installed')
                                            <span class="badge badge-success badge-sm">Installé</span>
                                        @elseif ($status->status === 'upgrading')
                                            <span class="badge badge-info badge-sm">
                                                <span class="loading loading-spinner loading-xs mr-1"></span>
                                                Mise à jour
                                            </span>
                                        @elseif ($status->status === 'downgrading')
                                            <span class="badge badge-info badge-sm">
                                                <span class="loading loading-spinner loading-xs mr-1"></span>
                                                Rétrogradation
                                            </span>
                                        @elseif ($status->status === 'error')
                                            <button type="button"
                                                class="badge badge-error badge-sm cursor-pointer hover:badge-outline"
                                                wire:click="$dispatch('open-install-log-modal', { statusId: {{ $status->id }} })">
                                                Erreur ↗
                                            </button>
                                        @else
                                            <button type="button"
                                                class="badge badge-warning badge-sm cursor-pointer hover:badge-outline"
                                                wire:click="$dispatch('open-install-log-modal', { statusId: {{ $status->id }} })">
                                                Non installé ↗
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
                @endif

        {{-- Modale log d'installation WPKG (partagée) --}}
        <livewire:components::organisms.install-log-modal />

        {{-- Modale personnalisation applicative (story 4.8) — activée uniquement si l'app est personnalisable --}}
        @if ($customizableKind)
            <livewire:components::organisms.app-customize-modal :key="'app-customize-modal-app-'.$applicationId" />
        @endif

        </div>{{-- /space-y-6 --}}
    @else
        <div class="alert alert-error">
            <i class="fa-solid fa-exclamation-triangle"></i>
            <span>Application non trouvée</span>
        </div>
    @endif
</x-organisms.page>
