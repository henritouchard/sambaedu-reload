<?php

use App\Enums\SambaPermission;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /app/guide — Hub du Guide des fonctionnalités (Story 40.1, AC1 & AC3).
 *
 * Landing listant les DOMAINES fonctionnels (= catégories de SambaPermission).
 * Ouvert à TOUT utilisateur authentifié : AUCUN guard bloquant dans mount()
 * (le Guide n'est jamais fermé — c'est son contenu qui est gaté, page par
 * page, via le composant x-molecules.feature-guide-item).
 */
new #[Title('Guide des fonctionnalités')] class extends Component {
    /**
     * Domaines fonctionnels avec, pour l'utilisateur connecté, le compteur
     * « accessibles / total ». Le domaine pilote « Utilisateurs » est cliquable ;
     * les autres sont présents mais marqués « Bientôt disponible ».
     *
     * @return array<int, array{category: string, label: string, total: int, accessible: int, available: bool}>
     */
    #[Computed]
    public function domains(): array
    {
        $user = auth()->user();
        $domains = [];

        foreach (SambaPermission::groupedByCategory() as $category => $data) {
            $permissions = $data['permissions'];
            $accessible = 0;
            foreach ($permissions as $perm) {
                if ($user && $user->can($perm->value)) {
                    $accessible++;
                }
            }

            $domains[] = [
                'category'   => $category,
                'label'      => $data['label'],
                'total'      => count($permissions),
                'accessible' => $accessible,
                // Domaine pilote documenté en 40.1 = catégorie `user`. Les autres
                // suivront (40.2, 40.3…) : cartes « Bientôt disponible » sans lien
                // mort. Ancré sur l'enum (pas de littéral) pour rester couplé à
                // `SambaPermission::category()` si sa valeur évolue.
                'available'  => $category === SambaPermission::UserRead->category(),
            ];
        }

        return $domains;
    }

    /**
     * Icône FontAwesome par domaine (cosmétique — pas de duplication de libellé).
     *
     * `protected` (pas `public`) : c'est un helper de rendu, pas une action
     * Livewire — inutile de l'exposer comme méthode appelable depuis le front.
     * Reste accessible depuis la vue SFC (bindée à l'instance du composant).
     */
    protected function domainIcon(string $category): string
    {
        return match ($category) {
            'user'              => 'fa-solid fa-users',
            'share'             => 'fa-solid fa-folder-open',
            'network-share'     => 'fa-solid fa-hard-drive',
            'folder-rule'       => 'fa-solid fa-lock',
            'computer'          => 'fa-solid fa-display',
            'wpkg'              => 'fa-solid fa-box-archive',
            'server'            => 'fa-solid fa-server',
            'wallpaper'         => 'fa-solid fa-image',
            'app-customization' => 'fa-solid fa-sliders',
            default             => 'fa-solid fa-circle-question',
        };
    }
};
?>

<x-organisms.page title="Guide des fonctionnalités"
    icon="fa-solid fa-circle-question"
    description="Découvrez ce que permet SambaEdu, domaine par domaine, avec des guides pas-à-pas. Les fonctionnalités auxquelles vous n'avez pas droit restent visibles mais verrouillées.">

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 pt-4">
        @foreach ($this->domains as $domain)
            @php
                $isUser = $domain['available'];
                $icon = $this->domainIcon($domain['category']);
            @endphp

            @if ($isUser)
                {{-- Domaine pilote documenté → carte cliquable --}}
                <a href="{{ route('app.guide.utilisateurs') }}"
                    data-testid="domain-{{ $domain['category'] }}"
                    class="card bg-base-100 shadow-md hover:shadow-xl transition-all duration-200 hover:-translate-y-1 border border-base-300/50 hover:border-primary/40">
                    <div class="card-body">
                        <div class="flex items-center gap-4 mb-2">
                            <div class="w-12 h-12 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                <i class="{{ $icon }} text-primary text-xl"></i>
                            </div>
                            <h2 class="card-title text-lg leading-tight">{{ $domain['label'] }}</h2>
                        </div>
                        <p class="text-sm text-base-content/70">
                            Guides pas-à-pas des fonctionnalités du domaine.
                        </p>
                        <div class="card-actions justify-between items-center mt-4">
                            <span data-testid="domain-count-{{ $domain['category'] }}"
                                class="badge badge-primary badge-outline">
                                {{ $domain['accessible'] }} / {{ $domain['total'] }} accessibles
                            </span>
                            <span class="text-primary text-sm font-medium">
                                Consulter <i class="fa-solid fa-arrow-right ml-1"></i>
                            </span>
                        </div>
                    </div>
                </a>
            @else
                {{-- Domaine non encore documenté → carte grisée « Bientôt disponible » --}}
                <div data-testid="domain-{{ $domain['category'] }}"
                    class="card bg-base-200/40 border border-base-300 opacity-60 cursor-not-allowed">
                    <div class="card-body">
                        <div class="flex items-center gap-4 mb-2">
                            <div class="w-12 h-12 rounded-xl bg-base-300/60 flex items-center justify-center shrink-0">
                                <i class="{{ $icon }} text-base-content/50 text-xl"></i>
                            </div>
                            <h2 class="card-title text-lg leading-tight text-base-content/70">{{ $domain['label'] }}</h2>
                        </div>
                        <p class="text-sm text-base-content/60">
                            Guide en cours de rédaction.
                        </p>
                        <div class="card-actions justify-between items-center mt-4">
                            <span data-testid="domain-count-{{ $domain['category'] }}"
                                class="badge badge-ghost">
                                {{ $domain['accessible'] }} / {{ $domain['total'] }} accessibles
                            </span>
                            <span class="badge badge-neutral badge-outline gap-1">
                                <i class="fa-solid fa-clock"></i> Bientôt disponible
                            </span>
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

</x-organisms.page>
