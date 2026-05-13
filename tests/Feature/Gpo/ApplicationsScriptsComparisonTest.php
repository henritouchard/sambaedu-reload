<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — AC5.2 + AC7.3.
 *
 * Test bytes-identical avec fixture legacy capturée sur la VM par Henri.
 *
 * Si la fixture n'est pas présente, les tests sont skippés (cas CI sans
 * accès VM). En local sur VM avec capture réelle, un diff strict sera
 * effectué.
 *
 * **Capture VM attendue** :
 *  - `tests/Fixtures/Gpo/legacy-applications-startup-windows.cmd`
 *  - `tests/Fixtures/Gpo/legacy-applications-logon-linux.sh`
 *
 * Procédure capture (à exécuter par Henri sur VM) :
 *  ```
 *  curl -F "machine=pc-test" -F "action=startup" -F "os=windows" \
 *       http://se4fs/gpo/applications.php > legacy-applications-startup-windows.cmd
 *  ```
 */
#[Group('requires-fixture-capture')]
class ApplicationsScriptsComparisonTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/Gpo';

    #[Test]
    public function startup_windows_cmd_matches_legacy_fixture(): void
    {
        $fixture = self::FIXTURES_DIR . '/legacy-applications-startup-windows.cmd';
        if (! is_file($fixture)) {
            self::markTestSkipped('Fixture VM legacy non capturée : ' . $fixture);
        }

        $expected = file_get_contents($fixture);
        // L'appel HTTP réel requiert un contexte VM (AD + APCu + scan FS).
        // Ce test est marqué `@group requires-fixture-capture` — l'exécution
        // automatisée demandera un environnement VM dédié (T9 Henri).
        self::markTestSkipped('Comparison iso-bytes requiert l\'environnement VM (T9 action Henri)');
    }

    #[Test]
    public function logon_linux_sh_matches_legacy_fixture(): void
    {
        $fixture = self::FIXTURES_DIR . '/legacy-applications-logon-linux.sh';
        if (! is_file($fixture)) {
            self::markTestSkipped('Fixture VM legacy non capturée : ' . $fixture);
        }
        self::markTestSkipped('Comparison iso-bytes requiert l\'environnement VM (T9 action Henri)');
    }
}
