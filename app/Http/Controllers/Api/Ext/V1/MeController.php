<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Ext\V1;

use App\Auth\Oidc\Support\OidcClaimsResolver;
use App\Http\Controllers\Controller;
use App\Models\OidcAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Story 56.4 — **L'API EXTENSIONS v1** (FR21, FR22, AR6).
 *
 * Deux endpoints, en lecture seule, consommés par une extension avec son propre
 * access token opaque :
 *
 *  | Route                    | Scope requis | Réponse                          |
 *  |--------------------------|--------------|----------------------------------|
 *  | `GET /api/ext/v1/me`     | `profile`    | `{success, message, sub, name, role?}` |
 *  | `GET /api/ext/v1/me/groups` | `groups`  | `{success, message, sub, groups}` |
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  ⚠️ CONTRAT PUBLIC v1 — GELÉ À LA LIVRAISON (NFR11)
 *
 *  Ce que rend ce contrôleur sera consommé par du code que nous n'écrivons pas
 *  (l'extension BBB de l'Epic 57, le SDK de l'Epic 58) et que nous ne
 *  redéployons pas. Règle asymétrique, identique à celle des claims 55.2 :
 *
 *    • on peut AJOUTER une clé (évolution additive) ;
 *    • on ne peut JAMAIS en retirer une, la renommer, ni changer son TYPE.
 *
 *  Une rupture se livre en `/api/ext/v2/` À CÔTÉ, jamais en modifiant v1. La
 *  liste EXACTE des clés de chaque réponse est verrouillée par test.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Les valeurs sont celles du contrat de claims 55.2, VERBATIM.** Elles
 * viennent d'{@see OidcClaimsResolver} — la même source que l'id_token et
 * `/userinfo` —, filtrées par le scope EFFECTIF calculé par le middleware.
 * Cette API n'invente aucun claim, n'en élargit aucun, et ne re-résout jamais
 * l'utilisateur : FR24 (aucun accès à la base ni à l'annuaire pour les
 * extensions) se tient précisément parce que la surface exposée est exactement
 * celle que le SSO publiait déjà.
 *
 * **`sub` = `oidc_access_tokens.user_login`** — le sujet résolu À L'ÉMISSION.
 * L'égalité `sub` id_token ⇄ `sub` userinfo ⇄ `sub` API est ainsi garantie PAR
 * CONSTRUCTION, jamais par une re-résolution qui pourrait diverger (invariant
 * README OIDC #12).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI LE FORMAT MAISON ICI, ET PAS L'EXCEPTION OAUTH
 *
 *  `TokenController` et `UserinfoController` dérogent au `{success, message,
 *  …}` du projet : leur interlocuteur est un client OIDC STANDARD, qui attend
 *  RFC 6749 / OIDC Core. Ici, l'interlocuteur est le SDK SE5 (AR6) — du code
 *  que nous publions, pour un canal que nous définissons. Il consomme donc le
 *  format uniforme du projet (architecture.md §Format de Réponse API) : clés
 *  métier À LA RACINE, jamais de wrapper `data:`.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ **Aucun identifiant n'est accepté en entrée.** L'identité de la requête
 * est le jeton, résolu par le middleware `ext.token` et lu dans les attributs
 * de requête (doctrine `AuthenticateAgentToken`, 23.2). Un paramètre
 * `?user=…` n'aurait aucun effet — il n'est jamais lu.
 */
class MeController extends Controller
{
    /**
     * `GET /api/ext/v1/me` — identité minimale de l'utilisateur porteur du
     * jeton. Scope requis : `profile`.
     *
     * `role` est ABSENT quand il n'est pas résoluble (identité fédérée, rôle
     * hors vocabulaire) : c'est le contrat gelé de 55.2 — jamais `null`, jamais
     * `""`, jamais une sentinelle inventée. Une extension qui n'obtient pas de
     * rôle n'habilite pas.
     */
    public function me(Request $request): JsonResponse
    {
        [$user, $record, $scope] = $this->context($request);

        $claims = OidcClaimsResolver::claimsFor($user, $scope);

        $payload = ['sub' => (string) $record->user_login];

        if (array_key_exists('name', $claims)) {
            $payload['name'] = $claims['name'];
        }

        if (array_key_exists('role', $claims)) {
            $payload['role'] = $claims['role'];
        }

        $this->logServed($record, 'me');

        return $this->ok('Identité de l\'utilisateur courant.', $payload);
    }

    /**
     * `GET /api/ext/v1/me/groups` — classes et équipes de l'utilisateur
     * porteur du jeton. Scope requis : `groups`.
     *
     * `groups` est TOUJOURS présent (liste, éventuellement vide) : « aucun
     * groupe » est une donnée, pas une absence de réponse. Noms NUS triés,
     * types `classe` et `equipe` uniquement — jamais un DN, jamais un
     * identifiant de base.
     */
    public function groups(Request $request): JsonResponse
    {
        [$user, $record, $scope] = $this->context($request);

        $claims = OidcClaimsResolver::claimsFor($user, $scope);

        $payload = [
            'sub' => (string) $record->user_login,
            // Le scope `groups` est garanti présent par le middleware ; le
            // repli `[]` couvre le seul cas restant — un résolveur qui
            // n'aurait rien produit — par une liste vide plutôt que par une
            // clé manquante, qui romprait le contrat de type.
            'groups' => array_values((array) ($claims['groups'] ?? [])),
        ];

        $this->logServed($record, 'me.groups');

        return $this->ok('Groupes de l\'utilisateur courant.', $payload);
    }

    /**
     * L'identité de la requête, telle que le middleware l'a résolue.
     *
     * @return array{0: User, 1: OidcAccessToken, 2: string}
     */
    private function context(Request $request): array
    {
        /** @var User $user */
        $user = $request->attributes->get('ext.user');
        /** @var OidcAccessToken $record */
        $record = $request->attributes->get('ext.record');

        return [$user, $record, (string) $request->attributes->get('ext.effective_scope', '')];
    }

    /**
     * Réponse au format MAISON : `success`, `message`, puis les clés métier À
     * LA RACINE.
     *
     * `no-store` : une réponse porteuse d'identité ne doit être conservée ni
     * par un proxy, ni par un navigateur, ni par le cache HTTP d'un SDK.
     *
     * @param  array<string, mixed>  $payload
     */
    private function ok(string $message, array $payload): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message] + $payload)
            ->withHeaders([
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ]);
    }

    /** ⚠️ Jamais de PII au journal : ni `sub`, ni `name`, ni `groups`. */
    private function logServed(OidcAccessToken $record, string $endpoint): void
    {
        Log::channel('oidc')->info('[MeController] oidc.ext_api.served', [
            'action_type' => 'oidc.ext_api.served',
            'endpoint' => $endpoint,
            'client_id' => $record->client?->client_id,
            'token_hash_prefix' => substr((string) $record->token_hash, 0, 8),
        ]);
    }
}
