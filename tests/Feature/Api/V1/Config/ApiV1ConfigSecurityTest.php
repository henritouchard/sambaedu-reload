<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Config;

use App\Auth\V1\Models\WorkstationJwtRevocation;
use App\Auth\V1\Support\JwtErrorCodes;
use App\Services\AppCustomization\AppCustomizationService;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use App\Services\Wallpaper\Contracts\WallpaperContextRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 16.13 — AC5.3 / AC6.4 (option b).
 *
 * Tests transversaux de sécurité sur les 8 endpoints
 * `/api/v1/workstation-config/*` :
 *
 *  - **workstation_uuid query ignoré** (F5 + Opus-21 post-review) : un
 *    JWT émet `sub=UUID_A` (poste seedé), on passe
 *    `?workstation_uuid=UUID_B` (UUID_B NON seedé) — si le controller
 *    respectait la query → 404 ; si JWT prime → 200 image.
 *    Preuve binaire forte (vs ancien test 200/200 non discriminant).
 *  - **Repository fail-fast** (F6 post-review) : sur les 4 endpoints
 *    sensibles (wallpaper, firefox, thunderbird, associations + network,
 *    veyon), les repositories APCu legacy ne doivent JAMAIS être
 *    appelés (mock `shouldNotReceive('findById')`).
 *  - **JWT révoqué** (F1 + Henri Q1 post-review) : matérialise AC2.5
 *    explicitement — JWT avec `jti` enregistré en
 *    `workstation_jwt_revocations` → 401 + code `jwt.revoked`.
 *  - **`/api/v1/agent/ping`** : non-régression 16.10.
 */
class ApiV1ConfigSecurityTest extends TestCase
{
    use IssuesWorkstationJwt;
    use SeedsWorkstationConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
        $this->seedWorkstationContextSchemas();
        Cache::store('array')->flush();

