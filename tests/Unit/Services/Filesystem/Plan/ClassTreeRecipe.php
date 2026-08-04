<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Plan;

use App\Models\DirectoryTemplate;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\PlanResolutionContext;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 60.1 — LE cas d'épreuve : le partage classe, dit en vocabulaire de plan.
 *
 * Cette recette n'est PAS seedée (aucune recette n'est modifiée par la story
 * 60.1) : elle existe uniquement pour prouver que le langage est assez expressif
 * pour porter le partage classe historique — c'est ce qui débloquera la story
 * 60.5. Elle exerce les QUATRE natures, les TROIS rôles d'arête, un groupe au nom
 * déjà préfixé (piège du double préfixe) et un plafond.
 *
 * Aucun terme de plan de fichiers concret n'y figure : ni mode, ni nom de groupe
 * système, ni chemin absolu. C'est précisément la propriété que le test de garde
 * vérifie mécaniquement.
 */
trait ClassTreeRecipe
{
    /** Identités internes du décor (jamais des logins : ce sont des sujets). */
    public const GROUP_CLASSE_ID = 7;

    public const GROUP_EQUIPE_ID = 11;

    public const USER_ALICE_ID = 101;   // manager

    public const USER_BRUNO_ID = 102;   // member

    public const USER_CAMILLE_ID = 103; // member

    public const USER_DENIS_ID = 104;   // owner

