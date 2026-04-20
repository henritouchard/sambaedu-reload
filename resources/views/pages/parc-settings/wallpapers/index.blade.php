<?php

use App\Components\Traits\WithToasts;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — défauts établissement (wallpaper + lockscreen).
 *
 * Story 4.7 — AC 8. Convention maison filesystem-based router.
 */
new #[Title('Fonds d\'écran — SE4FS')] class extends Component {
    use WithToasts;

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('wallpaper.manage'),
            403,
            'Permission wallpaper.manage requise.',
        );
    }
};
?>

<x-organisms.page
    title="Fonds d'écran"
    :scrollable="true"
    description="Paramétrez les fonds d'écran et écrans de verrouillage par défaut de l'établissement.">

    <div class="space-y-6">
        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">Hiérarchie de résolution</p>
                <p class="text-sm opacity-80">
                    Les fonds d'écran par défaut définis ici sont affichés quand aucun fond
                    spécifique (salle, groupe, utilisateur) n'est configuré. La priorité
                    va toujours au plus spécifique.
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <livewire:components.molecules.wallpaper-card
                type="wallpaper"
                :isDefault="true"
                title="Fond d'écran par défaut"
                description="Affiché sur tous les postes si aucune configuration plus spécifique n'existe."
                :key="'wallpaper-default'" />

            <livewire:components.molecules.wallpaper-card
                type="lockscreen"
                :isDefault="true"
                title="Écran de verrouillage par défaut"
                description="Affiché quand la session est verrouillée ou avant login (startup / lockscreen)."
                :key="'lockscreen-default'" />
        </div>
    </div>
</x-organisms.page>
