<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Config\PasswordPolicyConfig;
use App\Config\LdapConfig;
use App\Config\SambaEduConfig;
use App\Gpo\Services\ReadUserManager;
use App\Ldap\AdUserManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `ReadUserManager` — Story 16.3b (refactor post-review 2026-05-12).
 *
 * Suite au correctif post-review (option A complète Henri), `ReadUserManager`
 * dépend désormais d'`AdUserManager` natif (vs shims `create_ad_user` /
 * `usersetpassword` / `user_valid_passwd` qui retournaient toujours false).
 * Les tests mockent `AdUserManager` directement — plus besoin de sous-classes
 * stub qui overrident `createReadUserUnderLock` (méthode redevenue `private`).
 *
 * Le test de création réelle AD n'est pas couvert ici (testé en smoke VM via
 * `docs/qa/domains/gpo.md` §4.6).
 */
class ReadUserManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeConfig(array $kv, ?array $reloadKv = null): SambaEduConfig
    {
        /** @var SambaEduConfig&\Mockery\MockInterface $mock */
        $mock = Mockery::mock(SambaEduConfig::class);

        $state = ['data' => $kv, 'reloadCount' => 0];
        $mock->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default = null) use (&$state): mixed {
                return $state['data'][$key] ?? $default;
            });
        $mock->shouldReceive('has')
            ->andReturnUsing(fn(string $key) => array_key_exists($key, $state['data']));
        $mock->shouldReceive('all')->andReturnUsing(fn() => $state['data']);
        $mock->shouldReceive('reload')->andReturnUsing(function () use (&$state, $reloadKv): void {
            $state['reloadCount']++;
            if ($reloadKv !== null) {
                $state['data'] = $reloadKv;
            }
        });
        $mock->shouldReceive('set')->andReturnUsing(function (string $k, mixed $v) use (&$state): void {
            $state['data'][$k] = $v;
        });

        $ldap = Mockery::mock(LdapConfig::class);
        $ldap->baseDn = $kv['ldap_base_dn'] ?? 'dc=example,dc=local';
        $mock->shouldReceive('ldap')->andReturn($ldap);

        $policy = Mockery::mock(PasswordPolicyConfig::class);
        $policy->minLength = 8;
        $mock->shouldReceive('passwordPolicy')->andReturn($policy);

        return $mock;
    }

    /**
     * @param  array<string, mixed>  $opts  Configuration du mock AdUserManager :
     *  - `exists` : bool  (compte AD pré-existant ?)
     *  - `create` : bool  (create() retourne quoi ?)
     *  - `setPassword` : bool  (setPassword() retourne quoi ?)
     *  - `validatePassword` : bool  (validatePassword() retourne quoi ?)
     */
    private function makeAdUserManager(array $opts = []): AdUserManager
    {
        $mock = Mockery::mock(AdUserManager::class);
        $mock->shouldReceive('exists')->andReturn($opts['exists'] ?? false);
        $mock->shouldReceive('create')->andReturn($opts['create'] ?? true);
        $mock->shouldReceive('setPassword')->andReturn($opts['setPassword'] ?? true);
        $mock->shouldReceive('validatePassword')->andReturn($opts['validatePassword'] ?? true);
        return $mock;
    }

    private function fakeLock(bool $blockReturns = true): void
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andReturn($blockReturns);
        $lock->shouldReceive('release')->andReturn(true);
        Cache::shouldReceive('lock')->andReturn($lock);
    }

    #[Test]
    public function it_returns_existing_password_when_already_set_and_valid(): void
    {
        $config = $this->makeConfig([
            'read_ldap_password' => 'already-set',
            'suffix' => '',
        ]);
        $ad = $this->makeAdUserManager(['validatePassword' => true]);

        $manager = new ReadUserManager($config, $ad);
        $pwd = $manager->ensurePassword();

        $this->assertSame('already-set', $pwd);
    }

    #[Test]
    public function it_resets_password_when_existing_but_invalid(): void
    {
        $config = $this->makeConfig([
            'read_ldap_password' => 'drift-pwd',
            'suffix' => '',
        ]);
        $ad = $this->makeAdUserManager([
            'validatePassword' => false, // drift !
            'setPassword' => true,        // recovery OK
        ]);

        $manager = new ReadUserManager($config, $ad);
        $pwd = $manager->ensurePassword();

        $this->assertSame('drift-pwd', $pwd, 'recovery réussie → on garde le pwd existant');
    }

    #[Test]
    public function it_returns_null_when_drift_recovery_fails(): void
    {
        // Review fix #M1 : échec bruyant si la recovery échoue.
        // (vs comportement legacy qui retournait silencieusement l'ancien pwd
        // qui ne valide plus → Veyon parc-wide cassé sans alerte).
        $config = $this->makeConfig([
            'read_ldap_password' => 'drift-pwd',
            'suffix' => '',
        ]);
        $ad = $this->makeAdUserManager([
            'validatePassword' => false,
            'setPassword' => false, // recovery KO !
        ]);

        $manager = new ReadUserManager($config, $ad);
        $pwd = $manager->ensurePassword();

        $this->assertNull($pwd, 'drift non recoverable → null → controller strip BindPassword (option B)');
    }

    #[Test]
    public function it_creates_password_when_missing_and_lock_acquired(): void
    {
        $config = $this->makeConfig([
            'read_ldap_password' => '',
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'ldap_base_dn' => 'dc=example,dc=local',
        ]);
        $ad = $this->makeAdUserManager([
            'exists' => false,
            'create' => true,
        ]);
        $this->fakeLock(blockReturns: true);

        $manager = new ReadUserManager($config, $ad);
        $pwd = $manager->ensurePassword();

        $this->assertNotNull($pwd);
        $this->assertGreaterThanOrEqual(15, strlen($pwd));
    }

    #[Test]
    public function it_returns_null_when_lock_cannot_be_acquired(): void
    {
        $config = $this->makeConfig([
            'read_ldap_password' => '',
            'suffix' => '',
        ]);
        $ad = $this->makeAdUserManager();
        $this->fakeLock(blockReturns: false);

        $manager = new ReadUserManager($config, $ad);
        $pwd = $manager->ensurePassword();

        $this->assertNull($pwd);
    }

    #[Test]
    public function it_returns_password_from_reload_when_other_worker_created_first(): void
    {
        // Simule un autre worker qui a posé le password pendant qu'on attendait
        // le lock (double-checked locking).
        $config = $this->makeConfig(
            kv: ['read_ldap_password' => '', 'suffix' => ''],
            reloadKv: ['read_ldap_password' => 'set-by-other-worker', 'suffix' => ''],
        );
        $ad = $this->makeAdUserManager();
        $this->fakeLock(blockReturns: true);

        $manager = new ReadUserManager($config, $ad);
        $pwd = $manager->ensurePassword();

        $this->assertSame('set-by-other-worker', $pwd);
    }

    #[Test]
    public function it_returns_null_when_ad_creation_fails(): void
    {
        $config = $this->makeConfig([
            'read_ldap_password' => '',
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'ldap_base_dn' => 'dc=example,dc=local',
        ]);
        $ad = $this->makeAdUserManager([
            'exists' => false,
            'create' => false, // AD KO !
        ]);
        $this->fakeLock(blockReturns: true);

        $manager = new ReadUserManager($config, $ad);
        $pwd = $manager->ensurePassword();

        $this->assertNull($pwd);
    }

    #[Test]
    public function it_treats_existing_ad_user_as_create_success(): void
    {
        // Idempotence anti-race : si un autre worker a créé le compte AD avant
        // qu'on prenne le lock + reload, on considère la création comme
        // réussie (et le password est généré + persisté en config).
        $config = $this->makeConfig([
            'read_ldap_password' => '',
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
        ]);
        $ad = $this->makeAdUserManager([
            'exists' => true,  // compte AD déjà présent !
            'create' => false, // mais create() ne sera pas appelé
        ]);
        $this->fakeLock(blockReturns: true);

        $manager = new ReadUserManager($config, $ad);
        $pwd = $manager->ensurePassword();

        $this->assertNotNull($pwd, 'exists=true → succès idempotent, password généré');
    }
}
