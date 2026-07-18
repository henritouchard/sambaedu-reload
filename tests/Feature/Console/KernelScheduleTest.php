<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Kernel;
use App\Models\SystemSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class KernelScheduleTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        // Story 5.1d — code review #8 : éviter qu'un SystemSetting `quota.trash`
        // posé par un test perturbe les autres tests si l'ordre est aléatoire.
        // DatabaseTransactions rollback les rows, mais on appelle aussi
        // `SystemSetting::forget()` pour purger d'éventuels caches statiques
        // si le modèle en ajoute à l'avenir (defensive).
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('system_settings')) {
                SystemSetting::forget('quota.trash');
            }
        } catch (\Throwable) {
            // No-op : la table peut ne pas exister selon l'ordre des tests.
        }

        parent::tearDown();
    }

    #[Test]
    public function it_schedules_automatic_users_sync_from_ad(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasUsersSync = collect($schedule->events())->contains(
            static fn($event): bool => str_contains((string) $event->command, 'users:sync-from-ad --scope=all')
        );

        $this->assertTrue($hasUsersSync, 'Le scheduler doit déclencher users:sync-from-ad automatiquement.');
    }

    #[Test]
    public function it_does_not_schedule_user_groups_sync_from_ad(): void
    {
        // Les groupes utilisateurs sont synchronisés par le tick `users:sync-from-ad`
        // (SyncUsersFromAdJob → UserGroupService::syncFromAd(), avant les users).
        // L'ancienne entrée dédiée `user-groups:sync-from-ad` pointait une commande
        // jamais créée (NamespaceNotFoundException loggée toutes les 5 min) → retirée.
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasUserGroupsSync = collect($schedule->events())->contains(
            static fn($event): bool => str_contains((string) $event->command, 'user-groups:sync-from-ad')
        );

        $this->assertFalse($hasUserGroupsSync, 'Le scheduler ne doit PLUS planifier user-groups:sync-from-ad (commande inexistante).');
    }

    #[Test]
    public function it_schedules_group_schedules_execution_every_minute(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasGroupSchedules = collect($schedule->events())->contains(
            static fn ($event): bool => str_contains((string) $event->command, 'parc:execute-group-schedules')
        );

        $this->assertTrue($hasGroupSchedules, 'Le scheduler doit déclencher parc:execute-group-schedules (story 4-4).');
    }

    #[Test]
    public function it_schedules_group_schedule_runs_pruning_daily(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasPruneCommand = collect($schedule->events())->contains(
            static fn ($event): bool => str_contains((string) $event->command, 'parc:prune-group-schedule-runs')
        );

        $this->assertTrue($hasPruneCommand, 'Le scheduler doit déclencher parc:prune-group-schedule-runs (story 4-4).');
    }

    #[Test]
    public function it_does_not_schedule_quota_refresh_cache(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasQuotaRefresh = collect($schedule->events())->contains(
            static fn ($event): bool => str_contains((string) $event->command, 'quota:refresh-cache')
        );

        $this->assertFalse($hasQuotaRefresh, 'Le scheduler ne doit plus déclencher quota:refresh-cache (supprimée en 5.1a, remplacée par snapshot BDD en 5.1b).');
    }

    #[Test]
    public function it_schedules_quota_snapshot_daily_at_03h(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasQuotaSnapshot = collect($schedule->events())->contains(
            static fn ($event): bool => str_contains((string) $event->command, 'quota:snapshot')
                && $event->expression === '0 3 * * *'
        );

        $this->assertTrue(
            $hasQuotaSnapshot,
            'Le scheduler doit déclencher quota:snapshot quotidiennement à 03h00 (story 5.1b).'
        );
    }

    // =========================================================================
    // Story 5.1d — trash:purge à 02h00 + ->when() conditionné par SystemSetting
    // =========================================================================

    #[Test]
    public function it_schedules_trash_purge_daily_at_02h(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasTrashPurge = collect($schedule->events())->contains(
            static fn ($event): bool => str_contains((string) $event->command, 'trash:purge')
                && $event->expression === '0 2 * * *'
        );

        $this->assertTrue(
            $hasTrashPurge,
            'Le scheduler doit déclencher trash:purge quotidiennement à 02h00 (story 5.1d).'
        );
    }

    #[Test]
    public function it_does_not_run_trash_purge_when_auto_disabled(): void
    {
        // Crée la table system_settings + insère purge_auto=false.
        if (!\Illuminate\Support\Facades\Schema::hasTable('system_settings')) {
            \Illuminate\Support\Facades\Schema::create('system_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key', 191)->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }
        \App\Models\SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $trashEvent = collect($schedule->events())->first(
            static fn ($event): bool => str_contains((string) $event->command, 'trash:purge')
        );

        $this->assertNotNull($trashEvent);
        $this->assertFalse(
            $trashEvent->filtersPass($this->app),
            'Avec purge_auto=false, la closure ->when() doit retourner false (event non exécuté).',
        );
    }

    // =========================================================================
    // Story 16.11 — migration:health-check schedulé daily
    // =========================================================================

    // =========================================================================
    // Story 20.2 — federated:purge-identities à 02h30 + ->when() toggle config
    // =========================================================================

    #[Test]
    public function it_schedules_federated_purge_identities_daily_at_0230(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasFederatedPurge = collect($schedule->events())->contains(
            static fn ($event): bool => str_contains((string) $event->command, 'federated:purge-identities')
                && $event->expression === '30 2 * * *'
        );

        $this->assertTrue(
            $hasFederatedPurge,
            'Le scheduler doit déclencher federated:purge-identities quotidiennement à 02h30 (story 20.2).',
        );
    }

    #[Test]
    public function it_does_not_run_federated_purge_when_anonymize_disabled(): void
    {
        config(['federated_auth.retention.anonymize_enabled' => false]);

        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $event = collect($schedule->events())->first(
            static fn ($event): bool => str_contains((string) $event->command, 'federated:purge-identities')
        );

        $this->assertNotNull($event);
        $this->assertFalse(
            $event->filtersPass($this->app),
            'anonymize_enabled=false → la closure ->when() doit retourner false (D-8).',
        );
    }

    #[Test]
    public function it_runs_federated_purge_when_anonymize_enabled(): void
    {
        config(['federated_auth.retention.anonymize_enabled' => true]);

        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $event = collect($schedule->events())->first(
            static fn ($event): bool => str_contains((string) $event->command, 'federated:purge-identities')
        );

        $this->assertNotNull($event);
        $this->assertTrue(
            $event->filtersPass($this->app),
            'anonymize_enabled=true → la closure ->when() doit retourner true.',
        );
    }

    #[Test]
    public function it_schedules_migration_health_check_daily(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasMigrationHealthCheck = collect($schedule->events())->contains(
            static fn ($event): bool => str_contains((string) $event->command, 'migration:health-check')
        );

        $this->assertTrue(
            $hasMigrationHealthCheck,
            'Le scheduler doit déclencher migration:health-check quotidiennement (story 16.11).',
        );
    }

    // =========================================================================
    // Story 16.12 — script-logs:archive:rotate schedulé daily 04:00 (post review F1)
    // =========================================================================

    #[Test]
    public function it_schedules_script_logs_archive_rotate_daily_at_0400(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasScriptLogsArchive = collect($schedule->events())->contains(
            static fn ($event): bool => str_contains((string) $event->command, 'script-logs:archive:rotate')
                && $event->expression === '0 4 * * *'
        );

        $this->assertTrue(
            $hasScriptLogsArchive,
            'Le scheduler doit déclencher script-logs:archive:rotate quotidiennement à 04h00 (story 16.12, post review F1 décalage Q1 de 03:30 → 04:00 pour éviter collision printers:sync).',
        );
    }

    #[Test]
    public function it_runs_trash_purge_when_auto_enabled(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('system_settings')) {
            \Illuminate\Support\Facades\Schema::create('system_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key', 191)->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }
        \App\Models\SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => true]);

        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $trashEvent = collect($schedule->events())->first(
            static fn ($event): bool => str_contains((string) $event->command, 'trash:purge')
        );

        $this->assertNotNull($trashEvent);
        $this->assertTrue(
            $trashEvent->filtersPass($this->app),
            'Avec purge_auto=true, la closure ->when() doit retourner true (event éligible).',
        );
        // Cleanup : assuré par tearDown() + DatabaseTransactions rollback.
    }

    // =========================================================================
    // Story 26.3 — profiles:snapshot à 04h30
    // =========================================================================

    #[Test]
    public function it_schedules_profiles_snapshot_daily_at_0430(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasProfilesSnapshot = collect($schedule->events())->contains(
            static fn ($event): bool => str_contains((string) $event->command, 'profiles:snapshot')
                && $event->expression === '30 4 * * *'
        );

        $this->assertTrue(
            $hasProfilesSnapshot,
            'Le scheduler doit déclencher profiles:snapshot quotidiennement à 04h30 (story 26.3).'
        );
    }
}
