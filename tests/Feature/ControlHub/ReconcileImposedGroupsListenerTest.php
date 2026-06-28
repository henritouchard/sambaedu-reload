<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\LockReason;
use App\Models\ControlHubContract;
use App\Models\WorkstationGroup;
use App\Services\ControlHub\ControlHubContractIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 30.3 — Déclenchement automatique de la réconciliation (AC #5) via le listener
 * abonné à `ControlHubContractChanged`, et commande artisan de réconciliation manuelle.
 *
 * ⚠️ On NE fait PAS `Event::fake()` ici : on veut que le listener réel s'exécute.
 * `Queue::fake()` neutralise la synchro AD (observer).
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM.
 */
class ReconcileImposedGroupsListenerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
    }

    private function ingestionService(): ControlHubContractIngestionService
    {
        return new ControlHubContractIngestionService();
    }

    // ── AC #5 — Déclenchement automatique à la réception du contrat ───────────

    #[Test]
    public function ingesting_imposed_groups_triggers_the_listener_and_creates_the_groups(): void
    {
        // Aucun groupe avant ingestion.
        self::assertNull(WorkstationGroup::findByName('parc-terminales'));

        $this->ingestionService()->ingest([
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'salle-info'],
            ],
        ]);

        // Le listener (abonné à ControlHubContractChanged, émis après commit) a
        // déclenché la réconciliation → le WorkstationGroup imposé existe.
        $group = WorkstationGroup::findByName('parc-terminales');
        self::assertNotNull($group);
        self::assertTrue($group->managed_by_control_hub);
        self::assertSame(LockReason::CONTROL_HUB->value, $group->locked);
        self::assertSame('salle-info', $group->controlhub_label);
    }

    #[Test]
    public function re_ingesting_without_a_previously_imposed_group_releases_its_lock_via_the_listener(): void
    {
        // 1re ingestion : le contrat impose G → le listener crée G verrouillé.
        $this->ingestionService()->ingest([
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'salle-info'],
            ],
        ]);

        $group = WorkstationGroup::findByName('parc-terminales');
        self::assertNotNull($group);
        self::assertTrue($group->managed_by_control_hub);
        self::assertSame(LockReason::CONTROL_HUB->value, $group->locked);

        // 2e ingestion SANS G (prune) → mutation → ControlHubContractChanged →
        // listener → réconciliation → levée du verrou amont, SANS suppression.
        $this->ingestionService()->ingest([
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
            ],
            'imposed_groups' => [],
        ]);

        $group = WorkstationGroup::findByName('parc-terminales');
        self::assertNotNull($group, 'Le groupe non-imposé ne doit PAS être supprimé (levée du verrou seulement).');
        self::assertNull($group->locked);
        self::assertFalse($group->managed_by_control_hub);
    }

    #[Test]
    public function ingestion_without_imposed_groups_creates_no_group(): void
    {
        $this->ingestionService()->ingest([
            'labels' => [
                ['name' => 'nomade', 'mode' => 'free'],
            ],
        ]);

        // Le listener s'exécute (event émis car mutation) mais la réconciliation
        // est un no-op fonctionnel : aucun groupe imposé à garantir.
        self::assertSame(0, WorkstationGroup::query()->count());
    }

    // ── Task 4 — Commande artisan de réconciliation manuelle ─────────────────

    #[Test]
    public function artisan_command_reconciles_when_a_contract_is_active(): void
    {
        $contract = ControlHubContract::factory()->create();
        \App\Models\ControlHubContractImposedGroup::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'name' => 'bureau_direction',
        ]);

        $this->artisan('controlhub:reconcile-imposed-groups')->assertExitCode(0);

        self::assertNotNull(WorkstationGroup::findByName('bureau_direction'));
    }

    #[Test]
    public function artisan_command_is_a_no_op_without_active_contract(): void
    {
        self::assertNull(ControlHubContract::active());

        $this->artisan('controlhub:reconcile-imposed-groups')
            ->expectsOutputToContain('Aucun contrat amont actif')
            ->assertExitCode(0);

        self::assertSame(0, WorkstationGroup::query()->count());
    }
}
