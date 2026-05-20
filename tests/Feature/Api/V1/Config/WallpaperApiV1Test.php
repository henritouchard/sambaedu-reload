<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Config;

use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 16.13 — AC5.2 (`/api/v1/workstation-config/wallpaper`).
 *
 * ≥5 tests :
 *  - 401 sans Authorization (jwt.missing)
 *  - 401 JWT expiré (jwt.expired)
 *  - 401 JWT tier=controlhub (jwt.wrong_tier)
 *  - 404 workstation_uuid inconnu
 *  - 200 happy path (image/jpeg ou image/png)
 *
 * + tests spécifiques wallpaper (format invalide, action invalide).
 */
class WallpaperApiV1Test extends TestCase
{
    use IssuesWorkstationJwt;
    use SeedsWorkstationConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // F8 post-review : on n'esquive plus le setUp global si Imagick
        // est absent — les tests 401/404/400 n'en ont jamais besoin (la
        // réponse intervient avant toute composition image). Le check
        // Imagick est désormais déporté dans les 3 tests qui en ont besoin
        // (`happy_path_returns_200_image_jpeg`,
        // `happy_path_png_format_returns_image_png`,
        // `response_has_no_store_cache_headers`).
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
        $this->seedWorkstationContextSchemas();
        Cache::store('array')->flush();

        if (empty(config('app.key'))) {
            config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        }

        // Schémas additionnels nécessaires à WallpaperResolver.
        if (! Schema::hasTable('wallpapers')) {
            Schema::create('wallpapers', function (Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->string('path');
                $t->string('type');
                $t->string('owner_type')->nullable();
                $t->unsignedBigInteger('owner_id')->nullable();
                $t->boolean('is_default')->default(false);
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
            });
        }

        config()->set('wallpapers.storage_path', sys_get_temp_dir() . '/wp-fake-' . uniqid());
        if (class_exists('Imagick')) {
            config()->set('wallpapers.system_default_path', $this->makeFakeJpg());
        }
    }

    /**
     * Guard local pour les tests qui composent réellement une image.
     */
    private function requireImagick(): void
    {
        if (! class_exists('Imagick')) {
            self::markTestSkipped('Imagick non disponible — composition wallpaper requise pour ce test.');
        }
    }

    private function makeFakeJpg(): string
    {
        $path = sys_get_temp_dir() . '/wp-sys-default-' . bin2hex(random_bytes(3)) . '.jpg';
        $im = new \Imagick();
        $im->newImage(1920, 1080, new \ImagickPixel('#444444'));
        $im->setImageFormat('jpg');
        $im->writeImage($path);
        $im->destroy();
        return $path;
    }

    #[Test]
    public function missing_authorization_returns_401_missing(): void
    {
        $this->getJson('/api/v1/workstation-config/wallpaper')
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_MISSING]);
    }

    #[Test]
    public function expired_jwt_returns_401_expired(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt([
            'sub' => $this->seededWorkstationUuid,
            'iat' => Carbon::now()->subDays(2)->getTimestamp(),
            'exp' => Carbon::now()->subDay()->getTimestamp(),
        ]);

        $this->getJson('/api/v1/workstation-config/wallpaper', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_EXPIRED]);
    }

    #[Test]
    public function wrong_tier_returns_401_wrong_tier(): void
    {
        $emitted = $this->issueTestJwt([
            'sub' => $this->seededWorkstationUuid,
            'tier' => 'controlhub',
        ]);

        $this->getJson('/api/v1/workstation-config/wallpaper', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_WRONG_TIER]);
    }

    #[Test]
    public function unknown_workstation_returns_404(): void
    {
        $emitted = $this->issueTestJwt(['sub' => '99999999-9999-4999-9999-999999999999']);

        $this->getJson(
            '/api/v1/workstation-config/wallpaper?action=wallpaper&os=linux&format=png',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(404);
    }

    #[Test]
    public function happy_path_returns_200_image_jpeg(): void
    {
        $this->requireImagick();
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/wallpaper?action=wallpaper&os=linux&format=jpg',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function happy_path_png_format_returns_image_png(): void
    {
        $this->requireImagick();
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/wallpaper?action=wallpaper&os=linux&format=png',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function invalid_action_returns_400(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $this->getJson(
            '/api/v1/workstation-config/wallpaper?action=invalid&os=linux',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(400);
    }

    #[Test]
    public function invalid_format_returns_400(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $this->getJson(
            '/api/v1/workstation-config/wallpaper?action=wallpaper&os=linux&format=bmp',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(400);
    }

    #[Test]
    public function response_has_no_store_cache_headers(): void
    {
        $this->requireImagick();
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/wallpaper?action=wallpaper&os=linux',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
