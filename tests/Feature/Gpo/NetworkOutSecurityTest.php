<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Config\LdapConfig;
use App\Config\PasswordPolicyConfig;
use App\Config\SambaEduConfig;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests sécurité `NetworkOutController` — Story 16.3b AC4.2/4.3.
 *
 * On vérifie que les payloads d'attaque classiques (path traversal, SQLi,
 * shell injection) retournent 204 SANS aucun accès `AppContextRepository`
 * (= preuve qu'aucun appel APCu/exec/LDAP n'est tenté).
 */
class NetworkOutSecurityTest extends TestCase
{
    /** @var array<int,string> */
    public static array $resolveCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Tracker spy : compte les appels findById
        self::$resolveCalls = [];

        $this->app->bind(AppContextRepository::class, function () {
            return new class implements AppContextRepository {
                public function findById(string $id): ?\App\Dto\AppCustomization\AppContext
                {
                    NetworkOutSecurityTest::$resolveCalls[] = $id;
                    return null;
                }
            };
        });

        // Config minimale.
        /** @var SambaEduConfig&\Mockery\MockInterface $mock */
        $mock = Mockery::mock(SambaEduConfig::class);
        $mock->shouldReceive('get')->andReturnUsing(fn($k, $d = null) => $d);
        $mock->shouldReceive('has')->andReturn(false);
        $mock->shouldReceive('all')->andReturn([]);
        $mock->shouldReceive('reload')->andReturnNull();
        $ldap = Mockery::mock(LdapConfig::class);
        $ldap->baseDn = 'dc=x';
        $mock->shouldReceive('ldap')->andReturn($ldap);
        $policy = Mockery::mock(PasswordPolicyConfig::class);
        $policy->minLength = 8;
        $mock->shouldReceive('passwordPolicy')->andReturn($policy);
        $this->app->instance(SambaEduConfig::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        self::$resolveCalls = [];
        parent::tearDown();
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function maliciousIdsProvider(): array
    {
        return [
            'sql-injection' => ["' OR 1=1 --"],
            'path-traversal' => ['../../etc/passwd'],
            'plain-injection' => ['INJECTION'],
            'too-short' => ['abc'],
            'too-long' => [str_repeat('a', 64)],
            'non-hex' => [str_repeat('z', 32)],
            'shell-injection' => ['; rm -rf /'],
        ];
    }

    /**
     * @dataProvider maliciousIdsProvider
     */
    #[Test]
    public function it_rejects_malicious_id_with_empty_ok_and_no_repository_lookup(string $maliciousId): void
    {
        // Décision Henri 2026-05-12 post-review : iso-legacy 200 body=""
        // au lieu de 204 sur tous les paths dégénérés (incl. id malformé).
        $resp = $this->post('/gpo/network_out.php', [
            'action' => 'startup',
            'os' => 'linux',
            'id' => $maliciousId,
        ]);
        $resp->assertOk();
        $this->assertSame('', (string) $resp->getContent());
        $this->assertSame([], self::$resolveCalls, "id=$maliciousId ne doit déclencher AUCUN findById");
    }

    #[Test]
    public function veyon_out_also_rejects_malicious_id_without_lookup(): void
    {
        // Veyon utilise le même garde validation md5.
        $resp = $this->post('/gpo/veyon_out.php', ['id' => '../../etc/passwd']);
        $resp->assertOk();
        $this->assertSame('', (string) $resp->getContent());
        $this->assertSame([], self::$resolveCalls);
    }
}
