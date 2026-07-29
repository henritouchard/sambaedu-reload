<?php

declare(strict_types=1);

namespace App\OidcWitness\Http\Controllers;

use App\OidcWitness\Jwt\Exceptions\InvalidWitnessIdTokenException;
use App\OidcWitness\Jwt\WitnessIdTokenVerifier;
use App\OidcWitness\Support\WitnessCredentials;
use App\OidcWitness\Support\WitnessErrorCodes;
use App\OidcWitness\Support\WitnessHttpClient;
use App\OidcWitness\Support\WitnessProviderMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use JsonException;
use League\OAuth2\Client\Provider\GenericProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Story 55.3 — **L'APP-TÉMOIN : un client OIDC honnête, en quarantaine.**
 *
 * Deux routes, une page : « Bonjour {name}, rôle {role}, groupes {groups} ».
 * Tout ce qui s'y affiche vient d'un id_token VÉRIFIÉ, obtenu par le protocole
 * public — discovery, autorisation, échange serveur-à-serveur, JWKS. Rien n'est
 * lu dans la base, l'annuaire, ni dans l'état de connexion de SE5.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER S'INTERDIT (FR24, verrouillé par
 *  `tests/Architecture/ExtensionIsolationTest.php`)
 *
 *  Aucun modèle Eloquent, aucun service applicatif de SE5, aucune façade de
 *  base de données, aucun annuaire, aucun accès à l'utilisateur connecté ni au
 *  magasin d'état côté serveur de SE5. Un témoin qui triche ne prouve rien :
 *  il validerait la connexion SE5, pas le SSO.
 *
 *  Ce qu'il utilise, en revanche : le routage, les cookies chiffrés, le cache,
 *  le journal, Blade. C'est de l'INFRASTRUCTURE d'hébergement, pas de la
 *  donnée — une vraie extension aurait la sienne. La preuve d'isolation par
 *  PROCESSUS appartient aux extensions `app` (Epics 56/57) ; celle par
 *  CONTRAT est ici, et elle est testée.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **La route n'est PAS derrière le garde d'authentification de SE5** — et c'est
 * le cœur de la démonstration : c'est `/oidc/authorize` qui authentifie. Un
 * témoin placé derrière le garde rendrait la preuve « sans re-saisie
 * d'identifiants » circulaire.
 *
 * **L'état entre les deux routes** : `state`, `nonce` et `code_verifier` PKCE
 * voyagent dans UN cookie chiffré dédié (`APP_KEY`), 5 minutes, `HttpOnly`,
 * `SameSite=Lax` — le retour d'autorisation est une navigation GET de premier
 * niveau, `Lax` passe. C'est ce qu'une extension ferait avec son propre magasin
 * d'état. Le cookie est invalidé à la première lecture.
 *
 * **Le fournisseur ne valide RIEN de l'id_token pour nous** : `GenericProvider`
 * le rend brut dans `getValues()`. TOUTE la validation appartient à
 * {@see WitnessIdTokenVerifier}. Aucun claim d'un jeton non vérifié n'est jamais
 * affiché.
 */
class WitnessController
{
    /** Nom du cookie d'état du témoin. */
    public const STATE_COOKIE = 'oidc_witness_state';

    /**
     * Borne de taille du cookie d'état accepté en lecture. Un cookie est
     * plafonné à ~4 Ko par les navigateurs : au-delà, la valeur ne vient pas
     * de nous. On refuse explicitement plutôt que de laisser un JSON démesuré
     * atteindre le décodeur.
     */
    public const MAX_STATE_COOKIE_BYTES = 4096;

    public function __construct(
        private readonly WitnessHttpClient $http,
        private readonly WitnessProviderMetadata $metadata,
        private readonly WitnessIdTokenVerifier $verifier,
    ) {
    }

    // =====================================================================
    // GET /sso-demo — le départ
    // =====================================================================

