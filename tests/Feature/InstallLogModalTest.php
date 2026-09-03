<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgentResourceState;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use App\Services\Windows\WorkstationLogReader;
use App\Services\Windows\WorkstationLogReadResult;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Tests Feature : composant Livewire install-log-modal
 *
 * Couverture :
 *   - Mount initial : $visible = false, log non chargé
 *   - open($statusId) avec workstation + log présent → $visible = true, contenu populé
 *   - open($statusId) avec workstation sans log_path → $installLogMissing = true
 *   - open($statusId) avec status inexistant → $installLogMissing = true
 *   - close() → tout reset, $visible = false
 */
class InstallLogModalTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('agent_resource_states');
            Schema::dropIfExists('workstation_application_status');
            Schema::dropIfExists('applications');
            Schema::dropIfExists('workstations');
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createTablesIfNeeded(): void
    {
        if (!Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('os')->nullable();
                $table->string('ip')->nullable();
                $table->string('mac')->nullable();
                $table->string('log_path')->nullable();
                $table->integer('status')->default(0);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $table) {
                $table->id();
                $table->string('app_id');
                $table->string('name')->nullable();
                $table->string('version')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('agent_resource_states')) {
            Schema::create('agent_resource_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workstation_id')->constrained('workstations')->cascadeOnDelete();
                $table->string('type');
                $table->string('status')->default('compliant');
                $table->string('hash')->nullable();
                $table->text('detail')->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_application_status')) {
            Schema::create('workstation_application_status', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workstation_id')->constrained('workstations')->cascadeOnDelete();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->string('status')->default('not-installed');
                $table->string('installed_version')->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->boolean('reboot_required')->default(false);
                $table->text('message')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }
    }

    private function makeWorkstation(string|null $logPath = null): Workstation
    {
        return Workstation::create([
            'name'     => 'PC-TEST-' . uniqid(),
            'log_path' => $logPath,
        ]);
    }

    private function makeApplication(): Application
    {
        return Application::create([
            'app_id'  => 'test-app-' . uniqid(),
            'name'    => 'TestApp',
            'version' => '1.0',
        ]);
    }

    private function makeStatus(Workstation $ws, Application $app, string $status = 'error'): WorkstationApplicationStatus
    {
        return WorkstationApplicationStatus::create([
            'workstation_id' => $ws->id,
            'application_id' => $app->id,
            'status'         => $status,
        ]);
    }

    private function makeAgentState(Workstation $ws, string $type, string $detail): AgentResourceState
    {
        return AgentResourceState::create([
            'workstation_id' => $ws->id,
            'type'           => $type,
            'status'         => 'error',
            'hash'           => str_repeat('a', 64),
            'detail'         => $detail,
            'reported_at'    => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_mount_initial_state_is_hidden_and_no_log(): void
    {
        // Le service ne doit jamais être appelé au mount
        $mockReader = Mockery::mock(WorkstationLogReader::class);
        $mockReader->shouldNotReceive('read');
        $this->app->instance(WorkstationLogReader::class, $mockReader);

        $component = Livewire::test('components::organisms.install-log-modal');

        $component->assertSet('visible', false)
                  ->assertSet('statusId', null)
                  ->assertSet('installLogContent', null)
                  ->assertSet('installLogMissing', false);
    }

    public function test_open_with_existing_status_and_log(): void
    {
        $ws  = $this->makeWorkstation('/some/path/PC-TEST.log');
        $app = $this->makeApplication();
        $st  = $this->makeStatus($ws, $app, 'error');

        $fakeContent = "2026-04-14 10:32:15, DEBUG  : Installation OK\n";
        $fakeResult  = new WorkstationLogReadResult(
            content: $fakeContent,
            missing: false,
            truncated: false,
            filename: 'PC-TEST.log',
        );

        $mockReader = Mockery::mock(WorkstationLogReader::class);
        $mockReader->shouldReceive('read')
                   ->once()
                   ->andReturn($fakeResult);
        $this->app->instance(WorkstationLogReader::class, $mockReader);

        Livewire::test('components::organisms.install-log-modal')
            ->dispatch('open-install-log-modal', statusId: $st->id)
            ->assertSet('visible', true)
            ->assertSet('installLogMissing', false)
            ->assertSet('installLogContent', $fakeContent)
            ->assertSet('installLogTruncated', false)
            ->assertSet('installLogFilename', 'PC-TEST.log');
    }

    public function test_open_with_workstation_without_log_path(): void
    {
        $ws  = $this->makeWorkstation(null);
        $app = $this->makeApplication();
        $st  = $this->makeStatus($ws, $app, 'error');

        $missingResult = new WorkstationLogReadResult(
            content: null,
            missing: true,
            truncated: false,
            filename: null,
        );

        $mockReader = Mockery::mock(WorkstationLogReader::class);
        $mockReader->shouldReceive('read')
                   ->once()
                   ->andReturn($missingResult);
        $this->app->instance(WorkstationLogReader::class, $mockReader);

        Livewire::test('components::organisms.install-log-modal')
            ->dispatch('open-install-log-modal', statusId: $st->id)
            ->assertSet('visible', true)
            ->assertSet('installLogMissing', true)
            ->assertSet('installLogContent', null);
    }

    public function test_open_with_nonexistent_status_sets_missing(): void
    {
        $mockReader = Mockery::mock(WorkstationLogReader::class);
        $mockReader->shouldNotReceive('read');
        $this->app->instance(WorkstationLogReader::class, $mockReader);

        Livewire::test('components::organisms.install-log-modal')
            ->dispatch('open-install-log-modal', statusId: 99999)
            ->assertSet('visible', true)
            ->assertSet('installLogMissing', true)
            ->assertSet('installLogContent', null);
    }

    public function test_close_resets_all_state(): void
    {
        $ws  = $this->makeWorkstation('/path/PC-CLOSE.log');
        $app = $this->makeApplication();
        $st  = $this->makeStatus($ws, $app, 'error');

        $fakeResult = new WorkstationLogReadResult(
            content: "some log content\n",
            missing: false,
            truncated: false,
            filename: 'PC-CLOSE.log',
        );

        $mockReader = Mockery::mock(WorkstationLogReader::class);
        $mockReader->shouldReceive('read')->once()->andReturn($fakeResult);
        $this->app->instance(WorkstationLogReader::class, $mockReader);

        Livewire::test('components::organisms.install-log-modal')
            ->dispatch('open-install-log-modal', statusId: $st->id)
            ->assertSet('visible', true)
            ->call('close')
            ->assertSet('visible', false)
            ->assertSet('statusId', null)
            ->assertSet('installLogContent', null)
            ->assertSet('installLogMissing', false)
            ->assertSet('installLogTruncated', false)
            ->assertSet('installLogFilename', null);
    }

    // ------------------------------------------------------------------
    // Ouverture depuis « État rapporté par type » (onglet Agent)
    // ------------------------------------------------------------------

    /**
     * Le tableau tronque le détail à 80 caractères — or c'est là que se trouve la
     * liste des paquets en échec. La modale doit le rendre en entier.
     */
    public function test_agent_state_opens_with_full_detail_and_workstation_log(): void
    {
        $ws = $this->makeWorkstation('/some/path/PC-TEST.log');
        $detail = 'WPKG déclenché mais apps non installées après le run : '
            . implode(', ', array_map(fn (int $i): string => "paquet-{$i}", range(1, 20)));
        $state = $this->makeAgentState($ws, 'applications', $detail);

        $fakeContent = "2026-09-03 10:32:15, ERROR : installation refusée\n";
        $mockReader = Mockery::mock(WorkstationLogReader::class);
        $mockReader->shouldReceive('read')
                   ->once()
                   ->andReturn(new WorkstationLogReadResult(
                       content: $fakeContent,
                       missing: false,
                       truncated: false,
                       filename: 'PC-TEST.log',
                   ));
        $this->app->instance(WorkstationLogReader::class, $mockReader);

        Livewire::test('components::organisms.install-log-modal')
            ->dispatch('open-agent-state-modal', stateId: $state->id)
            ->assertSet('visible', true)
            ->assertSet('showLog', true)
            ->assertSet('reportedDetail', $detail)
            ->assertSet('installLogContent', $fakeContent);
    }

    /**
     * Un type qui ne passe pas par WPKG n'a pas de log de poste : afficher un bloc
     * « non disponible » le ferait lire comme une anomalie.
     */
    public function test_a_non_wpkg_agent_state_shows_no_log_block(): void
    {
        $ws = $this->makeWorkstation('/some/path/PC-TEST.log');
        $state = $this->makeAgentState($ws, 'wallpaper', 'hash inattendu');

        $mockReader = Mockery::mock(WorkstationLogReader::class);
        $mockReader->shouldNotReceive('read');
        $this->app->instance(WorkstationLogReader::class, $mockReader);

        Livewire::test('components::organisms.install-log-modal')
            ->dispatch('open-agent-state-modal', stateId: $state->id)
            ->assertSet('visible', true)
            ->assertSet('showLog', false)
            ->assertSet('reportedDetail', 'hash inattendu')
            ->assertSet('installLogContent', null);
    }

    public function test_closing_an_agent_state_modal_resets_its_own_state(): void
    {
        $ws = $this->makeWorkstation(null);
        $state = $this->makeAgentState($ws, 'wallpaper', 'hash inattendu');

        Livewire::test('components::organisms.install-log-modal')
            ->dispatch('open-agent-state-modal', stateId: $state->id)
            ->assertSet('reportedDetail', 'hash inattendu')
            ->call('close')
            ->assertSet('visible', false)
            ->assertSet('reportedDetail', null)
            ->assertSet('showLog', true);
    }
}
