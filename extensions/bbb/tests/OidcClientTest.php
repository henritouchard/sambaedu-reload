<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Http\ArraySessionStore;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Oidc\Client;
use SambaEdu\ExtBbb\Oidc\Credentials;
use SambaEdu\ExtBbb\Oidc\ErrorCodes;
use SambaEdu\ExtBbb\Oidc\IdTokenVerifier;
use SambaEdu\ExtBbb\Oidc\OidcException;
use SambaEdu\ExtBbb\Oidc\ProviderMetadata;
use SambaEdu\ExtBbb\Tests\Support\FakeJsonHttpClient;
use SambaEdu\ExtBbb\Tests\Support\InMemoryReplayGuard;
use SambaEdu\ExtBbb\Tests\Support\JwtFactory;

/**
 * Story 57.1 — **LE PARCOURS OIDC : PKCE S256 ET `redirect_uri` VERBATIM.**
 *
 * Deux pièges structurels sont verrouillés ici :
 *
 * 1. **`SE5_OIDC_REDIRECT_URI` est un CHEMIN**, répété au caractère près à
 *    l'autorisation ET à l'échange — le fournisseur compare en égalité stricte.
 *    Le reconstruire, ou le rendre absolu, casserait le SSO en silence.
 * 2. **PKCE `S256` obligatoire** : le `code_challenge` doit être le SHA-256 du
 *    `code_verifier` mémorisé, encodé en base64url. `plain` est refusé par le
 *    fournisseur — l'envoyer serait un échec certain, mais tardif.
 *
 * Le transport est remplacé, le protocole est intact.
 */
final class OidcClientTest extends TestCase
{
    private const ISSUER = 'https://se5.example.test';

    private const CLIENT_ID = 'client-hex';

    private const REDIRECT_URI = '/ext/bbb/oidc/callback';

    private const KID = 'test-oidc-kid';

    private function credentials(): Credentials
    {
        return new Credentials(self::ISSUER, self::CLIENT_ID, 'secret-hex', self::REDIRECT_URI);
    }

    /** @return array<string, array<string, mixed>> */
    private function documents(): array
    {
        return [
            self::ISSUER . '/.well-known/openid-configuration' => [
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER . '/oidc/authorize',
                'token_endpoint' => self::ISSUER . '/oidc/token',
                'jwks_uri' => self::ISSUER . '/oidc/jwks',
            ],
            self::ISSUER . '/oidc/jwks' => [
                'keys' => [JwtFactory::jwk(JwtFactory::keyPair()['public'], self::KID)],
            ],
        ];
    }

