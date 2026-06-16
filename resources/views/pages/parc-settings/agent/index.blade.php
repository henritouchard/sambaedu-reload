<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 25.5 — Page parc-settings/agent : console de pilotage de la flotte.
 *
 * Trois surfaces sur une seule page (convention `pages/` filesystem-router,
 * Livewire SFC, `can:computer.install`) :
 *   1. Releases & rings — voir les releases publiées (25.1), la version ciblée
 *      par ring, cibler/rollback un ring (`target()`), définir la stable par
 *      défaut (`promote()`). Tout passe par `ReleaseCreationService`.
 *   2. Enrôlements en attente — surface d'approbation porte 2 (25.3), étendue
 *      en 25.5 à l'approbation d'un poste « inconnu » par sélection de cible.
 *   3. Progression du déploiement — versions rapportées par les agents,
 *      groupées par ring (à jour / en retard / jamais vus).
 *
 * La page n'embarque aucune logique métier : elle compose les partials.
 */
new #[Title('Agent — Flotte - SE4FS')] class extends Component
{
    //
};
?>

<x-organisms.page title="Agent — Flotte" icon="fa-solid fa-shield-halved"
    description="Pilotez les rings de déploiement, les demandes d'enrôlement et les releases de la flotte agent.">

    <div class="flex flex-col gap-10">
        {{-- Surface 1 : releases & rings (25.1, pilotage via ReleaseCreationService) --}}
        <section>
            <livewire:pages::parc-settings.agent._partials.releases-rings />
        </section>

        {{-- Surface 4 : catalogue d'outils — portable Rainmeter uploadé + toggle (25.6) --}}
        <section>
            <h2 class="text-xl font-bold mb-3 flex items-center gap-2">
                <i class="fa-solid fa-wrench text-primary"></i> Outils du parc
            </h2>
            <livewire:pages::parc-settings.agent._partials.tools-catalog />
        </section>

        {{-- Surface 3 : progression du déploiement (lecture seule) --}}
        <section>
            <h2 class="text-xl font-bold mb-3 flex items-center gap-2">
                <i class="fa-solid fa-chart-line text-primary"></i> Progression du déploiement
            </h2>
            <livewire:pages::parc-settings.agent._partials.deployment-progress />
        </section>

        {{-- Surface 2 : enrôlements en attente (porte 2, 25.3 + extension 25.5) --}}
        <section>
            <h2 class="text-xl font-bold mb-3 flex items-center gap-2">
                <i class="fa-solid fa-user-shield text-primary"></i> Enrôlements en attente
            </h2>
            <livewire:pages::parc-settings.agent._partials.enrollment-requests />
        </section>
    </div>
</x-organisms.page>
