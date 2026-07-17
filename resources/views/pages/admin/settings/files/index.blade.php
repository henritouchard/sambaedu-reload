<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /admin/settings/files — « Gestion des fichiers » (page HÔTE à onglets).
 *
 * Regroupe en deux onglets (décision Henri 2026-07-17) :
 *   - « Personnels et partagés » : la politique de gestion des fichiers (mode
 *     partages/Nextcloud + config NC) qui gouverne l'accès au home et aux classes
 *     et le montage des lecteurs ({@see \App\Services\FilePolicyService}).
 *   - « Lecteurs réseaux » : la gestion des partages réseau gérés (liste, création,
 *     assignation) — composant embarqué `pages::admin.shares.index`. Le détail
 *     d'un partage reste une sous-page (`/admin/shares/{id}`).
 *
 * Sécurité : `can:server.admin` sur la route + garde mount(). L'onglet lecteurs
 * exige EN PLUS `view-networkshare` (garde d'affichage + mount du composant embarqué).
 */
new #[Title('Gestion des fichiers')] class extends Component {
    #[Url(keep: true)]
    public string $tab = 'personnels-partages';

    private const TABS = ['personnels-partages', 'lecteurs-reseaux'];

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'personnels-partages';
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
    description="Politique d'accès aux fichiers des utilisateurs et lecteurs réseau gérés."
    back="{{ route('admin.settings') }}">

    <div class="flex flex-col gap-6 pt-4">

        @php
            $filesTabs = [
                'personnels-partages' => ['label' => 'Personnels et partagés', 'icon' => 'fa-solid fa-sliders'],
                'lecteurs-reseaux' => ['label' => 'Lecteurs réseaux', 'icon' => 'fa-solid fa-network-wired'],
            ];
        @endphp
        <x-molecules.tabs :tabs="$filesTabs" :active="$tab" class="bg-base-200 w-fit" />

        <div class="flex flex-col">
            @if ($tab === 'personnels-partages')
                <livewire:pages::admin.settings.files._partials.personnels-partages-tab />
            @elseif ($tab === 'lecteurs-reseaux')
                @can('view-networkshare')
                    <livewire:pages::admin.shares.index />
                @else
                    <div class="alert alert-warning">
                        <i class="fa-solid fa-lock"></i>
                        <span>Vous n'avez pas la permission de gérer les partages réseau.</span>
                    </div>
                @endcan
            @endif
        </div>

    </div>
</x-organisms.page>