        if (empty(config('app.key'))) {
            config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function workstation_uuid_query_is_ignored_in_favor_of_jwt(): void
    {
        // F5 + Opus-21 post-review : preuve binaire forte.
        //
        // UUID_A seedé (poste résolvable) → JWT signe UUID_A.
        // UUID_B non seedé (poste inexistant) → passé en query.
        //
        // Si le controller respectait la query → 404 (UUID_B inconnu DB).
        // Si le controller respecte le JWT → 200 + image/jpeg (UUID_A OK).
        //
        // L'endpoint /api/v1/workstation-config/wallpaper a été choisi
        // pour discriminer fortement (image vs vide).
        if (! class_exists('Imagick')) {
            self::markTestSkipped('Imagick non disponible — wallpaper happy path requis.');
        }

        $this->seedWorkstationContext(
            uuid: 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
            name: 'post-a',
        );

        // Préparer le fake wallpaper system default pour éviter l'erreur
        // compose (iso WallpaperApiV1Test).
        $this->ensureWallpaperFixtures();

        $emitted = $this->issueTestJwt(['sub' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa']);

        $response = $this->getJson(
            '/api/v1/workstation-config/wallpaper?workstation_uuid=bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb&action=wallpaper&os=linux&format=jpg',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        // UUID_B inexistant → si la query était lue, on aurait 404.
        // UUID_A seedé via JWT → 200 image/jpeg.
        $response->assertOk();
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    private function ensureWallpaperFixtures(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('wallpaper_assets')) {
            \Illuminate\Support\Facades\Schema::create('wallpaper_assets', function (\Illuminate\Database\Schema\Blueprint $t): void {
                $t->id();
                $t->string('filename')->unique();
                $t->string('original_name')->nullable();
                $t->string('checksum', 64)->unique();
                $t->unsignedBigInteger('byte_size')->nullable();
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
            });
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('wallpapers')) {
            \Illuminate\Support\Facades\Schema::create('wallpapers', function (\Illuminate\Database\Schema\Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->unsignedBigInteger('asset_id')->nullable();
                $t->string('type');
                $t->string('owner_type')->nullable();
                $t->unsignedBigInteger('owner_id')->nullable();
                $t->boolean('is_default')->default(false);
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
            });
        }
        $path = sys_get_temp_dir() . '/wp-sys-default-' . bin2hex(random_bytes(3)) . '.jpg';
        $im = new \Imagick();
        $im->newImage(1920, 1080, new \ImagickPixel('#444444'));
        $im->setImageFormat('jpg');
        $im->writeImage($path);
        $im->destroy();
        config()->set('wallpapers.storage_path', sys_get_temp_dir() . '/wp-fake-' . uniqid());
        config()->set('wallpapers.system_default_path', $path);
    }

    #[Test]
    public function repositories_apcu_legacy_are_never_called_on_api_v1_routes(): void
    {
        // F6 post-review : extension de la couverture à 6 endpoints
        // sensibles (vs 2 précédemment — `network` et `veyon` seulement).
        //
        // On bind 3 mocks fail-fast : `AppContextRepository` (APCu legacy),
        // `WallpaperContextRepository` (APCu wallpaper). L'invocation
        // déclencherait `shouldNotReceive` au tearDown Mockery.
        //
        // On vise les endpoints `/firefox`, `/thunderbird`, `/wallpaper`,
        // `/associations`, `/network`, `/veyon`. Les statuts 200/400/404
        // acceptés : le seul critère est que les repositories legacy ne
        // soient jamais appelés.
        $appContextMock = Mockery::mock(AppContextRepository::class);
        $appContextMock->shouldNotReceive('findById');
        $this->app->instance(AppContextRepository::class, $appContextMock);

        $wallpaperContextMock = Mockery::mock(WallpaperContextRepository::class);
        $wallpaperContextMock->shouldNotReceive('findById');
        $this->app->instance(WallpaperContextRepository::class, $wallpaperContextMock);

        // `AppCustomizationService` (chaîne firefox/thunderbird) — on
        // mocke `resolvePoliciesForMachine` pour court-circuiter la
        // chaîne LDAP/AD lourde mais retourner un payload valide.
        $appCustomizationMock = Mockery::mock(AppCustomizationService::class);
        $appCustomizationMock->shouldReceive('resolvePoliciesForMachine')
            ->andReturn(['policies' => [], 'extensions' => []]);
        $this->app->instance(AppCustomizationService::class, $appCustomizationMock);

        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        // Endpoints qui consomment `AppContext`/`WallpaperContext`
        // repositories en chemin legacy mais doivent les éviter en apiV1.
        // `/wallpaper` est testé avec UUID inconnu → 404 (évite chaîne
        // Imagick lourde, prouve juste que le repository n'est pas
        // touché).
        // `/network` et `/veyon` happy path → 200 (mocks `generator`
        // déjà bypassés).
        // `/firefox` et `/thunderbird` happy path → 200 (mock service ci-dessus).
        // `/associations` 400 (pas de body `list` valide) → mais
        // toAppContext est appelé avant validation list, donc on passe
        // d'abord par resolver, pas par repository APCu legacy.
        $endpoints = [
            ['GET', '/api/v1/workstation-config/wallpaper?action=wallpaper&os=linux'],
            ['GET', '/api/v1/workstation-config/firefox?os=linux&user=jdoe'],
            ['GET', '/api/v1/workstation-config/thunderbird?user=jdoe'],
            ['GET', '/api/v1/workstation-config/network?action=startup&os=linux'],
            ['GET', '/api/v1/workstation-config/veyon?licence=1'],
            ['POST', '/api/v1/workstation-config/associations'],
        ];

        foreach ($endpoints as [$method, $endpoint]) {
            $response = $method === 'POST'
                ? $this->postJson($endpoint, [], ['Authorization' => 'Bearer ' . $emitted['token']])
                : $this->getJson($endpoint, ['Authorization' => 'Bearer ' . $emitted['token']]);

            // 200/400/404/500 acceptés — le seul critère est que les
            // mocks fail-fast n'aient jamais été invoqués (vérifié par
            // Mockery au tearDown). 500 toléré pour wallpaper sans
            // Imagick (compose exception côté composer mais bien sans
            // appel au repository legacy).
            self::assertContains(
                $response->getStatusCode(),
                [200, 400, 404, 500],
                "Endpoint {$method} {$endpoint} a retourné un statut inattendu : " . $response->getStatusCode(),
            );
        }
    }

    #[Test]
    public function revoked_jwt_returns_401_revoked(): void
    {
        // F1 + Henri Q1 post-review : matérialise AC2.5 explicitement.
        // Pattern iso `PingControllerTest::revoked_jwt_returns_401_revoked`
        // (Story 16.10).
        $jti = (string) Str::uuid();
        $emitted = $this->issueTestJwt(['jti' => $jti]);

        WorkstationJwtRevocation::query()->create([
            'id' => (string) Str::uuid(),
            'jti' => $jti,
            'workstation_uuid' => $emitted['sub'],
            'revoked_at' => Carbon::now(),
            'reason' => 'lost_device',
            'expires_at' => Carbon::now()->addDay(),
        ]);

        $this->getJson(
            '/api/v1/workstation-config/wallpaper',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_REVOKED]);
    }

    #[Test]
    public function agent_ping_endpoint_still_works_non_regression(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $this->getJson('/api/v1/agent/ping', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'workstation_uuid', 'api_version']);
    }

    #[Test]
    public function api_v1_routes_all_require_authorization_header(): void
    {
        $endpoints = [
            'GET /api/v1/workstation-config/wallpaper',
            'GET /api/v1/workstation-config/firefox',
            'GET /api/v1/workstation-config/thunderbird',
            'GET /api/v1/workstation-config/shortcuts',
            'GET /api/v1/workstation-config/network',
            'GET /api/v1/workstation-config/veyon',
            'POST /api/v1/workstation-config/associations',
            'GET /api/v1/workstation-config/applications-scripts',
        ];

        foreach ($endpoints as $endpoint) {
            [$method, $url] = explode(' ', $endpoint, 2);
            $response = $method === 'POST'
                ? $this->postJson($url, ['list' => json_encode(['x' => []])])
                : $this->getJson($url);

            $response->assertStatus(401);
        }
    }
}
