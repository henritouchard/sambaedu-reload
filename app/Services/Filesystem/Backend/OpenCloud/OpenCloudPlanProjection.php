<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\OpenCloud;

use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * LE PLAN, TRADUIT UNE FOIS, DANS LE MODÈLE D'UN ESPACE DE PROJET.
 *
 * ---------------------------------------------------------------------------
 * **UN SEUL ÉTAGE, ET C'EST LA DIFFÉRENCE MAJEURE AVEC L'AUTRE PRODUIT.**
 *
 * Le dossier d'équipe de l'autre produit a deux étages — un plafond par groupe,
 * des règles par chemin qui le rabaissent — et la clôture s'y obtient par
 * SOUSTRACTION. Ici, il n'y en a qu'un : **un octroi, posé sur un item**. Il n'y a
 * ni plafond de zone à rabaisser, ni règle de masque, ni mécanisme de refus —
 * mesuré le 2026-08-13, l'action de refus rend `400 « resharing not supported »`
 * et le rôle de refus de l'autre fork n'est pas au catalogue.
 *
 * **La clôture s'obtient donc par CONSTRUCTION : on n'octroie rien à la racine.**
 * Mesuré : un destinataire qui n'a reçu que `_travail` obtient `207` dessus,
 * `404` sur la racine de l'espace et `404` sur les dossiers voisins — il ne les
 * voit même pas. Refuser par construction est plus fort que refuser par
 * soustraction : il n'y a rien à soustraire, donc rien qui puisse survivre.
 *
 * **La contrepartie est FERME et elle est dite.** Un octroi posé sur un item
 * propage à tout SON sous-arbre (mesuré : un octroi sur la racine donne `207`
 * partout ; un octroi sur le seul `_travail` rend `_travail/devoirs` navigable),
 * et rien ne le referme. Quand le plan octroie sur un ANCÊTRE et referme un rôle
 * plus bas, la clôture n'est **pas exprimable** — le backend le CONSTATE à la
 * relecture, sur chaque ancêtre et pas seulement sur la racine, et rend
 * `non_exprimable` en nommant le rôle. C'est le résultat que le contrat a été
 * dessiné pour porter.
 *
 * ---------------------------------------------------------------------------
 * **TROIS ÉTATS D'OCTROI, ET LE DEUXIÈME N'EST PAS EXPRIMABLE ICI** :
 *
 *  | état du plan            | traduction                                        |
 *  |-------------------------|---------------------------------------------------|
 *  | octroi ACTIF            | un octroi portant le rôle compatible le plus riche |
 *  | octroi SUSPENDU         | **inexprimable** — voir ci-dessous                 |
 *  | rôle en CLÔTURE         | AUCUN octroi (et c'est cela, la fermeture)         |
 *
 * Mesuré : un octroi explicitement VIDE est refusé (`roles: []` et
 * `actions: []` rendent `400`), et le minimum acceptable
 * (`libre.graph/driveItem/basic/read`) n'est pas « rien » — il fait apparaître le
 * dossier chez son destinataire et son ouverture rend `500`. La suspension se
 * matérialise donc comme une ABSENCE d'octroi, l'effet est juste (aucun accès),
 * mais la DISTINCTION entre « suspendu » et « jamais accordé » est perdue : le
 * nœud rend `non_exprimable` en le disant.
 *
 * **Un sujet à la fois octroyé par un rôle et clos par un autre reste OCTROYÉ** :
 * l'union au plus permissif est la doctrine de l'epic, et la clôture n'a jamais
 * été une interdiction — elle constate qu'un rôle n'a rien reçu.
 */
final class OpenCloudPlanProjection
{
    /**
     * @param  array<string, array{type:string,id:string,subject:PlanSubject}>  $principals  clé => principal
     * @param  array<string, string>  $keyBySubject  clé de tri de sujet => clé de principal.
     *                                                 PRIVÉE : elle documente une étape de la
     *                                                 compilation, elle n'est lue par personne
     *                                                 d'autre — et une propriété publique que
     *                                                 rien ne lit finit par être lue de travers.
     * @param  array<string, array<string, list<string>>>  $desired  chemin => (clé => verbes)
     * @param  array<string, array<string, PlanSubject>>  $closedSubjects  chemin => (clé => sujet refermé)
     * @param  array<string, array<string, true>>  $suspended  chemin => clés d'octrois SUSPENDUS
     * @param  array<string, list<string>>  $nodeDetails  chemin => causes d'octroi NON ÉCRIT (bloquantes)
     * @param  list<string>  $notices  constats qui n'empêchent AUCUNE écriture
     * @param  array<string, array{subject:PlanSubject,members:list<string>}>  $groups  nom => appartenance voulue
     */
    private function __construct(
        public readonly array $principals,
        private readonly array $keyBySubject,
        public readonly array $desired,
        public readonly array $closedSubjects,
        public readonly array $suspended,
        public readonly array $nodeDetails,
        public readonly array $notices,
        public readonly array $groups,
    ) {
    }

    public static function compile(FilePlan $plan, OpenCloudSubjectProjector $projector): self
    {
        $principals = [];
        $keyBySubject = [];
        $groups = [];
        $unresolved = [];
        $notices = [];

        // --- 1. Les principaux, une fois pour toutes -------------------------
        foreach ($projector->subjectsOf($plan) as $subject) {
            if ($subject->type === PlanSubject::TYPE_USER) {
                $id = $projector->openCloudUserIdFor($subject);
                if ($id === null) {
                    $unresolved[$subject->sortKey()] = $projector->identityRemediation($projector->loginFor($subject));

                    continue;
                }
                $key = ObservedPermission::TYPE_USER . ':' . $id;
                $principals[$key] = ['type' => ObservedPermission::TYPE_USER, 'id' => $id, 'subject' => $subject];
                $keyBySubject[$subject->sortKey()] = $key;

                continue;
            }

            $name = $projector->groupNameFor($subject);
            if ($name === null) {
                $unresolved[$subject->sortKey()] = sprintf(
                    'aucun nom de groupe distant n\'est dérivable du groupe interne #%d : son octroi n\'a pas '
                    . 'été écrit.',
                    $subject->id,
                );

                continue;
            }

            $key = ObservedPermission::TYPE_GROUP . ':' . $name;
            $principals[$key] = ['type' => ObservedPermission::TYPE_GROUP, 'id' => $name, 'subject' => $subject];
            $keyBySubject[$subject->sortKey()] = $key;

            $membership = $projector->membersFor($subject);
            $groups[$name] = ['subject' => $subject, 'members' => $membership['members']];

            if ($membership['missing'] !== []) {
                // **UN CONSTAT, PAS UN ÉCHEC DE NŒUD.** L'octroi du groupe EST
                // écrit ; ce qui manque, c'est le COMPTE de certaines personnes —
                // un état de l'annuaire de l'instance, pas de la traduction du
                // plan. Le rendre bloquant peindrait en rouge tout répertoire
                // d'une instance dont les comptes ne sont pas encore rattachés, et
                // l'exploitant cesserait de lire des rouges qui ne disent rien de
                // la zone.
                $notices[] = sprintf(
                    '%d membre(s) du groupe interne #%d n\'ont pas encore d\'identité OpenCloud connue (%s…) : '
                    . 'l\'octroi du groupe est en place, mais ces personnes n\'y accéderont qu\'une fois leur '
                    . 'compte rattaché.',
                    count($membership['missing']),
                    $subject->id,
                    $membership['missing'][0],
                );
            }
        }

        // --- 2. Les verbes voulus, nœud par nœud -----------------------------
        $desired = [];
        $closedSubjects = [];
        $suspended = [];
        $nodeDetails = [];

        foreach ($plan->nodes as $node) {
            $desired[$node->path] = [];
            $closedSubjects[$node->path] = [];
            $suspended[$node->path] = [];
            $nodeDetails[$node->path] = [];

            foreach ($node->grants as $grant) {
                $key = $keyBySubject[$grant->subject->sortKey()] ?? null;
                if ($key === null) {
                    $detail = $unresolved[$grant->subject->sortKey()] ?? null;
                    if ($detail !== null && ! in_array($detail, $nodeDetails[$node->path], true)) {
                        $nodeDetails[$node->path][] = $detail;
                    }

                    continue;
                }

                if (! $grant->isActive()) {
                    // La suspension est PORTÉE jusqu'ici pour pouvoir être DITE.
                    // Elle ne se traduit pas — voir le docblock de classe.
                    $suspended[$node->path][$key] = true;
                    $desired[$node->path][$key] ??= [];

                    continue;
                }

                // Union au plus permissif : deux rôles qui pointent le même sujet
                // ne se retirent rien l'un à l'autre.
                $desired[$node->path][$key] = array_values(array_unique(array_merge(
                    $desired[$node->path][$key] ?? [],
                    $grant->verbs,
                )));
            }

            foreach ($node->closure as $role) {
                foreach ($plan->roles[$role] ?? [] as $subject) {
                    $key = $keyBySubject[$subject->sortKey()] ?? null;
                    if ($key === null) {
                        continue;
                    }
                    // Un sujet déjà octroyé ici n'est pas refermé : l'octroi gagne.
                    if (! empty($desired[$node->path][$key])) {
                        continue;
                    }
                    $closedSubjects[$node->path][$key] = $subject;
                }
            }
        }

        return new self(
            $principals,
            $keyBySubject,
            $desired,
            $closedSubjects,
            $suspended,
            array_map(static fn (array $d): array => array_values($d), $nodeDetails),
            array_values(array_unique($notices)),
            $groups,
        );
    }

    /** L'octroi de ce principal sur ce nœud est-il SUSPENDU par le plan ? */
    public function isSuspended(string $nodePath, string $principalKey): bool
    {
        return isset($this->suspended[$nodePath][$principalKey]);
    }

    /**
     * Les nœuds du plan, dans l'ordre où ils doivent être CRÉÉS : parents d'abord.
     *
     * L'ordre n'est pas un confort : mesuré, une création dont le parent manque
     * rend `409` et échoue. Trier par PROFONDEUR rend l'ordre indépendant de
     * l'ordre canonique du plan, qui est alphabétique et ne le garantit pas.
     *
     * @return list<string>
     */
    public static function creationOrder(FilePlan $plan): array
    {
        $paths = array_values(array_filter(
            $plan->nodePaths(),
            static fn (string $p): bool => $p !== PlanNode::ROOT_PATH,
        ));

        usort($paths, static function (string $a, string $b): int {
            $depth = substr_count($a, '/') <=> substr_count($b, '/');

            return $depth !== 0 ? $depth : strcmp($a, $b);
        });

        return $paths;
    }
}
