<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy pour la personnalisation applicative (story 4.8).
 *
 * Toutes les méthodes gardent `app.customize`. Des permissions granulaires
 * `app.customize.firefox` / `app.customize.thunderbird` peuvent être
 * ajoutées en follow-up (AC 11).
 */
class AppCustomizationPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'viewAny-app-customization' => 'viewAny',
        'view-app-customization' => 'view',
        'create-app-customization' => 'create',
        'update-app-customization' => 'update',
        'delete-app-customization' => 'delete',
        'manage-app-customization' => 'viewAny',
    ];

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'app.customize');
    }

    public function view(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'app.customize');
    }

    public function create(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'app.customize');
    }

    public function update(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'app.customize');
    }

    public function delete(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'app.customize');
    }
}
