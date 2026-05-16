<?php

declare(strict_types=1);

namespace App\Auth\V1\Jwt;

/**
 * Story 16.10 — AC2.2.
 *
 * DTO immutable représentant les claims d'un JWT poste validé. Retourné par
 * `WorkstationJwtVerifier::verify()`. Injecté dans
 * `$request->attributes->set('auth_v1.jwt_claims', $claims)` par le
 * middleware `EnsureWorkstationJwt`.
 *
 * **Pas de méthode `isValid()` ou équivalent** : un `WorkstationJwtClaims`
 * existant *est* déjà validé (sa construction passe par le verifier qui a
 * vérifié `exp` + `tier` + révocation). Si on parle d'un DTO, il est valide.
 */
final readonly class WorkstationJwtClaims
{
    public function __construct(
        public string $sub,
        public string $jti,
        public string $tier,
        public string $kid,
        public int $iat,
        public int $exp,
        public string $iss,
    ) {
    }

    /**
     * Alias sémantique : le `sub` claim contient le workstation_uuid.
     */
    public function workstationUuid(): string
    {
        return $this->sub;
    }

    /**
     * Sérialise au format array (utile logs sans secret, debug).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sub' => $this->sub,
            'jti' => $this->jti,
            'tier' => $this->tier,
            'kid' => $this->kid,
            'iat' => $this->iat,
            'exp' => $this->exp,
            'iss' => $this->iss,
        ];
    }
}
