<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou architectural Story 16.14 — Routes + ordre + permissions (AC7.3).
 *
 * Vérifie :
 *   1. Les 3 routes nouvelles sont déclarées dans routes/web.php.
 *   2. Toutes 3 sont gardées par `can:server.admin`.
 *   3. Les routes `by-ou` et `sections` sont déclarées AVANT la route `{guid}` (anti-régression piège 1 de 16.9).
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

    #[Test]
    public function route_sections_is_declared(): void
    {
        self::assertStringContainsString(
            "'/sections'",
            $this->webPhpContent,
            "La route /sections doit être déclarée dans routes/web.php (AC4.1)."
        );

        self::assertStringContainsString(
            "name('sections')",
            $this->webPhpContent,
            "La route sections doit avoir le nom 'sections' dans le groupe gpo. (AC4.1)."
        );
    }

    #[Test]
    public function route_system_jobs_is_declared(): void
    {
        self::assertStringContainsString(
            "'/jobs'",
            $this->webPhpContent,
            "La route /jobs doit être déclarée dans routes/web.php (AC5.1)."
        );

        self::assertStringContainsString(
            "name('jobs.index')",
            $this->webPhpContent,
            "La route jobs doit avoir le nom 'jobs.index' dans le groupe system. (AC5.1)."
        );
    }

    #[Test]
    public function all_new_routes_have_server_admin_middleware(): void
    {
        // Chaque nouvelle route doit être entourée d'un ->middleware('can:server.admin')
        // On vérifie que les 3 blocs de code correspondants contiennent cette protection.

        // Approche : compter les occurrences de middleware + route voisine
        $byOuSection = $this->extractRouteBlock($this->webPhpContent, "'/by-ou'");
        self::assertStringContainsString(
            "can:server.admin",
            $byOuSection,
            "La route by-ou doit être protégée par can:server.admin."
        );

        $sectionsSection = $this->extractRouteBlock($this->webPhpContent, "'/sections'");
        self::assertStringContainsString(
            "can:server.admin",
            $sectionsSection,
            "La route sections doit être protégée par can:server.admin."
        );

        $jobsSection = $this->extractRouteBlock($this->webPhpContent, "'/jobs'");
        self::assertStringContainsString(
            "can:server.admin",
            $jobsSection,
            "La route jobs doit être protégée par can:server.admin."
        );
    }

    #[Test]
    public function static_routes_declared_before_guid_parameterized_route(): void
    {
        // Vérifier l'ordre : by-ou et sections AVANT /{guid} dans le groupe settings/gpo
        $byOuPos = strpos($this->webPhpContent, "'/by-ou'");
        $sectionsPos = strpos($this->webPhpContent, "'/sections'");
        $guidPos = strpos($this->webPhpContent, "'/{guid}'");

        self::assertNotFalse($byOuPos, "Route by-ou introuvable.");
        self::assertNotFalse($sectionsPos, "Route sections introuvable.");
        self::assertNotFalse($guidPos, "Route /{guid} introuvable.");

        self::assertLessThan(
            $guidPos,
            $byOuPos,
            "La route by-ou DOIT être déclarée AVANT la route paramétrée /{guid} (anti-régression piège 1 de 16.9)."
        );

        self::assertLessThan(
            $guidPos,
            $sectionsPos,
            "La route sections DOIT être déclarée AVANT la route paramétrée /{guid} (anti-régression piège 1 de 16.9)."
        );
    }

    #[Test]
    public function system_group_exists_for_jobs_route(): void
    {
        self::assertStringContainsString(
            "settings/system",
            $this->webPhpContent,
            "Un groupe Route::prefix('settings/system') doit exister pour le dashboard jobs (AC5.1)."
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
