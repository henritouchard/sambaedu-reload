<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Http\Controllers;

use App\Auth\Oidc\Services\OidcAccessTokenValidator;
use App\Auth\Oidc\Support\OidcClaimsResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Story 55.2 — `GET|POST /oidc/userinfo` (FR21, OIDC Core §5.3).
 *
 * Le client présente l'access_token opaque obtenu à l'échange et reçoit
 * `{sub, …claims du scope du jeton}`. C'est le **canal de repli** du contrat de
 * claims : identique à l'id_token, mais interrogeable à tout moment pendant la
 * vie du jeton (TTL 600 s) — utile pour un client qui n'a pas gardé l'id_token,
 * ou dont les claims tiendraient mal dans un JWT (établissement à très forte
 * volumétrie de groupes).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  DÉCISIONS FIGÉES
 *
 *  • **GET ET POST** — OIDC Core §5.3.1 impose les deux ; un client conforme
 *    peut utiliser l'un ou l'autre, il n'y a rien à gagner à en interdire un.
 *  • **Bearer dans l'en-tête `Authorization`, UNIQUEMENT.** Un
 *    `?access_token=…` finirait dans les logs du serveur, l'historique et le
 *    `Referer` (doctrine D-3 du login fédéré, reprise par 55.1 sur le token
 *    endpoint). RFC 6750 autorise la forme « URI query parameter » : SE5 ne la
 *    supporte PAS — un jeton en query est simplement IGNORÉ, donc traité comme
 *    absent.
 *  • **Réponse JSON simple, non signée.** OIDC Core le permet ; un userinfo
 *    signé (JWT) serait du sur-conçu sans consommateur — la preuve
 *    d'authentification, c'est l'id_token.
 *  • **`sub` = la valeur STOCKÉE sur le jeton** — le sujet résolu à l'émission.
 *    OIDC Core §5.3.2 exige l'égalité avec le `sub` de l'id_token du même
 *    flux : elle est garantie PAR CONSTRUCTION, jamais par une re-résolution
 *    qui pourrait diverger.
 *  • **Claims RECALCULÉS à chaque appel** depuis l'état SQL courant, comme
 *    l'id_token l'est à l'échange. Un changement de classe pendant la vie du
 *    jeton est donc visible ici ; la staleness inverse (id_token figé) est
 *    explicitement acceptée par l'architecture (option C1) et bornée par le
 *    TTL de 600 s.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ **EXCEPTION ASSUMÉE AU FORMAT DE RÉPONSE MAISON**, iso `TokenController` :
 * pas de `{success, message, …}`. L'interlocuteur est un client OIDC standard,
 * potentiellement une bibliothèque tierce — le contrat est celui d'OIDC Core
 * §5.3.2 et de la RFC 6750 §3.
 *
 * ⚠️ **Aucune PII au journal.** `oidc.userinfo.served` ne porte que le
 * `client_id` et le préfixe de hash du jeton — ni `sub`, ni `name`, ni
 * `groups`. La doctrine 55.1 excluait déjà le `sub` ; `name` et `groups` sont
 * exactement de la même nature.
 */
class UserinfoController extends Controller
{
    public function __construct(
        private readonly OidcAccessTokenValidator $tokens,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $verdict = $this->tokens->validate($this->extractBearer($request));

        if ($verdict['ok'] !== true) {
            Log::channel('oidc')->warning('[UserinfoController] oidc.userinfo.rejected', [
                'action_type' => 'oidc.userinfo.rejected',
                // Code FIN au journal seulement — la réponse, elle, est
                // indistincte entre les cinq causes de refus.
                'code' => $verdict['code'],
                'token_hash_prefix' => $verdict['token_hash_prefix'],
            ]);

            return $this->unauthorized($verdict['presented']);
        }

        $record = $verdict['record'];

        // ⚠️ ORDRE : le `sub` du jeton est écrit EN DERNIER, donc gagnant. Le
        // résolveur de claims ne produit jamais de `sub` (par construction),
        // mais l'identité servie ne doit dépendre d'aucune promesse tenue
        // ailleurs — même garantie qu'à l'émission de l'id_token.
        $payload = array_merge(
            OidcClaimsResolver::claimsFor($verdict['user'], $record->scope),
            ['sub' => $record->user_login],
        );

        Log::channel('oidc')->info('[UserinfoController] oidc.userinfo.served', [
            'action_type' => 'oidc.userinfo.served',
            'client_id' => $record->client?->client_id,
            'token_hash_prefix' => substr($record->token_hash, 0, 8),
            // ⚠️ JAMAIS `sub`, `name` ni `groups` : c'est de la PII.
        ]);

        return response()->json($payload)->withHeaders(self::NO_STORE);
    }

    /**
     * En-têtes anti-cache. Une réponse porteuse de données d'identité ne doit
     * être conservée ni par un proxy, ni par un navigateur.
     *
     * @var array<string, string>
     */
    private const NO_STORE = [
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
    ];

    /**
     * Extrait le jeton du SEUL en-tête `Authorization: Bearer …`.
     *
     * Retourne `null` pour tout le reste (en-tête absent, schéma `Basic`,
     * `Bearer` sans valeur) — et n'inspecte NI la query string, NI le corps :
     * un jeton qui y figurerait est ignoré, donc refusé comme absent.
     */
    private function extractBearer(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization', ''));

        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    /**
     * 401 RFC 6750 §3, INDISTINCT entre les causes de refus.
     *
     * @param  bool  $presented  Un jeton a-t-il été présenté ? RFC 6750 §3 :
     *                           quand la requête ne porte AUCUNE information
     *                           d'authentification, le serveur « SHOULD NOT »
     *                           inclure de code d'erreur — il se contente du
     *                           challenge. Quand un jeton a été présenté et
     *                           refusé, `invalid_token` est dû.
     */
    private function unauthorized(bool $presented): JsonResponse
    {
        if (! $presented) {
            return response()->json(new \stdClass(), 401)->withHeaders(array_merge(self::NO_STORE, [
                'WWW-Authenticate' => 'Bearer realm="oidc"',
            ]));
        }

        // Une seule et même réponse pour : jeton inconnu, expiré, client
        // révoqué, utilisateur supprimé. Distinguer offrirait un oracle à qui
        // teste des jetons au hasard.
        $description = 'The access token is invalid or expired.';

        return response()->json([
            'error' => 'invalid_token',
            'error_description' => $description,
        ], 401)->withHeaders(array_merge(self::NO_STORE, [
            'WWW-Authenticate' => sprintf(
                'Bearer realm="oidc", error="invalid_token", error_description="%s"',
                $description,
            ),
        ]));
    }
}
