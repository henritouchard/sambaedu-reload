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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature `AssociationsOutController` — Story 16.3c AC6.4.
 *
 * Pattern iso `NetworkOutEndpointTest`.
 */
class AssociationsOutEndpointTest extends TestCase
{
    private const VALID_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $packagesXml;

    protected function setUp(): void
    {
        parent::setUp();
        // Story 16.13bis — `gpo/associations_out.php` transformée en
        // MigrationController::serveFragment ; tests Feature URL caducs (R6).
        $this->markTestSkipped('Story 16.13bis : route legacy transformée en MigrationController (R6).');

        $this->packagesXml = sys_get_temp_dir() . '/packages-' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents($this->packagesXml, <<<'XML'
        <?xml version="1.0"?>
        <packages>
          <package id="firefox">
            <Association ProgId="FirefoxHTML" Identifier=".html" type="file"/>
          </package>
        </packages>
        XML);

        config(['sambaedu.wpkg.deploy_path' => dirname($this->packagesXml)]);
        // Override : on injecte un Reader avec le path explicite.
        $this->app->bind(PackagesXmlAssociationsReader::class, function () {
            return new class($this->packagesXml) extends PackagesXmlAssociationsReader {
                public function __construct(private readonly string $forcedPath) {}
                public function read(?string $path = null): array
                {
                    return parent::read($this->forcedPath);
                }
            };
        });
    }

    protected function tearDown(): void
    {
        if (is_file($this->packagesXml)) @unlink($this->packagesXml);
        Mockery::close();
        parent::tearDown();
    }

    private function seedContext(string $id, array $apcu = []): void
    {
        $apcu = $apcu + [
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'post01'],
            'salle' => 'salle1',
            'list' => ['Profs', 'Parc1'],
            'list_u' => ['Profs'],
            'os' => 'windows',
            'time' => time(),
        ];

