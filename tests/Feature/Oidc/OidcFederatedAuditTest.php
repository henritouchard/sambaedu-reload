<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc;

use App\Auth\Federated\Session\FederatedSession;
use App\Http\Middleware\Auth\AuditExternalAction;
use App\Models\ExternalActionAuditLog;
use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\IssuesFederatedJwt;
use Tests\TestCase;

/**
 * Story 55.1 — correctif de review #1 : l'imputabilité d'une émission d'identité
 * OIDC pour un acteur FÉDÉRÉ.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI CE FICHIER EXISTE
 *
 *  `/oidc/authorize` est déclarée avec `federated.audit`, et le commentaire de
 *  la route justifie ce choix par l'imputabilité : « un acteur fédéré peut
 *  atteindre cette route, l'émission d'un jeton en son nom doit figurer au
 *  journal ».
 *
 *  Or {@see AuditExternalAction} n'audite un GET que si le NOM de sa route
 *  matche `federated_auth.audit.sensitive_get_routes`. Déclarer l'alias sur une
 *  route GET absente de cette liste en fait un NO-OP SILENCIEUX — la garantie
 *  affichée n'existe pas.
 *
 *  Et rien d'autre ne la rattrape : les logs du channel `oidc` omettent
 *  VOLONTAIREMENT le `sub` (NFR3), et `oidc_authorization_codes` — seule table
 *  portant `user_login` — est purgée au fil de l'eau (`code_purge_after`).
 *  Passé ce délai, plus rien ne dit qui a obtenu un token, pour quelle
 *  extension, ni quand.
 *
 *  ⚠️ Ce test lit la config RÉELLE de l'application (aucun `config([...])` de
 *  complaisance) : c'est le seul moyen qu'il tombe si quelqu'un retire
 *  `oidc.authorize` de l'allowlist.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Le middleware est exercé directement (même parti-pris que
 * {@see \Tests\Feature\Auth\Federated\ExternalActionAuditTest}) : déterministe
 * sur l'hôte, sans LDAP ni PostgreSQL.
 */
class OidcFederatedAuditTest extends TestCase
{
    use IssuesFederatedJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFederatedAuth();
        $this->ensureFederatedTables();

        Role::firstOrCreate(['name' => 'technicien', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function provisionFederatedActor(string $sub = 'sub-oidc'): User
    {
        $identity = ExternalIdentity::create([
            'external_sub' => $sub,
            'issuer' => $this->federatedTestIss,
            'login' => 'tech.externe',
            'name' => 'Tech Externe',
            'email' => 'tech@example.org',
            'is_active' => true,
        ]);

        $user = new User();
        $user->login = 'ext:'.$sub;
        $user->fullname = 'Tech Externe';
        $user->email = 'tech@example.org';
        $user->source = 'federated';
        $user->external_identity_id = $identity->id;
        $user->role = 'federated';
        $user->is_active = true;
        $user->save();

        $user->syncRoles(['technicien']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Auth::login($user->fresh());

        return $user->fresh();
    }

    private function makeRequest(string $uri, ?string $routeName, bool $federated): Request
    {
        $request = Request::create($uri, 'GET');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        if ($federated) {
            FederatedSession::mark($request, Auth::user()?->external_identity_id);
        }

        if ($routeName !== null) {
            $route = new IlluminateRoute(['GET'], $uri, []);
            $route->name($routeName);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
        }

        return $request;
    }

    private function runMiddleware(Request $request, int $status = 302): Response
    {
        $response = (new AuditExternalAction())->handle(
            $request,
            fn () => new Response('', $status),
        );

        (new AuditExternalAction())->terminate($request, $response);

        return $response;
    }

    #[Test]
    public function an_authorize_request_by_a_federated_actor_is_actually_written_to_the_audit_journal(): void
    {
        $user = $this->provisionFederatedActor('sub-oidc-granted');

        $request = $this->makeRequest(
            '/oidc/authorize?client_id=abc&redirect_uri=https%3A%2F%2Fext.example.test%2Fcb',
            'oidc.authorize',
            federated: true,
        );

        $this->runMiddleware($request, 302);

        self::assertSame(1, ExternalActionAuditLog::count(), 'une ligne d\'audit, pas une intention');

        $log = ExternalActionAuditLog::first();
        self::assertSame('oidc.authorize', $log->route_name);
        self::assertSame('GET', $log->http_method);
        self::assertSame(302, $log->status_code);
        self::assertSame('ext:sub-oidc-granted', $log->actor_login);
        self::assertSame('sub-oidc-granted', $log->actor_external_sub);
        self::assertSame('federated', $log->source);
        self::assertSame($user->id, $log->user_id);
    }

    #[Test]
    public function an_unlisted_get_route_writes_nothing_which_is_precisely_what_the_allowlist_does(): void
    {
        // Contrôle NÉGATIF, et démonstration du bug corrigé : sur une route GET
        // ABSENTE de l'allowlist, le middleware — pourtant exécuté — n'écrit
        // rien. C'était exactement l'état de `oidc.authorize` avant ce correctif.
        // Sans ce test, le précédent ne prouverait pas que c'est l'allowlist qui
        // fait le travail.
        $this->provisionFederatedActor('sub-oidc-muet');

        $request = $this->makeRequest('/app/dashboard', 'app.dashboard', federated: true);

        $this->runMiddleware($request, 200);

        self::assertSame(0, ExternalActionAuditLog::count());
    }

    #[Test]
    public function a_non_federated_actor_never_touches_this_journal(): void
    {
        // Le journal reste réservé aux acteurs externes : un utilisateur AD
        // local qui fait du SSO n'y écrit rien (invariant Story 20.4, AC2).
        $user = new User();
        $user->login = 'prof.dupont';
        $user->role = 'autre';
        $user->is_active = true;
        $user->save();

        Auth::login($user);

        $request = $this->makeRequest('/oidc/authorize?client_id=abc', 'oidc.authorize', federated: false);

        $this->runMiddleware($request, 302);

        self::assertSame(0, ExternalActionAuditLog::count());
    }
}
