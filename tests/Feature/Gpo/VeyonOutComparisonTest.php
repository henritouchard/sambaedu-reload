<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Dto\AppCustomization\AppContext;
use App\Gpo\Services\ReadUserManager;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MakesGpoConfigFakes;
use Tests\TestCase;

/**
 * Test de comparison iso-structure JSON — Story 16.3b AC5.5.
 *
 * Skippé si le fixture VM `tests/Fixtures/Gpo/legacy-veyon-out.json` n'a pas
 * été capturé. La diff exclut `LDAP.BindPassword` (chiffrement OAEP
 * non-déterministe — chaque appel produit un cipher différent même avec le
 * même clear-text et la même clé publique).
 *
 * @group requires-fixture-capture
 *
 * @since Story 16.3b (review fix #7) : diff structurel implémenté (décision
 *        Henri D). Le test n'est plus un fantôme `markTestIncomplete` ; il
 *        compare réellement la structure dès que le fixture est posé en T0.10.
 */
class VeyonOutComparisonTest extends TestCase
{
    use MakesGpoConfigFakes;

    private const FIXTURE_PATH = 'tests/Fixtures/Gpo/legacy-veyon-out.json';
    private const VALID_ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

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
    public function native_json_structure_matches_legacy_fixture_modulo_bind_password(): void
    {
        $this->bindFakeConfig([
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'groups_rdn' => 'ou=Groups',
            'parcs_rdn' => 'ou=Parcs',
            'computers_rdn' => 'ou=computers',
            'ldap_base_dn' => 'dc=example,dc=local',
            'se4ad_name' => 'se4ad',
            'domain' => 'example.local',
        ]);
        $this->seedContext(self::VALID_ID);
        $this->bindReadUser('fixture-compare-pwd-12345678');

        $resp = $this->post('/gpo/veyon_out.php', ['id' => self::VALID_ID]);
        $resp->assertOk();

        $actual = json_decode((string) $resp->getContent(), true);
        $this->assertIsArray($actual, 'sortie native doit être un JSON valide');

        $expectedRaw = file_get_contents(base_path(self::FIXTURE_PATH));
        $this->assertIsString($expectedRaw, 'fixture lisible');
        $expected = json_decode($expectedRaw, true);
        $this->assertIsArray($expected, 'fixture doit être un JSON valide');

        // Exclure BindPassword des deux côtés (chiffrement non-déterministe).
        if (isset($expected['LDAP']['BindPassword'])) {
            unset($expected['LDAP']['BindPassword']);
        }
        if (isset($actual['LDAP']['BindPassword'])) {
            unset($actual['LDAP']['BindPassword']);
        }

        $this->assertEquals(
            $expected,
            $actual,
            'Structure JSON native doit matcher la fixture legacy (modulo BindPassword)'
        );
    }

    private function bindFakeConfig(array $kv): void
    {
        $this->bindFakeSambaEduConfig($kv);
    }

    private function bindReadUser(?string $password): void
    {
        $mock = Mockery::mock(ReadUserManager::class);
        $mock->shouldReceive('ensurePassword')->andReturn($password);
        $this->app->instance(ReadUserManager::class, $mock);
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
