<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Dto\AppCustomization\AppContext;
use App\Gpo\Services\AssociationsResolver;
use App\Gpo\Services\PackagesXmlAssociationsReader;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test Feature comparison fixture — Story 16.3c AC4.2 / AC6.7.
 *
 * Diff structurel (ksort récursif) entre sortie native et fixture legacy
 * capturée sur la VM (`tests/Fixtures/Gpo/legacy-associations-out.json`).
 *
 * Le fixture livré dans cette story est **artisanal** (capture VM = action
 * Henri T0.10). Si le fixture est artisanal, le test marque skip avec note.
 * Pattern iso `VeyonOutComparisonTest`.
 */
#[Group('requires-fixture-capture')]
class AssociationsOutComparisonTest extends TestCase
{
    private const VALID_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const FIXTURE_PATH = __DIR__ . '/../../Fixtures/Gpo/legacy-associations-out.json';
    private const SAMPLE_XML_PATH = __DIR__ . '/../../Fixtures/Gpo/packages-xml-sample.xml';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function native_output_diff_matches_legacy_fixture_structurally(): void
    {
        if (! is_file(self::FIXTURE_PATH)) {
            $this->markTestSkipped('Fixture legacy non capturé sur VM (T0.10 — action Henri).');
        }

        $expected = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);
        if (! is_array($expected) || ! isset($expected['result'])) {
            $this->markTestSkipped('Fixture mal formé.');
        }

        // Setup réplique du contexte qui a produit le fixture artisanal :
        //  - firefox installé sur le poste
        //  - default.xml expose `.jpg → WindowsPhotoViewer`
        //  - sys.json `firefox => ['all']`
        $defaultXml = tempnam(sys_get_temp_dir(), 'cmp-default-') . '.xml';
        file_put_contents($defaultXml, <<<'XML'
        <?xml version="1.0"?>
        <root>
          <Association ProgId="WindowsPhotoViewer" Identifier=".jpg" type="file"/>
        </root>
        XML);

        $sysJson = tempnam(sys_get_temp_dir(), 'cmp-sys-') . '.json';
        file_put_contents($sysJson, json_encode(['firefox' => ['all']]));

        $localJson = tempnam(sys_get_temp_dir(), 'cmp-local-') . '.json';
        file_put_contents($localJson, '{}');

        // Reader sur le packages-xml-sample
        $this->app->bind(PackagesXmlAssociationsReader::class, function () {
            $reader = new PackagesXmlAssociationsReader();
            // Lire directement le sample.
            $reflectionForcedPath = self::SAMPLE_XML_PATH;
            return new class($reflectionForcedPath) extends PackagesXmlAssociationsReader {
                public function __construct(private string $forced) {}
                public function read(?string $p = null): array { return parent::read($this->forced); }
            };
        });

        // WPKG resolver : firefox installé
        $wpkg = Mockery::mock(WorkstationPackagesResolver::class);
        $wpkg->shouldReceive('resolve')->andReturn(new Collection(['firefox']));
        $this->app->instance(WorkstationPackagesResolver::class, $wpkg);

        // AppContextRepository
        $ctx = AppContext::fromApcuArray([
            'machine' => ['cn' => 'post01'],
            'user' => ['cn' => 'jdoe'],
            'list' => ['Profs'],
            'list_u' => ['Profs'],
            'os' => 'windows',
            'time' => time(),
        ]);
        $this->app->bind(AppContextRepository::class, function () use ($ctx) {
            return new class($ctx) implements AppContextRepository {
                public function __construct(private AppContext $ctx) {}
                public function findById(string $id): ?AppContext { return $this->ctx; }
            };
        });
        // AssociationsResolver custom paths
        $this->app->bind(AssociationsResolver::class, function ($app) use ($defaultXml, $sysJson, $localJson) {
            return new AssociationsResolver(
                $app->make(PackagesXmlAssociationsReader::class),
                $app->make(WorkstationPackagesResolver::class),
                $defaultXml, $sysJson, $localJson,
            );
        });

        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => json_encode([]),
        ]);

        $resp->assertOk();
        $native = json_decode((string) $resp->getContent(), true);

        @unlink($defaultXml);
        @unlink($sysJson);
        @unlink($localJson);

        // Diff structurel avec ksort récursif (parité 16.3b D8).
        $sortRecursive = function (array $arr) use (&$sortRecursive): array {
            ksort($arr);
            foreach ($arr as $k => $v) {
                if (is_array($v)) {
                    $arr[$k] = $sortRecursive($v);
                }
            }
            return $arr;
        };

        $this->assertSame(
            $sortRecursive($expected),
            $sortRecursive($native),
            "Différence structurelle entre output natif et fixture legacy.\n"
            . "Legacy fixture: " . json_encode($expected, JSON_PRETTY_PRINT) . "\n"
            . "Native output: " . json_encode($native, JSON_PRETTY_PRINT),
        );
    }
}
