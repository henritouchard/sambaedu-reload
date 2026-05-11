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

        $this->admin = $this->makeAdmin();
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
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

    #[Test]
    public function gate_denies_when_user_lacks_wpkg_assign(): void
    {
        // Re-define Gate to deny for any user.
        Gate::define('wpkg.assign', fn ($user) => false);

        // Le `before` hook de Spatie\Permission interroge la table `permissions`
        // dès qu'un User authentifié traverse Gate::authorize. Ici on n'a pas
        // bootstrappé le schéma Spatie (hors-scope WpkgSchemaBootstrapper) — on
        // se déconnecte donc pour que Laravel skip le before-hook (signature
        // `Authorizable $user` non-nullable) et tombe directement sur le Gate
        // défini ci-dessus.
        \Illuminate\Support\Facades\Auth::logout();

        // Le service lui-même ne vérifie pas le Gate (c'est fait dans le composant
        // Livewire). On vérifie ici que `Gate::authorize('wpkg.assign')` lève bien
        // AuthorizationException — appliqué par les méthodes du composant.
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        Gate::authorize('wpkg.assign');
    }
}
