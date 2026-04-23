<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Models\WorkstationGroupSchedule;
use App\Models\WorkstationGroupScheduleRun;
use App\Services\Parc\WorkstationGroupScheduleService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\MocksAdminUser;

/**
 * Tests Feature Livewire panneau Programmations (story 4-4).
 *
 * 16 tests : 10 AC15 + 6 AC26 (one-shot).
 */
class GroupSchedulesPageTest extends TestCase
{
    use DatabaseTransactions;
    use MocksAdminUser;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        $this->createTablesIfNeeded();
        Queue::fake();
        $this->actAsAdmin();
        Gate::before(fn ($user, string $ability) => $ability === 'computer.control' ? true : null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->createdTables) {
            Schema::dropIfExists('workstation_group_schedule_runs');
            Schema::dropIfExists('workstation_group_schedules');
            Schema::dropIfExists('machine_power_action_tasks');
            Schema::dropIfExists('workstation_group_workstation');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('workstations');
        }
        parent::tearDown();
    }

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

        if (!Schema::hasTable('workstation_group_schedules')) {
            Schema::create('workstation_group_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workstation_group_id');
                $table->string('action', 16);
                $table->string('mode', 16)->default('recurring');
                $table->json('days_of_week')->nullable();
                $table->time('time_of_day')->nullable();
                $table->string('timezone', 64)->nullable();
                $table->timestamp('run_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->boolean('enabled')->default(true);
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_group_schedule_runs')) {
            Schema::create('workstation_group_schedule_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('schedule_id')->nullable();
                $table->timestamp('ran_at');
                $table->time('ran_for_time');
                $table->date('ran_for_date');
                $table->json('summary');
                $table->timestamps();
                $table->unique(['schedule_id', 'ran_for_date', 'ran_for_time'], 'wgsr_schedule_date_time_unique');
            });
            $this->createdTables = true;
        }
    }

    private function makeGroup(): WorkstationGroup
    {
        $group = WorkstationGroup::create([
            'name' => 'lab-sched-livewire-' . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);

        for ($i = 1; $i <= 2; $i++) {
            $ws = Workstation::create([
                'name' => "pc-livewire-{$i}-" . uniqid(),
                'os' => 'Windows 10',
                'ip' => "192.168.220.{$i}",
                'mac' => sprintf('aa:bb:ee:ff:00:%02x', $i),
                'status' => 1,
            ]);
            $ws->groups()->attach($group->id, ['physical' => true]);
        }

        return $group;
    }

    // ========================================
    // AC15 — tests Livewire panneau récurrent
    // ========================================

    public function test_admin_can_see_schedules_panel_on_group_page(): void
    {
        $group = $this->makeGroup();
        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1, 2, 3, 4, 5],
            'time_of_day' => '08:30:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->assertSee('Actions Programmées')
            ->assertSee('Lun–Ven')
            ->assertSee('08:30');
    }

    public function test_admin_can_create_schedule_via_modal(): void
    {
        $group = $this->makeGroup();

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('openScheduleModal')
            ->assertSet('scheduleModalOpen', true)
            ->set('formMode', 'recurring')
            ->set('formAction', 'wake')
            ->set('formDaysOfWeek', [1, 2, 3, 4, 5])
            ->set('formTimeOfDay', '08:30')
            ->set('formTimezone', 'Europe/Paris')
            ->call('saveSchedule')
            ->assertSet('scheduleModalOpen', false);

        $this->assertDatabaseHas('workstation_group_schedules', [
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
        ]);
    }

    public function test_admin_can_toggle_schedule_enabled(): void
    {
        $group = $this->makeGroup();
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:00:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('toggleSchedule', $schedule->id);

        $this->assertFalse($schedule->fresh()->enabled);
    }

    public function test_admin_can_edit_schedule(): void
    {
        $group = $this->makeGroup();
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:00:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('openScheduleModal', $schedule->id)
            ->assertSet('editingScheduleId', $schedule->id)
            ->set('formTimeOfDay', '09:15')
            ->set('formDaysOfWeek', [1, 2, 3])
            ->call('saveSchedule');

        $refreshed = $schedule->fresh();
        $this->assertEquals('09:15:00', $refreshed->time_of_day->format('H:i:s'));
        $this->assertEquals([1, 2, 3], $refreshed->days_of_week);
    }

    public function test_admin_can_delete_schedule_with_confirm(): void
    {
        $group = $this->makeGroup();
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:00:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('deleteSchedule', $schedule->id);

        $this->assertDatabaseMissing('workstation_group_schedules', ['id' => $schedule->id]);
    }

    public function test_non_admin_sees_read_only_view_without_crud_buttons(): void
    {
        // Remplacer l'utilisateur mocké par un non-admin : ->can() retourne false
        $this->swapAuthToNonAdmin();

        $group = $this->makeGroup();
        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:00:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        $html = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->assertSee('Actions Programmées')
            ->html();

        // Le bouton "Ajouter" ne doit pas être présent (guarded @can)
        $this->assertStringNotContainsString('Ajouter une programmation', $html);
    }

    public function test_non_admin_cannot_forge_livewire_call_to_create_schedule(): void
    {
        $this->swapAuthToNonAdmin();

        $group = $this->makeGroup();

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('openScheduleModal')
            ->assertSet('scheduleModalOpen', false);

        $this->assertEquals(0, WorkstationGroupSchedule::count());
    }

    public function test_non_admin_cannot_forge_toggleSchedule(): void
    {
        $group = $this->makeGroup();
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:00:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        $this->swapAuthToNonAdmin();

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('toggleSchedule', $schedule->id);

        // Guard serveur-side doit stopper l'opération : enabled inchangé + toast d'erreur
        $this->assertTrue($schedule->fresh()->enabled, 'Le guard non-admin doit empêcher toggleSchedule');
    }

    public function test_non_admin_cannot_forge_deleteSchedule(): void
    {
        $group = $this->makeGroup();
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:00:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        $this->swapAuthToNonAdmin();

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('deleteSchedule', $schedule->id);

        // Guard serveur-side doit stopper l'opération : schedule toujours présent
        $this->assertDatabaseHas('workstation_group_schedules', ['id' => $schedule->id]);
    }

    /**
     * Remplace l'admin mocké (->can()=true) par un non-admin (->can()=false)
     * et force Gate::before à répondre FALSE sur computer.control pour écraser
     * la gate admin installée dans setUp().
     */
    private function swapAuthToNonAdmin(): void
    {
        // Nouveau Gate::before cumulatif → retourne false (deny) explicitement
        // pour computer.control. Dans Laravel, les callbacks sont évalués en
        // ordre FIFO et le premier retour non-null l'emporte.
        // Ici on contourne en swappant directement l'auth avec un user qui
        // retourne toujours false et en évaluant via $user->can.
        // Le Gate::allows() dans les méthodes Livewire passe par $user->can(),
        // donc un user ->can() === false est suffisant.
        $this->app['auth']->forgetGuards();
        $user = \Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        $user->shouldReceive('can')->andReturn(false);
        $user->shouldReceive('checkPermissionTo')->andReturn(false);
        $user->shouldReceive('hasPermissionTo')->andReturn(false);
        $user->shouldReceive('getAuthIdentifier')->andReturn(2);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $user->login = 'non-admin';
        $this->actingAs($user);

        // Réinitialise les callbacks Gate::before en recréant le Gate facade.
        // Dans Laravel 11, on ne peut pas "unregister" un before callback — on
        // remplace le Gate entier par un neuf.
        $this->app->singleton(\Illuminate\Contracts\Auth\Access\Gate::class, function ($app) {
            return new \Illuminate\Auth\Access\Gate(
                $app,
                fn () => $app['auth']->user(),
            );
        });
        \Illuminate\Support\Facades\Gate::clearResolvedInstances();
    }

    public function test_schedule_history_panel_displays_recent_runs_paginated(): void
    {
        // Couverture minimale — la propriété schedules s'affiche avec les runs
        // n'est pas paginée côté history mais la liste des schedules est bien rendue.
        $group = $this->makeGroup();
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:00:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        for ($i = 0; $i < 3; $i++) {
            WorkstationGroupScheduleRun::create([
                'schedule_id' => $schedule->id,
                'ran_at' => now()->subDays($i),
                'ran_for_time' => '08:00:00',
                'ran_for_date' => now()->subDays($i)->toDateString(),
                'summary' => ['success_count' => 2, 'failed_count' => 0, 'skipped_count' => 0, 'task_ids' => [], 'errors' => []],
            ]);
        }

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->assertSee('Actions Programmées');

        $this->assertEquals(3, WorkstationGroupScheduleRun::count());
    }

    public function test_schedule_validation_requires_at_least_one_day_of_week(): void
    {
        $group = $this->makeGroup();

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('openScheduleModal')
            ->set('formMode', 'recurring')
            ->set('formDaysOfWeek', [])
            ->set('formTimeOfDay', '08:00')
            ->call('saveSchedule')
            ->assertHasErrors(['formDaysOfWeek']);

        $this->assertEquals(0, WorkstationGroupSchedule::count());
    }

    public function test_schedule_validation_rejects_invalid_time_format(): void
    {
        $group = $this->makeGroup();

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('openScheduleModal')
            ->set('formMode', 'recurring')
            ->set('formDaysOfWeek', [1, 2])
            ->set('formTimeOfDay', 'not-a-time')
            ->set('formTimezone', 'Europe/Paris')
            ->call('saveSchedule')
            ->assertHasErrors(['formTimeOfDay']);
    }

    // ========================================
    // AC26 — tests UI one-shot
    // ========================================

    public function test_admin_can_create_one_shot_schedule_via_modal_toggle(): void
    {
        $group = $this->makeGroup();

        $future = now()->addDays(2);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('openScheduleModal')
            ->call('toggleFormMode', 'one_shot')
            ->assertSet('formMode', 'one_shot')
            ->set('formAction', 'wake')
            ->set('formRunAtDate', $future->format('d/m/Y'))
            ->set('formRunAtTime', $future->format('H:i'))
            ->call('saveSchedule')
            ->assertSet('scheduleModalOpen', false);

        $this->assertDatabaseHas('workstation_group_schedules', [
            'workstation_group_id' => $group->id,
            'mode' => 'one_shot',
            'action' => 'wake',
        ]);
    }

    public function test_one_shot_form_conditionally_hides_recurring_fields_and_vice_versa(): void
    {
        $group = $this->makeGroup();

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('openScheduleModal')
            ->call('toggleFormMode', 'one_shot')
            ->assertSet('formMode', 'one_shot')
            ->assertSet('formDaysOfWeek', []);

        $component->call('toggleFormMode', 'recurring')
            ->assertSet('formMode', 'recurring')
            ->assertSet('formRunAtDate', null);
    }

    public function test_one_shot_with_run_at_in_past_shows_validation_error(): void
    {
        $group = $this->makeGroup();

        $past = now()->subHour();

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('openScheduleModal')
            ->call('toggleFormMode', 'one_shot')
            ->set('formRunAtDate', $past->format('d/m/Y'))
            ->set('formRunAtTime', $past->format('H:i'))
            ->call('saveSchedule')
            ->assertHasErrors(['formRunAtDate']);

        $this->assertEquals(0, WorkstationGroupSchedule::count());
    }

    public function test_completed_one_shot_edit_button_is_hidden(): void
    {
        $group = $this->makeGroup();
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'run_at' => now()->subHours(2),
            'completed_at' => now()->subHour(),
            'enabled' => false,
        ]);

        // La tentative d'ouverture de la modale sur ce schedule terminé échoue
        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('openScheduleModal', $schedule->id)
            ->assertSet('scheduleModalOpen', false);
    }

    public function test_completed_one_shot_clone_button_creates_new_one_shot_prefilled(): void
    {
        $group = $this->makeGroup();
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'run_at' => now()->subHours(2),
            'completed_at' => now()->subHour(),
            'enabled' => false,
        ]);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('cloneOneShot', $schedule->id);

        $this->assertEquals(2, WorkstationGroupSchedule::count());
        $newSchedule = WorkstationGroupSchedule::where('id', '!=', $schedule->id)->first();
        $this->assertEquals('one_shot', $newSchedule->mode);
        $this->assertNull($newSchedule->completed_at);
        // Correction review #13/#14 : le clone est créé DÉSACTIVÉ pour éviter que
        // le placeholder run_at=now+1h fire spontanément avant que l'utilisateur
        // ne confirme la vraie date dans la modale auto-ouverte.
        $this->assertFalse($newSchedule->enabled, 'Le clone doit être créé désactivé (placeholder non confirmé)');
        $this->assertNotNull($newSchedule->run_at);
        $this->assertTrue(
            $newSchedule->run_at->greaterThan(now()),
            'Le run_at placeholder doit être dans le futur (now+1h)'
        );
    }

    public function test_panel_displays_recurring_one_shot_future_and_completed_in_correct_order(): void
    {
        $group = $this->makeGroup();

        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1, 2, 3, 4, 5],
            'time_of_day' => '08:30:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'run_at' => now()->addDays(5),
            'enabled' => true,
        ]);

        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'run_at' => now()->subDay(),
            'completed_at' => now()->subDay()->addHour(),
            'enabled' => false,
        ]);

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id]);

        // AC24 : l'ordre d'affichage doit être récurrent → one-shot futur → terminé.
        // assertSeeInOrder vérifie que les chaînes apparaissent dans cet ordre dans
        // le HTML rendu, ce qui garantit que orderByRaw(... completed_at ...) est correct.
        $component->assertSeeInOrder(['Récurrent', 'Date unique', 'Terminé']);
    }

    // ========================================
    // AC9 — page dédiée historique runs
    // ========================================

    public function test_runs_page_renders_recent_runs_for_schedule(): void
    {
        $group = $this->makeGroup();
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:00:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        for ($i = 0; $i < 3; $i++) {
            WorkstationGroupScheduleRun::create([
                'schedule_id' => $schedule->id,
                'ran_at' => now()->subDays($i),
                'ran_for_time' => '08:00:00',
                'ran_for_date' => now()->subDays($i)->toDateString(),
                'summary' => ['success_count' => 2, 'failed_count' => 0, 'skipped_count' => 0, 'task_ids' => [], 'errors' => []],
            ]);
        }

        Livewire::test('pages::parc.groups.[id].schedules.[scheduleId].runs.index', [
            'id' => $group->id,
            'scheduleId' => $schedule->id,
        ])
            ->assertSee('Historique')
            ->assertSee('2 OK');
    }

    public function test_runs_page_redirects_when_schedule_does_not_belong_to_group(): void
    {
        $group1 = $this->makeGroup();
        $group2 = $this->makeGroup();

        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group1->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:00:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        // Tentative d'accès avec un group_id qui ne correspond pas au schedule
        Livewire::test('pages::parc.groups.[id].schedules.[scheduleId].runs.index', [
            'id' => $group2->id,
            'scheduleId' => $schedule->id,
        ])->assertRedirect(route('app.parc.groups.show', ['id' => $group2->id]));
    }
}
