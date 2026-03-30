<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Navigation Legacy')] class extends Component {

    public array $menu = [];

    public function mount()
    {
        $uai = config('sambaedu.etab_ou', '');
        $prefix = !empty($uai) ? '/' . $uai : '';

        $this->menu = [
            [
                'title' => 'Configuration generale',
                'links' => [
                    ['text' => 'Parametres serveur', 'href' => $prefix . '/conf_params.php?cat=1'],
                    ['text' => 'Integration ENT ou CSV', 'href' => $prefix . '/annu/config_ent.php?cat=1'],
                    ['text' => 'Profils de droits', 'href' => $prefix . '/annu/profiles.php'],
                    ['text' => 'Action serveur', 'href' => $prefix . '/action_serv.php'],
                    ['text' => 'Modules', 'href' => $prefix . '/conf_modules.php'],
                ],
            ],
            [
                'title' => 'Annuaire',
                'links' => [
                    ['text' => 'Acces a l\'annuaire', 'href' => $prefix . '/annu2/annu.php'],
                    ['text' => 'Modifier mon compte', 'href' => $prefix . '/annu2/mod_entry.php'],
                    ['text' => 'Mon mot de passe', 'href' => $prefix . '/annu2/mod_pwd.php'],
                    ['text' => 'Nettoyage des comptes', 'href' => $prefix . '/annu/ldap_cleaner.php'],
                ],
            ],
            [
                'title' => 'Informations systeme',
                'links' => [
                    ['text' => 'Diagnostic', 'href' => $prefix . '/test.php'],
                    ['text' => 'Test des connexions AD', 'href' => $prefix . '/infos/test_ldap.php'],
                    ['text' => 'Informations generales', 'href' => $prefix . '/infos/infose.php'],
                    ['text' => 'Connexions actives', 'href' => $prefix . '/parcs/smbstatus.php'],
                    ['text' => 'Historique', 'href' => $prefix . '/parcs/show_histo.php'],
                    ['text' => 'Espace disque', 'href' => $prefix . '/infos/df.php'],
                    ['text' => 'Occupation disque', 'href' => $prefix . '/infos/du.php'],
                    ['text' => 'Fixer des quotas', 'href' => $prefix . '/infos/quota_fixer.php'],
                    ['text' => 'Quotas effectifs', 'href' => $prefix . '/infos/quota_visu.php'],
                    ['text' => 'Actions de maintenance', 'href' => $prefix . '/infos/fix_se4.php'],
                    ['text' => 'Mot de passe', 'href' => $prefix . '/infos/infomdp.php'],
                ],
            ],
            [
                'title' => 'Gestion des partages',
                'links' => [
                    ['text' => 'Repertoires partages', 'href' => $prefix . '/partages/rep_classes.php'],
                    ['text' => 'Droits sur fichiers', 'href' => $prefix . '/acls/acls.php'],
                    ['text' => 'Dossier echange', 'href' => $prefix . '/dossier_echange/dossier_echange.php'],
                ],
            ],
            [
                'title' => 'Gestion des imprimantes',
                'links' => [
                    ['text' => 'Liste', 'href' => $prefix . '/printers/list_printers.php'],
                    ['text' => 'Details', 'href' => $prefix . '/printers/view_printers.php'],
                    ['text' => 'Ajouter ou configurer', 'href' => $prefix . '/printers/config_printer.php'],
                    ['text' => 'Supprimer', 'href' => $prefix . '/printers/delete_printer_choice.php'],
                    ['text' => 'Pilotes Windows', 'href' => $prefix . '/printers/add_driver.php'],
                ],
            ],
            [
                'title' => 'Gestion des parcs',
                'links' => [
                    ['text' => 'Liste des parcs', 'href' => $prefix . '/parcs/show_parc.php'],
                    ['text' => 'Action sur les parcs', 'href' => $prefix . '/parcs2/action_parc.php'],
                    ['text' => 'Installations', 'href' => $prefix . '/parcs2/lance_action.php'],
                    ['text' => 'Programmer l\'allumage', 'href' => $prefix . '/parcs/wolstop_station.php?action=timing'],
                    ['text' => 'Recherche machines', 'href' => $prefix . '/parcs2/cherche_machine.php'],
                    ['text' => 'Ajout et creation', 'href' => $prefix . '/parcs/create_parc.php'],
                    ['text' => 'Import CSV', 'href' => $prefix . '/parcs/import_csv.php'],
                    ['text' => 'Export CSV', 'href' => $prefix . '/parcs/export_csv.php'],
                    ['text' => 'Delegation', 'href' => $prefix . '/parcs/delegate_parc.php'],
                ],
            ],
            [
                'title' => 'Clients et applications',
                'links' => [
                    ['text' => 'GPOs et profils itinerants', 'href' => $prefix . '/gpo/gestion_gpo.php'],
                    ['text' => 'Configuration des applications', 'href' => $prefix . '/gpo/gestion_apps.php'],
                    ['text' => 'Fonds d\'ecrans', 'href' => $prefix . '/gpo/wallpaper.php'],
                    ['text' => 'Raccourcis', 'href' => $prefix . '/gpo/shortcuts.php'],
                    ['text' => 'Applications Wine', 'href' => $prefix . '/gpo/wine.php'],
                    ['text' => 'Sources Windows', 'href' => $prefix . '/ipxe/Win10/win_iso.php'],
                ],
            ],
            [
                'title' => 'Affichage dynamique',
                'links' => [
                    ['text' => 'Configurer les flux', 'href' => $prefix . '/display/config.php'],
                    ['text' => 'Configurer les ecrans', 'href' => $prefix . '/display/screen.php'],
                    ['text' => 'Voir l\'affichage', 'href' => $prefix . '/display/'],
                ],
            ],
            [
                'title' => 'Serveur DHCP',
                'links' => [
                    ['text' => 'Configuration', 'href' => $prefix . '/dhcp/config.php'],
                    ['text' => 'Gestion des baux', 'href' => $prefix . '/dhcp/baux.php'],
                ],
            ],
            [
                'title' => 'Applications Windows (WPKG)',
                'links' => [
                    ['text' => 'Mise a jour des applications', 'href' => $prefix . '/wpkg/depot_accueil.php'],
                    ['text' => 'Gestion des applications', 'href' => $prefix . '/wpkg/app_liste.php'],
                    ['text' => 'Gestion des parcs', 'href' => $prefix . '/wpkg/parc_statuts.php'],
                    ['text' => 'Gestion des postes', 'href' => $prefix . '/wpkg/poste_statuts.php'],
                    ['text' => 'Maintenance', 'href' => $prefix . '/wpkg/maintenance_accueil.php'],
                ],
            ],
        ];
    }
}; ?>

<div>
    <x-organisms.page
        title="Navigation Legacy"
        description="Liens vers les pages de l'interface legacy SambaEdu"
    >
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($menu as $section)
                <div class="card bg-base-100 shadow-sm border border-base-300">
                    <div class="card-body p-4">
                        <h2 class="card-title text-sm font-semibold">{{ $section['title'] }}</h2>
                        <ul class="menu menu-sm p-0">
                            @foreach($section['links'] as $link)
                                <li>
                                    <a href="{{ $link['href'] }}" class="text-xs">
                                        {{ $link['text'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>
    </x-organisms.page>
</div>
