<!-- Search Component -->
<div x-data="{
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
    <!-- Search Button -->
    <button class="" @click="searchOpen = true">
        <x-atoms.searchInput placeholder="Rechercher..." icon="fa-magnifying-glass" />
    </button>

    <!-- Search Modal -->
    <div x-show="searchOpen" x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0" class="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4"
        style="display: none;">
        <div class="fixed inset-0 bg-black/50" @click="searchOpen = false"></div>
        <div class="relative w-full max-w-lg bg-base-100 rounded-lg shadow-xl border border-base-200" @click.stop>
            <div class="p-4">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-base-content/60"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <input type="text" placeholder="Rechercher..."
                        class="input input-bordered w-full pl-10 pr-4" x-model="searchQuery" @input="search()"
                        x-ref="searchInput" @keydown.escape="searchOpen = false; searchQuery = ''"
                        x-init="$watch('searchOpen', value => { if (value) setTimeout(() => $el.focus(), 100) })" />
                </div>

                <!-- Search Results -->
                <div class="mt-4 max-h-96 overflow-y-auto">
                    <template x-if="searchResults.length > 0">
                        <div class="space-y-2">
                            <template x-for="result in searchResults" :key="result.url">
                                <a :href="result.url"
                                    class="block p-3 hover:bg-base-200 rounded-md transition-colors border border-base-200">
                                    <div class="font-medium text-sm" x-text="result.title"></div>
                                    <div class="text-xs text-base-content/60 mt-1" x-text="result.description">
                                    </div>
                                    <div class="text-xs text-primary mt-1" x-text="result.category"></div>
                                </a>
                            </template>
                        </div>
                    </template>

                    <template x-if="searchQuery.length >= 2 && searchResults.length === 0">
                        <div class="text-center py-8 text-base-content/60">
                            <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <p>Aucun résultat trouvé pour "<span x-text="searchQuery"></span>"</p>
                        </div>
                    </template>

                    <template x-if="searchQuery.length < 2">
                        <div class="text-center py-8 text-base-content/60">
                            <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <p>Tapez pour rechercher...</p>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-4 py-3 bg-base-200/50 rounded-b-lg border-t border-base-200">
                <div class="flex items-center justify-between text-xs text-base-content/60">
                    <div>
                        <span x-show="searchResults.length > 0">
                            <span x-text="searchResults.length"></span> résultat(s) trouvé(s)
                        </span>
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="text-xs">Ctrl+K pour ouvrir</span>
                        <button @click="searchOpen = false; searchQuery = ''"
                            class="text-xs hover:text-base-content transition-colors">
                            Fermer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
