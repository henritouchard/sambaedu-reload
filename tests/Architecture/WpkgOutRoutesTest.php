<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 17.6 / AC6.4 / D8 — Garde-fous architecturaux des 2 routes
 * `/wpkg/linux_out.php` et `/wpkg/winget_out.php`.
 *
 * Tests (lecture textuelle de `routes/web.php`, pattern iso `IpxeNamespaceTest`) :
 *   1. Les 2 routes sont enregistrées et référencent leurs controllers.
 *   2. Elles sont déclarées AVANT le catchall legacy `{path}` (sinon le shim
 *      PHP-FPM legacy les intercepte silencieusement).
 *   3. Elles N'ONT PAS le middleware `auth.v1.workstation` (assertion négative,
 *      D2 — postes pas encore enrôlés JWT).
 *   4. Elles ONT le middleware `local.request` (+ throttle).
 */
class WpkgOutRoutesTest extends TestCase
{
    /** @var array{linux: string, winget: string} */
    private const NEEDLES = [
        'linux' => "['\"]/wpkg/linux_out\\.php['\"]",
        'winget' => "['\"]/wpkg/winget_out\\.php['\"]",
    ];

    private function routesContent(): string
    {
        $routesFile = realpath(__DIR__ . '/../../routes/web.php');
        self::assertNotFalse($routesFile, 'routes/web.php introuvable');

        return (string) file_get_contents($routesFile);
    }

    /**
     * AC6.4 #1 — Les 2 routes sont enregistrées et référencent leurs controllers.
     */
    #[Test]
    public function the_two_wpkg_out_routes_are_registered(): void
    {
        $content = $this->routesContent();

        foreach (self::NEEDLES as $name => $needle) {
            $pattern = '@Route::(?:match|get|post)\s*\([^;]*?' . $needle . '@';
            self::assertSame(
                1,
                preg_match($pattern, $content),
                "La route /wpkg/{$name}_out.php n'est pas déclarée dans routes/web.php",
            );
        }

        self::assertStringContainsString(
            'App\\Wpkg\\Deployment\\Http\\Controllers\\LinuxOutController',
            $content,
            'La route linux_out doit référencer LinuxOutController',
        );
        self::assertStringContainsString(
            'App\\Wpkg\\Deployment\\Http\\Controllers\\WingetOutController',
            $content,
            'La route winget_out doit référencer WingetOutController',
        );
    }

    /**
     * AC6.4 #2 — Les 2 routes sont déclarées AVANT le catchall legacy `{path}`.
     */
    #[Test]
    public function the_two_wpkg_out_routes_are_declared_before_catchall(): void
    {
        $content = $this->routesContent();

        $catchallPattern = "/Route::match\s*\([^)]*['\"]\\{path\\}['\"]/";
        self::assertSame(
            1,
            preg_match($catchallPattern, $content, $catchallMatches, PREG_OFFSET_CAPTURE),
            'Catchall legacy {path} introuvable',
        );
        $catchallOffset = $catchallMatches[0][1];

        foreach (self::NEEDLES as $name => $needle) {
            $pattern = '@Route::(?:match|get|post)\s*\([^;]*?' . $needle . '@';
            self::assertSame(
                1,
                preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE),
                "Route /wpkg/{$name}_out.php non déclarée",
            );
            self::assertLessThan(
                $catchallOffset,
                $matches[0][1],
                "ORDRE INVALIDE : la route /wpkg/{$name}_out.php doit être AVANT le catchall "
                . 'legacy {path}, sinon le shim PHP-FPM legacy l\'intercepte silencieusement.',
            );
        }
    }

    /**
     * AC6.4 #3 — Les 2 routes N'ONT PAS le middleware `auth.v1.workstation`
     * (assertion négative, D2 — postes pas enrôlés JWT à l'install/boot).
     */
    #[Test]
    public function the_two_wpkg_out_routes_do_not_have_jwt_middleware(): void
    {
        $content = $this->routesContent();
        $statements = explode(';', $content);

        foreach (self::NEEDLES as $name => $needle) {
            $stmt = $this->statementFor($statements, $needle);
            self::assertNotNull($stmt, "Statement de la route /wpkg/{$name}_out.php introuvable");

            self::assertDoesNotMatchRegularExpression(
                '/auth\.v1\.workstation/',
                $stmt,
                "La route /wpkg/{$name}_out.php NE DOIT PAS porter auth.v1.workstation (D2). "
                . 'Bloc fautif : ' . substr(trim($stmt), 0, 200),
            );
            // Pas non plus de Bearer/Sanctum.
            self::assertDoesNotMatchRegularExpression(
                '/auth:sanctum|auth\.v1\.lan-only/',
                $stmt,
                "La route /wpkg/{$name}_out.php NE DOIT PAS porter de middleware d'auth/JWT (D2).",
            );
        }
    }

    /**
     * AC6.4 #4 — Les 2 routes ONT le middleware `local.request` (+ throttle).
     */
    #[Test]
    public function the_two_wpkg_out_routes_have_local_request_middleware(): void
    {
        $content = $this->routesContent();
        $statements = explode(';', $content);

        foreach (self::NEEDLES as $name => $needle) {
            $stmt = $this->statementFor($statements, $needle);
            self::assertNotNull($stmt, "Statement de la route /wpkg/{$name}_out.php introuvable");

            self::assertMatchesRegularExpression(
                '/local\.request/',
                $stmt,
                "La route /wpkg/{$name}_out.php doit porter le middleware local.request (D2). "
                . 'Bloc fautif : ' . substr(trim($stmt), 0, 200),
            );
            self::assertMatchesRegularExpression(
                '/throttle:\d+,\d+/',
                $stmt,
                "La route /wpkg/{$name}_out.php doit porter un middleware throttle (D2).",
            );
        }
    }

    /**
     * @param  list<string>  $statements
     */
    private function statementFor(array $statements, string $needle): ?string
    {
        foreach ($statements as $stmt) {
            if (preg_match('@' . $needle . '@', $stmt) === 1) {
                return $stmt;
            }
        }

        return null;
    }
}
