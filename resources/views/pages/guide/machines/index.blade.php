<?php

use App\Enums\SambaPermission;
use App\Support\Help\FeatureGuideRegistry;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /app/guide/machines — Domaine « Machines » (Story 40.2, AC2 & AC5).
 *
 * Documente en format how-to les 5 permissions de la catégorie `computer`
 * (`computer.view`, `computer.control`, `computer.elevate`, `computer.install`,
 * `computer.remote.rdp`). Chaque fonctionnalité est TOUJOURS rendue (jamais
 * masquée) via x-molecules.feature-guide-item : déverrouillée si l'utilisateur
 * a la permission Spatie, verrouillée (grisée + cadenas) sinon.
 *
 * Gating = `can()` GLOBAL (comportement par défaut du composant : prop `unlocked`
 * NON fournie). Bien que les permissions `computer.*` soient délégables scopées
 * WorkstationGroup dans le reste de l'app, un guide répond à « as-tu ce droit
 * quelque part ? » — pas « sur quel parc ». Aucune logique
 * `PermissionService::canOnWorkstationGroup()` ici (choix produit 40.2).
 *
 * Aucun guard bloquant : le Guide est ouvert à tout utilisateur authentifié.
 */
new #[Title('Guide — Machines')] class extends Component {
    /**
     * Les fonctionnalités du domaine `computer`, alimentées par le registre
     * how-to. Les intitulés ne sont PAS ré-écrits : ils restent portés par
     * l'enum (le composant lit `SambaPermission::label()`).
     *
     * @return array<int, array{permission: SambaPermission, objective: string, steps: string[], link: ?string, linkLabel: ?string}>
     */
    #[Computed]
    public function features(): array
    {
        // Catégorie ancrée sur l'enum (pas de littéral `'computer'`) : reste
        // couplée à `SambaPermission::category()` si sa valeur évolue.
        $category = SambaPermission::ComputerView->category();
        $grouped = SambaPermission::groupedByCategory();
        $permissions = $grouped[$category]['permissions'] ?? [];

        return array_map(function (SambaPermission $perm): array {
            $howto = FeatureGuideRegistry::forPermission($perm);

            // `Route::has()` : un how-to pointant (par erreur future) vers une route
            // inexistante ne doit PAS provoquer un 500 pour tous les rôles ; on
            // dégrade proprement en absence de lien.
            $link = null;
            if (isset($howto['route']) && Route::has($howto['route'])) {
                $link = route($howto['route']);
            }

            return [
                'permission' => $perm,
                'objective'  => $howto['objective'] ?? '',
                'steps'      => $howto['steps'] ?? [],
                'link'       => $link,
                'linkLabel'  => $howto['routeLabel'] ?? null,
            ];
        }, $permissions);
    }
};
?>

<x-organisms.page title="Machines"
    icon="fa-solid fa-display"
    description="Guides pas-à-pas de la gestion du parc de postes. Les actions auxquelles vous n'avez pas droit sont affichées mais verrouillées."
    back="{{ route('app.guide') }}">

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 pt-4">
        @foreach ($this->features as $feature)
            <x-molecules.feature-guide-item
                :permission="$feature['permission']"
                :objective="$feature['objective']"
                :steps="$feature['steps']"
                :link="$feature['link']"
                :linkLabel="$feature['linkLabel']" />
        @endforeach
    </div>

</x-organisms.page>
