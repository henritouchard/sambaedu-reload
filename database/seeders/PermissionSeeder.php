<?php

namespace Database\Seeders;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder pour les permissions et rôles SambaEdu
 *
 * ============================================================================
 * Story 7.2 : seed idempotent NON-DESTRUCTIF.
 * ============================================================================
 *
 * Règles d'or (AC1) :
 *  - Les permissions de `SambaPermission::cases()` (20 depuis Story 7.3 avec
 *    l'ajout de `computer.remote.rdp`) sont créées via `Permission::findOrCreate`
 *    (idempotent, aucune perte si elles existent).
 *  - Les 9 rôles de `SambaRole::cases()` sont créés via `Role::firstOrCreate`.
 *    Leurs permissions sont resynchronisées via `syncPermissions(...)`
 *    UNIQUEMENT si le rôle vient d'être créé (`wasRecentlyCreated === true`)
 *    OU si on invoque le seeder avec le flag `--force` (param de la méthode
 *    `run()`).
 *  - Les profils custom (créés par l'UI `rights-management` → onglet Profils,
 *    ou rapatriés de la branche LDAP `rights_rdn`) sont **ignorés par ce
 *    seeder**. Ils ne sont ni supprimés ni ré-écrits.
 *
 * Objectif : sur un `php artisan db:seed` en prod, un admin ayant customisé
 * le rôle `computer-admin` retrouve ses permissions intactes. Un profil
 * "Animateur CDI" créé en UI est préservé.
 *
 * Identification seeded vs custom : `SambaRole::isSeeded($name)` (source de
 * vérité enum, pas de colonne DB `origin`).
 */
class PermissionSeeder extends Seeder
{
    /**
     * Exécute le seed.
     *
     * @param bool $force Si `true`, force la resynchro des permissions des
     *                    rôles seedés existants (rare : migration d'enum). Par
     *                    défaut `false` = non-destructif strict.
     *
     * @return array{
     *   permissions_created: int,
     *   roles_seeded_new: int,
     *   roles_seeded_synced_forced: int,
     *   roles_seeded_preserved: int,
     *   roles_custom_preserved: int,
     * }
     */
    public function run(bool $force = false): array
    {
        // Reset cache Spatie avant mutations pour éviter les incohérences intra-requête.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $stats = [
            'permissions_created' => 0,
            'roles_seeded_new' => 0,
            'roles_seeded_synced_forced' => 0,
            'roles_seeded_preserved' => 0,
            'roles_custom_preserved' => 0,
        ];

        // ---------------------------------------------------------------------
        // 1. Permissions — `findOrCreate` idempotent.
        // ---------------------------------------------------------------------
        foreach (SambaPermission::cases() as $perm) {
            $before = Permission::where('name', $perm->value)
                ->where('guard_name', 'web')
                ->exists();
            Permission::findOrCreate($perm->value, 'web');
            if (! $before) {
                $stats['permissions_created']++;
            }
        }

        // ---------------------------------------------------------------------
        // 2. Rôles seedés — `firstOrCreate` + syncPermissions conditionnel.
        // ---------------------------------------------------------------------
        foreach (SambaRole::cases() as $sambaRole) {
            $role = Role::firstOrCreate(
                ['name' => $sambaRole->value, 'guard_name' => 'web']
            );

            if ($role->wasRecentlyCreated) {
                // Création initiale : on attache les permissions canoniques de l'enum.
                $role->syncPermissions($sambaRole->permissionNames());
                $stats['roles_seeded_new']++;
            } elseif ($force) {
                // Force : re-sync (cas migration d'enum, pas le défaut).
                $role->syncPermissions($sambaRole->permissionNames());
                $stats['roles_seeded_synced_forced']++;
            } else {
                // Déjà présent, non forcé : on ne touche pas (préserve les
                // modifications faites via l'UI onglet Profils).
                $stats['roles_seeded_preserved']++;
            }
        }

        // ---------------------------------------------------------------------
        // 3. Rôles custom — comptage only (aucune action).
        // ---------------------------------------------------------------------
        $stats['roles_custom_preserved'] = Role::where('guard_name', 'web')
            ->whereNotIn(
                'name',
                array_map(fn(SambaRole $r) => $r->value, SambaRole::cases())
            )
            ->count();

        // Reset cache Spatie après mutations pour propager aux requêtes suivantes.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Log::info('[PermissionSeeder] Seed terminé', $stats);

        return $stats;
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
