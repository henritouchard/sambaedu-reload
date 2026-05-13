<?php

use App\Components\Traits\WithToasts;
use App\Enums\AppKind;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — personnalisation applicative établissement.
 *
 * Story 4.8 — Task 4.7 (AC 7). Convention filesystem-based router :
 * `/app/parc-settings/app-customizations` → cette page.
 */
new #[Title('Personnalisation applications — SE4FS')] class extends Component {
    use WithToasts;

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('app.customize'),
            403,
            'Permission app.customize requise.',
        );
    }

    /**
     * @return AppKind[]
     */
    public function kinds(): array
    {
        return AppKind::cases();
    }
};
?>

<x-organisms.page
    title="Personnalisation applications"
    :scrollable="true"
    description="Paramétrez les politiques des applications (Firefox, Thunderbird, …) par défaut de l'établissement.">

    {{-- Breadcrumb de retour GPO (Story 16.3a, AC4.2) — affiché uniquement si ?from_gpo présent --}}
    <x-slot:actions>
        <x-molecules.gpo-back-link />
    </x-slot:actions>

    <div class="space-y-6">
        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">Hiérarchie de résolution</p>
                <p class="text-sm opacity-80">
                    Les policies définies ici sont appliquées à tous les postes si aucune
                    configuration plus spécifique n'existe (WorkstationGroup, groupe AD, utilisateur).
                    La priorité va toujours au plus spécifique.
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @foreach ($this->kinds() as $kind)
                <livewire:components::molecules.app-customization-card
                    :app-kind="$kind->value"
                    :title="$kind->label()"
                    description="Politique appliquée par défaut à tous les postes."
                    :key="'app-customization-etab-' . $kind->value" />
            @endforeach
        </div>
    </div>

    <livewire:components::organisms.app-customize-modal :key="'app-customize-modal'" />
</x-organisms.page>
