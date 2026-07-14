<?php

declare(strict_types=1);

namespace App\Models\Pivot;

use Illuminate\Database\Eloquent\Relations\Pivot;
use InvalidArgumentException;

/**
 * Story 5.2 (D5=A) — Modèle pivot custom pour la table `user_group_user`.
 *
 * Permet à `App\Observers\UserGroupUserPivotObserver` d'écouter les events
 * Eloquent `created`/`deleted` sur les rows pivot User↔UserGroup. Ces events
 * sont dispatchés par Laravel uniquement si la relation BelongsToMany est
 * définie via `->using(UserGroupUserPivot::class)` sur les deux côtés
 * (`App\Models\User::groups()` + `App\Models\UserGroup::users()`).
 *
 * Note : la table `user_group_user` n'a pas de PK auto-increment dans la
 * migration `create_unified_schema` (PK composite `(user_id, user_group_id)`).
 * On définit donc `$incrementing = false` — Eloquent peut quand même router
 * les events sans dépendre d'une PK numérique.
 *
 * Le pivot ne stocke pas de timestamp (pas de `created_at` côté DB) — on
 * désactive aussi `$timestamps = false`. Si la migration évolue plus tard
 * pour tracer les attaches/detaches dans la table, ces flags peuvent être
 * réactivés.
 */
class UserGroupUserPivot extends Pivot
{
    protected $table = 'user_group_user';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Story 42.1 — Vocabulaire BORNÉ du rôle d'arête `user_group_user.role`.
     *
     * Le rôle d'un user DANS un groupe devient un attribut d'arête `string` :
     *  - `member`  : membre simple (élève, ou tout user sans rôle de gestion) ;
     *  - `manager` : professeur membre (dérivé de `users.role='prof'`) ;
     *  - `owner`   : « propriétaire » de l'arête — ABSORBE l'ancien flag d'arête
     *                `is_head_teacher` (professeur principal). Depuis 42.2, le
     *                rôle est la SEULE source (le miroir n'est plus écrit).
     *
     * SQLite ne borne PAS les varchar (`string('role', 20)` non appliqué en
     * test — `project_sqlite_tests_no_varchar_enforcement`). La garde applicative
     * {@see self::assertValidRole()} est donc la SEULE frontière côté SE5 ; PG
     * lèverait un 22001 seulement à l'écriture d'une valeur > 20 en prod.
     */
    public const ROLE_MEMBER = 'member';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_OWNER = 'owner';

    /**
     * Liste exhaustive du vocabulaire de rôle d'arête (borne applicative).
     *
     * @var array<int,string>
     */
    public const ROLES = [
        self::ROLE_MEMBER,
        self::ROLE_MANAGER,
        self::ROLE_OWNER,
    ];

    /**
     * Story 4.14 — cast booléen de l'attribut d'arête `is_head_teacher`.
     *
     * SQLite stocke les bool en 0/1 (string « 0 »/« 1 » à la lecture brute),
     * PG en true/false. Sans ce cast, `$pivot->is_head_teacher` renvoie une
     * valeur non fiable selon le driver — piège classique des tests pivot. Le
     * cast garantit un vrai bool côté lecture (`assertTrue` fiable) sur les deux
     * drivers.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'is_head_teacher' => 'boolean',
    ];

    // NB Story 42.1/42.2 — pas de cast pour `role` : c'est un `string` natif
    // fiable sur les deux drivers. Depuis 42.2, le miroir `is_head_teacher`
    // n'est PLUS écrit sur le chemin vivant (le read-back `projectFoldedGroup`
    // ne pose que `role`) : la colonne devient STALE et n'est plus LUE par
    // aucun code vivant (audit 42.2 — restent les vestiges one-shot
    // MergeLegacyUserGroups/BackfillUserGroupUserRoles, gardés hasColumn).
    // Colonne + cast + withPivot CONSERVÉS (fixtures de tests, bases
    // brownfield) jusqu'à la migration destructive `dropColumn` post-42.4.

    /**
     * Story 42.1 — Garde applicative du vocabulaire de rôle d'arête.
     *
     * SQLite ne borne pas les varchar : tout chemin applicatif qui reçoit une
     * valeur de rôle NON constante (backfill, helper de dérivation, futurs
     * consommateurs) DOIT passer par cette garde. Lève une
     * {@see InvalidArgumentException} hors {@see self::ROLES}.
     */
    public static function assertValidRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Rôle d\'arête invalide : « %s ». Vocabulaire attendu : %s.',
                $role,
                implode('|', self::ROLES)
            ));
        }
    }

    /**
     * Story 42.1 — Rôle d'arête PAR DÉFAUT au rattachement, dérivé du rôle
     * GLOBAL `users.role` (`eleve|prof|admin|autre`).
     *
     * `prof` → {@see self::ROLE_MANAGER} ; tout le reste (élève, admin, autre,
     * null) → {@see self::ROLE_MEMBER}. Jamais `owner` par défaut : le statut
     * « professeur principal » (owner) est une désignation explicite, pas un
     * défaut de rattachement.
     */
    public static function defaultRoleForGlobalRole(?string $globalRole): string
    {
        $role = $globalRole === 'prof' ? self::ROLE_MANAGER : self::ROLE_MEMBER;

        // Review 42.1 #2 — défense en profondeur : la garde est câblée sur le
        // point de dérivation partagé par tous les écrivains, pas seulement
        // exercée en test. Coût nul, et 42.2/42.4 hériteront du filet quand des
        // valeurs non constantes (UI, import AD) transiteront par ici.
        self::assertValidRole($role);

        return $role;
    }
}
