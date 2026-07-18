<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /admin/settings — Landing « Réglages ».
 *
 * Page d'index regroupant en sections thématiques (Système, GPO, Migration,
 * Réseau & intégrations) les liens vers les pages de configuration.
 *
 * Sécurité : middleware can:server.admin sur la route + double guard mount().
 */
new #[Title('Réglages')] class extends Component {
    public function mount(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }
    }
};
?>

<x-organisms.page title="Réglages"
    icon="fa-solid fa-sliders"
    description="Configuration et administration du serveur SambaEdu">

    <div class="flex flex-col gap-10 pt-4">

        {{-- ============================================================
             Section Système
             ============================================================ --}}
        <x-molecules.settings-section
            title="Système"
            icon="fa-solid fa-gears"
            color="primary"
            description="État du système, jobs en arrière-plan et logs d'exécution.">

            <x-molecules.settings-card
                href="{{ route('admin.settings.system-status') }}"
                icon="fa-solid fa-heart-pulse"
                iconColor="primary"
                title="État du système"
                description="Connectivité AD, base de données, controlHub, Apache, iPXE et distros installables (Général) ; journaux d'erreurs runtime legacy PHP + Laravel (Logs)."
                badge="Diagnostic"
                testid="card-system-status" />

            <x-molecules.settings-card
                href="{{ route('admin.settings.credentials') }}"
                icon="fa-solid fa-key"
                iconColor="primary"
                title="Compte se4install"
                description="Rotation TOTP 6 h du mot de passe AD du compte de déploiement."
                badge="Sécurité"
                testid="card-credentials" />

            <x-molecules.settings-card
                href="{{ route('admin.settings.security') }}"
                icon="fa-solid fa-user-clock"
                iconColor="primary"
                title="Sécurité & session"
                description="Déconnexion automatique de l'interface sur inactivité (durée de session)."
                badge="Sécurité"
                testid="card-security" />
        </x-molecules.settings-section>

        {{-- ============================================================
             Section Agent / Flotte
             ============================================================ --}}
        <x-molecules.settings-section
            title="Agent / Flotte"
            icon="fa-solid fa-shield-halved"
            color="primary"
            description="Pilotage de l'agent desired-state : rings de déploiement, releases, enrôlements et catalogue d'outils.">

            <x-molecules.settings-card
                href="{{ route('admin.settings.agent') }}"
                icon="fa-solid fa-shield-halved"
                iconColor="primary"
                title="Console de la flotte"
                description="Rings & releases, demandes d'enrôlement, progression du déploiement et outils du parc."
                badge="Agent"
                testid="card-agent" />

            <x-molecules.settings-card
                href="{{ route('admin.settings.parc-defaults') }}"
                icon="fa-solid fa-layer-group"
                iconColor="primary"
                title="Configuration par défaut du parc"
                description="Couche Broadcast appliquée à tous les postes : fond d'écran, écran de verrouillage, registre, applications par défaut et outils agent."
                badge="Broadcast"
                testid="card-parc-defaults" />
        </x-molecules.settings-section>

        {{-- ============================================================
             Section GPO
             ============================================================ --}}
        <x-molecules.settings-section
            title="GPO Active Directory"
            icon="fa-solid fa-file-code"
            color="secondary"
            description="Gestion native des Group Policy Objects et de leurs sections.">

            <x-molecules.settings-card
                href="{{ route('admin.gpo.index') }}"
                icon="fa-solid fa-folder-tree"
                iconColor="secondary"
                title="Toutes les GPOs"
                description="Listing global avec filtres avancés et détails par GUID."
                badge="Catalogue"
                testid="card-gpo-index" />

            <x-molecules.settings-card
                href="{{ route('admin.gpo.by-ou') }}"
                icon="fa-solid fa-sitemap"
                iconColor="secondary"
                title="Vue par OU"
                description="Vue inverse : OUs Active Directory → GPOs liées + héritage."
                badge="Hiérarchie"
                testid="card-gpo-by-ou" />

            <x-molecules.settings-card
                href="{{ route('admin.gpo.wine') }}"
                icon="fa-solid fa-wine-bottle"
                iconColor="secondary"
                title="Wine — Apps Linux"
                description="Configuration des applications Wine déployées sur les postes Linux."
                badge="Wine"
                testid="card-gpo-wine" />

            <x-molecules.settings-card
                href="{{ route('admin.gpo.wpkg-deployment') }}"
                icon="fa-solid fa-box-archive"
                iconColor="secondary"
                title="WPKG — Pipeline"
                description="Pipeline de déploiement WPKG : étapes, statuts et historique."
                badge="WPKG"
                testid="card-gpo-wpkg" />
        </x-molecules.settings-section>

        {{-- ============================================================
             Section Migration
             ============================================================ --}}
        <x-molecules.settings-section
            title="Migration"
            icon="fa-solid fa-exchange-alt"
            color="warning"
            description="Outils d'assistance à la migration SE4 → SE5 et observabilité du canal legacy — voués à disparaître une fois le parc bascule agent.">

            <x-molecules.settings-card
                href="{{ route('admin.settings.migration') }}"
                icon="fa-solid fa-exchange-alt"
                iconColor="warning"
                title="Migration SE4 → SE5"
                description="Sync from AD, logs d'exécution des scripts et legacy monitor — regroupés en onglets."
                badge="Migration"
                badgeColor="warning"
                testid="card-migration" />
        </x-molecules.settings-section>

        {{-- ============================================================
             Section Réseau & intégrations
             ============================================================ --}}
        <x-molecules.settings-section
            title="Réseau & intégrations"
            icon="fa-solid fa-network-wired"
            color="info"
            description="Réseau local et intégrations avec les services distants.">

            @can('viewAny-dhcp')
                <x-molecules.settings-card
                    href="{{ route('app.network.dhcp') }}"
                    icon="fa-solid fa-network-wired"
                    iconColor="info"
                    title="Réseau DHCP"
                    description="Sous-réseaux, baux, plages d'adresses et import de la configuration DHCP."
                    badge="DHCP"
                    testid="card-dhcp" />
            @endcan

            @can('server.admin')
                <x-molecules.settings-card
                    href="{{ route('admin.settings.files') }}"
                    icon="fa-solid fa-folder-tree"
                    iconColor="info"
                    title="Gestion des fichiers"
                    description="Politique d'accès aux fichiers (partages réseau / Nextcloud), quotas &amp; FS, lecteurs réseau gérés et profils itinérants."
                    badge="Fichiers"
                    testid="card-files" />
            @endcan

            <x-molecules.settings-card
                href="{{ route('admin.controlHub.control-hub') }}"
                icon="fa-solid fa-satellite-dish"
                iconColor="info"
                title="ControlHub"
                description="Handshake et supervision via le hub de contrôle central irundo."
                badge="Intégration"
                testid="card-controlhub" />
        </x-molecules.settings-section>

    </div>
</x-organisms.page>
