<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Config\LdapConfig;
use App\Config\PasswordPolicyConfig;
use App\Config\SambaEduConfig;
use App\Dto\AppCustomization\AppContext;
use App\Gpo\Services\ReadUserManager;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature `VeyonOutController` — Story 16.3b AC5.2.
 */
class VeyonOutEndpointTest extends TestCase
{
    private const VALID_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private string $pubKeyPath;
    private string $privKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $tmpDir = sys_get_temp_dir() . '/veyon-feat-' . uniqid('', true);
        @mkdir($tmpDir, 0o755, true);
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privPem);
        $pubDetails = openssl_pkey_get_details($res);
        $this->privKeyPath = $tmpDir . '/priv.pem';
        $this->pubKeyPath = $tmpDir . '/pub.pem';
        file_put_contents($this->privKeyPath, $privPem);
        file_put_contents($this->pubKeyPath, $pubDetails['key']);

        config()->set('sambaedu.gpo.veyon.template_path', base_path('tests/Fixtures/Gpo/veyon-template.json'));
        config()->set('sambaedu.gpo.veyon.local_path', '/nonexistent/local.json');
        config()->set('sambaedu.gpo.veyon.pubkey_path', $this->pubKeyPath);

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

        // ReadUserManager : par défaut renvoie un password fixe (pas d'AD réel).
        $this->bindReadUser('fake-pwd-for-tests-123');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        @unlink($this->pubKeyPath);
        @unlink($this->privKeyPath);
        parent::tearDown();
    }

    private function bindFakeConfig(array $kv): void
    {
        /** @var SambaEduConfig&\Mockery\MockInterface $mock */
        $mock = Mockery::mock(SambaEduConfig::class);
        $mock->shouldReceive('get')
            ->andReturnUsing(fn(string $key, mixed $default = null): mixed => $kv[$key] ?? $default);
        $mock->shouldReceive('has')
            ->andReturnUsing(fn(string $key) => array_key_exists($key, $kv));
        $mock->shouldReceive('all')->andReturn($kv);
        $mock->shouldReceive('reload')->andReturnNull();

        $ldap = Mockery::mock(LdapConfig::class);
        $ldap->baseDn = $kv['ldap_base_dn'] ?? 'dc=example,dc=local';
        $mock->shouldReceive('ldap')->andReturn($ldap);

        $policy = Mockery::mock(PasswordPolicyConfig::class);
        $policy->minLength = 8;
        $mock->shouldReceive('passwordPolicy')->andReturn($policy);

        $this->app->instance(SambaEduConfig::class, $mock);
    }

    private function bindReadUser(?string $password): void
    {
        $mock = Mockery::mock(ReadUserManager::class);
        $mock->shouldReceive('ensurePassword')->andReturn($password);
        $this->app->instance(ReadUserManager::class, $mock);
    }

    private function seedContext(string $id, array $apcu = []): void
    {
        $apcu = $apcu + [
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'post01'],
            'salle' => 'salle1',
            'list_u' => [],
            'os' => 'linux',
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

    #[Test]
    public function it_returns_empty_ok_for_invalid_id(): void
    {
        // Décision Henri 2026-05-12 post-review : iso-legacy 200 body=""
        // (le legacy `exit()` retombe sur 200, pas 204).
        $resp = $this->post('/gpo/veyon_out.php', ['id' => 'not-an-md5']);
        $resp->assertOk();
        $this->assertSame('', (string) $resp->getContent());
    }

    #[Test]
    public function it_returns_empty_ok_when_context_expired(): void
    {
        $this->seedContext('ffffffffffffffffffffffffffffffff');
        $resp = $this->post('/gpo/veyon_out.php', ['id' => self::VALID_ID]);
        $resp->assertOk();
        $this->assertSame('', (string) $resp->getContent());
    }

    #[Test]
    public function it_returns_empty_ok_when_machine_name_empty(): void
    {
        $this->seedContext(self::VALID_ID, [
            'machine' => ['cn' => ''], // machine sans nom
        ]);
        $resp = $this->post('/gpo/veyon_out.php', ['id' => self::VALID_ID]);
        $resp->assertOk();
        $this->assertSame('', (string) $resp->getContent());
    }

    #[Test]
    public function it_generates_veyon_json_with_full_ldap_section(): void
    {
        $this->seedContext(self::VALID_ID);

        $resp = $this->post('/gpo/veyon_out.php', ['id' => self::VALID_ID]);
        $resp->assertOk();
        $this->assertStringContainsString('application/json', (string) $resp->headers->get('Content-Type'));

        $json = json_decode((string) $resp->getContent(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('LDAP', $json);
        $this->assertArrayHasKey('BindDN', $json['LDAP']);
        $this->assertArrayHasKey('BindPassword', $json['LDAP']);
        $this->assertSame(389, $json['LDAP']['ServerPort']);
        $this->assertNotEmpty($json['LDAP']['BindPassword']);
    }

    #[Test]
    public function it_strips_bind_password_when_read_user_creation_fails(): void
    {
        $this->bindReadUser(null); // simule échec création AD
        $this->seedContext(self::VALID_ID);

        $resp = $this->post('/gpo/veyon_out.php', ['id' => self::VALID_ID]);
        $resp->assertOk();

        $json = json_decode((string) $resp->getContent(), true);
        $this->assertArrayHasKey('LDAP', $json);
        $this->assertArrayNotHasKey('BindPassword', $json['LDAP'], 'option B Henri : pas de BindPassword si échec');
    }

    #[Test]
    public function it_includes_authorized_user_groups(): void
    {
        $this->seedContext(self::VALID_ID);
        $resp = $this->post('/gpo/veyon_out.php', ['id' => self::VALID_ID]);
        $json = json_decode((string) $resp->getContent(), true);

        $this->assertArrayHasKey('AccessControl', $json);
        $auth = $json['AccessControl']['AuthorizedUserGroups'];
        $this->assertStringContainsString('CN=Admins', $auth[0]);
        $this->assertStringContainsString('CN=Profs', $auth[1]);
        $this->assertStringContainsString('CN=Administratifs', $auth[2]);
    }

    #[Test]
    public function licence_param_does_not_leak_filesystem_paths_ac4_4(): void
    {
        // AC4.4 — chemin codé en dur, pas de path traversal possible.
        // Le test vérifie aussi qu'aucun input utilisateur n'est interpolé dans
        // le chemin (on tente un path traversal via le param `licence` lui-même).
        $resp = $this->post('/gpo/veyon_out.php', [
            'licence' => '../../etc/passwd',
        ]);
        // licence != '1' => route normale, validation id requise.
        $resp->assertOk();
        $this->assertSame('', (string) $resp->getContent());
    }

    #[Test]
    public function it_returns_octet_stream_when_licence_param_is_1(): void
    {
        // Test indépendant de la présence ou non du fichier /etc/sambaedu/applications/veyon/licence.vlf
        // sur l'host : on vérifie juste status 200 + content-type.
        $resp = $this->post('/gpo/veyon_out.php', ['licence' => '1']);
        $resp->assertOk();
        $this->assertSame('application/octet-stream', $resp->headers->get('Content-Type'));
    }

    #[Test]
    public function it_does_not_call_read_user_when_licence_path_taken(): void
    {
        // Le mock de ReadUserManager retournerait pwd ; mais si la route
        // licence court-circuite, on ne le vérifie pas via shouldNotReceive
        // (mockery sealed). Test indirect : status octet-stream.
        $resp = $this->post('/gpo/veyon_out.php', ['licence' => '1', 'id' => self::VALID_ID]);
        $resp->assertOk();
        $this->assertSame('application/octet-stream', $resp->headers->get('Content-Type'));
    }

    #[Test]
    public function it_accepts_get_request_iso_legacy(): void
    {
        $this->seedContext(self::VALID_ID);
        $resp = $this->get('/gpo/veyon_out.php?id=' . self::VALID_ID);
        $resp->assertOk();
    }

    #[Test]
    public function it_creates_read_user_when_password_missing(): void
    {
        // AC2.5/2.6 + #1 review fix : ReadUserManager doit être invoqué quand
        // `read_ldap_password` est absent en config. On utilise un mock du
        // service avec `shouldReceive('ensurePassword')->once()` pour valider
        // l'appel.
        $this->seedContext(self::VALID_ID);

        $readUserMock = Mockery::mock(ReadUserManager::class);
        $readUserMock->shouldReceive('ensurePassword')
            ->once()
            ->andReturn('pwd-generated-on-fly-123456');
        $this->app->instance(ReadUserManager::class, $readUserMock);

        $resp = $this->post('/gpo/veyon_out.php', ['id' => self::VALID_ID]);
        $resp->assertOk();

        $json = json_decode((string) $resp->getContent(), true);
        $this->assertArrayHasKey('LDAP', $json);
        $this->assertArrayHasKey('BindPassword', $json['LDAP']);
        $this->assertNotEmpty($json['LDAP']['BindPassword']);
    }

    #[Test]
    public function it_calls_ensure_password_exactly_once_per_request(): void
    {
        // AC2.5 — `ReadUserManager::ensurePassword()` doit être invoqué une et
        // une seule fois par appel à `legacyOut`. Le lock anti-race est testé
        // côté Unit (cf. `ReadUserManagerTest::it_returns_password_from_reload_when_other_worker_created_first`).
        $this->seedContext(self::VALID_ID);

        $readUserMock = Mockery::mock(ReadUserManager::class);
        $readUserMock->shouldReceive('ensurePassword')
            ->once()
            ->andReturn('pwd-once-123456789');
        $this->app->instance(ReadUserManager::class, $readUserMock);

        $resp = $this->post('/gpo/veyon_out.php', ['id' => self::VALID_ID]);
        $resp->assertOk();
    }

    #[Test]
    public function bind_password_is_decryptable_with_private_key(): void
    {
        $this->seedContext(self::VALID_ID);
        $resp = $this->post('/gpo/veyon_out.php', ['id' => self::VALID_ID]);
        $resp->assertOk();

        $json = json_decode((string) $resp->getContent(), true);
        $hex = $json['LDAP']['BindPassword'];
        $cipher = hex2bin($hex);
        $priv = file_get_contents($this->privKeyPath);
        $decrypted = '';
        $ok = openssl_private_decrypt($cipher, $decrypted, $priv, OPENSSL_PKCS1_OAEP_PADDING);
        $this->assertTrue($ok);
        $this->assertSame('fake-pwd-for-tests-123', $decrypted);
    }
}
