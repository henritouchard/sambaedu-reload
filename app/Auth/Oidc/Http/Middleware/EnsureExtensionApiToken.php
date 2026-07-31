<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Http\Middleware;

use App\Auth\Oidc\Services\OidcAccessTokenValidator;
use App\Auth\Oidc\Support\OidcBearer;
use App\Auth\Oidc\Support\OidcClaimsResolver;
use App\Auth\Oidc\Support\OidcErrorCodes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 56.4 — **La porte de l'API extensions `/api/ext/v1/`** (FR21, FR22, AR6).
 *
 * Alias router : `ext.token`, avec le scope requis en paramètre —
 * `ext.token:profile`, `ext.token:groups`. La règle « cet endpoint exige tel
 * scope » est donc DÉCLARÉE SUR LA ROUTE : lisible dans `routes/api.php`,
 * vérifiable par la table des routes, jamais enfouie dans un contrôleur.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LE JETON EST LA SEULE IDENTITÉ DE LA REQUÊTE
 *
 *  Le sujet résolu par le jeton est injecté dans les attributs de requête ; les
 *  contrôleurs de ce canal n'acceptent JAMAIS d'identifiant d'utilisateur en
 *  entrée (doctrine `AuthenticateAgentToken`, story 23.2 — le précédent
 *  Bearer-opaque le plus proche du projet).
 *
 *   • `ext.user`            — l'utilisateur résolu ({@see \App\Models\User})
 *   • `ext.record`          — la ligne de jeton ({@see \App\Models\OidcAccessToken})
 *   • `ext.client`          — le client OIDC de l'extension
 *   • `ext.effective_scope` — le scope EFFECTIF, recalculé à cet instant
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Le « token de service » de FR22, c'est CE jeton** : l'access token opaque
 * émis à l'échange, lié au client — donc à l'extension —, borné par un scope et
 * révocable (TTL 600 s). Pas de grant `client_credentials` : les deux endpoints
 * du v1 portent sur l'utilisateur courant, qu'un jeton machine n'a pas.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  DEUX REFUS, DEUX SENS — ET AUCUNE FUITE
 *
 *  • **401 `invalid_token`** : jeton absent, inconnu, expiré, client révoqué,
 *    utilisateur disparu ou désactivé. Corps STRICTEMENT INDISTINCT entre les
 *    six causes — distinguer offrirait un oracle à qui teste des jetons. Les
 *    codes fins ({@see OidcErrorCodes}) vont au JOURNAL seul.
 *  • **403 `insufficient_scope`** : le jeton est valide, mais l'endpoint exige
 *    un scope qui n'est pas (ou plus) dans son scope effectif. La réponse ne
 *    nomme NI les scopes détenus, NI ceux qui manquent : l'intégrateur légitime
 *    lit le paramètre `scope` de sa réponse token, l'attaquant n'apprend rien.
 *
 *  Rien de ce qui est journalisé ici n'est de la PII (README OIDC) : `client_id`
 *  — un identifiant public — et un préfixe de hash de 8 caractères, jamais le
 *  jeton, jamais le `sub`, le nom ou les groupes.
 * ══════════════════════════════════════════════════════════════════════════
 */
class EnsureExtensionApiToken
{
    /** `realm` du challenge RFC 6750 — distinct de celui du fournisseur OIDC. */
    public const REALM = 'ext-api';

    /**
     * Message UNIQUE des 401. Une constante, parce que c'est l'indistinction
     * elle-même qui est la propriété de sécurité : six causes, une phrase.
     */
    public const MESSAGE_INVALID_TOKEN = 'Jeton d\'accès absent, invalide ou expiré.';

    /** Message UNIQUE des 403 — générique, sans énumération de scopes. */
    public const MESSAGE_INSUFFICIENT_SCOPE = 'Le jeton ne porte pas l\'autorisation requise pour cette ressource.';

    public function __construct(
        private readonly OidcAccessTokenValidator $tokens,
    ) {
    }

