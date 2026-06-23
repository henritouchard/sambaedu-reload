<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\WorkstationEnvironment;
use App\Models\Workstation;
use App\Models\WorkstationGroup;

/**
 * Résout UN `WorkstationEnvironment` pour un poste appartenant à N parcs
 * (Story 26.1 — AC3).
 *
 * Un poste vit dans plusieurs `WorkstationGroup` (sa salle physique + ses parcs
 * logiques, pivot global 4.11). Chaque parc PEUT déclarer un environnement ;
 * ce service applique la **précédence** :
 *
 *     nomade > personal_local > shared_local
 *
 * et retourne le **défaut `shared_local`** quand aucune valeur n'est déclarée
 * (poste sans groupe, ou tous les groupes à `null`). La précédence vit ICI et
 * NULLE PART ailleurs (décision D1, parallèle `StateMaille`/`StateCompiler`) —
 * ni dans l'enum, ni dans les futurs StateProviders.
 *
 * Lecture **exclusivement Postgres** : `WorkstationGroup::whereIn('id', ...)`.
 * JAMAIS d'AD / LdapRecord / APCu (NFR7, discipline absolue iso
 * {@see TargetContext}). Service stateless → singleton sans état.
 *
 * **Point de consommation Epic 27** : les handlers (raccourcis 27.1, profils
 * navigateur 27.4) appelleront ce service depuis un
 * `StateProvider::itemsFor(TargetContext $ctx)`. Pour rester dans la discipline
 * « les providers ne re-requêtent jamais les appartenances », privilégier
 * {@see resolveForGroupIds()} avec les ids déjà mémorisés par
 * `TargetContext::workstationGroupIds()`.
 *
 * ⚠️ Note (26.1) : ce service n'a jamais été branché sur le canal legacy
 * (`ApplicationScriptsGenerator`/`ShortcutCompilerService` + le pansement
 * Bug C 4e5a152, tous supprimés à l'extinction legacy 27.14). Le Bug C est
 * corrigé définitivement par le handler raccourcis (Story 27.1) qui consomme
 * CE service. Ne PAS recâbler de canal legacy.
 */
final readonly class WorkstationEnvironmentResolver
{
    /**
     * Ordre de précédence décroissant : la première valeur présente dans la
     * collection des parcs gagne.
     *
     * ⚠️ EXHAUSTIVE : toute nouvelle case de {@see WorkstationEnvironment} DOIT
     * être ajoutée ici, sinon elle serait ignorée silencieusement (retombée sur
     * `shared_local`). Le test `precedence_covers_every_enum_case` verrouille
     * cette invariant.
     *
     * @var list<WorkstationEnvironment>
     */
    public const PRECEDENCE = [
        WorkstationEnvironment::Nomade,
        WorkstationEnvironment::PersonalLocal,
        WorkstationEnvironment::SharedLocal,
    ];

    /**
     * Résout l'environnement d'un poste depuis tous ses parcs (salle + parcs
     * logiques). Une requête Postgres sur le pivot global.
     */
    public function resolve(Workstation $workstation): WorkstationEnvironment
    {
        $ids = $workstation->groups()->pluck('workstation_groups.id')->all();

        return $this->resolveForGroupIds($ids);
    }

    /**
     * Résout l'environnement à partir d'une liste d'ids de groupes DÉJÀ
     * résolue (point d'entrée privilégié pour les StateProviders Epic 27 :
     * `TargetContext::workstationGroupIds()` — pas de re-requête des
     * appartenances).
     *
     * @param  list<int>  $workstationGroupIds
     */
    public function resolveForGroupIds(array $workstationGroupIds): WorkstationEnvironment
    {
        if ($workstationGroupIds === []) {
            return WorkstationEnvironment::SharedLocal;
        }

        $declared = WorkstationGroup::query()
            ->whereIn('id', $workstationGroupIds)
            ->whereNotNull('environment')
            ->pluck('environment');

        foreach (self::PRECEDENCE as $candidate) {
            if ($declared->contains($candidate)) {
                return $candidate;
            }
        }

        return WorkstationEnvironment::SharedLocal;
    }
}
