<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Printer;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;
use Tests\Traits\CreatesPrintersSchema;

/**
 * Story 6.1 — Tests Unit du modèle App\Models\Printer.
 *
 * Couvre :
 *  - PK string + non-incrementing + keyType.
 *  - Relation `workstationGroups()` BelongsToMany via pivot.
 *  - Scopes `nonOrphan()` / `orphans()`.
 *  - Scope `forUser()` : admin / délégué / lambda sans accès.
 */
class PrinterTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;
    use CreatesPrintersSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        // Neutralise les jobs AdSync dispatchés par WorkstationGroupObserver
        // (LDAP indisponible en SQLite mémoire).
        Queue::fake();
        WorkstationGroupObserver::disableSync();

        $this->createPermissionSchema();
        $this->createPrintersSchema();
        (new PermissionSeeder())->run();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        $this->dropPrintersSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    #[Test]
    public function primary_key_is_string_cups_name_and_non_incrementing(): void
    {
        $printer = new Printer();
        $this->assertSame('cups_name', $printer->getKeyName());
        $this->assertFalse($printer->getIncrementing());
        $this->assertSame('string', $printer->getKeyType());
    }

    private function makeGroup(string $name): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => $name,
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function workstation_groups_relation_uses_pivot_with_attached_metadata(): void
    {
        $user = User::create(['login' => 'admin1', 'role' => 'admin', 'is_active' => true]);
        $group = $this->makeGroup('salle-test-1');
        $printer = Printer::factory()->create();

        $printer->workstationGroups()->attach($group->id, [
            'attached_at' => now(),
            'attached_by_user_id' => $user->id,
        ]);

        $printer->refresh();
        $this->assertCount(1, $printer->workstationGroups);
        $pivot = $printer->workstationGroups->first()->pivot;
        $this->assertSame($user->id, (int) $pivot->attached_by_user_id);
    }

    #[Test]
    public function scope_non_orphan_filters_out_orphans(): void
    {
        Printer::factory()->create(['cups_name' => 'imp1']);
        Printer::factory()->orphan()->create(['cups_name' => 'imp2']);

        $names = Printer::nonOrphan()->pluck('cups_name')->all();
        $this->assertContains('imp1', $names);
        $this->assertNotContains('imp2', $names);
    }

    #[Test]
    public function scope_orphans_filters_in_only_orphans(): void
    {
        Printer::factory()->create(['cups_name' => 'imp1']);
        Printer::factory()->orphan()->create(['cups_name' => 'imp2']);

        $names = Printer::orphans()->pluck('cups_name')->all();
        $this->assertContains('imp2', $names);
        $this->assertNotContains('imp1', $names);
    }

    #[Test]
    public function scope_for_user_returns_all_for_server_admin(): void
    {
        $admin = User::create(['login' => 'admin', 'role' => 'admin', 'is_active' => true]);
        $admin->givePermissionTo('server.admin');

        Printer::factory()->create(['cups_name' => 'imp1']);
        Printer::factory()->create(['cups_name' => 'imp2']);

        $rows = Printer::forUser($admin)->get();
        $this->assertCount(2, $rows);
    }

    #[Test]
    public function scope_for_user_returns_only_attached_for_delegated_user(): void
    {
        $user = User::create(['login' => 'delegate', 'role' => 'autre', 'is_active' => true]);
        $group = WorkstationGroup::factory()->create();

        // Délégation scopée server.admin sur ce parc.
        app(PermissionService::class)->grantDelegation($user, 'server.admin', $group);

        $imp1 = Printer::factory()->create(['cups_name' => 'imp1']);
        $imp2 = Printer::factory()->create(['cups_name' => 'imp2']);

        $imp1->workstationGroups()->attach($group->id, [
            'attached_at' => now(),
            'attached_by_user_id' => $user->id,
        ]);
        // imp2 n'est rattachée à aucun parc autorisé.

        $names = Printer::forUser($user)->pluck('cups_name')->all();
        $this->assertContains('imp1', $names);
        $this->assertNotContains('imp2', $names);
    }

    #[Test]
    public function scope_for_user_returns_empty_for_user_without_access(): void
    {
        $user = User::create(['login' => 'lambda', 'role' => 'autre', 'is_active' => true]);

        Printer::factory()->create(['cups_name' => 'imp1']);

        $names = Printer::forUser($user)->pluck('cups_name')->all();
        $this->assertEmpty($names);
    }
}
