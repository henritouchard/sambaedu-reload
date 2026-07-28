<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Services;

use App\Auth\Oidc\Support\OidcErrorCodes;
use App\Auth\Oidc\Support\OidcSubjectResolver;
use App\Models\OidcAuthorizationCode;
use App\Models\OidcClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Story 55.1 — Le cœur du flux **Authorization Code + PKCE**.
 *
 * Deux moments, deux méthodes :
 *
 *  - {@see self::validateAuthorizeRequest()} — valide une requête
 *    `/oidc/authorize` DANS UN ORDRE QUI EST LA SÉCURITÉ ELLE-MÊME (voir plus
 *    bas) ;
 *  - {@see self::consumeCode()} — échange le code au token endpoint, à usage
 *    unique, sous verrou.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  ORDRE DE VALIDATION — RÈGLE OAUTH CARDINALE
 *  On ne redirige JAMAIS vers une `redirect_uri` non validée. Sinon SE5
 *  devient un open-redirector, et le message de refus lui-même part chez
 *  l'attaquant qui a fabriqué l'URL.
 *
 *   1. `client_id` présent, connu, ACTIF …………… sinon refus LOCAL (page 400)
 *   2. `redirect_uri` présente et STRICTEMENT déclarée … sinon refus LOCAL
 *   3. — à partir d'ici seulement, les refus sont REDIRIGEABLES —
 *      `response_type` = `code`, `scope` contenant `openid`,
 *      `code_challenge` présent et `code_challenge_method` = `S256`
 *   4. nominal : émission du code
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **PKCE est OBLIGATOIRE, en S256 seul** (NFR1). Ni son absence ni la méthode
 * `plain` ne sont tolérées : sans PKCE, un code intercepté (historique de
 * navigation, log de proxy, redirection détournée) suffit à obtenir un
 * id_token. `plain` transmet le secret en clair à l'autorisation et ne protège
 * donc de rien.
 *
 * **Usage unique sous verrou** : `consumeCode()` s'exécute dans une
 * `DB::transaction()` avec `lockForUpdate()` sur la ligne du code — deux
 * échanges concurrents ⇒ un seul gagnant (patron
 * {@see \App\Services\Extensions\ExtensionLifecycleService}). ⚠️ PAS de
 * `Cache::lock()` : APCu n'a pas de support de verrou dans ce projet.
 *
 * **Un échec de vérification consomme le code.** Dès lors qu'un code valide et
 * non périmé a été présenté par son client, toute anomalie ultérieure
 * (`redirect_uri` divergente, `code_verifier` faux) le brûle : il a été
 * présenté par quelqu'un qui le possède, il n'y a pas de seconde chance.
 *
 * Le service ne loggue rien lui-même : il rend des VERDICTS, et les
 * contrôleurs — qui connaissent le contexte HTTP — les journalisent.
 */
class OidcAuthorizationService
{
    /** Refus non redirigeable → page d'erreur locale 400. */
    public const KIND_LOCAL = 'local';

    /** Refus redirigeable → 302 vers la `redirect_uri` DÉCLARÉE, avec `error`. */
    public const KIND_REDIRECT = 'redirect';

