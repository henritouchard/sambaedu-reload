<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Dto\GpoSummary;
use App\Gpo\Services\GpoService;
use App\Gpo\Support\SambaToolRunner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Mockery;
use Mockery\MockInterface;

/**
 * Helper de test pour les services consommant {@see GpoService} (Story 16.1).
 *
 * Fournit deux modes d'utilisation :
 *
 * 1. **Outputs samba-tool fixtures statiques** (`listallOutput()`,
 *    `showOutput()`, etc.) — pour tester `GpoService` lui-même via
 *    `Process::fake()`.
 *
 * 2. **Builder fluide de mock** (`make()->withGpos(...)->bind()`) — pour les
 *    tests Feature Livewire qui n'ont besoin que de stubber les méthodes
 *    publiques de `GpoService` sans toucher au binaire. Évite la duplication
 *    de boilerplate Mockery dans chaque test (Story 16.2 fix #11).
 *
 * Exemples :
 *
 * ```php
 * // Mode fixtures samba-tool :
 * Process::fake([
 *     '*samba-tool* gpo listall *' => Process::result(FakesGpoService::listallOutput()),
 * ]);
 * $service = FakesGpoService::makeService();
 *
 * // Mode mock fluide pour tests Feature :
 * FakesGpoService::make()
 *     ->withGpos([$gpo1, $gpo2])
 *     ->withGpo('{guid}', $gpo1)
 *     ->withContainersFor('{guid}', ['OU=Salles,DC=ex,DC=org'])
 *     ->withLinksFor('OU=Salles,DC=ex,DC=org', [$link1])
 *     ->withInheritanceFor('OU=Salles,DC=ex,DC=org', true)
 *     ->bind($this->app);
 * ```
 */
final class FakesGpoService
{
    private ?MockInterface $mock = null;

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

    // -------------------------------------------------------------------------
    // Builder fluide de mock (Story 16.2 fix #11)
    // -------------------------------------------------------------------------

    /**
     * Démarre la construction d'un mock fluide de {@see GpoService}.
     */
    public static function make(): self
    {
        $f = new self();
        $f->mock = Mockery::mock(GpoService::class);
        return $f;
    }

    /**
     * Stub `list()` → Collection des GPOs fournies.
     *
     * @param iterable<GpoSummary> $gpos
     */
    public function withGpos(iterable $gpos): self
    {
        $collection = $gpos instanceof Collection ? $gpos : collect($gpos);
        $this->mock->shouldReceive('list')->andReturn($collection);
        return $this;
    }

    /**
     * Force `list()` à lever une exception (pour tester AC1.7).
     */
    public function withListThrowing(\Throwable $exception): self
    {
        $this->mock->shouldReceive('list')->andThrow($exception);
        return $this;
    }

    /**
     * Stub `get($name)` → le GpoSummary fourni (ou null pour 404).
     */
    public function withGpo(string $name, ?GpoSummary $gpo): self
    {
        $this->mock->shouldReceive('get')->with($name)->andReturn($gpo);
        return $this;
    }

    /**
     * Force `get($name)` à lever une exception.
     */
    public function withGetThrowing(string $name, \Throwable $exception): self
    {
        $this->mock->shouldReceive('get')->with($name)->andThrow($exception);
        return $this;
    }

    /**
     * Stub `listContainers($name)` → liste de DNs.
     *
     * @param list<string> $containerDns
     */
    public function withContainersFor(string $name, array $containerDns): self
    {
        $this->mock->shouldReceive('listContainers')->with($name)->andReturn($containerDns);
        return $this;
    }

    /**
     * Stub `getLinks($dn)` → liste de GpoLink.
     *
     * @param list<GpoLink> $links
     */
    public function withLinksFor(string $containerDn, array $links): self
    {
        $this->mock->shouldReceive('getLinks')->with($containerDn)->andReturn($links);
        return $this;
    }

    /**
     * Stub `getInheritance($dn)` → bool.
     */
    public function withInheritanceFor(string $containerDn, bool $inherit): self
    {
        $this->mock->shouldReceive('getInheritance')->with($containerDn)->andReturn($inherit);
        return $this;
    }

    /**
     * Catch-all : tout appel à getLinks/getInheritance non explicitement
     * configuré retournera la valeur fournie. Évite les "should not receive"
     * inattendus quand le test n'a pas besoin de granularité par DN.
     */
    public function withDefaultLinks(array $links = []): self
    {
        $this->mock->shouldReceive('getLinks')->andReturn($links);
        return $this;
    }

    public function withDefaultInheritance(bool $inherit = true): self
    {
        $this->mock->shouldReceive('getInheritance')->andReturn($inherit);
        return $this;
    }

    // -------------------------------------------------------------------------
    // Builders write — Story 16.5 (setLink / removeLink / setInheritance / reorderLinks)
    // -------------------------------------------------------------------------

    /**
     * Stub `setLink()` → retourne le bool fourni (true par défaut = succès).
     * Sans argument, accepte n'importe quelle combinaison de params.
     */
    public function withSetLinkResult(bool $result = true): self
    {
        $this->mock->shouldReceive('setLink')->andReturn($result);
        return $this;
    }

    /**
     * Force `setLink()` à lever une exception (test gestion d'erreur UI).
     */
    public function withSetLinkThrowing(\Throwable $e): self
    {
        $this->mock->shouldReceive('setLink')->andThrow($e);
        return $this;
    }

    /**
     * Stub `removeLink()` → retourne le bool fourni.
     */
    public function withRemoveLinkResult(bool $result = true): self
    {
        $this->mock->shouldReceive('removeLink')->andReturn($result);
        return $this;
    }

    public function withRemoveLinkThrowing(\Throwable $e): self
    {
        $this->mock->shouldReceive('removeLink')->andThrow($e);
        return $this;
    }

    /**
     * Stub `setInheritance()` → retourne le bool fourni.
     */
    public function withSetInheritanceResult(bool $result = true): self
    {
        $this->mock->shouldReceive('setInheritance')->andReturn($result);
        return $this;
    }

    /**
     * Stub `reorderLinks()` → retourne le bool fourni.
     */
    public function withReorderLinksResult(bool $result = true): self
    {
        $this->mock->shouldReceive('reorderLinks')->andReturn($result);
        return $this;
    }

    public function withReorderLinksThrowing(\Throwable $e): self
    {
        $this->mock->shouldReceive('reorderLinks')->andThrow($e);
        return $this;
    }

    /**
     * Affirme qu'aucune méthode de lecture ne sera appelée. Utile pour les
     * tests de validation qui doivent rejeter les inputs avant tout dispatch.
     */
    public function expectNoCalls(): self
    {
        $this->mock->shouldNotReceive('list');
        $this->mock->shouldNotReceive('get');
        $this->mock->shouldNotReceive('listContainers');
        $this->mock->shouldNotReceive('getLinks');
        $this->mock->shouldNotReceive('getInheritance');
        $this->mock->shouldNotReceive('setLink');
        $this->mock->shouldNotReceive('removeLink');
        $this->mock->shouldNotReceive('setInheritance');
        $this->mock->shouldNotReceive('reorderLinks');
        return $this;
    }

    /**
     * Bind le mock dans le container de l'application Laravel passé.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public function bind($app): MockInterface
    {
        $mock = $this->mock;
        $app->bind(GpoService::class, fn() => $mock);
        return $mock;
    }

    /**
     * Retourne le mock sous-jacent (pour ajouter des `shouldReceive` ad-hoc).
     */
    public function mock(): MockInterface
    {
        return $this->mock;
    }
}
