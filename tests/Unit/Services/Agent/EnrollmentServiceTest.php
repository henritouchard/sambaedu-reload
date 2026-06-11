<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\Workstation;
use App\Services\Agent\Enrollment\EnrollmentService;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `EnrollmentService` — Story 23.3 (AC1-AC4).
 *
 * Ticket d'enrôlement one-time : format, hachage, révocation à la
 * réinstallation (AC2), écrasement à la re-génération, consommation au
 * redeem, résultats d'échec typés (conflit 409 / refus 403).
 */
class EnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnrollmentService $service;

    private TokenRotationService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new TokenRotationService();
        $this->service = new EnrollmentService($this->tokens);
    }

    // ── openTicket (AC1, AC2) ───────────────────────────────────────────

    #[Test]
    public function open_ticket_returns_64_hex_and_stores_only_its_sha256_with_expiry(): void
    {
        $ws = Workstation::factory()->create();

        $ticket = $this->service->openTicket($ws);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ticket);
        $ws->refresh();
        self::assertSame(hash('sha256', $ticket), $ws->agent_enroll_ticket_hash);
        self::assertNotSame($ticket, $ws->agent_enroll_ticket_hash);
        // Expiry = now + TTL config (défaut 240 min).
        self::assertTrue($ws->agent_enroll_ticket_expires_at->isFuture());
        self::assertEqualsWithDelta(
            now()->addMinutes((int) config('agent.enroll_ticket_ttl_minutes'))->getTimestamp(),
            $ws->agent_enroll_ticket_expires_at->getTimestamp(),
            5,
        );
    }

    #[Test]
    public function open_ticket_does_not_revoke_anything_on_unenrolled_workstation(): void
    {
        $ws = Workstation::factory()->create();

        $this->service->openTicket($ws);

        $ws->refresh();
        self::assertNull($ws->agent_token_hash);
        self::assertNotNull($ws->agent_enroll_ticket_hash);
    }

    #[Test]
    public function open_ticket_revokes_existing_token_immediately_on_reinstall(): void
    {
        // AC2 — réinstall = révocation au DÉBUT de la réinstall : le clone
        // éventuel de l'ancien token meurt pendant que le disque se formate.
        $ws = Workstation::factory()->create();
        $this->tokens->issueFor($ws);
        self::assertTrue($ws->refresh()->isAgentEnrolled());

        $this->service->openTicket($ws);

        $ws->refresh();
        self::assertNull($ws->agent_token_hash);
        self::assertNull($ws->agent_previous_token_hash);
        self::assertFalse($ws->isAgentEnrolled());
        self::assertNotNull($ws->agent_enroll_ticket_hash);
    }

    #[Test]
    public function reopening_ticket_simply_replaces_previous_one(): void
    {
        // AC1 — re-fetch WinPE : écrasement, pas d'erreur.
        $ws = Workstation::factory()->create();
        $first = $this->service->openTicket($ws);

        $second = $this->service->openTicket($ws->refresh());

        $ws->refresh();
        self::assertNotSame($first, $second);
        self::assertSame(hash('sha256', $second), $ws->agent_enroll_ticket_hash);
        // Le premier ticket est mort.
        self::assertFalse($this->service->redeem($first)->enrolled);
    }

    #[Test]
    public function zero_ttl_misconfig_does_not_produce_stillborn_tickets(): void
    {
        config(['agent.enroll_ticket_ttl_minutes' => 0]);
        $ws = Workstation::factory()->create();

        $ticket = $this->service->openTicket($ws);

        self::assertTrue($ws->refresh()->agent_enroll_ticket_expires_at->isFuture());
        self::assertTrue($this->service->redeem($ticket)->enrolled);
    }

    // ── redeem (AC3) ─────────────────────────────────────────────────────

    #[Test]
    public function redeem_consumes_ticket_and_issues_token_hashed_in_db(): void
    {
        $ws = Workstation::factory()->create();
        $ticket = $this->service->openTicket($ws);

        $result = $this->service->redeem($ticket);

        self::assertTrue($result->enrolled);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $result->token);
        $ws->refresh();
        // Ticket consommé (colonnes effacées), token né haché.
        self::assertNull($ws->agent_enroll_ticket_hash);
        self::assertNull($ws->agent_enroll_ticket_expires_at);
        self::assertSame(hash('sha256', (string) $result->token), $ws->agent_token_hash);
    }

    #[Test]
    public function redeem_resolves_by_ticket_hash_not_by_provided_identity(): void
    {
        // Le ticket EST l'identité : un uuid/mac d'un AUTRE poste ne change
        // pas la résolution (log de cohérence seulement, jamais d'autorisation).
        $other = Workstation::factory()->create();
        $ws = Workstation::factory()->create();
        $ticket = $this->service->openTicket($ws);

        $result = $this->service->redeem($ticket, [
            'uuid' => $other->uuid,
            'mac' => $other->mac,
            'hostname' => 'autre-poste',
        ]);

        self::assertTrue($result->enrolled);
        self::assertSame(hash('sha256', (string) $result->token), $ws->refresh()->agent_token_hash);
        self::assertNull($other->refresh()->agent_token_hash);
    }

    // ── redeem — échecs typés (AC4) ──────────────────────────────────────

    #[Test]
    public function redeem_with_replayed_ticket_is_not_allowed(): void
    {
        $ws = Workstation::factory()->create();
        $ticket = $this->service->openTicket($ws);
        $this->service->redeem($ticket);

        $replay = $this->service->redeem($ticket);

        // Poste désormais enrôlé + identifiable ? Non : sans uuid/mac fournis
        // le poste visé n'est pas résolu → refus indistinct.
        self::assertFalse($replay->enrolled);
        self::assertFalse($replay->conflict);
    }

    #[Test]
    public function redeem_with_expired_ticket_is_refused(): void
    {
        $ws = Workstation::factory()->create();
        $ticket = $this->service->openTicket($ws);
        $ws->refresh();
        $ws->agent_enroll_ticket_expires_at = now()->subMinute();
        $ws->save();

        $result = $this->service->redeem($ticket);

        self::assertFalse($result->enrolled);
        self::assertFalse($result->conflict);
        self::assertNull($ws->refresh()->agent_token_hash);
    }

    #[Test]
    public function redeem_with_unknown_or_empty_ticket_is_refused(): void
    {
        Workstation::factory()->create();

        self::assertFalse($this->service->redeem(str_repeat('f', 64))->enrolled);
        self::assertFalse($this->service->redeem('')->enrolled);
    }

    #[Test]
    public function invalid_ticket_targeting_enrolled_workstation_is_conflict(): void
    {
        // AC4 — 409 réservé au cas « poste identifiable (uuid) ET déjà
        // enrôlé » : rien n'est écrasé, son token reste intact.
        $ws = Workstation::factory()->create();
        $token = $this->tokens->issueFor($ws);

        $result = $this->service->redeem(str_repeat('f', 64), ['uuid' => $ws->uuid]);

        self::assertFalse($result->enrolled);
        self::assertTrue($result->conflict);
        self::assertSame(hash('sha256', $token), $ws->refresh()->agent_token_hash);
    }

    #[Test]
    public function invalid_ticket_targeting_enrolled_workstation_by_mac_is_conflict(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);
        $this->tokens->issueFor($ws);

        // Résolution par mac à défaut d'uuid — format ipconfig toléré.
        $result = $this->service->redeem('', ['mac' => 'AA-BB-CC-DD-EE-FF']);

        self::assertTrue($result->conflict);
    }

    #[Test]
    public function invalid_ticket_targeting_unenrolled_or_unknown_workstation_is_not_allowed(): void
    {
        $ws = Workstation::factory()->create();

        // Poste connu mais non enrôlé → 403 (futur accueil porte 2, 25.3).
        $known = $this->service->redeem('', ['uuid' => $ws->uuid]);
        // Poste inconnu → 403 indistinct.
        $unknown = $this->service->redeem('', ['uuid' => '99999999-9999-9999-9999-999999999999']);

        self::assertFalse($known->enrolled);
        self::assertFalse($known->conflict);
        self::assertFalse($unknown->enrolled);
        self::assertFalse($unknown->conflict);
    }
}
