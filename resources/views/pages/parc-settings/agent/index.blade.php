<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 25.3 — Page agent desired-state (squelette minimal).
 *
 * Ne porte ICI que la surface d'approbation des enrôlements porte 2
 * (poste migré sans ticket → demande pending → approbation un-clic). Les rings,
 * releases et la progression de convergence sont la story 25.5 : ne pas les
 * ajouter ici (elle intégrera le partial livré ci-dessous dans sa page complète).
 */
new #[Title('Agent — Enrôlements - SE4FS')] class extends Component
{
    //
};
?>

<x-organisms.page title="Agent — Enrôlements" icon="fa-solid fa-shield-halved"
    description="Approuvez les postes migrés qui demandent à rejoindre le système (porte 2)">

    <div class="h-full flex flex-col gap-4">
        <livewire:pages::parc-settings.agent._partials.enrollment-requests />
    </div>
</x-organisms.page>
