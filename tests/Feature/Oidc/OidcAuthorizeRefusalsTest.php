<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc;

use App\Auth\Oidc\Services\OidcAuthorizationService;
use App\Auth\Oidc\Support\OidcClaimsResolver;
use App\Auth\Oidc\Support\OidcErrorCodes;
use App\Http\Middleware\Auth\AuditExternalAction;
use App\Http\Middleware\Auth\SambaEduAuth;
use App\Models\OidcAuthorizationCode;
use App\Models\OidcClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\CapturesOidcLogs;
use Tests\Feature\Oidc\Concerns\UsesOidcTestKeys;
use Tests\TestCase;

/**
 * Story 55.1 — **AC3** : les refus de `/oidc/authorize`, fail-closed et
 * journalisés.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER PROUVE, ET POURQUOI L'ORDRE COMPTE
 *
 *  Règle OAuth cardinale : **on ne redirige JAMAIS vers une `redirect_uri` non
 *  validée**. Un attaquant qui fabrique une URL `/oidc/authorize?…&redirect_uri=
 *  https://chez-moi.example/` ne doit obtenir NI code, NI redirection — sinon
 *  SE5 est un open-redirector et le refus lui-même part chez lui.
 *
 *  D'où deux familles testées séparément :
 *   • refus LOCAUX (client, `redirect_uri`) ⇒ 400, aucune redirection ;
 *   • refus REDIRIGEABLES (PKCE, `response_type`, `scope`) ⇒ 302 vers l'URI
 *     DÉCLARÉE avec `error` + `state`.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ Chaque test de refus est adossé au contrôle POSITIF
 * {@see self::the_nominal_request_of_this_fixture_does_emit_a_code()} : sans
 * lui, un refus pourrait n'être que le symptôme d'une plomberie cassée et ne
 * rien démontrer.
 */
class OidcAuthorizeRefusalsTest extends TestCase
{
    use CapturesOidcLogs;
    use RefreshDatabase;
    use UsesOidcTestKeys;

    private const DECLARED_URI = 'https://ext.example.test/callback';

    private const ATTACKER_URI = 'https://attaquant.example/collecte';

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

    private function makeUser(): User
    {
        return User::query()->create([
            'login' => 'prof.dupont',
            'role' => 'autre',
            'is_active' => true,
        ]);
    }

    private function makeClient(): OidcClient
    {
        return OidcClient::factory()->withRedirectUris(self::DECLARED_URI)->create();
    }

    /** @param array<string, string> $overrides */
    private function query(OidcClient $client, array $overrides = []): array
    {
        return array_merge([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::DECLARED_URI,
            'scope' => 'openid',
            'state' => 'state-xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], $overrides);
    }

    /** @param array<string, string> $overrides */
    private function authorize(OidcClient $client, array $overrides = [])
    {
        return $this->actingAs($this->makeUser())
            ->get('/oidc/authorize?'.http_build_query($this->query($client, $overrides)));
    }

    // ── Le contrôle positif dont dépendent tous les refus ─────────────────

    #[Test]
    public function the_nominal_request_of_this_fixture_does_emit_a_code(): void
    {
        $client = $this->makeClient();

        $response = $this->authorize($client);

        $response->assertStatus(302);
        self::assertStringStartsWith(
            self::DECLARED_URI.'?code=',
            (string) $response->headers->get('Location'),
        );
        self::assertSame(1, OidcAuthorizationCode::query()->count());
    }

    // ── Refus NON redirigeables : 400, aucune redirection ─────────────────

    #[Test]
    public function an_unknown_client_gets_a_local_400_and_no_redirection(): void
    {
        $this->makeClient(); // un client existe — le refus ne vient pas d'un registre vide.

        $response = $this->actingAs($this->makeUser())->get('/oidc/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => 'client-qui-nexiste-pas',
            'redirect_uri' => self::ATTACKER_URI,
            'scope' => 'openid',
            'state' => 'state-xyz',
            'code_challenge' => 'peu-importe',
            'code_challenge_method' => 'S256',
        ]));

        $response->assertStatus(400);
        $response->assertHeaderMissing('Location');
        self::assertSame(0, OidcAuthorizationCode::query()->count(), 'aucun code émis');

        // La page ne divulgue rien du registre.
        $response->assertDontSee(self::DECLARED_URI);
        $response->assertDontSee(self::ATTACKER_URI);
    }

    #[Test]
    public function an_undeclared_redirect_uri_gets_a_local_400_and_no_redirection(): void
    {
        $client = $this->makeClient();

        $response = $this->authorize($client, ['redirect_uri' => self::ATTACKER_URI]);

        // ⚠️ LE test anti-open-redirector : le client est valide, seule l'URI
        // est fabriquée. Rediriger ici enverrait la victime chez l'attaquant.
        $response->assertStatus(400);
        $response->assertHeaderMissing('Location');
        self::assertSame(0, OidcAuthorizationCode::query()->count());
    }

