<?php

use App\Gpo\Support\NativeSectionResolver;
use App\Models\AppProfile;
use App\Models\Shortcut;
use App\Models\Wallpaper;
use App\Gpo\Services\WinePrefixScanner;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — Catalogue des sections natives GPO — Story 16.14 D.
 *
 * Affiche la grille des sections cataloguées dans NativeSectionResolver::MAPPING
 * avec compteurs d'entités gérées (best-effort D6).
 *
 * Route : GET /admin/settings/gpo/sections (admin.gpo.sections).
 * Permission : can:server.admin.
 *
 * Note : Blade pur suffisant pour la plupart des cas (D2).
 * On utilise Livewire SFC pour homogénéité pattern et accès aux compteurs.
 */
new #[Title('Sections natives GPO - SE4FS')] class extends Component {

    /** @var array<string, array{patterns: list<string>, url: string, label: string, icon: string}> */
    public array $sections = [];

    /** @var array<string, int|null> Compteurs d'entités par section (null = N/A). */
    public array $counters = [];

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $this->sections = NativeSectionResolver::all();
        $this->loadCounters();
    }

    private function loadCounters(): void
    {
        // wallpapers
        try {
            $this->counters['wallpapers'] = Wallpaper::count();
        } catch (\Throwable) {
            $this->counters['wallpapers'] = null;
        }

        // app-customizations (AppProfile)
        try {
            $this->counters['app-customizations'] = AppProfile::count();
        } catch (\Throwable) {
            $this->counters['app-customizations'] = null;
        }

        // shortcuts
        try {
            $this->counters['shortcuts'] = Shortcut::count();
        } catch (\Throwable) {
            $this->counters['shortcuts'] = null;
        }

        // wine (WinePrefixScanner::list() → array count)
        try {
            $scanner = app(WinePrefixScanner::class);
            $this->counters['wine'] = count($scanner->list());
        } catch (\Throwable) {
            $this->counters['wine'] = null;
        }

        // profils-itinerants (best-effort — pas de service dédié en 16.14)
        $this->counters['profils-itinerants'] = null;
    }

    /** Labels humains personnalisés pour la page sections (AC4.2). */
    public function getSectionLabel(string $key): string
    {
        return match ($key) {
            'profils-itinerants'  => 'Profils itinérants',
            'wallpapers'          => 'Fonds d\'écran',
            'app-customizations'  => 'Personnalisation apps (Firefox/Thunderbird)',
            'shortcuts'           => 'Raccourcis',
            'wine'                => 'Apps Wine (Linux)',
            default               => $key,
        };
    }
};
?>

<x-organisms.page title="Sections natives GPO" :scrollable="true"
    description="Catalogue des sections GPO gérables nativement dans SE5 (SE4FS).">

    <div class="space-y-4">

        {{-- Breadcrumb --}}
        <div class="text-sm breadcrumbs">
            <ul>
                <li><a href="{{ route('admin.gpo.index') }}" class="text-base-content/60 hover:text-primary">Toutes les GPOs</a></li>
                <li class="text-base-content/80">Sections natives</li>
            </ul>
        </div>

        {{-- Description --}}
        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="text-sm">
                    Ces sections sont détectées heuristiquement depuis le nom de la GPO par
                    <code class="font-mono text-xs bg-base-300 px-1 rounded">NativeSectionResolver</code>.
                    Cliquez sur une card pour accéder à la gestion native.
                </p>
            </div>
        </div>

        {{-- Grid des sections --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" data-testid="sections-grid">
            @foreach ($sections as $key => $section)
                <a href="{{ $section['url'] }}"
                    class="card bg-base-100 border border-base-300 hover:border-primary hover:shadow-md transition-all duration-200 cursor-pointer"
                    data-testid="section-card-{{ $key }}">
                    <div class="card-body py-4 px-5">
                        <div class="flex items-start gap-4">
                            <div class="p-3 bg-primary/10 rounded-xl flex-shrink-0">
                                <i class="fa-solid {{ $section['icon'] }} text-primary text-xl"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-sm leading-tight mb-1">
                                    {{ $this->getSectionLabel($key) }}
                                </h3>
                                <p class="text-xs text-base-content/60 leading-snug mb-2">
                                    {{ $section['label'] }}
                                </p>
                                {{-- Compteur d'entités (best-effort D6) --}}
                                @php $counter = $counters[$key] ?? null; @endphp
                                <div class="flex items-center gap-2">
                                    @if ($counter !== null)
                                        <span class="badge badge-outline badge-sm" data-testid="section-counter-{{ $key }}">
                                            {{ $counter }} {{ $counter <= 1 ? 'entité' : 'entités' }}
                                        </span>
                                    @else
                                        <span class="badge badge-ghost badge-sm text-base-content/40" data-testid="section-counter-na-{{ $key }}">
                                            —
                                        </span>
                                    @endif
                                    <span class="text-xs text-primary opacity-70">
                                        <i class="fa-solid fa-arrow-right text-xs"></i>
                                        Gérer
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        {{-- Note patterns de détection --}}
        <div class="card bg-base-200 border border-base-300 shadow-sm mt-4">
            <div class="card-body py-3 px-4">
                <p class="text-xs text-base-content/60">
                    <strong>Source de vérité :</strong>
                    <code class="font-mono bg-base-300 px-1 rounded">NativeSectionResolver::MAPPING</code>
                    — {{ count($sections) }} sections cataloguées.
                    La détection est heuristique (substring case-insensitive sur le displayName de la GPO).
                </p>
            </div>
        </div>
    </div>
</x-organisms.page>
