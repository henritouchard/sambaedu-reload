<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.2 / AC1 / AC8 — Garde-fous architecturaux des routes tombstones du
 * canal client legacy.
 *
 * Tests (lecture textuelle de `routes/web.php`, patron `WpkgOutRoutesTest`) :
 *   1. Chacune des 18 routes tombstone est déclarée.
 *   2. Chacune est déclarée AVANT le catchall legacy `{path}` (sinon le catchall
 *      proxifie et le poste exécute du HTML/erreur).
 *   3. Chacune porte `local.request` + `throttle:…`.
 *   4. Aucune ne porte de middleware d'auth (D2 — postes non enrôlés).
 *   5. Chacune porte `withoutMiddleware(['web'])` (appels machine sans CSRF).
 *   6. `/ipxe/linux/action.php` est déclarée AVANT `/ipxe/{version}/action.php`
 *      (le littéral doit gagner).
 */
class LegacyTombstoneRoutesTest extends TestCase
{
    /**
     * Fragments regex identifiant le path littéral de chaque route tombstone
     * (quoté dans le `Route::match`).
     *
     * @var array<string, string>
     */
    private const NEEDLES = [
        'applications'   => "['\"]/gpo/applications\\.php['\"]",
        'shortcuts'      => "['\"]/gpo/shortcuts_out\\.php['\"]",
        'wallpaper'      => "['\"]/gpo/wallpaper_out\\.php['\"]",
        'veyon'          => "['\"]/gpo/veyon_out\\.php['\"]",
        'no_internet'    => "['\"]/gpo/no_internet_out\\.php['\"]",
        'associations'   => "['\"]/gpo/associations_out\\.php['\"]",
        'firefox'        => "['\"]/gpo/firefox_out\\.php['\"]",
        'thunderbird'    => "['\"]/gpo/thunderbird_out\\.php['\"]",
        'cloud'          => "['\"]/partages/cloud_out\\.php['\"]",
        'hosts_xml'      => "['\"]/wpkg/hosts_xml_out\\.php['\"]",
        'profiles_xml'   => "['\"]/wpkg/profiles_xml_out\\.php['\"]",
        'packages_xml'   => "['\"]/wpkg/packages_xml_out\\.php['\"]",
        'wpkg_log'       => "['\"]/wpkg/wpkg_log\\.php['\"]",
        'download_prefix' => "['\"]/wpkg/download_prefix\\.php['\"]",
        'ipxe_linux'     => "['\"]/ipxe/linux/action\\.php['\"]",
        'ipxe_version'   => "['\"]/ipxe/\\{version\\}/action\\.php['\"]",
        'ipxe_sysprep'   => "['\"]/ipxe/Win10/sysprep\\.xml\\.php['\"]",
        'ipxe_unattend'  => "['\"]/ipxe/Win10/unattend\\.xml\\.php['\"]",
    ];

    private function routesContent(): string
    {
        $routesFile = realpath(__DIR__ . '/../../routes/web.php');
        self::assertNotFalse($routesFile, 'routes/web.php introuvable');

        return (string) file_get_contents($routesFile);
    }

    /**
     * @param  list<string>  $statements
     */
    private function statementFor(array $statements, string $needle): ?string
    {
        foreach ($statements as $stmt) {
            if (preg_match('@' . $needle . '@', $stmt) === 1
                && str_contains($stmt, 'Route::match')) {
                return $stmt;
            }
        }

        return null;
    }

    #[Test]
    public function all_tombstone_routes_are_registered(): void
    {
        $content = $this->routesContent();

        foreach (self::NEEDLES as $name => $needle) {
            $pattern = '@Route::match\s*\([^;]*?' . $needle . '@';
            self::assertSame(
                1,
                preg_match($pattern, $content),
                "La route tombstone '{$name}' n'est pas déclarée dans routes/web.php",
            );
        }

        self::assertStringContainsString(
            'App\\Http\\Controllers\\LegacyTombstoneController',
            $content,
            'Les routes tombstones doivent référencer LegacyTombstoneController',
        );
    }

