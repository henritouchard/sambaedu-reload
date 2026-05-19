<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Config;

use App\Auth\V1\Support\JwtErrorCodes;
use App\Dto\AppCustomization\AppContext;
use App\Gpo\Services\AssociationsResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 16.13 — AC5.2 (`/api/v1/workstation-config/associations`).
 */
class AssociationsApiV1Test extends TestCase
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

        // Mock AssociationsResolver pour éviter dépendances FS / Eloquent
        // packages.xml lourdes.
        $mock = Mockery::mock(AssociationsResolver::class);
        $mock->shouldReceive('parseLocalAssocs')
            ->andReturnUsing(fn ($input) => is_array($input) ? $input : []);
        $mock->shouldReceive('resolve')
            ->andReturn([
                'pdf' => ['ProgId' => 'FoxitReader.Document', 'type' => 'extension'],
            ]);
        $this->app->instance(AssociationsResolver::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function missing_authorization_returns_401_missing(): void
    {
        $this->postJson('/api/v1/workstation-config/associations', ['list' => json_encode(['ext' => []])])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_MISSING]);
    }

    #[Test]
    public function expired_jwt_returns_401_expired(): void
    {
        $emitted = $this->issueTestJwt([
            'sub' => $this->seededWorkstationUuid,
            'iat' => Carbon::now()->subDays(2)->getTimestamp(),
            'exp' => Carbon::now()->subDay()->getTimestamp(),
        ]);

        $this->postJson(
            '/api/v1/workstation-config/associations',
            ['list' => json_encode(['ext' => []])],
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_EXPIRED]);
    }

    #[Test]
    public function wrong_tier_returns_401_wrong_tier(): void
    {
        $emitted = $this->issueTestJwt([
            'sub' => $this->seededWorkstationUuid,
            'tier' => 'controlhub',
        ]);

        $this->postJson(
            '/api/v1/workstation-config/associations',
            ['list' => json_encode(['ext' => []])],
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_WRONG_TIER]);
    }

    #[Test]
    public function unknown_workstation_returns_404(): void
    {
        $emitted = $this->issueTestJwt(['sub' => '99999999-9999-4999-9999-999999999999']);

        $this->postJson(
            '/api/v1/workstation-config/associations',
            ['list' => json_encode(['ext' => []])],
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(404);
    }

    #[Test]
    public function happy_path_post_returns_200_text_json_with_result(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->postJson(
            '/api/v1/workstation-config/associations',
            ['list' => json_encode(['ext' => ['pdf,FoxitReader.Document']])],
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $this->assertSame('text/json', (string) $response->headers->get('Content-Type'));
        $payload = json_decode($response->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('result', $payload);
    }

    #[Test]
    public function missing_list_param_returns_400(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $this->postJson(
            '/api/v1/workstation-config/associations',
            [],
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(400);
    }

    #[Test]
    public function invalid_json_list_returns_400(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $this->postJson(
            '/api/v1/workstation-config/associations',
            ['list' => 'this-is-not-json'],
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(400);
    }

    #[Test]
    public function list_too_large_returns_400(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $this->postJson(
            '/api/v1/workstation-config/associations',
            ['list' => str_repeat('a', 11000)],
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(400);
    }
}
