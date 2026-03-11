<?php

namespace Database\Seeders;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder pour les permissions et rôles SambaEdu
 * 
 * Crée les 16 permissions mappées depuis les constantes SE_* legacy
 * et les rôles correspondant aux raccourcis composites.
 */
class PermissionSeeder extends Seeder
{

    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions from SambaPermission enum
        foreach (SambaPermission::cases() as $perm) {
            Permission::findOrCreate($perm->value, 'web');
        }

        // Create roles and assign permissions from SambaRole enum
        foreach (SambaRole::cases() as $sambaRole) {
            $role = Role::findOrCreate($sambaRole->value, 'web');
            $role->syncPermissions($sambaRole->permissionNames());
        }
    }

    /**
     * Retourne le mapping bitmask → permission
     */
    public static function getBitmaskMapping(): array
    {
        return SambaPermission::bitmaskMapping();
    }

    /**
     * Retourne la liste des rôles et leurs permissions
     */
    public static function getRolesMapping(): array
    {
        return collect(SambaRole::cases())
            ->mapWithKeys(fn(SambaRole $r) => [$r->value => $r->permissionNames()])
            ->toArray();
    }
}