    #[Test]
    public function a_redirect_uri_matching_only_by_prefix_is_refused(): void
    {
        $client = $this->makeClient();

        // La correspondance est une ÉGALITÉ EXACTE : ni suffixe, ni
        // sous-chemin. Un `startsWith` laisserait passer ceci.
        $response = $this->authorize($client, ['redirect_uri' => self::DECLARED_URI.'/../../evade']);

        $response->assertStatus(400);
        self::assertSame(0, OidcAuthorizationCode::query()->count());
    }

    #[Test]
    public function a_missing_redirect_uri_gets_a_local_400(): void
    {
        $client = $this->makeClient();

        $response = $this->authorize($client, ['redirect_uri' => '']);

        $response->assertStatus(400);
        self::assertSame(0, OidcAuthorizationCode::query()->count());
    }

    #[Test]
    public function a_disabled_client_gets_a_local_400(): void
    {
        $client = OidcClient::factory()->withRedirectUris(self::DECLARED_URI)->disabled()->create();

        $response = $this->authorize($client);

        $response->assertStatus(400);
        $response->assertHeaderMissing('Location');
        self::assertSame(0, OidcAuthorizationCode::query()->count());
    }

    // ── Refus REDIRIGEABLES : 302 vers l'URI DÉCLARÉE ─────────────────────

    /**
     * @param array<string, string> $overrides
     */
    private function assertRedirectableRefusal(array $overrides, string $expectedError): void
    {
        $client = $this->makeClient();

        $response = $this->authorize($client, $overrides);

        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');

        self::assertStringStartsWith(self::DECLARED_URI.'?', $location, 'redirection vers l\'URI DÉCLARÉE');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        self::assertSame($expectedError, $query['error'] ?? null);
        self::assertSame('state-xyz', $query['state'] ?? null, 'le `state` est relayé même en erreur');
        self::assertArrayNotHasKey('code', $query, 'aucun code dans une redirection d\'erreur');
        self::assertSame(0, OidcAuthorizationCode::query()->count(), 'aucun code émis');
    }

    #[Test]
    public function a_missing_pkce_challenge_is_refused(): void
    {
        // NFR1 : PKCE est OBLIGATOIRE. Sans lui, un code intercepté suffit à
        // obtenir un id_token.
        $this->assertRedirectableRefusal(['code_challenge' => ''], 'invalid_request');
    }

    #[Test]
    public function the_plain_pkce_method_is_explicitly_refused(): void
    {
        // `plain` transmet le secret en clair à l'autorisation : il ne protège
        // de rien. Il n'est pas annoncé par la discovery et doit être refusé.
        $this->assertRedirectableRefusal(['code_challenge_method' => 'plain'], 'invalid_request');
    }

    #[Test]
    public function an_absent_pkce_method_is_refused_and_not_defaulted_to_s256(): void
    {
        // RFC 7636 : une méthode absente vaut `plain`. L'interpréter
        // silencieusement comme S256 reviendrait à ne rien vérifier.
        $this->assertRedirectableRefusal(['code_challenge_method' => ''], 'invalid_request');
    }

    #[Test]
    public function a_response_type_other_than_code_is_refused(): void
    {
        // Le flux implicite (`token`) expose le jeton dans la barre d'adresse.
        $this->assertRedirectableRefusal(['response_type' => 'token'], 'unsupported_response_type');
    }

    #[Test]
    public function a_scope_without_openid_is_refused(): void
    {
        $this->assertRedirectableRefusal(['scope' => 'profile email'], 'invalid_scope');
    }

    #[Test]
    public function a_scope_containing_openid_among_others_is_accepted(): void
    {
        // Contrôle NÉGATIF du test précédent : la vérification porte bien sur
        // la présence du jeton `openid` dans la liste, pas sur une égalité de
        // chaîne qui rejetterait tout scope composé.
        $client = $this->makeClient();

        $response = $this->authorize($client, ['scope' => 'openid profile']);

        $response->assertStatus(302);
        self::assertStringStartsWith(self::DECLARED_URI.'?code=', (string) $response->headers->get('Location'));
        self::assertSame('openid profile', OidcAuthorizationCode::query()->first()?->scope);
    }

    #[Test]
    public function a_scope_whose_value_merely_contains_openid_as_a_substring_is_refused(): void
    {
        // « openidx » n'est PAS le scope `openid` : la vérification doit
        // découper sur les espaces, pas faire un `str_contains`.
        $this->assertRedirectableRefusal(['scope' => 'openidx'], 'invalid_scope');
    }

    // ── Bornes de longueur des paramètres PERSISTÉS (correctif review #3) ──

