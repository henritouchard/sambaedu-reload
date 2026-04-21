<!-- Sidebar -->
<div class="drawer-side">
    <label for="drawer-toggle" aria-label="close sidebar" class="drawer-overlay"></label>
    <aside class="min-h-full w-80 border-r border-gray-200 bg-base-100 shadow-xl">
        <!-- Sidebar header -->
        <div class="p-6 backdrop-blur-sm">
            <h2 class="text-xl font-bold text-base-content flex items-center justify-center">
                <img src="{{ asset('img/LogoSambaEdu.png') }}" alt="Logo SambaEdu" class="w-72 h-auto object-contain" />
            </h2>
        </div>

        <!-- Sidebar content -->
        <div class="p-6 space-y-4">
            <ul class="menu w-full space-y-6">
                <li>
                    <a href="{{ route('app.dashboard') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/dashboard*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <x-icons.dashboard></x-icons.dashboard>
                        Tableau de bord
                    </a>
                </li>
                <li>
                    <a href="{{ route('app.users') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/users*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <i class="fa-solid fa-users text-xl"></i>
                        Utilisateurs
                    </a>
                </li>
                <li>
                    <a href="{{ route('app.rights-management') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/rights-management*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <i class="fa-solid fa-shield-halved text-xl"></i>
                        Gestion des droits
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.controlHub.control-hub') }}"
                        class="flex items-center gap-4 py-3 text-base font-medium {{ request()->is('admin/controlHub/*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <x-icons.controlHub />
                        Controlhub
                    </a>
                </li>
                <li>
                    <a href="{{ route('app.parc.index') }}"
                        class="flex items-center gap-4 py-3 text-base font-medium {{ request()->is('app/parc') || request()->is('app/parc/*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                            </path>
                        </svg>
                        Gestion du parc
                    </a>
                </li>
                <li>
                    <a href="{{ route('app.parc-settings.index') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/parc-settings') || request()->is('app/parc-settings/profiles*') || request()->is('app/parc-settings/applications*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <x-icons.apps />
                        Applications
                    </a>
                </li>
                @can('wallpaper.manage')
                <li>
                    <a href="{{ route('app.parc-settings.wallpapers') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('app/parc-settings/wallpapers*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <i class="fa-solid fa-image text-xl"></i>
                        Fonds d'écran
                    </a>
                </li>
                @endcan
                <li>
                    <a href="{{ route('admin.migrate') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium {{ request()->is('admin/migrate*') ? 'active bg-primary/20 text-primary shadow-lg' : 'hover:bg-base-200/70' }} rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <i class="fa-solid fa-exchange-alt text-xl"></i>
                        Migration
                    </a>
                </li>
                {{-- <li>
                    <a href="{{ url('/parcs/rdp.php?login=' . (Auth::user()->login ?? 'henri.touchard') . '&refresh=1') }}"
                        class="flex items-center gap-4 px-4 py-3 text-base font-medium hover:bg-base-200/70 rounded-xl transition-all duration-200 hover:shadow-md hover:scale-[1.02]">
                        <x-icons.remoteDesktop />
                        Bureau distant
                    </a>
                </li> --}}
            </ul>


            {{--
            <div class="divider my-6"></div>

            <!-- Annuaire -->
            <div
                class="collapse collapse-arrow bg-gradient-to-r from-base-200/60 to-base-100/40 backdrop-blur-sm border border-base-300/50 rounded-xl overflow-hidden">
                <input type="checkbox" class="peer" />
                <div
                    class="collapse-title text-base font-semibold flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 transition-colors">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                        </path>
                    </svg>
                    Utilisateurs
                </div>
                <div class="collapse-content px-4 pb-4">
                    <div class="space-y-2">
                        <a href="{{ url('/annu2/annu.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Accès
                            à
                            l'annuaire</a>
                        <a href="{{ url('/annu2/mod_entry.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Modifier
                            mon
                            compte</a>
                        <a href="{{ url('/annu2/mod_pwd.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Mon
                            mot de
                            passe</a>
                        <a href="{{ url('/annu2/me.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Voir
                            ma
                            fiche</a>
                        <a href="{{ url('/individuel.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Mon
                            espace
                            personnel</a>
                        <a href="{{ url('/annu/ldap_cleaner.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Nettoyage
                            des comptes</a>
                    </div>
                </div>
            </div>


            <!-- Informations système -->
            <div
                class="collapse collapse-arrow bg-gradient-to-r from-base-200/60 to-base-100/40 backdrop-blur-sm border border-base-300/50 rounded-xl overflow-hidden">
                <input type="checkbox" class="peer" />
                <div
                    class="collapse-title text-base font-semibold flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 transition-colors">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z">
                        </path>
                    </svg>
                    Informations système
                </div>
                <div class="collapse-content px-4 pb-4">
                    <div class="space-y-2">
                        <a href="{{ url('/parcs/smbstatus.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Connexions
                            actives</a>
                        <a href="{{ url('/parcs/show_histo.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Historique</a>
                        <a href="{{ url('/infos/quota_fixer.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Fixer
                            des
                            quotas</a>
                        <a href="{{ url('/infos/quota_visu.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Quotas
                            effectifs</a>
                        <a href="{{ url('/stats/stats_poste.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Statistique
                            d'un poste</a>
                        <a href="{{ url('/stats/stats_postes.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Statistique
                            des postes</a>
                        <a href="{{ url('/stats/stats_postes2.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Statistique
                            des postes2</a>
                    </div>
                </div>
            </div>

            <!-- Gestion des partages -->
            <div
                class="collapse collapse-arrow bg-gradient-to-r from-base-200/60 to-base-100/40 backdrop-blur-sm border border-base-300/50 rounded-xl overflow-hidden">
                <input type="checkbox" class="peer" />
                <div
                    class="collapse-title text-base font-semibold flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 transition-colors">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                    </svg>
                    Gestion des partages
                </div>
                <div class="collapse-content px-4 pb-4">
                    <div class="space-y-2">
                        <a href="{{ url('/partages/rep_cloud.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Répertoires
                            de cloud</a>
                        <a href="{{ url('/partages/rep_classes.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Répertoires
                            Partagés</a>
                        <a href="{{ url('/acls/acls.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Droits
                            sur
                            fichiers</a>
                        <a href="{{ url('/dossier_echange/dossier_echange.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Dossier
                            échange</a>
                    </div>
                </div>
            </div>

            <!-- Gestion des imprimantes -->
            <div
                class="collapse collapse-arrow bg-gradient-to-r from-base-200/60 to-base-100/40 backdrop-blur-sm border border-base-300/50 rounded-xl overflow-hidden">
                <input type="checkbox" class="peer" />
                <div
                    class="collapse-title text-base font-semibold flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 transition-colors">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z">
                        </path>
                    </svg>
                    Gestion des imprimantes
                </div>
                <div class="collapse-content px-4 pb-4">
                    <div class="space-y-2">
                        <a href="{{ url('/printers/list_printers.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Liste</a>
                        <a href="{{ url('/printers/view_printers.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Détails</a>
                        <a href="{{ url('/printers/config_printer.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Ajouter
                            ou
                            configurer</a>
                        <a href="{{ url('/printers/delete_printer_choice.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Supprimer</a>
                        <a href="{{ url('/printers/add_driver.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Ajouter
                            des
                            pilotes Windows</a>
                    </div>
                </div>
            </div>

            <!-- Gestion des parcs -->
            <div
                class="collapse collapse-arrow bg-gradient-to-r from-base-200/60 to-base-100/40 backdrop-blur-sm border border-base-300/50 rounded-xl overflow-hidden">
                <input type="checkbox" class="peer" />
                <div
                    class="collapse-title text-base font-semibold flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 transition-colors">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                        </path>
                    </svg>
                    Gestion des parcs
                </div>
                <div class="collapse-content px-4 pb-4">
                    <div class="space-y-2">
                        <a href="{{ url('/parcs/show_parc.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1 bg-primary/10 text-primary font-medium">Liste
                            des parcs</a>
                        <a href="{{ url('/parcs/action_parc.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Action
                            sur
                            les parcs</a>
                        <a href="{{ url('/parcs/lance_action.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Installations</a>

                        <a href="{{ url('/parcs/wolstop_station.php?action=timing') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Programmer
                            l'allumage</a>
                        <a href="{{ url('/parcs/cherche_machine.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Recherche
                            et
                            modification des machines</a>
                        <a href="{{ url('/parcs/create_parc.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Ajout
                            et
                            création</a>
                        <a href="{{ url('/parcs/rename_parc.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Renommage</a>
                        <a href="{{ url('/parcs/delete_parc.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Suppression</a>
                        <a href="{{ url('/parcs/import_csv.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Import
                            CSV</a>
                        <a href="{{ url('/parcs/export_csv.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Export
                            CSV</a>
                        <a href="{{ url('/parcs/delegate_parc.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Délégation</a>
                    </div>
                </div>
            </div>

            <!-- Clients et applications -->
            <div
                class="collapse collapse-arrow bg-gradient-to-r from-base-200/60 to-base-100/40 backdrop-blur-sm border border-base-300/50 rounded-xl overflow-hidden">
                <input type="checkbox" class="peer" />
                <div
                    class="collapse-title text-base font-semibold flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 transition-colors">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                    Clients et applications
                </div>
                <div class="collapse-content px-4 pb-4">
                    <div class="space-y-2">
                        <a href="{{ url('/gpo/gestion_gpo.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Gestion
                            des
                            GPOs</a>
                        <a href="{{ url('/gpo/gestion_apps.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Configuration
                            des applications</a>
                        <a href="{{ url('/gpo/wallpaper.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Configuration
                            des fonds d'écrans</a>
                        <a href="{{ url('/gpo/shortcuts.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Configuration
                            des raccourcis</a>
                        <a href="{{ url('/gpo/wine.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Configuration
                            des applications Wine</a>
                        <a href="{{ url('/ipxe/Win10/win_iso.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Sources
                            Windows</a>
                    </div>
                </div>
            </div>

            <!-- Visioconférences -->
            <div
                class="collapse collapse-arrow bg-gradient-to-r from-base-200/60 to-base-100/40 backdrop-blur-sm border border-base-300/50 rounded-xl overflow-hidden">
                <input type="checkbox" class="peer" />
                <div
                    class="collapse-title text-base font-semibold flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 transition-colors">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z">
                        </path>
                    </svg>
                    Visioconférences
                </div>
                <div class="collapse-content px-4 pb-4">
                    <div class="space-y-2">
                        <a href="{{ url('/bbb/create.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Créer
                            un
                            salon</a>
                        <a href="{{ url('/bbb/join.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Rejoindre
                            un
                            salon</a>
                        <a href="{{ url('/bbb/records.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Enregistrements</a>
                    </div>
                </div>
            </div>

            <!-- Serveur dhcp -->
            <div
                class="collapse collapse-arrow bg-gradient-to-r from-base-200/60 to-base-100/40 backdrop-blur-sm border border-base-300/50 rounded-xl overflow-hidden">
                <input type="checkbox" class="peer" />
                <div
                    class="collapse-title text-base font-semibold flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 transition-colors">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01">
                        </path>
                    </svg>
                    Serveur DHCP
                </div>
                <div class="collapse-content px-4 pb-4">
                    <div class="space-y-2">
                        <a href="{{ url('/dhcp/baux.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Gestion
                            des
                            baux</a>
                    </div>
                </div>
            </div>

            <!-- Applications Windows -->
            <div
                class="collapse collapse-arrow bg-gradient-to-r from-base-200/60 to-base-100/40 backdrop-blur-sm border border-base-300/50 rounded-xl overflow-hidden">
                <input type="checkbox" class="peer" />
                <div
                    class="collapse-title text-base font-semibold flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 transition-colors">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z">
                        </path>
                    </svg>
                    Applications Windows
                </div>
                <div class="collapse-content px-4 pb-4">
                    <div class="space-y-2">
                        <a href="{{ url('/wpkg/depot_accueil.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Mise
                            à jour
                            des applications</a>
                        <a href="{{ url('/wpkg/app_liste.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Gestion
                            des
                            applications</a>
                        <a href="{{ url('/wpkg/parc_statuts.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Gestion
                            des
                            parcs</a>
                        <a href="{{ url('/wpkg/poste_statuts.php') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Gestion
                            des
                            postes</a>
                        <a href="{{ route('app.windows-deploy.reports.index') }}"
                            class="block px-4 py-2 text-sm hover:bg-base-300/70 rounded-lg transition-colors hover:translate-x-1">Rapports
                            WPKG</a>
                    </div>
                </div>
            </div>

        </div>
            --}}
    </aside>
</div>