    public function handle(Request $request, Closure $next, string $requiredScope = ''): Response
    {
        $bearer = OidcBearer::fromRequest($request);

        $verdict = $this->tokens->validate($bearer);

        if ($verdict['ok'] !== true) {
            $this->logRejection($verdict['code'], $verdict['token_hash_prefix'], $request);

            return $this->unauthorized($verdict['presented']);
        }

        $record = $verdict['record'];
        $effectiveScope = (string) $verdict['effective_scope'];

        // ── Le scope requis par l'endpoint ─────────────────────────────────
        //
        // Fail-closed sur la DÉCLARATION elle-même : une route de ce canal sans
        // scope requis, ou avec un scope hors du catalogue fermé, est un
        // câblage fautif — on refuse plutôt que de servir des données à la
        // faveur d'un paramètre oublié. Le contrôle est ici, pas dans un test :
        // un test ne garde rien à l'exécution.
        if ($requiredScope === '' || ! array_key_exists($requiredScope, OidcClaimsResolver::CLAIMS_BY_SCOPE)) {
            Log::channel('oidc')->error('[EnsureExtensionApiToken] oidc.ext_api.rejected', [
                'action_type' => 'oidc.ext_api.rejected',
                'code' => OidcErrorCodes::ACCESS_TOKEN_SCOPE_INSUFFICIENT,
                'reason' => 'required_scope_misconfigured',
                'required_scope' => $requiredScope,
                'path' => $request->path(),
            ]);

            return $this->forbidden();
        }

        if (! in_array($requiredScope, OidcClaimsResolver::parseScope($effectiveScope), true)) {
            Log::channel('oidc')->warning('[EnsureExtensionApiToken] oidc.ext_api.rejected', [
                'action_type' => 'oidc.ext_api.rejected',
                'code' => OidcErrorCodes::ACCESS_TOKEN_SCOPE_INSUFFICIENT,
                'client_id' => $record->client?->client_id,
                'token_hash_prefix' => substr((string) $record->token_hash, 0, 8),
                // Le scope REQUIS est une propriété publique de la route, pas
                // un secret. Le scope DÉTENU, lui, n'est jamais journalisé
                // avec l'identité : il ne dirait rien d'utile au diagnostic que
                // le couple (endpoint, préfixe de jeton) ne dise déjà.
                'required_scope' => $requiredScope,
            ]);

            return $this->forbidden();
        }

        $request->attributes->set('ext.user', $verdict['user']);
        $request->attributes->set('ext.record', $record);
        $request->attributes->set('ext.client', $record->client);
        $request->attributes->set('ext.effective_scope', $effectiveScope);

        return $next($request);
    }

    private function logRejection(string $code, string $prefix, Request $request): void
    {
        Log::channel('oidc')->warning('[EnsureExtensionApiToken] oidc.ext_api.rejected', [
            'action_type' => 'oidc.ext_api.rejected',
            // Code FIN au journal SEULEMENT — la réponse, elle, est indistincte.
            'code' => $code,
            'token_hash_prefix' => $prefix,
            'path' => $request->path(),
        ]);
    }

    /**
     * 401 au format MAISON (AR6), challenge RFC 6750 §3.
     *
     * @param  bool  $presented  RFC 6750 §3 : une requête SANS aucune
     *                           information d'authentification reçoit le
     *                           challenge nu, sans code d'erreur ; un jeton
     *                           présenté et refusé mérite `invalid_token`. Le
     *                           CORPS, lui, ne varie pas d'un caractère.
     */
    private function unauthorized(bool $presented): JsonResponse
    {
        $challenge = $presented
            ? sprintf('Bearer realm="%s", error="invalid_token"', self::REALM)
            : sprintf('Bearer realm="%s"', self::REALM);

        return response()->json([
            'success' => false,
            'message' => self::MESSAGE_INVALID_TOKEN,
            'error' => 'invalid_token',
        ], 401)->withHeaders([
            'WWW-Authenticate' => $challenge,
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    /** 403 au format MAISON — générique, sans énumération de scopes. */
    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => self::MESSAGE_INSUFFICIENT_SCOPE,
            'error' => 'insufficient_scope',
        ], 403)->withHeaders([
            'WWW-Authenticate' => sprintf('Bearer realm="%s", error="insufficient_scope"', self::REALM),
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }
}
