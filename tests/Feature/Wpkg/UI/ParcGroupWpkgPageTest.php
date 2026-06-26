<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\UI;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Wpkg\Deployment\Events\AppProfileWorkstationGroupChanged;
use App\Wpkg\Deployment\Events\WorkstationGroupApplicationsChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.4 / AC1, AC7.1 — Onglet "Applications WPKG" sur la page parc
 * (Décision A : onglet de premier niveau via ?tab=wpkg, pas de route séparée).
 *
 * NB : ces tests appellent directement les méthodes Livewire métier (attach/detach)
 * sans rendre le DOM intégral — la fiche groupe complète déclenche des Gates et
 * services qui ne sont pas l'objet du test (cf. MachineShowPageTest pour le
 * pattern feature complet). On vérifie ici la couche service+events branchée
 * via le composant.
 */
class ParcGroupWpkgPageTest extends TestCase
{
    private AppProfile $profile;
    private WorkstationGroup $group;
    private User $admin;
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        WpkgSchemaBootstrapper::bootstrap();

        $this->profile = AppProfile::create(['name' => 'p-1', 'is_active' => true]);
        $this->group = WorkstationGroup::create(['name' => 'parc-1']);
        $this->application = Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);

        // Stub Gate `wpkg.assign` à allow / deny selon les tests.
        Gate::define('wpkg.assign', fn ($user) => true);
        Gate::define('view', fn ($user, $model = null) => true);
        // Story 29.1 — l'enforcement WPKG passe désormais par le Gate SCOPÉ
        // (T4 : defense-in-depth dans AppProfileService). On le stube à allow
        // ici (le schéma Spatie permissions n'est pas bootstrappé ; cf.
        // WorkstationGroupPolicyWpkgTest / AppProfileServiceWpkgScopingTest pour
        // la couverture réelle de la décision d'autorisation).
        Gate::define('assign-wpkg-workstationGroup', fn ($user, $model = null) => true);

        $this->admin = $this->makeAdmin();
        // Story 29.1 — le Gate scopé est invoqué sous un user authentifié, ce qui
        // déclenche le before-hook Spatie (lecture de la table `permissions`).
        // On crée les tables Spatie minimales (vides) pour que le before-hook
        // n'échoue pas, puis le `Gate::define` stubé ci-dessus autorise l'action.
        $this->bootstrapSpatieTables();
        $this->actingAs($this->admin);
    }

    private function bootstrapSpatieTables(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::class;
        if (! $schema::hasTable('permissions')) {
            $schema::create('permissions', function ($t) {
                $t->id();
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
                $t->unique(['name', 'guard_name']);
            });
        }
        if (! $schema::hasTable('roles')) {
            $schema::create('roles', function ($t) {
                $t->id();
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
                $t->unique(['name', 'guard_name']);
            });
        }
        if (! $schema::hasTable('model_has_permissions')) {
            $schema::create('model_has_permissions', function ($t) {
                $t->unsignedBigInteger('permission_id');
                $t->string('model_type');
                $t->unsignedBigInteger('model_id');
                $t->primary(['permission_id', 'model_id', 'model_type'], 'mhp_primary');
            });
        }
        if (! $schema::hasTable('model_has_roles')) {
            $schema::create('model_has_roles', function ($t) {
                $t->unsignedBigInteger('role_id');
                $t->string('model_type');
                $t->unsignedBigInteger('model_id');
                $t->primary(['role_id', 'model_id', 'model_type'], 'mhr_primary');
            });
        }
        if (! $schema::hasTable('role_has_permissions')) {
            $schema::create('role_has_permissions', function ($t) {
                $t->unsignedBigInteger('permission_id');
                $t->unsignedBigInteger('role_id');
                $t->primary(['permission_id', 'role_id']);
            });
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'roles', 'permissions'] as $table) {
            \Illuminate\Support\Facades\Schema::dropIfExists($table);
        }
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        // Schema users minimaliste — créé si absent.
        if (! \Illuminate\Support\Facades\Schema::hasTable('users')) {
            \Illuminate\Support\Facades\Schema::create('users', function ($t) {
                $t->id();
                $t->string('login')->nullable();
                $t->string('name')->nullable();
                $t->string('email')->nullable();
                $t->string('password')->nullable();
                $t->timestamps();
            });
        }
        return User::create([
            'login' => 'admin',
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'hashed',
        ]);
    }

    #[Test]
    public function attach_profile_dispatches_event(): void
    {
        Event::fake([AppProfileWorkstationGroupChanged::class]);

        $svc = app(\App\Services\AppProfile\AppProfileService::class);
        $svc->addWorkstationGroups($this->profile->id, [$this->group->id]);

        Event::assertDispatched(AppProfileWorkstationGroupChanged::class, function ($e) {
            return $e->appProfileId === $this->profile->id
                && $e->workstationGroupId === $this->group->id
                && $e->direction === 'attached';
        });
        self::assertSame(1, $this->group->appProfiles()->count());
    }

    #[Test]
    public function attach_application_directly_to_group_dispatches_plural_event(): void
    {
        Event::fake([WorkstationGroupApplicationsChanged::class]);

        $svc = app(\App\Services\AppProfile\AppProfileService::class);
        $attached = $svc->addApplicationsToWorkstationGroup($this->group->id, [$this->application->id]);

        self::assertSame([$this->application->id], $attached);
        Event::assertDispatched(WorkstationGroupApplicationsChanged::class);
        self::assertSame(1, $this->group->applications()->count());
    }

    #[Test]
    public function detach_profile_dispatches_event(): void
    {
        $this->profile->workstationGroups()->attach([$this->group->id]);
        Event::fake([AppProfileWorkstationGroupChanged::class]);

        $svc = app(\App\Services\AppProfile\AppProfileService::class);
        $svc->removeWorkstationGroups($this->profile->id, [$this->group->id]);

        Event::assertDispatched(AppProfileWorkstationGroupChanged::class, function ($e) {
            return $e->direction === 'detached';
        });
    }

    // NB (Story 29.1) : l'ex-test `gate_denies_when_user_lacks_wpkg_assign` testait
    // l'ANCIEN gate global `wpkg.assign` (tautologique, sans rapport avec le scoping).
    // L'enforcement scopé `assign-wpkg-workstationGroup` est désormais couvert par
    // tests/Unit/Policies/WorkstationGroupPolicyWpkgTest.php (7 cas : positif/négatif
    // par salle, négative active, expiration, fallback global) et par
    // tests/Feature/AppProfile/AppProfileServiceWpkgScopingTest.php (couche service +
    // contexte non authentifié). Test supprimé pour ne pas créer de fausse confiance.
}
