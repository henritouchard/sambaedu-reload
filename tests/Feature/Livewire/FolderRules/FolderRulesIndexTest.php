<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\FolderRules;

use App\Models\FolderAccessRule;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 36.4 (AC4) — page liste + modale de création : listing, 403, création
 * valide/invalide, confirmation deny bloquante.
 */
class FolderRulesIndexTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::folder-rules.index';

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

    private function viewerOnly(): User
    {
        $u = User::create(['login' => 'view-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $u->givePermissionTo('folderrule.view');

        return $u;
    }

    #[Test]
    public function lists_existing_rules(): void
    {
        FolderAccessRule::factory()->create(['label' => 'Interdire Direction']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)->assertOk()->assertSee('Interdire Direction');
    }

    #[Test]
    public function mount_is_forbidden_without_view_permission(): void
    {
        $u = User::create(['login' => 'none-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $this->actingAs($u);

        Livewire::test(self::PAGE)->assertForbidden();
    }

    #[Test]
    public function viewer_cannot_open_the_create_modal(): void
    {
        $this->actingAs($this->viewerOnly());

        Livewire::test(self::PAGE)->call('openCreate')->assertForbidden();
    }

    #[Test]
    public function manager_creates_an_allow_rule_and_is_redirected(): void
    {
        $group = UserGroup::factory()->create(['name' => 'Profs', 'ad_dn' => null]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->set('label', 'Autoriser Échange aux profs')
            ->set('path', 'D:\\Echange')
            ->set('userGroupId', $group->id)
            ->set('sens', 'allow')
            ->set('niveau', 'modify')
            ->set('portee', 'folder_subfolders_files')
            ->call('createRule')
            ->assertHasNoErrors()
            ->assertRedirect();

        self::assertSame(1, FolderAccessRule::where('label', 'Autoriser Échange aux profs')->count());
    }

    #[Test]
    public function a_deny_rule_without_acknowledgement_is_refused(): void
    {
        $group = UserGroup::factory()->create(['name' => 'Eleves', 'ad_dn' => null]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->set('label', 'Interdire Direction')
            ->set('path', 'D:\\Direction')
            ->set('userGroupId', $group->id)
            ->set('sens', 'deny')
            ->set('niveau', 'list_folder')
            ->set('portee', 'folder_only')
            ->set('denyAcknowledged', false)
            ->call('createRule')
            ->assertHasErrors('denyAcknowledged');

        self::assertSame(0, FolderAccessRule::count());
    }

    #[Test]
    public function a_deny_rule_with_acknowledgement_is_created(): void
    {
        $group = UserGroup::factory()->create(['name' => 'Eleves', 'ad_dn' => null]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->set('label', 'Interdire Direction')
            ->set('path', 'D:\\Direction')
            ->set('userGroupId', $group->id)
            ->set('sens', 'deny')
            ->set('niveau', 'list_folder')
            ->set('portee', 'folder_only')
            ->set('denyAcknowledged', true)
            ->call('createRule')
            ->assertHasNoErrors()
            ->assertRedirect();

        self::assertSame(1, FolderAccessRule::count());
    }

    #[Test]
    public function a_non_absolute_path_is_rejected_by_validation(): void
    {
        $group = UserGroup::factory()->create(['ad_dn' => null]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->set('label', 'Mauvais chemin')
            ->set('path', 'Software\\X')
            ->set('userGroupId', $group->id)
            ->set('sens', 'allow')
            ->set('niveau', 'read')
            ->set('portee', 'folder_only')
            ->call('createRule')
            ->assertHasErrors('path');

        self::assertSame(0, FolderAccessRule::count());
    }
}
