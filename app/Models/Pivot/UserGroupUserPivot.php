<?php

declare(strict_types=1);

namespace App\Models\Pivot;

use Illuminate\Database\Eloquent\Relations\Pivot;

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
}
