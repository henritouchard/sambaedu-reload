<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Http\Controllers;

use App\Auth\Oidc\Keys\OidcKeyManager;
use App\Auth\Oidc\Support\OidcErrorCodes;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 55.1 — Endpoints PUBLICS de découverte du fournisseur OIDC.
 *
 *  - `GET /.well-known/openid-configuration` — le document de discovery
 *    (OpenID Connect Discovery 1.0 §4) ;
 *  - `GET /oidc/jwks` — le JWKS (RFC 7517) qui permet à n'importe quel client
 *    de vérifier la signature d'un id_token SANS secret partagé.
 *
 * **Publics et stateless par nature** : ils ne révèlent que des métadonnées de
 * protocole et une clé PUBLIQUE. Les exiger authentifiés casserait tout client
 * OIDC standard — et n'apporterait rien, puisque leur contenu est justement
 * destiné à être connu de tous.
 *
 * ⚠️ **Ces deux documents sont un CONTRAT PUBLIC gelé à la première
 * publication** : une extension déployée lit la discovery une fois et met en
 * cache. Retirer ou renommer une clé casse les intégrations existantes.
 * `userinfo_endpoint` est VOLONTAIREMENT absent en 55.1 — l'annoncer avant
 * d'avoir l'endpoint (Story 55.2) ferait échouer les clients qui l'appellent.
 *
 * ⚠️ Le JSON est en anglais normatif (contrat standard), contrairement aux
 * messages destinés aux humains.
 */
class DiscoveryController extends Controller
{
    public function __construct(
        private readonly OidcKeyManager $keys,
    ) {
    }

    /**
     * Document de discovery. Les URL sont construites par `route()` pour rester
     * cohérentes avec le routage réel (jamais de chemin recopié à la main).
     */
    public function openidConfiguration(): JsonResponse
    {
        return response()->json([
            'issuer' => $this->issuer(),
            'authorization_endpoint' => route('oidc.authorize'),
            'token_endpoint' => route('oidc.token'),
            'jwks_uri' => route('oidc.jwks'),

            // UN SEUL flux est supporté : Authorization Code + PKCE. Les flux
            // implicite et hybride exposent des jetons dans la barre d'adresse
            // — ils ne seront pas ajoutés.
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'response_modes_supported' => ['query'],

            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid'],

            // PKCE OBLIGATOIRE, S256 seul : `plain` n'est pas annoncé, donc
            // aucun client conforme ne le tentera.
            'code_challenge_methods_supported' => ['S256'],

            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
            ],

            'claims_supported' => ['iss', 'sub', 'aud', 'exp', 'iat', 'jti', 'nonce'],

            // ⚠️ `userinfo_endpoint` : Story 55.2. Ne pas l'ajouter ici.
        ]);
    }

    /**
     * JWKS de la clé de signature active.
     *
     * Fail-closed : si la clé n'est pas initialisée, on répond 503 plutôt qu'un
     * `{"keys": []}` en 200 — un JWKS vide servi en 200 serait mis en cache par
     * les clients et ferait échouer toutes les vérifications bien après que la
     * clé ait été générée.
     */
    public function jwks(): JsonResponse
    {
        try {
            return response()->json($this->keys->jwks());
        } catch (Throwable $e) {
            Log::channel('oidc')->error('[DiscoveryController] JWKS indisponible', [
                'action_type' => 'oidc.jwks.unavailable',
                'code' => OidcErrorCodes::KEYS_UNAVAILABLE,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'server_error',
                'error_description' => 'Signing key not initialized.',
            ], 503);
        }
    }

    private function issuer(): string
    {
        $iss = rtrim((string) config('oidc.issuer', ''), '/');

        return $iss !== '' ? $iss : rtrim((string) config('app.url', 'http://localhost'), '/');
    }
}