        $ctx = AppContext::fromApcuArray($apcu);
        $this->app->bind(AppContextRepository::class, function () use ($id, $ctx) {
            return new class($id, $ctx) implements AppContextRepository {
                public function __construct(private string $valid, private AppContext $ctx) {}
                public function findById(string $id): ?AppContext
                {
                    return $id === $this->valid ? $this->ctx : null;
                }
            };
        });
    }

    private function mockWorkstationPackages(array $packages): void
    {
        $mock = Mockery::mock(WorkstationPackagesResolver::class);
        $mock->shouldReceive('resolve')->andReturn(new Collection($packages));
        $this->app->instance(WorkstationPackagesResolver::class, $mock);
    }

    #[Test]
    public function it_returns_400_for_invalid_id(): void
    {
        $resp = $this->post('/gpo/associations_out.php', [
            'id' => 'INJECTION',
            'list' => '{}',
        ]);
        $resp->assertStatus(400);
        $this->assertSame('', (string) $resp->getContent());
    }

    #[Test]
    public function it_returns_400_for_id_path_traversal(): void
    {
        $resp = $this->post('/gpo/associations_out.php', [
            'id' => '../../etc/passwd',
            'list' => '{}',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_returns_400_when_list_missing(): void
    {
        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_returns_400_when_list_oversized(): void
    {
        // > 10 Ko
        $bigList = json_encode(['file' => array_fill(0, 5000, '.x,Y')]);
        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => $bigList,
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_returns_400_when_list_not_json(): void
    {
        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => 'not-json-just-string',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_returns_400_when_context_expired(): void
    {
        $this->seedContext('ffffffffffffffffffffffffffffffff'); // un autre id
        $this->mockWorkstationPackages([]);

        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => '{}',
        ]);
        $resp->assertStatus(400);
    }

    #[Test]
    public function it_returns_200_with_text_json_content_type_on_nominal_case(): void
    {
        $this->seedContext(self::VALID_ID);
        $this->mockWorkstationPackages(['firefox']);

        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => json_encode([]),
        ]);

        $resp->assertOk();
        $this->assertStringContainsString('text/json', (string) $resp->headers->get('Content-Type'));

        $body = json_decode((string) $resp->getContent(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('result', $body);
    }

    #[Test]
    public function it_intersects_packages_xml_with_workstation_packages_resolver(): void
    {
        $this->seedContext(self::VALID_ID);
        $this->mockWorkstationPackages(['firefox']); // firefox installé

        // Pas de JSON système / local → seules les apps installées passent,
        // mais sans une entrée dans associations.json elles ne matchent pas non plus.
        // Pour qu'on récupère .html → il faut un JSON système. On le crée :
        $sysJson = sys_get_temp_dir() . '/assoc-sys-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($sysJson, json_encode(['firefox' => ['all']]));

        // Override le AssociationsResolver pour utiliser ce path.
        $this->app->bind(AssociationsResolver::class, function ($app) use ($sysJson) {
            return new AssociationsResolver(
                $app->make(PackagesXmlAssociationsReader::class),
                $app->make(WorkstationPackagesResolver::class),
                '/tmp/non-existent.xml',
                $sysJson,
                '/tmp/non-existent-local.json',
            );
        });

        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => json_encode([]),
        ]);

        $resp->assertOk();
        $body = json_decode((string) $resp->getContent(), true);
        $this->assertArrayHasKey('.html', $body['result']);
        $this->assertSame('FirefoxHTML', $body['result']['.html']['ProgId']);

        @unlink($sysJson);
    }

    #[Test]
    public function it_loads_default_xml_associations_when_present(): void
    {
        $this->seedContext(self::VALID_ID);
        $this->mockWorkstationPackages([]); // pas de package WPKG

        $defaultXml = sys_get_temp_dir() . '/default-' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents($defaultXml, <<<'XML'
        <?xml version="1.0"?>
        <root>
          <Association ProgId="WindowsPhotoViewer" Identifier=".jpg" type="file"/>
        </root>
        XML);

        $this->app->bind(AssociationsResolver::class, function ($app) use ($defaultXml) {
            return new AssociationsResolver(
                $app->make(PackagesXmlAssociationsReader::class),
                $app->make(WorkstationPackagesResolver::class),
                $defaultXml,
                '/tmp/no-sys.json',
                '/tmp/no-local.json',
            );
        });

        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => json_encode([]),
        ]);

        $resp->assertOk();
        $body = json_decode((string) $resp->getContent(), true);
        $this->assertArrayHasKey('.jpg', $body['result']);

        @unlink($defaultXml);
    }

    #[Test]
    public function it_returns_empty_result_when_packages_xml_missing(): void
    {
        // Pas de packages.xml → reader retourne [] gracieux.
        $this->app->bind(PackagesXmlAssociationsReader::class, function () {
            return new class extends PackagesXmlAssociationsReader {
                public function read(?string $path = null): array { return []; }
            };
        });
        $this->seedContext(self::VALID_ID);
        $this->mockWorkstationPackages(['firefox']);

        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => json_encode([]),
        ]);

        $resp->assertOk();
        $body = json_decode((string) $resp->getContent(), true);
        $this->assertSame([], $body['result']);
    }

    #[Test]
    public function it_returns_only_diff_associations_when_local_assocs_match_server(): void
    {
        $this->seedContext(self::VALID_ID);
        $this->mockWorkstationPackages(['firefox']);

        $sysJson = sys_get_temp_dir() . '/assoc-sys-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($sysJson, json_encode(['firefox' => ['all']]));

        $this->app->bind(AssociationsResolver::class, function ($app) use ($sysJson) {
            return new AssociationsResolver(
                $app->make(PackagesXmlAssociationsReader::class),
                $app->make(WorkstationPackagesResolver::class),
                '/tmp/no-default.xml', $sysJson, '/tmp/no-local.json',
            );
        });

        // Le poste déclare déjà avoir `.html → FirefoxHTML` → delta vide.
        $localList = json_encode(['file' => ['.html,FirefoxHTML']]);
        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => $localList,
        ]);

        $resp->assertOk();
        $body = json_decode((string) $resp->getContent(), true);
        $this->assertArrayNotHasKey('.html', $body['result']);

        @unlink($sysJson);
    }

    #[Test]
    public function it_does_not_write_assoc_result_json_in_testing(): void
    {
        $this->seedContext(self::VALID_ID);
        $this->mockWorkstationPackages([]);

        $tmpPath = '/tmp/assoc_result.json';
        @unlink($tmpPath);

        $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => json_encode([]),
        ])->assertOk();

        // En testing, le write est skippé.
        $this->assertFileDoesNotExist($tmpPath);
    }

    #[Test]
    public function it_applies_throttle_300_per_minute(): void
    {
        // AC6.4 #10 — middleware `throttle:300,1` côté route. La 301ème requête
        // (sur une même IP testkit) doit retourner 429 Too Many Requests.
        // Pattern iso `NetworkOutEndpointTest::it_applies_throttle_300_per_minute` :
        // smoke test (1 requête passe) + couverture middleware par
        // `AssociationsOutRouteRegistrationTest`. On ne tape pas 301 fois en CI
        // pour ne pas ralentir.
        \Illuminate\Support\Facades\RateLimiter::clear('throttle:300,1|127.0.0.1');

        $this->seedContext(self::VALID_ID);
        $this->mockWorkstationPackages([]);

        $resp = $this->post('/gpo/associations_out.php', [
            'id' => self::VALID_ID,
            'list' => json_encode([]),
        ]);
        $resp->assertOk();

        $this->assertTrue(
            true,
            'throttle middleware existence couverte par AssociationsOutRouteRegistrationTest',
        );
    }

    #[Test]
    public function it_uses_get_method_is_not_routed(): void
    {
        // Route déclarée POST only — GET tombe sur le catchall legacy.
        $resp = $this->get('/gpo/associations_out.php?id=' . self::VALID_ID);
        // Le catchall legacy peut soit retourner 200 (legacy resolved) soit autre chose ;
        // l'important = la route native ne match PAS sur GET.
        $route = \Illuminate\Support\Facades\Route::getRoutes()
            ->match(\Illuminate\Http\Request::create('/gpo/associations_out.php', 'GET'));
        $this->assertSame(
            \App\Http\Controllers\LegacyCatchallController::class . '@handle',
            $route->getActionName(),
        );
    }
}
