<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Jobs\DispatchMachinePowerActionJob;
use App\Models\MachineBootLog;
use App\Models\MachinePowerActionTask;
use App\Enums\AgentResourceStatus;
use App\Models\AgentReportEvent;
use App\Models\AgentResourceState;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use App\Services\Agent\Reporting\ReportIngestService;
use App\Services\Parc\MachinePowerService;
use App\Services\Parc\RemoteAccessService;
use App\Services\Parc\WorkstationGroupService;
use App\Services\WorkstationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use App\Wpkg\Deployment\Events\WorkstationOptionsChanged;
use App\Wpkg\Deployment\Models\WpkgWorkstationOption;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
            Schema::dropIfExists('agent_report_events');
            Schema::dropIfExists('agent_resource_states');
            Schema::dropIfExists('application_workstation_group');
            Schema::dropIfExists('application_workstation');
            Schema::dropIfExists('app_profile_workstation_group');
            Schema::dropIfExists('app_profile_workstation');
            Schema::dropIfExists('app_profiles');
            Schema::dropIfExists('applications');
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
                $table->timestamp('last_report_at')->nullable();
                $table->timestamp('date_rapport_poste')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                // Story 23.2 / 24.7 — colonnes du canal agent (la card Agent + la
                // conformité 24.7 les lisent au render).
                $table->string('agent_token_hash', 64)->nullable();
                $table->timestamp('agent_token_rotated_at')->nullable();
                $table->timestamp('agent_last_checkin_at')->nullable();
                $table->timestamp('agent_quarantined_at')->nullable();
                $table->timestamp('agent_sync_requested_at')->nullable();
                // Mode debug du poste (toggle onglet Agent).
                $table->boolean('debug')->default(false);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        // Story 24.7 — tables D3 (24.1) lues par ConformityService.
        if (!Schema::hasTable('agent_resource_states')) {
            Schema::create('agent_resource_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('status', 32);
                $table->string('hash', 64);
                $table->text('detail')->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->timestamps();
                $table->unique(['workstation_id', 'type']);
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('agent_report_events')) {
            Schema::create('agent_report_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('previous_status', 32)->nullable();
                $table->string('status', 32);
                $table->string('hash', 64);
                $table->text('detail')->nullable();
                $table->timestamp('created_at')->nullable();
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

        // Story 37.1 (review #7) — le résumé de déploiement (toujours rendu) eager-load
        // `application` : sans cette table, un WorkstationApplicationStatus casse le render.
        if (!Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $table) {
                $table->id();
                $table->string('app_id')->nullable();
                $table->string('name')->nullable();
                $table->boolean('is_parc_default')->default(false);
                $table->timestamp('archived_at')->nullable();
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

        // Onglet Applications — les computed props `wpkgAttachedProfiles`/
        // `wpkgAttachedApplications` eager-load les pivots profils/apps (directs
        // poste + hérités parc). Sans ces tables, le render de l'onglet casse.
        if (!Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('display_name')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('app_profile_workstation')) {
            Schema::create('app_profile_workstation', function (Blueprint $table) {
                $table->foreignId('workstation_id')->constrained('workstations')->cascadeOnDelete();
                $table->unsignedBigInteger('app_profile_id');
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('app_profile_workstation_group')) {
            Schema::create('app_profile_workstation_group', function (Blueprint $table) {
                $table->unsignedBigInteger('workstation_group_id');
                $table->unsignedBigInteger('app_profile_id');
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('application_workstation')) {
            Schema::create('application_workstation', function (Blueprint $table) {
                $table->foreignId('workstation_id')->constrained('workstations')->cascadeOnDelete();
                $table->unsignedBigInteger('application_id');
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('application_workstation_group')) {
            Schema::create('application_workstation_group', function (Blueprint $table) {
                $table->unsignedBigInteger('workstation_group_id');
                $table->unsignedBigInteger('application_id');
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

    public function test_unknown_machine_redirects_to_parc_index(): void
    {
        // Machine introuvable : `loadMachine()` pose le redirect et rend false,
        // `mount()` s'interrompt avant loadAvailableGroups / initDeploymentTab /
        // loadWpkgOptionsState.
        //
        // Le composant ne porte plus de gardes `if (!$this->workstation)` : ce test
        // garde donc réellement le court-circuit, pas seulement le redirect. Retirer
        // le `return` du mount fait échouer ce test sur une ViewException.
        Livewire::test('pages::parc.machines.[id].index', ['id' => 999999])
            ->assertRedirect(route('app.parc.index'));
    }

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

    // ─── Story 24.7 — Conformité agent (AC2, AC4, AC5) ──────────────────────

    private function makeEnrolledWorkstation(): Workstation
    {
        $ws = $this->makeWorkstation();
        $ws->agent_token_hash = str_repeat('a', 64);
        $ws->agent_last_checkin_at = now();
        $ws->save();

        return $ws->refresh();
    }

    private function seedState(Workstation $ws, string $type, AgentResourceStatus $status, ?string $detail = null): void
    {
        AgentResourceState::create([
            'workstation_id' => $ws->id,
            'type' => $type,
            'status' => $status,
            'hash' => str_repeat('b', 64),
            'detail' => $detail,
            'reported_at' => now(),
        ]);
    }

    public function test_machine_page_shows_reported_state_by_type(): void
    {
        // AC2 — la card Agent (étendue) montre l'état rapporté par type, daté.
        $ws = $this->makeEnrolledWorkstation();
        $this->mockGroupService($ws);
        $this->seedState($ws, 'wallpaper', AgentResourceStatus::Compliant);
        $this->seedState($ws, 'overlay', AgentResourceStatus::Drift);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'agent') // la card Agent vit dans l'onglet « Agent »
            ->assertSee('État rapporté par type')
            ->assertSee('wallpaper')
            ->assertSee('overlay')
            ->assertSee('En écart'); // libellé du badge drift
    }

    public function test_machine_page_lists_recent_events(): void
    {
        // AC2 — sous-section « Derniers événements » datés.
        $ws = $this->makeEnrolledWorkstation();
        $this->mockGroupService($ws);
        AgentReportEvent::create([
            'workstation_id' => $ws->id,
            'type' => 'wallpaper',
            'previous_status' => null,
            'status' => AgentResourceStatus::Drift,
            'hash' => str_repeat('c', 64),
            'created_at' => now()->subMinutes(2),
        ]);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'agent') // la card Agent vit dans l'onglet « Agent »
            ->assertSee('Derniers événements');
    }

    /**
     * Accorde `computer.control` (le gate de forceSyncWorkstation, review
     * 24.7 #1). Le défaut `$user = null` rend la closure guest-friendly :
     * cette suite ne s'authentifie pas (sinon Gate::before n'est pas appelé).
     */
    private function grantComputerControl(): void
    {
        Gate::before(fn ($user = null, string $ability = '') => $ability === 'computer.control' ? true : null);
    }

    public function test_force_sync_posts_a_pending_request(): void
    {
        // AC5 — clic « Forcer la synchro » → agent_sync_requested_at posé + toast.
        $this->grantComputerControl();
        $ws = $this->makeEnrolledWorkstation();
        $this->mockGroupService($ws);
        $this->seedState($ws, 'wallpaper', AgentResourceStatus::Compliant);

        $this->assertNull($ws->agent_sync_requested_at);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('forceSyncWorkstation')
            ->assertDispatched('toastMagic', status: 'success');

        $this->assertNotNull($ws->refresh()->agent_sync_requested_at);
    }

    public function test_force_sync_rejected_for_non_enrolled_workstation(): void
    {
        // AC5 / piège 6 — bouton garde serveur : poste non enrôlé → erreur,
        // aucune demande posée.
        $this->grantComputerControl();
        $ws = $this->makeWorkstation(); // pas enrôlé
        $this->mockGroupService($ws);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('forceSyncWorkstation')
            ->assertDispatched('toastMagic', status: 'error');

        $this->assertNull($ws->refresh()->agent_sync_requested_at);
    }

    public function test_force_sync_denied_without_computer_control(): void
    {
        // Review 24.7 #1 — sans le gate `computer.control` (page accessible
        // en lecture), l'action est refusée et aucune demande n'est posée.
        $ws = $this->makeEnrolledWorkstation();
        $this->mockGroupService($ws);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('forceSyncWorkstation')
            ->assertDispatched('toastMagic', status: 'error');

        $this->assertNull($ws->refresh()->agent_sync_requested_at);
    }

    public function test_toggle_debug_mode_flips_flag_and_wpkg_options(): void
    {
        // Onglet Agent — clic « Mode debug » → workstation.debug + options
        // WPKG debug/logdebug, toast succès. L'event de régénération .ini est
        // faké (pas d'écriture filesystem en test).
        Event::fake([WorkstationOptionsChanged::class]);
        $this->grantComputerControl();
        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'agent')
            ->call('toggleDebugMode')
            ->assertDispatched('toastMagic', status: 'success');

        $this->assertTrue($ws->refresh()->debug);
        $options = WpkgWorkstationOption::where('workstation_id', $ws->id)
            ->pluck('option_value', 'option_key');
        $this->assertSame('true', $options['debug'] ?? null);
        $this->assertSame('true', $options['logdebug'] ?? null);
    }

    public function test_toggle_debug_mode_denied_without_computer_control(): void
    {
        // Sans `computer.control`, l'action est refusée et le drapeau ne bouge pas.
        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'agent')
            ->call('toggleDebugMode')
            ->assertDispatched('toastMagic', status: 'error');

        $this->assertFalse($ws->refresh()->debug);
    }

    public function test_drift_returns_to_compliant_on_reingest(): void
    {
        // AC4 — deux ingestions successives (drift puis compliant) via le
        // ReportIngestService réel → la vue passe de l'exception à l'absence.
        $ws = $this->makeEnrolledWorkstation();
        $this->mockGroupService($ws);

        $ingest = app(ReportIngestService::class);
        $report = fn (string $status, string $hash) => [
            'items' => [[
                'type' => 'wallpaper',
                'status' => $status,
                'hash' => $hash,
            ]],
        ];

        // 1) drift
        $ingest->ingest($ws, $report('drift', str_repeat('d', 64)));
        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'agent') // la card Agent vit dans l'onglet « Agent »
            ->assertSee('En écart');

        // 2) compliant (la cible a convergé)
        $ingest->ingest($ws, $report('compliant', str_repeat('e', 64)));
        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'agent')
            ->assertDontSee('En écart');
    }

    // ─── Story 37.1 — Câblage de l'onglet « État cible » (review #1 + #7) ────

    public function test_state_tab_wires_desired_state_component(): void
    {
        // Review #1 — câblage réel : `setTab('state')` autorisé + directive
        // @elseif ($tab === 'state') monte le SFC `desired-state-tab`. #[Lazy] ⇒
        // le rendu ne contient que le placeholder (« État cible du poste »).
        // Review #7 — la branche `state` est PLATE : sur tab=state, aucune autre
        // branche ne doit fuiter. On cible le CORPS de la card « Groupes logiques »
        // (désormais dans l'onglet dédié), pas son libellé d'onglet toujours visible.
        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'state')
            ->assertSet('tab', 'state')
            ->assertSee('État cible du poste')
            ->assertDontSee('Une machine peut appartenir à plusieurs groupes logiques');
    }

    public function test_state_tab_renders_even_with_deployment_statuses(): void
    {
        // Review #7 — cas régressif : sur un poste AYANT des statuts de déploiement
        // (deploy non vide), l'ancien câblage imbriquait `state` dans le @if
        // déploiement ⇒ l'état cible n'était JAMAIS atteint. La branche plate le
        // rend désormais quel que soit l'état du déploiement.
        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);

        $app = Application::create(['app_id' => 'wpkgapp', 'name' => 'WPKG App']);
        WorkstationApplicationStatus::create([
            'workstation_id' => $ws->id,
            'application_id' => $app->id,
            'status' => 'installed',
            'installed_version' => '1.0.0',
            'reported_at' => now(),
        ]);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'state')
            ->assertSet('tab', 'state')
            ->assertSee('État cible du poste')
            ->assertDontSee('Déploiement des applications'); // titre de la card déploiement (branche Général)
    }

    public function test_general_tab_shows_technical_details_moved_from_header(): void
    {
        // Le détail du poste (ex-card header) vit désormais dans l'onglet Général :
        // salle physique + grille technique. Le corps de la card « Groupes logiques »
        // ne doit PAS y figurer (il a son propre onglet).
        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->assertSet('tab', 'general')
            ->assertSee('Salle physique')
            ->assertSee('Adresse IP')
            ->assertDontSee('Une machine peut appartenir à plusieurs groupes logiques');
    }

    public function test_logical_tab_shows_logical_groups_card(): void
    {
        // Les groupes logiques ont leur onglet dédié : le corps de la card s'y affiche.
        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'logical')
            ->assertSet('tab', 'logical')
            ->assertSee('Une machine peut appartenir à plusieurs groupes logiques');
    }

    public function test_settings_tab_shows_ini_options(): void
    {
        // Les options .ini WPKG ont désormais leur propre onglet « Paramètres »
        // (ex-sous-onglet « Options .ini » de l'onglet Applications).
        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'settings')
            ->assertSet('tab', 'settings')
            ->assertSee('Options .ini WPKG');
    }

    public function test_applications_tab_has_no_subtabs(): void
    {
        // L'onglet Applications n'affiche plus que les assignations : les anciens
        // sous-onglets (dont « Options .ini ») ont disparu.
        $ws = $this->makeWorkstation();
        $this->mockGroupService($ws);

        Livewire::test('pages::parc.machines.[id].index', ['id' => $ws->id])
            ->call('setTab', 'wpkg')
            ->assertSet('tab', 'wpkg')
            ->assertSee('Profils applicatifs')
            ->assertDontSee('Options .ini WPKG');
    }
}
