<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc;

use App\Http\Middleware\Auth\AuditExternalAction;
use App\Http\Middleware\Auth\SambaEduAuth;
use App\Models\OidcAccessToken;
use App\Models\OidcAuthorizationCode;
use App\Models\OidcClient;
use App\Models\User;
use Database\Factories\OidcClientFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\UsesOidcTestKeys;
use Tests\TestCase;

/**
 * Story 55.1 — **AC1** (flux nominal) et **AC3** (refus de l'échange).
 *
 * Le chemin nominal complet est déroulé pour de vrai : `/oidc/authorize` →
 * code → `/oidc/token` → id_token DÉCODÉ ET VÉRIFIÉ avec la clé publique. Les
 * tests de refus qui suivent s'appuient tous sur ce chemin nominal établi —
 * un refus n'a de valeur démonstrative que si le succès est prouvé par
 * ailleurs, sinon il pourrait être obtenu par une plomberie cassée.
 *
 * ⚠️ Bypass du guard : `/oidc/authorize` est derrière `sambaedu.auth`, qui lit
 * `$_SESSION` et le LDAP — inatteignables sur l'hôte de test. On neutralise le
 * middleware et on pose l'utilisateur par `actingAs` (patron exact
 * `tests/Feature/Ipxe/WindowsIsoRouteTest.php:95-101`). Ce que le guard fait
 * vraiment est couvert par `OidcLoginResumptionTest`.
 */
class OidcAuthorizationFlowTest extends TestCase
{
    use RefreshDatabase;
    use UsesOidcTestKeys;

    private const REDIRECT_URI = 'https://ext.example.test/callback';

    /** `code_verifier` PKCE des tests (RFC 7636 : 43 à 128 caractères). */
    private const VERIFIER = 'verifier-0123456789-0123456789-0123456789-abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useOidcTestKeys();

        $this->withoutMiddleware([
            SambaEduAuth::class,
            AuditExternalAction::class,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeUser(string $login = 'prof.dupont'): User
    {
        return User::query()->create([
            'login' => $login,
            'fullname' => 'Professeur Dupont',
            'role' => 'autre',
            'is_active' => true,
        ]);
    }

    private function makeClient(): OidcClient
    {
        return OidcClient::factory()->withRedirectUris(self::REDIRECT_URI)->create();
    }

    private static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @param array<string, string> $overrides */
    private function authorizeQuery(OidcClient $client, array $overrides = []): array
    {
        return array_merge([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'openid',
            'state' => 'state-xyz',
            'code_challenge' => self::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'nonce' => 'nonce-abc',
        ], $overrides);
    }

    /** Déroule `/oidc/authorize` et retourne le `code` extrait de la redirection. */
    private function obtainCode(OidcClient $client, User $user, array $overrides = []): string
    {
        $response = $this->actingAs($user)->get('/oidc/authorize?'.http_build_query($this->authorizeQuery($client, $overrides)));

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        self::assertArrayHasKey('code', $query, 'La redirection nominale doit porter un `code`.');

        return (string) $query['code'];
    }

    /** @param array<string, string> $overrides */
    private function exchange(OidcClient $client, string $code, array $overrides = [], ?string $secret = null)
    {
        $payload = array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
        ], $overrides);

        $secret ??= OidcClientFactory::DEFAULT_SECRET;

        return $this->post('/oidc/token', $payload, [
            'Authorization' => 'Basic '.base64_encode($client->client_id.':'.$secret),
        ]);
    }

    // ── AC1 — le chemin nominal ───────────────────────────────────────────

    #[Test]
    public function authorize_then_token_yields_a_signed_id_token_without_any_login_form(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();

        $authorize = $this->actingAs($user)->get('/oidc/authorize?'.http_build_query($this->authorizeQuery($client)));

        // FR17 : aucune re-saisie d'identifiants — on repart directement chez
        // le client, pas vers un formulaire de login.
        $authorize->assertStatus(302);
        $location = (string) $authorize->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        self::assertStringNotContainsString('/login', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('state-xyz', $query['state'] ?? null, 'le `state` d\'origine est relayé');
        self::assertNotEmpty($query['code'] ?? '');

        // Le code CLAIR n'a jamais touché la base : seul son sha256 y est.
        self::assertDatabaseMissing('oidc_authorization_codes', ['code_hash' => $query['code']]);
        self::assertDatabaseHas('oidc_authorization_codes', [
            'code_hash' => hash('sha256', (string) $query['code']),
            'user_login' => 'prof.dupont',
            'consumed_at' => null,
        ]);

        // ── L'échange ────────────────────────────────────────────────────
        $token = $this->exchange($client, (string) $query['code']);

        $token->assertOk();
        $body = $token->json();

        self::assertSame('Bearer', $body['token_type']);
        self::assertSame(600, $body['expires_in']);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['id_token']);

        // L'access_token opaque est persisté HASHÉ (consommé par 55.2).
        self::assertSame(1, OidcAccessToken::query()->count());
        self::assertDatabaseHas('oidc_access_tokens', [
            'token_hash' => hash('sha256', (string) $body['access_token']),
            'user_login' => 'prof.dupont',
        ]);

        // ── L'id_token, vérifié pour de vrai ─────────────────────────────
        $parts = explode('.', (string) $body['id_token']);
        self::assertCount(3, $parts, 'un JWT a trois segments');
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);

