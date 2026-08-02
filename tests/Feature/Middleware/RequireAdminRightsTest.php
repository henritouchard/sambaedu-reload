<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Enums\SambaRole;
use App\Http\Middleware\RequireAdminRights;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\AuthenticationService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Story 49.2 (AC6) — `sambaedu.admin` décide sur des données Postgres.
 *
 * Le middleware lisait le DTO LDAP posé par le guard (`memberOf`, groupes de la
 * branche `OU=rights`). Il lit désormais le User Eloquent et ses colonnes
 * miroir. **Les motifs sont identiques** : bascule de transport, pas de
 * sémantique. Ce test fige les quatre entrées (super-admin, `ad_right_profiles`,
 * nom de groupe, refus) et documente l'écart assumé de D4.
 *
 * Enjeu concret : sur les routes quota (`web.php`), ce middleware est la SEULE
 * garde. Une erreur d'appréciation y est directement exploitable.
 */
class RequireAdminRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder())->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        Mockery::close();
        parent::tearDown();
    }

    /** Middleware réel ; `AuthenticationService` mocké (il lit `$_SESSION`). */
    private function makeMiddleware(?string $fallbackLogin = null): RequireAdminRights
    {
        $authService = Mockery::mock(AuthenticationService::class);
        $authService->shouldReceive('getCurrentUser')->andReturn($fallbackLogin);

        return new RequireAdminRights($authService);
    }

    /**
     * Requête JSON (statuts déterministes : 403 refusé / 401 non résolu, là où
     * le chemin HTML fait un `back()` dépendant du Referer).
     */
    private function jsonRequest(?User $user, ?string $login = null): Request
    {
        $request = Request::create('/app/admin/quotas', 'GET', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        if ($user !== null) {
            $request->attributes->set('sambaedu_user', $user);
        }
        if ($login !== null) {
            $request->attributes->set('sambaedu_login', $login);
        }

        return $request;
    }

    /** @return array{0: bool, 1: Response} */
    private function runMiddleware(RequireAdminRights $middleware, Request $request): array
    {
        $passed = false;

        $response = $middleware->handle($request, function () use (&$passed): Response {
            $passed = true;
            return new Response('ok', 200);
        });

        return [$passed, $response];
    }

    // ========================================================================
    // Voie 1 — super-admin (clause VITALE, testée en premier)
    // ========================================================================

    #[Test]
    public function the_protected_admin_passes_with_no_group_and_no_right_profile(): void
    {
        // LE scénario sinistre que la clause `hasRole(SuperAdmin)` prévient :
        // `admin` figure dans `MainGroups::SYSTEM_ACCOUNTS`, il est donc filtré
        // du balayage AD→SQL et n'a NI appartenance NI `ad_right_profiles`. Les
        // deux autres voies le rateraient, et il serait verrouillé hors de ses
        // propres routes.
        $admin = User::create([
            'login' => User::PROTECTED_ADMIN_LOGIN,
            'role' => 'autre',
            'is_active' => true,
        ]);
        $admin->assignRole(SambaRole::SuperAdmin->value);

        $this->assertSame([], $admin->userGroups()->pluck('name')->all());
        $this->assertNull($admin->ad_right_profiles);

        [$passed] = $this->runMiddleware($this->makeMiddleware(), $this->jsonRequest($admin->fresh()));

        $this->assertTrue($passed, 'Le compte protégé doit garder l\'accès à ses routes');
    }

    #[Test]
    public function a_delegated_super_admin_passes(): void
    {
        $delegue = User::create(['login' => 'super.delegue', 'role' => 'prof', 'is_active' => true]);
        $delegue->assignRole(SambaRole::SuperAdmin->value);

        [$passed] = $this->runMiddleware($this->makeMiddleware(), $this->jsonRequest($delegue->fresh()));

        $this->assertTrue($passed);
    }

    // ========================================================================
    // Voie 2 — profils de droits AD (`ad_right_profiles`)
    // ========================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminRightProfileProvider(): array
    {
        // Exactement les motifs d'avant la bascule, y compris leur caractère
        // NON ancré (`str_contains`) : `SE_user_admin_etab` matche.
        return [
            'user_admin' => ['user_admin'],
            'computer_admin' => ['computer_admin'],
            'admin_se4' => ['admin_se4'],
            'admins' => ['admins'],
            'administrateurs' => ['Administrateurs'],
            'motif non ancré' => ['SE_user_admin_0123456X'],
            'casse indifférente' => ['USER_ADMIN'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminRightProfileProvider')]
    #[Test]
    public function an_ad_right_profile_grants_access(string $profile): void
    {
        $user = User::create([
            'login' => 'droit.' . md5($profile),
            'role' => 'prof',
            'is_active' => true,
            'ad_right_profiles' => [$profile],
        ]);

        [$passed] = $this->runMiddleware($this->makeMiddleware(), $this->jsonRequest($user->fresh()));

        $this->assertTrue($passed, "Le profil de droits « {$profile} » doit ouvrir l'accès");
    }

    #[Test]
    public function an_unrelated_right_profile_does_not_grant_access(): void
    {
        $user = User::create([
            'login' => 'droit.anodin',
            'role' => 'prof',
            'is_active' => true,
            'ad_right_profiles' => ['share_refresh', 'eleve_admin'],
        ]);

        [$passed, $response] = $this->runMiddleware($this->makeMiddleware(), $this->jsonRequest($user->fresh()));

        $this->assertFalse($passed);
        $this->assertSame(403, $response->getStatusCode());
    }

    // ========================================================================
    // Voie 3 — noms de groupes SQL
    // ========================================================================

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function groupNameProvider(): array
    {
        return [
            'Administrateurs' => ['Administrateurs', true],
            'Domain Admins' => ['Domain Admins', true],
            'motif non ancré' => ['Admins-etab', true],
            'classe ordinaire' => ['3emeA', false],
            'Profs' => ['Profs', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('groupNameProvider')]
    #[Test]
    public function group_membership_is_evaluated_on_sql_names(string $groupName, bool $expected): void
    {
        $user = User::create([
            'login' => 'groupe.' . md5($groupName),
            'role' => 'prof',
            'is_active' => true,
        ]);

        $group = UserGroup::create([
            'name' => $groupName,
            'display_name' => $groupName,
            'type' => 'classe',
        ]);
        $user->userGroups()->attach($group->id);

        [$passed] = $this->runMiddleware($this->makeMiddleware(), $this->jsonRequest($user->fresh()));

        $this->assertSame($expected, $passed);
    }

    // ========================================================================
    // Refus
    // ========================================================================

    #[Test]
    public function a_user_without_anything_is_refused(): void
    {
        $user = User::create(['login' => 'monsieur.rien', 'role' => 'prof', 'is_active' => true]);

        [$passed, $response] = $this->runMiddleware($this->makeMiddleware(), $this->jsonRequest($user->fresh()));

        $this->assertFalse($passed);
        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function no_resolvable_user_redirects_to_login(): void
    {
        [$passed, $response] = $this->runMiddleware(
            $this->makeMiddleware(),
            $this->jsonRequest(null),
        );

        $this->assertFalse($passed);
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Écart ASSUMÉ et documenté (D4) : une délégation Spatie autre que
     * `super-admin` n'ouvre PAS ce middleware. Elle ne l'ouvrait pas davantage
     * avant la bascule (le DTO LDAP ne connaissait pas les rôles Spatie) — c'est
     * donc bien une parité, et non un durcissement introduit ici. La
     * simplification vers du Spatie pur est un chantier d'extinction ultérieur.
     */
    #[Test]
    public function a_spatie_user_admin_alone_does_not_open_this_middleware(): void
    {
        $user = User::create(['login' => 'user.admin.spatie', 'role' => 'prof', 'is_active' => true]);
        $user->assignRole(SambaRole::UserAdmin->value);

        [$passed, $response] = $this->runMiddleware($this->makeMiddleware(), $this->jsonRequest($user->fresh()));

        $this->assertFalse($passed);
        $this->assertSame(403, $response->getStatusCode());
    }

    // ========================================================================
    // Résolution de l'utilisateur
    // ========================================================================

    #[Test]
    public function the_user_is_resolved_from_the_login_attribute_when_absent(): void
    {
        $admin = User::create(['login' => 'admin.par.login', 'role' => 'prof', 'is_active' => true]);
        $admin->assignRole(SambaRole::SuperAdmin->value);

        [$passed] = $this->runMiddleware(
            $this->makeMiddleware(),
            $this->jsonRequest(null, 'admin.par.login'),
        );

        $this->assertTrue($passed);
    }

    #[Test]
    public function the_user_is_resolved_from_the_auth_service_as_last_resort(): void
    {
        $admin = User::create(['login' => 'admin.par.session', 'role' => 'prof', 'is_active' => true]);
        $admin->assignRole(SambaRole::SuperAdmin->value);

        [$passed] = $this->runMiddleware(
            $this->makeMiddleware('admin.par.session'),
            $this->jsonRequest(null),
        );

        $this->assertTrue($passed);
    }

    #[Test]
    public function a_non_eloquent_attribute_is_ignored_and_re_resolved(): void
    {
        // Défense de bord : un guard alternatif (ou un test) pourrait poser autre
        // chose qu'un Eloquent dans `sambaedu_user`. Le middleware ne doit ni
        // planter, ni faire confiance à cet objet.
        $admin = User::create(['login' => 'admin.hybride', 'role' => 'prof', 'is_active' => true]);
        $admin->assignRole(SambaRole::SuperAdmin->value);

        $request = Request::create('/app/admin/quotas', 'GET', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $request->attributes->set('sambaedu_user', new \stdClass());
        $request->attributes->set('sambaedu_login', 'admin.hybride');

        [$passed] = $this->runMiddleware($this->makeMiddleware(), $request);

        $this->assertTrue($passed);
    }

    // ========================================================================
    // Correction de review — symétrie D2 : les voies de secours excluent aussi
    // les comptes fédérés
    // ========================================================================

    /**
     * L'exclusion des fédérés tenait « par construction » tant que la
     * résolution passait par le LDAP (un externe n'y existe pas). En basculant
     * sur SQL, elle devait être recodée — le guard l'a fait (D2), les deux
     * voies de secours de ce middleware l'avaient perdue.
     */
    #[Test]
    public function the_login_fallback_never_resolves_a_federated_homonym(): void
    {
        $federated = User::create([
            'login' => 'homonyme',
            'role' => 'autre',
            'is_active' => true,
        ]);
        $federated->source = 'federated';
        $federated->save();
        $federated->assignRole(SambaRole::SuperAdmin->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Aucun `sambaedu_user` posé : on force la 2ᵉ voie (par `sambaedu_login`).
        [$passed, $response] = $this->runMiddleware(
            $this->makeMiddleware(),
            $this->jsonRequest(null, 'homonyme')
        );

        self::assertFalse($passed, 'un compte fédéré ne doit pas être résolu comme identité native');
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function the_auth_service_fallback_never_resolves_a_federated_homonym(): void
    {
        $federated = User::create([
            'login' => 'homonyme2',
            'role' => 'autre',
            'is_active' => true,
        ]);
        $federated->source = 'federated';
        $federated->save();
        $federated->assignRole(SambaRole::SuperAdmin->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Ni `sambaedu_user` ni `sambaedu_login` : on force la 3ᵉ voie.
        [$passed, $response] = $this->runMiddleware(
            $this->makeMiddleware('homonyme2'),
            $this->jsonRequest(null)
        );

        self::assertFalse($passed);
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function a_native_account_is_still_resolved_by_the_fallbacks(): void
    {
        // Contre-épreuve : l'exclusion ne doit pas avoir cassé la voie elle-même.
        $native = User::create([
            'login' => 'admin.local',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $native->assignRole(SambaRole::SuperAdmin->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$passed] = $this->runMiddleware(
            $this->makeMiddleware(),
            $this->jsonRequest(null, 'admin.local')
        );

        self::assertTrue($passed);
    }
}
