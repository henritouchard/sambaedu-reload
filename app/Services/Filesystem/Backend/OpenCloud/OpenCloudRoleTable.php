<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\OpenCloud;

use App\Services\Filesystem\Plan\PlanGrant;

/**
 * LA TRADUCTION DES VERBES VERS LES RÔLES, PAR CONTENANCE D'ACTIONS.
 *
 * ---------------------------------------------------------------------------
 * **CE PRODUIT N'A PAS DE MASQUE DE BITS, IL A DES RÔLES NOMMÉS — ET C'EST PLUS
 * DIFFICILE, PAS PLUS FACILE.**
 *
 * Un masque de bits se somme : quatre verbes, quatre bits, aucune interprétation.
 * Un catalogue de rôles, lui, propose des PAQUETS que personne n'a taillés pour
 * notre vocabulaire. Un paquet accorde presque toujours un peu plus ou un peu
 * moins que ce que le plan décrit, et « presque » est exactement l'endroit où un
 * droit non écrit se glisse. La traduction est donc une DÉCISION, et elle est
 * écrite ici, une fois, testée.
 *
 * **LA RÈGLE, EN UNE PHRASE** : un rôle est *compatible* si TOUTES ses actions
 * sont autorisées par les verbes de l'octroi ; parmi les compatibles on retient
 * le plus riche ; et s'il ne transmet pas tous les verbes demandés, **on écrit le
 * constat** et le nœud rend `non_exprimable`. Jamais l'arrondi vers le haut :
 * accorder un droit qu'aucun verbe ne décrit produirait, sous drift STRICT, un
 * état que le plan ne sait pas DÉCRIRE — donc une dérive perpétuelle, ou pire,
 * une réconciliation qui la « corrige » en détruisant du travail.
 *
 * ---------------------------------------------------------------------------
 * **DEUX FAMILLES DE RÔLES DISJOINTES — mesuré le 2026-08-13.**
 *
 * La racine d'un espace et un sous-dossier n'acceptent PAS les mêmes rôles, et
 * deux rôles homonymes y portent des identifiants DIFFÉRENTS. Employer celui de
 * l'autre famille rend `400 « role not applicable to this resource »`. La table
 * est donc scindée, et la famille est choisie par la POSITION du nœud, jamais
 * devinée.
 *
 * **`Can manage` n'est JAMAIS octroyé.** Il porte la gestion des membres
 * (`permissions/create|update|delete|deny`), qu'aucun verbe du plan ne décrit.
 * C'est le pendant exact du droit de re-partage refusé sur l'autre produit, et
 * pour la même raison. Il est nommé ici pour que la relecture sache le
 * RECONNAÎTRE — un droit qu'on ne nomme pas est un droit qu'on confond avec du
 * bruit.
 *
 * ---------------------------------------------------------------------------
 * **CE QUE LA TABLE NE SAIT PAS EXPRIMER, ET QUI EST DIT PLUTÔT QUE TU.**
 *
 * `{lire, creer}` — « déposer sans pouvoir effacer » — n'a **aucun** rôle exact.
 * Le seul candidat (`Can upload`) accorde en plus le renommage
 * (`path/update`), que le contrat des verbes définit comme *créer + supprimer* :
 * il donnerait donc une partie de `supprimer` que l'octroi ne porte pas. On
 * retient le plus restrictif compatible (`Can view`) et le nœud rend
 * `non_exprimable` en nommant `creer`. C'est une limite PERMANENTE du modèle de
 * rôles du produit, pas une dette de notre code.
 *
 * Bonne nouvelle mesurée à côté : les recettes livrées ne produisent que `{lire}`
 * et les QUATRE verbes, et ces deux-là tombent **exactement** juste. La
 * dégradation ne concerne que les combinaisons fines de l'écran de recettes.
 */
final class OpenCloudRoleTable
{
    /** Famille de rôles applicable à la RACINE d'un espace. */
    public const FAMILY_SPACE = 'space';

    /** Famille de rôles applicable à un SOUS-DOSSIER. */
    public const FAMILY_ITEM = 'item';

