<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Plan;

use App\Enums\PlanAnchor;
use App\Models\DirectoryTemplate;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanResolutionContext;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 60.1 — LE cas d'épreuve : le partage classe, dit en vocabulaire de plan.
 *
 * Cette recette n'est PAS seedée (aucune recette n'est modifiée par la story
 * 60.1) : elle existe uniquement pour prouver que le langage est assez expressif
 * pour porter le partage classe historique — c'est ce qui débloquera la story
 * **Story 62.4 — les PARAMÈTRES de cette fixture disent désormais des VERBES**
 * (`'verbs' => PlanGrant::VERBS` là où elle écrivait `'access' => 'rw'`,
 * `[PlanGrant::VERB_LIRE]` là où elle écrivait `'ro'`). C'est le mappage de
 * migration, appliqué à une recette de test comme il l'a été aux recettes en base.
 * Rien d'ATTENDU n'a bougé nulle part : les référentiels figés qui consomment cette
 * fixture comparent des chaînes de sortie, et elles sont identiques au caractère
 * près — c'est précisément ce qui fait de leur immobilité la preuve que la
 * traduction est juste.
 *
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
                    'verbs' => PlanGrant::VERBS,
                    'cardinality' => 'one',
                ],
                [
                    'key' => 'classe',
                    'label' => 'Élèves de la classe',
                    'maille' => UserGroup::class,
                    'group_type' => 'classe',
                    'verbs' => [PlanGrant::VERB_LIRE],
                    'cardinality' => 'one',
                ],
                [
                    'key' => 'referents',
                    'label' => 'Enseignants référents',
                    'maille' => UserGroup::class,
                    'group_type' => 'classe',
                    'verbs' => PlanGrant::VERBS,
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
                        ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
                        ['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]],
                    ],
                ],
                [
                    'path' => '_travail/devoirs',
                    'label' => 'Dépôt des devoirs',
                    'nature' => 'contenu_libre',
                    'grants' => [
                        ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
                        ['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]],
                    ],
                ],
                [
                    // LE nœud de l'AC9 : la classe n'a AUCUN octroi ici.
                    'path' => '_profs',
                    'label' => 'Espace des enseignants',
                    'nature' => 'partagee',
                    'grants' => [
                        ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
                        ['role' => 'referents', 'verbs' => PlanGrant::VERBS],
                    ],
                ],
                [
                    // LE nœud de l'AC3 : suspendre n'est pas supprimer.
                    'path' => '_echange',
                    'label' => 'Espace d\'échange',
                    'nature' => 'activable',
                    'activable' => true,
                    'grants' => [
                        ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
                        ['role' => 'classe', 'verbs' => PlanGrant::VERBS, 'suspendable' => true],
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
                        ['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'verbs' => PlanGrant::VERBS],
                        ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
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
     * Story 60.2 → 60.5 — LA MÊME recette, rendue AUTO-RÉSOLVABLE et accrochée au
     * type `classe`.
     *
     * **C'est le SEED, écrit en décor.** Un test d'équivalence
     * ({@see \Tests\Unit\Database\Seeders\ClassTreeRecipeEquivalenceTest}) épingle
     * que ce décor et la 5ᵉ recette seedée disent exactement la même chose. Deux
     * descriptions du partage de classe qui divergeraient rendraient tous les tests
     * qui s'appuient sur ce décor faussement rassurants.
     *
     * **Trois corrections apportées par la story 60.5** — chacune était un piège
     * nommé :
     *
     *  1. **La RACINE existe.** Le décor 60.2 n'avait pas de nœud « . » : la racine
     *     n'avait donc aucun octroi exprimé, alors que la racine historique porte
     *     la traversée de l'équipe ET de la classe. Sans elle, la comparaison des
     *     deux arbres était tout simplement infaisable.
     *  2. **`edge_roles` liste `manager` SEUL.** « L'équipe = gestionnaires ∪
     *     propriétaires » semble plus juste ; c'est faux deux fois. Cela émettrait
     *     DEUX sujets, donc une entrée d'audience supplémentaire que l'arbre
     *     historique n'a pas — un écart non documenté, c'est-à-dire un échec. Et le
     *     surensemble est déjà DANS l'annuaire : le groupe des enseignants d'une
     *     classe contient ses professeurs principaux. Un seul rôle d'arête suffit
     *     donc à dire exactement ce que l'existant dit.
     *  3. **Le motif de chemin est RELATIF À SA ZONE.** Il ne porte plus de segment
     *     de tête : la zone est portée par l'ancre logique du plan, et la racine
     *     d'un plan est UN segment — la garde de chemin refuse le reste.
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
            'root_anchor' => PlanAnchor::Classes->value,
            'roles_spec' => self::CLASS_TREE_ROLES,
            'path_pattern' => self::CLASS_TREE_PATH_PATTERN,
            'nodes_spec' => self::CLASS_TREE_NODES,
        ]);
    }

    /**
     * Motif de chemin de l'arbre de classe, RELATIF à sa zone : le préfixe
     * `Classe_` est CONSERVÉ (symétrie du diff avec l'arbre historique, et
     * migration bon marché le jour venu).
     */
    public const CLASS_TREE_PATH_PATTERN = 'Classe_{group.bare_name}';

    /**
     * Les DEUX rôles de l'arbre de classe et leurs stratégies.
     *
     * @var array<int, array<string, mixed>>
     */
    public const CLASS_TREE_ROLES = [
        [
            'key' => 'equipe',
            'label' => 'Équipe enseignante',
            'maille' => UserGroup::class,
            'group_type' => 'classe',
            'verbs' => PlanGrant::VERBS,
            'cardinality' => 'one',
            'resolution' => [
                'strategy' => 'edge_role',
                'edge_roles' => ['manager'],
            ],
        ],
        [
            'key' => 'classe',
            'label' => 'Élèves de la classe',
            'maille' => UserGroup::class,
            'group_type' => 'classe',
            'verbs' => [PlanGrant::VERB_LIRE],
            'cardinality' => 'one',
            'resolution' => ['strategy' => 'self'],
        ],
    ];

    /**
     * Les SIX nœuds de l'arbre de classe.
     *
     * @var array<int, array<string, mixed>>
     */
    public const CLASS_TREE_NODES = [
        [
            'path' => '.',
            'label' => 'Racine du partage de classe',
            'nature' => 'partagee',
            'grants' => [
                ['role' => 'equipe', 'verbs' => [PlanGrant::VERB_LIRE]],
                ['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]],
            ],
        ],
        [
            'path' => '_travail',
            'label' => 'Documents de travail',
            'nature' => 'partagee',
            'grants' => [
                ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
                ['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]],
            ],
        ],
        [
            'path' => '_travail/devoirs',
            'label' => 'Devoirs distribués aux élèves',
            'nature' => 'contenu_libre',
            'grants' => [
                ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
                ['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]],
            ],
        ],
        [
            'path' => '_profs',
            'label' => 'Espace des enseignants',
            'nature' => 'partagee',
            'grants' => [
                ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
            ],
        ],
        [
            'path' => '_echange',
            'label' => 'Espace d\'échange',
            'nature' => 'activable',
            'activable' => true,
            'grants' => [
                ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
                ['role' => 'classe', 'verbs' => PlanGrant::VERBS, 'suspendable' => true],
            ],
        ],
        [
            'path' => '{member.login}',
            'label' => 'Dossier personnel de l\'élève',
            'nature' => 'par_membre',
            'edge_role' => 'member',
            'grants' => [
                ['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'verbs' => PlanGrant::VERBS],
                ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
            ],
        ],
    ];

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
