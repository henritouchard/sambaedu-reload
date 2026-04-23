<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Enums\SambaPermission;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 7.2 (AC9) — Performance des @can (pas de N+1 sur listings).
 *
 * Vérifie que sur un listing de 50 users, la résolution des permissions
 * Spatie via `$user->can(...)` ne génère PAS 50 queries (cache warmed).
 *
 * Contrairement au listing Blade Livewire complet (difficile à tester en
 * isolation — il dépend du composant), on teste le coeur de la résolution :
 * N calls à `$user->can(...)` sur N users différents ne produit pas N queries
 * ILIKE `permissions` — le cache applicatif Spatie absorbe les checks après
 * la première hydratation.
 */
class CanResolutionTest extends TestCase
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
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    public function test_multiple_can_checks_on_same_user_does_not_multiply_queries(): void
    {
        $user = User::create(['login' => 'perf-u1', 'role' => 'prof', 'is_active' => true]);
        $user->assignRole('prof');

        DB::enableQueryLog();
        DB::flushQueryLog();

        // 20 calls `can()` sur le même user — cache applicatif Spatie doit
        // éviter la multiplication des requêtes.
        for ($i = 0; $i < 20; $i++) {
            $user->can(SambaPermission::UserRead->value);
            $user->can(SambaPermission::UserModify->value);
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            5,
            count($queries),
            sprintf('20 can() ne doivent pas émettre >5 queries (%d queries observées)', count($queries))
        );
    }

    /**
     * Review 7.2 #5 — AC9 reformulé : test du rendu Livewire effectif du
     * listing `/app/users` avec 20 users + 10 classes. Garantit qu'on ne tombe
     * pas dans un N+1 à cause du nouveau scoping classe (review 7.2 #3).
     *
     * Seuil conservateur (< 50 queries) — l'objectif est de détecter les N+1
     * catastrophiques (~1 query/user). Le chiffre exact dépend du composant
     * et de la pagination, mais un N+1 ferait exploser ce seuil.
     */
    public function test_livewire_users_index_rendering_stays_under_query_threshold(): void
    {
        UserGroupObserver::disableSync();

        // 10 classes + 20 users (2 par classe).
        $classes = [];
        for ($i = 0; $i < 10; $i++) {
            $classes[] = UserGroup::create([
                'name' => "perf-classe-{$i}",
                'display_name' => "Classe {$i}",
                'type' => 'class',
            ]);
        }

        $users = [];
        for ($i = 0; $i < 20; $i++) {
            $u = User::create([
                'login' => "perf-listing-{$i}",
                'firstname' => "FN-{$i}",
                'lastname' => "LN-{$i}",
                'role' => 'eleve',
                'is_active' => true,
            ]);
            $u->userGroups()->attach($classes[$i % 10]->id);
            $users[] = $u;
        }

        // Admin qui voit tout (pas de scoping classe qui filtrerait).
        $admin = User::create([
            'login' => 'perf-admin',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->assignRole('user-admin');

        $this->actingAs($admin);

        // Warmup du cache Spatie + pré-résolution des perms de l'admin.
        $admin->can('user.read');

        DB::enableQueryLog();
        DB::flushQueryLog();

        // Rendu du composant Livewire — déclenche la query computed `users()`.
        Livewire::test('pages::users.index');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        UserGroupObserver::enableSync();

        $this->assertLessThan(
            50,
            count($queries),
            sprintf(
                'Rendu Livewire users.index avec 20 users + 10 classes doit rester < 50 queries (observées: %d). '
                . 'Un N+1 sur le scoping classe ou les rôles ferait exploser ce seuil.',
                count($queries)
            )
        );
    }

    public function test_multiple_users_can_checks_stays_reasonable(): void
    {
        // Crée 10 users distincts, chacun vérifie une permission.
        // Le cache Spatie **permissions** (liste globale des perms) est warmup une fois.
        // Les relations `model_has_roles` / `model_has_permissions` doivent être fetched
        // au premier check de chaque user, mais pas plus — donc ≤ 2-3 queries/user.
        $users = [];
        for ($i = 0; $i < 10; $i++) {
            $u = User::create(['login' => "perf-u-{$i}", 'role' => 'prof', 'is_active' => true]);
            $u->assignRole('prof');
            $users[] = $u;
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        foreach ($users as $u) {
            $u->can(SambaPermission::UserRead->value);
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            30,
            count($queries),
            sprintf('10 can() sur 10 users distincts doit émettre ≤ 30 queries (observées: %d)', count($queries))
        );
    }
}
