<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\ServiceCredential;
use App\Services\ServiceCredentialTotpReconciler;
use App\Services\ServiceCredentials;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceCredentialTotpReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP'; // secret base32 de test
    private const PERIOD = 21600;

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée → compteur déterministe.
        Carbon::setTestNow(Carbon::createFromTimestamp(1_900_000_000));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function expectedCode(int $counter): string
    {
        return TOTP::create(self::SECRET, self::PERIOD, 'sha256', 6)->at($counter * self::PERIOD);
    }

    #[Test]
    public function it_writes_ad_and_advances_the_applied_counter_on_success(): void
    {
        ServiceCredential::create([
            'name' => 'se4install',
            'secret' => 'base-secret',
            'totp_secret' => self::SECRET,
            'totp_applied_counter' => null,
        ]);

        $current = intdiv(now()->getTimestamp(), self::PERIOD);
        $expected = 'base-secret' . $this->expectedCode($current);

        $this->mock(UserService::class, function (MockInterface $mock) use ($expected): void {
            $mock->shouldReceive('changePasswordInAd')
                ->once()
                ->withArgs(fn (string $login, string $pwd, bool $must): bool =>
                    $login === 'se4install' && $pwd === $expected && $must === false)
                ->andReturn(true);
        });

        $status = app(ServiceCredentialTotpReconciler::class)->reconcile('se4install');

        $this->assertSame('applied', $status);
        $this->assertSame($current, ServiceCredential::firstWhere('name', 'se4install')->totp_applied_counter);
    }

    #[Test]
    public function it_keeps_the_applied_counter_untouched_when_the_ad_write_fails(): void
    {
        // LE test anti-désync : échec AD → compteur reste null → rejouable.
        ServiceCredential::create([
            'name' => 'se4install',
            'secret' => 'base-secret',
            'totp_secret' => self::SECRET,
            'totp_applied_counter' => null,
        ]);

        $this->mock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('changePasswordInAd')->once()->andReturn(false);
        });

        $status = app(ServiceCredentialTotpReconciler::class)->reconcile('se4install');

        $this->assertSame('failed', $status);
        $this->assertNull(ServiceCredential::firstWhere('name', 'se4install')->totp_applied_counter);
    }

    #[Test]
    public function it_does_not_touch_ad_when_already_on_the_current_window(): void
    {
        $current = intdiv(now()->getTimestamp(), self::PERIOD);

        ServiceCredential::create([
            'name' => 'se4install',
            'secret' => 'base-secret',
            'totp_secret' => self::SECRET,
            'totp_applied_counter' => $current,
        ]);

        $this->mock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('changePasswordInAd')->never();
        });

        $status = app(ServiceCredentialTotpReconciler::class)->reconcile('se4install');

        $this->assertSame('up_to_date', $status);
    }

    #[Test]
    public function effective_password_reflects_the_applied_window_not_wall_clock(): void
    {
        $current = intdiv(now()->getTimestamp(), self::PERIOD);
        // Simule un AD resté sur la fenêtre précédente (réconciliation en retard).
        $applied = $current - 1;

        ServiceCredential::create([
            'name' => 'se4install',
            'secret' => 'base-secret',
            'totp_secret' => self::SECRET,
            'totp_applied_counter' => $applied,
        ]);

        $effective = app(ServiceCredentials::class)->effectivePassword('se4install');

        // Le consommateur lit ce que l'AD détient (fenêtre appliquée), pas now().
        $this->assertSame('base-secret' . $this->expectedCode($applied), $effective);
        $this->assertNotSame('base-secret' . $this->expectedCode($current), $effective);
    }

    #[Test]
    public function effective_password_is_base_only_before_first_application(): void
    {
        ServiceCredential::create([
            'name' => 'se4install',
            'secret' => 'base-secret',
            'totp_secret' => self::SECRET,
            'totp_applied_counter' => null,
        ]);

        // TOTP pas encore appliqué à l'AD → l'AD détient la base nue.
        $this->assertSame('base-secret', app(ServiceCredentials::class)->effectivePassword('se4install'));
    }
}
