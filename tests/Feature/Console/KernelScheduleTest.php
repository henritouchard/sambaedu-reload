<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class KernelScheduleTest extends TestCase
{
    /** @test */
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

    /** @test */
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
}