    /**
     * Les rôles MESURÉS sur OpenCloud 7.2.3, avec leurs actions relues.
     *
     * Les identifiants sont épinglés ici plutôt que découverts à chaque appel :
     * les découvrir coûterait une requête par nœud, et le catalogue ne dit de
     * toute façon rien de la traduction — c'est nous qui décidons quel paquet
     * correspond à quels verbes. Le test d'intégration confronte cette table au
     * catalogue RÉEL de l'instance : si une version future la déplace, c'est lui
     * qui le dira, pas la production.
     *
     * @var array<string, array{id:string,label:string,family:string,weight:int,actions:list<string>}>
     */
    public const ROLES = [
        // --- Famille SOUS-DOSSIER --------------------------------------------
        'item.view' => [
            'id' => 'b1e2218d-eef8-4d4c-b82d-0f1a1b48f3b5',
            'label' => 'Can view',
            'family' => self::FAMILY_ITEM,
            'weight' => 10,
            'actions' => [
                'libre.graph/driveItem/basic/read',
                'libre.graph/driveItem/children/read',
                'libre.graph/driveItem/content/read',
                'libre.graph/driveItem/deleted/read',
                'libre.graph/driveItem/path/read',
                'libre.graph/driveItem/quota/read',
            ],
        ],
        'item.upload' => [
            'id' => '1c996275-f1c9-4e71-abdf-a42f6495e960',
            'label' => 'Can upload',
            'family' => self::FAMILY_ITEM,
            'weight' => 50,
            'actions' => [
                'libre.graph/driveItem/basic/read',
                'libre.graph/driveItem/children/create',
                'libre.graph/driveItem/children/read',
                'libre.graph/driveItem/content/read',
                'libre.graph/driveItem/path/read',
                'libre.graph/driveItem/path/update',
                'libre.graph/driveItem/upload/create',
            ],
        ],
        'item.edit' => [
            'id' => 'fb6c3e19-e378-47e5-b277-9732f9de6e21',
            'label' => 'Can edit',
            'family' => self::FAMILY_ITEM,
            'weight' => 60,
            'actions' => [
                'libre.graph/driveItem/basic/read',
                'libre.graph/driveItem/children/create',
                'libre.graph/driveItem/children/read',
                'libre.graph/driveItem/content/read',
                'libre.graph/driveItem/deleted/read',
                'libre.graph/driveItem/deleted/update',
                'libre.graph/driveItem/path/read',
                'libre.graph/driveItem/path/update',
                'libre.graph/driveItem/quota/read',
                'libre.graph/driveItem/standard/delete',
                'libre.graph/driveItem/upload/create',
            ],
        ],

        // --- Famille RACINE D'ESPACE -----------------------------------------
        'space.view' => [
            'id' => 'a8d5fe5e-96e3-418d-825b-534dbdf22b99',
            'label' => 'Can view',
            'family' => self::FAMILY_SPACE,
            'weight' => 40,
            'actions' => [
                'libre.graph/driveItem/basic/read',
                'libre.graph/driveItem/children/read',
                'libre.graph/driveItem/content/read',
                'libre.graph/driveItem/deleted/read',
                'libre.graph/driveItem/path/read',
                'libre.graph/driveItem/permissions/read',
                'libre.graph/driveItem/quota/read',
            ],
        ],
        'space.edit' => [
            'id' => '58c63c02-1d89-4572-916a-870abc5a1b7d',
            'label' => 'Can edit',
            'family' => self::FAMILY_SPACE,
            'weight' => 90,
            'actions' => [
                'libre.graph/driveItem/basic/read',
                'libre.graph/driveItem/children/create',
                'libre.graph/driveItem/children/read',
                'libre.graph/driveItem/content/read',
                'libre.graph/driveItem/deleted/read',
                'libre.graph/driveItem/deleted/update',
                'libre.graph/driveItem/path/read',
                'libre.graph/driveItem/path/update',
                'libre.graph/driveItem/permissions/read',
                'libre.graph/driveItem/quota/read',
                'libre.graph/driveItem/standard/delete',
                'libre.graph/driveItem/upload/create',
                'libre.graph/driveItem/versions/read',
                'libre.graph/driveItem/versions/update',
            ],
        ],
    ];

    /**
     * Le rôle d'ADMINISTRATION d'un espace. **Jamais octroyé par une traduction
     * de plan** : il porte la gestion des membres, qu'aucun verbe ne décrit.
     *
     * Il est nommé pour que la relecture le reconnaisse — c'est le rôle que
     * l'instance donne d'office au compte qui crée l'espace, et le confondre avec
     * un octroi du plan ferait rapporter un écart permanent sur la racine.
     */
    public const MANAGE_ROLE_ID = '312c0871-5ef7-4b3a-85b6-0e4074c64049';

    /**
     * Les actions qu'un verbe du plan AUTORISE.
     *
     * `path/update` (renommer / déplacer) n'est dans aucune de ces listes : le
     * contrat des verbes le définit comme *créer + supprimer*, il n'est donc
     * autorisé que lorsque les DEUX verbes sont présents ({@see allowedActions()}).
     *
     * @var array<string, list<string>>
     */
    private const VERB_ACTIONS = [
        PlanGrant::VERB_LIRE => [
            'libre.graph/driveItem/basic/read',
            'libre.graph/driveItem/children/read',
            'libre.graph/driveItem/content/read',
            'libre.graph/driveItem/deleted/read',
            'libre.graph/driveItem/path/read',
            'libre.graph/driveItem/permissions/read',
            'libre.graph/driveItem/quota/read',
            'libre.graph/driveItem/versions/read',
        ],
        PlanGrant::VERB_EDITER => [
            'libre.graph/driveItem/upload/create',
            'libre.graph/driveItem/versions/update',
        ],
        PlanGrant::VERB_CREER => [
            'libre.graph/driveItem/children/create',
            'libre.graph/driveItem/upload/create',
        ],
        PlanGrant::VERB_SUPPRIMER => [
            'libre.graph/driveItem/standard/delete',
            'libre.graph/driveItem/deleted/read',
            'libre.graph/driveItem/deleted/update',
            'libre.graph/driveItem/deleted/delete',
        ],
    ];

