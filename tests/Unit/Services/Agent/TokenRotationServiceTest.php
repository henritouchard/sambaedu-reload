<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `TokenRotationService` — Story 23.2 (FR12-FR14).
 *
 * Cycle de vie du token agent : format, hachage, grâce D5, révocation,
 * quarantaine. Le clair n'est jamais persisté.
 */
class TokenRotationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TokenRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TokenRotationService();
    }

    #[Test]
    public function issue_returns_64_hex_token_and_stores_only_its_sha256(): void
    {
        $ws = Workstation::factory()->create();

        $token = $this->service->issueFor($ws);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $ws->refresh();
        self::assertSame(hash('sha256', $token), $ws->agent_token_hash);
        self::assertNotSame($token, $ws->agent_token_hash);
        self::assertNotNull($ws->agent_token_rotated_at);
    }

    #[Test]
    public function issue_clears_previous_hash_and_quarantine(): void
    {
        $ws = Workstation::factory()->create();
        $ws->agent_previous_token_hash = str_repeat('a', 64);
        $ws->agent_quarantined_at = now();
        $ws->save();

        $this->service->issueFor($ws);

        $ws->refresh();
        self::assertNull($ws->agent_previous_token_hash);
        self::assertNull($ws->agent_quarantined_at);
    }

    #[Test]
    public function rotate_slides_current_hash_to_previous(): void
    {
        $ws = Workstation::factory()->create();
        $first = $this->service->issueFor($ws);

        $second = $this->service->rotateFor($ws->refresh());

        $ws->refresh();
        self::assertSame(hash('sha256', $first), $ws->agent_previous_token_hash);
        self::assertSame(hash('sha256', $second), $ws->agent_token_hash);
        self::assertNotSame($first, $second);
    }

    #[Test]
    public function rotate_preserves_existing_grace_window(): void
    {
        // Réponse perdue : le poste ne détient que le PREMIER token. Une
        // seconde rotation ne doit pas écraser previous avec un hash que
        // le poste n'a jamais reçu (sinon lock-out).
        $ws = Workstation::factory()->create();
        $first = $this->service->issueFor($ws);
        $this->service->rotateFor($ws->refresh());

        $third = $this->service->rotateFor($ws->refresh());

        $ws->refresh();
        self::assertSame(hash('sha256', $first), $ws->agent_previous_token_hash);
        self::assertSame(hash('sha256', $third), $ws->agent_token_hash);
    }

    #[Test]
    public function confirm_rotation_closes_grace_window(): void
    {
        $ws = Workstation::factory()->create();
        $this->service->issueFor($ws);
        $this->service->rotateFor($ws->refresh());

        $this->service->confirmRotation($ws->refresh());

        self::assertNull($ws->refresh()->agent_previous_token_hash);
    }

    #[Test]
    public function revoke_clears_all_agent_columns(): void
    {
        $ws = Workstation::factory()->create();
        $this->service->issueFor($ws);
        $this->service->rotateFor($ws->refresh());
        $ws->refresh();
        $ws->agent_quarantined_at = now();
        $ws->save();

        $this->service->revokeFor($ws, 'test_revocation');

        $ws->refresh();
        self::assertNull($ws->agent_token_hash);
        self::assertNull($ws->agent_previous_token_hash);
        self::assertNull($ws->agent_token_rotated_at);
        self::assertNull($ws->agent_quarantined_at);
        self::assertFalse($ws->isAgentEnrolled());
    }

    #[Test]
    public function quarantine_sets_timestamp_without_touching_tokens(): void
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        $this->service->quarantine($ws->refresh(), 'mac mismatch test');

        $ws->refresh();
        self::assertTrue($ws->isAgentQuarantined());
        self::assertSame(hash('sha256', $token), $ws->agent_token_hash);
    }
}
