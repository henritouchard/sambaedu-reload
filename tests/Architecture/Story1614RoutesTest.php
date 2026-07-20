<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou architectural Story 16.14 — Routes + ordre + permissions (AC7.3).
 *
 * Vérifie :
 *   1. Les routes statiques du groupe `settings/gpo` sont déclarées AVANT la
 *      route paramétrée `{guid}` (anti-régression piège 1 de 16.9) et gardées
 *      par `can:server.admin`.
 *   2. Le groupe `settings/system-status` (« État du système ») existe.
 *
 * Historique des retraits :
 *   - `sections` (« Sections natives ») — commit 6e98c41.
 *   - `jobs` — consolidée dans `settings/system-status`.
 *   - `by-ou` (« Vue par OU », AC3.1) — supprimée : l'onglet « GPO » de
 *     /admin/settings/migration évalue en permanence les DEUX périmètres
 *     (postes et comptes), là où `by-ou` exigeait de choisir une OU et
 *     calculait faux sur les liens ENFORCED. Les assertions correspondantes
 *     ont donc été retirées ; l'invariant d'ORDRE reste couvert ci-dessous par
 *     les routes statiques survivantes.
 */
class Story1614RoutesTest extends TestCase
{
    /** Routes statiques du groupe `settings/gpo` devant précéder `/{guid}`. */
    private const STATIC_GPO_ROUTES = ["'/wine'", "'/wpkg-deployment'"];

    private string $webPhpContent = '';

    protected function setUp(): void
    {
        parent::setUp();
        $path = realpath(__DIR__ . '/../../routes/web.php');
        if ($path === false) {
            self::fail('routes/web.php introuvable.');
        }
        $this->webPhpContent = (string) file_get_contents($path);
    }

    #[Test]
    public function all_static_gpo_routes_have_server_admin_middleware(): void
    {
        foreach (self::STATIC_GPO_ROUTES as $needle) {
            $block = $this->extractRouteBlock($this->webPhpContent, $needle);

            self::assertStringContainsString(
                'can:server.admin',
                $block,
                sprintf('La route %s doit être protégée par can:server.admin.', $needle),
            );
        }
    }

    #[Test]
    public function static_routes_declared_before_guid_parameterized_route(): void
    {
        $guidPos = strpos($this->webPhpContent, "'/{guid}'");
        self::assertNotFalse($guidPos, 'Route /{guid} introuvable.');

        foreach (self::STATIC_GPO_ROUTES as $needle) {
            $pos = strpos($this->webPhpContent, $needle);
            self::assertNotFalse($pos, sprintf('Route %s introuvable.', $needle));

            self::assertLessThan(
                $guidPos,
                $pos,
                sprintf(
                    'La route %s DOIT être déclarée AVANT la route paramétrée /{guid} '
                    . '(anti-régression piège 1 de 16.9 : la regex GUID capterait le segment statique).',
                    $needle,
                ),
            );
        }
    }

    #[Test]
    public function the_removed_by_ou_route_is_not_reintroduced(): void
    {
        self::assertStringNotContainsString(
            "'/by-ou'",
            $this->webPhpContent,
            'La route /by-ou a été supprimée : l\'inventaire GPO vit dans l\'onglet '
            . '« GPO » de /admin/settings/migration, qui évalue les deux périmètres.',
        );
    }

    #[Test]
    public function system_group_exists_for_system_status_route(): void
    {
        self::assertStringContainsString(
            'settings/system-status',
            $this->webPhpContent,
            "Un groupe Route::prefix('settings/system-status') doit exister (« État du système », ex-dashboard jobs consolidé — AC5.1).",
        );
    }

    /**
     * Extrait un bloc de ~400 caractères autour d'une chaîne de référence.
     */
    private function extractRouteBlock(string $content, string $needle): string
    {
        $pos = strpos($content, $needle);
        if ($pos === false) {
            return '';
        }
        $start = max(0, $pos - 100);

        return substr($content, $start, 400);
    }
}