    public function start(Request $request): Response
    {
        $credentials = WitnessCredentials::load();

        if ($credentials === null) {
            return $this->failure(
                WitnessCredentials::isProvisioned()
                    ? WitnessErrorCodes::CREDENTIALS_UNREADABLE
                    : WitnessErrorCodes::NOT_PROVISIONED,
                503,
            );
        }

        try {
            $discovery = $this->metadata->discovery($credentials->issuer);
        } catch (Throwable $e) {
            return $this->failure(WitnessErrorCodes::DISCOVERY_UNAVAILABLE, 503, $e);
        }

        $nonce = bin2hex(random_bytes(16));

        $provider = $this->provider($credentials, $discovery);

        $authorizationUrl = $provider->getAuthorizationUrl([
            'scope' => (string) config('oidc.witness.scope', 'openid profile groups'),
            'nonce' => $nonce,
        ]);

        $payload = json_encode([
            'state' => $provider->getState(),
            'nonce' => $nonce,
            'verifier' => (string) $provider->getPkceCode(),
            'ts' => Carbon::now()->getTimestamp(),
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return $this->failure(WitnessErrorCodes::STATE_MISSING, 500);
        }

        Log::channel('oidc')->info('[Witness] oidc.witness.start', [
            'action_type' => 'oidc.witness.start',
            'client_id' => $credentials->clientId,
            // ⚠️ Ni `state`, ni `nonce`, ni le verifier PKCE : ce sont des
            // secrets de session client, pas des identifiants publics.
        ]);

        return redirect()
            ->away($authorizationUrl)
            ->withCookie($this->stateCookie($payload));
    }

    // =====================================================================
    // GET /sso-demo/callback — le retour
    // =====================================================================

    public function callback(Request $request): Response
    {
        $credentials = WitnessCredentials::load();

        if ($credentials === null) {
            return $this->failure(WitnessErrorCodes::NOT_PROVISIONED, 503);
        }

        $stored = $this->readState($request);

        if ($stored === null) {
            return $this->failure(WitnessErrorCodes::STATE_MISSING, 400);
        }

        // ⚠️ AVANT TOUT ÉCHANGE. Un `state` qui ne correspond à aucun départ
        // signale un retour fabriqué par un tiers : présenter le code au token
        // endpoint reviendrait à échanger un code qu'on n'a pas demandé
        // (injection de code d'autorisation). On refuse sans rien consommer.
        $state = (string) $request->query('state', '');

        if ($state === '' || ! hash_equals($stored['state'], $state)) {
            return $this->failure(WitnessErrorCodes::STATE_MISMATCH, 400);
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            // Le fournisseur a pu répondre `?error=...` (refus redirigeable).
            return $this->failure(WitnessErrorCodes::CODE_MISSING, 400, null, [
                'oauth_error' => (string) $request->query('error', ''),
            ]);
        }

        try {
            $discovery = $this->metadata->discovery($credentials->issuer);
        } catch (Throwable $e) {
            return $this->failure(WitnessErrorCodes::DISCOVERY_UNAVAILABLE, 503, $e);
        }

        $provider = $this->provider($credentials, $discovery);
        $provider->setPkceCode($stored['verifier']);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (Throwable $e) {
            return $this->failure(WitnessErrorCodes::TOKEN_EXCHANGE_FAILED, 502, $e);
        }

        $values = $token->getValues();
        $idToken = isset($values['id_token']) && is_string($values['id_token']) ? $values['id_token'] : '';

        if ($idToken === '') {
            return $this->failure(WitnessErrorCodes::ID_TOKEN_MISSING, 502);
        }

        try {
            $jwks = $this->metadata->jwks((string) $discovery['jwks_uri']);
        } catch (Throwable $e) {
            return $this->failure(WitnessErrorCodes::JWKS_UNUSABLE, 503, $e);
        }

        try {
            $claims = $this->verifier->verify($idToken, $credentials, $jwks, $stored['nonce']);
        } catch (InvalidWitnessIdTokenException $e) {
            return $this->failure($e->errorCode, 400, $e);
        }

        Log::channel('oidc')->info('[Witness] oidc.witness.verified', [
            'action_type' => 'oidc.witness.verified',
            'client_id' => $credentials->clientId,
            // ⚠️ Aucun claim : `sub`, `name` et `groups` sont de la PII
            // (doctrine 55.1/55.2, reconduite côté client).
        ]);

        $groups = isset($claims['groups']) && is_array($claims['groups'])
            ? array_values(array_filter(array_map(
                static fn (mixed $g): string => is_scalar($g) ? (string) $g : '',
                $claims['groups'],
            ), static fn (string $g): bool => $g !== ''))
            : [];

        return response()
            ->view('oidc-witness.claims', [
                'subject' => $this->stringClaim($claims, 'sub'),
                'name' => $this->stringClaim($claims, 'name'),
                'role' => $this->stringClaim($claims, 'role'),
                'groups' => $groups,
                'hasGroupsClaim' => array_key_exists('groups', $claims),
            ])
            ->withCookie(Cookie::forget(self::STATE_COOKIE));
    }

    // =====================================================================
    // Interne
    // =====================================================================

    /** @param array<string, mixed> $discovery */
    private function provider(WitnessCredentials $credentials, array $discovery): GenericProvider
    {
        return new GenericProvider([
            'clientId' => $credentials->clientId,
            'clientSecret' => $credentials->clientSecret,
            'redirectUri' => $credentials->redirectUri,
            'urlAuthorize' => (string) $discovery['authorization_endpoint'],
            'urlAccessToken' => (string) $discovery['token_endpoint'],
            // Le témoin n'appelle pas `/userinfo` : l'id_token porte déjà les
            // claims du contrat v1. On renseigne l'URL parce que la
            // bibliothèque l'exige, jamais on ne l'utilise.
            'urlResourceOwnerDetails' => (string) ($discovery['userinfo_endpoint'] ?? ''),
            // OAuth 2 sépare les scopes par une ESPACE (RFC 6749 §3.3) ; le
            // défaut de la bibliothèque est la virgule.
            'scopeSeparator' => ' ',
            // PKCE S256 — la seule méthode annoncée par la discovery.
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ], [
            // Le MÊME transport que la discovery et le JWKS.
            'httpClient' => $this->http->client(),
        ]);
    }

    /**
     * Lit, valide et borne le cookie d'état.
     *
     * @return array{state: string, nonce: string, verifier: string}|null
     */
    private function readState(Request $request): ?array
    {
        $raw = $request->cookie(self::STATE_COOKIE);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (strlen($raw) > self::MAX_STATE_COOKIE_BYTES) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['state', 'nonce', 'verifier'] as $field) {
            if (! isset($decoded[$field]) || ! is_string($decoded[$field]) || $decoded[$field] === '') {
                return null;
            }
        }

        // Le cookie porte SA propre expiration : le navigateur peut mentir sur
        // `Max-Age`, pas sur le contenu chiffré.
        $ttl = max(60, (int) config('oidc.witness.state_ttl', 300));
        $issuedAt = (int) ($decoded['ts'] ?? 0);

        if ($issuedAt <= 0 || Carbon::now()->getTimestamp() - $issuedAt > $ttl) {
            return null;
        }

        return [
            'state' => (string) $decoded['state'],
            'nonce' => (string) $decoded['nonce'],
            'verifier' => (string) $decoded['verifier'],
        ];
    }

