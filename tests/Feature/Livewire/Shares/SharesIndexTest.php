<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Shares;

use App\Models\NetworkShare;
use App\Models\User;
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
 * Story 34.2 — Tests Feature Livewire de la page liste + création (T2/T3/AC1/AC2).
 *
 * Process::fake() : AUCUN accès FS réel ; shares_root pointé sur un tempdir.
 */
class SharesIndexTest extends TestCase
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

        $this->tempRoot = sys_get_temp_dir() . '/netshare-ui-' . uniqid();
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

    private const PAGE = 'pages::shares.index';

    private function manager(): User
    {
        $u = User::create(['login' => 'mgr-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $u->givePermissionTo('networkshare.view');
        $u->givePermissionTo('networkshare.manage');

        return $u;
    }

    private function viewerOnly(): User
    {
        $u = User::create(['login' => 'view-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $u->givePermissionTo('networkshare.view');

        return $u;
    }

    #[Test]
    public function lists_existing_shares(): void
    {
        NetworkShare::factory()->create(['name' => 'Direction', 'directory_name' => 'direction']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('Direction')
            ->assertSee('direction');
    }

    #[Test]
    public function open_create_prefills_next_safe_letter(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openCreate')
            ->assertSet('isCreateOpen', true)
            ->assertSet('letter', 'M:');
    }

    #[Test]
    public function creates_a_share_and_provisions(): void
    {
        $admin = $this->manager();
        $this->actingAs($admin);

        Livewire::test(self::PAGE)
            ->set('name', 'Échange Direction')
            ->set('directoryName', 'echange_direction')
            ->set('letter', 'P:')
            ->call('createShare')
            ->assertHasNoErrors()
            ->assertDispatched('toastMagic');

        $this->assertDatabaseHas('network_shares', [
            'directory_name' => 'echange_direction',
            'letter' => 'P:',
            'created_by_user_id' => $admin->id,
        ]);
        Process::assertRan(fn ($process) => str_contains($process->command, 'mkdir'));
    }

    #[Test]
    public function rejects_malformed_directory_name(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->set('name', 'Bad')
            ->set('directoryName', 'invalid name/../x')
            ->call('createShare')
            ->assertHasErrors(['directoryName']);

        $this->assertDatabaseCount('network_shares', 0);
    }

    #[Test]
    public function rejects_reserved_letter_at_the_form(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->set('name', 'Home pirate')
            ->set('directoryName', 'pirate')
            ->set('letter', 'K:')
            ->call('createShare')
            ->assertHasErrors(['letter']);

        $this->assertDatabaseCount('network_shares', 0);
    }

    #[Test]
    public function rejects_duplicate_directory_name(): void
    {
        NetworkShare::factory()->create(['directory_name' => 'direction']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->set('name', 'Dup')
            ->set('directoryName', 'direction')
            ->call('createShare')
            ->assertHasErrors(['directoryName']);
    }

    #[Test]
    public function viewer_cannot_create(): void
    {
        $this->actingAs($this->viewerOnly());

        Livewire::test(self::PAGE)
            ->set('name', 'Nope')
            ->set('directoryName', 'nope')
            ->call('createShare')
            ->assertStatus(403);

        $this->assertDatabaseCount('network_shares', 0);
    }

    #[Test]
    public function user_without_view_permission_is_forbidden_on_index(): void
    {
        // Review #5 : symétrie avec la page détail — l'hydratation directe du
        // composant index est fermée par l'`abort_unless` du mount.
        $noPerm = User::create(['login' => 'noperm-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $this->actingAs($noPerm);

        Livewire::test(self::PAGE)->assertStatus(403);
    }
}
