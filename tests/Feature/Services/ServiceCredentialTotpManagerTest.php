<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\ServiceCredential;
use App\Services\ServiceCredentialTotpManager;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceCredentialTotpManagerTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = 21600;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestamp(1_900_000_000));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function currentCounter(): int
    {
        return intdiv(now()->getTimestamp(), self::PERIOD);
    }

    #[Test]
    public function activate_writes_base_plus_code_to_ad_then_persists(): void
    {
        $captured = null;
        $this->mock(UserService::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('changePasswordInAd')
                ->once()
                ->withArgs(function (string $login, string $pwd, bool $must) use (&$captured): bool {
                    $captured = $pwd;

                    return $login === 'se4install' && $must === false;
                })
                ->andReturn(true);
        });

        $ok = app(ServiceCredentialTotpManager::class)->activate('se4install');

        $this->assertTrue($ok);

        $rec = ServiceCredential::firstWhere('name', 'se4install');
        $this->assertNotNull($rec->totp_secret);
        $this->assertSame($this->currentCounter(), $rec->totp_applied_counter);

        // Le mdp posé dans l'AD = base + code(fenêtre courante) du secret persisté.
        $expected = $rec->secret . TOTP::create($rec->totp_secret, self::PERIOD, 'sha256', 6)
            ->at($this->currentCounter() * self::PERIOD);
        $this->assertSame($expected, $captured);
    }

    #[Test]
    public function activate_persists_nothing_when_the_ad_write_fails(): void
    {
        $this->mock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('changePasswordInAd')->once()->andReturn(false);
        });

        $ok = app(ServiceCredentialTotpManager::class)->activate('se4install');

        $this->assertFalse($ok);
        // DB inchangée : aucun row créé → pas d'état en avance sur l'AD.
        $this->assertNull(ServiceCredential::firstWhere('name', 'se4install'));
    }

    #[Test]
    public function activate_imports_an_existing_totp_secret(): void
    {
        $imported = 'MFRGGZDFMZTWQ2LKNNWG23TP'; // base32

        $this->mock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('changePasswordInAd')->once()->andReturn(true);
        });

        $ok = app(ServiceCredentialTotpManager::class)->activate('se4install', $imported);

        $this->assertTrue($ok);
        $this->assertSame($imported, ServiceCredential::firstWhere('name', 'se4install')->totp_secret);
    }

    private function writeHashesFile(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hashes_');
        file_put_contents($path, json_encode($data));

        return $path;
    }

    #[Test]
    public function import_is_a_noop_when_the_hashes_file_is_absent(): void
    {
        $this->mock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('changePasswordInAd');
        });

        $stats = app(ServiceCredentialTotpManager::class)
            ->importSe4installFromLegacyHashes('/nonexistent/hashes');

        $this->assertFalse($stats['found']);
        $this->assertFalse($stats['imported']);
        $this->assertNull(ServiceCredential::firstWhere('name', 'se4install'));
    }

    #[Test]
    public function import_adopts_the_legacy_token_without_writing_ad(): void
    {
        config(['sambaedu.se4install_passwd' => 'legacy-base']);
        $path = $this->writeHashesFile([
            'se4install' => ['token' => 'JBSWY3DPEHPK3PXP', 'hash' => 'whatever'],
        ]);

        // Adoption non-destructive : AUCUNE écriture AD.
        $this->mock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('changePasswordInAd');
        });

        $stats = app(ServiceCredentialTotpManager::class)
            ->importSe4installFromLegacyHashes($path);

        @unlink($path);

        $this->assertTrue($stats['imported']);
        $rec = ServiceCredential::firstWhere('name', 'se4install');
        $this->assertSame('legacy-base', $rec->secret);
        $this->assertSame('JBSWY3DPEHPK3PXP', $rec->totp_secret);
        $this->assertSame($this->currentCounter(), $rec->totp_applied_counter);
    }

    #[Test]
    public function import_is_idempotent_when_totp_already_managed(): void
    {
        ServiceCredential::create([
            'name' => 'se4install',
            'secret' => 'existing-base',
            'totp_secret' => 'EXISTINGSECRET22',
            'totp_applied_counter' => $this->currentCounter(),
        ]);

        $path = $this->writeHashesFile([
            'se4install' => ['token' => 'JBSWY3DPEHPK3PXP', 'hash' => 'whatever'],
        ]);

        $this->mock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('changePasswordInAd');
        });

        $stats = app(ServiceCredentialTotpManager::class)
            ->importSe4installFromLegacyHashes($path);

        @unlink($path);

        $this->assertTrue($stats['already_imported']);
        $this->assertFalse($stats['imported']);
        // Pas d'écrasement du secret existant.
        $this->assertSame('EXISTINGSECRET22', ServiceCredential::firstWhere('name', 'se4install')->totp_secret);
    }

    #[Test]
    public function deactivate_restores_base_in_ad_and_clears_totp(): void
    {
        ServiceCredential::create([
            'name' => 'se4install',
            'secret' => 'base-secret',
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'totp_applied_counter' => $this->currentCounter(),
        ]);

        $this->mock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('changePasswordInAd')
                ->once()
                ->withArgs(fn (string $login, string $pwd, bool $must): bool =>
                    $login === 'se4install' && $pwd === 'base-secret' && $must === false)
                ->andReturn(true);
        });

        $ok = app(ServiceCredentialTotpManager::class)->deactivate('se4install');

        $this->assertTrue($ok);
        $rec = ServiceCredential::firstWhere('name', 'se4install');
        $this->assertNull($rec->totp_secret);
        $this->assertNull($rec->totp_applied_counter);
        $this->assertSame('base-secret', $rec->secret);
    }
}
