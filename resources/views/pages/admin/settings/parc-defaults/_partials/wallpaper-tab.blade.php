<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Story 27.17 — Onglet « Fond d'écran » de /admin/settings/parc-defaults.
 *
 * Édite le DÉFAUT établissement du wallpaper (couche Broadcast :
 * `wallpapers WHERE owner_id IS NULL AND is_default = true AND type='wallpaper'`)
 * en RÉUTILISANT le composant `wallpaper-card` (`isDefault=true`, `ownerType=null`)
 * et `WallpaperUploadService`. Aucune nouvelle table.
 *
 * Décision Henri : tout en `server.admin` sur cette page — on passe donc
 * `gate="server.admin"` au composant (au lieu du `wallpaper.manage` des pages
 * ciblées d'origine). Garde mount() en plus du middleware route.
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
        title="Fond d'écran par défaut"
        icon="fa-solid fa-image"
        color="primary"
        description="Affiché sur tous les postes si aucune configuration plus spécifique (parc, utilisateur) ne s'applique. Élément facultatif : tant qu'aucun fond n'est défini, le poste garde son fond système.">

        <div class="max-w-md col-span-full">
            <livewire:components::molecules.wallpaper-card
                type="wallpaper"
                :ownerType="null"
                :isDefault="true"
                gate="server.admin"
                title="Fond d'écran par défaut du parc"
                description="Diffusé à tous les postes (maille Broadcast)." />
        </div>
    </x-molecules.settings-section>
</div>
