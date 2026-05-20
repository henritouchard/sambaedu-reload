<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Config;

use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 16.13 — AC5.2 (`/api/v1/workstation-config/network`).
 */
class NetworkApiV1Test extends TestCase
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
    }

    #[Test]
    public function missing_authorization_returns_401_missing(): void
    {
        $this->getJson('/api/v1/workstation-config/network')
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

        $this->getJson('/api/v1/workstation-config/network', ['Authorization' => 'Bearer ' . $emitted['token']])
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

        $this->getJson('/api/v1/workstation-config/network', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_WRONG_TIER]);
    }

    #[Test]
    public function unknown_workstation_returns_404(): void
    {
        $emitted = $this->issueTestJwt(['sub' => '99999999-9999-4999-9999-999999999999']);

        $this->getJson(
            '/api/v1/workstation-config/network?action=startup&os=linux',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(404);
    }

    #[Test]
    public function action_logon_linux_returns_200_text_plain(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/network?action=startup&os=linux&user=jdoe',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $this->assertStringContainsString(
            'text/plain',
            (string) $response->headers->get('Content-Type'),
        );
    }

    #[Test]
    public function os_windows_returns_200_empty_body(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/network?action=startup&os=windows',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $this->assertSame('', (string) $response->getContent());
    }

    #[Test]
    public function invalid_action_returns_200_empty_body(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/network?action=invalid&os=linux',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        // Iso-legacy : action ∉ {startup, logon} → body vide.
        $response->assertOk();
        $this->assertSame('', (string) $response->getContent());
    }
}
