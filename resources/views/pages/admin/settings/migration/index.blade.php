<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /admin/settings/migration — « Migration SE4 → SE5 » (page HÔTE à onglets).
 *
 * Regroupe les outils d'assistance à la migration / d'observabilité du canal
 * legacy, tous voués à disparaître une fois le parc entièrement bascule agent.
 * Chaque onglet EMBARQUE la feature (composant Livewire imbriqué, sans chrome
 * de page propre) — modèle « Gestion des fichiers ».
 *
 *   - « Sync from AD »    : assistant d'import AD → SQL (`pages::sync-from-ad.index`).
 *   - « Logs scripts »    : logs d'exécution des scripts du parc, canal en
 *     extinction (`pages::admin.settings.scripts-logs.index`). Le détail d'un
 *     log reste une sous-page (`/admin/settings/scripts-logs/{id}`).
 *   - « Legacy Monitor »  : appels catchall en temps réel — routes legacy encore
 *     actives (`pages::admin.legacy-monitor.index`).
 *   - « GPO »             : inventaire des GPO du domaine et de leur EFFECTIVITÉ
 *     réelle sur le périmètre de l'instance (`_partials/gpos-tab`). Remplace le
 *     listing `/admin/settings/gpo`, dont le badge « Active » valait
 *     `versionNumber > 0` (= « éditée un jour ») et affichait donc en vert des
 *     GPO totalement neutralisées. Cible d'extinction : une seule GPO effective,
 *     `SE_agent_bootstrap`.
 *
 * NB : l'Error Logger n'est PAS ici — il capte aussi les exceptions Laravel
 * (diagnostic runtime SE5), il vit donc dans l'onglet « Logs » de
 * /admin/settings/system-status.
 *
 * Les anciennes routes individuelles (`/admin/sync-from-ad`,
 * `/admin/legacy-monitor`, `/admin/settings/scripts-logs`) redirigent désormais
 * vers l'onglet correspondant de cette page.
 *
 * Sécurité : `can:server.admin` sur la route + garde mount().
 */
new #[Title('Migration SE4 → SE5')] class extends Component {
    #[Url(keep: true)]
    public string $tab = 'sync-from-ad';

    private const TABS = ['sync-from-ad', 'logs-scripts', 'legacy-monitor', 'gpos'];

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'sync-from-ad';
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

<x-organisms.page title="Migration SE4 → SE5"
    icon="fa-solid fa-exchange-alt"
    description="Outils d'assistance à la migration SE4 → SE5 et observabilité du canal legacy (voués à disparaître une fois le parc bascule agent)."
    back="{{ route('admin.settings') }}">

    <div class="flex flex-col gap-6 pt-4">

        @php
            $migrationTabs = [
                'sync-from-ad' => ['label' => 'Sync from AD', 'icon' => 'fa-solid fa-rotate'],
                'logs-scripts' => ['label' => 'Logs scripts', 'icon' => 'fa-solid fa-scroll'],
                'legacy-monitor' => ['label' => 'Legacy Monitor', 'icon' => 'fa-solid fa-eye'],
                'gpos' => ['label' => 'GPO', 'icon' => 'fa-solid fa-sitemap'],
            ];
        @endphp
        <x-molecules.tabs :tabs="$migrationTabs" :active="$tab" />

        <div class="flex flex-col">
            @if ($tab === 'sync-from-ad')
                <livewire:pages::sync-from-ad.index />
            @elseif ($tab === 'logs-scripts')
                <livewire:pages::admin.settings.scripts-logs.index />
            @elseif ($tab === 'legacy-monitor')
                <livewire:pages::admin.legacy-monitor.index />
            @elseif ($tab === 'gpos')
                <livewire:pages::admin.settings.migration._partials.gpos-tab />
            @endif
        </div>

    </div>
</x-organisms.page>
