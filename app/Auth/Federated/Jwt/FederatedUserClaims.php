<?php

declare(strict_types=1);

namespace App\Auth\Federated\Jwt;

/**
 * Story 20.1.
 *
 * DTO immuable représentant les claims d'un JWT fédéré VALIDÉ. Retourné par
 * {@see FederatedJwtVerifier::verify()}. Calqué sur
 * {@see \App\Auth\V1\Jwt\WorkstationJwtClaims}.
 *
 * **Pas de méthode `isValid()`** : un `FederatedUserClaims` existant *est*
 * déjà validé (sa construction passe par le verifier qui a vérifié signature,
 * iss/aud/tier/exp/nbf et anti-rejeu jti).
 *
 * Claims portés :
 *  - `sub`   : identifiant externe stable (clé de l'`ExternalIdentity`).
 *  - `jti`   : identifiant unique du jeton (anti-rejeu).
 *  - `kid`   : identifiant de la clé de signature.
 *  - `iss`   : émetteur (IdP externe de confiance).
 *  - `aud`   : audience (= identifiant de cette instance SE5).
 *  - `tier`  : tier d'identité (`federated-user`).
 *  - `role`  : nom de rôle externe (à mapper vers un SambaRole).
 *  - `login` : login d'affichage de l'utilisateur externe.
 *  - `name`  : nom complet.
 *  - `email` : email.
 *  - `iat`/`exp` : timestamps.
 */
final readonly class FederatedUserClaims
{
    public function __construct(
        public string $sub,
        public string $jti,
        public string $kid,
        public string $iss,
        public string $aud,
        public string $tier,
        public string $role,
        public string $login,
        public string $name,
        public string $email,
        public int $iat,
        public int $exp,
    ) {
    }

    /**
     * Alias sémantique : le `sub` claim contient l'identifiant externe stable.
     */
    public function externalSub(): string
    {
        return $this->sub;
    }

    /**
     * Sérialise le sous-ensemble NON sensible des claims (logs sans secret).
     * N'expose JAMAIS le JWT brut. `login`/`name`/`email` sont volontairement
     * exclus du jeu « loggable » (PII) — cf. AC16.
     *
     * @return array<string, mixed>
     */
    public function toLoggableArray(): array
    {
        return [
            'sub' => $this->sub,
            'jti' => $this->jti,
            'iss' => $this->iss,
            'role' => $this->role,
        ];
    }

    /**
     * Sérialise l'ensemble des claims (usage interne : upsert identité). À ne
     * PAS passer au logger.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sub' => $this->sub,
            'jti' => $this->jti,
            'kid' => $this->kid,
            'iss' => $this->iss,
            'aud' => $this->aud,
            'tier' => $this->tier,
            'role' => $this->role,
            'login' => $this->login,
            'name' => $this->name,
            'email' => $this->email,
            'iat' => $this->iat,
            'exp' => $this->exp,
        ];
    }
}
