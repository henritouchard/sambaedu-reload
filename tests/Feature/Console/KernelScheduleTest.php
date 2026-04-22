<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class KernelScheduleTest extends TestCase
{
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
    public function it_schedules_automatic_user_groups_sync_from_ad(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $schedule = $this->app->make(Schedule::class);

        $scheduleMethod = new \ReflectionMethod($kernel, 'schedule');
        $scheduleMethod->setAccessible(true);
        $scheduleMethod->invoke($kernel, $schedule);

        $hasUserGroupsSync = collect($schedule->events())->contains(
            static fn($event): bool => str_contains((string) $event->command, 'user-groups:sync-from-ad')
        );

        $this->assertTrue($hasUserGroupsSync, 'Le scheduler doit déclencher user-groups:sync-from-ad automatiquement.');
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
}
