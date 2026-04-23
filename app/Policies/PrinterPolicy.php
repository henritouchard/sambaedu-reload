<?php

namespace App\Policies;

use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Story 7.2 (AC5) — Policy pour les imprimantes (Epic 6 backlog).
 *
 * Décision produit (0.8) : réutilise `server.admin` — les actions sur les
 * imprimantes étaient gardées par `SE_SERVER_ADMIN` en legacy et sur le
 * module printers back-office (cf. `sambaedu/gpo/printers.php`).
 *
 * Gates :
 *  - `viewAny-printer` : `server.admin` (consultation back-office admin).
 *  - `manage-printer`  : `server.admin` (modification/suppression).
 */
class PrinterPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'viewAny-printer' => 'viewAny',
        'manage-printer' => 'manage',
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
