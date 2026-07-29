<?php

use App\Models\User;
use App\Services\Extensions\ExtensionLauncherService;
use Livewire\Component;

/**
 * Story 54.3 (FR13/FR14/FR15-link/FR16, NFR9, UX-DR2) — Lanceur « gaufre »
 * de la navbar.
 *
 * SFC Livewire d'organisme navbar (précédents exacts dans ce même dossier :
 * `search-modal.blade.php`, `install-log-modal.blade.php`), testable
 * `Livewire::test('components::organisms.app-launcher')`.
 *
 * **NFR9** : les tuiles sont chargées UNE FOIS au `mount()` via
 * {@see ExtensionLauncherService::tilesFor()} (1 requête SQL, zéro requête
 * HTTP par construction — les tuiles sont des `<a>` statiques). Pas d'action
 * Livewire, pas de `WithToasts` : ce composant n'a rien à notifier, il
 * affiche.
 *
 * **FR14** : ce composant décide UNIQUEMENT de la visibilité d'une tuile —
 * aucune route, aucun middleware, aucune garde n'est ajouté devant
 * `entry_url`. Masquer une tuile n'est PAS une protection.
 */
new class extends Component {
    /** @var list<array{key: string, name: string, icon: string, entry_url: string}> */
    public array $tiles = [];

    public function mount(ExtensionLauncherService $launcher): void
    {
        $user = auth()->user();

        // Garde `instanceof` (patron `search-modal.blade.php`) : le guard
        // `sambaedu.auth` hydrate normalement un `App\Models\User` Eloquent
        // complet (`Auth::login($eloquentUser)` — `SambaEduAuthGuard.php:111-114`),
        // mais `auth()->user()` peut en théorie renvoyer un autre type
        // (ou `null`) — état vide propre plutôt qu'une erreur.
        if (! $user instanceof User) {
            $this->tiles = [];

            return;
        }

        // ⚠️ DÉGRADATION GRACIEUSE OBLIGATOIRE (NFR6) — patron littéral de
        // `pages/admin/extensions/index.blade.php` (Story 54.2) : « une
        // bibliothèque illisible ne doit pas rendre une 500 ».
        //
        // Ce composant est rendu par `layouts::app` ET `layouts::legacy-embed`,
        // donc sur TOUTE page authentifiée du produit. Sans cette garde, une
        // table `extensions` absente ou illisible ferait tomber l'intégralité
        // de SE5 en 500 — y compris des pages sans aucun rapport avec les
        // extensions. Ce n'est pas théorique : `scripts/update.sh` sert le code
        // neuf pendant tout composer+npm+build VitePress AVANT de lancer
        // `migrate --force`. La release qui livre l'Epic 54 traverse donc
        // nécessairement une fenêtre de plusieurs minutes où la table n'existe
        // pas encore.
        //
        // On journalise (jamais silencieux) et on rend l'état vide : la gaufre
        // reste, le reste de SE5 vit.
        try {
            $this->tiles = $launcher->tilesFor($user);
        } catch (\Throwable $e) {
            report($e);
            $this->tiles = [];
        }
    }
};
?>

{{--
    Racine UNIQUE et STABLE : ce `<div class="dropdown dropdown-end">` est
    TOUJOURS rendu, quel que soit l'état (tuiles présentes ou état vide).
    Piège connu (fiche mémoire) : un `@if` au premier niveau d'un SFC enfant
    provoque un 500 au re-render du parent. Ce composant est un parent rendu
    sur TOUTES les pages — c'est le pire endroit possible pour ce piège.
--}}
<div class="dropdown dropdown-end">
    <div tabindex="0" role="button" class="btn btn-ghost btn-circle" title="Applications"
        aria-label="Ouvrir le lanceur d'applications">
        <i class="fa-solid fa-table-cells text-xl"></i>
    </div>
    <div tabindex="0"
        class="dropdown-content z-[1] w-80 p-3 shadow-lg border border-base-300 bg-base-100 rounded-box">
        <div class="pb-2 mb-2 border-b border-base-300">
            <h3 class="font-semibold">Applications</h3>
        </div>

        <div class="grid grid-cols-3 gap-2" data-testid="launcher-tiles">
            @foreach ($tiles as $tile)
                <a href="{{ $tile['entry_url'] }}" target="_blank" rel="noopener"
                    data-testid="launcher-tile-{{ $tile['key'] }}"
                    class="flex flex-col items-center gap-1 p-2 rounded-lg text-center hover:bg-base-200 transition-colors">
                    <i class="{{ $tile['icon'] !== '' ? $tile['icon'] : 'fa-solid fa-puzzle-piece' }} text-2xl text-primary"></i>
                    <span class="text-xs w-full truncate">{{ $tile['name'] }}</span>
                </a>
            @endforeach
        </div>

        <div class="{{ count($tiles) > 0 ? 'hidden' : '' }} py-6 text-center text-base-content/60"
            data-testid="launcher-empty">
            <i class="fa-solid fa-table-cells text-2xl opacity-40 mb-2"></i>
            <p class="text-sm">Aucune application disponible.</p>
        </div>
    </div>
</div>
