<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light" class="preload">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SambaEdu' }}</title>

    {{-- Applique le thème sauvegardé avant le premier paint (anti-FOUC) --}}
    <script>
        try { document.documentElement.setAttribute('data-theme', localStorage.getItem('theme') || 'light'); } catch (e) {}
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {!! ToastMagic::styles() !!}

    {{-- Neutralise l'animation du drawer tant que le CSS n'est pas peint (évite le glitch « sidebar plié → déplié » au changement de page). --}}
    <script>
        window.addEventListener('load', () => {
            requestAnimationFrame(() => document.documentElement.classList.remove('preload'));
        });
    </script>

</head>

<body class="bg-base-200/30" x-data="{
    searchOpen: false,
    searchQuery: '',
    searchResults: [],
    availableLinks: {
        'Annuaire': [
            { title: 'Accès à l\'annuaire', description: 'Consulter l\'annuaire des utilisateurs', url: '/annu/annu.php' },
            { title: 'Modifier mon compte', description: 'Modifier les informations de mon compte', url: '/annu/mod_entry.php' },
            { title: 'Mon mot de passe', description: 'Changer mon mot de passe', url: '/annu/mod_pwd.php' },
            { title: 'Voir ma fiche', description: 'Afficher ma fiche personnelle', url: '/annu/me.php' },
            { title: 'Mon espace personnel', description: 'Accéder à mon espace personnel', url: '/individuel.php' },
            { title: 'Nettoyage des comptes', description: 'Nettoyer les comptes inutilisés', url: '/annu/ldap_cleaner.php' }
        ],
        'Bureau Distant': [
            { title: 'Se connecter', description: 'Connexion au bureau distant', url: '/parcs/rdp.php?login=henri.touchard&refresh=1' }
        ],
        'Informations système': [
            { title: 'Connexions actives', description: 'Voir les connexions Samba actives', url: '/parcs/smbstatus.php' },
            { title: 'Historique', description: 'Consulter l\'historique des connexions', url: '/parcs/show_histo.php' },
            { title: 'Fixer des quotas', description: 'Configurer les quotas utilisateurs', url: '/infos/quota_fixer.php' },
            { title: 'Quotas effectifs', description: 'Visualiser les quotas en cours', url: '/infos/quota_visu.php' },
            { title: 'Statistique d\'un poste', description: 'Voir les stats d\'un poste spécifique', url: '/stats/stats_poste.php' },
            { title: 'Statistique des postes', description: 'Voir les stats de tous les postes', url: '/stats/stats_postes.php' },
            { title: 'Statistique des postes2', description: 'Voir les stats avancées des postes', url: '/stats/stats_postes2.php' }
        ],
        'Gestion des partages': [
            { title: 'Répertoires de cloud', description: 'Gérer les répertoires cloud', url: '/partages/rep_cloud.php' },
            { title: 'Répertoires Partagés', description: 'Gérer les répertoires de classes', url: '/partages/rep_classes.php' },
            { title: 'Droits sur fichiers', description: 'Configurer les ACL des fichiers', url: '/acls/acls.php' },
            { title: 'Dossier échange', description: 'Gérer le dossier d\'échange', url: '/dossier_echange/dossier_echange.php' }
        ],
        'Gestion des imprimantes': [
            { title: 'Liste', description: 'Liste des imprimantes', url: '/printers/list_printers.php' },
            { title: 'Détails', description: 'Détails des imprimantes', url: '/printers/view_printers.php' },
            { title: 'Ajouter ou configurer', description: 'Configurer une imprimante', url: '/printers/config_printer.php' },
            { title: 'Supprimer', description: 'Supprimer une imprimante', url: '/printers/delete_printer_choice.php' },
            { title: 'Ajouter des pilotes Windows', description: 'Installer des pilotes Windows', url: '/printers/add_driver.php' }
        ],
        'Gestion des parcs': [
            { title: 'Liste des parcs', description: 'Afficher tous les parcs', url: '/parcs/show_parc.php' },
            { title: 'Action sur les parcs', description: 'Effectuer des actions sur les parcs', url: '/parcs/action_parc.php' },
            { title: 'Installations', description: 'Lancer des installations', url: '/parcs/lance_action.php' },
            { title: 'Programmer l\'allumage', description: 'Configuration WOL', url: '/parcs/wolstop_station.php?action=timing' },
            { title: 'Recherche et modification des machines', description: 'Chercher et modifier les machines', url: '/parcs/cherche_machine.php' },
            { title: 'Ajout et création', description: 'Créer un nouveau parc', url: '/parcs/create_parc.php' },
            { title: 'Renommage', description: 'Renommer un parc', url: '/parcs/rename_parc.php' },
            { title: 'Suppression', description: 'Supprimer un parc', url: '/parcs/delete_parc.php' },
            { title: 'Import CSV', description: 'Importer des données CSV', url: '/parcs/import_csv.php' },
            { title: 'Export CSV', description: 'Exporter des données CSV', url: '/parcs/export_csv.php' },
            { title: 'Délégation', description: 'Déléguer la gestion d\'un parc', url: '/parcs/delegate_parc.php' }
        ],
        'Clients et applications': [
            { title: 'Gestion des GPOs', description: 'Gérer les stratégies de groupe', url: '/gpo/gestion_gpo.php' },
            { title: 'Configuration des applications', description: 'Configurer les applications', url: '/gpo/gestion_apps.php' },
            { title: 'Configuration des fonds d\'écrans', description: 'Gérer les fonds d\'écran', url: '/gpo/wallpaper.php' },
            { title: 'Configuration des raccourcis', description: 'Gérer les raccourcis bureau', url: '/gpo/shortcuts.php' },
            { title: 'Configuration des applications Wine', description: 'Configurer Wine', url: '/gpo/wine.php' },
            { title: 'Sources Windows', description: 'Gérer les images Windows', url: '/ipxe/Win10/win_iso.php' }
        ],
        'Visioconférences': [
            { title: 'Créer un salon', description: 'Créer une room BigBlueButton', url: '/bbb/create.php' },
            { title: 'Rejoindre un salon', description: 'Rejoindre une room existante', url: '/bbb/join.php' },
            { title: 'Enregistrements', description: 'Gérer les enregistrements', url: '/bbb/records.php' }
        ],
        'Serveur DHCP': [
            { title: 'Gestion des baux', description: 'Gérer les baux DHCP', url: '/dhcp/baux.php' }
        ],
        'Applications Windows': [
            { title: 'Mise à jour des applications', description: 'Mettre à jour via WPKG', url: '/wpkg/depot_accueil.php' },
            { title: 'Gestion des applications', description: 'Gérer les applications WPKG', url: '/wpkg/app_liste.php' },
            { title: 'Gestion des parcs', description: 'Gérer les parcs WPKG', url: '/wpkg/parc_statuts.php' },
            { title: 'Gestion des postes', description: 'Gérer les postes WPKG', url: '/wpkg/poste_statuts.php' }
        ]
    },
    search() {
        if (this.searchQuery.length < 2) {
            this.searchResults = [];
            return;
        }

        let results = [];
        const query = this.searchQuery.toLowerCase();

        Object.entries(this.availableLinks).forEach(([category, links]) => {
            links.forEach(link => {
                let score = 0;
                if (link.title.toLowerCase().includes(query)) score += 10;
                if (link.description.toLowerCase().includes(query)) score += 5;
                if (category.toLowerCase().includes(query)) score += 3;

                if (score > 0) {
                    results.push({
                        ...link,
                        category: category,
                        score: score
                    });
                }
            });
        });

        this.searchResults = results
            .sort((a, b) => b.score - a.score)
            .slice(0, 8);
    }
}" @keydown.ctrl.k.prevent="searchOpen = true">


    <div class="drawer relative 2xl:drawer-open">
        <input id="drawer-toggle" type="checkbox" class="drawer-toggle" />
        <!-- Main content -->
        <div class="drawer-content  flex flex-col max-h-screen">
            <x-organisms.navbar />


            <!-- Page content -->
            <main class="flex-1 p-6 lg:pt-4 lg:py-0 max-h-full overflow-x-hidden relative z-10">
                {{ $slot }}
            </main>

            {!! ToastMagic::scripts() !!}
        </div>

        <x-organisms.sidebar />

        @livewireScripts

        {{-- Modals empilés depuis les composants --}}
        @stack('modals')

        <script>
            document.addEventListener('alpine:init', () => {
                // Configuration Alpine.js pour l'interface admin
                console.log('Alpine.js initialisé par Livewire');
            });

            // Gérer le focus sur l'input de recherche
            document.addEventListener('alpine:initialized', () => {
                document.addEventListener('keydown', (e) => {
                    if (e.ctrlKey && e.key === 'k') {
                        e.preventDefault();
                        const searchOpen = Alpine.$data(document.body).searchOpen;
                        if (!searchOpen) {
                            Alpine.$data(document.body).searchOpen = true;
                            setTimeout(() => {
                                const input = document.querySelector('[x-ref="searchInput"]');
                                if (input) input.focus();
                            }, 100);
                        }
                    }
                });
            });
        </script>

        {{-- Scripts empilés depuis les composants --}}
        @stack('scripts')

        <!-- Modal de confirmation globale (en dehors de tout contexte d'empilement) -->
        <x-molecules.confirm-modal />
</body>

</html>