<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Network;

use App\Models\DhcpReservation;
use App\Models\Workstation;
use App\Services\Network\DhcpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;
use Tests\Traits\CreatesDhcpSchema;

/**
 * Story 8.1 — T8b / AC9 : parsing du fichier legacy `reservations.inc` +
 * upsert idempotent dans `dhcp_reservations`.
 *
 * Couvre :
 *  - cas nominaux + parsing tolérant aux espaces/tabs/sauts de ligne ;
 *  - commentaires `#` et `//` ignorés ;
 *  - lignes vides ignorées ;
 *  - blocs mal formés comptabilisés en errors[] sans avorter l'import ;
 *  - MAC dupliquée dans le même fichier : on garde le premier, le second
 *    est skipped ;
 *  - lien `workstation_id` quand un Workstation matche le `name` ;
 *  - idempotence : rejeu donne `created=0, updated=N` ;
 *  - préservation source : un rejeu n'écrase pas une source `manual` plus
 *    spécifique.
 */
class DhcpServiceImportLegacyTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesDhcpSchema;

    private DhcpService $service;
    private string $fixture;

    /** @var array<int, array{level:string,message:string}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDhcpSchema();
        $this->service = new DhcpService(new FakeCommandRunner());
        $this->fixture = base_path('tests/Fixtures/dhcp/reservations.inc');
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        $this->dropDhcpSchema();
        parent::tearDown();
    }

    private function logger(): callable
    {
        return function (string $level, string $message): void {
            $this->logs[] = ['level' => $level, 'message' => $message];
        };
    }

    #[Test]
    public function it_parses_nominal_blocks_and_creates_reservations(): void
    {
        $stats = $this->service->importFromLegacyFile($this->fixture, $this->logger());

        // Fixture : 3 blocs valides (poste01, poste02, poste03), 1 dup MAC
        // (poste99) → skipped, 1 malformé (posteBroken) → désormais comptabilisé
        // en errors[] (cf. review code 8.1 #5), 1 MAC invalide (posteBadMac) → error.
        $this->assertSame(3, DhcpReservation::count());
        $this->assertSame(3, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertGreaterThanOrEqual(1, $stats['skipped'] + count($stats['errors']));
    }

    #[Test]
    public function it_reports_malformed_host_block_as_error(): void
    {
        // Review code 8.1 #5 — la fixture contient `host posteBroken { … ` sans
        // accolade fermante. Doit produire au moins une erreur identifiant
        // ce bloc, plutôt qu'un skip silencieux.
        $stats = $this->service->importFromLegacyFile($this->fixture, $this->logger());

        $brokenError = null;
        foreach ($stats['errors'] as $err) {
            if (str_contains($err['reason'], 'posteBroken')) {
                $brokenError = $err;
                break;
            }
        }

        $this->assertNotNull($brokenError, 'Le bloc `host posteBroken` malformé doit être rapporté en errors[]');
        $this->assertGreaterThan(0, $brokenError['line']);
        $this->assertStringContainsString('malformé', $brokenError['reason']);
    }

    #[Test]
    public function it_marks_imported_records_with_legacy_migration_source(): void
    {
        $this->service->importFromLegacyFile($this->fixture, $this->logger());

        $this->assertGreaterThan(0, DhcpReservation::bySource('legacy-migration')->count());
    }

    #[Test]
    public function it_links_workstation_when_matching_name_exists(): void
    {
        Workstation::create([
            'name' => 'poste01',
            'status' => 'active',
        ]);

        $this->service->importFromLegacyFile($this->fixture, $this->logger());

        $reservation = DhcpReservation::where('name', 'poste01')->first();
        $this->assertNotNull($reservation);
        $this->assertNotNull($reservation->workstation_id);
    }

    #[Test]
    public function it_leaves_workstation_id_null_when_no_match(): void
    {
        $this->service->importFromLegacyFile($this->fixture, $this->logger());

        $reservation = DhcpReservation::where('name', 'poste01')->first();
        $this->assertNotNull($reservation);
        $this->assertNull($reservation->workstation_id);
    }

    #[Test]
    public function it_is_idempotent_on_replay(): void
    {
        $stats1 = $this->service->importFromLegacyFile($this->fixture, $this->logger());
        $this->assertGreaterThan(0, $stats1['created']);

        $beforeCount = DhcpReservation::count();
        $stats2 = $this->service->importFromLegacyFile($this->fixture, $this->logger());
        $afterCount = DhcpReservation::count();

        $this->assertSame($beforeCount, $afterCount, 'Le rejeu ne doit pas créer de doublons');
        $this->assertSame(0, $stats2['created']);
        $this->assertSame($stats1['created'], $stats2['updated']);
    }

    #[Test]
    public function it_does_not_overwrite_manual_source_on_replay(): void
    {
        // Crée manuellement une réservation pour poste01 avec source=manual
        DhcpReservation::create([
            'name' => 'poste01',
            'mac' => '00:11:22:33:44:55',
            'ip' => '10.0.0.10',
            'source' => 'manual',
        ]);

        $this->service->importFromLegacyFile($this->fixture, $this->logger());

        $reservation = DhcpReservation::where('name', 'poste01')->first();
        $this->assertNotNull($reservation);
        // AC9 : la source d'origine est préservée lors d'un update
        $this->assertSame('manual', $reservation->source);
    }

    #[Test]
    public function it_collects_errors_without_aborting_on_bad_mac(): void
    {
        $stats = $this->service->importFromLegacyFile($this->fixture, $this->logger());

        // posteBadMac doit générer une erreur, mais le reste est créé.
        $this->assertGreaterThan(0, count($stats['errors']));
        $this->assertGreaterThanOrEqual(3, $stats['created']);

        $hasBadMacError = false;
        foreach ($stats['errors'] as $err) {
            if (str_contains($err['reason'], 'MAC invalide')) {
                $hasBadMacError = true;
                break;
            }
        }
        $this->assertTrue($hasBadMacError, 'Une erreur MAC invalide doit être présente');
    }

    #[Test]
    public function it_skips_duplicate_mac_within_same_file(): void
    {
        $stats = $this->service->importFromLegacyFile($this->fixture, $this->logger());
        // poste99 a la même MAC que poste01 — doit être skipped
        $this->assertGreaterThanOrEqual(1, $stats['skipped']);
        $this->assertNull(DhcpReservation::where('name', 'poste99')->first());
    }

    #[Test]
    public function it_tolerates_whitespace_and_tab_variations(): void
    {
        // poste03 a des espaces variables et des sauts de ligne dans le bloc
        $this->service->importFromLegacyFile($this->fixture, $this->logger());
        $this->assertNotNull(DhcpReservation::where('name', 'poste03')->first());
    }

    #[Test]
    public function it_throws_runtime_exception_when_file_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->importFromLegacyFile('/nonexistent/path/reservations.inc', $this->logger());
    }
}
