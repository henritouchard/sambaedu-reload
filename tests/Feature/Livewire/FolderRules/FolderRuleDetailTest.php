<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\FolderRules;

use App\Models\FolderAccessRule;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 36.4 (AC4/AC5) — page détail : toggle actif/inactif, suppression refusée
 * si active / autorisée si inactive, assignation de parcs, 403 sans permission.
 */
class FolderRuleDetailTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::folder-rules.[id].index';

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        foreach (['folderrule.view', 'folderrule.manage'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function manager(): User
    {
        $u = User::create(['login' => 'mgr-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $u->givePermissionTo('folderrule.view');
        $u->givePermissionTo('folderrule.manage');

        return $u;
    }

    #[Test]
    public function toggling_active_flips_the_flag(): void
    {
        $rule = FolderAccessRule::factory()->create(['is_active' => true]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $rule->id])->call('toggleActive');
        self::assertFalse($rule->fresh()->is_active);
    }

    #[Test]
    public function deleting_an_active_rule_is_refused_and_keeps_it(): void
    {
        $rule = FolderAccessRule::factory()->create(['is_active' => true]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $rule->id])->call('deleteRule');
        self::assertNotNull($rule->fresh(), 'une règle active n\'est pas supprimable (D3)');
    }

    #[Test]
    public function deleting_an_inactive_rule_redirects_and_removes_it(): void
    {
        $rule = FolderAccessRule::factory()->inactive()->create();
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $rule->id])
            ->call('deleteRule')
            ->assertRedirect(route('app.folder-rules'));

        self::assertNull(FolderAccessRule::find($rule->id));
    }

    #[Test]
    public function manager_assigns_and_removes_a_parc(): void
    {
        $rule = FolderAccessRule::factory()->create();
        $wg = WorkstationGroup::factory()->logical()->create();
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE, ['id' => $rule->id])
            ->set('assignParcId', $wg->id)
            ->call('attachParc');
        self::assertContains($wg->id, $rule->fresh()->assignedWorkstationGroupIds());

        $component->call('detachParc', $wg->id);
        self::assertNotContains($wg->id, $rule->fresh()->assignedWorkstationGroupIds());
    }

    #[Test]
    public function mount_is_forbidden_without_view_permission(): void
    {
        $rule = FolderAccessRule::factory()->create();
        $u = User::create(['login' => 'none-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $this->actingAs($u);

        Livewire::test(self::PAGE, ['id' => $rule->id])->assertForbidden();
    }
}