    /**
     * Valide une requête d'autorisation.
     *
     * @param  array<string, mixed>  $params  Query string de `/oidc/authorize`.
     * @return array{ok: true, client: OidcClient, redirect_uri: string, state: string, scope: string, nonce: string, code_challenge: string, code_challenge_method: string}
     *                                       |array{ok: false, kind: string, code: string, oauth_error: string, redirect_uri: string, state: string, client_id: string}
     */
    public function validateAuthorizeRequest(array $params): array
    {
        $clientId = $this->str($params, 'client_id');
        $redirectUri = $this->str($params, 'redirect_uri');
        $state = $this->str($params, 'state');

        // ── 1. Le client ─────────────────────────────────────────────────
        // Un `client_id` inconnu et un client révoqué produisent le MÊME refus
        // visible (page 400 sobre) : distinguer les deux dirait à un tiers
        // quels clients existent. Le journal, lui, les sépare.
        if ($clientId === '') {
            return $this->localRefusal(OidcErrorCodes::CLIENT_UNKNOWN, $clientId, $redirectUri, $state);
        }

        $client = OidcClient::query()->where('client_id', $clientId)->first();

        if ($client === null) {
            return $this->localRefusal(OidcErrorCodes::CLIENT_UNKNOWN, $clientId, $redirectUri, $state);
        }

        if (! $client->enabled) {
            return $this->localRefusal(OidcErrorCodes::CLIENT_DISABLED, $clientId, $redirectUri, $state);
        }

        // ── 2. L'URI de redirection ──────────────────────────────────────
        if ($redirectUri === '') {
            return $this->localRefusal(OidcErrorCodes::REDIRECT_URI_MISSING, $clientId, $redirectUri, $state);
        }

        if (! $client->allowsRedirectUri($redirectUri)) {
            return $this->localRefusal(OidcErrorCodes::REDIRECT_URI_MISMATCH, $clientId, $redirectUri, $state);
        }

        // ── 3. À partir d'ici, les refus sont redirigeables ──────────────
        $responseType = $this->str($params, 'response_type');
        if ($responseType !== 'code') {
            return $this->redirectRefusal(
                OidcErrorCodes::UNSUPPORTED_RESPONSE_TYPE,
                'unsupported_response_type',
                $clientId,
                $redirectUri,
                $state,
            );
        }

        $scope = $this->str($params, 'scope');
        if (! in_array('openid', preg_split('/\s+/', trim($scope)) ?: [], true)) {
            return $this->redirectRefusal(
                OidcErrorCodes::SCOPE_MISSING_OPENID,
                'invalid_scope',
                $clientId,
                $redirectUri,
                $state,
            );
        }

        $codeChallenge = $this->str($params, 'code_challenge');
        if ($codeChallenge === '') {
            return $this->redirectRefusal(
                OidcErrorCodes::PKCE_MISSING,
                'invalid_request',
                $clientId,
                $redirectUri,
                $state,
            );
        }

        // `code_challenge_method` absent vaut `plain` selon la RFC 7636 — donc
        // un refus, et non un défaut implicite à S256 : accepter un challenge
        // dont on ignore la méthode reviendrait à ne rien vérifier.
        $method = $this->str($params, 'code_challenge_method');
        if ($method !== 'S256') {
            return $this->redirectRefusal(
                OidcErrorCodes::PKCE_METHOD_UNSUPPORTED,
                'invalid_request',
                $clientId,
                $redirectUri,
                $state,
            );
        }

        return [
            'ok' => true,
            'client' => $client,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => trim($scope),
            'nonce' => $this->str($params, 'nonce'),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $method,
        ];
    }

    /**
     * Émet un code d'autorisation à usage unique et renvoie sa valeur CLAIRE
     * (seul son sha256 est persisté).
     *
     * @param  array{redirect_uri: string, scope: string, nonce: string, code_challenge: string, code_challenge_method: string}  $validated
     */
    public function issueCode(OidcClient $client, User $user, array $validated): string
    {
        $this->purgeStaleCodes();

        $code = bin2hex(random_bytes(32));

        OidcAuthorizationCode::query()->create([
            'oidc_client_id' => $client->id,
            'user_id' => $user->id,
            // ⚠️ Point UNIQUE de résolution du sujet — jamais `$user->login` ici.
            'user_login' => OidcSubjectResolver::for($user),
            'code_hash' => hash('sha256', $code),
            'redirect_uri' => $validated['redirect_uri'],
            'code_challenge' => $validated['code_challenge'],
            'code_challenge_method' => $validated['code_challenge_method'],
            'nonce' => $validated['nonce'],
            'scope' => $validated['scope'],
            'expires_at' => now()->addSeconds((int) config('oidc.code_ttl', 60)),
            'created_at' => now(),
        ]);

        return $code;
    }