    private function client(?FakeJsonHttpClient $http = null): Client
    {
        $http ??= new FakeJsonHttpClient($this->documents());

        return new Client(
            $this->credentials(),
            new ProviderMetadata($http),
            new IdTokenVerifier(new InMemoryReplayGuard()),
            $http,
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function claims(string $nonce, array $overrides = []): array
    {
        $now = time();

        return array_merge([
            'iss' => self::ISSUER,
            'sub' => 'prof.dupont',
            'aud' => self::CLIENT_ID,
            'iat' => $now,
            'exp' => $now + 300,
            'jti' => 'jti-' . bin2hex(random_bytes(8)),
            'nonce' => $nonce,
            'name' => 'Professeur Dupont',
            'role' => 'prof',
            'groups' => ['3A'],
        ], $overrides);
    }

    // =====================================================================
    // Autorisation
    // =====================================================================

    #[Test]
    public function the_authorization_request_carries_pkce_s256_and_the_verbatim_redirect_uri(): void
    {
        $session = new ArraySessionStore();

        $url = $this->client()->authorizationUrl($session);

        self::assertStringStartsWith(self::ISSUER . '/oidc/authorize?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('code', $query['response_type']);
        self::assertSame(self::CLIENT_ID, $query['client_id']);
        self::assertSame('openid profile groups', $query['scope']);
        self::assertSame('S256', $query['code_challenge_method']);

        // VERBATIM : un CHEMIN, jamais une URL absolue, jamais normalisé.
        self::assertSame(self::REDIRECT_URI, $query['redirect_uri']);

        // Le défi est bien dérivé du vérificateur mémorisé.
        $verifier = (string) $session->get('oidc.code_verifier');
        self::assertNotSame('', $verifier);
        self::assertSame(
            IdTokenVerifier::base64UrlEncode(hash('sha256', $verifier, true)),
            $query['code_challenge'],
        );

        // …et il ne CONTIENT jamais le vérificateur (ce serait du `plain`
        // déguisé).
        self::assertStringNotContainsString($verifier, (string) $query['code_challenge']);

        self::assertSame($query['state'], $session->get('oidc.state'));
        self::assertSame($query['nonce'], $session->get('oidc.nonce'));
    }

    #[Test]
    public function two_authorization_requests_never_share_their_state(): void
    {
        // L'aléa est cryptographique : deux demandes successives ne doivent rien
        // partager, sans quoi le `state` ne lie plus rien.
        $first = new ArraySessionStore();
        $second = new ArraySessionStore();

        $client = $this->client();
        $client->authorizationUrl($first);
        $client->authorizationUrl($second);

        self::assertNotSame($first->get('oidc.state'), $second->get('oidc.state'));
        self::assertNotSame($first->get('oidc.nonce'), $second->get('oidc.nonce'));
        self::assertNotSame($first->get('oidc.code_verifier'), $second->get('oidc.code_verifier'));
    }

    #[Test]
    public function an_extension_without_credentials_refuses_to_start_a_login(): void
    {
        $client = new Client(
            new Credentials(self::ISSUER, '', '', ''),
            new ProviderMetadata(new FakeJsonHttpClient($this->documents())),
            new IdTokenVerifier(new InMemoryReplayGuard()),
            new FakeJsonHttpClient($this->documents()),
        );

        try {
            $client->authorizationUrl(new ArraySessionStore());
            self::fail('une extension non provisionnée ne peut pas démarrer de connexion');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::NOT_PROVISIONED, $e->errorCode);
        }
    }

    // =====================================================================
    // Retour
    // =====================================================================

    /** @return array{0: Client, 1: ArraySessionStore, 2: FakeJsonHttpClient} */
    private function startedFlow(?callable $tokenResponse = null): array
    {
        $http = new FakeJsonHttpClient($this->documents());
        $session = new ArraySessionStore();

        $client = new Client(
            $this->credentials(),
            new ProviderMetadata($http),
            new IdTokenVerifier(new InMemoryReplayGuard()),
            $http,
        );

        $client->authorizationUrl($session);

        if ($tokenResponse !== null) {
            $http = new FakeJsonHttpClient(
                $this->documents(),
                $tokenResponse((string) $session->get('oidc.nonce')),
            );

            $client = new Client(
                $this->credentials(),
                new ProviderMetadata($http),
                new IdTokenVerifier(new InMemoryReplayGuard()),
                $http,
            );
        }

        return [$client, $session, $http];
    }

    #[Test]
    public function a_nominal_callback_exchanges_the_code_and_returns_verified_claims(): void
    {
        // LE contrôle positif du parcours : sans lui, tous les refus ci-dessous
        // pourraient n'être que le symptôme d'une plomberie cassée.
        [$client, $session, $http] = $this->startedFlow(
            static fn (string $nonce): array => [
                'status' => 200,
                'body' => ['id_token' => JwtFactory::sign([
                    'iss' => self::ISSUER,
                    'sub' => 'prof.dupont',
                    'aud' => self::CLIENT_ID,
                    'iat' => time(),
                    'exp' => time() + 300,
                    'jti' => 'jti-nominal',
                    'nonce' => $nonce,
                    'name' => 'Professeur Dupont',
                    'role' => 'prof',
                    'groups' => ['3A'],
                ], 'RS256', null, self::KID)],
            ],
        );

        $verifierSent = (string) $session->get('oidc.code_verifier');

        $claims = $client->completeAuthorization(
            new Request('GET', '/oidc/callback', [
                'code' => 'code-a-usage-unique',
                'state' => (string) $session->get('oidc.state'),
            ]),
            $session,
        );

        self::assertSame('prof.dupont', $claims['sub']);
        self::assertSame('prof', $claims['role']);

        // L'échange est SERVEUR-À-SERVEUR, authentifié en `client_secret_basic`,
        // et rejoue la `redirect_uri` VERBATIM.
        self::assertCount(1, $http->posts);
        $post = $http->posts[0];
        self::assertSame(self::ISSUER . '/oidc/token', $post['url']);
        self::assertSame('authorization_code', $post['fields']['grant_type']);
        self::assertSame('code-a-usage-unique', $post['fields']['code']);
        self::assertSame(self::REDIRECT_URI, $post['fields']['redirect_uri']);
        self::assertSame($verifierSent, $post['fields']['code_verifier']);
        self::assertSame(self::CLIENT_ID, $post['user']);
        self::assertSame('secret-hex', $post['password']);

        // Aucun secret client dans le corps : il est dans l'en-tête Basic.
        self::assertArrayNotHasKey('client_secret', $post['fields']);

        // L'état d'autorisation est CONSOMMÉ : un retour, une tentative.
        self::assertNull($session->get('oidc.state'));
        self::assertNull($session->get('oidc.code_verifier'));
    }

    #[Test]
    public function a_callback_without_any_started_authorization_is_refused(): void
    {
        try {
            $this->client()->completeAuthorization(
                new Request('GET', '/oidc/callback', ['code' => 'c', 'state' => 's']),
                new ArraySessionStore(),
            );
            self::fail('un retour sans départ doit être refusé');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::STATE_MISSING, $e->errorCode);
        }
    }

    #[Test]
    public function a_diverging_state_is_refused(): void
    {
        [$client, $session] = $this->startedFlow();

        try {
            $client->completeAuthorization(
                new Request('GET', '/oidc/callback', ['code' => 'c', 'state' => 'state-fabrique']),
                $session,
            );
            self::fail('un state divergent doit être refusé');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::STATE_MISMATCH, $e->errorCode);
        }
    }

