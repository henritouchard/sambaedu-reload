<?php

declare(strict_types=1);

namespace App\Models\Pivot;

use App\Support\RoleCatalog;
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
     * Story 42.1 → 62.1 — les trois clés HISTORIQUES du rôle d'arête
     * `user_group_user.role`.
     *
     * Le rôle d'un user DANS un groupe est un attribut d'arête `string`. Story
     * 60.2 — ce vocabulaire est GÉNÉRIQUE : il qualifie une appartenance, il ne
     * décrit aucun métier scolaire et n'est pas un niveau d'accès (l'accès est
     * l'autre côté du mappage, `ro|rw`). Le libellé MÉTIER dépend du type de
     * groupe et vit dans {@see \App\Support\RoleCatalog} — `manager` se lit
     * « Enseignant » en classe, « Porteur » en projet, « Référent » en équipe :
     *  - `member`  : membre simple, sans rôle de gestion sur ce groupe ;
     *  - `manager` : membre qui gère le groupe (posé par défaut pour un
     *                `users.role='prof'` au rattachement, cf.
     *                {@see self::defaultRoleForGlobalRole()}) ;
     *  - `owner`   : « propriétaire » de l'arête — ABSORBE l'ancien flag d'arête
     *                `is_head_teacher` (professeur principal). Depuis 42.2, le
     *                rôle est la SEULE source (le miroir n'est plus écrit) ; c'est
     *                lui qui alimente la projection d'annuaire `PP_`.
     *
     * SQLite ne borne PAS les varchar (`string('role', 20)` non appliqué en
     * test — `project_sqlite_tests_no_varchar_enforcement`). La garde applicative
     * {@see self::assertValidRole()} est donc la SEULE frontière côté SE5 ; PG
     * lèverait un 22001 seulement à l'écriture d'une valeur > 20 en prod.
     *
     * **Ces trois-là restent des CONSTANTES, et c'est délibéré.** Story 62.1 : le
     * vocabulaire n'est plus borné, il est CATALOGUÉ ({@see self::roles()}) — un
     * établissement peut y ajouter « tuteur ». Mais ces trois clés-ci sont écrites
     * EN LITTÉRAL par du code vivant (dérivation au rattachement, garde
     * « professeur principal ⇒ classe », projection d'annuaire `PP_`, recettes
     * seedées) : elles sont structurelles, elles se seedent, elles ne se
     * suppriment jamais.
     */
    public const ROLE_MEMBER = 'member';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_OWNER = 'owner';

    /**
     * Story 62.1 — le vocabulaire de rôle d'arête, LU dans le catalogue.
     *
     * Ce n'est plus une constante : le catalogue ({@see \App\Models\GroupRole})
     * est administrable depuis `/admin/settings/groups`. La lecture est mémoïsée
     * dans {@see RoleCatalog} et garantit TOUJOURS au moins les trois clés
     * historiques, même sur une base non seedée — le plancher est un invariant,
     * pas une commodité.
     *
     * @return list<string>
     */
    public static function roles(): array
    {
        return RoleCatalog::keys();
    }

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
     * Story 42.1 → 62.1 — Garde applicative du vocabulaire de rôle d'arête.
     *
     * SQLite ne borne pas les varchar : tout chemin applicatif qui reçoit une
     * valeur de rôle NON constante (backfill, helper de dérivation, futurs
     * consommateurs) DOIT passer par cette garde. Lève une
     * {@see InvalidArgumentException} hors du catalogue ({@see self::roles()}).
     */
    public static function assertValidRole(string $role): void
    {
        $roles = self::roles();

        if (!in_array($role, $roles, true)) {
            throw new InvalidArgumentException(sprintf(
                'Rôle d\'arête invalide : « %s ». Vocabulaire attendu : %s.',
                $role,
                implode('|', $roles)
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
        // exercée en test. Coût nul : 42.2 (projection) et 42.4 (read-back du
        // trio AD) en héritent — la dérivation heuristique HORS trio (D5) passe
        // par ici, et le read-back du trio écrit des constantes de vocabulaire
        // (jamais d'assertValidRole en levée dans le chemin d'import — D6).
        self::assertValidRole($role);

        return $role;
    }
}
