<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Models\User;

/**
 * Story 4.10 — DTO retourné par {@see IpxeAuthService::authorize()}.
 *
 * Immuable. Le caller peut :
 *  - lire `$status` pour décider du flow (HALT / autoriser),
 *  - lire `$username` pour l'audit ou la propagation,
 *  - lire `$user` (PG Eloquent) pour les checks de policy ultérieurs.
 */
final class IpxeAuthOutcome
{
    public function __construct(
        public readonly IpxeAuthStatus $status,
        public readonly ?string $username,
        public readonly ?User $user,
    ) {
    }
}
