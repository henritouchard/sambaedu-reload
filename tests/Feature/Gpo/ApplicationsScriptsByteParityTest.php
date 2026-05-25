<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Services\ApplicationTemplatesScanner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsScriptParity;
use Tests\TestCase;

/**
 * Story 17.2 — AC2.2 / D4.
 *
 * Test de parité bytes strict : la sortie du moteur natif `ApplicationScriptsAssembler`
 * doit être byte-identique à la sortie legacy `gpo/applications.php` pour les mêmes
 * paramètres d'entrée.
 *
 * **Référence** : audit 17.1 Section G.1 — « verrouiller la parité bytes ».
 *
 * **Fixtures** : `tests/Fixtures/Gpo/applications/<scenario>/expected.<ext>`
 * Capturées sur VM legacy (paquet `sambaedu` 4.17.285) le 2026-05-21.
 * Procédure de (re)capture : `tests/Fixtures/Gpo/applications/README.md`.
 *
 * **Post-review 17.4 P6** : le helper de parité (`assertScriptParity()`,
 * `buildLineDiff()`, `configureForFixtures()`, `makeAssembler()`) est désormais
 * consommé depuis le trait partagé `Tests\Concerns\AssertsScriptParity` (plus de
 * copie inline divergente). Les normalizers `id` additionnels du trait (header
 * bash + `-F "id=…"`) sont **no-op** sur 17.2 : l'`$info['id']` est figé à la
 * valeur de capture, donc la même valeur est normalisée des deux côtés
 * (expected == actual avant normalisation pour ces tokens).
 *
 * **Source des scripts** : 17.2 reste VM-only (`/usr/share/sambaedu/applications/`,
 * groupe `requires-fixture-capture`). Le snapshot portable P3 est exploité par
 * `ApplicationsScriptsCriticalParityTest` (17.4) uniquement.
 *
 * Si les fixtures ne sont pas présentes (ex. CI sans VM), les tests sont skippés
 * avec `markTestSkipped` (groupe `requires-fixture-capture`).
 */
#[Group('requires-fixture-capture')]
class ApplicationsScriptsByteParityTest extends TestCase
{
    use AssertsScriptParity;

    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/Gpo/applications';

    private const PACKAGE_PATH = '/usr/share/sambaedu/applications/';
    private const LOCAL_PATH   = '/etc/sambaedu/applications/';

    /**
     * AC2.2 — Scénario 1 : Windows logon utilisateur standard.
     *
     * Clés couvertes : SE4FS_NAME, DOMAIN, UAI, SE4INSTALL_NAME (wallpaper).
     */
    #[Test]
    public function it_matches_legacy_bytes_for_windows_logon_user(): void
    {
        $fixture = self::FIXTURES_DIR . '/windows_logon_user/expected.cmd';
        if (! is_file($fixture)) {
            self::markTestSkipped('Fixture non capturée : ' . $fixture . '. Voir README.md pour la procédure.');
        }
        if (! is_dir(self::PACKAGE_PATH)) {
            self::markTestSkipped('Scripts package non présents : ' . self::PACKAGE_PATH . '. Exécuter sur VM.');
        }

        $this->configureForFixtures();
        $assembler = $this->makeAssembler();
        $scanner   = new ApplicationTemplatesScanner();
        $scripts   = $scanner->scan(self::PACKAGE_PATH, self::LOCAL_PATH);

        // $info iso-legacy — mêmes valeurs que lors de la capture fixture
        $info = [
            'os'                 => 'windows',
            'action'             => 'logon',
            'interpreter'        => 'cmd',
            'context'            => '',
            'remote'             => false,
            'machine'            => [
                'cn'       => 'pc-test',
                'dn'       => 'cn=pc-test,ou=salle01,ou=parcs,dc=localdev,dc=fr',
                'memberof' => ['cn=salle01,ou=parcs,dc=localdev,dc=fr'],
            ],
            'user'               => ['cn' => 'testuser', 'memberof' => []],
            'userprofile'        => 'C:\\Users\\testuser',
            'salle'              => 'salle01',
            'parcs'              => ['salle01'],
            'list'               => ['testuser', 'salle01', 'pc-test'],
            'liste_applications' => [],
            'admin'              => 0,
            'id'                 => md5('testuserpc-testlogon'), // e32b20d400dd5651c7544bffa04bb48d
            'speed'              => 0,
        ];

        $out      = $assembler->assemble($info, $scripts);
        $actual   = $out['cmd'];
        $expected = (string) file_get_contents($fixture);

        $this->assertScriptParity($expected, $actual);
    }

