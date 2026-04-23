<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Review 7.2 #3 — Le listing `/app/users` doit filtrer les users par classe
 * pour un Prof scopé classe (RGPD). Sans ce filtre, la Policy `UserPolicy::view`
 * est appliquée uniquement sur les targets individuels mais pas sur la liste.
 *
 * Scénarios :
 *  - Prof avec 1 classe → ne voit que ses élèves
 *  - Prof sans classe attachée → liste vide
 *  - UserAdmin / SuperAdmin → voit tout (bypass scoping)
 */
class UsersListingScopedTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->createPermissionSchema();
        (new PermissionSeeder())->run();
        UserGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeUser(string $login, string $role = 'eleve'): User
    {
        return User::create(['login' => $login, 'role' => $role, 'is_active' => true]);
    }

    private function makeClass(string $name): UserGroup
    {
        return UserGroup::create([
            'name' => $name,
            'display_name' => $name,
            'type' => 'class',
        ]);
    }

    public function test_prof_listing_sees_only_users_of_own_classes(): void
    {
        $prof = $this->makeUser('list-prof-1', 'prof');
        $prof->assignRole('prof');

        $classA = $this->makeClass('list-classeA');
        $classB = $this->makeClass('list-classeB');

        $elSame = $this->makeUser('list-el-same');
        $elOther = $this->makeUser('list-el-other');

        $prof->userGroups()->attach($classA->id);
        $elSame->userGroups()->attach($classA->id);
        $elOther->userGroups()->attach($classB->id);

        $this->actingAs($prof);

        $component = Livewire::test('pages::users.index');
        $paginator = $component->instance()->users;

        $visibleLogins = collect($paginator->items())
            ->map(fn(User $u) => $u->login)
            ->all();

        $this->assertContains($elSame->login, $visibleLogins);
        $this->assertNotContains(
            $elOther->login,
            $visibleLogins,
            "Le Prof ne doit PAS voir les users d'une autre classe (review 7.2 #3)"
        );
    }

    public function test_prof_without_class_sees_nobody(): void
    {
        $prof = $this->makeUser('list-prof-no-class', 'prof');
        $prof->assignRole('prof');

        $classA = $this->makeClass('list-classeX');
        $el = $this->makeUser('list-el-x');
        $el->userGroups()->attach($classA->id);

        $this->actingAs($prof);

        $component = Livewire::test('pages::users.index');
        $paginator = $component->instance()->users;

        $visibleLogins = collect($paginator->items())
            ->map(fn(User $u) => $u->login)
            ->all();

        // Un Prof sans classe attachée n'a accès à aucun user (le listing
        // retourne vide plutôt que de tout exposer — RGPD).
        $this->assertEmpty(
            array_intersect($visibleLogins, [$el->login, $prof->login]),
            'Prof sans classe ne doit rien voir (et pas lui-même via les classes)'
        );
    }

    public function test_user_admin_sees_everyone(): void
    {
        $admin = $this->makeUser('list-admin', 'admin');
        $admin->assignRole('user-admin');

        $classA = $this->makeClass('list-admin-classe');
        $el1 = $this->makeUser('list-admin-el1');
        $el2 = $this->makeUser('list-admin-el2');
        $el1->userGroups()->attach($classA->id);
        // el2 n'a pas de classe — doit quand même être visible par l'admin global.

        $this->actingAs($admin);

        $component = Livewire::test('pages::users.index');
        $paginator = $component->instance()->users;

        $visibleLogins = collect($paginator->items())
            ->map(fn(User $u) => $u->login)
            ->all();

        $this->assertContains($el1->login, $visibleLogins);
        $this->assertContains($el2->login, $visibleLogins);
    }
}
