<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Services\AppProfile\AppProfileService;
use App\Components\Traits\WithToasts;
use App\Models\Application;
use Illuminate\Support\Facades\Log;

new #[Title('Détail de l\'Application - SE4FS')] class extends Component {
    use WithToasts;

    private AppProfileService $appProfileService;

    public int $applicationId;
    public ?Application $application = null;

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
    }

    public function loadApplication(): void
    {
        $this->application = $this->appProfileService->getApplication($this->applicationId);
    }
};
?>

<x-organisms.page title="{{ $application?->name ?? 'Application' }}" :scrollable="false"
    backUrl="{{ route('app.parc-settings.index', ['tab' => 'applications']) }}" backText="Retour au catalogue">

    @if ($application)
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Informations principales -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Carte principale -->
                <div class="card bg-base-100 shadow-sm border border-base-200">
                    <div class="card-body">
                        <div class="flex items-start gap-4">
                            <div class="avatar placeholder">
                                <div class="bg-primary/10 text-primary rounded-xl w-16 h-16">
                                    <i class="fa-solid fa-cube text-2xl"></i>
                                </div>
                            </div>
                            <div class="flex-1">
                                <h2 class="text-2xl font-bold">{{ $application->name }}</h2>
                                <p class="text-base-content/60">
                                    <code class="bg-base-200 px-2 py-0.5 rounded">{{ $application->app_id }}</code>
                                </p>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                <span class="badge badge-lg badge-primary">v{{ $application->version }}</span>
                                <span class="badge badge-outline">{{ $application->branch ?? 'stable' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Détails techniques -->
                <div class="card bg-base-100 shadow-sm border border-base-200">
                    <div class="card-body">
                        <h3 class="card-title text-lg mb-4">
                            <i class="fa-solid fa-info-circle mr-2"></i>
                            Informations techniques
                        </h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <span class="text-sm text-base-content/60">Catégorie</span>
                                <p class="font-medium">{{ $application->category ?? '-' }}</p>
                            </div>
                            <div>
                                <span class="text-sm text-base-content/60">Dépôt</span>
                                <p class="font-medium">{{ $application->depot?->name ?? '-' }}</p>
                            </div>
                            <div>
                                <span class="text-sm text-base-content/60">Compatibilité</span>
                                <p class="font-medium">{{ $application->compatibility ?? '-' }}</p>
                            </div>
                            <div>
                                <span class="text-sm text-base-content/60">Branche</span>
                                <p>
                                    <span
                                        class="badge {{ $application->branch === 'stable' ? 'badge-success' : 'badge-warning' }}">
                                        {{ $application->branch ?? 'stable' }}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <span class="text-sm text-base-content/60">Date</span>
                                <p class="font-medium">
                                    {{ $application->updated_at?->format('d/m/Y H:i') ?? '-' }}
                                </p>
                            </div>
                        </div>
                        @if ($application->xml_sha)
                            <div class="mt-4">
                                <span class="text-sm text-base-content/60">SHA XML</span>
                                <p class="font-mono text-xs bg-base-200 p-2 rounded mt-1 break-all">
                                    {{ $application->xml_sha }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>

            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Profils utilisant cette application -->
                <div class="card bg-base-100 shadow-sm border border-base-200">
                    <div class="card-body">
                        <h3 class="card-title text-lg mb-4">
                            <i class="fa-solid fa-layer-group mr-2"></i>
                            Profils applicatifs
                        </h3>
                        @if ($application->appProfiles->count() > 0)
                            <div class="space-y-2">
                                @foreach ($application->appProfiles as $profile)
                                    <a href="{{ route('app.parc-settings.profiles.show', $profile->id) }}"
                                        class="flex items-center gap-3 p-2 rounded-lg hover:bg-base-200 transition-colors">
                                        <div class="avatar placeholder">
                                            <div class="bg-secondary/10 text-secondary rounded w-8 h-8">
                                                <i class="fa-solid fa-layer-group text-sm"></i>
                                            </div>
                                        </div>
                                        <span
                                            class="font-medium flex-1">{{ $profile->display_name ?? $profile->name }}</span>
                                        <i class="fa-solid fa-chevron-right text-base-content/30"></i>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <p class="text-base-content/60 text-sm">
                                Cette application n'est dans aucun profil.
                            </p>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    @else
        <div class="alert alert-error">
            <i class="fa-solid fa-exclamation-triangle"></i>
            <span>Application non trouvée</span>
        </div>
    @endif
</x-organisms.page>
