<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Story 17.4 — helper de parité bytes factorisé (post-review P6).
 *
 * Extrait de `ApplicationsScriptsByteParityTest` (17.2) pour réutilisation
 * dans `ApplicationsScriptsCriticalParityTest` (17.4) ET re-consommé par 17.2
 * lui-même (post-review P6 : suppression de la copie inline divergente).
 *
 * Fournit :
 *  - `assertScriptParity()`  : normalise + compare byte-par-byte deux scripts.
 *  - `buildLineDiff()`       : diff ligne-par-ligne lisible pour le debug.
 *  - `configureForFixtures()`: injecte la config iso-legacy VM (sambaedu.conf).
 *  - `makeAssembler()`       : crée un `ApplicationScriptsAssembler` (cache reset).
 *  - `applicationsScriptsSource()` : chemin des scripts package (snapshot CI
 *    portable P3, fallback système VM si snapshot absent).
 *
 * **Normalisations appliquées** (post-review P7 — regex `id` restreintes aux
 * contextes session connus, pour ne PAS masquer un hash non-session) :
 *  - `SET DOMAINSID=…`            → `SET DOMAINSID=__SID__` (SID Samba variable).
 *  - `SET id=<32hex>`             → `SET id=__ID__`  (header cmd Windows startup).
 *  - `id=<32hex>` en début de ligne → `id=__ID__`     (header bash Linux).
 *  - `-F "id=<32hex>"`            → `-F "id=__ID__"`  (footer/powershell curl,
 *    cmd ET bash — le seul autre endroit où l'`id` md5 session apparaît).
 *
 * **Innocuité P7 documentée** : l'audit des 5 fragments critiques + headers/
 * footers (cf. `tests/Fixtures/Gpo/applications/README.md` § « Normalisation »)
 * montre qu'aucun hash 32-hex *non-session* n'apparaît dans ces contextes :
 *  - le hash Firefox hardcodé est `308046B0AF4A39CB` (16 chars MAJUSCULES) →
 *    hors champ de `[a-f0-9]{32}` (lowercase + longueur).
 *  - les `md5_file()` du mécanisme `once` produisent un md5 mais ne sont jamais
 *    préfixés par `id=`/`SET id=`/`-F "id="` (ils sont en `.md5` filename ou
 *    comparaison `local_md5`).
 * La regex large `\bid=([a-f0-9]{32})\b` de la version 17.2-bis est donc
 * remplacée par trois ancres de contexte explicites.
 */
trait AssertsScriptParity
{
    /**
     * Snapshot portable du package `/usr/share/sambaedu/applications/`
     * (Story 17.4 P3). Capturé byte-identique au paquet `sambaedu 4.17.285`
     * (SHA256 `8e0b5be2…`, cf. README). Permet aux tests de parité de tourner
     * SANS dépendre du chemin système VM → portable CI.
     */
    private const APPLICATIONS_SNAPSHOT_PATH = __DIR__ . '/../Fixtures/Gpo/applications/_package_snapshot/';

    /** Chemin système legacy (utilisé en fallback si snapshot absent). */
    private const APPLICATIONS_SYSTEM_PATH = '/usr/share/sambaedu/applications/';

    /**
     * Assert que deux scripts (expected vs actual) sont byte-identiques
     * après normalisation des valeurs variables (SID, ID md5 de session).
     *
     * @param list<array{pattern: string, replacement: string}> $extra Normalisations supplémentaires.
     */
    private function assertScriptParity(string $expected, string $actual, array $extra = []): void
    {
        $normalizers = array_merge(
            [
                // DOMAINSID — valeur lue via `net getdomainsid` dans le legacy ;
                // le natif génère une string vide. Une regex unique capture toutes les valeurs.
                ['pattern' => '/SET DOMAINSID=[^\r\n]*/', 'replacement' => 'SET DOMAINSID=__SID__'],
                // id md5 session dans le header cmd Windows (SET id=...).
                ['pattern' => '/SET id=[a-f0-9]{32}/i', 'replacement' => 'SET id=__ID__'],
                // id md5 session dans le header bash Linux (id=... en début de ligne).
                ['pattern' => '/(^|\r?\n)id=[a-f0-9]{32}\b/i', 'replacement' => '$1id=__ID__'],
                // id md5 session dans les `-F "id=…"` des footers / DL powershell
                // (cmd ET bash — seul autre contexte où l'id session apparaît).
                ['pattern' => '/-F "id=[a-f0-9]{32}"/i', 'replacement' => '-F "id=__ID__"'],
            ],
            $extra,
        );

        $normExpected = $expected;
        $normActual   = $actual;

        foreach ($normalizers as $n) {
            $normExpected = (string) preg_replace($n['pattern'], $n['replacement'], $normExpected);
            $normActual   = (string) preg_replace($n['pattern'], $n['replacement'], $normActual);
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

        $diff  = '';
        $diffs = 0;
        for ($i = 0; $i < $maxLines; $i++) {
            $e = $expLines[$i] ?? '[EOF]';
            $a = $actLines[$i] ?? '[EOF]';
            if ($e !== $a) {
                $diff .= sprintf(
                    "Line %d:\n  expected: %s\n  actual  : %s\n",
                    $i + 1,
                    var_export($e, true),
                    var_export($a, true),
                );
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
     * Injecte la configuration sambaedu iso-legacy (valeurs VM de test).
     *
     * Identique aux valeurs injectées dans `$config` lors de la capture fixtures
     * (procédure `tests/Fixtures/Gpo/applications/README.md`).
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
            // Valeurs couvrant les 8 nouvelles clés (Story 17.2)
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
     * Crée un `ApplicationScriptsAssembler` avec cache whitelist réinitialisé.
     */
    private function makeAssembler(): \App\Gpo\Services\ApplicationScriptsAssembler
    {
        $assembler = new \App\Gpo\Services\ApplicationScriptsAssembler();
        $ref       = new \ReflectionProperty($assembler, 'substitutionsCache');
        $ref->setValue($assembler, null);

        return $assembler;
    }

    /**
     * Story 17.4 P3 — chemin des scripts package pour les tests de parité.
     *
     * Préfère le **snapshot portable** committé sous `tests/Fixtures/` (byte-
     * identique au paquet `sambaedu 4.17.285`) → les tests de parité tournent
     * en CI sans dépendre du chemin système VM. Fallback sur le chemin système
     * `/usr/share/sambaedu/applications/` uniquement si le snapshot est absent
     * (ne devrait jamais arriver — le snapshot est versionné).
     *
     * Retourne `null` si AUCUNE source n'est disponible (→ skip propre).
     */
    private function applicationsScriptsSource(): ?string
    {
        if (is_dir(self::APPLICATIONS_SNAPSHOT_PATH)) {
            return self::APPLICATIONS_SNAPSHOT_PATH;
        }
        if (is_dir(self::APPLICATIONS_SYSTEM_PATH)) {
            return self::APPLICATIONS_SYSTEM_PATH;
        }

        return null;
    }
}
