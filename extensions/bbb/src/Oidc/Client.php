<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Http\SessionStore;

/**
 * Story 57.1 — **LE PARCOURS OIDC DE L'EXTENSION : CODE + PKCE S256.**
 *
 * Contrat côté client (§6 du contrat d'extension `app`), rien à négocier :
 *
 * 1. Authorization Code + PKCE, **`S256` OBLIGATOIRE** — le fournisseur refuse
 *    `plain` et refuse l'absence de méthode.
 * 2. `redirect_uri` en **correspondance exacte**, à l'autorisation ET à
 *    l'échange. C'est un CHEMIN, répété VERBATIM depuis l'environnement.
 * 3. Client **confidentiel** (`client_secret_basic`) : l'échange du code est
 *    serveur-à-serveur, le secret ne touche jamais le navigateur.
 * 4. Code à usage unique — un échec de vérification le consomme côté
 *    fournisseur. Ne jamais rejouer un code.
 * 5. **Pas de refresh token, pas de `client_credentials`.**
 *
 * L'id_token suffit à l'extension : il porte `sub`, `name`, `role` et `groups`.
 * `/api/ext/v1/` n'est pas appelée — le contrat demande de ne l'appeler que sur
 * besoin démontré, et il n'y en a pas ici.
 */
final class Client
{
    /** Durée de validité de l'état d'autorisation mémorisé, en secondes. */
    public const AUTHORIZATION_TTL = 600;

    private const SESSION_STATE = 'oidc.state';

    private const SESSION_NONCE = 'oidc.nonce';

    private const SESSION_VERIFIER = 'oidc.code_verifier';

    private const SESSION_STARTED_AT = 'oidc.started_at';

    public function __construct(
        private readonly Credentials $credentials,
        private readonly ProviderMetadata $metadata,
        private readonly IdTokenVerifier $verifier,
        private readonly JsonHttpClient $http,
    ) {
    }

    /**
     * Prépare une demande d'autorisation et rend l'URL absolue du fournisseur.
     *
     * `state`, `nonce` et `code_verifier` sont tirés d'un aléa
     * CRYPTOGRAPHIQUE (`random_bytes`) — le legacy SE4 tirait ses mots de passe
     * de salon avec `rand()`, ce qui est exactement ce qu'il ne faut pas faire.
     *
     * @throws OidcException
     */
    public function authorizationUrl(SessionStore $store): string
    {
        $this->assertProvisioned();

        $discovery = $this->metadata->discovery($this->credentials->issuer);

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = self::urlSafe(random_bytes(48));

        $store->put(self::SESSION_STATE, $state);
        $store->put(self::SESSION_NONCE, $nonce);
        $store->put(self::SESSION_VERIFIER, $verifier);
        $store->put(self::SESSION_STARTED_AT, time());

        $query = [
            'response_type' => 'code',
            'client_id' => $this->credentials->clientId,
            // VERBATIM. Le fournisseur compare en égalité stricte : reconstruire
            // cette valeur (la rendre absolue, normaliser un slash) casserait
            // l'autorisation, et la casserait en silence.
            'redirect_uri' => $this->credentials->redirectUri,
            'scope' => 'openid profile groups',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::urlSafe(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ];

        $endpoint = (string) $discovery['authorization_endpoint'];

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?')
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Traite le retour du fournisseur et rend les claims VÉRIFIÉS.
     *
     * L'état mémorisé est consommé quoi qu'il arrive : un retour, une tentative.
     *
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    public function completeAuthorization(Request $request, SessionStore $store): array
    {
        $this->assertProvisioned();

        $expectedState = $store->get(self::SESSION_STATE);
        $expectedNonce = $store->get(self::SESSION_NONCE);
        $verifier = $store->get(self::SESSION_VERIFIER);
        $startedAt = $store->get(self::SESSION_STARTED_AT);

        $this->forgetAuthorizationState($store);

        if (! is_string($expectedState) || $expectedState === ''
            || ! is_string($expectedNonce) || $expectedNonce === ''
            || ! is_string($verifier) || $verifier === ''
            || ! is_int($startedAt) || $startedAt + self::AUTHORIZATION_TTL < time()) {
            throw OidcException::of(ErrorCodes::STATE_MISSING, 'aucune demande d\'autorisation en cours');
        }

        $receivedState = $request->query('state');
        if ($receivedState === '' || ! hash_equals($expectedState, $receivedState)) {
            throw OidcException::of(ErrorCodes::STATE_MISMATCH, 'le retour ne correspond à aucun départ');
        }

        $code = $request->query('code');
        if ($code === '') {
            // Couvre aussi le retour porteur d'une `error` OAuth : il n'y a pas
            // de code, donc rien à échanger.
            throw OidcException::of(ErrorCodes::CODE_MISSING, 'retour sans code d\'autorisation');
        }

        $discovery = $this->metadata->discovery($this->credentials->issuer);

        $response = $this->http->postForm(
            (string) $discovery['token_endpoint'],
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                // Identique, au caractère près, à celle de l'autorisation.
                'redirect_uri' => $this->credentials->redirectUri,
                'code_verifier' => $verifier,
            ],
            $this->credentials->clientId,
            $this->credentials->clientSecret,
        );

        if ($response['status'] !== 200) {
            throw OidcException::of(
                ErrorCodes::TOKEN_EXCHANGE_FAILED,
                sprintf('token endpoint : statut %d', $response['status']),
            );
        }

        $idToken = $response['body']['id_token'] ?? null;

        if (! is_string($idToken) || $idToken === '') {
            throw OidcException::of(ErrorCodes::ID_TOKEN_MISSING, 'réponse sans id_token');
        }

        $jwks = $this->metadata->jwks((string) $discovery['jwks_uri']);

        return $this->verifier->verify($idToken, $this->credentials, $jwks, $expectedNonce);
    }

    public function forgetAuthorizationState(SessionStore $store): void
    {
        foreach ([self::SESSION_STATE, self::SESSION_NONCE, self::SESSION_VERIFIER, self::SESSION_STARTED_AT] as $key) {
            $store->forget($key);
        }
    }

    /** @throws OidcException */
    private function assertProvisioned(): void
    {
        if (! $this->credentials->isComplete()) {
            throw OidcException::of(
                ErrorCodes::NOT_PROVISIONED,
                'credentials OIDC absents : l\'extension n\'a pas été installée par le canal standard',
            );
        }
    }

    private static function urlSafe(string $raw): string
    {
        return IdTokenVerifier::base64UrlEncode($raw);
    }
}