    #[Test]
    public function a_callback_without_code_is_refused(): void
    {
        // Couvre aussi le retour porteur d'une `error` OAuth : il n'y a rien à
        // échanger.
        [$client, $session] = $this->startedFlow();

        try {
            $client->completeAuthorization(
                new Request('GET', '/oidc/callback', [
                    'error' => 'access_denied',
                    'state' => (string) $session->get('oidc.state'),
                ]),
                $session,
            );
            self::fail('un retour sans code doit être refusé');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::CODE_MISSING, $e->errorCode);
        }
    }

    #[Test]
    public function an_expired_authorization_state_is_refused(): void
    {
        [$client, $session] = $this->startedFlow();
        $session->put('oidc.started_at', time() - Client::AUTHORIZATION_TTL - 1);

        try {
            $client->completeAuthorization(
                new Request('GET', '/oidc/callback', [
                    'code' => 'c',
                    'state' => (string) $session->get('oidc.state'),
                ]),
                $session,
            );
            self::fail('un état périmé doit être refusé');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::STATE_MISSING, $e->errorCode);
        }
    }

    #[Test]
    public function a_refused_token_exchange_is_reported_as_such(): void
    {
        [$client, $session] = $this->startedFlow(
            static fn (): array => ['status' => 400, 'body' => ['error' => 'invalid_grant']],
        );

        try {
            $client->completeAuthorization(
                new Request('GET', '/oidc/callback', [
                    'code' => 'code-deja-brule',
                    'state' => (string) $session->get('oidc.state'),
                ]),
                $session,
            );
            self::fail('un échange refusé doit être signalé');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::TOKEN_EXCHANGE_FAILED, $e->errorCode);
        }
    }

    #[Test]
    public function a_token_response_without_id_token_is_refused(): void
    {
        [$client, $session] = $this->startedFlow(
            static fn (): array => ['status' => 200, 'body' => ['access_token' => 'opaque']],
        );

        try {
            $client->completeAuthorization(
                new Request('GET', '/oidc/callback', [
                    'code' => 'c',
                    'state' => (string) $session->get('oidc.state'),
                ]),
                $session,
            );
            self::fail('une réponse sans id_token doit être refusée');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::ID_TOKEN_MISSING, $e->errorCode);
        }
    }

    #[Test]
    public function the_id_token_of_a_callback_is_bound_to_ITS_own_nonce(): void
    {
        // Le jeton est authentique et parfaitement valide — mais il répond à une
        // AUTRE demande d'autorisation.
        [$client, $session] = $this->startedFlow(
            fn (): array => [
                'status' => 200,
                'body' => ['id_token' => JwtFactory::sign(
                    $this->claims('nonce-d-une-autre-demande'),
                    'RS256',
                    null,
                    self::KID,
                )],
            ],
        );

        try {
            $client->completeAuthorization(
                new Request('GET', '/oidc/callback', [
                    'code' => 'c',
                    'state' => (string) $session->get('oidc.state'),
                ]),
                $session,
            );
            self::fail('un nonce divergent doit être refusé');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::NONCE_MISMATCH, $e->errorCode);
        }
    }

    // =====================================================================
    // Découverte
    // =====================================================================

    #[Test]
    public function no_oidc_endpoint_is_ever_hardcoded(): void
    {
        // Tout se DÉCOUVRE depuis l'issuer : le jour où le fournisseur serait
        // remplacé (NFR12), l'extension suivrait sans être modifiée.
        $http = new FakeJsonHttpClient($this->documents());
        $this->client($http)->authorizationUrl(new ArraySessionStore());

        self::assertSame([self::ISSUER . '/.well-known/openid-configuration'], $http->getUrls);
    }

    #[Test]
    public function a_discovery_document_claiming_another_issuer_is_refused(): void
    {
        $documents = $this->documents();
        $documents[self::ISSUER . '/.well-known/openid-configuration']['issuer'] = 'https://ailleurs.test';

        try {
            $this->client(new FakeJsonHttpClient($documents))->authorizationUrl(new ArraySessionStore());
            self::fail('une découverte incohérente doit être refusée');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::DISCOVERY_UNAVAILABLE, $e->errorCode);
        }
    }

    #[Test]
    public function an_empty_jwks_stops_the_callback(): void
    {
        // Fail-closed : ne rien pouvoir vérifier n'est pas une raison
        // d'accepter.
        $documents = $this->documents();
        $documents[self::ISSUER . '/oidc/jwks'] = ['keys' => []];

        $http = new FakeJsonHttpClient($documents, ['status' => 200, 'body' => ['id_token' => 'peu-importe']]);
        $session = new ArraySessionStore();

        $client = new Client(
            $this->credentials(),
            new ProviderMetadata($http),
            new IdTokenVerifier(new InMemoryReplayGuard()),
            $http,
        );

        $client->authorizationUrl($session);

        try {
            $client->completeAuthorization(
                new Request('GET', '/oidc/callback', [
                    'code' => 'c',
                    'state' => (string) $session->get('oidc.state'),
                ]),
                $session,
            );
            self::fail('un JWKS vide doit tout refuser');
        } catch (OidcException $e) {
            self::assertSame(ErrorCodes::JWKS_UNUSABLE, $e->errorCode);
        }
    }
}
