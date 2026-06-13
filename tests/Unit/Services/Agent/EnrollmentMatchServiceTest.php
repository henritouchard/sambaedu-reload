<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\Workstation;
use App\Services\Agent\Enrollment\EnrollmentMatchService;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `EnrollmentMatchService` — Story 25.3 (AC1, AC3).
 *
 * Faisceau de preuves : MAC = ancre fiable (normalisée tirets/colons/nu),
 * hostname = corroborant, uuid = jamais suffisant seul. Candidat UNIQUE exigé.
 * Concordance : MAC connue ET hostname cohérent ET poste non enrôlé — invariant
 * anti-usurpation verrouillé.
 */
class EnrollmentMatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnrollmentMatchService $matcher;

    private TokenRotationService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new EnrollmentMatchService();
        $this->tokens = new TokenRotationService();
    }

    // ── match() : MAC = ancre, normalisation ────────────────────────────

    #[Test]
    public function matches_known_workstation_by_mac_whatever_the_separator(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);

        foreach (['AA-BB-CC-DD-EE-FF', 'aabbccddeeff', 'aa:bb:cc:dd:ee:ff'] as $presented) {
            $matched = $this->matcher->match(['mac' => $presented]);
            self::assertNotNull($matched, "MAC présentée «{$presented}» doit rapprocher.");
            self::assertSame($ws->id, $matched->id);
        }
    }

    #[Test]
    public function no_match_when_mac_is_absent_or_unreadable(): void
    {
        Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);

        self::assertNull($this->matcher->match([]));
        self::assertNull($this->matcher->match(['mac' => '']));
        self::assertNull($this->matcher->match(['mac' => 'pas-une-mac']));
    }

    #[Test]
    public function uuid_or_hostname_alone_never_resolve_a_candidate(): void
    {
        $ws = Workstation::factory()->create([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'name' => 'PC-LAB-01',
        ]);

        // uuid seul → null (corroborant faible, gap 3).
        self::assertNull($this->matcher->match(['uuid' => $ws->uuid]));
        // hostname seul → null.
        self::assertNull($this->matcher->match(['hostname' => 'PC-LAB-01']));
    }

    #[Test]
    public function multiple_candidates_on_same_mac_yield_no_unique_match(): void
    {
        // Deux postes partageant la MAC (clone/import bancal) → ambiguïté.
        Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);
        Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff']);

        self::assertNull($this->matcher->match(['mac' => 'aa:bb:cc:dd:ee:ff']));
    }

    // ── isConcordant() : invariant anti-usurpation ──────────────────────

    #[Test]
    public function concordant_when_mac_matches_hostname_coherent_and_not_enrolled(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);

        self::assertTrue($this->matcher->isConcordant($ws, [
            'mac' => 'AA-BB-CC-DD-EE-FF',
            'hostname' => 'pc-lab-01',
        ]));
    }

    #[Test]
    public function concordant_when_hostname_absent_on_request(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);

        self::assertTrue($this->matcher->isConcordant($ws, ['mac' => 'aa:bb:cc:dd:ee:ff']));
    }

    #[Test]
    public function not_concordant_when_hostname_diverges(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);

        self::assertFalse($this->matcher->isConcordant($ws, [
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'autre-nom',
        ]));
    }

    #[Test]
    public function not_concordant_when_mac_diverges(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);

        self::assertFalse($this->matcher->isConcordant($ws, [
            'mac' => '11:22:33:44:55:66',
            'hostname' => 'PC-LAB-01',
        ]));
    }

    #[Test]
    public function enrolled_workstation_is_never_concordant(): void
    {
        $ws = Workstation::factory()->create(['mac' => 'aa:bb:cc:dd:ee:ff', 'name' => 'PC-LAB-01']);
        $this->tokens->issueFor($ws);
        $ws->refresh();

        self::assertFalse($this->matcher->isConcordant($ws, [
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'hostname' => 'PC-LAB-01',
        ]));
    }
}
