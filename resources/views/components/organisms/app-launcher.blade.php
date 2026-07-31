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
 * **Story 56.5 (FR35)** : une tuile dont le backend a été observé INJOIGNABLE
 * (état persisté par `ext:health:check`, LU dans la même requête — jamais
 * mesuré ici) porte un badge « Indisponible ». Elle **reste cliquable** : l'état
 * peut dater de 5 minutes, et bloquer transformerait un affichage en
 * autorisation (FR14). Le non-admin ne voit aucun détail technique : ni
 * catégorie d'incident, ni port, ni date.
 *
 * **FR14** : ce composant décide UNIQUEMENT de la visibilité d'une tuile —
 * aucune route, aucun middleware, aucune garde n'est ajouté devant
 * `entry_url`. Masquer une tuile n'est PAS une protection.
 */
new class extends Component {
    /** @var list<array{key: string, name: string, icon: string, entry_url: string, unavailable: bool}> */
    public array $tiles = [];

    public function mount(ExtensionLauncherService $launcher): void
    {
        $user = auth()->user();

        // Garde `instanceof` (patron `search-modal.blade.php`) : le guard
        // `sambaedu.auth` hydrate normalement un `App\Models\User` Eloquent
        // complet (`Auth::login($eloquentUser)` — `SambaEduAuthGuard.php:111-114`),
        // mais `auth()->user()` peut en théorie renvoyer un autre type
        // (ou `null`) — état vide propre plutôt qu'une erreur.
        if (!$user instanceof User) {
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
<div class="dropdown dropdown-start">
    <div tabindex="0" role="button" class="btn btn-ghost btn-circle" title="Applications"
        aria-label="Ouvrir le lanceur d'applications">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" class="fill-primary">
            <circle cx="4" cy="4" r="2.6" />
            <circle cx="12" cy="4" r="2.6" />
            <circle cx="20" cy="4" r="2.6" />
            <circle cx="4" cy="12" r="2.6" />
            <circle cx="12" cy="12" r="2.6" />
            <circle cx="20" cy="12" r="2.6" />
            <circle cx="4" cy="20" r="2.6" />
            <circle cx="12" cy="20" r="2.6" />
            <circle cx="20" cy="20" r="2.6" />
        </svg>
    </div>
    <div tabindex="0"
        class="dropdown-content z-[1] w-80 p-3 shadow-lg border border-base-300 bg-base-100 rounded-box">
        <div class="pb-2 mb-2 border-b border-base-300">
            <h3 class="font-semibold">Applications</h3>
        </div>

        <div class="grid grid-cols-3 gap-2" data-testid="launcher-tiles">
            @foreach ($tiles as $tile)
                {{--
                    Story 56.5 — l'état conditionnel est porté par des CLASSES et
                    un bloc INTERNE, jamais par la structure : la tuile reste un
                    `<a href>` dans les deux états (FR14 — un badge n'est pas une
                    garde), et le `@if` du badge est imbriqué, loin du premier
                    niveau du SFC (piège maison de la racine stable).
                --}}
                @php $unavailable = (bool) ($tile['unavailable'] ?? false); @endphp
                <a href="{{ $tile['entry_url'] }}" target="_blank" rel="noopener"
                    data-testid="launcher-tile-{{ $tile['key'] }}"
                    title="{{ $unavailable ? $tile['name'].' — indisponible actuellement' : $tile['name'] }}"
                    class="relative flex flex-col items-center gap-1 p-2 rounded-lg text-center hover:bg-base-200 transition-colors {{ $unavailable ? 'opacity-60' : '' }}">
                    <i
                        class="{{ $tile['icon'] !== '' ? $tile['icon'] : 'fa-solid fa-puzzle-piece' }} text-2xl {{ $unavailable ? 'text-base-content/40' : 'text-primary' }}"></i>
                    <span class="text-xs w-full truncate">{{ $tile['name'] }}</span>
                    @if ($unavailable)
                        <span class="absolute top-0.5 right-0.5 leading-none text-warning"
                            data-testid="launcher-tile-unavailable-{{ $tile['key'] }}"
                            title="Indisponible actuellement" aria-label="Indisponible actuellement">
                            <i class="fa-solid fa-circle-exclamation text-xs"></i>
                        </span>
                    @endif
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
