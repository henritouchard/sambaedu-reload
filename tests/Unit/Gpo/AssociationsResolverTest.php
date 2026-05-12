<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Dto\AppCustomization\AppContext;
use App\Gpo\Services\AssociationsResolver;
use App\Gpo\Services\PackagesXmlAssociationsReader;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `AssociationsResolver` — Story 16.3c AC6.5.
 *
 * Couvre la logique métier d'intersection / filtrage / merge / delta légère
 * (cf. AC3.5 étapes 1-8). Mocks des collaborateurs (`PackagesXmlAssociationsReader`,
 * `WorkstationPackagesResolver`) pour rester unit pur.
 */
class AssociationsResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/assoc-resolver-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) { @unlink($f); }
        @rmdir($this->tmpDir);
        Mockery::close();
        parent::tearDown();
    }

    private function makeContext(string $machine = 'post01', array $list = ['Profs', 'Parc1']): AppContext
    {
        return AppContext::fromApcuArray([
            'machine' => ['cn' => $machine],
            'user' => ['cn' => 'jdoe'],
            'list' => $list,
            'list_u' => $list,
            'os' => 'windows',
            'time' => time(),
        ]);
    }

    private function mockReader(array $associations): PackagesXmlAssociationsReader
    {
        $mock = Mockery::mock(PackagesXmlAssociationsReader::class);
        $mock->shouldReceive('read')->andReturn($associations);
        return $mock;
    }

    private function mockPackagesResolver(array $packages): WorkstationPackagesResolver
    {
        $mock = Mockery::mock(WorkstationPackagesResolver::class);
        $mock->shouldReceive('resolve')->andReturn(new Collection($packages));
        return $mock;
    }

    #[Test]
    public function it_returns_empty_result_when_no_apps_installed(): void
    {
        $reader = $this->mockReader(['firefox' => ['.html' => ['ProgId' => 'FFHTML', 'type' => 'file']]]);
        $packagesResolver = $this->mockPackagesResolver([]); // aucun package installé

        $resolver = new AssociationsResolver(
            $reader, $packagesResolver,
            $this->tmpDir . '/default.xml',
            $this->tmpDir . '/sys.json',
            $this->tmpDir . '/local.json',
        );

        $result = $resolver->resolve($this->makeContext(), []);
        $this->assertSame([], $result);
    }

    #[Test]
    public function it_includes_default_xml_associations(): void
    {
        $reader = $this->mockReader([]);
        $packagesResolver = $this->mockPackagesResolver([]);

        file_put_contents($this->tmpDir . '/default.xml', <<<'XML'
        <?xml version="1.0"?>
        <root>
          <Association ProgId="WindowsPhotoViewer" Identifier=".jpg" type="file"/>
        </root>
        XML);

        $resolver = new AssociationsResolver(
            $reader, $packagesResolver,
            $this->tmpDir . '/default.xml',
            $this->tmpDir . '/sys.json',
            $this->tmpDir . '/local.json',
        );

        $result = $resolver->resolve($this->makeContext(), []);
        $this->assertArrayHasKey('.jpg', $result);
        $this->assertSame('WindowsPhotoViewer', $result['.jpg']['ProgId']);
    }

    #[Test]
    public function it_filters_by_workstation_installed_apps_only(): void
    {
        $reader = $this->mockReader([
            'firefox' => ['.html' => ['ProgId' => 'FFHTML', 'type' => 'file']],
            'photoshop' => ['.psd' => ['ProgId' => 'AdobePSD', 'type' => 'file']],
        ]);
        // Seul firefox est installé sur ce poste.
        $packagesResolver = $this->mockPackagesResolver(['firefox']);

        file_put_contents($this->tmpDir . '/sys.json', json_encode([
            'firefox' => ['all'],
            'photoshop' => ['all'],
        ]));

        $resolver = new AssociationsResolver(
            $reader, $packagesResolver,
            $this->tmpDir . '/default.xml',
            $this->tmpDir . '/sys.json',
            $this->tmpDir . '/local.json',
        );

        $result = $resolver->resolve($this->makeContext(), []);
        $this->assertArrayHasKey('.html', $result);
        $this->assertArrayNotHasKey('.psd', $result);
    }

    #[Test]
    public function it_applies_user_groups_filter_with_all_and_force(): void
    {
        $reader = $this->mockReader([
            'firefox' => ['.html' => ['ProgId' => 'FFHTML', 'type' => 'file']],
            'libreoffice' => ['.odt' => ['ProgId' => 'LO_ODT', 'type' => 'file']],
        ]);
        $packagesResolver = $this->mockPackagesResolver(['firefox', 'libreoffice']);

        // firefox = pour tous (all), libreoffice = pour Profs uniquement.
        file_put_contents($this->tmpDir . '/sys.json', json_encode([
            'firefox' => ['all'],
            'libreoffice' => ['Profs'],
        ]));

        $resolver = new AssociationsResolver(
            $reader, $packagesResolver,
            $this->tmpDir . '/default.xml',
            $this->tmpDir . '/sys.json',
            $this->tmpDir . '/local.json',
        );

        // Context = ['Profs', 'Parc1'] → both apps match.
        $result = $resolver->resolve($this->makeContext('post01', ['Profs', 'Parc1']), []);
        $this->assertArrayHasKey('.html', $result);
        $this->assertArrayHasKey('.odt', $result);

        // Context = ['Eleves', 'Parc1'] → seul firefox (all).
        $result2 = $resolver->resolve($this->makeContext('post01', ['Eleves', 'Parc1']), []);
        $this->assertArrayHasKey('.html', $result2);
        $this->assertArrayNotHasKey('.odt', $result2);
    }

    #[Test]
    public function it_local_associations_override_system_ones(): void
    {
        $reader = $this->mockReader([
            'firefox' => ['.html' => ['ProgId' => 'FFHTML_v1', 'type' => 'file']],
        ]);
        // Le système et le local pointent tous deux sur firefox mais l'app
        // local a une autre liste. La merge se fait dans l'ordre système puis
        // local — donc le local "écrase". On vérifie l'effet en ajoutant une
        // association supplémentaire côté firefox via packages.xml :
        $reader2 = $this->mockReader([
            'firefox' => [
                '.html' => ['ProgId' => 'FFHTML', 'type' => 'file'],
            ],
        ]);
        $packagesResolver = $this->mockPackagesResolver(['firefox']);

        file_put_contents($this->tmpDir . '/sys.json', json_encode([
            'firefox' => ['Eleves'],
        ]));
        file_put_contents($this->tmpDir . '/local.json', json_encode([
            'firefox' => ['Profs'],
        ]));

        $resolver = new AssociationsResolver(
            $reader2, $packagesResolver,
            $this->tmpDir . '/default.xml',
            $this->tmpDir . '/sys.json',
            $this->tmpDir . '/local.json',
        );

        // Context Profs → seul local matche. Si on était dans Eleves, sys+local pourraient diverger.
        $result = $resolver->resolve($this->makeContext('post01', ['Profs']), []);
        $this->assertArrayHasKey('.html', $result);
    }

    #[Test]
    public function it_returns_delta_only_vs_local_assocs_input(): void
    {
        $reader = $this->mockReader([
            'firefox' => [
                '.html' => ['ProgId' => 'FFHTML', 'type' => 'file'],
                '.xml'  => ['ProgId' => 'FFXML', 'type' => 'file'],
            ],
        ]);
        $packagesResolver = $this->mockPackagesResolver(['firefox']);

        file_put_contents($this->tmpDir . '/sys.json', json_encode([
            'firefox' => ['all'],
        ]));

        $resolver = new AssociationsResolver(
            $reader, $packagesResolver,
            $this->tmpDir . '/default.xml',
            $this->tmpDir . '/sys.json',
            $this->tmpDir . '/local.json',
        );

        // Le poste déclare déjà avoir `.html → FFHTML`. On ne doit recevoir QUE `.xml`.
        $localAssocs = [
            '.html' => ['ProgId' => 'FFHTML', 'type' => 'file'],
        ];
        $result = $resolver->resolve($this->makeContext(), $localAssocs);
        $this->assertArrayNotHasKey('.html', $result);
        $this->assertArrayHasKey('.xml', $result);
    }

    #[Test]
    public function parse_local_assocs_normalizes_legacy_string_input(): void
    {
        $reader = $this->mockReader([]);
        $packagesResolver = $this->mockPackagesResolver([]);
        $resolver = new AssociationsResolver(
            $reader, $packagesResolver,
            $this->tmpDir . '/default.xml',
            $this->tmpDir . '/sys.json',
            $this->tmpDir . '/local.json',
        );

        // Iso-legacy `associations_out.php:32-39` format `{type: ["identifier,ProgId"]}`.
        $listJson = json_encode([
            'file' => ['.html,FFHTML', '.txt,Notepad'],
            'protocol' => ['http,FFURL'],
        ]);

        $parsed = $resolver->parseLocalAssocs($listJson);

        $this->assertSame([
            '.html' => ['ProgId' => 'FFHTML', 'type' => 'file'],
            '.txt'  => ['ProgId' => 'Notepad', 'type' => 'file'],
            'http'  => ['ProgId' => 'FFURL', 'type' => 'protocol'],
        ], $parsed);
    }

    #[Test]
    public function parse_local_assocs_uses_greedy_split_on_last_comma_iso_legacy(): void
    {
        // Iso-legacy `associations_out.php:36` regex `/^\s*(.*)\s*,\s*(.*)$/`
        // greedy : sur input `".html,Foo,Bar"`, capture `(".html,Foo", "Bar")`.
        // Review 16.3c #M1 : la version originale (non-greedy `(.*?)`) capturait
        // `(".html", "Foo,Bar")`, écart sémantique avec legacy iso-bytes.
        $reader = $this->mockReader([]);
        $packagesResolver = $this->mockPackagesResolver([]);
        $resolver = new AssociationsResolver(
            $reader, $packagesResolver,
            $this->tmpDir . '/default.xml',
            $this->tmpDir . '/sys.json',
            $this->tmpDir . '/local.json',
        );

        $parsed = $resolver->parseLocalAssocs(json_encode([
            'file' => ['.html,Foo,Bar'],
        ]));

        $this->assertSame([
            '.html,Foo' => ['ProgId' => 'Bar', 'type' => 'file'],
        ], $parsed);
    }
}
