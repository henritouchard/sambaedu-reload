<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Overlay;

use App\Dto\Overlay\OverlayAlert;
use App\Dto\Wallpaper\WallpaperContext;
use App\Services\Filesystem\XfsQuotaService;
use App\Services\Overlay\OverlaySignalBuilder;
use App\Services\UserSessionsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit — dérivation des signaux overlay (multi-session, quota).
 *
 * Les collaborateurs sont mockés ; on vérifie le mapping signal + sévérité +
 * la dégradation silencieuse (services absents / login vide).
 */
class OverlaySignalBuilderTest extends TestCase
{
    private function context(string $login = 'jdoe'): WallpaperContext
    {
        return new WallpaperContext(
            userLogin: $login,
            userFullname: $login,
            userIsAdmin: false,
            machineName: 'post01',
            salleName: 'salle-test',
            groupsUser: [],
            mainUserType: null,
            os: 'linux',
            timestamp: 0,
            raw: [],
        );
    }

    /**
     * @param array{home:array,sambaedu:array} $usage
     */
    private function quotaMock(array $usage, array $partitions = []): XfsQuotaService
    {
        $quota = $this->createMock(XfsQuotaService::class);
        $quota->method('getDiskUsage')->willReturn($usage);
        $quota->method('getOverQuotaPartitionsFormatted')->willReturn($partitions);

        return $quota;
    }

    private function noQuota(): array
    {
        return [
            'home' => ['is_over_soft' => false, 'is_over_hard' => false],
            'sambaedu' => ['is_over_soft' => false, 'is_over_hard' => false],
        ];
    }

    #[Test]
    public function returns_empty_when_login_is_empty(): void
    {
        $builder = new OverlaySignalBuilder(null, null);

        self::assertSame([], $builder->buildDerived($this->context('')));
    }

    #[Test]
    public function returns_empty_when_services_absent(): void
    {
        $builder = new OverlaySignalBuilder(null, null);

        self::assertSame([], $builder->buildDerived($this->context()));
    }

    #[Test]
    public function emits_multi_session_warning(): void
    {
        $sessions = $this->createMock(UserSessionsService::class);
        $sessions->method('getOtherMachines')->willReturn(['PC07', 'PC12']);

        $builder = new OverlaySignalBuilder($sessions, $this->quotaMock($this->noQuota()));
        $alerts = $builder->buildDerived($this->context());

        self::assertCount(1, $alerts);
        $alert = $alerts[0];
        self::assertSame('multi_session', $alert->id);
        self::assertSame(OverlayAlert::SOURCE_DERIVED, $alert->source);
        self::assertSame('session', $alert->kind);
        self::assertSame(OverlayAlert::SEVERITY_WARNING, $alert->severity);
        self::assertSame(['PC07', 'PC12'], $alert->meta['machines']);
    }

    #[Test]
    public function no_multi_session_when_no_other_machine(): void
    {
        $sessions = $this->createMock(UserSessionsService::class);
        $sessions->method('getOtherMachines')->willReturn([]);

        $builder = new OverlaySignalBuilder($sessions, $this->quotaMock($this->noQuota()));

        self::assertSame([], $builder->buildDerived($this->context()));
    }

    #[Test]
    public function emits_quota_warning_on_soft_over(): void
    {
        $usage = [
            'home' => ['is_over_soft' => true, 'is_over_hard' => false],
            'sambaedu' => ['is_over_soft' => false, 'is_over_hard' => false],
        ];
        $builder = new OverlaySignalBuilder(null, $this->quotaMock($usage));
        $alerts = $builder->buildDerived($this->context());

        self::assertCount(1, $alerts);
        self::assertSame('quota', $alerts[0]->id);
        self::assertSame(OverlayAlert::SEVERITY_WARNING, $alerts[0]->severity);
    }

    #[Test]
    public function emits_quota_critical_on_hard_over_with_partitions(): void
    {
        $usage = [
            'home' => ['is_over_soft' => true, 'is_over_hard' => true],
            'sambaedu' => ['is_over_soft' => false, 'is_over_hard' => false],
        ];
        $partitions = [['label' => 'home', 'used_mb' => 4800, 'soft_mb' => 5000, 'grace_days' => 3]];

        $builder = new OverlaySignalBuilder(null, $this->quotaMock($usage, $partitions));
        $alerts = $builder->buildDerived($this->context());

        self::assertCount(1, $alerts);
        self::assertSame(OverlayAlert::SEVERITY_CRITICAL, $alerts[0]->severity);
        self::assertSame($partitions, $alerts[0]->meta['partitions']);
    }

    #[Test]
    public function no_quota_alert_when_under_quota(): void
    {
        $builder = new OverlaySignalBuilder(null, $this->quotaMock($this->noQuota()));

        self::assertSame([], $builder->buildDerived($this->context()));
    }
}
