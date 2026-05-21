<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Services\ApplicationScriptsAssembler;
use App\Gpo\Services\ApplicationTemplatesScanner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
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
 * **Normalisation** :
 *  - `SET DOMAINSID=<valeur>` → `SET DOMAINSID=__SID__`
 *    (le natif génère DOMAINSID vide, le legacy appelle `net getdomainsid` —
 *    divergence documentée dans `ApplicationScriptsAssembler::headerScripts` ligne ~306)
 *  - Aucune autre normalisation n'est autorisée (CR/LF, charset, séparateurs préservés).
 *
 * **Prérequis** :
 *  - Les scripts `/usr/share/sambaedu/applications/` doivent être présents sur la VM.
 *  - Config `sambaedu.*` doit être positionnée via `config()->set()` dans les tests.
 *
 * Si les fixtures ne sont pas présentes (ex. CI sans VM), les tests sont skippés
 * avec `markTestSkipped` (groupe `requires-fixture-capture`).
 *
 * **Comment régénérer les fixtures** : voir `tests/Fixtures/Gpo/applications/README.md`.
 */
#[Group('requires-fixture-capture')]
class ApplicationsScriptsByteParityTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/Gpo/applications';

    private const PACKAGE_PATH = '/usr/share/sambaedu/applications/';
    private const LOCAL_PATH   = '/etc/sambaedu/applications/';

    /**
     * Normalise `SET DOMAINSID=<valeur>` → `SET DOMAINSID=__SID__`
     * pour absorber la divergence natif (vide) vs legacy (valeur réelle).
     *
     * @param list<array{pattern: string, replacement: string}> $extra
     */
    private function assertScriptParity(string $expected, string $actual, array $extra = []): void
    {
        $normalizers = array_merge(
            [
                // DOMAINSID — valeur lue via `net getdomainsid` dans le legacy ;
                // le natif génère une string vide. Une regex unique capture toutes les valeurs.
                ['pattern' => '/SET DOMAINSID=[^\r\n]*/', 'replacement' => 'SET DOMAINSID=__SID__'],
            ],
            $extra,
        );

        $normExpected = $expected;
        $normActual   = $actual;

        foreach ($normalizers as $n) {
            $normExpected = preg_replace($n['pattern'], $n['replacement'], $normExpected);
            $normActual   = preg_replace($n['pattern'], $n['replacement'], $normActual);
        }

        if ($normExpected !== $normActual) {
            $diff = $this->buildLineDiff($normExpected, $normActual);
            self::fail("Script parity failed.\n\nDiff (expected vs actual):\n" . $diff);
        }

        self::assertSame($normExpected, $normActual);
    }

    /**
     * Génère un diff ligne-par-ligne lisible pour faciliter le debug.
     */
    private function buildLineDiff(string $expected, string $actual): string
    {
        $expLines = explode("\n", $expected);
        $actLines = explode("\n", $actual);
        $maxLines = max(count($expLines), count($actLines));

        $diff = '';
        $diffs = 0;
        for ($i = 0; $i < $maxLines; $i++) {
            $e = $expLines[$i] ?? '[EOF]';
            $a = $actLines[$i] ?? '[EOF]';
            if ($e !== $a) {
                $diff .= sprintf("Line %d:\n  expected: %s\n  actual  : %s\n", $i + 1, var_export($e, true), var_export($a, true));
                $diffs++;
                if ($diffs >= 20) {
                    $diff .= "... (diff truncated after 20 lines)\n";
                    break;
                }
            }
        }

        return $diff ?: '(no line diff found — possible binary difference)';
    }

    /**
     * Prépare l'assembleur natif avec la configuration iso-legacy
     * (identique aux valeurs injectées dans `$config` lors de la capture fixtures).
     */
    private function configureForFixtures(): void
    {
        config([
            // Valeurs du legacy sambaedu.conf sur la VM de test
            'sambaedu.se4fs_name'           => 'se4fs',
            'sambaedu.se4fs_ip'             => '192.168.122.50',
            'sambaedu.domain'               => 'localdev.fr',
            'sambaedu.uai'                  => '0000000x',
            'sambaedu.samba_domain'         => 'LOCALDEV',
            'sambaedu.se4ad_ip'             => '192.168.122.60',
            'sambaedu.se4install_name'      => 'se4install',
            // Valeurs injectées pour couvrir les 8 nouvelles clés (Story 17.2)
            'sambaedu.windows.adminse_name' => 'adminse',
            'sambaedu.glpi_url'             => 'http://glpi.test.fr',
            'sambaedu.no_internet'          => 'pasInternet',
            'sambaedu.dhcp_reseau'          => '192.168.1.0',
            'sambaedu.dhcp_masque'          => '255.255.255.0',
            'sambaedu.cloud_perso_name'     => 'Mes Documents',
            'sambaedu.netlogon_path'        => '/var/lib/samba/sysvol',
            'sambaedu.wpkg.base_url'        => '',
            // Wrapper désactivé pour les tests de parité (flag false = iso-legacy)
            'sambaedu.scripts.logging.enabled' => false,
        ]);
    }

    /**
     * Crée un Assembler natif avec cache whitelist réinitialisé.
     */
    private function makeAssembler(): ApplicationScriptsAssembler
    {
        $assembler = new ApplicationScriptsAssembler();
        $ref = new \ReflectionProperty($assembler, 'substitutionsCache');
        $ref->setValue($assembler, null);

        return $assembler;
    }

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
        $expected = file_get_contents($fixture);

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
        $expected = file_get_contents($fixture);

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
        $expected = file_get_contents($fixture);

        $this->assertScriptParity($expected, $actual);
    }
}