    /**
     * AC2.2 / AC2.3 — Scénario 2 : Windows startup machine (context=system).
     *
     * Clés couvertes : NO_INTERNET, DHCP_RESEAU, DHCP_MASQUE, SE4FS_IP, SE4AD_IP
     * (firewall/startup.windows) + ADMINSE_NAME (folders/clean_profiles).
     */
    #[Test]
    public function it_matches_legacy_bytes_for_windows_startup_firewall(): void
    {
        $fixture = self::FIXTURES_DIR . '/windows_startup_firewall/expected.cmd';
        if (! is_file($fixture)) {
            self::markTestSkipped('Fixture non capturée : ' . $fixture . '. Voir README.md pour la procédure.');
        }
        if (! is_dir(self::PACKAGE_PATH)) {
            self::markTestSkipped('Scripts package non présents : ' . self::PACKAGE_PATH . '. Exécuter sur VM.');
        }

        $this->configureForFixtures();
        $assembler = $this->makeAssembler();
        $scanner   = new ApplicationTemplatesScanner();
        $scripts   = $scanner->scan(self::PACKAGE_PATH, self::LOCAL_PATH);

        $info = [
            'os'                 => 'windows',
            'action'             => 'startup',
            'interpreter'        => 'cmd',
            'context'            => 'system',
            'remote'             => false,
            'machine'            => [
                'cn'       => 'pc-test',
                'dn'       => 'cn=pc-test,ou=salle01,ou=parcs,dc=localdev,dc=fr',
                'memberof' => ['cn=salle01,ou=parcs,dc=localdev,dc=fr'],
            ],
            'user'               => ['cn' => 'pc-test', 'memberof' => []],
            'userprofile'        => 'C:\\Users\\Default',
            'salle'              => 'salle01',
            'parcs'              => ['salle01'],
            'list'               => ['pc-test', 'salle01'],
            'liste_applications' => [],
            'admin'              => 0,
            'id'                 => md5('pc-testpc-teststartup'), // bb28a6b4b3480d159d803c740968ea0c
            'speed'              => 0,
        ];

        $out      = $assembler->assemble($info, $scripts);
        $actual   = $out['cmd'];
        $expected = (string) file_get_contents($fixture);

        $this->assertScriptParity($expected, $actual);
    }

    /**
     * AC2.2 / AC2.3 — Scénario 3 : Linux startup machine (GLPI Agent).
     *
     * Clés couvertes : GLPI_URL (glpi/startup.linux) + UAI, SE4FS_NAME, DOMAIN.
     */
    #[Test]
    public function it_matches_legacy_bytes_for_linux_startup_glpi(): void
    {
        $fixture = self::FIXTURES_DIR . '/linux_startup_glpi/expected.sh';
        if (! is_file($fixture)) {
            self::markTestSkipped('Fixture non capturée : ' . $fixture . '. Voir README.md pour la procédure.');
        }
        if (! is_dir(self::PACKAGE_PATH)) {
            self::markTestSkipped('Scripts package non présents : ' . self::PACKAGE_PATH . '. Exécuter sur VM.');
        }

        $this->configureForFixtures();
        $assembler = $this->makeAssembler();
        $scanner   = new ApplicationTemplatesScanner();
        $scripts   = $scanner->scan(self::PACKAGE_PATH, self::LOCAL_PATH);

        $info = [
            'os'                 => 'linux',
            'action'             => 'startup',
            'interpreter'        => 'bash',
            'context'            => '',
            'remote'             => false,
            'machine'            => [
                'cn'       => 'pc-test',
                'dn'       => 'cn=pc-test,ou=salle01,dc=localdev,dc=fr',
                'memberof' => [],
            ],
            'user'               => ['cn' => 'pc-test', 'memberof' => []],
            'userprofile'        => '',
            'salle'              => 'salle01',
            'parcs'              => [],
            'list'               => ['pc-test', 'salle01'],
            'liste_applications' => [],
            'admin'              => 0,
            'id'                 => md5('pc-testpc-teststartuplinux'), // 8a118d6979c527bd242a1e3fc3cb09cb
            'speed'              => 0,
        ];

        $out      = $assembler->assemble($info, $scripts);
        $actual   = $out['bash'];
        $expected = (string) file_get_contents($fixture);

        $this->assertScriptParity($expected, $actual);
    }
}
