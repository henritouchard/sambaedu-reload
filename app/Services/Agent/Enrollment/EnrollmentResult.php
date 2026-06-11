<?php

declare(strict_types=1);

namespace App\Services\Agent\Enrollment;

/**
 * Story 23.3 — résultat typé de {@see EnrollmentService::redeem()}.
 *
 * Porte la décision métier (enrôlé / conflit / refus) pour que le controller
 * se contente de mapper HTTP (200 / 409 / 403) sans logique propre (règle
 * architecture : logique métier dans le service, pas le controller).
 *
 *  - `enrolled`   → 200, `$token` = clair 64 hex (transmis UNE seule fois).
 *  - `conflict`   → 409 `AGENT_ENROLL_CONFLICT` : ticket invalide MAIS le
 *    poste visé (uuid, à défaut mac) est déjà enrôlé — rien n'est écrasé.
 *  - `notAllowed` → 403 `AGENT_ENROLL_NOT_ALLOWED` : tout le reste, sans
 *    oracle distinguant ticket inconnu / expiré / déjà consommé (futur point
 *    d'accueil de la porte 2 — Story 25.3).
 */
final class EnrollmentResult
{
    private function __construct(
        public readonly bool $enrolled,
        public readonly bool $conflict,
        public readonly ?string $token,
    ) {
    }

    public static function enrolled(string $token): self
    {
        return new self(true, false, $token);
    }

    public static function conflict(): self
    {
        return new self(false, true, null);
    }

    public static function notAllowed(): self
    {
        return new self(false, false, null);
    }
}
