<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /admin/settings/profils-itinerants — Réglages profils itinérants.
 *
 * Wrapper de page autour du composant Livewire historique
 * `pages::admin.settings._partials.profils-itinerants-tab` (ex-onglet de
 * /admin/settings, couvert par AdminSettingsProfilsItinerantsTabTest).
 *
 * Sécurité : middleware can:server.admin sur la route + double guard mount().
 */
new #[Title('Profils itinérants')] class extends Component {
    public function mount(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }
    }
};
?>

<x-organisms.page title="Profils itinérants"
    icon="fa-solid fa-users-gear"
    description="Exclusions ExcludeProfileDirs et statistiques globales des dossiers roaming"
    back="{{ route('admin.settings') }}">

    <x-slot:actions>
        <x-molecules.gpo-back-link />
    </x-slot:actions>

    <div class="flex-1 min-h-0 flex flex-col">
        <livewire:pages::admin.settings._partials.profils-itinerants-tab />
    </div>
</x-organisms.page>
