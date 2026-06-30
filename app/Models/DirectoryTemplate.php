<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Eloquent\Model;

/**
 * Story 34.3 — « template de répertoire » (recette d'échange préfabriquée).
 *
 * Lecture seule côté applicatif (peuplé par {@see Database\Seeders\DirectoryTemplateSeeder},
 * jamais édité en 34.3 — Q3 option B). Une recette décrit les RÔLES-cibles d'un
 * pattern métier récurrent ; {@see App\Services\Filesystem\DirectoryTemplateService}
 * lit `roles_spec` depuis la DB pour matérialiser un {@see NetworkShare} + ses
 * assignations par maille avec le bon `access`.
 *
 * **Mailles autorisées dans une recette** : `User` et `UserGroup` UNIQUEMENT.
 * Aucune recette ne porte d'ACL sur un `WorkstationGroup` (invariant WG-montage-
 * seul de 34.1 : POSIX ne sait pas exprimer « les users de la machine X »).
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string|null $description
 * @property array<int, array<string, mixed>> $roles_spec
 */
class DirectoryTemplate extends Model
{
    /** Clés stables des 4 recettes seedées (34.3 — `élèves → profs` reporté 34.x). */
    public const KEY_DIRECTION_TO_ALL = 'direction_to_all';
    public const KEY_PROFS_TO_ELEVES = 'profs_to_eleves';
    public const KEY_USER_TO_USER = 'user_to_user';
    public const KEY_GROUP_SPACE = 'group_space';

    /**
     * Mailles autorisées dans une recette (sous-ensemble STRICT des
     * {@see NetworkShare::ALLOWED_ASSIGNABLE_TYPES} : pas de `WorkstationGroup`).
     */
    public const ALLOWED_ROLE_MAILLES = [
        User::class,
        UserGroup::class,
    ];

    protected $table = 'directory_templates';

    protected $fillable = [
        'key',
        'label',
        'description',
        'roles_spec',
    ];

    protected $casts = [
        'roles_spec' => 'array',
    ];

    /**
     * Rôles-cibles de la recette (liste ordonnée). Chaque rôle :
     * `{key, label, maille, group_type, access, cardinality}`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function roles(): array
    {
        return $this->roles_spec ?? [];
    }

    /**
     * Le rôle dont la `key` correspond, ou `null`.
     *
     * @return array<string, mixed>|null
     */
    public function role(string $key): ?array
    {
        foreach ($this->roles() as $role) {
            if (($role['key'] ?? null) === $key) {
                return $role;
            }
        }

        return null;
    }

    /**
     * `true` si aucun rôle ne porte une maille hors {@see ALLOWED_ROLE_MAILLES}
     * (invariant WG-montage-seul : une recette ne grant JAMAIS d'ACL sur un parc).
     */
    public function respectsMountOnlyInvariant(): bool
    {
        foreach ($this->roles() as $role) {
            if (! in_array($role['maille'] ?? null, self::ALLOWED_ROLE_MAILLES, true)) {
                return false;
            }
        }

        return true;
    }
}
