<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc;

use App\Http\Middleware\Auth\AuditExternalAction;
use App\Http\Middleware\Auth\SambaEduAuth;
use App\Models\OidcAuthorizationCode;
use App\Models\OidcClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\UsesOidcTestKeys;
use Tests\TestCase;

/**
 * Story 55.1 — **AC4** : sans session SE5, login standard **puis reprise du
 * flux**.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LE PIÈGE N°1 DE LA STORY
 *
 *  `SambaEduAuthGuard::unauthorized()` stockait `$request->path()` dans
 *  `url.intended` — SANS la query string. Or TOUT le flux OIDC vit dans la
 *  query (`client_id`, `state`, `code_challenge`, `nonce`…). Un utilisateur
 *  sans session dirigé vers `/oidc/authorize?…` était donc renvoyé au login,
 *  puis « repris » sur `/oidc/authorize` NU — refusé systématiquement. Le SSO
 *  n'aurait JAMAIS pu aboutir au premier accès de la journée, c'est-à-dire
 *  dans le cas le plus fréquent.
 *
 *  Correctif : `fullUrl()`. C'est le mécanisme STANDARD du projet
 *  (`url.intended` + `redirect()->intended()` d'`AuthController`) qu'on
 *  répare — pas un canal parallèle qu'on invente.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ Ce test est le SEUL de la story à laisser tourner le vrai guard : c'est
 * lui qui est en cause. Le login LDAP réel est hors de portée de l'hôte, donc
 * la seconde moitié (la reprise) est jouée en re-visitant l'URL mémorisée avec
 * une session posée — le scénario humain complet est au runbook QA (Section 11).
 */
class OidcLoginResumptionTest extends TestCase
{
    use RefreshDatabase;
    use UsesOidcTestKeys;

    private const REDIRECT_URI = 'https://ext.example.test/callback';

    private const VERIFIER = 'verifier-0123456789-0123456789-0123456789-abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useOidcTestKeys();
    }

    private function makeClient(): OidcClient
    {
        return OidcClient::factory()->withRedirectUris(self::REDIRECT_URI)->create();
    }

    private function authorizePath(OidcClient $client): string
    {
        return '/oidc/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'openid',
            'state' => 'state-xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'nonce' => 'nonce-abc',
        ]);
    }

    #[Test]
    public function without_a_session_the_user_is_sent_to_the_standard_login_with_the_full_url_memorised(): void
    {
        $client = $this->makeClient();
        $path = $this->authorizePath($client);

        // ⚠️ Aucun bypass du guard ici : c'est lui qu'on teste.
        $response = $this->get($path);

        $response->assertRedirect(route('auth.login'));

        $intended = (string) session('url.intended');

        // Le cœur du correctif : la query COMPLÈTE est préservée.
        self::assertStringContainsString('/oidc/authorize', $intended);
        self::assertStringContainsString('client_id='.$client->client_id, $intended);
        self::assertStringContainsString('code_challenge=', $intended);
        self::assertStringContainsString('state=state-xyz', $intended);
        self::assertStringContainsString('nonce=nonce-abc', $intended);
        self::assertStringContainsString('code_challenge_method=S256', $intended);

        // Et rien n'a été émis tant que l'utilisateur n'est pas authentifié.
        self::assertSame(0, OidcAuthorizationCode::query()->count());
    }

    #[Test]
    public function the_memorised_url_actually_completes_the_flow_once_a_session_exists(): void
    {
        $client = $this->makeClient();

        // 1. Sans session : le guard mémorise l'URL.
        $this->get($this->authorizePath($client))->assertRedirect(route('auth.login'));
        $intended = (string) session('url.intended');

        // 2. Après login, `redirect()->intended()` d'`AuthController` renvoie
        //    l'utilisateur exactement là. On rejoue cette URL avec une session.
        $user = User::query()->create([
            'login' => 'prof.dupont',
            'role' => 'autre',
            'is_active' => true,
        ]);

        $this->withoutMiddleware([SambaEduAuth::class, AuditExternalAction::class]);

        $resumed = $this->actingAs($user)->get($intended);

        // Le flux ABOUTIT : un code est émis, sans intervention supplémentaire.
        $resumed->assertStatus(302);
        self::assertStringStartsWith(self::REDIRECT_URI.'?code=', (string) $resumed->headers->get('Location'));
        self::assertSame(1, OidcAuthorizationCode::query()->count());
        self::assertSame('nonce-abc', OidcAuthorizationCode::query()->firstOrFail()->nonce);
    }

    #[Test]
    public function an_amputated_url_would_not_complete_the_flow(): void
    {
        // Contrôle NÉGATIF : la démonstration de l'utilité du correctif. Avec
        // l'ancien `path()`, c'est EXACTEMENT ce que l'utilisateur obtenait
        // après s'être authentifié — une page d'erreur, à chaque première
        // connexion de la journée.
        $client = $this->makeClient();
        $user = User::query()->create([
            'login' => 'prof.dupont',
            'role' => 'autre',
            'is_active' => true,
        ]);

        $this->withoutMiddleware([SambaEduAuth::class, AuditExternalAction::class]);

        $response = $this->actingAs($user)->get('/oidc/authorize');

        $response->assertStatus(400);
        self::assertSame(0, OidcAuthorizationCode::query()->count());
    }
}
