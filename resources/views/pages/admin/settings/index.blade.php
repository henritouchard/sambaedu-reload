<?php

use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Story 5.1c — Page de réglages système /admin/settings.
 *
 * Scaffold à onglets extensible décalqué sur `pages/parc-settings/index.blade.php`
 * (pattern `#[Url(keep: true)] public string $tab` + `setTab()` + tablist).
 *
 * Onglet unique en 5.1c : "Quotas & FS" (D3=A — interdiction stricte de
 * placeholders "coming soon"). Les futurs onglets (DHCP, CUPS, Profils, ...)
 * seront ajoutés dans leurs Epics respectives.
 *
 * Sécurité : middleware `can:server.admin` sur la route + double guard serveur
 * `Gate::allows('server.admin')` ici (paranoïa AC 12).
 */
new #[Title('Réglages système')] class extends Component
{
    use WithToasts;

    #[Url(keep: true)]
    public string $tab = 'quotas-fs';

    public function mount(): void
    {
        // Double guard serveur — paranoïa : la route a déjà un can:server.admin.
        if (!Gate::allows('server.admin')) {
            abort(403);
        }
    }

    public function setTab(string $tab): void
    {
        // Double guard serveur sur chaque action publique (AC 12 — payload
        // Livewire forgé même par un user sans permission ne doit pas pouvoir
        // changer d'onglet ou ré-émettre un render).
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        // `#[Url(keep: true)]` synchronise la query string automatiquement —
        // pas besoin de full redirect (cf. review 5.1c #9).
        $this->tab = $tab;
    }
};
?>

<x-organisms.page title="Réglages" :scrollable="false"
    description="Configuration système globale — quotas, période de grâce, corbeille">

    <div class="h-full flex flex-col gap-4">
        {{-- Onglets : un seul onglet visible en 5.1c (D3=A — pas de placeholders). --}}
        <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit">
            <button type="button" role="tab" class="tab {{ $tab === 'quotas-fs' ? 'tab-active' : '' }}"
                wire:click="setTab('quotas-fs')">
                <i class="fa-solid fa-hard-drive mr-2"></i>
                Quotas & FS
            </button>
        </div>

        {{-- Contenu de l'onglet actif. --}}
        <div class="flex-1 min-h-0 flex flex-col">
            @if ($tab === 'quotas-fs')
                <livewire:pages::admin.settings._partials.quotas-fs-tab />
            @endif
        </div>
    </div>
</x-organisms.page>
