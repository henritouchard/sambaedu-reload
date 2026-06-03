<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\Federated;

use App\Auth\Federated\ExternalIdentityLifecycleService;
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
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\IssuesFederatedJwt;
use Tests\TestCase;

/**
 * Story 20.4 — Feature : middleware d'audit dénormalisé des actions externes.
 *
 * Exerce directement le middleware {@see AuditExternalAction} (parité avec
 * `FederatedLoginEndpointTest` qui exerce guard/controller directement) afin
 * de rester déterministe sur le host (pas de LDAP/PG). Vraie DB SQLite.
 *
 * Couvre AC1..AC7 + AC9.
 */
class ExternalActionAuditTest extends TestCase
{
    use IssuesFederatedJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFederatedAuth();
        $this->ensureFederatedTables();
        config([
            'federated_auth.audit.sensitive_get_routes' => [
                'app.user.show',
                'app.users.*',
            ],
        ]);
        Role::firstOrCreate(['name' => 'technicien', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Provisionne une identité externe + un User fédéré loggué avec un rôle.
     *
     * @return array{identity: ExternalIdentity, user: User}
     */
    private function provisionFederatedActor(string $sub = 'sub-tech', string $role = 'technicien'): array
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
        $user->login = 'ext:' . $sub;
        $user->fullname = 'Tech Externe';
        $user->email = 'tech@example.org';
        $user->source = 'federated';
        $user->external_identity_id = $identity->id;
        $user->role = 'federated';
        $user->is_active = true;
        $user->save();

        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Auth::login($user->fresh());

        return ['identity' => $identity, 'user' => $user->fresh()];
    }

    /**
     * Construit une requête (session fédérée marquée si demandé) avec une route
     * nommée optionnelle, prête à traverser le middleware d'audit.
     */
    private function makeRequest(string $method, string $uri, ?string $routeName, bool $federated): Request
    {
        $request = Request::create($uri, $method);
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        if ($federated) {
            FederatedSession::mark($request, Auth::user()?->external_identity_id);
        }

        if ($routeName !== null) {
            $route = new IlluminateRoute([$method], $uri, []);
            $route->name($routeName);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
        }

        return $request;
    }

    /**
     * Exerce le cycle réel du middleware terminable : `handle()` (pass-through)
     * PUIS `terminate($request, $response)` — où l'audit est désormais écrit
     * (post-review P-1/P-2). On RE-RÉSOUT volontairement une NOUVELLE instance
     * pour `terminate()` afin de reproduire fidèlement le comportement du Kernel
     * (qui re-résout le middleware via le container) et de garantir qu'aucun état
     * d'instance n'est requis entre `handle()` et `terminate()`.
     */
    private function runMiddleware(Request $request, int $status = 200): Response
    {
        $response = (new AuditExternalAction())->handle(
            $request,
            fn () => new Response('ok', $status),
        );

        // Instance distincte = preuve que terminate() ne dépend d'aucun état
        // porté par handle() (sémantique Kernel Laravel).
        (new AuditExternalAction())->terminate($request, $response);

        return $response;
    }

    #[Test]
    public function mutating_federated_action_writes_denormalised_log(): void
    {
        // AC1 : POST en session fédérée → ligne dénormalisée complète.
        $actor = $this->provisionFederatedActor('sub-mut', 'technicien');
        $request = $this->makeRequest('POST', '/app/users/1/quota', 'app.users.quota.update', federated: true);

        $this->runMiddleware($request, 200);

        $this->assertSame(1, ExternalActionAuditLog::count());
        $log = ExternalActionAuditLog::first();
        $this->assertSame('ext:sub-mut', $log->actor_login);
        $this->assertSame('sub-mut', $log->actor_external_sub);
        $this->assertSame('Tech Externe', $log->actor_name);
        $this->assertSame('technicien', $log->actor_role);
        $this->assertSame('federated', $log->source);
        $this->assertSame('POST', $log->http_method);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('app.users.quota.update', $log->route_name);
        $this->assertNotNull($log->occurred_at);
        $this->assertSame($actor['identity']->id, $log->external_identity_id);
        $this->assertSame($actor['user']->id, $log->user_id);
    }

    #[Test]
    public function sensitive_get_in_federated_session_writes_log(): void
    {
        // AC4 (2e branche) : GET sur route sensible (PII élève) → ligne écrite,
        // http_method='GET'.
        $this->provisionFederatedActor('sub-get-sens', 'technicien');
        $request = $this->makeRequest('GET', '/app/users/jdoe', 'app.user.show', federated: true);

        $this->runMiddleware($request, 200);

        $this->assertSame(1, ExternalActionAuditLog::count());
        $log = ExternalActionAuditLog::first();
        $this->assertSame('GET', $log->http_method);
        $this->assertSame('app.user.show', $log->route_name);
        $this->assertSame('ext:sub-get-sens', $log->actor_login);
    }

    #[Test]
    public function non_sensitive_get_in_federated_session_writes_nothing(): void
    {
        // AC4 (1re branche) : GET sur route NON sensible → aucune ligne.
        $this->provisionFederatedActor('sub-get-neutre', 'technicien');
        $request = $this->makeRequest('GET', '/app/dashboard', 'app.dashboard', federated: true);

        $this->runMiddleware($request, 200);

        $this->assertSame(0, ExternalActionAuditLog::count());
    }

    #[Test]
    public function get_request_without_route_name_is_not_audited(): void
    {
        // AC4 (défensif) : un GET en session fédérée sur une route SANS nom
        // (route()->getName() === null) ne peut matcher aucune allowlist → aucune
        // ligne d'audit. Garantit que l'absence de nom n'écrit rien par défaut.
        $this->provisionFederatedActor('sub-get-noname', 'technicien');
        $request = $this->makeRequest('GET', '/app/users/jdoe', routeName: null, federated: true);

        $this->assertNull($request->route()?->getName());

        $this->runMiddleware($request, 200);

        $this->assertSame(0, ExternalActionAuditLog::count());
    }

    #[Test]
    public function non_federated_session_writes_nothing(): void
    {
        // AC2 : une action en session AD/LDAP normale (non fédérée) → rien.
        // On loggue un user AD normal, sans marqueur fédéré.
        $user = User::create([
            'login' => 'prof.dupont', 'fullname' => 'Prof Dupont', 'source' => 'ad',
            'role' => 'prof', 'is_active' => true,
        ]);
        Auth::login($user);

        $request = $this->makeRequest('POST', '/app/users/1/quota', 'app.users.quota.update', federated: false);

        $this->runMiddleware($request, 200);

        $this->assertSame(0, ExternalActionAuditLog::count());
    }

    #[Test]
    public function log_remains_readable_after_soft_delete_of_identity(): void
    {
        // AC3 (1re partie) : soft-delete de l'identité → ligne intacte.
        $actor = $this->provisionFederatedActor('sub-soft', 'technicien');
        $request = $this->makeRequest('DELETE', '/app/users/1', 'app.user.delete', federated: true);
        $this->runMiddleware($request, 204);

        $actor['identity']->delete(); // soft-delete

        $log = ExternalActionAuditLog::first()->fresh();
        $this->assertSame('ext:sub-soft', $log->actor_login);
        $this->assertSame('sub-soft', $log->actor_external_sub);
        $this->assertSame('Tech Externe', $log->actor_name);
        $this->assertSame('technicien', $log->actor_role);
    }

    #[Test]
    public function log_remains_readable_after_anonymisation_of_identity(): void
    {
        // AC3 (2e partie, raison d'être) : anonymisation 20.2 (PII vidée,
        // external_sub → anon:<hmac>, soft-delete) → la ligne d'audit reste
        // lisible et attribuable (valeurs COPIÉES au moment de l'action).
        $actor = $this->provisionFederatedActor('sub-anon', 'technicien');
        $request = $this->makeRequest('POST', '/app/users/1/quota', 'app.users.quota.update', federated: true);
        $this->runMiddleware($request, 200);

        (new ExternalIdentityLifecycleService())->anonymize($actor['identity']);

        // L'identité est désormais anonymisée…
        $anon = ExternalIdentity::withTrashed()->find($actor['identity']->id);
        $this->assertNotNull($anon->anonymized_at);
        $this->assertNull($anon->name);
        $this->assertStringStartsWith('anon:', $anon->external_sub);

        // … mais la ligne d'audit conserve les valeurs claires copiées.
        $log = ExternalActionAuditLog::first()->fresh();
        $this->assertSame('ext:sub-anon', $log->actor_login);
        $this->assertSame('sub-anon', $log->actor_external_sub);
        $this->assertSame('Tech Externe', $log->actor_name);
        $this->assertSame('technicien', $log->actor_role);
    }

    #[Test]
    public function audit_write_failure_does_not_break_request_and_is_traced(): void
    {
        // AC5 : échec d'écriture de l'audit (DB cassée) → la requête métier
        // réussit quand même (fail-soft) + trace federated.audit.write_failed.
        $this->provisionFederatedActor('sub-fail', 'technicien');
        $request = $this->makeRequest('POST', '/app/users/1/quota', 'app.users.quota.update', federated: true);

        // Casse la table d'audit pour forcer l'échec d'INSERT.
        \Illuminate\Support\Facades\Schema::drop('external_action_audit_logs');

        Log::shouldReceive('channel')->with('federated-auth')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            // AC7 : contrôle EXACT des clés du contexte loggué. Une assertion
            // anti-PII tautologique (str_contains du nom/email) passerait même
            // si une clé PII était ajoutée plus tard ; on borne donc le contexte
            // à la liste blanche stricte {action_type, exception}.
            $this->assertSame(['action_type', 'exception'], array_keys($context),
                'Le contexte federated.audit.write_failed ne doit contenir QUE action_type et exception (aucune PII).');

            return str_contains($message, 'federated.audit.write_failed')
                && ($context['action_type'] ?? null) === 'federated.audit.write_failed';
        });

