<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\User;
use App\Models\Workstation;

/**
 * Cible d'une compilation d'état : le couple (poste, user) et ses
 * appartenances **résolues une fois** (Story 23.4).
 *
 * Hydratation **exclusivement Postgres** (relations Eloquent du pivot 4.11 et
 * du pivot SQL `user_group_user`) — jamais d'APCu ni de LdapRecord (critère
 * Keycloak, NFR7). Ne pas confondre avec `App\Dto\Wallpaper\WallpaperContext`
 * qui vient du cache legacy (`get_apps()`, groupes AD) : c'est le contexte du
 * canal legacy, celui-ci est le contexte du canal agent.
 *
 * Les providers consomment les listes d'ids mémorisées ici et ne re-requêtent
 * **jamais** les appartenances eux-mêmes.
 *
 * `$user` nullable : compilation machine-only (check-in boot, story 23.5) —
 * les mailles user sont alors simplement vides.
 */
final readonly class TargetContext
{
    /**
     * @param  list<int>  $physicalGroupIds  salles (`is_physical = true`) du poste
     * @param  list<int>  $logicalGroupIds  parcs (`is_physical = false`) du poste
     * @param  list<int>  $userGroupIds  groupes SQL du user (`[]` si machine-only)
     */
    private function __construct(
        public Workstation $workstation,
        public ?User $user,
        public array $physicalGroupIds,
        public array $logicalGroupIds,
        public array $userGroupIds,
    ) {}

    /**
     * Construit le contexte en résolvant les appartenances (3 requêtes max,
     * une par relation pivot).
     */
    public static function for(Workstation $workstation, ?User $user): self
    {
        return new self(
            workstation: $workstation,
            user: $user,
            physicalGroupIds: self::ids($workstation->physicalRooms()->pluck('workstation_groups.id')->all()),
            logicalGroupIds: self::ids($workstation->logicalGroups()->pluck('workstation_groups.id')->all()),
            userGroupIds: $user === null
                ? []
                : self::ids($user->groups()->pluck('user_groups.id')->all()),
        );
    }

    /**
     * Tous les WorkstationGroups du poste (salle + parcs), pour les ciblages
     * qui ne distinguent pas les deux mailles (ex. `overlay_signals
     * .workstation_group_id`).
     *
     * @return list<int>
     */
    public function workstationGroupIds(): array
    {
        return array_values(array_unique(array_merge($this->physicalGroupIds, $this->logicalGroupIds)));
    }

    /**
     * Triés : l'ordre des ids ne doit jamais dépendre du plan d'exécution SQL
     * (ex. `physicalGroupIds[0]` → `room` de l'item identity → ETag). Les
     * consommateurs font des `in_array`, insensibles à l'ordre.
     *
     * @param  array<int|string>  $raw
     * @return list<int>
     */
    private static function ids(array $raw): array
    {
        $ids = array_map(static fn ($id): int => (int) $id, $raw);
        sort($ids);

        return $ids;
    }
}
