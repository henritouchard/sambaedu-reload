<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Printer;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Policies\PrinterPolicy;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;
use Tests\Traits\CreatesPrintersSchema;

/**
 * Story 6.1 — Tests Feature de PrinterPolicy::manage.
 *
 * Fix #11 (décision produit 2026-04-28) : seuls les administrateurs globaux
 * (`server.admin`) peuvent modifier les imprimantes. Les délégués scopés
 * (server.admin sur un parc) peuvent VOIR les imprimantes de leur périmètre
 * mais ne peuvent pas les modifier.
 *
 * Couvre AC8 :
 *  - admin global → peut manage TOUTES les imprimantes (y compris orphan).
 *  - délégué scopé → ne peut PAS manage même une imprimante rattachée à son parc.
 *  - utilisateur sans délégation → refus.
 */
class PrinterPolicyDelegationTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;
    use CreatesPrintersSchema;

    private PrinterPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        Queue::fake();
        WorkstationGroupObserver::disableSync();

        $this->createPermissionSchema();
        $this->createPrintersSchema();
        (new PermissionSeeder())->run();

        $this->policy = new PrinterPolicy();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        $this->dropPrintersSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeUser(string $login, array $perms = []): User
    {
        $user = User::create(['login' => $login, 'role' => 'autre', 'is_active' => true]);
        foreach ($perms as $p) {
            $user->givePermissionTo($p);
        }
        return $user;
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
    public function admin_can_manage_any_printer_including_orphan(): void
    {
        $admin = $this->makeUser('admin', ['server.admin']);

        $regular = Printer::create(['cups_name' => 'imp_reg', 'orphan' => false]);
        $orphan = Printer::create(['cups_name' => 'imp_orph', 'orphan' => true]);

        $this->assertTrue($this->policy->manage($admin, $regular));
        $this->assertTrue($this->policy->manage($admin, $orphan));
    }

    #[Test]
    public function admin_can_manage_without_printer_instance(): void
    {
        $admin = $this->makeUser('admin', ['server.admin']);
        $this->assertTrue($this->policy->manage($admin, null));
    }

    #[Test]
    public function delegated_user_cannot_manage_printer_even_attached_to_their_group(): void
    {
        // Fix #11 : décision produit — les délégués ne peuvent pas modifier les imprimantes,
        // seuls les admins globaux et les "Référents numériques" (server.admin global) le peuvent.
        $delegate = $this->makeUser('delegate');
        $group = $this->makeGroup('salle_a');

        $svc = app(PermissionService::class);
        $svc->grantDelegation($delegate, 'server.admin', $group);

        // Pré-condition : la délégation est effective sur ce parc.
        $this->assertTrue(
            $svc->canOnWorkstationGroup($delegate, 'server.admin', $group),
            'Pré-condition : la délégation doit être effective'
        );

        $printer = Printer::create(['cups_name' => 'imp_a', 'orphan' => false]);
        $printer->workstationGroups()->attach($group->id, [
            'attached_at' => now(),
            'attached_by_user_id' => $delegate->id,
        ]);

        $printer->refresh();

        // Malgré la délégation scopée, le délégué ne peut pas modifier l'imprimante.
        $this->assertFalse(
            $this->policy->manage($delegate, $printer),
            'Délégué scopé ne doit pas pouvoir modifier les imprimantes (fix #11)'
        );
    }

    #[Test]
    public function delegated_user_cannot_manage_printer_attached_to_other_group(): void
    {
        $delegate = $this->makeUser('delegate');
        $myGroup = $this->makeGroup('salle_a');
        $otherGroup = $this->makeGroup('salle_b');

        app(PermissionService::class)->grantDelegation($delegate, 'server.admin', $myGroup);

        $printer = Printer::create(['cups_name' => 'imp_b', 'orphan' => false]);
        $printer->workstationGroups()->attach($otherGroup->id, [
            'attached_at' => now(),
            'attached_by_user_id' => $delegate->id,
        ]);

        $printer->refresh();

        $this->assertFalse(
            $this->policy->manage($delegate, $printer),
            'Délégué ne doit pas pouvoir manage une imprimante rattachée à un autre parc'
        );
    }

    #[Test]
    public function delegated_user_cannot_manage_orphan_printer(): void
    {
        $delegate = $this->makeUser('delegate');
        $group = $this->makeGroup('salle_a');

        app(PermissionService::class)->grantDelegation($delegate, 'server.admin', $group);

        $orphan = Printer::create(['cups_name' => 'imp_orph', 'orphan' => true]);

        $this->assertFalse(
            $this->policy->manage($delegate, $orphan),
            'Délégué ne doit jamais pouvoir manage une imprimante orphan'
        );
    }

    #[Test]
    public function user_without_any_permission_or_delegation_is_denied(): void
    {
        $lambda = $this->makeUser('lambda');
        $printer = Printer::create(['cups_name' => 'imp_a', 'orphan' => false]);

        $this->assertFalse($this->policy->manage($lambda, $printer));
    }
}