    private function stateCookie(string $payload): \Symfony\Component\HttpFoundation\Cookie
    {
        $minutes = (int) ceil(max(60, (int) config('oidc.witness.state_ttl', 300)) / 60);

        return Cookie::make(
            name: self::STATE_COOKIE,
            value: $payload,
            minutes: $minutes,
            path: '/sso-demo',
            domain: null,
            secure: null,
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    /** @param array<string, mixed> $claims */
    private function stringClaim(array $claims, string $key): string
    {
        return isset($claims[$key]) && is_scalar($claims[$key]) ? (string) $claims[$key] : '';
    }

    /**
     * Page d'erreur SOBRE du témoin + journal.
     *
     * L'écran ne porte que le code normalisé — jamais le message de
     * l'exception, qui pourrait citer une URL interne, un `client_id` ou une
     * cause exploitable. Le détail va au journal `oidc`.
     *
     * @param  array<string, mixed>  $context
     */
    private function failure(string $code, int $status, ?Throwable $e = null, array $context = []): Response
    {
        Log::channel('oidc')->warning('[Witness] oidc.witness.failed', array_merge([
            'action_type' => 'oidc.witness.failed',
            'code' => $code,
            'error' => $e?->getMessage(),
        ], $context));

        return response()->view('oidc-witness.error', [
            'errorCode' => $code,
            'notProvisioned' => $code === WitnessErrorCodes::NOT_PROVISIONED,
        ], $status);
    }
}
