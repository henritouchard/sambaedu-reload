<?php

namespace App\Policies;

use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Story 7.2 (AC5) — Policy pour les configurations DHCP (Epic 1bis-16 backlog).
 *
 * Décision produit (0.8) : `server.admin` suffit — aucun bit DHCP dédié en
 * legacy, la page `sambaedu/dhcp/index.php` est déjà gardée par
 * `SE_SERVER_ADMIN`.
 *
 * Gates :
 *  - `viewAny-dhcp` : `server.admin`.
 *  - `manage-dhcp`  : `server.admin`.
 */
class DhcpPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'viewAny-dhcp' => 'viewAny',
        'manage-dhcp' => 'manage',
    ];

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'server.admin');
    }

    public function manage(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'server.admin');
    }
}
