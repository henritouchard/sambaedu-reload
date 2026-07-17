<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Story 27.17 — /admin/settings/parc-defaults : surface d'édition CONSOLIDÉE de
 * la couche `Broadcast` (« configuration par défaut du parc »).
 *
 * Cette page regroupe, en un seul endroit à onglets, l'édition des DÉFAUTS
 * établissement (maille Broadcast — plancher de précédence, overridable par une
 * config plus spécifique) de plusieurs domaines :
 *
 *   - Wallpaper / Lockscreen  → `wallpapers` (owner_id NULL, is_default)
 *   - Registre / capacités    → `capabilities.default_value` (défaut diffusé)
 *   - Apps défaut parc        → `applications.is_parc_default` (net-new 27.17)
 *   - Outils agent            → `agent_tools` (CANAL SÉPARÉ — manifest, hors state)
 *
 * Le mécanisme de précédence (`StateCompiler::specificity()`) et les providers
 * restent INCHANGÉS : la page réutilise les services écrivains existants. Elle
 * ne crée aucune nouvelle maille ni groupe `_TousLesPostes`.
 *
 * L'onglet « Overlay » est ajouté par la story 27.18 (hors scope 27.17).
 *
 * Sécurité : middleware `can:server.admin` sur la route + double guard mount()
 * (décision Henri : tout en `server.admin` sur cette page de défauts ; les
 * actions mutantes des partials re-gardent `Gate::authorize('server.admin')`).
 */
new #[Title('Configuration par défaut du parc')] class extends Component {
    /** Onglet actif (réactivité par URL — pattern maison DaisyUI tabs-boxed). */
    #[Url(keep: true)]
    public string $tab = 'wallpaper';

    /**
     * GUID de la GPO source (lien profond `?from_gpo=<GUID>` généré par
     * {@see \App\Gpo\Support\NativeSectionResolver}). Exposé en `#[Url]` pour
     * que le paramètre PERSISTE dans l'URL lors de la navigation par onglets
     * (Livewire) → le breadcrumb `<x-molecules.gpo-back-link>` reste affiché.
     */
    #[Url]
    public ?string $from_gpo = null;

    /** Onglets disponibles (l'overlay arrive en 27.18). */
    private const TABS = ['wallpaper', 'lockscreen', 'registry', 'apps', 'tools'];

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'wallpaper';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }
};
?>

<x-organisms.page title="Configuration par défaut du parc"
    icon="fa-solid fa-layer-group"
    description="Couche Broadcast appliquée par défaut à TOUS les postes (overridable par une config plus spécifique : parc, poste, utilisateur)."
    back="{{ route('admin.settings') }}">

    <div class="flex flex-col gap-6 pt-4">

        {{-- Breadcrumb de retour GPO (Story 16.3a) — rendu uniquement si la page
             a été atteinte depuis un lien profond `?from_gpo=<GUID>`. Le composant
             lit lui-même la query string (avec fallback Referer pour les updates
             Livewire). --}}
        @if ($from_gpo)
            <div>
                <x-molecules.gpo-back-link />
            </div>
        @endif

        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">Maille « Broadcast » — la base commune du parc</p>
                <p class="text-sm opacity-80">
                    Ce que vous éditez ici s'applique <strong>à tous les postes</strong> en dernière priorité.
                    Une configuration plus spécifique (parc, poste, utilisateur) <strong>prend le dessus</strong>
                    automatiquement. Les éléments <span class="badge badge-sm badge-error">obligatoires</span> sont
                    provisionnés côté serveur et poussés d'office ; les éléments facultatifs ne s'appliquent que si
                    vous les activez.
                </p>
            </div>
        </div>

        {{-- Onglets (composant partagé x-molecules.tabs, #[Url] $tab) --}}
        @php
            $parcDefaultsTabs = [
                'wallpaper' => ['label' => "Fond d'écran", 'icon' => 'fa-solid fa-image'],
                'lockscreen' => ['label' => 'Écran de verrouillage', 'icon' => 'fa-solid fa-lock'],
                'registry' => ['label' => 'Registre / capacités', 'icon' => 'fa-solid fa-sliders'],
                'apps' => ['label' => 'Applications', 'icon' => 'fa-solid fa-cube'],
                'tools' => ['label' => 'Outils agent', 'icon' => 'fa-solid fa-screwdriver-wrench'],
            ];
        @endphp
        <x-molecules.tabs :tabs="$parcDefaultsTabs" :active="$tab" />

        {{-- Contenu des onglets --}}
        <div class="flex flex-col">
            @if ($tab === 'wallpaper')
                <livewire:pages::admin.settings.parc-defaults._partials.wallpaper-tab />
            @elseif ($tab === 'lockscreen')
                <livewire:pages::admin.settings.parc-defaults._partials.lockscreen-tab />
            @elseif ($tab === 'registry')
                <livewire:pages::admin.settings.parc-defaults._partials.registry-tab />
            @elseif ($tab === 'apps')
                <livewire:pages::admin.settings.parc-defaults._partials.apps-tab />
            @elseif ($tab === 'tools')
                <livewire:pages::admin.settings.parc-defaults._partials.tools-tab />
            @endif
        </div>

    </div>
</x-organisms.page>
