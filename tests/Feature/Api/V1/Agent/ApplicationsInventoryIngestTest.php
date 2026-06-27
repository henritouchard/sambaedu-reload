<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Models\AgentApplicationInventory;
use App\Models\AgentResourceState;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.5 — AC4 : ingestion de l'inventaire PAR APP (champ additif
 * `inventory` sur l'item `applications` du rapport agent → serveur).
 *
 * Vérifie : upsert des lignes `agent_application_inventory` (clé
 * `(workstation_id, app_id)`) EN PLUS de la ligne d'état par type (inchangée),
 * nettoyage level-triggered des apps absentes du rapport, et que le VERDICT du
 * type reste PAR TYPE (grain 27.8 intact — l'inventaire est une donnée, pas un
 * verdict).
 */
final class ApplicationsInventoryIngestTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT_ROUTE = '/api/v1/agent/report';

    private TokenRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TokenRotationService::class);
    }

    /** @param array<string, mixed> $payload */
    private function report(string $token, array $payload): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson(self::REPORT_ROUTE, $payload);
    }

    /** @return array{0: Workstation, 1: string} */
    private function enrolledWorkstation(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        return [$ws->refresh(), $token];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(Workstation $ws, array $items): array
    {
        return [
            'schema' => 'se5.desired-state/v1',
            'generated_at' => now()->toIso8601String(),
            'agent_version' => '2.2.13',
            'workstation' => ['hostname' => $ws->name, 'uuid' => $ws->uuid],
            'items' => $items,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $inventory
     * @return array<string, mixed>
     */
    private function applicationsItem(string $status, array $inventory): array
    {
        $item = [
            'type' => 'applications',
            'status' => $status,
            'hash' => str_repeat('a', 64),
            'inventory' => $inventory,
        ];

        // Règle `items.*.detail required_if status,error` (ReportRequest §6) :
        // un item au verdict `error` doit porter un `detail` top-level non vide.
        if ($status === 'error') {
            $item['detail'] = 'au moins une application en échec';
        }

        return $item;
    }

    #[Test]
    public function report_upserts_inventory_rows_and_a_single_type_state(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();

        $this->report($token, $this->payload($ws, [
            $this->applicationsItem('drift', [
                ['app_id' => 'firefox', 'status' => 'compliant'],
                ['app_id' => 'vlc', 'status' => 'error', 'detail' => 'installeur en échec'],
            ]),
        ]))->assertOk();

        // Deux lignes d'inventaire (une par app).
        self::assertSame(2, AgentApplicationInventory::query()->where('workstation_id', $ws->id)->count());
        $firefox = AgentApplicationInventory::query()
            ->where('workstation_id', $ws->id)->where('app_id', 'firefox')->first();
        self::assertNotNull($firefox);
        self::assertSame('compliant', $firefox->status->value);

        // UNE seule ligne d'état PAR TYPE (verdict par type, grain 27.8 intact).
        $states = AgentResourceState::query()->where('workstation_id', $ws->id)->get();
        self::assertCount(1, $states);
        self::assertSame('applications', $states[0]->type);
        self::assertSame('drift', $states[0]->status->value);
    }

    #[Test]
    public function inventory_is_level_triggered_absent_app_is_cleaned_up(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();

        // 1er rapport : firefox + vlc.
        $this->report($token, $this->payload($ws, [
            $this->applicationsItem('drift', [
                ['app_id' => 'firefox', 'status' => 'compliant'],
                ['app_id' => 'vlc', 'status' => 'compliant'],
            ]),
        ]))->assertOk();
        self::assertSame(2, AgentApplicationInventory::query()->where('workstation_id', $ws->id)->count());

        // 2e rapport : vlc désassigné (absent de l'inventaire) → sa ligne est
        // nettoyée (n'occupe plus de siège — level-triggered).
        $this->report($token, $this->payload($ws, [
            $this->applicationsItem('compliant', [
                ['app_id' => 'firefox', 'status' => 'compliant'],
            ]),
        ]))->assertOk();

        $rows = AgentApplicationInventory::query()->where('workstation_id', $ws->id)->pluck('app_id')->all();
        self::assertSame(['firefox'], $rows);
    }

    #[Test]
    public function inventory_status_is_refreshed_on_each_report(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();

        $this->report($token, $this->payload($ws, [
            $this->applicationsItem('error', [
                ['app_id' => 'firefox', 'status' => 'error', 'detail' => 'absent'],
            ]),
        ]))->assertOk();
        self::assertSame(
            'error',
            AgentApplicationInventory::query()->where('workstation_id', $ws->id)->where('app_id', 'firefox')->first()->status->value,
        );

        // L'app s'installe au cycle suivant → statut rafraîchi à compliant.
        $this->report($token, $this->payload($ws, [
            $this->applicationsItem('compliant', [
                ['app_id' => 'firefox', 'status' => 'compliant'],
            ]),
        ]))->assertOk();
        self::assertSame(
            'compliant',
            AgentApplicationInventory::query()->where('workstation_id', $ws->id)->where('app_id', 'firefox')->first()->status->value,
        );
        // Toujours une seule ligne (upsert, pas d'accumulation).
        self::assertSame(1, AgentApplicationInventory::query()->where('workstation_id', $ws->id)->count());
    }

    #[Test]
    public function report_without_inventory_field_is_accepted_and_creates_no_rows(): void
    {
        // Un autre type (ou un agent antérieur sans `inventory`) ne crée aucune
        // ligne d'inventaire — champ additif optionnel (forward-compat §9).
        [$ws, $token] = $this->enrolledWorkstation();

        $this->report($token, $this->payload($ws, [
            ['type' => 'wallpaper', 'status' => 'compliant', 'hash' => str_repeat('b', 64)],
        ]))->assertOk();

        self::assertSame(0, AgentApplicationInventory::query()->where('workstation_id', $ws->id)->count());
    }

    #[Test]
    public function applications_type_without_inventory_key_purges_all_existing_rows(): void
    {
        // Un item `type=applications` SANS clé `inventory` (agent antérieur ou
        // bug agent) déclenche `ingestApplicationsInventory($ws, [])` → purge
        // TOTALE des lignes existantes (level-triggered : 0 app rapportée = 0
        // siège). Ce branchement dangereux doit être exercé explicitement.
        [$ws, $token] = $this->enrolledWorkstation();

        // Pré-peupler deux lignes d'inventaire.
        $this->report($token, $this->payload($ws, [
            $this->applicationsItem('compliant', [
                ['app_id' => 'firefox', 'status' => 'compliant'],
                ['app_id' => 'vlc', 'status' => 'compliant'],
            ]),
        ]))->assertOk();
        self::assertSame(2, AgentApplicationInventory::query()->where('workstation_id', $ws->id)->count());

        // Rapport `type=applications` sans clé `inventory` (null) → purge totale.
        $this->report($token, $this->payload($ws, [
            ['type' => 'applications', 'status' => 'compliant', 'hash' => str_repeat('c', 64)],
        ]))->assertOk();

        self::assertSame(
            0,
            AgentApplicationInventory::query()->where('workstation_id', $ws->id)->count(),
            'Un rapport applications sans clé inventory doit purger toutes les lignes existantes (level-triggered)',
        );
    }

    #[Test]
    public function applications_with_empty_inventory_array_purges_all_existing_rows(): void
    {
        // inventory:[] (tableau vide explicite) → purge totale (level-triggered).
        // Complémentaire au cas ci-dessus (clé absente vs tableau vide).
        [$ws, $token] = $this->enrolledWorkstation();

        $this->report($token, $this->payload($ws, [
            $this->applicationsItem('compliant', [
                ['app_id' => 'firefox', 'status' => 'compliant'],
            ]),
        ]))->assertOk();
        self::assertSame(1, AgentApplicationInventory::query()->where('workstation_id', $ws->id)->count());

        $this->report($token, $this->payload($ws, [
            $this->applicationsItem('compliant', []),
        ]))->assertOk();

        self::assertSame(
            0,
            AgentApplicationInventory::query()->where('workstation_id', $ws->id)->count(),
            'inventory:[] doit purger toutes les lignes (level-triggered : 0 app = 0 siège)',
        );
    }

    #[Test]
    public function golden_report_with_inventory_is_accepted_verbatim(): void
    {
        // Non-régression contrat : le golden FIGÉ (qui illustre l'inventaire)
        // passe la validation et l'ingestion.
        [$ws, $token] = $this->enrolledWorkstation();
        $golden = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Agent/report.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $golden['workstation'] = ['hostname' => $ws->name, 'uuid' => $ws->uuid];

        $this->report($token, $golden)->assertOk()->assertJson(['success' => true]);

        // L'inventaire du golden (firefox compliant, vlc error) est ingéré.
        self::assertSame(2, AgentApplicationInventory::query()->where('workstation_id', $ws->id)->count());
    }
}