    /**
     * @param  string|null  $nodeLabel  remplace le libellé du premier nœud. Réservé au
     *                                  méta-test de la garde de neutralité : le libellé est
     *                                  le seul champ de TEXTE LIBRE d'un plan, donc le seul
     *                                  par lequel on peut faire entrer un marqueur interdit
     *                                  dans une sérialisation authentique pour prouver que
     *                                  la détection le voit. Aucun autre test ne s'en sert.
     */
    protected function classTreeTemplate(?string $nodeLabel = null): DirectoryTemplate
    {
        $template = new DirectoryTemplate([
            'key' => 'classe_share',
            'label' => 'Partage de classe',
            'description' => 'Le partage de classe historique, dit en vocabulaire de plan.',
            'roles_spec' => [
                [
                    'key' => 'equipe',
                    'label' => 'Équipe enseignante',
                    'maille' => UserGroup::class,
                    'group_type' => 'equipe',
                    'access' => 'rw',
                    'cardinality' => 'one',
                ],
                [
                    'key' => 'classe',
                    'label' => 'Élèves de la classe',
                    'maille' => UserGroup::class,
                    'group_type' => 'classe',
                    'access' => 'ro',
                    'cardinality' => 'one',
                ],
                [
                    'key' => 'referents',
                    'label' => 'Enseignants référents',
                    'maille' => UserGroup::class,
                    'group_type' => 'classe',
                    'access' => 'rw',
                    'cardinality' => 'one',
                ],
            ],
            'path_pattern' => 'Classes/Classe_{group.bare_name}',
            'nodes_spec' => [
                [
                    'path' => '_travail',
                    'label' => 'Documents de travail',
                    'nature' => 'partagee',
                    'grants' => [
                        ['role' => 'equipe', 'access' => 'rw'],
                        ['role' => 'classe', 'access' => 'ro'],
                    ],
                ],
                [
                    'path' => '_travail/devoirs',
                    'label' => 'Dépôt des devoirs',
                    'nature' => 'contenu_libre',
                    'grants' => [
                        ['role' => 'equipe', 'access' => 'rw'],
                        ['role' => 'classe', 'access' => 'ro'],
                    ],
                ],
                [
                    // LE nœud de l'AC9 : la classe n'a AUCUN octroi ici.
                    'path' => '_profs',
                    'label' => 'Espace des enseignants',
                    'nature' => 'partagee',
                    'grants' => [
                        ['role' => 'equipe', 'access' => 'rw'],
                        ['role' => 'referents', 'access' => 'rw'],
                    ],
                ],
                [
                    // LE nœud de l'AC3 : suspendre n'est pas supprimer.
                    'path' => '_echange',
                    'label' => 'Espace d\'échange',
                    'nature' => 'activable',
                    'activable' => true,
                    'grants' => [
                        ['role' => 'equipe', 'access' => 'rw'],
                        ['role' => 'classe', 'access' => 'rw', 'suspendable' => true],
                    ],
                ],
                [
                    // LE nœud de l'AC4 : un dossier par membre, octroi nominatif.
                    'path' => '{member.login}',
                    'label' => 'Dossier personnel',
                    'nature' => 'par_membre',
                    'edge_role' => 'member',
                    'plafond' => 2147483648,
                    'grants' => [
                        ['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'access' => 'rw'],
                        ['role' => 'equipe', 'access' => 'rw'],
                    ],
                ],
            ],
        ]);

        if ($nodeLabel !== null) {
            $nodes = $template->nodes_spec;
            $nodes[0]['label'] = $nodeLabel;
            $template->nodes_spec = $nodes;
        }

        return $template;
    }

    /**
     * Story 60.2 — LA MÊME recette, rendue AUTO-RÉSOLVABLE et accrochable au type
     * `classe`. C'est exactement ce que la story 60.5 seedera, et l'écrire ici en
     * décor est la répétition générale.
     *
     * Deux rôles seulement, et deux stratégies :
     *  - `equipe` en stratégie d'ARÊTE sur `manager|owner`. C'est la seule façon
     *    de dire « les enseignants de cette classe » : depuis le repliement 4.13,
     *    l'équipe pédagogique n'a plus de ligne à elle en base. Deux rôles
     *    d'arête listés ⇒ DEUX sujets abstraits, quel que soit l'effectif.
     *  - `classe` en stratégie « lui-même » : le groupe de matérialisation entier.
     *
     * Aucun rôle en cible désignée : c'est la condition d'accrochage, et c'est ce
     * qui permettra à la création d'un groupe de matérialiser son arbre seule.
     *
     * @param  string|null  $attachedType  type de groupe accroché (`null` = non accrochée)
     */
    protected function autoResolvableClassTreeTemplate(?string $attachedType = 'classe'): DirectoryTemplate
    {
        return new DirectoryTemplate([
            'key' => 'classe_share_auto',
            'label' => 'Partage de classe (auto-résolvable)',
            'description' => 'La recette que la création d\'un groupe classe pourra matérialiser seule.',
            'attached_group_type' => $attachedType,
            'roles_spec' => [
                [
                    'key' => 'equipe',
                    'label' => 'Équipe enseignante',
                    'maille' => UserGroup::class,
                    'group_type' => 'classe',
                    'access' => 'rw',
                    'cardinality' => 'one',
                    'resolution' => [
                        'strategy' => 'edge_role',
                        'edge_roles' => ['manager', 'owner'],
                    ],
                ],
                [
                    'key' => 'classe',
                    'label' => 'Élèves de la classe',
                    'maille' => UserGroup::class,
                    'group_type' => 'classe',
                    'access' => 'ro',
                    'cardinality' => 'one',
                    'resolution' => ['strategy' => 'self'],
                ],
            ],
            'path_pattern' => 'Classes/Classe_{group.bare_name}',
            'nodes_spec' => [
                [
                    'path' => '_travail',
                    'label' => 'Documents de travail',
                    'nature' => 'partagee',
                    'grants' => [
                        ['role' => 'equipe', 'access' => 'rw'],
                        ['role' => 'classe', 'access' => 'ro'],
                    ],
                ],
                [
                    'path' => '_travail/devoirs',
                    'label' => 'Dépôt des devoirs',
                    'nature' => 'contenu_libre',
                    'grants' => [
                        ['role' => 'equipe', 'access' => 'rw'],
                        ['role' => 'classe', 'access' => 'ro'],
                    ],
                ],
                [
                    'path' => '_profs',
                    'label' => 'Espace des enseignants',
                    'nature' => 'partagee',
                    'grants' => [
                        ['role' => 'equipe', 'access' => 'rw'],
                    ],
                ],
                [
                    'path' => '_echange',
                    'label' => 'Espace d\'échange',
                    'nature' => 'activable',
                    'activable' => true,
                    'grants' => [
                        ['role' => 'equipe', 'access' => 'rw'],
                        ['role' => 'classe', 'access' => 'rw', 'suspendable' => true],
                    ],
                ],
                [
                    'path' => '{member.login}',
                    'label' => 'Dossier personnel',
                    'nature' => 'par_membre',
                    'edge_role' => 'member',
                    'plafond' => 2147483648,
                    'grants' => [
                        ['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'access' => 'rw'],
                        ['role' => 'equipe', 'access' => 'rw'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Contexte de résolution : un groupe au nom DÉJÀ préfixé, quatre membres
     * couvrant les trois rôles d'arête, et les cibles des rôles de la recette —
     * dont une audience qualifiée par un rôle d'arête (la forme dictée par la
     * mesure d'ouverture d'epic).
     *
     * @param  array<string,bool>  $nodeActivation
     */
    protected function classTreeContext(array $nodeActivation = []): PlanResolutionContext
    {
        return new PlanResolutionContext(
            groupId: self::GROUP_CLASSE_ID,
            groupName: 'Classe_3emeA',
            groupType: 'classe',
            members: [
                ['id' => self::USER_ALICE_ID, 'login' => 'alecoz', 'edge_role' => 'manager'],
                ['id' => self::USER_BRUNO_ID, 'login' => 'bmartin', 'edge_role' => 'member'],
                ['id' => self::USER_CAMILLE_ID, 'login' => 'cpetit', 'edge_role' => 'member'],
                ['id' => self::USER_DENIS_ID, 'login' => 'ddurand', 'edge_role' => 'owner'],
            ],
            roleTargets: [
                'equipe' => [PlanSubject::group(self::GROUP_EQUIPE_ID)],
                'classe' => [PlanSubject::group(self::GROUP_CLASSE_ID)],
                'referents' => [PlanSubject::group(self::GROUP_CLASSE_ID, 'owner')],
            ],
            nodeActivation: $nodeActivation,
        );
    }
}
