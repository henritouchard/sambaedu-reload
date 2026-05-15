<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Dto\AppCustomization\AppContext;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MakesGpoConfigFakes;
use Tests\TestCase;

/**
 * Test de comparison iso-bytes — Story 16.3b AC5.5.
 *
 * Skippé si le fixture VM `tests/Fixtures/Gpo/legacy-network-out-startup-linux.txt`
 * n'a pas été capturé (cf. T0.10 + D10). Le test n'est pas bloquant en CI mais
 * doit pouvoir tourner manuellement quand Henri capture une référence.
 *
 * @group requires-fixture-capture
 */
class NetworkOutComparisonTest extends TestCase
{
    use MakesGpoConfigFakes;

    private const FIXTURE_PATH = 'tests/Fixtures/Gpo/legacy-network-out-startup-linux.txt';
    private const VALID_ID = 'cccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_file(base_path(self::FIXTURE_PATH))) {
            $this->markTestSkipped('Fixture legacy non capturé (T0.10) : ' . self::FIXTURE_PATH);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function native_output_matches_legacy_fixture(): void
    {
        $this->bindFakeConfig([
            'proxy_type' => 'aucun',
            'domain' => 'example.local',
            'se4fs_name' => 'se4fs',
        ]);
        $this->seedContext(self::VALID_ID);

        $resp = $this->post('/gpo/network_out.php', [
            'action' => 'startup',
            'os' => 'linux',
            'id' => self::VALID_ID,
        ]);
        $resp->assertOk();

        $expected = (string) file_get_contents(base_path(self::FIXTURE_PATH));
        $this->assertSame($expected, (string) $resp->getContent(), 'iso-bytes legacy vs native');
    }

    private function bindFakeConfig(array $kv): void
    {
        $this->bindFakeSambaEduConfig($kv);
    }

    private function seedContext(string $id): void
    {
        $ctx = AppContext::fromApcuArray([
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'post01', 'samaccountname' => 'POST01$'],
            'salle' => 'salle1',
            'list_u' => [],
            'os' => 'linux',
            'time' => 1234567890,
        ]);
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
}
