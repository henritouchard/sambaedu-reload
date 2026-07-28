<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Services;

use App\Auth\Oidc\Support\OidcErrorCodes;
use App\Models\OidcAccessToken;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Story 55.2 — Verdict sur un **access token OPAQUE** présenté en `Bearer`.
 *
 * Patron du namespace : **on rend un verdict, on ne lève pas d'exception de
 * contrôle** ({@see OidcAuthorizationService}). Un jeton refusé n'est pas une
 * anomalie du serveur : c'est un cas nominal du protocole, et il doit produire
 * une réponse normalisée, pas une trace d'erreur.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  SIX CAUSES DE REFUS, UNE SEULE RÉPONSE
 *
 *  absent · inconnu · expiré · client révoqué · utilisateur disparu ·
 *  utilisateur désactivé
 *
 *  Le verdict les distingue POUR LE JOURNAL (diagnostic d'intégration), le
 *  contrôleur n'en rend qu'un `401 invalid_token` indistinct — même doctrine
 *  que le token endpoint : ne jamais offrir d'oracle à qui teste des jetons.
 *
 *  ⚠️ **Résidu assumé (review 55.2 #2)** : le nombre de requêtes SQL avant
 *  verdict diffère selon la cause (1 pour un jeton inconnu, 2 pour un client
 *  révoqué, 3 pour un utilisateur disparu ou désactivé). La réponse HTTP est
 *  indistincte — corps et en-têtes identiques — mais le temps de traitement ne
 *  l'est pas strictement. Ce résidu est CONSERVÉ délibérément : l'écart porte
 *  sur deux lectures indexées locales, très en dessous de la gigue réseau, et
 *  l'égaliser imposerait de résoudre client ET utilisateur avant tout verdict —
 *  soit deux requêtes supplémentaires sur CHAQUE jeton invalide, ce qui offre à
 *  qui sonde des jetons une amplification de charge gratuite. On échangerait un
 *  canal non exploitable contre un vecteur de charge réel.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **« Révoquer un client rend ses jetons inutilisables »** : la promesse de
 * 55.1 devient OBSERVABLE ici. Un access token opaque se révoque parce qu'il
 * n'est qu'une clé de ligne — c'est exactement pourquoi il n'est pas un JWT
 * auto-porteur.
 *
 * **L'utilisateur se résout par `user_id`**, jamais par `user_login` (le `sub`
 * publié n'est pas une clé de jointure — {@see \App\Auth\Oidc\Support\OidcSubjectResolver}).
 *
 * ⚠️ **Aucune borne de longueur à poser ici** : le jeton présenté n'est jamais
 * PERSISTÉ — il est haché (sha256, longueur fixe 64) puis comparé. La leçon
 * « SQLite n'applique aucune borne VARCHAR » (review 55.1 #3) ne s'applique
 * qu'aux valeurs entrantes ÉCRITES en colonne bornée ; ce n'est pas le cas.
 */
class OidcAccessTokenValidator
{
    /**
     * @return array{ok: true, record: OidcAccessToken, user: User}
     *         |array{ok: false, code: string, presented: bool, token_hash_prefix: string}
     */
    public function validate(?string $bearer): array
    {
        $token = trim((string) $bearer);

        if ($token === '') {
            // RFC 6750 §3 : une requête SANS aucune information
            // d'authentification ne reçoit PAS de code d'erreur — juste le
            // challenge. `presented = false` porte cette nuance jusqu'au
            // contrôleur.
            return $this->refusal(OidcErrorCodes::ACCESS_TOKEN_MISSING, false, '');
        }

        $hash = hash('sha256', $token);
        $prefix = substr($hash, 0, 8);

        /** @var OidcAccessToken|null $record */
        $record = OidcAccessToken::query()->where('token_hash', $hash)->first();

        if ($record === null) {
            return $this->refusal(OidcErrorCodes::ACCESS_TOKEN_INVALID, true, $prefix);
        }

        if ($record->expires_at->lessThanOrEqualTo(Carbon::now())) {
            return $this->refusal(OidcErrorCodes::ACCESS_TOKEN_EXPIRED, true, $prefix);
        }

        // Révocation d'une extension pendant la vie d'un jeton : le jeton meurt
        // avec son client, sans attendre son `exp`.
        $client = $record->client;

        if ($client === null || ! $client->enabled) {
            return $this->refusal(OidcErrorCodes::CLIENT_DISABLED, true, $prefix);
        }

        $user = $record->user_id !== null
            ? User::query()->find($record->user_id)
            : null;

        if (! $user instanceof User) {
            return $this->refusal(OidcErrorCodes::USER_MISSING, true, $prefix);
        }

        // Correctif review 55.2 — SYMÉTRIE avec `$client->enabled` ci-dessus.
        // Un compte désactivé pendant la vie du jeton doit mourir avec lui,
        // exactement comme une extension révoquée : sans ce contrôle,
        // `/userinfo` continuait de servir nom, rôle et groupes d'un compte
        // désactivé pendant toute la fenêtre restante du jeton.
        //
        // Ce n'est doublonné par aucune couche : `SambaEduAuthGuard` valide
        // l'état du compte côté LDAP/AD, pas `users.is_active` — et de toute
        // façon la chaîne OIDC part d'un jeton, sans session à traverser.
        if (! $user->isActive()) {
            return $this->refusal(OidcErrorCodes::USER_INACTIVE, true, $prefix);
        }

        return ['ok' => true, 'record' => $record, 'user' => $user];
    }

    /**
     * @return array{ok: false, code: string, presented: bool, token_hash_prefix: string}
     */
    private function refusal(string $code, bool $presented, string $prefix): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'presented' => $presented,
            // Préfixe de hash SEUL (8 caractères) : de quoi corréler une
            // émission et un refus dans le journal, jamais de quoi reconstituer
            // le jeton (patron `WorkstationJwtVerifier::logRejection()`).
            'token_hash_prefix' => $prefix,
        ];
    }
}
