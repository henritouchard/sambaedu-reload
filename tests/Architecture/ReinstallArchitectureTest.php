<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 3.11 — Garde-fous architecturaux de la réinstallation OS pilotée.
 *
 *  1. Le bloc `:action` de `known.blade.php` chaine vers la route NATIVE
 *     `/ipxe/action/{action}` (path-param) et JAMAIS vers le tombstone
 *     legacy `/ipxe/action.php` (D7).
 *  2. Le garde `Str::isUuid` de `WorkstationLocator` et le contrat UUID-only
 *     restent intacts (non-régression — fix crash terrain 2026-06-26).
 *  3. La route `/ipxe/action/{action}` est déclarée AVANT le catchall legacy.
 */
class ReinstallArchitectureTest extends TestCase
{
    #[Test]
    public function known_menu_chains_to_native_action_route_not_tombstone(): void
    {
        $blade = realpath(__DIR__ . '/../../resources/views/ipxe/menu/known.blade.php');
        self::assertNotFalse($blade, 'known.blade.php introuvable');
        $content = (string) file_get_contents($blade);

        self::assertStringContainsString(
            '/ipxe/action/{{ $action[\'name\'] ?? \'\' }}',
            $content,
            'Le bloc :action doit chainer vers la route native /ipxe/action/{action} (path-param).',
        );

        self::assertStringNotContainsString(
            '/ipxe/action.php',
            $content,
            'Le bloc :action ne doit PLUS chainer vers le tombstone legacy /ipxe/action.php (D7).',
        );
    }

    #[Test]
    public function workstation_locator_preserves_uuid_guard(): void
    {
        $locator = realpath(__DIR__ . '/../../app/Ipxe/Services/WorkstationLocator.php');
        self::assertNotFalse($locator, 'WorkstationLocator introuvable');
        $content = (string) file_get_contents($locator);

        self::assertStringContainsString(
            'Str::isUuid',
            $content,
            'Le garde Str::isUuid du locator ne doit JAMAIS être retiré (fix crash 2026-06-26).',
        );
    }

    #[Test]
    public function ipxe_action_route_is_declared_before_catchall(): void
    {
        $routesFile = realpath(__DIR__ . '/../../routes/web.php');
        self::assertNotFalse($routesFile, 'routes/web.php introuvable');
        $content = (string) file_get_contents($routesFile);

        $actionPattern = "/Route::match\s*\([^;]*?['\"]\\/ipxe\\/action\\/\\{action\\}['\"]/";
        self::assertSame(
            1,
            preg_match($actionPattern, $content, $m, PREG_OFFSET_CAPTURE),
            'La route native /ipxe/action/{action} n\'est pas déclarée dans routes/web.php',
        );
        $actionOffset = $m[0][1];

        $catchallPattern = "/Route::match\s*\([^)]*['\"]\\{path\\}['\"]/";
        self::assertSame(
            1,
            preg_match($catchallPattern, $content, $cm, PREG_OFFSET_CAPTURE),
            'Le catchall legacy {path} n\'est pas déclaré dans routes/web.php',
        );
        $catchallOffset = $cm[0][1];

        self::assertLessThan(
            $catchallOffset,
            $actionOffset,
            'ORDRE INVALIDE : /ipxe/action/{action} doit être déclarée AVANT le catchall legacy.',
        );
    }
}
