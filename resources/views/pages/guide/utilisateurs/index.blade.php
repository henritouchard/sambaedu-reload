<?php

use App\Enums\SambaPermission;
use App\Support\Help\FeatureGuideRegistry;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /app/guide/utilisateurs — Domaine pilote « Utilisateurs » (Story 40.1, AC5).
 *
 * Documente en format how-to les 6 permissions de la catégorie `user`. Chaque
 * fonctionnalité est TOUJOURS rendue (jamais masquée) via
 * x-molecules.feature-guide-item : déverrouillée si l'utilisateur a la
 * permission Spatie, verrouillée (grisée + cadenas) sinon.
 *
 * Aucun guard bloquant : le Guide est ouvert à tout utilisateur authentifié.
 */
new #[Title('Guide — Utilisateurs')] class extends Component {
    /**
     * Les fonctionnalités du domaine `user`, alimentées par le registre how-to.
     * Les intitulés ne sont PAS ré-écrits : ils restent portés par l'enum
     * (le composant lit `SambaPermission::label()`).
     *
     * @return array<int, array{permission: SambaPermission, objective: string, steps: string[], link: ?string, linkLabel: ?string}>
     */
    #[Computed]
    public function features(): array
    {
        // Catégorie ancrée sur l'enum (pas de littéral `'user'`) : reste couplée
        // à `SambaPermission::category()` si sa valeur évolue.
        $category = SambaPermission::UserRead->category();
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

<x-organisms.page title="Utilisateurs"
    icon="fa-solid fa-users"
    description="Guides pas-à-pas de la gestion des comptes. Les actions auxquelles vous n'avez pas droit sont affichées mais verrouillées."
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
