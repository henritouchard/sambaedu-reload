<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\AclService;
use App\Services\Filesystem\ShareService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 5.2 — Tests Feature Livewire SFC `class-share-section`.
 *
 * Couvre AC 7 / 8 / 17 :
 *  - rendu conditionnel `type === 'classe'`
 *  - double guard manage-share (UI + serveur)
 *  - bouton actions désactivé sans permission `share.manage`
 */
class ClassShareSectionTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        (new PermissionSeeder())->run();

        // Désactive Observers AD pour éviter dispatched jobs.
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
        Process::fake([
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $this->tempRoot = sys_get_temp_dir() . '/share-livewire-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        AclService::$classesRoot = $this->tempRoot;
        ShareService::$classesRoot = $this->tempRoot;
    }

    protected function tearDown(): void
    {
        AclService::$classesRoot = '/var/sambaedu/Classes';
        ShareService::$classesRoot = '/var/sambaedu/Classes';
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        if (is_dir($this->tempRoot)) {
            $this->rrmdir($this->tempRoot);
        }
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        $items = @scandir($dir) ?: [];
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $f = $dir . '/' . $i;
            if (is_dir($f) && ! is_link($f)) {
                $this->rrmdir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    private function componentPath(): string
    {
        return 'pages::users.groups.[id]._partials.class-share-section';
    }

    private function makeAdmin(string $login = 'manager', array $perms = ['share.view', 'share.manage']): User
    {
        $u = User::create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        foreach ($perms as $p) {
            $u->givePermissionTo($p);
        }
        return $u;
    }

    private function makeClasse(string $name = '6A'): UserGroup
    {
        return UserGroup::create(['name' => $name, 'type' => 'classe', 'display_name' => "Classe $name"]);
    }

    // =========================================================================
    // AC 7 — rendu conditionnel
    // =========================================================================

    #[Test]
    public function it_renders_section_for_classe_type(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $classe = $this->makeClasse('6A');

        Livewire::test($this->componentPath(), ['groupId' => $classe->id])
            ->assertSet('isClasse', true)
            ->assertSee('Partage de classe')
            ->assertSee('Non créé');
    }

    #[Test]
    public function it_does_not_render_section_for_non_classe_type(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $role = UserGroup::create(['name' => 'Profs', 'type' => 'role']);

        Livewire::test($this->componentPath(), ['groupId' => $role->id])
            ->assertSet('isClasse', false)
            ->assertDontSee('Partage de classe');
    }

    #[Test]
    public function it_aborts_404_when_group_id_not_found(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->componentPath(), ['groupId' => 99999])
            ->assertStatus(404);
    }

    // =========================================================================
    // AC 7 — état FS reflété
    // =========================================================================

    #[Test]
    public function it_reflects_existing_share_state_with_subdirs(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $classe = $this->makeClasse('6A');

        // Pré-conditions FS : dossier classe + sous-dirs créés.
        @mkdir($this->tempRoot . '/Classe_6A', 0o755, true);
        @mkdir($this->tempRoot . '/Classe_6A/_travail', 0o755, true);
        @mkdir($this->tempRoot . '/Classe_6A/_profs', 0o755, true);

        Livewire::test($this->componentPath(), ['groupId' => $classe->id])
            ->assertSet('shareExists', true)
            ->assertSet('subdirs._travail', true)
            ->assertSet('subdirs._profs', true)
            ->assertSet('subdirs._echange', false)
            ->assertSee('Partage créé');
    }

    // =========================================================================
    // AC 8 — double guard manage-share
    // =========================================================================

    #[Test]
    public function it_blocks_create_share_without_manage_permission(): void
    {
        // User avec share.view mais SANS share.manage.
        $viewer = $this->makeAdmin('viewer-only', perms: ['share.view']);
        $this->actingAs($viewer);
        $classe = $this->makeClasse('6A');

        Livewire::test($this->componentPath(), ['groupId' => $classe->id])
            ->call('createShare')
            ->assertStatus(403);
    }

    #[Test]
    public function it_blocks_toggle_echange_without_manage_permission(): void
    {
        $viewer = $this->makeAdmin('viewer-toggle', perms: ['share.view']);
        $this->actingAs($viewer);
        $classe = $this->makeClasse('6A');

        Livewire::test($this->componentPath(), ['groupId' => $classe->id])
            ->call('toggleEchange')
            ->assertStatus(403);
    }

    #[Test]
    public function it_invokes_create_share_with_manage_permission(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $classe = $this->makeClasse('6A');

        Livewire::test($this->componentPath(), ['groupId' => $classe->id])
            ->call('createShare')
            ->assertHasNoErrors();

        // Le ShareService a appelé `sudo mkdir` et `sudo setfacl` au moins une
        // fois pour le path de la classe.
        Process::assertRan(fn ($p) => str_contains($p->command, 'mkdir')
            && str_contains($p->command, 'Classe_6A'));
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'Classe_6A'));
    }

    // =========================================================================
    // AC 17 — share.view seul → readonly
    // =========================================================================

    #[Test]
    public function it_shows_readonly_for_view_only_permission(): void
    {
        $viewer = $this->makeAdmin('readonly-user', perms: ['share.view']);
        $this->actingAs($viewer);
        $classe = $this->makeClasse('6A');

        // Note : Blade encode les apostrophes en HTML (`&#039;`), on assert
        // sur la sous-chaîne sans l'apostrophe pour rester robuste.
        Livewire::test($this->componentPath(), ['groupId' => $classe->id])
            ->assertSee('pas la permission', escape: false)
            ->assertDontSee('Créer le partage')
            ->assertDontSee('Réappliquer les ACLs');
    }

    #[Test]
    public function it_shows_access_restricted_without_view_permission(): void
    {
        $noPerm = $this->makeAdmin('no-perm', perms: []);
        $this->actingAs($noPerm);
        $classe = $this->makeClasse('6A');

        Livewire::test($this->componentPath(), ['groupId' => $classe->id])
            ->assertSee('Accès restreint');
    }

    // =========================================================================
    // refresh — pas de side effect FS
    // =========================================================================

    #[Test]
    public function refresh_action_does_not_require_manage_permission(): void
    {
        $viewer = $this->makeAdmin('refresh-viewer', perms: ['share.view']);
        $this->actingAs($viewer);
        $classe = $this->makeClasse('6A');

        Livewire::test($this->componentPath(), ['groupId' => $classe->id])
            ->call('refresh')
            ->assertHasNoErrors();
    }
}
