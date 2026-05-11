<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Jobs\DispatchMachinePowerActionJob;
use App\Models\MachineBootLog;
use App\Models\MachinePowerActionTask;
use App\Models\Workstation;
use App\Services\Parc\MachinePowerService;
use App\Services\Parc\RemoteAccessService;
use App\Services\Parc\WorkstationGroupService;
use App\Services\WorkstationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Tests Feature du composant Livewire `pages::parc.machines.[id].index`.
 *
 * Couvre les ACs de la story 4-2 :
 *   AC2 — feedback immédiat (statusRunning = true dès le call executeMachinePowerAction)
 *   AC3 — polling readiness détecte la machine up → toast succès + arrêt du poll
 *   AC4 — timeout après MACHINE_READINESS_TIMEOUT_SECONDS (120s par défaut)
 *   AC5 — erreur synchrone (MAC invalide, machine non trouvée) → pas de polling résiduel
 *   AC6 — extinction forcée dispatchée avec $force=true et loggée comme shutdown-force
 *   Idempotence du poll quand aucune action n'est en cours.
 */
class MachineShowPageTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        // L'environnement de test minimal (phpunit.xml) ne définit pas APP_KEY.
        // Livewire chiffre son snapshot (cookie/CSRF) via le service encrypter
        // dès le rendu de la vue du composant, d'où le besoin d'une clé valide.
        // Valeur jetable, strictement locale à la suite de test.
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('wpkg_workstation_options');
            Schema::dropIfExists('machine_power_action_tasks');
            Schema::dropIfExists('machine_boot_logs');
            Schema::dropIfExists('workstation_group_workstation');
            Schema::dropIfExists('workstation_application_status');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('workstations');
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function createTablesIfNeeded(): void
    {
        if (!Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('os')->nullable();
                $table->string('ip')->nullable();
                $table->string('mac')->nullable();
                $table->integer('status')->default(0);
                $table->unsignedBigInteger('physical_room_id')->nullable();
                $table->timestamp('last_report_at')->nullable();
                $table->timestamp('date_rapport_poste')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_physical')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('locked')->nullable();
                $table->text('description')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->string('app_profile_name')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $table) {
                $table->foreignId('workstation_id')->constrained('workstations')->cascadeOnDelete();
                $table->foreignId('workstation_group_id')->constrained('workstation_groups')->cascadeOnDelete();
                $table->boolean('physical')->default(false);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('machine_boot_logs')) {
            Schema::create('machine_boot_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workstation_id')->nullable();
                $table->string('machine_name');
                $table->string('action')->nullable();
                $table->string('initiated_by')->nullable();
                $table->boolean('success')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('stopped_at')->nullable();
                $table->string('os')->nullable();
                $table->integer('wol_score')->nullable();
                $table->integer('ipxe_score')->nullable();
                $table->integer('error_flags')->nullable();
                $table->integer('boot_speed')->nullable();
                $table->string('vlan')->nullable();
                $table->string('switch_port')->nullable();
                $table->string('switch_ip')->nullable();
                $table->string('switch_name')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_application_status')) {
            Schema::create('workstation_application_status', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workstation_id')->constrained('workstations')->cascadeOnDelete();
                $table->unsignedBigInteger('application_id');
                $table->string('status')->default('not-installed');
                $table->string('installed_version')->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('machine_power_action_tasks')) {
            Schema::create('machine_power_action_tasks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workstation_id')->nullable();
                $table->string('action', 32);
                $table->string('status', 16)->default('queued');
                $table->string('initiated_by', 100)->nullable();
                $table->timestamp('initiated_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('result')->nullable();
                $table->text('error_message')->nullable();
                $table->string('restart_phase', 16)->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        // Story 15.4 — la fiche machine lit `$workstation->wpkgOptions()` au render.
        if (!Schema::hasTable('wpkg_workstation_options')) {
            Schema::create('wpkg_workstation_options', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workstation_id');
                $table->string('option_key', 64);
                $table->string('option_value', 255);
                $table->timestamps();
            });
            $this->createdTables = true;
        }
    }

    private function makeWorkstation(): Workstation
    {
        return Workstation::create([
            'name' => 'pc-lab-01',
            'os' => 'Windows 10',
            'ip' => '127.0.0.1',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 1,
        ]);
    }

    private function mockGroupService(Workstation $ws): WorkstationGroupService
    {
        $mock = Mockery::mock(WorkstationGroupService::class);
        // Répondre à plusieurs appels (mount + rehydration éventuelle).
        $mock->shouldReceive('getWorkstation')->andReturn($ws);
        $mock->shouldReceive('getPhysicalRooms')->andReturn(collect());
        $mock->shouldReceive('getAvailableMachineActions')->andReturn([
            ['key' => 'wake', 'label' => 'Allumer', 'icon' => 'fa-solid fa-power-off', 'requires_confirmation' => false],
            ['key' => 'shutdown', 'label' => 'Éteindre', 'icon' => 'fa-solid fa-stop', 'requires_confirmation' => true],
            ['key' => 'shutdown-force', 'label' => "Forcer l'extinction", 'icon' => 'fa-solid fa-triangle-exclamation', 'requires_confirmation' => true],
            ['key' => 'restart', 'label' => 'Redémarrer', 'icon' => 'fa-solid fa-rotate-right', 'requires_confirmation' => true],
            ['key' => 'remote', 'label' => 'Accès distant', 'icon' => 'fa-solid fa-desktop', 'requires_confirmation' => false],
        ]);
        $mock->shouldReceive('getMachineActionLabel')->andReturnUsing(fn (string $a) => match ($a) {
            'wake' => 'allumage',
            'shutdown' => 'extinction',
            'shutdown-force' => 'extinction forcée',
            'restart' => 'redémarrage',
            'remote' => 'accès distant',
            default => $a,
        });

        $this->app->instance(WorkstationGroupService::class, $mock);
        return $mock;
    }

    private function bindPowerServiceMock(): MachinePowerService
    {
        $mock = Mockery::mock(MachinePowerService::class);
        $this->app->instance(MachinePowerService::class, $mock);
        return $mock;
    }

    // ─── Tests ──────────────────────────────────────────────────────────────
    //
    // Depuis la correction review #1 (2026-04-20), executeMachinePowerAction()
    // ne passe PLUS par WorkstationGroupService::executeMachineAction. Il crée
    // une ligne MachinePowerActionTask + dispatche un DispatchMachinePowerActionJob.
    // Les tests assertent donc sur l'état DB + Queue::fake() plutôt que sur le
    // mock du service.

    public function test_wake_action_emits_toast_and_starts_polling(): void
    {
        // AC2 — statusRunning doit passer à true immédiatement, un toast succès
        // est émis, et une MachinePowerActionTask est créée + un job dispatché.
        Queue::fake();

        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);
        $this->bindPowerServiceMock();

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('executeMachinePowerAction', 'wake')
            ->assertSet('statusRunning', true)
            ->assertSet('runningAction', 'wake')
            ->assertDispatched('toastMagic', status: 'success');

        $task = MachinePowerActionTask::where('workstation_id', $ws->id)
            ->where('action', 'wake')
            ->first();
        $this->assertNotNull($task, 'Une task MachinePowerActionTask doit avoir été créée.');
        $this->assertEquals(MachinePowerActionTask::STATUS_QUEUED, $task->status);
        $this->assertNull($task->restart_phase, 'wake ne doit pas initialiser restart_phase.');

        Queue::assertPushed(DispatchMachinePowerActionJob::class, fn ($job) => $job->taskId === $task->id);
    }

    public function test_restart_action_initializes_waiting_down_phase(): void
    {
        // Review #2 — une action restart doit créer la task avec
        // restart_phase = 'waiting-down' pour que le polling attende
        // d'abord que la machine cesse de répondre avant de chercher son retour.
        Queue::fake();

        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);
        $this->bindPowerServiceMock();

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('executeMachinePowerAction', 'restart')
            ->assertSet('statusRunning', true)
            ->assertSet('runningAction', 'restart');

        $task = MachinePowerActionTask::where('workstation_id', $ws->id)
            ->where('action', 'restart')
            ->first();
        $this->assertNotNull($task);
        $this->assertEquals(MachinePowerActionTask::RESTART_PHASE_WAITING_DOWN, $task->restart_phase);
    }

    public function test_restart_polling_transitions_from_waiting_down_to_waiting_up(): void
    {
        // Review #2 — on simule le ping qui retourne false (machine éteinte) :
        // la task doit passer de 'waiting-down' à 'waiting-up' SANS émettre
        // de toast succès (le redémarrage n'est pas encore terminé).
        Queue::fake();

        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);
        $power = $this->bindPowerServiceMock();

        $component = Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('executeMachinePowerAction', 'restart');

        $task = MachinePowerActionTask::where('workstation_id', $ws->id)->first();
        $this->assertNotNull($task);

        // Marquer la task running (le job aurait fait ça en prod).
        $task->update(['status' => MachinePowerActionTask::STATUS_RUNNING]);

        // Machine ne répond plus → transition waiting-down → waiting-up.
        $power->shouldReceive('ping')->once()->andReturn(false);

        $component->call('pollMachineReadiness')
            ->assertSet('statusRunning', true)
            ->assertNotDispatched('toastMagic', status: 'success');

        $task->refresh();
        $this->assertEquals(MachinePowerActionTask::RESTART_PHASE_WAITING_UP, $task->restart_phase);
    }

    public function test_restart_polling_completes_when_machine_reboots(): void
    {
        // Review #2 — depuis 'waiting-up', dès que le ping détecte un OS
        // (machine de retour), la task passe completed, toast succès,
        // polling stoppé.
        Queue::fake();

        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);
        $power = $this->bindPowerServiceMock();

        $component = Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('executeMachinePowerAction', 'restart');

        $task = MachinePowerActionTask::where('workstation_id', $ws->id)->first();
        $task->update([
            'status' => MachinePowerActionTask::STATUS_RUNNING,
            'restart_phase' => MachinePowerActionTask::RESTART_PHASE_WAITING_UP,
        ]);

        $power->shouldReceive('ping')->once()->andReturn('linux');

        $component->call('pollMachineReadiness')
            ->assertSet('statusRunning', false)
            ->assertSet('runningAction', null)
            ->assertDispatched('toastMagic', status: 'success');

        $task->refresh();
        $this->assertEquals(MachinePowerActionTask::STATUS_COMPLETED, $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_poll_readiness_detects_machine_online_and_stops_polling(): void
    {
        // AC3 — quand la machine est up (ping retourne 'linux'/'windows'),
        // statusRunning repasse à false, task marquée completed, toast succès.
        Queue::fake();

        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);
        $power = $this->bindPowerServiceMock();
        $power->shouldReceive('ping')->andReturn('linux');

        $component = Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('executeMachinePowerAction', 'wake')
            ->assertSet('statusRunning', true);

        $task = MachinePowerActionTask::where('workstation_id', $ws->id)->first();
        $task->update(['status' => MachinePowerActionTask::STATUS_RUNNING]);

        $component->call('pollMachineReadiness')
            ->assertSet('statusRunning', false)
            ->assertSet('runningAction', null)
            ->assertDispatched('toastMagic', status: 'success');

        $task->refresh();
        $this->assertEquals(MachinePowerActionTask::STATUS_COMPLETED, $task->status);
    }

    public function test_poll_readiness_times_out_after_configured_duration(): void
    {
        // AC4 — au-delà du timeout (120s par défaut), le poll arrête + toast warning
        // + ligne `machine_boot_logs` avec error_flags=1 + task marquée failed.
        Queue::fake();
        Config::set('parc.machine_readiness_timeout_seconds', 120);

        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);

        $power = $this->bindPowerServiceMock();
        // Le ping ne DOIT PAS être appelé : le timeout est détecté AVANT que
        // pollMachineReadiness() ne tente un ping. Enforcer l'invariant évite
        // que le test passe à tort si un bug réintroduisait un ping inutile.
        $power->shouldNotReceive('ping');
        $power->shouldReceive('logReadinessTimeout')
            ->with('pc-lab-01', 'wake')
            ->once();

        $start = Carbon::parse('2026-04-20 10:00:00');
        Carbon::setTestNow($start);

        $component = Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('executeMachinePowerAction', 'wake')
            ->assertSet('statusRunning', true);

        $task = MachinePowerActionTask::where('workstation_id', $ws->id)->first();
        $this->assertNotNull($task);

        // +121s → on a dépassé le timeout de 120s.
        Carbon::setTestNow($start->copy()->addSeconds(121));

        $component
            ->call('pollMachineReadiness')
            ->assertSet('statusRunning', false)
            ->assertDispatched('toastMagic', status: 'warning');

        $task->refresh();
        $this->assertEquals(MachinePowerActionTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('timeout', strtolower((string) $task->error_message));
    }

    public function test_poll_readiness_is_noop_when_no_action_in_progress(): void
    {
        // Idempotence : si statusRunning=false (page rechargée ou action terminée),
        // pollMachineReadiness() est un no-op strict (pas de toast, pas d'appel ping).
        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);

        $power = $this->bindPowerServiceMock();
        $power->shouldNotReceive('ping');
        $power->shouldNotReceive('logReadinessTimeout');

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->assertSet('statusRunning', false)
            ->call('pollMachineReadiness')
            ->assertSet('statusRunning', false)
            ->assertNotDispatched('toastMagic');
    }

    public function test_poll_readiness_stops_when_task_is_marked_failed(): void
    {
        // Review #1 — si le job async a marqué la task comme failed (ex: MAC
        // invalide, exception), le polling doit couper immédiatement en
        // affichant le error_message remonté par le job.
        Queue::fake();

        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);
        $power = $this->bindPowerServiceMock();
        // La tâche est déjà en échec quand le polling se réveille → pas de ping.
        $power->shouldNotReceive('ping');

        $component = Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('executeMachinePowerAction', 'wake')
            ->assertSet('statusRunning', true);

        $task = MachinePowerActionTask::where('workstation_id', $ws->id)->first();
        $task->update([
            'status' => MachinePowerActionTask::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => 'Pas d\'adresse MAC enregistrée pour cette machine',
        ]);

        $component->call('pollMachineReadiness')
            ->assertSet('statusRunning', false)
            ->assertDispatched('toastMagic', status: 'error');
    }

    public function test_dispatch_blocked_while_action_in_progress(): void
    {
        // Review #14 — un second click alors que statusRunning est true doit
        // être ignoré (toast warning + pas de nouvelle task).
        Queue::fake();

        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);
        $this->bindPowerServiceMock();

        $component = Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('executeMachinePowerAction', 'wake')
            ->assertSet('statusRunning', true);

        // Deuxième click (shutdown) pendant que wake est en cours.
        $component->call('executeMachinePowerAction', 'shutdown')
            ->assertSet('runningAction', 'wake') // inchangé
            ->assertDispatched('toastMagic', status: 'warning');

        // Une seule task créée.
        $this->assertEquals(1, MachinePowerActionTask::count());
    }

    public function test_shutdown_force_action_creates_task_with_correct_action(): void
    {
        // AC6 — le bouton "Forcer l'extinction" crée une task avec
        // action='shutdown-force' (et non 'shutdown').
        Queue::fake();

        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);
        $this->bindPowerServiceMock();

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('executeMachinePowerAction', 'shutdown-force')
            ->assertSet('statusRunning', true)
            ->assertSet('runningAction', 'shutdown-force')
            ->assertDispatched('toastMagic', status: 'success');

        $task = MachinePowerActionTask::first();
        $this->assertNotNull($task);
        $this->assertEquals('shutdown-force', $task->action);
        $this->assertEquals(MachinePowerActionTask::STATUS_QUEUED, $task->status);
    }

    public function test_invalid_argument_exception_shows_error_toast_and_stops_polling(): void
    {
        // AC5 cas extrême : action non supportée — le composant valide en amont
        // et throw InvalidArgumentException, proprement récupérée.
        Queue::fake();

        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);
        $this->bindPowerServiceMock();

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('executeMachinePowerAction', 'nope')
            ->assertSet('statusRunning', false)
            ->assertDispatched('toastMagic', status: 'error');

        // Pas de task créée pour une action invalide.
        $this->assertEquals(0, MachinePowerActionTask::count());
    }
}
