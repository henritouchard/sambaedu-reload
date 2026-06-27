<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Releases\ReleaseCreationService;
use App\Services\Agent\Releases\ReleaseOperationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `ReleaseCreationService` — Story 25.1 (AC1, AC5).
 *
 * L'AC « impossible de publier un artefact incohérent » : chaque refus
 * (fichier absent, hash divergent, version dupliquée, formats invalides)
 * = exception + AUCUNE ligne écrite. Succès + swap stable transactionnel,
 * promote (pointeur), target (updateOrCreate + récence). Fichiers binaires
 * factices en tmp via `config(['agent.releases_path' => …])` — jamais le
 * vrai storage/. Sans HTTP (le contrat HTTP vit dans `ReleaseEndpointTest`).
 */
class ReleaseCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReleaseCreationService $service;

    private string $releasesDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Neutraliser les observers AD (host sans LDAP).
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        $this->service = new ReleaseCreationService();
        $this->releasesDir = storage_path('framework/testing/releases-' . uniqid());
        File::ensureDirectoryExists($this->releasesDir);
        config(['agent.releases_path' => $this->releasesDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->releasesDir);

        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    /** Dépose un binaire factice et retourne son SHA-256 réel. */
    private function putBinary(string $filename, string $content = "MZ\x90\x00fake-pe"): string
    {
        file_put_contents($this->releasesDir . '/' . $filename, $content);

        return hash('sha256', $content);
    }

    /**
     * Refus attendu : exception avec la raison machine ET zéro écriture.
     */
    private function assertRejected(string $reason, callable $call): void
    {
        $before = AgentRelease::query()->count();
        try {
            $call();
            self::fail('Une ReleaseOperationException était attendue (raison ' . $reason . ').');
        } catch (ReleaseOperationException $e) {
            self::assertSame($reason, $e->reason);
        }
        self::assertSame($before, AgentRelease::query()->count(), 'Refus = AUCUNE ligne écrite.');
    }

    // ── AC1 — création vérifiée ──────────────────────────────────────────

    #[Test]
    public function create_verifies_the_hash_against_the_real_file_and_persists(): void
    {
        $hash = $this->putBinary('sambaedu-agent-2.1.2.exe');

        $release = $this->service->create('2.1.2', 'sambaedu-agent-2.1.2.exe', $hash);

        self::assertSame('2.1.2', $release->version);
        self::assertSame($hash, $release->hash);
        self::assertSame('sambaedu-agent-2.1.2.exe', $release->filename);
        self::assertFalse($release->is_stable);
        $this->assertDatabaseHas('agent_releases', ['version' => '2.1.2', 'hash' => $hash]);
    }

    #[Test]
    public function declared_hash_is_normalized_case_insensitively(): void
    {
        $hash = $this->putBinary('sambaedu-agent-2.1.2.exe');

        $release = $this->service->create('2.1.2', 'sambaedu-agent-2.1.2.exe', strtoupper($hash));

        self::assertSame($hash, $release->hash);
    }

    #[Test]
    public function create_stable_swaps_the_stable_pointer_transactionally(): void
    {
        $h1 = $this->putBinary('sambaedu-agent-2.0.0.exe', 'one');
        $h2 = $this->putBinary('sambaedu-agent-2.1.2.exe', 'two');

        $first = $this->service->create('2.0.0', 'sambaedu-agent-2.0.0.exe', $h1, stable: true);
        self::assertTrue($first->is_stable);

        $second = $this->service->create('2.1.2', 'sambaedu-agent-2.1.2.exe', $h2, stable: true);

        self::assertTrue($second->refresh()->is_stable);
        self::assertFalse($first->refresh()->is_stable);
        self::assertSame(1, AgentRelease::query()->where('is_stable', true)->count(), 'Au plus UNE stable.');
    }

    #[Test]
    public function hash_mismatch_is_rejected_without_any_write(): void
    {
        $this->putBinary('sambaedu-agent-2.1.2.exe');

        $this->assertRejected('hash_mismatch', fn () => $this->service->create(
            '2.1.2',
            'sambaedu-agent-2.1.2.exe',
            str_repeat('0', 64),
        ));
    }

    #[Test]
    public function missing_file_is_rejected(): void
    {
        $this->assertRejected('file_missing', fn () => $this->service->create(
            '2.1.2',
            'sambaedu-agent-2.1.2.exe',
            str_repeat('a', 64),
        ));
    }

    #[Test]
    public function duplicate_version_is_rejected(): void
    {
        $hash = $this->putBinary('sambaedu-agent-2.1.2.exe');
        $this->service->create('2.1.2', 'sambaedu-agent-2.1.2.exe', $hash);

        // Re-soumission identique (version ET filename déjà publiés) :
        // refus duplicate_version, seul doublon possible (filename dérivé).
        $this->assertRejected('duplicate_version', fn () => $this->service->create(
            '2.1.2',
            'sambaedu-agent-2.1.2.exe',
            $hash,
        ));
    }

    #[Test]
    public function filename_diverging_from_version_is_rejected(): void
    {
        // Review 25.1 #2 : le manifest annoncerait une version divergente
        // du binaire — refus AVANT hash/disque (aucune écriture).
        $this->putBinary('sambaedu-agent-9.9.9.exe', 'other');

        $this->assertRejected('filename_version_mismatch', fn () => $this->service->create(
            '2.1.2',
            'sambaedu-agent-9.9.9.exe',
            str_repeat('a', 64),
        ));
        self::assertSame(0, AgentRelease::query()->count());
    }

    #[Test]
    public function reusing_a_published_filename_for_another_version_is_rejected(): void
    {
        // Filename dérivé de la version (review 25.1 #2) : réutiliser le
        // filename d'une release publiée pour une autre version = mismatch,
        // refusé avant même le lookup des doublons.
        $hash = $this->putBinary('sambaedu-agent-2.1.2.exe');
        $this->service->create('2.1.2', 'sambaedu-agent-2.1.2.exe', $hash);

        $this->assertRejected('filename_version_mismatch', fn () => $this->service->create(
            '2.1.3',
            'sambaedu-agent-2.1.2.exe',
            $hash,
        ));
    }

    #[Test]
    public function malformed_inputs_are_rejected_before_any_disk_access(): void
    {
        // Domaines fermés validés EN CODE (piège n° 9 : SQLite n'applique
        // pas les varchar) — chaque forme invalide a sa raison machine.
        $this->assertRejected('invalid_version', fn () => $this->service->create(
            'v 2.1.2', // espace interdit
            'sambaedu-agent-2.1.2.exe',
            str_repeat('a', 64),
        ));
        $this->assertRejected('invalid_version', fn () => $this->service->create(
            str_repeat('9', 33), // > 32 (largeur colonne, validée en code)
            'sambaedu-agent-2.1.2.exe',
            str_repeat('a', 64),
        ));
        $this->assertRejected('invalid_filename', fn () => $this->service->create(
            '2.1.2',
            'agent.exe', // pas la forme produite par le build 24.5
            str_repeat('a', 64),
        ));
        $this->assertRejected('invalid_filename', fn () => $this->service->create(
            '2.1.2',
            '../sambaedu-agent-2.1.2.exe', // traversal
            str_repeat('a', 64),
        ));
        $this->assertRejected('invalid_hash', fn () => $this->service->create(
            '2.1.2',
            'sambaedu-agent-2.1.2.exe',
            'not-a-sha256',
        ));
    }

    // ── Décision n° 5 — promote ──────────────────────────────────────────

    #[Test]
    public function promote_moves_the_stable_pointer(): void
    {
        $h1 = $this->putBinary('sambaedu-agent-2.0.0.exe', 'one');
        $h2 = $this->putBinary('sambaedu-agent-2.1.2.exe', 'two');
        $old = $this->service->create('2.0.0', 'sambaedu-agent-2.0.0.exe', $h1, stable: true);
        $new = $this->service->create('2.1.2', 'sambaedu-agent-2.1.2.exe', $h2);

        $this->service->promote('2.1.2');

        self::assertTrue($new->refresh()->is_stable);
        self::assertFalse($old->refresh()->is_stable);
        self::assertSame(1, AgentRelease::query()->where('is_stable', true)->count());
    }

    #[Test]
    public function promote_unknown_version_throws(): void
    {
        $this->expectException(ReleaseOperationException::class);

        $this->service->promote('9.9.9');
    }

    // ── Décision n° 6 — target (ring) ────────────────────────────────────

    #[Test]
    public function target_creates_then_updates_a_single_ring_per_group(): void
    {
        $h1 = $this->putBinary('sambaedu-agent-2.0.0.exe', 'one');
        $h2 = $this->putBinary('sambaedu-agent-2.1.2.exe', 'two');
        $r1 = $this->service->create('2.0.0', 'sambaedu-agent-2.0.0.exe', $h1);
        $r2 = $this->service->create('2.1.2', 'sambaedu-agent-2.1.2.exe', $h2);
        $group = WorkstationGroup::factory()->create();

        $ring = $this->service->target('2.0.0', $group);
        self::assertSame($r1->id, $ring->agent_release_id);

        // Re-ciblage : MÊME ligne (UNIQUE par groupe), release mise à jour.
        $ring = $this->service->target('2.1.2', $group);
        self::assertSame($r2->id, $ring->agent_release_id);
        self::assertSame(1, AgentReleaseRing::query()->count());
    }

    #[Test]
    public function retargeting_the_same_version_refreshes_updated_at(): void
    {
        // La récence (décision n° 4) : un re-ciblage — même de la MÊME
        // version (cas rollback) — doit regagner la précédence.
        $hash = $this->putBinary('sambaedu-agent-2.1.2.exe');
        $this->service->create('2.1.2', 'sambaedu-agent-2.1.2.exe', $hash);
        $group = WorkstationGroup::factory()->create();

        $ring = $this->service->target('2.1.2', $group);
        $past = now()->subDays(2)->startOfSecond();
        DB::table('agent_release_rings')->where('id', $ring->id)
            ->update(['updated_at' => $past->toDateTimeString()]);

        $ring = $this->service->target('2.1.2', $group);

        self::assertTrue(
            $ring->updated_at->greaterThan($past),
            'updated_at doit être rafraîchi même sans changement de release.',
        );
    }

    #[Test]
    public function target_unknown_version_throws_and_writes_no_ring(): void
    {
        $group = WorkstationGroup::factory()->create();

        try {
            $this->service->target('9.9.9', $group);
            self::fail('Une ReleaseOperationException était attendue.');
        } catch (ReleaseOperationException $e) {
            self::assertSame('unknown_version', $e->reason);
        }
        self::assertSame(0, AgentReleaseRing::query()->count());
    }
}
