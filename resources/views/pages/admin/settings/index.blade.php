<?php

use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Story 5.1c — Page de réglages système /admin/settings.
 *
 * Scaffold à onglets extensible décalqué sur `pages/parc-settings/index.blade.php`
 * (pattern `#[Url(keep: true)] public string $tab` + `setTab()` + tablist).
 *
 * Onglets :
 *   - "Quotas & FS" (5.1c — D3=A interdiction stricte de placeholders "coming soon").
 *   - "Profils itinérants" (1bis.18f — gestion ExcludeProfileDirs + stats roaming).
 * Futurs onglets (DHCP, CUPS, ...) ajoutés dans leurs Epics respectives.
 *
 * Sécurité : middleware `can:server.admin` sur la route + double guard serveur
 * `Gate::allows('server.admin')` ici (paranoïa AC 12).
 */
new #[Title('Réglages système')] class extends Component
{
    use WithToasts;

    #[Url(keep: true)]
    public string $tab = 'quotas-fs';

    public function mount(): void
    {
        // Double guard serveur — paranoïa : la route a déjà un can:server.admin.
        if (!Gate::allows('server.admin')) {
            abort(403);
        }
    }

    public function setTab(string $tab): void
    {
        // Double guard serveur sur chaque action publique (AC 12 — payload
        // Livewire forgé même par un user sans permission ne doit pas pouvoir
        // changer d'onglet ou ré-émettre un render).
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        // `#[Url(keep: true)]` synchronise la query string automatiquement —
        // pas besoin de full redirect (cf. review 5.1c #9).
        $this->tab = $tab;
    }
};
?>

<x-organisms.page title="Réglages" :scrollable="false"
    description="Configuration système globale — quotas, période de grâce, corbeille">

    {{--
        Story 16.3a — AC4.2 / Piège 3 : breadcrumb retour GPO affiché UNIQUEMENT
        quand l'onglet actif est 'profils-itinerants' (évite la pollution sur autres tabs).
        Placé dans le slot `actions` (header droit) pour cohérence avec les 3 autres
        pages cibles wallpapers / app-customizations / shortcuts (review 16.3a #4).
    --}}
    @if ($tab === 'profils-itinerants')
        <x-slot:actions>
            <x-molecules.gpo-back-link />
        </x-slot:actions>
    @endif

    <div class="h-full flex flex-col gap-4">

        {{-- Onglets de la page settings. --}}
        <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit">
            <button type="button" role="tab" class="tab {{ $tab === 'quotas-fs' ? 'tab-active' : '' }}"
                wire:click="setTab('quotas-fs')">
                <i class="fa-solid fa-hard-drive mr-2"></i>
                Quotas & FS
            </button>
            <button type="button" role="tab" class="tab {{ $tab === 'profils-itinerants' ? 'tab-active' : '' }}"
                wire:click="setTab('profils-itinerants')">
                <i class="fa-solid fa-users-gear mr-2"></i>
                Profils itinérants
            </button>
        </div>

        {{-- Contenu de l'onglet actif. --}}
        <div class="flex-1 min-h-0 flex flex-col overflow-y-auto">
            @if ($tab === 'quotas-fs')
                <livewire:pages::admin.settings._partials.quotas-fs-tab />
            @elseif ($tab === 'profils-itinerants')
                <livewire:pages::admin.settings._partials.profils-itinerants-tab />
            @endif
        </div>
    </div>
</x-organisms.page>
