<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Shares;

use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 34.3 — Tests Feature Livewire de la modale « Créer depuis un template »
 * (T3/T4, AC3) : formulaire dynamique, aperçu, matérialisation, gating policy.
 */
class SharesFromTemplateTest extends TestCase
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

        $this->tempRoot = sys_get_temp_dir() . '/netshare-tplui-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);

        foreach (['networkshare.view', 'networkshare.manage'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        (new DirectoryTemplateSeeder())->run();
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
    public function open_template_prefills_letter_and_lists_templates(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->assertSet('isTemplateOpen', true)
            ->assertSet('templateLetter', 'M:')
            ->assertSee('Profs → élèves');
    }

    #[Test]
    public function dynamic_form_shows_roles_of_selected_template(): void
    {
        UserGroup::create(['name' => 'profs6e', 'type' => 'equipe']);
        UserGroup::create(['name' => '6eB', 'type' => 'classe']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'profs_to_eleves')
            ->assertSee('Équipe enseignante')
            ->assertSee('Classe');
    }

    #[Test]
    public function preview_reflects_selected_targets(): void
    {
        $equipe = UserGroup::create(['name' => 'profs6e', 'type' => 'equipe']);
        $classe = UserGroup::create(['name' => '6eB', 'type' => 'classe']);
        $this->actingAs($this->manager());

        $component = Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'profs_to_eleves')
            ->set('roleSelections.profs', $equipe->id)
            ->set('roleSelections.eleves', $classe->id);

        $preview = $component->instance()->templatePreview();
        $this->assertCount(2, $preview);
        $labels = array_column($preview, 'label');
        $this->assertContains('profs6e', $labels);
        $this->assertContains('6eB', $labels);
    }

    #[Test]
    public function materializes_a_share_with_assignments_and_provisions(): void
    {
        $equipe = UserGroup::create(['name' => 'profs6e', 'type' => 'equipe']);
        $classe = UserGroup::create(['name' => '6eB', 'type' => 'classe']);
        $admin = $this->manager();
        $this->actingAs($admin);

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'profs_to_eleves')
            ->set('templateName', 'Devoirs 6eB')
            ->set('templateDirectoryName', 'devoirs_6eb')
            ->set('templateLetter', 'P:')
            ->set('roleSelections.profs', $equipe->id)
            ->set('roleSelections.eleves', $classe->id)
            ->call('createFromTemplate')
            ->assertHasNoErrors();

        $share = NetworkShare::where('directory_name', 'devoirs_6eb')->first();
        $this->assertNotNull($share);
        $this->assertSame($admin->id, $share->created_by_user_id);
        $this->assertDatabaseHas('network_share_assignables', [
            'network_share_id' => $share->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $equipe->id,
            'access' => 'rw',
        ]);
        $this->assertDatabaseHas('network_share_assignables', [
            'network_share_id' => $share->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $classe->id,
            'access' => 'ro',
        ]);
        Process::assertRan(fn ($p) => str_contains($p->command, 'mkdir'));
    }

    #[Test]
    public function user_to_user_materializes_two_rw_grants(): void
    {
        $a = User::create(['login' => 'alice', 'role' => 'prof', 'is_active' => true]);
        $b = User::create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'user_to_user')
            ->set('templateName', 'Échange AB')
            ->set('templateDirectoryName', 'echange_ab')
            ->set('roleSelections.user_a', $a->id)
            ->set('roleSelections.user_b', $b->id)
            ->call('createFromTemplate')
            ->assertHasNoErrors();

        $share = NetworkShare::where('directory_name', 'echange_ab')->firstOrFail();
        $this->assertSame(2, NetworkShareAssignable::where('network_share_id', $share->id)->where('access', 'rw')->count());
    }

    #[Test]
    public function rejects_reserved_letter(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Pirate')
            ->set('templateDirectoryName', 'pirate')
            ->set('templateLetter', 'K:')
            ->set('roleSelections.group', $group->id)
            ->call('createFromTemplate')
            ->assertHasErrors(['templateLetter']);

        $this->assertDatabaseCount('network_shares', 0);
    }

    #[Test]
    public function rejects_malformed_directory_name(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Bad')
            ->set('templateDirectoryName', 'bad name/../x')
            ->set('roleSelections.group', $group->id)
            ->call('createFromTemplate')
            ->assertHasErrors(['templateDirectoryName']);

        $this->assertDatabaseCount('network_shares', 0);
    }

    #[Test]
    public function rejects_duplicate_directory_name_with_clear_message(): void
    {
        NetworkShare::factory()->create(['directory_name' => 'devoirs_6eb']);
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Dup')
            ->set('templateDirectoryName', 'devoirs_6eb')
            ->set('roleSelections.group', $group->id)
            ->call('createFromTemplate')
            ->assertHasErrors(['templateDirectoryName']);
    }

    #[Test]
    public function letter_collision_surfaces_toast_error_and_creates_nothing(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $existing = NetworkShare::factory()->create(['directory_name' => 'existant', 'letter' => 'P:']);
        NetworkShareAssignable::create([
            'network_share_id' => $existing->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'access' => 'ro',
        ]);
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Collision')
            ->set('templateDirectoryName', 'collision')
            ->set('templateLetter', 'P:')
            ->set('roleSelections.group', $group->id)
            ->call('createFromTemplate')
            ->assertDispatched('toastMagic');

        $this->assertDatabaseMissing('network_shares', ['directory_name' => 'collision']);
    }

    #[Test]
    public function missing_required_target_surfaces_toast_error_and_creates_nothing(): void
    {
        // Finding #2 (review) — la cardinalité des rôles est validée côté service
        // (pas de règle Livewire field-level) : un rôle requis non renseigné
        // remonte en toast erreur (InvalidArgumentException → toastMagic), AUCUN
        // share/pivot n'est créé (refus AVANT transaction).
        $this->actingAs($this->manager());

        Livewire::test(self::PAGE)
            ->call('openTemplate')
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Sans cible')
            ->set('templateDirectoryName', 'sans_cible')
            // roleSelections.group volontairement NON renseigné
            ->call('createFromTemplate')
            ->assertHasNoErrors()
            ->assertDispatched('toastMagic');

        $this->assertDatabaseCount('network_shares', 0);
        $this->assertDatabaseCount('network_share_assignables', 0);
    }

    #[Test]
    public function viewer_cannot_open_or_materialize(): void
    {
        $group = UserGroup::create(['name' => 'cdi', 'type' => 'autre']);
        $this->actingAs($this->viewerOnly());

        Livewire::test(self::PAGE)
            ->set('selectedTemplateKey', 'group_space')
            ->set('templateName', 'Nope')
            ->set('templateDirectoryName', 'nope')
            ->set('roleSelections.group', $group->id)
            ->call('createFromTemplate')
            ->assertStatus(403);

        $this->assertDatabaseCount('network_shares', 0);
    }
}
