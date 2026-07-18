<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou architectural Story 16.14 — Routes + ordre + permissions (AC7.3).
 *
 * Vérifie :
 *   1. La route `by-ou` est déclarée dans routes/web.php et gardée par `can:server.admin`.
 *   2. La route `by-ou` est déclarée AVANT la route `{guid}` (anti-régression piège 1 de 16.9).
 *   3. Le groupe `settings/system-status` (« État du système ») existe.
 *
 * NB : la route `sections` (« Sections natives ») a été retirée (commit 6e98c41) ;
 * la route dédiée `jobs` a été consolidée dans `settings/system-status`. Les
 * assertions correspondantes ont été retirées/recalées.
 */
class Story1614RoutesTest extends TestCase
{
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
    public function route_by_ou_is_declared(): void
    {
        self::assertStringContainsString(
            "'/by-ou'",
            $this->webPhpContent,
            "La route /by-ou doit être déclarée dans routes/web.php (AC3.1)."
        );

        self::assertStringContainsString(
            "name('by-ou')",
            $this->webPhpContent,
            "La route by-ou doit avoir le nom 'by-ou' dans le groupe gpo. (AC3.1)."
        );
    }

    // NOTE : la route `/sections` (« Sections natives » 16.14/AC4) a été
    // SUPPRIMÉE volontairement — commit 6e98c41 « chore(gpo): suppression de la
    // page catalogue Sections natives ». Les assertions correspondantes ont
    // donc été retirées de ce garde-fou (le garde couvre désormais /by-ou +
    // /jobs, toujours en vigueur).

    // NOTE : la route dédiée `/jobs` (« Dashboard jobs » 16.14/AC5) a été
    // CONSOLIDÉE dans `/admin/settings/system-status` (page « État du système »,
    // onglet dédié) — le groupe `settings/system` est devenu `settings/system-status`.
    // Les assertions `'/jobs'` + `name('jobs.index')` ont donc été retirées de ce
    // garde ; la présence du groupe est couverte par
    // `system_group_exists_for_system_status_route` ci-dessous.

    #[Test]
    public function all_new_routes_have_server_admin_middleware(): void
    {
        // La route by-ou doit être entourée d'un ->middleware('can:server.admin').
        $byOuSection = $this->extractRouteBlock($this->webPhpContent, "'/by-ou'");
        self::assertStringContainsString(
            "can:server.admin",
            $byOuSection,
            "La route by-ou doit être protégée par can:server.admin."
        );
    }

    #[Test]
    public function static_routes_declared_before_guid_parameterized_route(): void
    {
        // Vérifier l'ordre : by-ou AVANT /{guid} dans le groupe settings/gpo
        // (la route sections a été supprimée — cf. commit 6e98c41).
        $byOuPos = strpos($this->webPhpContent, "'/by-ou'");
        $guidPos = strpos($this->webPhpContent, "'/{guid}'");

        self::assertNotFalse($byOuPos, "Route by-ou introuvable.");
        self::assertNotFalse($guidPos, "Route /{guid} introuvable.");

        self::assertLessThan(
            $guidPos,
            $byOuPos,
            "La route by-ou DOIT être déclarée AVANT la route paramétrée /{guid} (anti-régression piège 1 de 16.9)."
        );
    }

    #[Test]
    public function system_group_exists_for_system_status_route(): void
    {
        self::assertStringContainsString(
            "settings/system-status",
            $this->webPhpContent,
            "Un groupe Route::prefix('settings/system-status') doit exister (« État du système », ex-dashboard jobs consolidé — AC5.1)."
        );
    }

    /**
     * Extrait un bloc de ~200 caractères autour d'une chaîne de référence.
     */
    private function extractRouteBlock(string $content, string $needle): string
    {
        $pos = strpos($content, $needle);
        if ($pos === false) {
            return '';
        }
        $start = max(0, $pos - 100);
        $length = 400;
        return substr($content, $start, $length);
    }
}
