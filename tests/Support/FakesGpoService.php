<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Gpo\Services\GpoService;
use App\Gpo\Support\SambaToolRunner;
use Illuminate\Support\Facades\Process;

/**
 * Helper de test pour les services consommant {@see GpoService} (Story 16.1).
 *
 * Fournit des fixtures réutilisables (output simulé `samba-tool gpo listall`,
 * etc.) et un wireup minimal de `Process::fake()` pour les tests unitaires
 * qui veulent injecter un comportement déterministe sans toucher au binaire
 * réel `samba-tool`.
 *
 * Exemples d'utilisation :
 *
 * ```php
 * // Dans un test :
 * use Tests\Support\FakesGpoService;
 *
 * Process::fake([
 *     '*samba-tool* gpo listall *' => Process::result(FakesGpoService::listallOutput()),
 * ]);
 *
 * $service = FakesGpoService::makeService();
 * $gpos = $service->list();
 * $this->assertCount(3, $gpos);
 * ```
 */
final class FakesGpoService
{
    /**
     * Output typique de `samba-tool gpo listall` — 3 GPOs simulées
     * couvrant les cas standard (default + custom + une avec accents).
     */
    public static function listallOutput(): string
    {
        return <<<'OUT'
GPO          : {31B2F340-016D-11D2-945F-00C04FB984F9}
display name : Default Domain Policy
path         : \\example.org\sysvol\example.org\Policies\{31B2F340-016D-11D2-945F-00C04FB984F9}
dn           : CN={31B2F340-016D-11D2-945F-00C04FB984F9},CN=Policies,CN=System,DC=example,DC=org
version      : 65539

GPO          : {6AC1786C-016F-11D2-945F-00C04FB984F9}
display name : Default Domain Controllers Policy
path         : \\example.org\sysvol\example.org\Policies\{6AC1786C-016F-11D2-945F-00C04FB984F9}
dn           : CN={6AC1786C-016F-11D2-945F-00C04FB984F9},CN=Policies,CN=System,DC=example,DC=org
version      : 12

GPO          : {AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}
display name : redirections
path         : \\example.org\sysvol\example.org\Policies\{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}
dn           : CN={AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE},CN=Policies,CN=System,DC=example,DC=org
version      : 3
OUT;
    }

    /**
     * Output typique de `samba-tool gpo show <GUID>`.
     */
    public static function showOutput(string $name = '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}', string $displayName = 'redirections', int $version = 3): string
    {
        return implode("\n", [
            'GPO          : ' . $name,
            'display name : ' . $displayName,
            'path         : \\\\example.org\sysvol\example.org\Policies\\' . $name,
            'dn           : CN=' . $name . ',CN=Policies,CN=System,DC=example,DC=org',
            'version      : ' . $version,
        ]);
    }

    /**
     * Output typique de `samba-tool gpo listcontainers <GUID>`.
     */
    public static function listContainersOutput(): string
    {
        return <<<'OUT'
   dn: OU=Salles,DC=example,DC=org
   dn: OU=Profs,OU=Salles,DC=example,DC=org
OUT;
    }

    /**
     * Output typique de `samba-tool gpo getlink <DN>` — 2 GPOs liées,
     * une enforced, une normale.
     */
    public static function getLinkOutput(): string
    {
        return <<<'OUT'
GPO     : {31B2F340-016D-11D2-945F-00C04FB984F9}
Name    : Default Domain Policy
Options : 0

GPO     : {AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}
Name    : redirections
Options : 2
OUT;
    }

    /**
     * Output typique de `samba-tool gpo getinheritance <DN>`.
     *
     * @param  bool  $inherit  true → "Inheritance Flag: GPO_INHERIT", false → "GPO_BLOCK_INHERITANCE".
     */
    public static function getInheritanceOutput(bool $inherit = true): string
    {
        return $inherit ? 'Inheritance Flag: GPO_INHERIT' : 'Inheritance Flag: GPO_BLOCK_INHERITANCE';
    }

    /**
     * Output typique d'une erreur samba-tool (stderr non vide + exit != 0).
     */
    public static function errorOutput(): string
    {
        return "ERROR(<class 'samba.NTSTATUSError'>): Failed to connect to AD: NT_STATUS_ACCESS_DENIED";
    }

    /**
     * Construit une instance de GpoService avec un runner réel — utiliser
     * avec `Process::fake()` pour injecter des outputs déterministes.
     */
    public static function makeService(?SambaToolRunner $runner = null): GpoService
    {
        return new GpoService($runner ?? new SambaToolRunner());
    }
}
