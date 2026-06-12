<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\User;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\SyncRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `SyncRequestService` — Story 24.7 (AC5, AC6).
 *
 * request/fulfill/isPending + périmètre groupe avec exclusions (non
 * enrôlés / quarantaine ignorés silencieusement, piège 6). Sans HTTP — le
 * contrat HTTP (bypass 304 / solde au report) vit dans `SyncRequestTest`.
 */
class SyncRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private SyncRequestService $service;

    private TokenRotationService $tokens;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SyncRequestService();
        $this->tokens = app(TokenRotationService::class);
        $this->admin = User::factory()->create();
    }

    private function enrolled(): Workstation
    {
        $ws = Workstation::factory()->create();
        $this->tokens->issueFor($ws);

        return $ws->refresh();
    }

    #[Test]
    public function request_on_enrolled_workstation_posts_a_pending_timestamp(): void
    {
        $ws = $this->enrolled();
        self::assertFalse($this->service->isPending($ws));

        $count = $this->service->request($ws, $this->admin);

        self::assertSame(1, $count);
        self::assertNotNull($ws->refresh()->agent_sync_requested_at);
        self::assertTrue($this->service->isPending($ws->refresh()));
    }

    #[Test]
    public function request_ignores_non_enrolled_workstation(): void
    {
        $ws = Workstation::factory()->create(); // jamais enrôlé

        $count = $this->service->request($ws, $this->admin);

        self::assertSame(0, $count);
        self::assertNull($ws->refresh()->agent_sync_requested_at);
    }

    #[Test]
    public function request_ignores_quarantined_workstation(): void
    {
        $ws = $this->enrolled();
        $this->tokens->quarantine($ws, 'test');

        $count = $this->service->request($ws->refresh(), $this->admin);

        self::assertSame(0, $count);
        self::assertNull($ws->refresh()->agent_sync_requested_at);
    }

    #[Test]
    public function request_on_collection_counts_only_eligible_members(): void
    {
        $enrolledA = $this->enrolled();
        $enrolledB = $this->enrolled();
        $notEnrolled = Workstation::factory()->create();
        $quarantined = $this->enrolled();
        $this->tokens->quarantine($quarantined, 'test');

        $members = collect([$enrolledA, $enrolledB, $notEnrolled, $quarantined->refresh()]);

        $count = $this->service->request($members, $this->admin);

        // Seuls les 2 enrôlés non quarantaine.
        self::assertSame(2, $count);
        self::assertNotNull($enrolledA->refresh()->agent_sync_requested_at);
        self::assertNotNull($enrolledB->refresh()->agent_sync_requested_at);
        self::assertNull($notEnrolled->refresh()->agent_sync_requested_at);
        self::assertNull($quarantined->refresh()->agent_sync_requested_at);
    }

    #[Test]
    public function fulfill_clears_a_pending_request(): void
    {
        $ws = $this->enrolled();
        $this->service->request($ws, $this->admin);

        $fulfilled = $this->service->fulfill($ws->refresh());

        self::assertTrue($fulfilled);
        self::assertNull($ws->refresh()->agent_sync_requested_at);
    }

    #[Test]
    public function fulfill_is_a_noop_without_pending_request(): void
    {
        $ws = $this->enrolled();

        $fulfilled = $this->service->fulfill($ws);

        self::assertFalse($fulfilled);
        self::assertNull($ws->refresh()->agent_sync_requested_at);
    }
}
