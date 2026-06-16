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
            description="Quotas, profils itinérants, jobs en arrière-plan et logs d'exécution.">

            <x-molecules.settings-card
                href="{{ route('admin.settings.system-status') }}"
                icon="fa-solid fa-heart-pulse"
                iconColor="primary"
                title="État du système"
                description="Connectivité AD, base de données, controlHub, Apache, iPXE et distros installables."
                badge="Diagnostic"
                testid="card-system-status" />

            <x-molecules.settings-card
                href="{{ route('admin.quotas') }}"
                icon="fa-solid fa-hard-drive"
                iconColor="primary"
                title="Quotas & FS"
                description="Quotas par profil, période de grâce et corbeille XFS."
                badge="Stockage"
                testid="card-quotas" />

            <x-molecules.settings-card
                href="{{ route('admin.settings.profils-itinerants') }}"
                icon="fa-solid fa-users-gear"
                iconColor="primary"
                title="Profils itinérants"
                description="Exclusions ExcludeProfileDirs et statistiques globales des dossiers roaming."
                badge="Roaming"
                testid="card-profils-itinerants" />

            <x-molecules.settings-card
                href="{{ route('admin.system.jobs.index') }}"
                icon="fa-solid fa-list-check"
                iconColor="primary"
                title="Jobs système"
                description="Dashboard des jobs Laravel et tâches en arrière-plan."
                badge="Monitoring"
                testid="card-jobs" />

            <x-molecules.settings-card
                href="{{ route('admin.scripts-logs.index') }}"
                icon="fa-solid fa-scroll"
                iconColor="primary"
                title="Logs scripts"
                description="Consultation des logs d'exécution des scripts (samba-tool, etc.)."
                badge="Diagnostic"
                testid="card-scripts-logs" />

            <x-molecules.settings-card
                href="{{ route('admin.settings.credentials') }}"
                icon="fa-solid fa-key"
                iconColor="primary"
                title="Compte se4install"
                description="Rotation TOTP 6 h du mot de passe AD du compte de déploiement."
                badge="Sécurité"
                testid="card-credentials" />
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
                href="{{ route('admin.gpo.sections') }}"
                icon="fa-solid fa-puzzle-piece"
                iconColor="secondary"
                title="Sections natives"
                description="Catalogue des sections reconnues (wallpapers, shortcuts, firefox…)."
                badge="Sections"
                testid="card-gpo-sections" />

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
            description="Outils d'assistance à la migration SE4 → SE5 (sambaedu-reload).">

            <x-molecules.settings-card
                href="{{ route('admin.sync-from-ad') }}"
                icon="fa-solid fa-rotate"
                iconColor="primary"
                title="Sync from AD"
                description="Synchronisation depuis l'Active Directory : utilisateurs, groupes et structures SE4FS."
                badge="Synchronisation"
                badgeColor="primary"
                testid="card-sync-from-ad" />

            <x-molecules.settings-card
                href="{{ route('admin.error-logger') }}"
                icon="fa-solid fa-bug"
                iconColor="error"
                title="Error Logger"
                description="Erreurs capturées en temps réel (legacy PHP & exceptions Laravel)."
                badge="Diagnostic"
                badgeColor="error"
                testid="card-error-logger" />

            <x-molecules.settings-card
                href="{{ route('admin.legacy-monitor') }}"
                icon="fa-solid fa-eye"
                iconColor="warning"
                title="Legacy Monitor"
                description="Surveillance des appels catchall pour identifier les routes legacy encore utilisées."
                badge="Monitoring"
                badgeColor="warning"
                testid="card-legacy-monitor" />

            <x-molecules.settings-card
                href="{{ route('admin.homelegacy') }}"
                icon="fa-solid fa-compass"
                iconColor="info"
                title="Navigation Legacy"
                description="Accès aux menus et pages de l'ancienne interface SE4FS embarquée."
                badge="Legacy"
                badgeColor="info"
                testid="card-homelegacy" />
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
