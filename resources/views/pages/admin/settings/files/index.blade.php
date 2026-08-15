<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /admin/settings/files — « Gestion des fichiers » (page HÔTE à onglets).
 *
 * Regroupe en trois onglets :
 *   - « Emplacements et cloud » (63.3) : OÙ VIVENT LES FICHIERS — le cloud actif
 *     de l'instance (choix exclusif, avec sa page de connexion), l'emplacement
 *     de l'espace personnel, celui de l'espace partagé, et les réglages qui en
 *     dépendent ({@see \App\Services\Filesystem\FileLocationService}).
 *   - « Lecteurs réseaux » : la gestion des partages réseau gérés (liste, création,
 *     assignation) — composant embarqué `pages::admin.shares.index`. Le détail
 *     d'un partage reste une sous-page (`/admin/shares/{id}`).
 *   - « Profils itinérants » (1bis.18f/26.3) : exclusions `ExcludeProfileDirs`,
 *     statistiques roaming et purge des orphelins.
 *
 * ⚠️ **Les onglets « Personnels et partagés » et « OpenCloud » ONT DISPARU**
 * (63.3). Ils portaient quatre interrupteurs indépendants qui disaient ce qui
 * était allumé sans jamais dire où vivent les fichiers, et leurs deux blocs de
 * connexion sont maintenant révélés par le choix de cloud du premier onglet.
 * Leurs clés retombent sur le défaut comme n'importe quelle clé inconnue.
 *
 * « Profils itinérants » était une page dédiée (`/admin/settings/profils-itinerants`)
 * retirée au profit d'une redirection vers `?tab=roaming` — sans que l'onglet cible
 * existe : l'UI était injoignable (l'URL retombait silencieusement sur le 1er onglet).
 * La redirection nommée reste stable, elle pointe enfin sur du réel.
 *
 * ⚠️ **Pas d'onglet « Quotas & FS »** (décision Henri 2026-08-05). Sa grille de
 * « quotas par défaut par profil » n'appliquait rien à personne : elle écrivait
 * `SystemSetting('quota.defaults')`, clé que la résolution ne lit pas — voir story
 * 5.1e. Conséquence temporaire assumée : la période de grâce et la corbeille
 * `/home/trash`, elles bien fonctionnelles, n'ont plus d'UI jusqu'à ce que 5.1e les
 * réinstalle en cartes dans le bloc « Réglages » de l'onglet des emplacements
 * (63.4). Leurs valeurs persistées
 * restent en vigueur (le cron 02h00 continue de tourner) et restent pilotables par
 * `php artisan trash:purge` et en tinker. `admin/settings/_partials/quotas-fs-tab`
 * n'a plus d'hôte : il attend d'être découpé par 5.1e.
 *
 * Sécurité : `can:server.admin` sur la route + garde mount(). L'onglet lecteurs
 * exige EN PLUS `view-networkshare` (garde d'affichage + mount du composant embarqué).
 * Le partial roaming porte son propre double guard `server.admin`.
 */
new #[Title('Gestion des fichiers')] class extends Component {
    #[Url(keep: true)]
    public string $tab = 'emplacements';

    private const TABS = ['emplacements', 'lecteurs-reseaux', 'roaming'];

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'emplacements';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }
};
?>

<x-organisms.page title="Gestion des fichiers"
    icon="fa-solid fa-folder-tree"
    description="Politique d'accès aux fichiers des utilisateurs, lecteurs réseau gérés, quotas et profils itinérants."
    back="{{ route('admin.settings') }}">

    {{-- Fil d'Ariane « Retour à la GPO » : porté par l'ex-page Profils itinérants
         (arrivée depuis l'éditeur GPO via ?from_gpo={guid}), il ne concerne que
         cet onglet. Le composant s'auto-masque en l'absence du paramètre. --}}
    @if ($tab === 'roaming')
        <x-slot:actions>
            <x-molecules.gpo-back-link />
        </x-slot:actions>
    @endif

    <div class="flex flex-col gap-6 pt-4">

        @php
            $filesTabs = [
                'emplacements' => ['label' => 'Emplacements et cloud', 'icon' => 'fa-solid fa-cloud'],
                'lecteurs-reseaux' => ['label' => 'Lecteurs réseaux', 'icon' => 'fa-solid fa-network-wired'],
                'roaming' => ['label' => 'Profils itinérants', 'icon' => 'fa-solid fa-users-gear'],
            ];
        @endphp
        <x-molecules.tabs :tabs="$filesTabs" :active="$tab" class="bg-base-200 w-fit" />

        <div class="flex flex-col">
            @if ($tab === 'emplacements')
                <livewire:pages::admin.settings.files._partials.emplacements-tab />
            @elseif ($tab === 'lecteurs-reseaux')
                @can('view-networkshare')
                    <livewire:pages::admin.shares.index />
                @else
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-lock"></i>
                        <span>Vous n'avez pas la permission de gérer les partages réseau.</span>
                    </div>
                @endcan
            @elseif ($tab === 'roaming')
                {{-- `flex-1 min-h-0` : le partial gère son propre conteneur de
                     scroll interne — sans ça il déborde. --}}
                <div class="flex-1 min-h-0 flex flex-col">
                    <livewire:pages::admin.settings._partials.profils-itinerants-tab />
                </div>
            @endif
        </div>

    </div>
</x-organisms.page>
