<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlanAnchor;
use App\Models\DirectoryTemplate;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\PlanGrant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Story 34.3 → 60.5 — peuplement PROD des recettes de « templates de répertoire »
 * (Q3 option B, arbitrage Henri 2026-06-30).
 *
 * Idempotent / non-destructif (iso {@see PermissionSeeder}) : `updateOrCreate`
 * sur la clé stable `key`. Un re-seed NE crée PAS de doublon et resynchronise
 * libellé/description/spec sur la baseline canonique du code (les recettes ne
 * sont pas éditables en UI, le code reste la source de vérité de la baseline).
 *
 * **5 recettes seedées** :
 *
 *  1. `direction_to_all` — direction (RW) publie, destinataires (RO) lisent.
 *  2. `profs_to_eleves`  — devoirs : les enseignants de la classe (RW) déposent,
 *                          les élèves (RO) lisent. **RECÂBLÉE en 60.5** — voir le
 *                          docblock de la recette.
 *  3. `user_to_user`     — échange bilatéral : deux utilisateurs (RW/RW).
 *  4. `group_space`      — espace commun d'un groupe (RW).
 *  5. `classe_se4`       — **story 60.5** : le partage de classe historique, dit
 *                          en vocabulaire d'ARBRE et matérialisé dans la racine
 *                          NEUVE. Seule recette d'arbre du catalogue, seule
 *                          matérialisée automatiquement à la création d'un groupe.
 *
 * INVARIANT (vérifié en test) : aucune recette ne porte de maille
 * `WorkstationGroup` — toutes les ACL portent sur `User`/`UserGroup`.
 *
 * ---------------------------------------------------------------------------
 * **Story 62.4 — LES DROITS SONT DITS EN VERBES, ET LA BASELINE EST DEVENUE
 * MAXIMALEMENT PERMISSIVE. C'est une contrepartie ASSUMÉE, pas un accident.**
 *
 * L'ancien vocabulaire binaire a été traduit selon la décision Q3 (Henri,
 * 2026-08-08) : `ro` → `lire` SEUL, `rw` → les QUATRE verbes
 * ({@see PlanGrant::VERBS}). C'est le seul mappage qui ne RETIRE d'accès à
 * personne — la doctrine de l'epic est additive, et une baseline qui aurait
 * « profité » du nouveau vocabulaire pour resserrer les droits aurait cassé, en
 * silence, des usages en place le jour du déploiement.
 *
 * Conséquence à assumer et à corriger PLUS TARD, pas ici : partout où une recette
 * disait « lecture/écriture », les audiences peuvent désormais aussi SUPPRIMER —
 * ce qu'elles pouvaient déjà, puisque c'est exactement ce que le mode d'écriture
 * historique accordait. Le raffinement (« les élèves déposent mais n'effacent
 * pas ») devient EXPRIMABLE dès maintenant, et se règlera à l'écran de la story
 * 62.6. Le faire ici, à l'aveugle, changerait le comportement d'instances en
 * place sans que personne ne l'ait demandé.
 *
 * ⚠️ Pré-déploiement VM : `php artisan db:seed --class=DirectoryTemplateSeeder`.
 */
class DirectoryTemplateSeeder extends Seeder
{
    /**
     * @return array{created: int, updated: int}
     */
    public function run(): array
    {
        $stats = ['created' => 0, 'updated' => 0];

        foreach ($this->templates() as $tpl) {
            $existing = DirectoryTemplate::where('key', $tpl['key'])->first();

            DirectoryTemplate::updateOrCreate(
                ['key' => $tpl['key']],
                [
                    'label' => $tpl['label'],
                    'description' => $tpl['description'],
                    'roles_spec' => $tpl['roles_spec'],
                    // Colonnes d'arbre et d'accrochage : TOUJOURS écrites, y
                    // compris à `null`. Les omettre pour les recettes plates
                    // laisserait une valeur périmée en base sur une instance où
                    // une recette a été modifiée à la main — un re-seed doit
                    // resynchroniser la baseline entière, pas la moitié.
                    'path_pattern' => $tpl['path_pattern'] ?? null,
                    'nodes_spec' => $tpl['nodes_spec'] ?? null,
                    'attached_group_type' => $tpl['attached_group_type'] ?? null,
                    'root_anchor' => $tpl['root_anchor'] ?? null,
                ],
            );

            $existing === null ? $stats['created']++ : $stats['updated']++;
        }

        Log::info('[DirectoryTemplateSeeder] Seed terminé', $stats);

        return $stats;
    }

