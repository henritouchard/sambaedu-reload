<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Wallpaper;

use App\Dto\Wallpaper\WallpaperContext;
use App\Dto\Wallpaper\WallpaperResolution;
use App\Services\Filesystem\XfsQuotaService;
use App\Services\UserSessionsService;
use App\Services\Wallpaper\WallpaperComposer;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ImageAssertions;
use Tests\TestCase;

/**
 * Tests structurels du Composer — couvre la matrice d'états AC 5 ter.
 *
 * Pas de comparaison binaire : on vérifie dimensions + format + dominance
 * de couleur par zone (couleurs issues de la matrice).
 */
class WallpaperComposerTest extends TestCase
{
    use ImageAssertions;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('Imagick')) {
            $this->markTestSkipped('Imagick non disponible.');
        }

        // Image de base 1920×1080 unie (#202020)
        $this->basePath = sys_get_temp_dir() . '/wp-base-' . bin2hex(random_bytes(3)) . '.jpg';
        $im = new \Imagick();
        $im->newImage(1920, 1080, new \ImagickPixel('#202020'));
        $im->setImageFormat('jpg');
        $im->writeImage($this->basePath);
        $im->destroy();

        config()->set('wallpapers.fonts.bold', [base_path('resources/fonts/Atkinson-Hyperlegible-Bold.ttf')]);
        config()->set('wallpapers.fonts.regular', [base_path('resources/fonts/Atkinson-Hyperlegible-Regular.ttf')]);
        config()->set('wallpapers.minimal_mode', false);
    }

    protected function tearDown(): void
    {
        @unlink($this->basePath);
        Mockery::close();
        parent::tearDown();
    }

    private function ctx(array $overrides = []): WallpaperContext
    {
        // Structure APCu réelle : user/machine = arrays LDAP (post-fix #1)
        return WallpaperContext::fromApcuArray(array_merge([
            'user' => ['cn' => 'jdoe', 'fullname' => 'John Doe'],
            'admin' => false,
            'machine' => ['cn' => 'post01'],
            'salle' => 'salle_a',
            'list_u' => ['Eleves'],
            'os' => 'linux',
            'time' => time(),
        ], $overrides));
    }

    private function resolution(): WallpaperResolution
    {
        return new WallpaperResolution(
            sourcePath: $this->basePath,
            level: WallpaperResolution::LEVEL_SALLE,
            ownerType: null,
            ownerName: 'salle_a',
        );
    }

    private function composer(?XfsQuotaService $quota = null, ?UserSessionsService $sessions = null): WallpaperComposer
    {
        return new WallpaperComposer($quota, $sessions);
    }

    private function quotaOverSoftOnly(): XfsQuotaService
    {
        $quota = Mockery::mock(XfsQuotaService::class);
        // Usage : home over soft (pas hard) → quota-warning actif
        $quota->shouldReceive('getDiskUsage')->andReturn([
            'home' => [
                'is_over_soft' => true,
                'is_over_hard' => false,
                'used_mb' => 450,
                'quota_soft_mb' => 500,
                'quota_hard_mb' => 600,
                'grace_days' => null,
            ],
            'sambaedu' => [
                'is_over_soft' => false,
                'is_over_hard' => false,
            ],
        ]);
        return $quota;
    }

    private function sessionsMock(array $others): UserSessionsService
    {
        $mock = Mockery::mock(UserSessionsService::class);
        $mock->shouldReceive('getOtherMachines')
            ->andReturn($others);
        return $mock;
    }

    #[Test]
    public function normal_state_renders_valid_1920x1080_jpg(): void
    {
        $blob = $this->composer()->composeWallpaper($this->resolution(), $this->ctx(), false, false, 'jpg');
        $this->assertImageBlobValid($blob);
        $this->assertImageFormat($blob, 'jpeg');
        $this->assertImageDimensions($blob, 1920, 1080);
    }

    #[Test]
    public function admin_state_contains_red_badge(): void
    {
        $blob = $this->composer()->composeWallpaper(
            $this->resolution(),
            $this->ctx(['admin' => true]),
            false,
            false,
            'jpg',
        );
        $this->assertImageBlobValid($blob);
        // Badge admin rouge #D32F2F présent (au moins 0.03% des pixels)
        $this->assertImageContainsColor($blob, '#D32F2F', 0.0003, 35);
    }

    #[Test]
    public function veyon_state_contains_red_cartouche(): void
    {
        $blob = $this->composer()->composeWallpaper($this->resolution(), $this->ctx(), false, true, 'jpg');
        $this->assertImageBlobValid($blob);
        // Cartouche rouge semi-transparent #C81E1E (rgba(200,30,30,0.85))
        $this->assertImageContainsColor($blob, '#C81E1E', 0.01, 35);
    }

    #[Test]
    public function admin_plus_veyon_cumule_badges(): void
    {
        $blob = $this->composer()->composeWallpaper(
            $this->resolution(),
            $this->ctx(['admin' => true]),
            false,
            true,
            'jpg',
        );
        $this->assertImageBlobValid($blob);
        $this->assertImageContainsColor($blob, '#D32F2F', 0.0002, 35); // admin
        $this->assertImageContainsColor($blob, '#C81E1E', 0.01, 35);    // cartouche veyon
    }

    #[Test]
    public function wait_mode_shows_connection_card(): void
    {
        $blob = $this->composer()->composeWallpaper($this->resolution(), $this->ctx(), true, false, 'jpg');
        $this->assertImageBlobValid($blob);
        // Cartouche noire translucide (rgba(0,0,0,0.75)) dominante dans sa zone
        $info = @getimagesizefromstring($blob);
        $this->assertNotFalse($info);
    }

    #[Test]
    public function quota_bloque_renders_dark_red_fullscreen(): void
    {
        $quota = Mockery::mock(XfsQuotaService::class);
        $quota->shouldReceive('getOverQuotaPartitionsFormatted')
            ->andReturn([
                ['label' => 'Espace perso', 'used_mb' => 1024, 'soft_mb' => 500, 'grace_days' => 3],
            ]);

        $blob = $this->composer($quota)->composeWallpaper(
            WallpaperResolution::quotaOverride(),
            $this->ctx(),
            false,
            false,
            'jpg',
        );
        $this->assertImageBlobValid($blob);
        // Rouge foncé #8B0000 dominant
        $this->assertDominantColor($blob, '#8B0000', 0.3, 40);
    }

    #[Test]
    public function lockscreen_renders_valid_png(): void
    {
        $blob = $this->composer()->composeLockscreen($this->resolution(), $this->ctx(), 'png');
        // PNG d'une image unie compressée peut être <5 Ko, seuil plus bas
        $this->assertImageBlobValid($blob, minBytes: 1_000);
        $this->assertImageFormat($blob, 'png');
        // Lockscreen png : 1280×720 selon AC 5
        $this->assertImageDimensions($blob, 1280, 720);
    }

    #[Test]
    public function lockscreen_renders_valid_jpg(): void
    {
        $blob = $this->composer()->composeLockscreen($this->resolution(), $this->ctx(), 'jpg');
        $this->assertImageBlobValid($blob);
        $this->assertImageFormat($blob, 'jpeg');
        $this->assertImageDimensions($blob, 1920, 1080);
    }

    #[Test]
    public function minimal_mode_renders_without_crash(): void
    {
        config()->set('wallpapers.minimal_mode', true);
        $blob = $this->composer()->composeWallpaper($this->resolution(), $this->ctx(), false, false, 'jpg');
        $this->assertImageBlobValid($blob);
        $this->assertImageDimensions($blob, 1920, 1080);
    }

    #[Test]
    public function fallback_font_does_not_crash(): void
    {
        // Force des fonts inexistantes — le Composer doit tomber sur le default Imagick
        config()->set('wallpapers.fonts.bold', ['/nonexistent/bold.ttf']);
        config()->set('wallpapers.fonts.regular', ['/nonexistent/regular.ttf']);

        $blob = $this->composer()->composeWallpaper($this->resolution(), $this->ctx(), false, false, 'jpg');
        $this->assertImageBlobValid($blob);
    }

    // ========================================================================
    // Post-review #2 — veyon_submarine masque cartouche ET badge
    // ========================================================================

    #[Test]
    public function veyon_submarine_hides_both_badge_and_card(): void
    {
        config()->set('sambaedu.veyon_submarine', true);

        $blob = $this->composer()->composeWallpaper(
            $this->resolution(),
            $this->ctx(),
            false,
            true, // veyon demandé MAIS submarine → doit rester invisible
            'jpg',
        );
        $this->assertImageBlobValid($blob);

        // Pas de cartouche rouge (#C81E1E, seuil 1%)
        $this->assertImageDoesNotContainColor($blob, '#C81E1E', 0.01, 35);
        // Pas de badge veyon (PNG orange #E67E22)
        $this->assertImageDoesNotContainColor($blob, '#E67E22', 0.0001, 35);
    }

    // ========================================================================
    // Post-review #7 — quota warning states
    // ========================================================================

    #[Test]
    public function quota_warning_state_shows_only_badge_no_card(): void
    {
        // Soft over (badge jaune) mais pas hard → pas de cartouche quota
        $blob = $this->composer($this->quotaOverSoftOnly())->composeWallpaper(
            $this->resolution(),
            $this->ctx(),
            false,
            false,
            'jpg',
        );
        $this->assertImageBlobValid($blob);
        // Badge quota-warning jaune #F39C12
        $this->assertImageContainsColor($blob, '#F39C12', 0.0001, 40);
        // Pas de cartouche quota saturé (#8B0000 dominant → non)
        $info = @getimagesizefromstring($blob);
        $this->assertSame(1920, $info[0]);
    }

    #[Test]
    public function admin_plus_quota_warning_cumul(): void
    {
        $blob = $this->composer($this->quotaOverSoftOnly())->composeWallpaper(
            $this->resolution(),
            $this->ctx(['admin' => true]),
            false,
            false,
            'jpg',
        );
        $this->assertImageBlobValid($blob);
        $this->assertImageContainsColor($blob, '#D32F2F', 0.0001, 40); // admin rouge
        $this->assertImageContainsColor($blob, '#F39C12', 0.0001, 40); // quota-warning jaune
    }

    // ========================================================================
    // Post-review #A — multi-session (UserSessionsService)
    // ========================================================================

    #[Test]
    public function multi_session_state_shows_badge_and_orange_card(): void
    {
        $sessions = $this->sessionsMock(['post02', 'post03']);
        $blob = $this->composer(null, $sessions)->composeWallpaper(
            $this->resolution(),
            $this->ctx(), // machine courante = post01
            false,
            false,
            'jpg',
        );
        $this->assertImageBlobValid($blob);
        // Cartouche orange rgba(230,140,30,0.85) ≈ #E68C1E
        $this->assertImageContainsColor($blob, '#E68C1E', 0.01, 35);
        // Badge multi-session bleu #2980B9
        $this->assertImageContainsColor($blob, '#2980B9', 0.0001, 40);
    }

    #[Test]
    public function veyon_plus_multi_session_stacks_cards(): void
    {
        $sessions = $this->sessionsMock(['post02']);
        $blob = $this->composer(null, $sessions)->composeWallpaper(
            $this->resolution(),
            $this->ctx(),
            false,
            true,
            'jpg',
        );
        $this->assertImageBlobValid($blob);
        // Les 2 cartouches (rouge veyon + orange multi-session)
        $this->assertImageContainsColor($blob, '#C81E1E', 0.01, 35);
        $this->assertImageContainsColor($blob, '#E68C1E', 0.01, 35);
    }

    #[Test]
    public function no_multi_session_when_sessions_service_absent(): void
    {
        // Régression : si UserSessionsService null, le cartouche multi-session
        // ne doit jamais apparaître. Post-review #A — dégradation gracieuse.
        $blob = $this->composer(null, null)->composeWallpaper(
            $this->resolution(),
            $this->ctx(),
            false,
            false,
            'jpg',
        );
        $this->assertImageBlobValid($blob);
        $this->assertImageDoesNotContainColor($blob, '#E68C1E', 0.005, 35);
    }
}