    #[Test]
    public function a_nonce_longer_than_its_column_is_refused_instead_of_crashing_on_insert(): void
    {
        // `nonce` part de la query string et atterrit dans
        // `oidc_authorization_codes.nonce` (VARCHAR 255). PostgreSQL REFUSE le
        // dépassement (`value too long for type character varying`) : sans
        // borne applicative, le client obtient un 500 générique hors journal
        // `oidc` au lieu d'un refus OAuth normalisé (FR20).
        //
        // ⚠️ SQLite — driver de cette suite — n'applique AUCUNE limite de
        // longueur. Ce test ne peut donc PAS prouver la contrainte SQL : il
        // prouve que le code refuse AVANT d'y arriver. C'est précisément ce qui
        // rend la divergence visible sur l'hôte.
        $this->assertRedirectableRefusal(
            ['nonce' => str_repeat('n', OidcAuthorizationService::MAX_NONCE_LENGTH + 1)],
            'invalid_request',
        );
    }

    #[Test]
    public function a_nonce_exactly_at_the_limit_is_accepted(): void
    {
        // Contrôle POSITIF de la borne : sans lui, un `off-by-one` qui refuserait
        // TOUT nonce passerait inaperçu derrière le test de refus ci-dessus.
        $client = $this->makeClient();
        $nonce = str_repeat('n', OidcAuthorizationService::MAX_NONCE_LENGTH);

        $response = $this->authorize($client, ['nonce' => $nonce]);

        $response->assertStatus(302);
        self::assertStringStartsWith(self::DECLARED_URI.'?code=', (string) $response->headers->get('Location'));
        self::assertSame($nonce, OidcAuthorizationCode::query()->first()?->nonce);
    }

    #[Test]
    public function an_oversized_scope_is_refused_too(): void
    {
        // Même colonne bornée, même raisonnement : `scope` (255) vient aussi de
        // la query et est persisté.
        $this->assertRedirectableRefusal(
            ['scope' => 'openid '.str_repeat('s', OidcAuthorizationService::MAX_SCOPE_LENGTH)],
            'invalid_request',
        );
    }

    #[Test]
    public function an_oversized_code_challenge_is_refused_too(): void
    {
        // `code_challenge` (128) : un challenge démesuré échouerait de toute
        // façon à la vérification PKCE — mais l'INSERT a lieu AVANT elle.
        $this->assertRedirectableRefusal(
            ['code_challenge' => str_repeat('c', OidcAuthorizationService::MAX_CODE_CHALLENGE_LENGTH + 1)],
            'invalid_request',
        );
    }

    // ── Story 55.2 / AC4 — l'ensemble des scopes est FERMÉ ────────────────

    #[Test]
    public function an_unsupported_scope_is_refused_with_invalid_scope_and_journaled(): void
    {
        // AC4 : un scope inconnu ne peut PAS être « accordé silencieusement ».
        // L'ignorer laisserait un client croire qu'il a obtenu quelque chose —
        // et le jour où `foo` deviendrait un vrai scope, il serait accordé
        // rétroactivement à qui le demandait déjà.
        $this->captureLogs();

        $this->assertRedirectableRefusal(['scope' => 'openid profile foo'], 'invalid_scope');

        self::assertContains(
            OidcErrorCodes::SCOPE_UNSUPPORTED,
            $this->loggedCodes(),
            'le code FIN doit partir au journal — la réponse OAuth, elle, reste générique',
        );
    }

    #[Test]
    public function the_three_supported_scopes_are_accepted_together(): void
    {
        // Contrôle POSITIF du test précédent : sans lui, un contrôle de scopes
        // qui refuserait TOUT passerait le test de refus haut la main.
        $client = $this->makeClient();

        $response = $this->authorize($client, ['scope' => 'openid profile groups']);

        $response->assertStatus(302);
        self::assertStringStartsWith(self::DECLARED_URI.'?code=', (string) $response->headers->get('Location'));
        self::assertSame('openid profile groups', OidcAuthorizationCode::query()->first()?->scope);
    }

    #[Test]
    public function the_supported_scope_set_is_exactly_the_one_the_discovery_announces(): void
    {
        // Le catalogue est une SOURCE UNIQUE : la discovery et le validateur
        // doivent lire la même. Deux listes divergentes annonceraient un
        // contrat que le flux ne tient pas.
        self::assertSame(['openid', 'profile', 'groups'], OidcClaimsResolver::supportedScopes());

        $announced = $this->get('/.well-known/openid-configuration')->assertOk()->json('scopes_supported');
        self::assertSame(OidcClaimsResolver::supportedScopes(), $announced);
    }

    #[Test]
    public function an_unsupported_scope_alone_is_refused_too(): void
    {
        // Variante sans `profile` : le refus ne dépend pas de la présence d'un
        // scope valide à côté.
        $this->assertRedirectableRefusal(['scope' => 'openid email'], 'invalid_scope');
    }

    #[Test]
    public function the_state_is_simply_omitted_when_the_client_did_not_send_one(): void
    {
        $client = $this->makeClient();

        $response = $this->authorize($client, ['state' => '', 'response_type' => 'token']);

        $response->assertStatus(302);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        self::assertSame('unsupported_response_type', $query['error'] ?? null);
        self::assertArrayNotHasKey('state', $query, 'pas de `state` vide inventé');
    }
}
