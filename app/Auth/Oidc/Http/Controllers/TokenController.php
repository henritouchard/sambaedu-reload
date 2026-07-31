<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Http\Controllers;

use App\Auth\Oidc\Jwt\OidcIdTokenIssuer;
use App\Auth\Oidc\Services\OidcAuthorizationService;
use App\Auth\Oidc\Services\OidcClientRegistry;
use App\Auth\Oidc\Support\OidcClaimsResolver;
use App\Auth\Oidc\Support\OidcErrorCodes;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 55.1 — `POST /oidc/token` : l'échange SERVEUR-À-SERVEUR.
 *
 * Le client confidentiel présente son secret, le code reçu par redirection et
 * son `code_verifier` PKCE ; il obtient un id_token signé et un access_token
 * opaque.
 *
 * **POST uniquement, jamais GET** : un GET mettrait le secret du client et le
 * code d'autorisation dans la query string — donc dans les logs du serveur,
 * l'historique et l'en-tête `Referer`. Même doctrine que le binding POST du
 * login fédéré (D-3).
 *
 * **Stateless** : `withoutMiddleware(['web'])` — ni session ni CSRF. L'appelant
 * est un serveur, pas un navigateur ; il n'a pas de cookie et un jeton CSRF
 * n'aurait aucun sens. L'authentification EST le secret du client.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  ⚠️ EXCEPTION ASSUMÉE AU FORMAT DE RÉPONSE MAISON
 *
 *  Ce contrôleur ne renvoie PAS le `{success, message, …}` documenté dans
 *  architecture.md §Format de Réponse API. Il renvoie le format normatif
 *  RFC 6749 §5.1/§5.2 (`{access_token, token_type, expires_in, id_token}` /
 *  `{error, error_description}`), parce que son interlocuteur est un client
 *  OIDC STANDARD — potentiellement une bibliothèque tierce que nous n'écrivons
 *  pas — et non le front SE5. C'est aussi ce qui rendra la bascule vers
 *  Keycloak (NFR12) invisible pour les extensions.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Les codes d'erreur fins restent dans le journal.** La réponse ne distingue
 * jamais « code inconnu », « code expiré », « code déjà consommé » ni
 * « utilisateur disparu » : les quatre sont `invalid_grant`, avec la MÊME
 * description. Un attaquant ne doit pas pouvoir savoir qu'il a trouvé un code
 * valide mais périmé — ni qu'un compte a été supprimé.
 *
 * **Story 55.2 — les claims métier.** L'utilisateur est résolu depuis le code
 * consommé (`user_id`), ses claims sont filtrés par le scope LIÉ AU CODE, et
 * l'émetteur les ajoute SOUS les claims standards (inécrasables).
 * ⚠️ `name`, `groups` et le `sub` sont de la PII : ils ne sont JAMAIS
 * journalisés, pas même en cas de refus.
 */