    /**
     * Échange un code d'autorisation. Usage unique, sous verrou.
     *
     * @return array{ok: true, record: OidcAuthorizationCode}|array{ok: false, code: string, oauth_error: string}
     */
    public function consumeCode(OidcClient $client, string $code, string $redirectUri, string $codeVerifier): array
    {
        if ($code === '') {
            return $this->grantRefusal(OidcErrorCodes::CODE_MISSING, 'invalid_request');
        }

        if ($codeVerifier === '') {
            return $this->grantRefusal(OidcErrorCodes::CODE_VERIFIER_MISSING, 'invalid_request');
        }

        $hash = hash('sha256', $code);

        return DB::transaction(function () use ($client, $hash, $redirectUri, $codeVerifier): array {
            /** @var OidcAuthorizationCode|null $record */
            $record = OidcAuthorizationCode::query()
                ->where('code_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return $this->grantRefusal(OidcErrorCodes::CODE_INVALID, 'invalid_grant');
            }

            // Un code appartenant à un AUTRE client n'est pas consommé : sinon
            // un client hostile pourrait brûler les codes de ses voisins en les
            // présentant avec ses propres identifiants.
            if ($record->oidc_client_id !== $client->id) {
                return $this->grantRefusal(OidcErrorCodes::CODE_CLIENT_MISMATCH, 'invalid_grant');
            }

            if ($record->isConsumed()) {
                return $this->grantRefusal(OidcErrorCodes::CODE_CONSUMED, 'invalid_grant');
            }

            if ($record->isExpired()) {
                return $this->grantRefusal(OidcErrorCodes::CODE_EXPIRED, 'invalid_grant');
            }

            // ⚠️ À partir d'ici, le code est valide et présenté par son client :
            // TOUT échec le consomme (pas de seconde tentative).
            if (! hash_equals($record->redirect_uri, $redirectUri)) {
                $this->markConsumed($record);

                return $this->grantRefusal(OidcErrorCodes::REDIRECT_URI_MISMATCH, 'invalid_grant');
            }

            if (! $this->verifyPkce($codeVerifier, $record->code_challenge)) {
                $this->markConsumed($record);

                return $this->grantRefusal(OidcErrorCodes::CODE_VERIFIER_MISMATCH, 'invalid_grant');
            }

            $this->markConsumed($record);

            return ['ok' => true, 'record' => $record];
        });
    }

    /**
     * Vérification PKCE S256 (RFC 7636 §4.6) :
     * `BASE64URL(SHA256(code_verifier)) === code_challenge`.
     *
     * Comparaison en temps constant : le challenge n'est pas un secret, mais la
     * discipline évite d'avoir à re-juger au prochain refactor.
     */
    public function verifyPkce(string $codeVerifier, string $codeChallenge): bool
    {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($codeChallenge, $computed);
    }

    /**
     * Purge opportuniste des codes largement périmés. Volumétrie négligeable :
     * aucune tâche planifiée à déployer sur chaque instance.
     */
    private function purgeStaleCodes(): void
    {
        OidcAuthorizationCode::query()
            ->where('expires_at', '<', now()->subSeconds((int) config('oidc.code_purge_after', 3600)))
            ->delete();
    }

    private function markConsumed(OidcAuthorizationCode $record): void
    {
        $record->consumed_at = now();
        $record->save();
    }

    /**
     * @return array{ok: false, kind: string, code: string, oauth_error: string, redirect_uri: string, state: string, client_id: string}
     */
    private function localRefusal(string $code, string $clientId, string $redirectUri, string $state): array
    {
        return [
            'ok' => false,
            'kind' => self::KIND_LOCAL,
            'code' => $code,
            'oauth_error' => 'invalid_request',
            // Conservée pour le JOURNAL uniquement — jamais utilisée comme
            // cible de redirection dans cette branche.
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'client_id' => $clientId,
        ];
    }

    /**
     * @return array{ok: false, kind: string, code: string, oauth_error: string, redirect_uri: string, state: string, client_id: string}
     */
    private function redirectRefusal(
        string $code,
        string $oauthError,
        string $clientId,
        string $redirectUri,
        string $state,
    ): array {
        return [
            'ok' => false,
            'kind' => self::KIND_REDIRECT,
            'code' => $code,
            'oauth_error' => $oauthError,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'client_id' => $clientId,
        ];
    }

    /** @return array{ok: false, code: string, oauth_error: string} */
    private function grantRefusal(string $code, string $oauthError): array
    {
        return ['ok' => false, 'code' => $code, 'oauth_error' => $oauthError];
    }

    /** @param array<string, mixed> $params */
    private function str(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
