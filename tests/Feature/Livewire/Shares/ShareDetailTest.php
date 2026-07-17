<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Shares;

use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 34.2 — Tests Feature Livewire de la page détail : assignation par maille
 * (RO/RW), retrait, re-provision, suppression, collision de lettre (T3/T4/T6/AC).
 */
class ShareDetailTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        Queue::fake();
        Process::fake();

        $this->tempRoot = sys_get_temp_dir() . '/netshare-detail-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);

        foreach (['networkshare.view', 'networkshare.manage'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private const PAGE = 'pages::admin.shares.[id].index';

    private function manager(): User
    {
        $u = User::create(['login' => 'mgr-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $u->givePermissionTo('networkshare.view');
        $u->givePermissionTo('networkshare.manage');

        return $u;
    }

    #[Test]
    public function attaches_a_user_with_rw_access_and_reprovisions(): void
    {
        $share = NetworkShare::factory()->create(['letter' => 'P:']);
        $user = User::create(['login' => 'alice', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $share->id])
            ->set('assignType', User::class)
            ->set('assignTargetId', $user->id)
            ->set('assignAccess', 'rw')
            ->call('addAssignment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('network_share_assignables', [
            'network_share_id' => $share->id,
            'assignable_type' => User::class,
            'assignable_id' => $user->id,
            'access' => 'rw',
        ]);
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl'));
    }

    #[Test]
    public function changing_access_updates_the_pivot_row(): void
    {
        $share = NetworkShare::factory()->create(['letter' => 'P:']);
        $user = User::create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);
        $row = NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => User::class,
            'assignable_id' => $user->id,
            'access' => 'ro',
        ]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $share->id])
            ->call('changeAccess', $row->id, 'rw');

        $this->assertDatabaseHas('network_share_assignables', ['id' => $row->id, 'access' => 'rw']);
    }

    #[Test]
    public function removing_an_assignment_deletes_the_pivot_row(): void
    {
        $share = NetworkShare::factory()->create(['letter' => 'P:']);
        $user = User::create(['login' => 'carol', 'role' => 'prof', 'is_active' => true]);
        $row = NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => User::class,
            'assignable_id' => $user->id,
            'access' => 'ro',
        ]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $share->id])
            ->call('removeAssignment', $row->id);

        $this->assertDatabaseMissing('network_share_assignables', ['id' => $row->id]);
    }

    #[Test]
    public function deduplicates_assignment_for_the_same_target(): void
    {
        $share = NetworkShare::factory()->create(['letter' => 'P:']);
        $group = UserGroup::create(['name' => 'classe-6a', 'type' => 'classe']);
        $this->actingAs($this->manager());

        $test = Livewire::test(self::PAGE, ['id' => $share->id])
            ->set('assignType', UserGroup::class)
            ->set('assignTargetId', $group->id)
            ->set('assignAccess', 'ro')
            ->call('addAssignment');

        // Re-ajout du même groupe avec un autre accès → upsert (1 ligne, access rw).
        $test->set('assignType', UserGroup::class)
            ->set('assignTargetId', $group->id)
            ->set('assignAccess', 'rw')
            ->call('addAssignment');

        $this->assertSame(1, NetworkShareAssignable::where('network_share_id', $share->id)->count());
        $this->assertDatabaseHas('network_share_assignables', [
            'network_share_id' => $share->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'access' => 'rw',
        ]);
    }

    #[Test]
    public function wg_only_share_surfaces_a_warning(): void
    {
        $share = NetworkShare::factory()->create(['letter' => 'P:']);
        $wg = WorkstationGroup::create(['name' => 'salle-1', 'is_physical' => true, 'is_active' => true]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $share->id])
            ->set('assignType', WorkstationGroup::class)
            ->set('assignTargetId', $wg->id)
            ->call('addAssignment')
            ->assertDispatched('toastMagic');

        // Le warning WG-montage-seul est rendu dans la page.
        Livewire::test(self::PAGE, ['id' => $share->id])
            ->assertSee('parc');
    }

    #[Test]
    public function letter_collision_is_blocked_on_save(): void
    {
        $user = User::create(['login' => 'dave', 'role' => 'prof', 'is_active' => true]);
        $a = NetworkShare::factory()->create(['name' => 'Direction', 'directory_name' => 'direction', 'letter' => 'P:']);
        $b = NetworkShare::factory()->create(['name' => 'Projets', 'directory_name' => 'projets', 'letter' => 'Q:']);
        NetworkShareAssignable::create(['network_share_id' => $a->id, 'assignable_type' => User::class, 'assignable_id' => $user->id, 'access' => 'ro']);
        NetworkShareAssignable::create(['network_share_id' => $b->id, 'assignable_type' => User::class, 'assignable_id' => $user->id, 'access' => 'ro']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $b->id])
            ->set('letter', 'P:') // collision avec A pour l'audience commune (user dave)
            ->call('saveDetails')
            ->assertDispatched('toastMagic');

        // La lettre N'A PAS été enregistrée (refus prédictif).
        $this->assertDatabaseHas('network_shares', ['id' => $b->id, 'letter' => 'Q:']);
    }

    #[Test]
    public function letter_collision_is_blocked_on_add_assignment_and_rolled_back(): void
    {
        // Review #1 : le vecteur `addAssignment` doit AUSSI fermer le piège #3.
        // A=P: déjà assigné à dave ; B=P: sans audience. Assigner dave à B crée
        // une audience commune {dave} sur la même lettre P: → collision → refus +
        // ROLLBACK de la ligne pivot (aucune écriture partielle).
        $dave = User::create(['login' => 'dave', 'role' => 'prof', 'is_active' => true]);
        $a = NetworkShare::factory()->create(['name' => 'Direction', 'directory_name' => 'direction', 'letter' => 'P:']);
        $b = NetworkShare::factory()->create(['name' => 'Projets', 'directory_name' => 'projets', 'letter' => 'P:']);
        NetworkShareAssignable::create(['network_share_id' => $a->id, 'assignable_type' => User::class, 'assignable_id' => $dave->id, 'access' => 'ro']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $b->id])
            ->set('assignType', User::class)
            ->set('assignTargetId', $dave->id)
            ->set('assignAccess', 'ro')
            ->call('addAssignment')
            ->assertDispatched('toastMagic');

        // La ligne pivot a été ROLLBACK : dave n'est PAS assigné à B.
        $this->assertDatabaseMissing('network_share_assignables', [
            'network_share_id' => $b->id,
            'assignable_type' => User::class,
            'assignable_id' => $dave->id,
        ]);
    }

    #[Test]
    public function deletes_the_share_and_cascades_the_pivot(): void
    {
        $share = NetworkShare::factory()->create(['letter' => 'P:']);
        $user = User::create(['login' => 'erin', 'role' => 'prof', 'is_active' => true]);
        NetworkShareAssignable::create(['network_share_id' => $share->id, 'assignable_type' => User::class, 'assignable_id' => $user->id, 'access' => 'ro']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE, ['id' => $share->id])
            ->call('deleteShare')
            ->assertRedirect(route('admin.shares'));

        $this->assertDatabaseMissing('network_shares', ['id' => $share->id]);
        $this->assertDatabaseMissing('network_share_assignables', ['network_share_id' => $share->id]);
    }

    #[Test]
    public function viewer_without_view_permission_is_forbidden(): void
    {
        $share = NetworkShare::factory()->create();
        $noPerm = User::create(['login' => 'noperm-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $this->actingAs($noPerm);

        Livewire::test(self::PAGE, ['id' => $share->id])->assertStatus(403);
    }
}