    /**
     * L'action qui TRANSMET un verbe : sans elle, le verbe n'est pas rendu, quoi
     * que le rôle accorde par ailleurs.
     *
     * @var array<string, string>
     */
    private const VERB_EVIDENCE = [
        PlanGrant::VERB_LIRE => 'libre.graph/driveItem/content/read',
        PlanGrant::VERB_EDITER => 'libre.graph/driveItem/upload/create',
        PlanGrant::VERB_CREER => 'libre.graph/driveItem/children/create',
        PlanGrant::VERB_SUPPRIMER => 'libre.graph/driveItem/standard/delete',
    ];

    /**
     * Le rôle à poser pour ces verbes, sur cette famille.
     *
     * @param  list<string>  $verbs  verbes du plan, en ordre canonique
     * @return array{id:string,label:string,verbs:list<string>,missing:list<string>}|null
     *                                `null` = aucun rôle compatible (octroi INEXPRIMABLE)
     */
    public static function resolve(array $verbs, string $family): ?array
    {
        if ($verbs === []) {
            // Un octroi explicitement VIDE n'existe pas dans ce modèle : mesuré,
            // `roles: []` et `actions: []` sont refusés, et le minimum acceptable
            // rend le nœud VISIBLE chez son destinataire. La suspension n'est donc
            // pas exprimable — l'appelant le DIT.
            return null;
        }

        $allowed = self::allowedActions($verbs);
        $best = null;

        foreach (self::ROLES as $role) {
            if ($role['family'] !== $family) {
                continue;
            }
            // COMPATIBLE = toutes ses actions sont autorisées par ces verbes.
            if (array_diff($role['actions'], $allowed) !== []) {
                continue;
            }
            if ($best === null || $role['weight'] > $best['weight']) {
                $best = $role;
            }
        }

        if ($best === null) {
            return null;
        }

        $conveyed = [];
        foreach ($verbs as $verb) {
            $evidence = self::VERB_EVIDENCE[$verb] ?? null;
            if ($evidence !== null && in_array($evidence, $best['actions'], true)) {
                $conveyed[] = $verb;
            }
        }

        return [
            'id' => $best['id'],
            'label' => $best['label'],
            'verbs' => $conveyed,
            // Les verbes DEMANDÉS que ce rôle ne transmet pas — le constat.
            'missing' => array_values(array_diff($verbs, $conveyed)),
        ];
    }

    /**
     * Les verbes du plan qu'un identifiant de rôle RELU exprime, en ordre
     * canonique. Un identifiant inconnu rend une liste vide — jamais une
     * devinette.
     *
     * @return list<string>
     */
    public static function verbsOf(string $roleId): array
    {
        foreach (self::ROLES as $role) {
            if ($role['id'] !== $roleId) {
                continue;
            }

            return array_values(array_filter(
                PlanGrant::VERBS,
                static fn (string $verb): bool => in_array(
                    self::VERB_EVIDENCE[$verb] ?? '',
                    $role['actions'],
                    true,
                ),
            ));
        }

        return [];
    }

    /** L'identifiant relu appartient-il au catalogue que SE5 sait décrire ? */
    public static function isKnown(string $roleId): bool
    {
        foreach (self::ROLES as $role) {
            if ($role['id'] === $roleId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les actions qu'un ensemble de verbes autorise.
     *
     * @param  list<string>  $verbs
     * @return list<string>
     */
    private static function allowedActions(array $verbs): array
    {
        $allowed = [];
        foreach ($verbs as $verb) {
            foreach (self::VERB_ACTIONS[$verb] ?? [] as $action) {
                $allowed[$action] = true;
            }
        }

        // Renommer / déplacer = créer ET supprimer. L'autoriser sur la seule
        // présence de l'un des deux donnerait à un déposant le droit de faire
        // disparaître le travail des autres.
        if (in_array(PlanGrant::VERB_CREER, $verbs, true)
            && in_array(PlanGrant::VERB_SUPPRIMER, $verbs, true)) {
            $allowed['libre.graph/driveItem/path/update'] = true;
        }

        return array_keys($allowed);
    }
}
