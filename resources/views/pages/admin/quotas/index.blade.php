<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /admin/quotas — Réglages quotas & filesystem.
 *
 * Wrapper de page autour du composant Livewire historique
 * `pages::admin.settings._partials.quotas-fs-tab`. Le partial est conservé tel
 * quel (couvert par AdminSettingsQuotasFsTabTest) ; seule l'exposition route
 * change (ex-onglet de /admin/settings).
 *
 * Sécurité : middleware can:server.admin sur la route + double guard mount().
 */
new #[Title('Quotas & FS')] class extends Component {
    public function mount(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }
    }
};
?>

<x-organisms.page title="Quotas & FS"
    icon="fa-solid fa-hard-drive"
    description="Quotas par profil, période de grâce et corbeille XFS"
    back="{{ route('admin.settings') }}">

    <div class="flex-1 min-h-0 flex flex-col">
        <livewire:pages::admin.settings._partials.quotas-fs-tab />
    </div>
</x-organisms.page>
