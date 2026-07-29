<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Http\Controllers;

use App\Auth\Oidc\Services\OidcAuthorizationService;
use App\Auth\Oidc\Support\OidcErrorCodes;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 55.1 — `GET /oidc/authorize` : le point d'entrée NAVIGATEUR du SSO.
 *
 * L'utilisateur arrive ici depuis l'extension. Si sa session SE5 est active, il
 * repart immédiatement vers la `redirect_uri` du client avec un code — **sans
 * jamais revoir de formulaire de login** (FR17). C'est tout l'objet du SSO.
 *
 * **Pourquoi la route est derrière `sambaedu.auth`** (et pas derrière une
 * vérification maison dans ce contrôleur) : le guard est la définition
 * AUTORITATIVE de « session SE5 active » — session legacy, réalignement
 * `Auth::login`, compte actif, sessions fédérées. Un ÉMETTEUR D'IDENTITÉ moins
 * strict que les pages qu'il protège serait une faille. Corollaire : la route
 * porte aussi `federated.audit` (invariant `FederatedAuditCoverageTest`).
 *
 * Sans session, le guard stocke `url.intended` et redirige vers le login
 * standard ; `redirect()->intended()` d'`AuthController` ramène ensuite ici
 * avec TOUS les paramètres (correctif `fullUrl()` du guard — Task 5).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  DEUX FAMILLES DE REFUS — la distinction EST la sécurité
 *
 *  • NON REDIRIGEABLE (client inconnu/révoqué, `redirect_uri` non déclarée) :
 *    page d'erreur locale 400. On ne redirige JAMAIS vers une URI non validée,
 *    sinon SE5 devient un open-redirector et le refus part chez l'attaquant.
 *  • REDIRIGEABLE (PKCE, `response_type`, `scope`) : 302 vers la `redirect_uri`
 *    DÉCLARÉE, avec `error` OAuth + `state` — c'est le client légitime qui est
 *    mal configuré, il a droit à une réponse exploitable.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Chaque branche de refus est journalisée (channel `oidc`) avec son code
 * INTERNE normalisé ; la réponse, elle, ne porte que le code OAuth standard.
 */
class AuthorizeController extends Controller
{
    public function __construct(
        private readonly OidcAuthorizationService $authorization,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // Défense en profondeur : le middleware `sambaedu.auth` garantit déjà
        // une session, mais un émetteur d'identité ne se contente pas d'une
        // garantie amont — sans utilisateur, aucun code n'est émis.
        if (! $user instanceof User) {
            return $this->localError(OidcErrorCodes::NO_SESSION, [
                'client_id' => (string) $request->query('client_id', ''),
            ]);
        }

        $verdict = $this->authorization->validateAuthorizeRequest($request->query());

        if ($verdict['ok'] !== true) {
            if ($verdict['kind'] === OidcAuthorizationService::KIND_LOCAL) {
                return $this->localError($verdict['code'], [
                    'client_id' => $verdict['client_id'],
                    // L'URI REFUSÉE est journalisée (utile au diagnostic), mais
                    // n'est jamais utilisée comme cible ni affichée.
                    'requested_redirect_uri' => $verdict['redirect_uri'],
                ]);
            }

            return $this->redirectError(
                $verdict['redirect_uri'],
                $verdict['oauth_error'],
                $verdict['state'],
                $verdict['code'],
                $verdict['client_id'],
            );
        }

        $code = $this->authorization->issueCode($verdict['client'], $user, [
            'redirect_uri' => $verdict['redirect_uri'],
            'scope' => $verdict['scope'],
            'nonce' => $verdict['nonce'],
            'code_challenge' => $verdict['code_challenge'],
            'code_challenge_method' => $verdict['code_challenge_method'],
        ]);

        Log::channel('oidc')->info('[AuthorizeController] oidc.authorize.granted', [
            'action_type' => 'oidc.authorize.granted',
            'client_id' => $verdict['client']->client_id,
            'extension_key' => $verdict['client']->extension_key,
            'scope' => $verdict['scope'],
            // ⚠️ JAMAIS le code clair. Un préfixe de hash suffit à corréler
            // l'émission et l'échange dans le journal (patron
            // `WorkstationJwtVerifier::logRejection()`).
            'code_hash_prefix' => substr(hash('sha256', $code), 0, 8),
        ]);

        $params = ['code' => $code];
        if ($verdict['state'] !== '') {
            $params['state'] = $verdict['state'];
        }

        return redirect()->away($this->appendQuery($verdict['redirect_uri'], $params));
    }

    /**
     * Refus NON redirigeable — page d'erreur locale 400.
     *
     * @param  array<string, mixed>  $context
     */
    private function localError(string $code, array $context): Response
    {
        Log::channel('oidc')->warning('[AuthorizeController] oidc.authorize.rejected', array_merge([
            'action_type' => 'oidc.authorize.rejected',
            'kind' => OidcAuthorizationService::KIND_LOCAL,
            'code' => $code,
        ], $context));

        // Blade AUTONOME (sans layout `app`) : un chemin d'erreur
        // d'authentification ne doit dépendre d'aucun composant de layout —
        // leçon de la review 54.3, où un composant de navbar faisait tomber
        // toutes les pages authentifiées.
        return response()->view('oidc.authorize-error', ['errorCode' => $code], 400);
    }

    /** Refus REDIRIGEABLE — 302 vers la `redirect_uri` DÉCLARÉE. */
    private function redirectError(
        string $redirectUri,
        string $oauthError,
        string $state,
        string $internalCode,
        string $clientId,
    ): Response {
        Log::channel('oidc')->warning('[AuthorizeController] oidc.authorize.rejected', [
            'action_type' => 'oidc.authorize.rejected',
            'kind' => OidcAuthorizationService::KIND_REDIRECT,
            'code' => $internalCode,
            'oauth_error' => $oauthError,
            'client_id' => $clientId,
        ]);

        $params = ['error' => $oauthError];
        if ($state !== '') {
            $params['state'] = $state;
        }

        return redirect()->away($this->appendQuery($redirectUri, $params));
    }

    /**
     * Ajoute des paramètres à une URI en préservant sa query éventuelle
     * (OIDC Core §3.1.2.5 : la `redirect_uri` peut déjà en porter).
     *
     * @param  array<string, string>  $params
     */
    private function appendQuery(string $uri, array $params): string
    {
        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri . $separator . http_build_query($params);
    }
}
