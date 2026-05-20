<?php

declare(strict_types=1);

namespace Tests\Feature\Wallpaper;

use App\Dto\Wallpaper\WallpaperContext;
use App\Services\Wallpaper\Contracts\WallpaperContextRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests feature — endpoint legacy `gpo/wallpaper_out.php`.
 *
 * Story 4.7 — AC 13.
 */
class LegacyOutEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Story 16.13bis — la route legacy `gpo/wallpaper_out.php` a été
        // transformée en `MigrationController::serveFragment` (R6 / Option a
        // sélective). Les tests Feature qui appellent cette URL via HTTP
        // n'invoquent plus `WallpaperController::legacyOut` — la méthode
        // reste appelable programmatiquement mais la route HTTP renvoie un
        // fragment de migration. Skip tant qu'une story de cleanup Phase 3
        // n'a pas retiré ces tests devenus sans objet.
        $this->markTestSkipped('Story 16.13bis : route legacy `gpo/wallpaper_out.php` transformée en MigrationController, tests Feature URL caducs (R6).');

        if (! class_exists('Imagick')) {
            $this->markTestSkipped('Imagick non disponible.');
        }

        Model::unguard();
        \App\Models\WorkstationGroup::flushEventListeners();
        \App\Models\UserGroup::flushEventListeners();
        \App\Models\User::flushEventListeners();

        // Clé de chiffrement pour les tests feature (Laravel `encrypter`)
        if (empty(config('app.key'))) {
            config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        }

        // Schémas SQLite minimaux
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('login')->unique();
            $t->string('password')->nullable();
            $t->string('role')->default('eleve');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('ad_rights_bitmask')->default(0);
            $t->timestamps();
        });
        Schema::create('user_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->string('type')->default('classe');
            $t->timestamps();
        });
        Schema::create('workstation_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_physical')->default(true);
            $t->timestamps();
        });
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

        config()->set('wallpapers.storage_path', sys_get_temp_dir() . '/wp-fake');
        config()->set('wallpapers.system_default_path', $this->makeFakeJpg());
    }

    /**
     * Bind un ContextRepository en mémoire avec un id ↔ contexte précalculé.
     */
    private function seedContext(string $id, array $context): void
    {
        $ctx = WallpaperContext::fromApcuArray($context);
        $this->app->bind(WallpaperContextRepository::class, function () use ($id, $ctx) {
            return new class($id, $ctx) implements WallpaperContextRepository {
                public function __construct(private string $validId, private WallpaperContext $ctx) {}

                public function findById(string $id): ?WallpaperContext
                {
                    return $id === $this->validId ? $this->ctx : null;
                }
            };
        });
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

    private function validId(): string
    {
        return str_repeat('a', 32);
    }

    #[Test]
    public function unknown_action_returns_400(): void
    {
        $this->post('/gpo/wallpaper_out.php', [
            'action' => 'icone',
            'id' => $this->validId(),
        ])->assertStatus(400);
    }

    #[Test]
    public function missing_action_returns_400(): void
    {
        // Post-review #9 : AC 13 demande explicitement ce test (distinct du
        // cas "action inconnue")
        $this->post('/gpo/wallpaper_out.php', [
            'id' => $this->validId(),
        ])->assertStatus(400);
    }

    #[Test]
    public function missing_id_returns_400(): void
    {
        $this->post('/gpo/wallpaper_out.php', [
            'action' => 'wallpaper',
            'id' => '',
        ])->assertStatus(400);
    }

    #[Test]
    public function malformed_id_returns_400(): void
    {
        $this->post('/gpo/wallpaper_out.php', [
            'action' => 'wallpaper',
            'id' => 'tooshort',
        ])->assertStatus(400);
    }

    #[Test]
    public function expired_or_unknown_context_returns_404(): void
    {
        $this->seedContext('different-id-value-ignored-by-regex-00', []);
        $this->post('/gpo/wallpaper_out.php', [
            'action' => 'wallpaper',
            'id' => $this->validId(),
        ])->assertStatus(404);
    }

    #[Test]
    public function wallpaper_action_returns_valid_jpeg(): void
    {
        $this->seedContext($this->validId(), [
            'user' => ['cn' => 'jdoe', 'fullname' => 'John Doe'],
            'admin' => false,
            'machine' => ['cn' => 'post01'],
            'salle' => '',
            'list_u' => [],
            'os' => 'linux',
            'time' => time(),
        ]);

        $response = $this->post('/gpo/wallpaper_out.php', [
            'action' => 'wallpaper',
            'id' => $this->validId(),
            'format' => 'jpg',
        ]);

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $info = @getimagesizefromstring($response->content());
        $this->assertNotFalse($info);
        $this->assertSame(1920, $info[0]);
    }

    #[Test]
    public function veyon_action_returns_valid_jpeg(): void
    {
        $this->seedContext($this->validId(), [
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'post01'],
            'salle' => '',
            'admin' => false,
        ]);

        $response = $this->post('/gpo/wallpaper_out.php', [
            'action' => 'veyon',
            'id' => $this->validId(),
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function lockscreen_action_returns_valid_png(): void
    {
        $this->seedContext($this->validId(), [
            'user' => '',
            'machine' => ['cn' => 'post01'],
            'salle' => '',
        ]);

        $response = $this->post('/gpo/wallpaper_out.php', [
            'action' => 'lockscreen',
            'id' => $this->validId(),
            'format' => 'png',
        ]);

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function wait_action_returns_valid_image(): void
    {
        $this->seedContext($this->validId(), [
            'user' => '',
            'machine' => ['cn' => 'post01'],
        ]);

        $response = $this->post('/gpo/wallpaper_out.php', [
            'action' => 'wallpaper-wait',
            'id' => $this->validId(),
        ]);

        $response->assertOk();
    }

    #[Test]
    public function no_cache_headers_present(): void
    {
        $this->seedContext($this->validId(), ['user' => ['cn' => 'jdoe']]);

        $response = $this->post('/gpo/wallpaper_out.php', [
            'action' => 'wallpaper',
            'id' => $this->validId(),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
