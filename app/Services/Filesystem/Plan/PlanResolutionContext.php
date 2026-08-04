<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Plan;

use App\Exceptions\Filesystem\PlanResolutionException;

/**
 * Story 60.1 — ENTRÉES en mémoire d'une résolution de plan.
 *
 * **Tout est fourni, rien n'est cherché.** Le résolveur ne requête aucune base,
 * ne lit aucun disque, n'ouvre aucun réseau : c'est l'appelant qui assemble ce
 * contexte. C'est ce qui rend la résolution PURE — donc testable sans base, sans
 * annuaire et sans faux processus, et rejouable à l'identique pour comparer deux
 * plans.
 *
 * **Un seul groupe : la maille du groupe EST la maille du cloisonnement.**
 * L'isolation vient de l'APPARTENANCE, pas de l'arborescence. On ne résout donc
 * pas « une classe et ses matières » d'un coup : on résout UN groupe, l'unité de
 * cloisonnement. Pour les matières, la maille pertinente sera « matière×classe »
 * et non « matière » nue — l'accrochage au type de groupe est le périmètre de la
 * story suivante ; ici, l'invariant est seulement documenté et respecté.
 *
 * Les membres portent leur RÔLE D'ARÊTE (`member|manager|owner`), vocabulaire
 * stocké inchangé. Le drapeau historique de professeur principal est mort depuis
 * la story 42.2 — seul le rôle d'arête est vivant, et c'est le seul que ce
 * contexte accepte.
 */
final class PlanResolutionContext
{
    public readonly int $groupId;

    public readonly string $groupName;

    public readonly ?string $groupType;

    /** @var list<array{id:int,login:string,edge_role:string}> triés par login */
    public readonly array $members;

    /** @var array<string, list<PlanSubject>> clé de rôle de recette => sujets */
    public readonly array $roleTargets;

    /** @var array<string,bool> chemin de nœud TEL QU'ÉCRIT dans la recette => actif */
    public readonly array $nodeActivation;

    /**
     * @param  list<array{id:int|string,login:string,edge_role:string}>  $members
     * @param  array<string, list<PlanSubject>>  $roleTargets
     * @param  array<string, bool>  $nodeActivation
     */
    public function __construct(
        int $groupId,
        string $groupName,
        ?string $groupType = null,
        array $members = [],
        array $roleTargets = [],
        array $nodeActivation = [],
    ) {
        if ($groupId <= 0) {
            throw PlanResolutionException::make('le groupe de cloisonnement doit porter une identité interne positive.');
        }
        if (trim($groupName) === '') {
            throw PlanResolutionException::make('le groupe de cloisonnement doit porter un nom.');
        }

        $normalized = [];
        $seen = [];
        foreach ($members as $member) {
            $id = (int) ($member['id'] ?? 0);
            $login = (string) ($member['login'] ?? '');
            $edgeRole = $member['edge_role'] ?? null;

            if ($id <= 0) {
                throw PlanResolutionException::make('un membre doit porter une identité interne positive.');
            }
            if ($login === '') {
                throw PlanResolutionException::make(sprintf('le membre #%d n\'a pas de login.', $id));
            }
            if (! GroupNameNormalizer::isKnownEdgeRole($edgeRole)) {
                throw PlanResolutionException::make(sprintf(
                    'rôle d\'arête inconnu pour le membre #%d (attendu : %s).',
                    $id,
                    implode('|', GroupNameNormalizer::EDGE_ROLES),
                ));
            }
            if (isset($seen[$id])) {
                throw PlanResolutionException::make(sprintf(
                    'le membre #%d apparaît deux fois dans le contexte (une arête, un rôle).',
                    $id,
                ));
            }
            $seen[$id] = true;

            $normalized[] = ['id' => $id, 'login' => $login, 'edge_role' => (string) $edgeRole];
        }

        // Tri STABLE par (login, id) : le contexte ne doit pas hériter de l'ordre
        // de lecture de l'appelant, sans quoi deux résolutions du même état
        // pourraient différer avant même le tri final du plan.
        usort($normalized, static fn (array $a, array $b): int => [$a['login'], $a['id']] <=> [$b['login'], $b['id']]);

        $targets = [];
        foreach ($roleTargets as $roleKey => $subjects) {
            foreach ((array) $subjects as $subject) {
                if (! $subject instanceof PlanSubject) {
                    throw PlanResolutionException::make(sprintf(
                        'la cible du rôle « %s » doit être un sujet de plan (identité interne), pas un nom.',
                        (string) $roleKey,
                    ));
                }
            }
            $targets[(string) $roleKey] = array_values((array) $subjects);
        }

        $activation = [];
        foreach ($nodeActivation as $path => $active) {
            $activation[(string) $path] = (bool) $active;
        }

        $this->groupId = $groupId;
        $this->groupName = $groupName;
        $this->groupType = $groupType;
        $this->members = $normalized;
        $this->roleTargets = $targets;
        $this->nodeActivation = $activation;
    }

    /**
     * Les membres portant `$edgeRole`, dans l'ordre stable du contexte.
     *
     * @return list<array{id:int,login:string,edge_role:string}>
     */
    public function membersWithEdgeRole(string $edgeRole): array
    {
        return array_values(array_filter(
            $this->members,
            static fn (array $m): bool => $m['edge_role'] === $edgeRole,
        ));
    }

    /**
     * Les sujets d'un rôle de recette. Tableau VIDE si le rôle n'a aucune cible :
     * le rôle n'octroie alors rien sur aucun nœud.
     *
     * **Un rôle sans cible n'entre PAS dans la clôture pour autant.** La clôture
     * est STRUCTURELLE : elle se calcule sur les octrois ÉCRITS dans la recette,
     * jamais sur les cibles résolues ici. Un rôle qui porte un octroi sur un nœud
     * en reste donc absent même si son audience est momentanément vide (aucun
     * enseignant référent cette année). C'est délibéré : la clôture est ce qu'un
     * backend à propagation devra refermer, et refermer un accès que la recette
     * accorde — au motif que personne ne l'occupe aujourd'hui — obligerait à le
     * rouvrir dès la première arrivée. La clôture doit rester une propriété de la
     * RECETTE, pas de l'effectif du jour.
     *
     * @return list<PlanSubject>
     */
    public function targetsForRole(string $roleKey): array
    {
        return $this->roleTargets[$roleKey] ?? [];
    }

    /**
     * État d'activation d'un nœud activable, par son chemin TEL QU'ÉCRIT dans la
     * recette. Défaut : ACTIF — iso le comportement historique, où l'espace
     * d'échange est ouvert à la création.
     */
    public function isNodeActive(string $specPath): bool
    {
        return $this->nodeActivation[$specPath] ?? true;
    }
}