class TokenController extends Controller
{
    public function __construct(
        private readonly OidcClientRegistry $clients,
        private readonly OidcAuthorizationService $authorization,
        private readonly OidcIdTokenIssuer $issuer,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        // ── 1. Authentification du client ────────────────────────────────
        [$clientId, $clientSecret, $usedBasic] = $this->extractClientCredentials($request);

        $client = $this->clients->authenticate($clientId, $clientSecret);

        if ($client === null) {
            // `invalid_client` + 401 (RFC 6749 §5.2) — et `WWW-Authenticate`
            // seulement si l'appelant a tenté le schéma Basic.
            $response = $this->error('invalid_client', 'Client authentication failed.', 401);

            return $usedBasic
                ? $response->withHeaders(['WWW-Authenticate' => 'Basic realm="oidc"'])
                : $response;
        }

        // ── 2. Le grant ──────────────────────────────────────────────────
        $grantType = (string) $request->input('grant_type', '');
        if ($grantType !== 'authorization_code') {
            $this->logRejection(OidcErrorCodes::UNSUPPORTED_GRANT_TYPE, $clientId, ['grant_type' => $grantType]);

            return $this->error('unsupported_grant_type', 'Only authorization_code is supported.', 400);
        }

        // ── 3. L'échange ─────────────────────────────────────────────────
        $verdict = $this->authorization->consumeCode(
            $client,
            (string) $request->input('code', ''),
            (string) $request->input('redirect_uri', ''),
            (string) $request->input('code_verifier', ''),
        );

        if ($verdict['ok'] !== true) {
            $this->logRejection($verdict['code'], $clientId);

            // `invalid_grant` comme `invalid_request` répondent 400
            // (RFC 6749 §5.2) — seul `invalid_client` est un 401.
            return $this->error(
                $verdict['oauth_error'],
                'The authorization code is invalid, expired or already used.',
                400,
            );
        }

        $record = $verdict['record'];

        // ── 4. L'utilisateur, puis ses claims (Story 55.2) ───────────────
        // ⚠️ Résolution par `user_id`, JAMAIS par `user_login` : ce dernier est
        // le `sub` PUBLIÉ (une valeur de contrat), pas une clé de jointure —
        // s'en servir créerait une adhérence au choix actuel du sujet.
        //
        // Fail-closed AVANT toute émission : compte supprimé pendant la fenêtre
        // de 60 s du code (ou code antérieur à la migration `user_id`) ⇒
        // `invalid_grant`, aucun jeton — jamais un id_token aux claims
        // partiels, jamais un access_token orphelin que `/userinfo` refuserait
        // ensuite sans que personne comprenne pourquoi.
        $user = $record->user_id !== null
            ? User::query()->find($record->user_id)
            : null;

        if (! $user instanceof User) {
            $this->logRejection(OidcErrorCodes::USER_MISSING, $clientId);

            // Même corps que les autres `invalid_grant` : la réponse ne dit pas
            // à un appelant que le compte visé a disparu.
            return $this->error(
                'invalid_grant',
                'The authorization code is invalid, expired or already used.',
                400,
            );
        }

        // Correctif review 55.2 — un compte DÉSACTIVÉ n'obtient pas d'identité,
        // au même titre qu'un compte supprimé. Symétrie avec la révocation d'un
        // client, vérifiée plus haut : sans ce contrôle, une désactivation faite
        // pendant la fenêtre de 60 s du code émettait quand même un id_token ET
        // un access_token utilisable 10 minutes de plus.
        //
        // `SambaEduAuthGuard` ne couvre pas ce cas : il valide l'état du compte
        // côté LDAP/AD, jamais `users.is_active` — et le token endpoint est un
        // appel serveur-à-serveur qui ne traverse aucune session.
        if (! $user->isActive()) {
            $this->logRejection(OidcErrorCodes::USER_INACTIVE, $clientId);

            return $this->error(
                'invalid_grant',
                'The authorization code is invalid, expired or already used.',
                400,
            );
        }

        // ── Story 56.4 — LE DOWNSCOPING (RFC 6749 §3.3) ──────────────────
        //
        // Le code d'autorisation porte le scope DEMANDÉ (validé contre le
        // catalogue fermé à l'autorisation). Ce qui est ÉMIS, lui, est borné
        // par ce que l'admin a réellement accordé au client :
        //
        //     effectif = scope du code ∩ (granted_scopes + openid)
        //
        // Le calcul a UN SEUL énoncé ({@see \App\Models\OidcClient::effectiveScopeFor()}),
        // partagé avec `/userinfo` et l'API extensions — sans quoi une
        // révocation mordrait à un endroit et pas à l'autre.
        //
        // Pourquoi RÉDUIRE et non refuser `invalid_scope` : révoquer une DONNÉE
        // (FR23) ne doit pas provoquer une panne de SSO. Le fail-closed du
        // projet vise le scope INCONNU — toujours refusé à l'autorisation. Le
        // non-accordé est réduit, et la réduction est ANNONCÉE par le paramètre
        // `scope` de la réponse : c'est le mécanisme standard, que tout client
        // OIDC sait lire — pas une ignorance silencieuse.
        $effectiveScope = $client->effectiveScopeFor((string) $record->scope);

        // Claims MÉTIER, filtrés par le scope EFFECTIF (issu du scope LIÉ AU
        // CODE — jamais d'un scope renvoyé au token endpoint par le client).
        $businessClaims = OidcClaimsResolver::claimsFor($user, $effectiveScope);

        // ── 5. L'émission ────────────────────────────────────────────────
        try {
            $idToken = $this->issuer->issueIdToken(
                $client,
                $record->user_login,
                $record->nonce,
                $businessClaims,
            );
            // Le jeton PERSISTE le scope effectif : ce qu'il ouvre au moment de
            // son émission. Une révocation ultérieure le réduira encore, à
            // chaque usage — l'inverse (persister le demandé) obligerait chaque
            // consommateur à refaire l'intersection contre le code d'origine.
            $accessToken = $this->issuer->issueAccessToken(
                $client,
                $record->user_login,
                $effectiveScope,
                (int) $user->id,
            );
        } catch (Throwable $e) {
            // Fail-closed : clé absente ou illisible ⇒ AUCUN jeton dégradé.
            Log::channel('oidc')->error('[TokenController] oidc.token.rejected', [
                'action_type' => 'oidc.token.rejected',
                'code' => OidcErrorCodes::KEYS_UNAVAILABLE,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return $this->error('server_error', 'Token signing is unavailable.', 500);
        }

        // RFC 6749 §5.1 : la réponse d'un token endpoint ne doit JAMAIS être
        // mise en cache (elle contient des identifiants).
        return response()->json([
            'access_token' => $accessToken['clear'],
            'token_type' => 'Bearer',
            'expires_in' => $accessToken['expires_in'],
            'id_token' => $idToken['token'],
            // RFC 6749 §3.3 : quand le scope délivré diffère du scope demandé,
            // le serveur DOIT l'annoncer ici. C'est le canal par lequel une
            // extension apprend ses scopes réels — raison pour laquelle aucune
            // variable d'environnement ne les fige côté extension (décision
            // 56.4 n° 5 : une valeur statique mentirait dès la 1ʳᵉ révocation).
            'scope' => $effectiveScope,
        ])->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Extrait les identifiants du client : `client_secret_basic` (en-tête
     * `Authorization: Basic`) OU `client_secret_post` (champs du corps). Les
     * deux méthodes standard sont acceptées — les bibliothèques clientes
     * choisissent l'une ou l'autre et il n'y a rien à gagner à en imposer une.
     *
     * @return array{0: string, 1: string, 2: bool} `[client_id, secret, tentative Basic]`
     */
    private function extractClientCredentials(Request $request): array
    {
        $header = (string) $request->header('Authorization', '');

        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);

            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);

                // RFC 6749 §2.3.1 : les deux composantes sont urlencodées.
                return [urldecode($id), urldecode($secret), true];
            }

            // En-tête Basic malformé : on ne se rabat PAS silencieusement sur
            // le corps — l'appelant croit s'authentifier par en-tête.
            return ['', '', true];
        }

        return [
            (string) $request->input('client_id', ''),
            (string) $request->input('client_secret', ''),
            false,
        ];
    }

    /** @param array<string, mixed> $context */
    private function logRejection(string $code, string $clientId, array $context = []): void
    {
        Log::channel('oidc')->warning('[TokenController] oidc.token.rejected', array_merge([
            'action_type' => 'oidc.token.rejected',
            'code' => $code,
            'client_id' => $clientId,
        ], $context));
    }

    /** Erreur au format RFC 6749 §5.2 (contrat public, anglais normatif). */
    private function error(string $error, string $description, int $status): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'error_description' => $description,
        ], $status)->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }
}
