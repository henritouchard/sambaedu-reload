<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Jwt;

use App\Auth\Oidc\Keys\OidcKeyManager;
use App\Models\OidcAccessToken;
use App\Models\OidcClient;
use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Story 55.1 — Émission de l'**id_token RS256** et de l'**access_token opaque**.
 *
 * **Frontière crypto** : c'est le SEUL fichier du namespace `App\Auth\Oidc`
 * autorisé à importer `Firebase\JWT\*` — verrouillé par
 * `tests/Architecture/OidcRoutesTest`, calque de la frontière déjà en place sur
 * `App\Auth\V1` et `App\Auth\Federated`. La dépendance crypto ne doit fuir ni
 * dans les contrôleurs, ni dans les modèles.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  STRUCTURE DE L'ID_TOKEN — structure figée par 55.1, claims métier par 55.2
 *
 *  header : { alg: "RS256", typ: "JWT", kid: "<active_kid>" }
 *  claims : { iss, sub, aud, exp, iat, jti, nonce? }
 *           + les claims MÉTIER scope-gatés fournis par l'appelant
 *             ({@see \App\Auth\Oidc\Support\OidcClaimsResolver} — `name`,
 *             `role`, `groups`, et RIEN d'autre : NFR5/NFR11).
 *
 *  ⚠️ **LES CLAIMS STANDARDS SONT INÉCRASABLES.** L'ordre du `array_merge` est
 *  LA garantie : `array_merge($metier, $standard)` — les claims standards sont
 *  écrits EN DERNIER, donc gagnent toute collision. Un résolveur bugué (ou un
 *  jour compromis) qui renverrait `sub`, `aud` ou `exp` ne peut pas altérer
 *  l'identité, le destinataire ni la durée de vie du jeton. Vérifié par un
 *  test d'injection dédié.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **`sub`** est fourni par l'appelant, résolu par le point unique
 * {@see \App\Auth\Oidc\Support\OidcSubjectResolver} : cette classe ne décide
 * jamais de ce qu'est l'identité d'un utilisateur.
 *
 * **`aud` = `client_id`** : l'id_token est lié au client qui l'a demandé. Un
 * client qui recevrait un jeton émis pour un autre doit le rejeter — c'est la
 * défense contre la confusion de destinataire.
 *
 * **`jti` = UUID v4** aléatoire (non corrélé au sujet ni au client) : le client
 * ou le SDK s'en sert pour détecter un rejeu (vérifié en 55.3).
 *
 * **Fail-closed** : clé absente ⇒ exception explicite « lancer
 * `php artisan oidc:keys:init` ». Jamais d'émission dégradée, jamais de repli
 * sur un autre algorithme.
 *
 * **Logging** : channel `oidc`, `action_type = oidc.token.issued`. JAMAIS le
 * jeton signé, jamais l'access_token clair — seulement `client_id`, `kid`,
 * `jti`, `exp`.
 */
class OidcIdTokenIssuer
{
    public function __construct(
        private readonly OidcKeyManager $keys,
    ) {
    }

    /**
     * Signe un id_token RS256 pour un couple (client, sujet).
     *
     * @param  string  $nonce  Relayé s'il est non vide (anti-rejeu côté client).
     * @param  array<string, mixed>  $businessClaims  Claims MÉTIER déjà filtrés
     *                                                par scope
     *                                                ({@see \App\Auth\Oidc\Support\OidcClaimsResolver::claimsFor()}).
     *                                                Cette classe ne les
     *                                                interprète pas : elle
     *                                                garantit seulement qu'ils
     *                                                n'écrasent rien.
     * @return array{token: string, jti: string, exp: int, iat: int, kid: string}
     */
    public function issueIdToken(
        OidcClient $client,
        string $subject,
        string $nonce = '',
        array $businessClaims = [],
    ): array {
        $kid = $this->keys->activeKid();
        $privateKey = $this->keys->loadPrivateKey();

        $ttl = (int) config('oidc.id_token_ttl', 300);
        $now = Carbon::now();
        $exp = $now->copy()->addSeconds($ttl);
        $jti = (string) Str::uuid();

        $standard = [
            'iss' => $this->issuer(),
            'sub' => $subject,
            'aud' => $client->client_id,
            'iat' => $now->getTimestamp(),
            'exp' => $exp->getTimestamp(),
            'jti' => $jti,
        ];

        // OIDC Core §3.1.3.7 : le `nonce` DOIT être relayé s'il a été fourni à
        // l'autorisation — et ne doit PAS apparaître sinon (un claim vide
        // laisserait croire à une protection anti-rejeu inexistante).
        if ($nonce !== '') {
            $standard['nonce'] = $nonce;
        }

        // ⚠️ ORDRE CRITIQUE — les standards EN DERNIER, donc gagnants.
        // `array_merge` écrase la valeur d'une clé déjà vue par celle qui
        // arrive après : aucun claim métier ne peut redéfinir `iss`, `sub`,
        // `aud`, `iat`, `exp`, `jti` ni `nonce`. Inverser ces deux arguments
        // ouvrirait une usurpation d'identité par le résolveur de claims.
        $claims = array_merge($businessClaims, $standard);

        $token = JWT::encode($claims, $privateKey, 'RS256', $kid);

        Log::channel('oidc')->info('[OidcIdTokenIssuer] oidc.token.issued', [
            'action_type' => 'oidc.token.issued',
            'client_id' => $client->client_id,
            'kid' => $kid,
            'jti' => $jti,
            'expires_at' => $exp->toIso8601String(),
            // ⚠️ Ni le jeton, ni le `sub`, ni aucun secret ne sont loggés.
        ]);

        return [
            'token' => $token,
            'jti' => $jti,
            'exp' => $exp->getTimestamp(),
            'iat' => $now->getTimestamp(),
            'kid' => $kid,
        ];
    }

    /**
     * Émet l'access_token OPAQUE (CSPRNG) et persiste son sha256.
     *
     * La réponse du token endpoint DOIT contenir un `access_token`
     * (RFC 6749 §5.1). Story 55.2 : il est désormais CONSOMMÉ par `/userinfo`.
     *
     * @param  string  $subject  Le `sub` DÉJÀ RÉSOLU (valeur publiée) — stocké
     *                           pour garantir l'égalité `sub` id_token ⇄
     *                           `sub` userinfo par construction.
     * @param  int|null  $userId  La clé de jointure vers `users`. C'est ELLE
     *                            qui sert à recalculer les claims à l'appel de
     *                            `/userinfo` — jamais le `subject`, qui n'est
     *                            pas une clé (55.2, migration `310000`).
     * @return array{clear: string, expires_in: int, expires_at: Carbon}
     */
    public function issueAccessToken(
        OidcClient $client,
        string $subject,
        string $scope,
        ?int $userId = null,
    ): array {
        $ttl = (int) config('oidc.access_token_ttl', 600);
        $expiresAt = Carbon::now()->addSeconds($ttl);

        // 32 octets CSPRNG = 256 bits d'entropie (patron des refresh tokens
        // postes, `WorkstationJwtIssuer::issueRefreshToken()`).
        $clear = bin2hex(random_bytes(32));

        OidcAccessToken::query()->create([
            'oidc_client_id' => $client->id,
            'user_id' => $userId,
            'user_login' => $subject,
            'token_hash' => hash('sha256', $clear),
            'scope' => $scope === '' ? 'openid' : $scope,
            'expires_at' => $expiresAt,
            'created_at' => Carbon::now(),
        ]);

        return [
            'clear' => $clear,
            'expires_in' => $ttl,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Valeur du claim `iss`. Identique à l'`issuer` publié par la discovery :
     * une divergence entre les deux casse la validation standard côté client.
     */
    public function issuer(): string
    {
        $iss = rtrim((string) config('oidc.issuer', ''), '/');

        return $iss !== '' ? $iss : rtrim((string) config('app.url', 'http://localhost'), '/');
    }
}