        $response = $this->runMiddleware($request, 200);

        // La réponse métier n'est PAS dégradée.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    #[Test]
    public function denormalised_role_reflects_active_spatie_role(): void
    {
        // AC6 : actor_role reflète exactement le rôle Spatie actif (20.3).
        $this->provisionFederatedActor('sub-role', 'technicien');
        // L'admin change le rôle actif : referent-numerique remplace technicien.
        Role::firstOrCreate(['name' => 'referent-numerique', 'guard_name' => 'web']);
        $user = Auth::user();
        $user->syncRoles(['referent-numerique']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Auth::login($user->fresh());

        $request = $this->makeRequest('POST', '/app/users/1/quota', 'app.users.quota.update', federated: true);
        $this->runMiddleware($request, 200);

        $this->assertSame('referent-numerique', ExternalActionAuditLog::first()->actor_role);
    }

    #[Test]
    public function no_pii_in_monolog_on_successful_audit(): void
    {
        // AC7 : un audit RÉUSSI n'émet aucun log Monolog porteur de PII. Le seul
        // dépositaire de l'identité claire est la TABLE d'audit (finalité bornée).
        $this->provisionFederatedActor('sub-nopii', 'technicien');

        // Aucun warning attendu sur le channel federated-auth en cas de succès.
        Log::shouldReceive('channel')->with('federated-auth')->andReturnSelf();
        Log::shouldReceive('warning')->never();

        $request = $this->makeRequest('POST', '/app/users/1/quota', 'app.users.quota.update', federated: true);
        $this->runMiddleware($request, 200);

        $this->assertSame(1, ExternalActionAuditLog::count());
    }

    #[Test]
    public function status_code_is_copied_from_response(): void
    {
        // AC1 complément : le status_code de la réponse réelle est copié.
        $this->provisionFederatedActor('sub-status', 'technicien');
        $request = $this->makeRequest('PUT', '/app/users/1', 'app.user.update', federated: true);

        $this->runMiddleware($request, 422);

        $this->assertSame(422, ExternalActionAuditLog::first()->status_code);
    }

    #[Test]
    public function mutating_federated_action_returning_500_is_audited(): void
    {
        // P-1 RÉSOLU (post-review) : valeur du refactor terminate(). Une mutation
        // fédérée qui produit une réponse 500 (le handler d'exceptions a déjà
        // converti un throw en réponse 500 AVANT terminate) est désormais
        // auditée — l'imputabilité de l'action en erreur est conservée.
        //
        // Avec l'ancien audit inline (handle après $next), un throw remontait
        // AU-DESSUS du middleware et l'écriture était SAUTÉE : cette ligne
        // n'aurait jamais existé.
        $this->provisionFederatedActor('sub-500', 'technicien');
        $request = $this->makeRequest('POST', '/app/users/1/quota', 'app.users.quota.update', federated: true);

        // Le Kernel appelle terminate() avec la réponse 500 produite par le
        // handler d'exceptions (on simule cette réponse d'erreur).
        $this->runMiddleware($request, 500);

        $this->assertSame(1, ExternalActionAuditLog::count());
        $log = ExternalActionAuditLog::first();
        $this->assertSame(500, $log->status_code);
        $this->assertSame('POST', $log->http_method);
        $this->assertSame('ext:sub-500', $log->actor_login);
        $this->assertSame('app.users.quota.update', $log->route_name);
    }

    #[Test]
    public function auth_user_is_resolvable_in_terminate(): void
    {
        // Preuve explicite (post-review) : `Auth::user()` reste résolvable dans
        // `terminate()` pour la même requête fédérée — c'est ce qui permet à
        // l'audit terminable de dénormaliser login/sub/nom/rôle sans fallback.
        // Si ce n'était pas le cas, AUCUNE ligne ne serait écrite (writeAudit
        // sort tôt quand Auth::user() n'est pas un User).
        $this->provisionFederatedActor('sub-term-auth', 'technicien');
        $request = $this->makeRequest('POST', '/app/users/1/quota', 'app.users.quota.update', federated: true);

        // handle() pass-through, puis terminate() via instance distincte.
        $response = (new AuditExternalAction())->handle(
            $request,
            fn () => new Response('ok', 200),
        );
        $this->assertNotNull(Auth::user(), 'Auth::user() doit rester résolu après handle().');

        (new AuditExternalAction())->terminate($request, $response);

        // La ligne a été écrite → Auth::user() a bien été résolu DANS terminate().
        $this->assertSame(1, ExternalActionAuditLog::count());
        $this->assertSame('ext:sub-term-auth', ExternalActionAuditLog::first()->actor_login);
    }
}
