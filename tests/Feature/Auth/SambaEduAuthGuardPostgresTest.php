<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\Federated\ExternalIdentityLifecycleService;
use App\Auth\Federated\Session\FederatedSession;
use App\Http\Middleware\Auth\SambaEduAuthGuard;
use App\Models\ExternalIdentity;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Story 49.2 — le guard de session lit Postgres, et RIEN d'autre.
 *
 * C'est le test de non-régression du point le plus sensible du produit : une
 * erreur ici n'abîme pas un écran, elle empêche tout le monde de se connecter
 * ou laisse entrer quelqu'un qui ne devrait pas. La dernière refonte du guard
 * (Epic 20) a produit LA régression de référence du dépôt — le flux fédéré
 * déconnecté à chaque requête par une revérification annuaire.
 *
 * Couvre AC1 (existence + activité SQL, fédérés exclus du lookup natif, zéro
 * LDAP), AC3 (branche fédérée intacte) et les cinq scénarios verrous d'AC11.
 */
class SambaEduAuthGuardPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================================================
    // Outillage
    // ========================================================================

    /**
     * Guard réel, `AuthenticationService` mocké (il lit `$_SESSION`, hors de
     * portée d'un test HTTP). Le compteur de `logout()` est renvoyé par
     * référence pour distinguer « refusé » de « refusé ET déconnecté ».
     */
    private function makeGuard(?string $sessionLogin, ?int &$logoutCalls = null): SambaEduAuthGuard
    {
        $logoutCalls = 0;

        $authService = Mockery::mock(AuthenticationService::class);
        $authService->shouldReceive('isAlreadyAuthenticated')->andReturn($sessionLogin !== null);
        $authService->shouldReceive('getCurrentUser')->andReturn($sessionLogin);
        $authService->shouldReceive('logout')->andReturnUsing(function () use (&$logoutCalls) {
            $logoutCalls++;
            return null;
        });

        return new SambaEduAuthGuard($authService, new ExternalIdentityLifecycleService());
    }

    private function makeRequest(string $uri = '/app/dashboard'): Request
    {
        $request = Request::create($uri, 'GET');
        $session = new Store('guard-test-' . uniqid(), new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }

    /** @return array{0: bool, 1: Response, 2: Request} */
    private function runGuard(SambaEduAuthGuard $guard, ?Request $request = null): array
    {
        $request ??= $this->makeRequest();
        $passed = false;

        $response = $guard->handle($request, function () use (&$passed): Response {
            $passed = true;
            return new Response('ok', 200);
        });

        return [$passed, $response, $request];
    }

    // ========================================================================
    // AC1 — zéro LDAP sur le chemin du guard
    // ========================================================================

    #[Test]
    public function guard_has_no_ldap_dependency_at_all(): void
    {
        // Verrou STRUCTUREL, et non comportemental : un test « le repo n'est pas
        // appelé » resterait vert si un futur développeur réintroduisait un
        // chemin LDAP par une autre porte (résolution via le container,
        // `LdapUser::findByLogin()` en statique…). On interdit donc le symbole.
        $constructor = (new ReflectionClass(SambaEduAuthGuard::class))->getConstructor();
        $types = array_map(
            static fn ($p) => (string) $p->getType(),
            $constructor?->getParameters() ?? [],
        );

        $this->assertNotContains(
            UserRepository::class,
            $types,
            'Le guard ne doit plus recevoir de UserRepository (FR-R4)',
        );

        $source = file_get_contents((new ReflectionClass(SambaEduAuthGuard::class))->getFileName());
        foreach (['UserRepository', 'LdapUser', 'AuthUser', 'LdapRecord'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "Aucune référence à {$forbidden} ne doit subsister dans le guard : "
                . 'il s\'exécute à chaque requête HTTP.',
            );
        }
    }

    // ========================================================================
    // AC11-1 — session native nominale
    // ========================================================================

    #[Test]
    public function native_session_passes_and_exposes_the_eloquent_user(): void
    {
        $user = User::create([
            'login' => 'prof.dupont',
            'fullname' => 'Prof Dupont',
            'role' => 'prof',
            'is_active' => true,
        ]);

        [$passed, , $request] = $this->runGuard($this->makeGuard('prof.dupont'));

        $this->assertTrue($passed);
        $this->assertInstanceOf(User::class, $request->attributes->get('sambaedu_user'));
        $this->assertSame($user->id, $request->attributes->get('sambaedu_user')->id);
        $this->assertSame('prof.dupont', $request->attributes->get('sambaedu_login'));
        $this->assertTrue(Auth::check());
        $this->assertInstanceOf(User::class, Auth::user());
        $this->assertSame($user->id, Auth::id());
    }

    #[Test]
    public function native_session_is_case_insensitive_on_login(): void
    {
        // AD est case-insensitive sur sAMAccountName, Postgres ne l'est pas :
        // sans `LOWER()`, une session ouverte en `JDOE` serait déconnectée.
        User::create(['login' => 'jdoe', 'role' => 'prof', 'is_active' => true]);

        [$passed] = $this->runGuard($this->makeGuard('JDOE'));

        $this->assertTrue($passed);
    }

    #[Test]
    public function navigating_several_requests_keeps_the_session(): void
    {
        User::create(['login' => 'prof.multi', 'role' => 'prof', 'is_active' => true]);
        $guard = $this->makeGuard('prof.multi');

        foreach (range(1, 3) as $i) {
            [$passed] = $this->runGuard($guard);
            $this->assertTrue($passed, "Requête #{$i} refusée");
        }
    }

    // ========================================================================
    // AC11-3 — compte désactivé : DÉCONNECTÉ au coup d'après
    // ========================================================================

    #[Test]
    public function deactivated_user_is_refused_and_logged_out(): void
    {
        $user = User::create(['login' => 'parti.hier', 'role' => 'prof', 'is_active' => true]);
        Auth::login($user);

        // Façon 49.3 : la réconciliation des départs pose `is_active = false`.
        $user->update(['is_active' => false]);

        $guard = $this->makeGuard('parti.hier', $logoutCalls);
        [$passed, $response] = $this->runGuard($guard);

        $this->assertFalse($passed);
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(1, $logoutCalls, 'La session legacy doit être détruite');
        $this->assertFalse(
            Auth::check(),
            'Le guard `web` doit être déconnecté : sans cela la session survit au refus '
            . 'et l\'utilisateur boucle indéfiniment sur la redirection vers /login.',
        );
    }

    #[Test]
    public function a_user_deactivated_mid_session_is_refused_on_the_very_next_request(): void
    {
        // Vérifie l'ABSENCE de fenêtre de cache : l'ancien chemin passait par un
        // `Cache::remember` LDAP de 60 s, pendant lesquelles un compte désactivé
        // continuait de naviguer.
        $user = User::create(['login' => 'a.desactiver', 'role' => 'prof', 'is_active' => true]);
        $guard = $this->makeGuard('a.desactiver');

        [$first] = $this->runGuard($guard);
        $this->assertTrue($first);

        $user->update(['is_active' => false]);

        [$second] = $this->runGuard($guard);
        $this->assertFalse($second, 'Aucune fenêtre de cache ne doit subsister sur le chemin du guard');
    }

    // ========================================================================
    // AC11-4 — compte inexistant en SQL
    // ========================================================================

    #[Test]
    public function unknown_login_is_refused_and_legacy_session_destroyed(): void
    {
        $guard = $this->makeGuard('fantome', $logoutCalls);
        [$passed, $response] = $this->runGuard($guard);

        $this->assertFalse($passed);
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(1, $logoutCalls);
    }

    #[Test]
    public function guard_never_creates_a_users_row(): void
    {
        // L'auto-provisioning a MIGRÉ vers la cérémonie de login (D1) : le laisser
        // ici maintenait un canal LDAP déclenché par toute ligne SQL manquante.
        $this->assertSame(0, User::count());

        $this->runGuard($this->makeGuard('nouveau.venu'));

        $this->assertSame(0, User::count(), 'Le guard ne doit plus rien provisionner');
    }

    // ========================================================================
    // AC1 / D2 — le lookup natif EXCLUT les comptes fédérés
    // ========================================================================

    #[Test]
    public function a_native_session_never_aligns_on_a_federated_row(): void
    {
        // Piège d'homonymie : le `findByLogin` LDAP excluait implicitement les
        // externes (ils n'existent pas dans l'annuaire), le `findByLogin` SQL les
        // trouverait. Une session native ne doit JAMAIS s'aligner sur eux.
        $federated = User::create([
            'login' => 'homonyme',
            'role' => 'federated',
            'is_active' => true,
        ]);
        $federated->source = 'federated';
        $federated->save();

        $guard = $this->makeGuard('homonyme', $logoutCalls);
        [$passed] = $this->runGuard($guard);

        $this->assertFalse($passed, 'Une session native ne doit pas être servie par une ligne fédérée');
        $this->assertSame(1, $logoutCalls);
        $this->assertFalse(Auth::check());
    }

    #[Test]
    public function a_row_created_without_source_is_served_as_native(): void
    {
        // `source` n'est PAS mass-assignable : la sync, l'auto-provisioning et
        // toute création ordinaire laissent donc la colonne à son défaut. Si le
        // lookup natif exigeait `source = 'ad'` explicitement, ou traitait le
        // défaut comme suspect, c'est TOUT le parc qui serait déconnecté.
        //
        // La migration `add_source_and_external_identity_to_users_table` déclare
        // la colonne NOT NULL DEFAULT 'ad' — un `null` est donc impossible en
        // base réelle ; la clause `orWhereNull` du guard est une ceinture pour
        // les schémas de test montés à la main, pas un cas de production.
        $user = User::create(['login' => 'ancien.compte', 'role' => 'prof', 'is_active' => true]);

        $this->assertSame('ad', $user->fresh()->getAttribute('source'));

        [$passed] = $this->runGuard($this->makeGuard('ancien.compte'));

        $this->assertTrue($passed);
    }

    // ========================================================================
    // AC11-5 — réalignement du guard web
    // ========================================================================

    #[Test]
    public function stale_web_session_is_realigned_on_the_legacy_session_login(): void
    {
        // Sans ce réalignement, une session web résiduelle ferait passer les
        // middlewares `can:*` sur l'identité du user PRÉCÉDENT.
        $previous = User::create(['login' => 'precedent', 'role' => 'prof', 'is_active' => true]);
        $current = User::create(['login' => 'courant', 'role' => 'prof', 'is_active' => true]);

        Auth::login($previous);
        $this->assertSame($previous->id, Auth::id());

        [$passed] = $this->runGuard($this->makeGuard('courant'));

        $this->assertTrue($passed);
        $this->assertSame($current->id, Auth::id());
    }

    // ========================================================================
    // AC3 / AC11-2 — branche fédérée intacte
    // ========================================================================

    #[Test]
    public function federated_session_is_untouched_by_the_postgres_switch(): void
    {
        $identity = ExternalIdentity::create([
            'external_sub' => 'ext-49-2',
            'issuer' => 'idp-test',
            'login' => 'tech.externe',
            'is_active' => true,
        ]);

        $user = User::create([
            'login' => 'ext:ext-49-2',
            'fullname' => 'Tech Externe',
            'role' => 'federated',
            'is_active' => true,
        ]);
        $user->source = 'federated';
        $user->external_identity_id = $identity->id;
        $user->save();

        $request = $this->makeRequest();
        FederatedSession::mark($request, $identity->id);

        [$passed, , $handled] = $this->runGuard($this->makeGuard('ext:ext-49-2'), $request);

        $this->assertTrue($passed, 'Un technicien externe doit continuer à naviguer après la bascule');
        $this->assertSame($user->id, $handled->attributes->get('sambaedu_user')->id);
        $this->assertSame($user->id, Auth::id());

        FederatedSession::forget($request);
    }

    #[Test]
    public function a_federated_user_is_not_judged_on_users_is_active(): void
    {
        // La révocation d'un externe passe par `ExternalIdentity.is_active`, pas
        // par la colonne du miroir AD — ce compte n'est jamais synchronisé.
        $identity = ExternalIdentity::create([
            'external_sub' => 'ext-49-2-bis',
            'issuer' => 'idp-test',
            'is_active' => true,
        ]);

        $user = User::create([
            'login' => 'ext:ext-49-2-bis',
            'role' => 'federated',
            'is_active' => false,
        ]);
        $user->source = 'federated';
        $user->external_identity_id = $identity->id;
        $user->save();

        $request = $this->makeRequest();
        FederatedSession::mark($request, $identity->id);

        [$passed] = $this->runGuard($this->makeGuard('ext:ext-49-2-bis'), $request);

        $this->assertTrue($passed);

        FederatedSession::forget($request);
    }

    #[Test]
    public function a_revoked_external_identity_is_still_logged_out(): void
    {
        $identity = ExternalIdentity::create([
            'external_sub' => 'ext-49-2-ter',
            'issuer' => 'idp-test',
            'is_active' => false,
        ]);

        $user = User::create([
            'login' => 'ext:ext-49-2-ter',
            'role' => 'federated',
            'is_active' => true,
        ]);
        $user->source = 'federated';
        $user->external_identity_id = $identity->id;
        $user->save();

        $request = $this->makeRequest();
        FederatedSession::mark($request, $identity->id);

        [$passed] = $this->runGuard($this->makeGuard('ext:ext-49-2-ter'), $request);

        $this->assertFalse($passed);

        FederatedSession::forget($request);
    }

    // ========================================================================
    // Early-returns préservés
    // ========================================================================

    #[Test]
    public function unauthenticated_session_is_refused_before_any_lookup(): void
    {
        User::create(['login' => 'peu.importe', 'role' => 'prof', 'is_active' => true]);

        [$passed, $response] = $this->runGuard($this->makeGuard(null));

        $this->assertFalse($passed);
        $this->assertNotSame(200, $response->getStatusCode());
    }

    #[Test]
    public function empty_login_is_refused(): void
    {
        [$passed, $response] = $this->runGuard($this->makeGuard(''));

        $this->assertFalse($passed);
        $this->assertNotSame(200, $response->getStatusCode());
    }
}
