<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 36.7 (AC4) — exposition d'assignation du mécanisme `app_profile` (sortie
 * du socle) : il DOIT apparaître comme assignable dans la section « Capacités »
 * des GROUPES D'UTILISATEURS (son provider résout les assignations UserGroup) et
 * NE DOIT PAS être proposé sur la surface PARC (override poste/parc inerte — un
 * profil suit l'utilisateur, pas la machine).
 */
class AppProfileAssignmentExposureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        WorkstationGroupObserver::disableSync();

        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        Permission::firstOrCreate(['name' => 'app.customize', 'guard_name' => 'web']);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        WorkstationGroupObserver::enableSync();
        Mockery::close();
        parent::tearDown();
    }

    private function makeAppProfileCapability(): Capability
    {
        $cap = Capability::factory()->create([
            'key' => 'roaming_app_profile',
            'label' => 'Profil applicatif itinérant',
            'value_type' => 'toggle',
            'default_value' => 'on',
            'options' => [['value' => 'on', 'label' => 'Activé'], ['value' => 'off', 'label' => 'Désactivé']],
        ]);
        CapabilityProjection::factory()->for($cap)->create([
            'os' => 'windows',
            'mechanism' => CapabilityProjection::MECHANISM_APP_PROFILE,
            'spec' => ['apps' => [[
                'app' => 'firefox',
                'link' => 'AppData\\Roaming\\Mozilla\\Firefox\\managed.default',
                'server' => '.mozilla\\firefox\\managed.default',
                'profile_name' => 'managed.default',
                'enabled' => true,
            ]]],
        ]);

        return $cap;
    }

    #[Test]
    public function app_profile_is_assignable_on_user_group_section(): void
    {
        $cap = $this->makeAppProfileCapability();

        $admin = User::factory()->create(['login' => 'admin']);
        $admin->givePermissionTo('app.customize');
        $this->actingAs($admin);

        $group = UserGroup::create(['name' => 'classe-6a', 'type' => 'classe', 'display_name' => 'Classe 6A']);

        $component = Livewire::test('pages::users.groups.[id]._partials.capabilities-section', ['groupId' => $group->id]);

        $ids = array_column($component->instance()->capabilities(), 'id');
        self::assertContains($cap->id, $ids, 'AC4 : app_profile listé comme assignable par groupe d\'utilisateurs');

        // La pose d'override fonctionne (pas de refus d'assignabilité).
        $component->call('openAdd', $cap->id)->assertSet('showOverrideModal', true);
    }

    #[Test]
    public function app_profile_is_not_offered_on_parc_capabilities_tab(): void
    {
        $cap = $this->makeAppProfileCapability();

        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        $user->shouldReceive('can')->andReturn(true);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $this->actingAs($user);

        $parc = WorkstationGroup::factory()->logical()->create();

        $component = Livewire::test('pages::parc.groups._partials.capabilities-tab', ['groupId' => $parc->id]);

        $ids = array_column($component->instance()->addableCapabilities(), 'id');
        self::assertNotContains($cap->id, $ids,
            'AC4 : app_profile NON proposé par parc (override poste inerte)');
    }

    /**
     * Review 36.7 #1 (leçon 35.4) : le refus par parc ne peut PAS vivre seulement
     * dans le listing. Un rejeu Livewire de `openAdd()` sur app_profile doit être
     * refusé côté SERVEUR (defense-in-depth) — sinon override parc inerte posé.
     */
    #[Test]
    public function forged_open_add_of_app_profile_is_refused_server_side_on_parc(): void
    {
        $cap = $this->makeAppProfileCapability();

        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        $user->shouldReceive('can')->andReturn(true);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $this->actingAs($user);

        $parc = WorkstationGroup::factory()->logical()->create();

        // Rejeu direct de openAdd() (contournement du listing filtré) : la modale
        // ne doit PAS s'ouvrir — refus serveur, aucun override amorcé.
        Livewire::test('pages::parc.groups._partials.capabilities-tab', ['groupId' => $parc->id])
            ->call('openAdd', $cap->id)
            ->assertSet('showOverrideModal', false);
    }
}