    /**
     * Baseline canonique des recettes (code = source de vérité, Q3 option B).
     *
     * @return array<int, array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            [
                'key' => DirectoryTemplate::KEY_DIRECTION_TO_ALL,
                'label' => 'Direction → tous (publication descendante)',
                'description' => 'La direction (ou une équipe) DÉPOSE en lecture/écriture ; '
                    .'les groupes destinataires LISENT en lecture seule. « Tous » se '
                    .'matérialise par une sélection EXPLICITE de groupes destinataires '
                    .'(un parc ne donnerait que la visibilité, sans aucun accès réel).',
                'roles_spec' => [
                    [
                        'key' => 'source',
                        'label' => 'Source (direction / équipe qui publie)',
                        'maille' => UserGroup::class,
                        'group_type' => null,
                        'verbs' => PlanGrant::VERBS,
                        'cardinality' => 'one',
                    ],
                    [
                        'key' => 'destinataires',
                        'label' => 'Destinataires (groupes qui lisent)',
                        'maille' => UserGroup::class,
                        'group_type' => null,
                        'verbs' => [PlanGrant::VERB_LIRE],
                        'cardinality' => 'many',
                    ],
                ],
            ],
            /*
             * Story 60.5 — RECETTE RECÂBLÉE (bug « profs_to_eleves inutilisable »,
             * constaté le 2026-08-04, invisible depuis cinq semaines).
             *
             * **Ce qui n'allait pas.** Le rôle « profs » contraignait un groupe de
             * type `equipe`. Or ce type n'est plus produit : le repliement 4.13
             * fusionne `Classe_3A` / `Equipe_3A` / `PP_3A` en UNE ligne au nom nu,
             * de type `classe` — comptage sur instance réelle : 302 classes, ZÉRO
             * équipe. Le sélecteur de cible était donc vide, et MUET : la recette
             * était impossible à matérialiser sans que rien ne le dise.
             *
             * **Le correctif.** L'équipe enseignante n'est plus un groupe, c'est un
             * RÔLE SUR L'ARÊTE du groupe classe (Epic 42). La recette s'accroche
             * donc au type `classe` et résout ses deux rôles seule : le flux manuel
             * ne demande plus qu'UN groupe de matérialisation.
             *
             * **`edge_roles` liste `manager` SEUL**, même arbitrage qu'à l'arbre de
             * classe : lister aussi `owner` émettrait un second sujet — un groupe
             * d'annuaire de professeurs principaux jamais éprouvé sur instance
             * réelle — alors que le surensemble est déjà DANS l'annuaire, le groupe
             * des enseignants d'une classe contenant ses professeurs principaux.
             *
             * **Elle reste PLATE.** Aucun arbre, aucune zone dédiée : un répertoire
             * réseau ordinaire, nommé par l'administrateur, matérialisé
             * MANUELLEMENT. Son accrochage lui donne l'auto-résolution de ses
             * cibles, pas la matérialisation automatique — sans quoi chacune des
             * 302 classes naîtrait avec un partage que personne n'a demandé.
             */
            [
                'key' => DirectoryTemplate::KEY_PROFS_TO_ELEVES,
                'label' => 'Profs → élèves (distribution de devoirs)',
                'description' => 'Les enseignants de la classe DÉPOSENT en lecture/écriture ; '
                    .'les élèves de la classe LISENT en lecture seule. Choisissez la classe : '
                    .'les enseignants et les élèves en sont déduits.',
                'attached_group_type' => 'classe',
                'roles_spec' => [
                    [
                        'key' => 'profs',
                        'label' => 'Enseignants de la classe (déposent)',
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
                        'key' => 'eleves',
                        'label' => 'Élèves de la classe (lecture seule)',
                        'maille' => UserGroup::class,
                        'group_type' => 'classe',
                        'verbs' => [PlanGrant::VERB_LIRE],
                        'cardinality' => 'one',
                        'resolution' => ['strategy' => 'self'],
                    ],
                ],
            ],
            [
                'key' => DirectoryTemplate::KEY_USER_TO_USER,
                'label' => 'Utilisateur ↔ utilisateur (échange bilatéral)',
                'description' => 'Deux utilisateurs partagent un espace commun en '
                    .'lecture/écriture (collaboration directe).',
                'roles_spec' => [
                    [
                        'key' => 'user_a',
                        'label' => 'Premier utilisateur',
                        'maille' => User::class,
                        'group_type' => null,
                        'verbs' => PlanGrant::VERBS,
                        'cardinality' => 'one',
                    ],
                    [
                        'key' => 'user_b',
                        'label' => 'Second utilisateur',
                        'maille' => User::class,
                        'group_type' => null,
                        'verbs' => PlanGrant::VERBS,
                        'cardinality' => 'one',
                    ],
                ],
            ],
            [
                'key' => DirectoryTemplate::KEY_GROUP_SPACE,
                'label' => 'Groupe (espace commun)',
                'description' => 'Espace de travail commun d\'un groupe d\'utilisateurs '
                    .'(lecture/écriture pour tous les membres).',
                'roles_spec' => [
                    [
                        'key' => 'group',
                        'label' => 'Groupe d\'utilisateurs',
                        'maille' => UserGroup::class,
                        'group_type' => null,
                        'verbs' => PlanGrant::VERBS,
                        'cardinality' => 'one',
                    ],
                ],
            ],
            $this->classeSe4Recipe(),
        ];
    }

    /**
     * Story 60.5 — LA 5ᵉ RECETTE : le partage de classe historique, dit dans le
     * langage d'arbre, matérialisé dans la racine NEUVE.
     *
     * **C'est à la fois la livraison et l'épreuve.** Si ce langage sait exprimer le
     * partage de classe existant, il est assez expressif ; sinon, c'est le langage
     * qui est faux, pas la recette. La forme de l'épreuve : le diff entre l'arbre
     * historique et l'arbre neuf, pour une même classe, doit être exactement
     * l'ensemble documenté d'écarts attendus, et rien d'autre
     * ({@see \Tests\Unit\Services\Filesystem\Backend\Posix\ClassTreeComparisonTest}).
     *
     * **SIX nœuds**, et chacun a sa raison :
     *  - la RACINE : traversée seule, personne n'y écrit — c'est un couloir, pas un
     *    espace de dépôt ;
     *  - `_travail` : les enseignants déposent, les élèves lisent ;
     *  - `_travail/devoirs` : MÊMES octrois que `_travail` — les élèves y sont en
     *    LECTURE. Ce n'est PAS une boîte de dépôt de copies : la collecte des
     *    travaux rendus n'est pas livrée, et l'écrire en dépôt serait une
     *    régression fonctionnelle déguisée en amélioration. La nature « contenu
     *    libre » accueille ce futur atelier sans rien en promettre ;
     *  - `_profs` : l'espace privé des enseignants. La classe n'y reçoit AUCUN
     *    octroi — sa clôture est CALCULÉE, jamais un refus explicite ;
     *  - `_echange` : actif à la création, suspendable. Suspendre vide l'octroi des
     *    élèves ; le dossier et les données restent ;
     *  - le dossier PERSONNEL de chaque élève, avec son octroi nominatif.
     *
     * **La zone est `classes`** : la racine NEUVE, hors de l'espace exposé en SMB
     * et hors de l'arbre historique, auquel SE5 n'écrit jamais un octet.
     *
     * **Le préfixe `Classe_` est CONSERVÉ** dans le motif de chemin. Il ne sert
     * plus à distinguer quoi que ce soit — la zone le fait — mais il rend le diff
     * des deux arbres lisible nom pour nom, et la migration bon marché le jour où
     * elle se décidera.
     *
     * **D4, tenue ici sans nouvelle fabrique.** La mesure d'ouverture d'epic
     * recommandait un « groupe dérivé » comme artefact compilé d'une audience.
     * Cet artefact EXISTE DÉJÀ : le trio d'annuaire entretenu par la
     * synchronisation des groupes est exactement cela. La recette n'invente donc
     * aucun groupe ; elle nomme des audiences, et la projection les fait
     * correspondre au trio en place.
     *
     * @return array<string, mixed>
     */
    private function classeSe4Recipe(): array
    {
        return [
            'key' => DirectoryTemplate::KEY_CLASSE_SE4,
            'label' => 'Classe (arbre de partage)',
            'description' => 'L\'arbre de partage d\'une classe : documents de travail, devoirs '
                .'distribués, espace privé des enseignants, espace d\'échange activable et un '
                .'dossier personnel par élève. Matérialisé automatiquement à la création d\'une '
                .'classe, dans la racine dédiée aux arbres de classe.',
            'attached_group_type' => 'classe',
            'root_anchor' => PlanAnchor::Classes->value,
            'path_pattern' => 'Classe_{group.bare_name}',
            'roles_spec' => [
                [
                    'key' => 'equipe',
                    'label' => 'Équipe enseignante',
                    'maille' => UserGroup::class,
                    'group_type' => 'classe',
                    'verbs' => PlanGrant::VERBS,
                    'cardinality' => 'one',
                    // `manager` SEUL, et c'est un arbitrage, pas un oubli. Ajouter
                    // `owner` émettrait un SECOND sujet d'audience — donc une
                    // entrée que l'arbre historique n'a pas, c'est-à-dire un écart
                    // que personne n'a documenté. Et le surensemble est déjà dans
                    // l'annuaire : le groupe des enseignants d'une classe contient
                    // ses professeurs principaux.
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
            ],
            'nodes_spec' => [
                [
                    'path' => '.',
                    'label' => 'Racine du partage de classe',
                    'nature' => 'partagee',
                    // Traversée SEULE des deux audiences : personne n'écrit à la
                    // racine. Aucun rôle n'est en clôture ici — les deux ont reçu
                    // quelque chose.
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
                    // Les élèves y sont en LECTURE. Ce dossier distribue les
                    // sujets ; il ne collecte pas les copies.
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
            ],
        ];
    }
}
