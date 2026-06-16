<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Printer;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\PrintersStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Print\CupsPrinterService;
use App\Services\Print\Exceptions\CupsDaemonDownException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `PrintersStateProvider` — Story 27.2 (AC1, AC2, AC3).
 *
 * Mailles POSTE (salle physique + parc logique, PAS de relation UserGroup→Printer),
 * union sans précédence, payload v1 (connexion logique, jamais l'URI back-end),
 * défaut exclusif = réglé par WG (physique > logique, départage cups_name asc),
 * métadonnée CUPS lue en LECTURE SEULE (CUPS down = métadonnée vide, l'état reste
 * compilable), ZÉRO AD/APCu.
 */
class PrintersStateProviderTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    private WorkstationGroup $room;  // salle physique

    private WorkstationGroup $parc;  // parc logique

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Ciblage Postgres-pur — pas de synchro AD (host sans LDAP).
        WorkstationGroupObserver::disableSync();

        $this->ws = Workstation::factory()->create();
        $this->room = WorkstationGroup::factory()->create();          // is_physical = true
        $this->parc = WorkstationGroup::factory()->logical()->create(); // is_physical = false
        $this->ws->groups()->attach([$this->room->id, $this->parc->id]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    /**
     * Provider avec un CUPS factice qui renvoie une métadonnée fixe (ou lève
     * CupsDaemonDownException si $down).
     */
    private function provider(bool $down = false): PrintersStateProvider
    {
        $cups = $this->createMock(CupsPrinterService::class);
        if ($down) {
            $cups->method('getPrinter')->willThrowException(
                new CupsDaemonDownException('CUPS injoignable'),
            );
        } else {
            $cups->method('getPrinter')->willReturnCallback(
                fn (string $name): array => [
                    'name' => $name,
                    'uri' => 'socket://backend/'.$name, // NE DOIT JAMAIS fuiter dans le payload
                    'state' => 'idle',
                    'description' => 'Desc '.$name,
                    'location' => 'Loc '.$name,
                    'model' => null,
                    'jobs_count' => 0,
                ],
            );
        }

        return new PrintersStateProvider($cups);
    }

    private function attach(Printer $printer, WorkstationGroup $group, bool $isDefault = false): void
    {
        DB::table('printer_workstation_group')->insert([
            'cups_name' => $printer->cups_name,
            'workstation_group_id' => $group->id,
            'attached_at' => now(),
            'attached_by_user_id' => null,
            'is_default' => $isDefault,
        ]);
    }

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, $this->user);
    }

    #[Test]
    public function declares_frozen_type_and_constants(): void
    {
        $p = $this->provider();
        self::assertSame('printers', $p->type());
        self::assertSame(Printer::TYPE_PRINTERS, $p->type());
        self::assertSame(ResourceSemantics::Aggregate, $p->semantics());
        self::assertSame(StateScope::Session, $p->scope());
    }

    #[Test]
    public function unions_printers_of_physical_and_logical_mailles(): void
    {
        $impRoom = Printer::factory()->create();
        $impParc = Printer::factory()->create();
        $this->attach($impRoom, $this->room);
        $this->attach($impParc, $this->parc);

        $candidates = $this->provider()->itemsFor($this->ctx());

        self::assertCount(2, $candidates);
        $mailles = $candidates->map(fn (StateCandidate $c): string => $c->maille->value)->all();
        self::assertEqualsCanonicalizing([
            StateMaille::PhysicalGroup->value,
            StateMaille::LogicalGroup->value,
        ], $mailles);
    }

    #[Test]
    public function payload_carries_logical_connection_never_backend_uri(): void
    {
        $imp = Printer::factory()->create(['cups_name' => 'imp-test']);
        $this->attach($imp, $this->room);

        $payload = $this->provider()->itemsFor($this->ctx())->first()->payload;

        self::assertSame('imp-test', $payload['cups_name']);
        self::assertSame('\\\\<se4fs>\\imp-test', $payload['connection']);
        self::assertSame('Desc imp-test', $payload['description']);
        self::assertSame('Loc imp-test', $payload['location']);
        self::assertFalse($payload['is_default']);
        // L'URI back-end CUPS (socket://…) ne doit JAMAIS apparaître (décision n° 4).
        self::assertStringNotContainsString('socket://', json_encode($payload));
    }

    #[Test]
    public function default_logical_wins_over_physical(): void
    {
        // Story 27.3 (D-Q3) — INVERSION GLOBALE `logique > physique` : deux
        // imprimantes DISTINCTES, chacune défaut sur sa maille. Le LOGIQUE doit
        // désormais l'emporter (comportement CHANGÉ sciemment, pas régressé).
        $impPhys = Printer::factory()->create(['cups_name' => 'aphys']); // cups_name "petit" exprès
        $impLog = Printer::factory()->create(['cups_name' => 'zlog']);   // cups_name "grand" exprès
        $this->attach($impPhys, $this->room, isDefault: true);
        $this->attach($impLog, $this->parc, isDefault: true);

        $byName = $this->provider()->itemsFor($this->ctx())
            ->keyBy(fn (StateCandidate $c): string => $c->payload['cups_name']);

        // Le logique gagne MÊME si son cups_name est "plus grand" (la maille
        // prime sur le départage alphabétique).
        self::assertTrue($byName['zlog']->payload['is_default']);
        self::assertFalse($byName['aphys']->payload['is_default']);

        // UN SEUL is_default true dans toute la collection.
        $defaults = $this->provider()->itemsFor($this->ctx())
            ->filter(fn (StateCandidate $c): bool => $c->payload['is_default'] === true);
        self::assertCount(1, $defaults);
    }

    #[Test]
    public function default_tie_broken_by_cups_name_asc_within_same_maille(): void
    {
        // Deux défauts sur la MÊME maille (physique) → départage cups_name asc.
        $impB = Printer::factory()->create(['cups_name' => 'bbb']);
        $impA = Printer::factory()->create(['cups_name' => 'aaa']);
        $this->attach($impB, $this->room, isDefault: true);
        $this->attach($impA, $this->room, isDefault: true);

        $byName = $this->provider()->itemsFor($this->ctx())
            ->keyBy(fn (StateCandidate $c): string => $c->payload['cups_name']);

        self::assertTrue($byName['aaa']->payload['is_default'], 'aaa < bbb → gagne le départage');
        self::assertFalse($byName['bbb']->payload['is_default']);
    }

    #[Test]
    public function no_default_set_means_no_default_in_payload(): void
    {
        $imp1 = Printer::factory()->create();
        $imp2 = Printer::factory()->create();
        $this->attach($imp1, $this->room);
        $this->attach($imp2, $this->parc);

        $defaults = $this->provider()->itemsFor($this->ctx())
            ->filter(fn (StateCandidate $c): bool => $c->payload['is_default'] === true);

        self::assertCount(0, $defaults, 'aucun is_default réglé → aucun défaut dans le payload');
    }

    #[Test]
    public function printers_outside_poste_mailles_are_not_returned(): void
    {
        $otherGroup = WorkstationGroup::factory()->create();
        $imp = Printer::factory()->create();
        $this->attach($imp, $otherGroup);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function cups_down_yields_empty_metadata_but_still_serves_printer(): void
    {
        $imp = Printer::factory()->create(['cups_name' => 'imp-x']);
        $this->attach($imp, $this->room);

        $payload = $this->provider(down: true)->itemsFor($this->ctx())->first()->payload;

        // L'imprimante reste servie (connexion logique stable), métadonnée vide.
        self::assertSame('\\\\<se4fs>\\imp-x', $payload['connection']);
        self::assertSame('', $payload['description']);
        self::assertSame('', $payload['location']);
    }

    #[Test]
    public function machine_only_context_still_serves_poste_printers(): void
    {
        // L'imprimante est une ressource de POSTE : un contexte machine-only
        // (user null) reçoit quand même les imprimantes de ses mailles POSTE.
        $imp = Printer::factory()->create();
        $this->attach($imp, $this->room);

        $candidates = $this->provider()->itemsFor(TargetContext::for($this->ws, null));

        self::assertCount(1, $candidates);
    }
}
