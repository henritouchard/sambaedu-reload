<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\User;
use App\Models\Workstation;
use Illuminate\Support\Facades\DB;

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
 *
 * **Hérédité physique (capacités).** `physicalGroupDepths` étend la chaîne
 * physique du poste à TOUS ses ancêtres (`parent_id`), chaque id étiqueté de sa
 * PROFONDEUR (salle directe = 0, +1 par parent ; le plus PROCHE l'emporte si un
 * ancêtre est atteint par plusieurs salles directes). C'est le SEUL canal qui
 * remonte la hiérarchie : `physicalGroupIds` reste les salles DIRECTES (l'item
 * identity lit `physicalGroupIds[0]` = salle du poste → ETag) et
 * `workstationGroupIds()` reste DIRECT (consommé par 6 providers qui n'héritent
 * PAS — wallpaper/printers/shortcuts/associations/overlay/environnement). Élargir
 * cet accesseur partagé ferait fuiter l'héritage hors des capacités (hors scope).
 * Le provider de capacités lit `physicalGroupDepths` pour ses propres requêtes.
 */
final readonly class TargetContext
{
    /**
     * @param  list<int>  $physicalGroupIds  salles DIRECTES (`is_physical = true`) du poste
     * @param  list<int>  $logicalGroupIds  parcs (`is_physical = false`) du poste
     * @param  list<int>  $userGroupIds  groupes SQL du user (`[]` si machine-only)
     * @param  array<int,int>  $physicalGroupDepths  chaîne physique ÉTENDUE aux ancêtres :
     *                                                id → profondeur (salle directe = 0)
     */
    private function __construct(
        public Workstation $workstation,
        public ?User $user,
        public array $physicalGroupIds,
        public array $logicalGroupIds,
        public array $userGroupIds,
        public array $physicalGroupDepths,
    ) {}

    /**
     * Construit le contexte en résolvant les appartenances (4 requêtes max :
     * une par relation pivot + une pour la chaîne d'ancêtres physiques).
     */
    public static function for(Workstation $workstation, ?User $user): self
    {
        $physicalGroupIds = self::ids($workstation->physicalRooms()->pluck('workstation_groups.id')->all());

        return new self(
            workstation: $workstation,
            user: $user,
            physicalGroupIds: $physicalGroupIds,
            logicalGroupIds: self::ids($workstation->logicalGroups()->pluck('workstation_groups.id')->all()),
            userGroupIds: $user === null
                ? []
                : self::ids($user->groups()->pluck('user_groups.id')->all()),
            physicalGroupDepths: self::physicalDepths($physicalGroupIds),
        );
    }

    /**
     * Tous les WorkstationGroups DIRECTS du poste (salle + parcs), pour les
     * ciblages qui ne distinguent pas les deux mailles (ex. `overlay_signals
     * .workstation_group_id`) et **n'héritent pas** de la hiérarchie physique.
     * L'hérédité physique des capacités passe par {@see $physicalGroupDepths},
     * jamais par cet accesseur (cf. note de classe).
     *
     * @return list<int>
     */
    public function workstationGroupIds(): array
    {
        return array_values(array_unique(array_merge($this->physicalGroupIds, $this->logicalGroupIds)));
    }

    /**
     * Chaîne physique ÉTENDUE aux ancêtres, id → profondeur — remontée de
     * `parent_id` depuis chaque salle directe (invariant 1-salle-max ⇒ chaîne
     * linéaire en pratique, mais on traite le cas général : profondeur MINIMALE
     * conservée si un ancêtre est atteint par plusieurs salles directes).
     *
     * Une SEULE requête (table `workstation_groups` petite, pluck de 2 colonnes
     * restreint aux groupes physiques) puis marche en mémoire — pas de N+1.
     *
     * @param  list<int>  $directRoomIds  salles directes (`is_physical = true`)
     * @return array<int,int>  id → profondeur (salle directe = 0)
     */
    private static function physicalDepths(array $directRoomIds): array
    {
        if ($directRoomIds === []) {
            return [];
        }

        // id → parent_id sur les seuls groupes physiques. `array_key_exists` (et
        // NON isset / Collection::has) sert de test « est physique » : il reste
        // fiable quand `parent_id` est null — une racine physique porte une valeur
        // null que isset/has rejetteraient à tort. On ne remonte/n'enregistre QUE
        // des nœuds physiques confirmés : un `parent_id` pointant (anomalie de
        // données, hors invariant) vers un groupe LOGIQUE est ignoré — pas de
        // reclassement physique, pas d'héritage d'un groupe dont le poste n'est
        // pas membre (review F1).
        $parentByPhysicalId = DB::table('workstation_groups')
            ->where('is_physical', true)
            ->pluck('parent_id', 'id')
            ->all();

        $depths = [];
        foreach ($directRoomIds as $rootId) {
            $current = (int) $rootId;
            $depth = 0;
            $seen = [];
            // Salle directe = physique par construction (physicalRooms()) ; on ne
            // progresse vers un parent QUE s'il est lui-même physique (clé connue).
            while (array_key_exists($current, $parentByPhysicalId) && ! isset($seen[$current])) {
                $seen[$current] = true; // garde anti-cycle (donnée corrompue).

                if (! isset($depths[$current]) || $depth < $depths[$current]) {
                    $depths[$current] = $depth;
                }

                $parent = $parentByPhysicalId[$current];
                if ($parent === null) {
                    break; // racine physique.
                }
                $current = (int) $parent;
                $depth++;
            }
        }

        return $depths;
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
