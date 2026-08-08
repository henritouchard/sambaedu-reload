<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La sidebar ne doit JAMAIS mettre l'application à terre à cause d'une route
 * absente.
 *
 * Contexte (incident du 2026-08-07). Un `bootstrap/cache/routes-v7.php` figé au
 * 11 juillet — donc antérieur aux routes d'extensions (Epic 54, fin juillet) —
 * rendait `admin.extensions` introuvable. La sidebar l'appelait sans garde ; or
 * elle est rendue sur CHAQUE page. Résultat : 500 sur l'application entière, y
 * compris le tableau de bord, et plus aucun écran pour diagnostiquer. Le code
 * était bon, seul le cache mentait.
 *
 * Ces tests verrouillent la règle : un lien dont la route manque DISPARAÎT du
 * menu, il ne lève pas. Ils échouent si quelqu'un retire un `Route::has()` au
 * motif que « la route existe forcément » — ce qui était vrai aussi le 11
 * juillet.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite). Pas de base : on rend la vue directement,
 * sans requête HTTP ni utilisateur authentifié.
 */
class SidebarRouteGuardTest extends TestCase
{
    /**
     * Rend la sidebar en accordant `server.admin`, afin que la section
     * « Serveur » (qui porte les liens les plus récents, donc les plus exposés)
     * soit effectivement évaluée.
     *
     * PIÈGE : la closure DOIT déclarer un premier paramètre acceptant `null`.
     * `Gate::callBeforeCallbacks()` saute tout callback qui n'accepte pas
     * l'invité quand aucun utilisateur n'est authentifié — et ces tests rendent
     * la vue sans session. Avec `fn () => true`, le `@can('server.admin')`
     * restait faux, la section « Serveur » n'était jamais rendue, et les
     * assertions `assertStringNotContainsString()` passaient pour la mauvaise
     * raison.
     */
    private function renderSidebar(): string
    {
        Gate::before(static fn (?Authenticatable $user) => true);

        return Blade::render('<x-organisms.sidebar />');
    }

    /**
     * Retire une route nommée de la table, en reproduisant exactement l'effet
     * d'un cache de routes périmé : la route n'existe plus pour `route()` ni
     * pour `Route::has()`.
     */
    private function forgetNamedRoute(string $name): void
    {
        $kept = new RouteCollection();

        foreach (Route::getRoutes() as $route) {
            if ($route->getName() !== $name) {
                $kept->add($route);
            }
        }

        Route::setRoutes($kept);
        $this->assertFalse(Route::has($name), "La route {$name} aurait dû être retirée.");
    }

    #[Test]
    public function elle_rend_le_lien_extensions_quand_la_route_existe(): void
    {
        $this->assertTrue(Route::has('admin.extensions'));

        $html = $this->renderSidebar();

        // Garde-fou inverse : le `Route::has()` ne doit pas masquer un lien
        // légitime. Sans cette assertion, un garde toujours-faux passerait
        // inaperçu et le menu se viderait en silence.
        $this->assertStringContainsString('Extensions', $html);
        $this->assertStringContainsString(route('admin.extensions'), $html);
    }

    #[Test]
    public function elle_rend_sans_lever_quand_la_route_extensions_manque(): void
    {
        $this->forgetNamedRoute('admin.extensions');

        $html = $this->renderSidebar();

        // Le reste du menu doit survivre : c'est toute la différence entre un
        // lien manquant et une application inaccessible.
        $this->assertStringContainsString('Tableau de bord', $html);
        $this->assertStringNotContainsString('Extensions', $html);
    }

    /**
     * Chaque lien de la sidebar, un par un. Le fait que la règle vaille pour
     * TOUS les liens est ce qui la rend auto-entretenue : personne n'a à juger
     * lequel serait « à risque ».
     *
     * @return list<array{0: string, 1: string}> [nom de route, libellé affiché]
     */
    public static function liensDeLaSidebar(): array
    {
        return [
            'tableau de bord' => ['app.dashboard', 'Tableau de bord'],
            'utilisateurs' => ['app.users', 'Utilisateurs'],
            'gestion des droits' => ['app.rights-management', 'Gestion des droits'],
            'gestion du parc' => ['app.parc.index', 'Gestion du parc'],
            'applications' => ['app.parc-settings.index', 'Applications'],
            'réglages' => ['admin.settings', 'Réglages'],
            'extensions' => ['admin.extensions', 'Extensions'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('liensDeLaSidebar')]
    public function aucun_lien_manquant_ne_fait_lever_la_sidebar(string $routeName, string $label): void
    {
        $this->assertTrue(Route::has($routeName), "La route {$routeName} devrait exister avant retrait.");

        $this->forgetNamedRoute($routeName);

        $html = $this->renderSidebar();

        $this->assertStringNotContainsString($label, $html);
        // La sidebar reste une sidebar : l'absence d'un lien ne doit pas
        // produire une coquille vide.
        $this->assertStringContainsString('drawer-side', $html);
    }

    #[Test]
    public function le_titre_serveur_disparait_si_ses_deux_liens_manquent(): void
    {
        // Un titre de section orphelin est un défaut visuel discret, mais c'est
        // exactement le genre de résidu qu'un garde posé lien par lien laisse
        // derrière lui si on oublie les intitulés.
        $this->forgetNamedRoute('admin.settings');
        $this->forgetNamedRoute('admin.extensions');

        $html = $this->renderSidebar();

        $this->assertStringNotContainsString('Serveur', $html);
        $this->assertStringContainsString('Tableau de bord', $html);
    }
}
