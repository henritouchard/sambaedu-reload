<?php

namespace App\Policies;

use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Story 7.2 (AC5) — Policy pour les partages (Epic 4.6 backlog).
 *
 * Gates :
 *  - `viewAny-share` : `share.view`.
 *  - `view-share`    : `share.view`.
 *  - `refresh-share` : `share.refresh`.
 */
class SharePolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'viewAny-share' => 'viewAny',
        'view-share' => 'view',
        'refresh-share' => 'refresh',
    ];

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'share.view');
    }

    public function view(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'share.view');
    }

    public function refresh(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'share.refresh');
    }
}
