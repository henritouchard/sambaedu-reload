<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Story 27.17 — Onglet « Écran de verrouillage » de /admin/settings/parc-defaults.
 *
 * Idem onglet wallpaper, avec `type='lockscreen'` : même composant
 * `wallpaper-card` + même `WallpaperUploadService` (le service distingue le
 * type). Couche Broadcast (défaut établissement, overridable).
 *
 * Décision Henri : tout en `server.admin` — `gate="server.admin"` au composant.
 */
new class extends Component {
    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }
    }
};
?>

<div>
    <x-molecules.settings-section
        title="Écran de verrouillage par défaut"
        icon="fa-solid fa-lock"
        color="primary"
        description="Image de l'écran de verrouillage Windows (scope machine) appliquée par défaut à tous les postes. Élément facultatif.">

        <div class="max-w-md">
            <livewire:components::molecules.wallpaper-card
                type="lockscreen"
                :ownerType="null"
                :isDefault="true"
                gate="server.admin"
                title="Écran de verrouillage par défaut du parc"
                description="Diffusé à tous les postes (maille Broadcast)." />
        </div>
    </x-molecules.settings-section>
</div>