        self::assertSame('RS256', $header['alg']);
        self::assertSame('JWT', $header['typ']);
        self::assertSame($this->testKid, $header['kid'], 'le `kid` est présent — il permet la rotation de clé');

        $claims = (array) JWT::decode((string) $body['id_token'], new Key($this->testPublicKeyPem(), 'RS256'));

        self::assertSame($this->testIssuer, $claims['iss']);
        self::assertSame('prof.dupont', $claims['sub'], 'le `sub` est résolu par OidcSubjectResolver');
        self::assertSame($client->client_id, $claims['aud'], 'le jeton est lié au client qui l\'a demandé');
        self::assertSame('nonce-abc', $claims['nonce']);
        self::assertNotEmpty($claims['jti']);
        self::assertLessThanOrEqual(300, $claims['exp'] - $claims['iat'], 'TTL court (NFR1)');

        // ⚠️ CONTRAT VERSIONNÉ (NFR11).
        //
        // Story 55.2 — cette assertion ÉVOLUE DE SENS sans changer de forme.
        // En 55.1 elle disait « les claims métier n'existent pas encore ».
        // Depuis 55.2 ils existent — et ce flux demande `scope=openid` SEUL :
        // elle prouve désormais le SCOPE-GATING (un scope non demandé ne
        // produit rien, NFR5), ce qui est une exigence strictement plus forte.
        // La preuve positive est dans `OidcIdTokenClaimsTest`.
        self::assertSame(
            'openid',
            OidcAccessToken::query()->first()?->scope,
            'ce flux demande bien `openid` seul — sans quoi l\'absence ci-dessous ne prouverait rien',
        );
        self::assertArrayNotHasKey('name', $claims);
        self::assertArrayNotHasKey('role', $claims);
        self::assertArrayNotHasKey('groups', $claims);