    #[Test]
    public function all_tombstone_routes_are_declared_before_catchall(): void
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
            $pattern = '@Route::match\s*\([^;]*?' . $needle . '@';
            self::assertSame(
                1,
                preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE),
                "Route tombstone '{$name}' non déclarée",
            );
            self::assertLessThan(
                $catchallOffset,
                $matches[0][1],
                "ORDRE INVALIDE : la route tombstone '{$name}' doit être AVANT le "
                . 'catchall legacy {path}, sinon le catchall proxifie et le poste '
                . 'exécute du HTML/erreur.',
            );
        }
    }

    #[Test]
    public function all_tombstone_routes_have_local_request_and_throttle(): void
    {
        $content = $this->routesContent();
        $statements = explode(';', $content);

        foreach (self::NEEDLES as $name => $needle) {
            $stmt = $this->statementFor($statements, $needle);
            self::assertNotNull($stmt, "Statement de la route tombstone '{$name}' introuvable");

            self::assertMatchesRegularExpression(
                '/local\.request/',
                $stmt,
                "La route tombstone '{$name}' doit porter le middleware local.request.",
            );
            self::assertMatchesRegularExpression(
                '/throttle:\d+,\d+/',
                $stmt,
                "La route tombstone '{$name}' doit porter un middleware throttle.",
            );
        }
    }

    #[Test]
    public function all_tombstone_routes_have_no_auth_middleware(): void
    {
        $content = $this->routesContent();
        $statements = explode(';', $content);

        foreach (self::NEEDLES as $name => $needle) {
            $stmt = $this->statementFor($statements, $needle);
            self::assertNotNull($stmt, "Statement de la route tombstone '{$name}' introuvable");

            self::assertDoesNotMatchRegularExpression(
                '/auth\.v1\.|auth:sanctum|sambaedu\.auth|auth\.federated/',
                $stmt,
                "La route tombstone '{$name}' NE DOIT PAS porter de middleware d'auth (D2). "
                . 'Bloc fautif : ' . substr(trim($stmt), 0, 200),
            );
        }
    }

    #[Test]
    public function all_tombstone_routes_are_without_web_middleware(): void
    {
        $content = $this->routesContent();
        $statements = explode(';', $content);

        foreach (self::NEEDLES as $name => $needle) {
            $stmt = $this->statementFor($statements, $needle);
            self::assertNotNull($stmt, "Statement de la route tombstone '{$name}' introuvable");

            self::assertMatchesRegularExpression(
                "/withoutMiddleware\\(\\[['\"]web['\"]\\]\\)/",
                $stmt,
                "La route tombstone '{$name}' doit porter withoutMiddleware(['web']) "
                . '(appels machine sans session/CSRF).',
            );
        }
    }

    #[Test]
    public function ipxe_linux_action_is_declared_before_generic_version_action(): void
    {
        $content = $this->routesContent();

        self::assertSame(
            1,
            preg_match('@' . self::NEEDLES['ipxe_linux'] . '@', $content, $linuxMatch, PREG_OFFSET_CAPTURE),
            '/ipxe/linux/action.php non déclarée',
        );
        self::assertSame(
            1,
            preg_match('@' . self::NEEDLES['ipxe_version'] . '@', $content, $versionMatch, PREG_OFFSET_CAPTURE),
            '/ipxe/{version}/action.php non déclarée',
        );

        self::assertLessThan(
            $versionMatch[0][1],
            $linuxMatch[0][1],
            'ORDRE INVALIDE : /ipxe/linux/action.php (bash) DOIT être déclarée AVANT '
            . '/ipxe/{version}/action.php (cmd générique), sinon {version}=linux capture '
            . 'l\'URL du démon autorun Linux et lui sert un commentaire cmd invalide.',
        );
    }
}