        self::assertSame(
            ['iss', 'sub', 'aud', 'iat', 'exp', 'jti', 'nonce'],
            array_keys($claims),
            'aucun claim supplémentaire ne s\'est glissé dans le contrat',
        );
    }

    #[Test]
    public function the_nonce_claim_is_absent_when_no_nonce_was_requested(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();

        $code = $this->obtainCode($client, $user, ['nonce' => '']);
        $body = $this->exchange($client, $code)->assertOk()->json();

        $claims = (array) JWT::decode((string) $body['id_token'], new Key($this->testPublicKeyPem(), 'RS256'));

        // Un `nonce` vide émis quand même laisserait croire à une protection
        // anti-rejeu qui n'existe pas.
        self::assertArrayNotHasKey('nonce', $claims);
    }

    // ── AC1 / AC3 — usage unique ──────────────────────────────────────────

    #[Test]
    public function replaying_a_consumed_code_is_refused(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();
        $code = $this->obtainCode($client, $user);

        // Contrôle POSITIF d'abord : le premier échange réussit.
        $this->exchange($client, $code)->assertOk();

        $replay = $this->exchange($client, $code);

        $replay->assertStatus(400);
        self::assertSame('invalid_grant', $replay->json('error'));
        self::assertSame(1, OidcAccessToken::query()->count(), 'aucun second jeton émis');
    }

    #[Test]
    public function an_expired_code_is_refused(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();
        $code = $this->obtainCode($client, $user);

        // Voyage temporel au-delà du TTL de 60 s.
        Carbon::setTestNow(Carbon::now()->addSeconds(120));

        try {
            $response = $this->exchange($client, $code);

            $response->assertStatus(400);
            self::assertSame('invalid_grant', $response->json('error'));
            self::assertSame(0, OidcAccessToken::query()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function a_wrong_code_verifier_is_refused_and_burns_the_code(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();
        $code = $this->obtainCode($client, $user);

        $bad = $this->exchange($client, $code, ['code_verifier' => 'un-verifier-qui-ne-correspond-pas-du-tout']);

        $bad->assertStatus(400);
        self::assertSame('invalid_grant', $bad->json('error'));

        // ⚠️ Le code est CONSOMMÉ malgré l'échec : il a été présenté par
        // quelqu'un qui le possède, il n'y a pas de seconde chance.
        self::assertNotNull(
            OidcAuthorizationCode::query()->where('code_hash', hash('sha256', $code))->first()?->consumed_at,
            'le code doit être consommé même quand le verifier est faux',
        );

        // Et le vrai verifier ne rattrape rien.
        $retry = $this->exchange($client, $code);
        $retry->assertStatus(400);
        self::assertSame(0, OidcAccessToken::query()->count());
    }

    #[Test]
    public function a_redirect_uri_differing_from_the_one_bound_to_the_code_is_refused(): void
    {
        $user = $this->makeUser();
        $client = OidcClient::factory()
            ->withRedirectUris(self::REDIRECT_URI, 'https://ext.example.test/autre')
            ->create();

        $code = $this->obtainCode($client, $user);

        // L'URI est pourtant DÉCLARÉE par le client — mais ce n'est pas celle
        // qui a été liée au code (obligation RFC 6749 §4.1.3).
        $response = $this->exchange($client, $code, ['redirect_uri' => 'https://ext.example.test/autre']);

        $response->assertStatus(400);
        self::assertSame('invalid_grant', $response->json('error'));
        self::assertSame(0, OidcAccessToken::query()->count());
    }

    #[Test]
    public function a_code_cannot_be_exchanged_by_another_client(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();
        $other = $this->makeClient();

        $code = $this->obtainCode($client, $user);

        $response = $this->exchange($other, $code);

        $response->assertStatus(400);
        self::assertSame('invalid_grant', $response->json('error'));

        // Le code du client légitime n'a PAS été brûlé par le tiers : sinon
        // n'importe quel client déclaré pourrait saboter les flux des autres.
        self::assertNull(
            OidcAuthorizationCode::query()->where('code_hash', hash('sha256', $code))->first()?->consumed_at,
        );
        $this->exchange($client, $code)->assertOk();
    }

    // ── AC3 — authentification du client ──────────────────────────────────

    #[Test]
    public function a_wrong_client_secret_is_refused_with_invalid_client(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();
        $code = $this->obtainCode($client, $user);

        $response = $this->exchange($client, $code, [], 'mauvais-secret');

        $response->assertStatus(401);
        self::assertSame('invalid_client', $response->json('error'));
        $response->assertHeader('WWW-Authenticate', 'Basic realm="oidc"');
        self::assertSame(0, OidcAccessToken::query()->count());
    }

    #[Test]
    public function a_disabled_client_cannot_exchange_a_code(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();
        $code = $this->obtainCode($client, $user);

        // Révocation APRÈS émission du code : c'est le scénario réel de la
        // désinstallation d'une extension pendant qu'un flux est en cours.
        $client->enabled = false;
        $client->save();

        $response = $this->exchange($client, $code);

        $response->assertStatus(401);
        self::assertSame('invalid_client', $response->json('error'));
        self::assertSame(0, OidcAccessToken::query()->count());
    }

    #[Test]
    public function client_secret_post_authentication_is_accepted_too(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();
        $code = $this->obtainCode($client, $user);

        // Forme `client_secret_post` : pas d'en-tête Basic, identifiants dans
        // le corps. Les deux méthodes sont annoncées par la discovery.
        $response = $this->post('/oidc/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
            'client_id' => $client->client_id,
            'client_secret' => OidcClientFactory::DEFAULT_SECRET,
        ]);

        $response->assertOk();
        self::assertNotEmpty($response->json('id_token'));
        // Pas de `WWW-Authenticate` attendu ici : aucune tentative Basic.
    }

    #[Test]
    public function an_unsupported_grant_type_is_refused(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();
        $code = $this->obtainCode($client, $user);

        $response = $this->exchange($client, $code, ['grant_type' => 'client_credentials']);

        $response->assertStatus(400);
        self::assertSame('unsupported_grant_type', $response->json('error'));
        // FR22 (`client_credentials`) est explicitement hors périmètre : Epic 56.
        self::assertSame(0, OidcAccessToken::query()->count());
    }

    #[Test]
    public function an_unknown_code_is_refused(): void
    {
        $this->makeUser();
        $client = $this->makeClient();

        $response = $this->exchange($client, bin2hex(random_bytes(32)));

        $response->assertStatus(400);
        self::assertSame('invalid_grant', $response->json('error'));

        // La réponse ne distingue PAS « inconnu », « expiré » et « consommé » :
        // le détail resterait une information exploitable par un attaquant.
        self::assertSame(
            'The authorization code is invalid, expired or already used.',
            $response->json('error_description'),
        );
    }

    #[Test]
    public function the_token_response_is_never_cached(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClient();
        $code = $this->obtainCode($client, $user);

        // RFC 6749 §5.1 : une réponse porteuse d'identifiants ne doit pas être
        // mise en cache (proxy, navigateur).
        $response = $this->exchange($client, $code);

        $response->assertOk();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
