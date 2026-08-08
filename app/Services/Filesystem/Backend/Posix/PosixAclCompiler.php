<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendOutcome;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;

/**
 * Story 60.4 — la COMPILATION d'un nœud de plan en entrées de liste d'accès
 * POSIX.
 *
 * C'est le cœur de la descente, et c'est aussi le code qui ne devait surtout pas
 * être réécrit : le jeu canonique de base, l'ordre des entrées d'accès et de leurs
 * miroirs d'héritage, l'échappement du groupe d'annuaire à espace viennent tels
 * quels du provisionnement 34.1. Un référentiel figé en littéraux, capturé sur le
 * comportement AVANT la descente, verrouille l'ensemble chaîne par chaîne
 * ({@see \Tests\Unit\Services\Filesystem\Backend\Posix\PosixGoldenAclTest}).
 *
 * ---------------------------------------------------------------------------
 * **Story 62.4 — LES OCTROIS SE DISENT EN QUATRE VERBES, ET LA TRADUCTION EST
 * DÉRIVÉE, PAS ÉNUMÉRÉE.**
 *
 * La matrice complète — deux axes, un drapeau de nœud, une règle unique de
 * dégradation — est écrite au docblock de {@see PosixVerbRendering}, et testée
 * branche par branche sur les quinze combinaisons. Trois choses seulement
 * appartiennent à CETTE classe :
 *
 *  1. **La décision de nœud.** La restriction de suppression au propriétaire est
 *     un attribut du DOSSIER, pas d'une entrée : c'est ici, en regardant TOUS les
 *     octrois actifs, qu'on décide de la poser ou non. Elle n'est posée que si un
 *     octroi demande « déposer sans effacer » ET qu'aucun autre octroi actif ne
 *     porte la suppression — sinon elle retirerait à celui-là l'effacement des
 *     fichiers d'autrui, c'est-à-dire un droit écrit dans la recette.
 *  2. **La déclaration.** Tout verbe non rendu produit un refus
 *     {@see FileBackendOutcome::NonExprimable} NON BLOQUANT : les autres octrois
 *     s'écrivent, l'octroi partiel s'écrit à son intersection exprimable, et le
 *     nœud REMONTE ce qui manque, en français, sans un mot du mécanisme.
 *  3. **La liste des fichiers, quand elle diffère.** Si dossiers et fichiers
 *     exigent des niveaux différents, la compilation rend DEUX listes ; c'est
 *     l'exécution qui les pose séparément. Un niveau unique approximé aurait donné
 *     un verbe de trop d'un côté ou de l'autre, en silence.
 *
 * **L'iso-sortie est intacte, et c'est la preuve du mappage de migration.** Les
 * deux seules combinaisons que portent les recettes en base après la migration —
 * `lire` seul, et les quatre verbes — compilent vers exactement les deux entrées
 * d'hier. Aucune des deux ne demande la restriction de suppression, aucune des deux
 * ne différencie fichiers et dossiers : sur une instance en place, aucune entrée ne
 * bouge, aucun mode ne bouge, et les référentiels figés le vérifient chaîne par
 * chaîne sans qu'un seul de leurs littéraux ait changé.
 *
 * **Trois états d'octroi, trois traductions** — la distinction que la story 60.1
 * a passé un critère entier à établir, et qu'il aurait été facile d'écraser ici :
 *  - octroi ACTIF → une (ou deux) entrées au niveau que les verbes dérivent ;
 *  - octroi SUSPENDU → une entrée EXPLICITEMENT VIDE (`---`). L'octroi existe, il
 *    ne donne rien, le dossier et les données restent. C'est la forme matérialisée
 *    de la suspension, et c'est ce qui permet à la comparaison désiré/observé de
 *    ne pas confondre « suspendu » avec « supprimé » ;
 *  - rôle en CLÔTURE → **aucune entrée d'octroi**. Il n'y a pas de refus en POSIX :
 *    l'absence d'entrée EST la fermeture du CONTENU — ni listage, ni lecture, ni
 *    mutation. Le nœud n'écrit donc aucun geste pour sa clôture, et la comparaison
 *    ne lui réclame rien. C'est un backend à propagation qui devra la matérialiser.
 *
 * **Story 62.5 — cette dernière phrase disait « → RIEN », et c'est devenu FAUX.**
 * Un rôle en clôture d'un ancêtre PEUT y recevoir une entrée : le COULOIR dérivé,
 * quand un nœud plus profond lui accorde quelque chose
 * ({@see PosixTraversalPlanner}). Ce n'est pas une brèche dans la clôture, et il
 * faut le dire précisément plutôt que de laisser l'ancienne phrase rassurer à tort :
 * le couloir permet de PASSER DEVANT LA PORTE, il ne rouvre pas la pièce. Le rôle
 * ne peut toujours ni lister l'ancêtre, ni y lire, ni y écrire — il peut seulement
 * atteindre le dossier profond qui, lui, lui a été accordé noir sur blanc. Un rôle
 * en clôture dont AUCUN descendant ne lui accorde rien continue, lui, de n'avoir
 * strictement aucune entrée.
 *
 * **Le parc ne contribue rien**, et il n'a même pas à être exclu ici : le plan ne
 * porte aucun octroi pour lui (invariant du modèle à deux axes, tenu en amont par
 * le projecteur de plan).
 *
 * **PUR au sens des entrées/sorties** : cette classe n'exécute rien. La seule
 * chose qu'elle appelle et qui touche au système est la traduction des sujets,
 * qui doit interroger l'annuaire pour ne jamais inventer un nom.
 */
final class PosixAclCompiler
{
    /**
     * GARDE-FOU D'ÉCHELLE — nombre maximal d'entrées NOMINATIVES sur un même
     * nœud.
     *
     * Ce n'est pas un réglage, c'est la lecture directe d'une mesure faite sur
     * une arborescence de 631 fichiers : la pose récursive coûte 0,026 s à 30
     * entrées nominatives, 0,32 s à 200, **7,16 s à 1 000, 63,07 s à 3 000**, et
     * bute sur une limite dure du système autour de 5 457. Le coût est
     * QUADRATIQUE — chaque entrée réécrit l'attribut étendu entier sur chaque
     * fichier. 200 est le dernier point sous le dixième de seconde ; au-delà, la
     * voie légitime n'est pas d'attendre, c'est le groupe dérivé.
     *
     * Un nœud qui dépasserait n'écrit RIEN et le dit avec ses chiffres — plutôt
     * qu'une minute de pose silencieuse dont personne ne comprendrait la cause.
     *
     * **Les nœuds par membre ne sont pas concernés par construction** : ils
     * portent UNE entrée nominative chacun, jamais une audience entière.
     */
    public const NOMINATIVE_ENTRIES_CEILING = 200;

    /**
     * Jeu canonique de base, identique pour tout nœud géré. Le groupe
     * d'administration garde la main, « les autres » n'ont rien, et chaque entrée
     * a son miroir d'héritage pour que le contenu créé ensuite hérite du même
     * contrat.
     *
     * @var list<string>
     */
    public const BASE_ACLS = [
        'user::rwx',
        'group::---',
        'group:domain\\040admins:rwx',
        'mask::rwx',
        'other::---',
        'default:user::rwx',
        'default:group::---',
        'default:group:domain\\040admins:rwx',
        'default:mask::rwx',
        'default:other::---',
    ];

    /**
     * Le jeu de base RESTREINT AUX ENTRÉES D'ACCÈS — les mêmes, sans les miroirs
     * d'héritage.
     *
     * Il ne sert QUE dans le cas où fichiers et dossiers reçoivent des niveaux
     * différents : les miroirs d'héritage n'existent que sur un dossier, et les
     * poser sur un fichier est refusé par le mécanisme lui-même. Dérivé de
     * {@see BASE_ACLS}, jamais recopié — deux listes qui divergeraient donneraient
     * deux contrats structurels selon le type d'objet.
     *
     * @return list<string>
     */
    public static function baseFileAcls(): array
    {
        return array_values(array_filter(
            self::BASE_ACLS,
            static fn (string $acl): bool => ! str_starts_with($acl, 'default:'),
        ));
    }

    /** Niveau d'entrée d'un octroi SUSPENDU : présent, et vide. */
    private const MODE_SUSPENDED = '---';

    /**
     * Story 62.5 — niveau d'un COULOIR d'accès dérivé : la traversée SEULE.
     *
     * Ni lecture, ni écriture : pénétrer le dossier, jamais le lister ni en ouvrir
     * le contenu. C'est la totalité de ce que la dérivation accorde, et le fait
     * qu'aucune autre lettre n'apparaisse ici est la garantie « rien de plus ».
     */
    private const MODE_TRAVERSAL = '--x';

    public function __construct(private readonly PosixSubjectProjector $projector)
    {
    }

    /**
     * @param  list<PosixTraversal>  $traversals  couloirs dérivés à ouvrir sur ce nœud
     *                                            ({@see PosixTraversalPlanner}). Le
     *                                            paramètre est OPTIONNEL : un appelant
     *                                            qui compile un nœud isolé — les
     *                                            référentiels figés le font — n'a pas
     *                                            de plan sous la main et ne doit pas
     *                                            être forcé d'en inventer un.
     */
    public function compile(PlanNode $node, array $traversals = []): CompiledNodeAcl
    {
        $acls = self::BASE_ACLS;
        $fileAcls = self::baseFileAcls();
        $refusals = [];
        $nominative = 0;
        $differentiated = false;

        // La restriction de suppression au propriétaire se décide UNE fois, pour
        // tout le nœud : c'est un attribut du dossier, jamais d'une entrée.
        $restriction = self::restrictsDeletion($node);

        foreach ($node->grants as $grant) {
            $projection = $this->projector->project($grant->subject);

            if (! $projection->isResolved()) {
                $refusal = new CompiledRefusal(
                    $projection->refusal ?? FileBackendOutcome::Echec,
                    (string) $projection->detail,
                    blocking: $projection->blocking,
                );

                // Un refus BLOQUANT arrête la compilation du nœud sur-le-champ :
                // rendre la liste partielle laisserait au backend une ACL qui a
                // l'air complète, et la pose commence par une purge.
                if ($refusal->blocking) {
                    return new CompiledNodeAcl([], [$refusal]);
                }

                $refusals[] = $refusal;

                continue;
            }

            // Un octroi SUSPENDU se rend par une entrée explicitement vide, quels
            // que soient ses verbes : la suspension est ORTHOGONALE au niveau.
            if (! $grant->isActive()) {
                if ($projection->type === PosixSubjectProjection::TYPE_USER) {
                    $nominative++;
                }
                $acls[] = "{$projection->type}:{$projection->name}:" . self::MODE_SUSPENDED;
                $acls[] = "default:{$projection->type}:{$projection->name}:" . self::MODE_SUSPENDED;
                $fileAcls[] = "{$projection->type}:{$projection->name}:" . self::MODE_SUSPENDED;

                continue;
            }

            $rendering = PosixVerbRendering::of($grant->verbs, $restriction);

            if (! $rendering->isExact()) {
                $refusals[] = new CompiledRefusal(
                    FileBackendOutcome::NonExprimable,
                    $this->declineDetail($grant, $rendering, $restriction),
                );
            }

            // Rien de rendu : aucune entrée. Une entrée vide serait relue comme une
            // suspension appliquée — le silence exact que cet epic supprime.
            if ($rendering->isEmpty()) {
                continue;
            }

            if ($projection->type === PosixSubjectProjection::TYPE_USER) {
                $nominative++;
            }

            $differentiated = $differentiated || $rendering->isDifferentiated();

            $acls[] = "{$projection->type}:{$projection->name}:{$rendering->directoryMode}";
            $acls[] = "default:{$projection->type}:{$projection->name}:{$rendering->directoryMode}";
            $fileAcls[] = "{$projection->type}:{$projection->name}:{$rendering->fileMode}";
        }

        // ---------------------------------------------------------------------
        // Story 62.5 — LES COULOIRS DÉRIVÉS, et les trois choses qu'ils NE font pas.
        //
        //  1. Ils n'entrent pas dans `$acls` : leur pose est NON RÉCURSIVE, sur le
        //     répertoire de tête seul. Les mélanger aux autres les ferait descendre
        //     dans tout le contenu libre de l'ancêtre — plus que le couloir, en
        //     silence.
        //  2. Ils n'ont AUCUN miroir d'héritage. Tout le reste de cette méthode pose
        //     accès et héritage par paire ; l'asymétrie est ici VOULUE — un miroir
        //     donnerait la traversée à tout enfant FUTUR de l'ancêtre.
        //  3. Ils n'ont AUCUNE entrée de fichier. La traversée d'un fichier n'existe
        //     pas : l'écrire poserait le bit d'exécution sur des documents, un droit
        //     que personne n'a demandé.
        $traversalAcls = [];
        foreach ($traversals as $traversal) {
            $projection = $this->projector->project($traversal->subject);

            if (! $projection->isResolved()) {
                $refusal = new CompiledRefusal(
                    $projection->refusal ?? FileBackendOutcome::Echec,
                    $this->traversalDetail($traversal, (string) $projection->detail),
                    blocking: $projection->blocking,
                );

                if ($refusal->blocking) {
                    return new CompiledNodeAcl([], [$refusal]);
                }

                $refusals[] = $refusal;

                continue;
            }

            // Une traversée nominative compte comme les autres : la limite dure du
            // système porte sur le NOMBRE D'ENTRÉES d'un objet, et le répertoire de
            // tête en est un.
            if ($projection->type === PosixSubjectProjection::TYPE_USER) {
                $nominative++;
            }

            $traversalAcls[] = "{$projection->type}:{$projection->name}:" . self::MODE_TRAVERSAL;
        }

        if ($nominative > self::NOMINATIVE_ENTRIES_CEILING) {
            return new CompiledNodeAcl([], [new CompiledRefusal(
                FileBackendOutcome::Echec,
                sprintf(
                    'ce nœud produirait %d entrées nominatives, au-delà du plafond de %d : rien n\'a été '
                    . 'écrit. Le coût de la pose est quadratique (mesuré : %d entrées ≈ 7 s, %d ≈ 63 s) et '
                    . 'le système la refuse au-delà de %d environ. La voie prévue pour une audience de cette '
                    . 'taille est un groupe dérivé, pas une énumération des personnes.',
                    $nominative,
                    self::NOMINATIVE_ENTRIES_CEILING,
                    1000,
                    3000,
                    5457,
                ),
                blocking: true,
            )]);
        }

        return new CompiledNodeAcl(
            $acls,
            $refusals,
            // La liste des fichiers ne voyage QUE si elle diffère : la rendre
            // systématiquement doublerait les gestes de pose de tous les nœuds du
            // dépôt pour n'y rien changer.
            $differentiated ? $fileAcls : [],
            $restriction,
            $traversalAcls,
        );
    }

    /**
     * La phrase d'un couloir qui n'a pas pu être ouvert, en VOCABULAIRE DE PLAN.
     *
     * Elle nomme le rôle et le nœud PROFOND qu'on voulait rendre atteignable, puis
     * recopie la cause telle que la traduction des sujets l'a formulée. Sans le nœud
     * profond, l'administrateur lirait « quelque chose a échoué sur cet ancêtre »
     * sans jamais savoir ce qui, plus bas, en dépendait.
     */
    private function traversalDetail(PosixTraversal $traversal, string $cause): string
    {
        return sprintf(
            'le couloir d\'accès vers %s (rôle%s %s) n\'a pas pu être ouvert sur ce dossier : %s',
            implode(', ', array_map(static fn (string $p): string => '« ' . $p . ' »', $traversal->nodePaths)),
            count($traversal->roleKeys) > 1 ? 's' : '',
            implode(', ', array_map(static fn (string $r): string => '« ' . $r . ' »', $traversal->roleKeys)),
            $cause,
        );
    }

    /**
     * Le nœud doit-il porter la restriction de suppression au propriétaire ?
     *
     * **Deux conditions, et la seconde est celle qu'on oublie.** Il faut qu'un
     * octroi actif demande « déposer sans effacer » — sinon la restriction ne rend
     * rien de plus — ET qu'AUCUN octroi actif du nœud ne porte la suppression. Sur
     * un nœud MIXTE, la poser retirerait aux porteurs de la suppression
     * l'effacement des fichiers d'autrui : une régression SILENCIEUSE, sur un droit
     * que la recette a écrit noir sur blanc. On ne la pose donc pas, et l'octroi
     * « déposer sans effacer » retombe sur son intersection exprimable, en le
     * disant.
     *
     * **Story 62.5 — PUBLIQUE et STATIQUE, pour ne pas en avoir deux.** Le
     * planificateur de traversée doit savoir, d'un nœud PROFOND, si ses octrois y
     * rendent quelque chose — donc rejouer exactement cette décision. La recopier
     * chez lui aurait créé une seconde autorité sur une règle dont toute la
     * subtilité tient à une condition qu'on oublie ; elle est donc appelée, jamais
     * dupliquée. La méthode ne lit que son argument : la rendre statique n'ouvre
     * rien.
     */
    public static function restrictsDeletion(PlanNode $node): bool
    {
        $wanted = false;

        foreach ($node->grants as $grant) {
            if (! $grant->isActive()) {
                continue;
            }
            if ($grant->hasVerb(PlanGrant::VERB_SUPPRIMER)) {
                return false;
            }
            if ($grant->hasVerb(PlanGrant::VERB_CREER)) {
                $wanted = true;
            }
        }

        return $wanted;
    }

    /**
     * La phrase d'un déclin, en VOCABULAIRE DE PLAN.
     *
     * Elle nomme le rôle et les verbes, jamais le mécanisme : ni mode, ni bit, ni
     * nom de la restriction. Le `detail` d'un rapport traverse la ligne de coupe et
     * s'affiche à un administrateur — il doit lui dire ce qui ne sera pas rendu et
     * pourquoi, pas comment le serveur de fichiers est fait.
     */
    private function declineDetail(PlanGrant $grant, PosixVerbRendering $rendering, bool $restriction): string
    {
        $missing = implode(', ', $rendering->missing);

        if (in_array(PlanGrant::VERB_SUPPRIMER, $rendering->missing, true)
            && ! $grant->hasVerb(PlanGrant::VERB_CREER)) {
            return sprintf(
                'la suppression sans la création ne peut pas être rendue par ce serveur de fichiers pour le '
                . 'rôle « %s » : les deux verbes y passent par le même levier, et l\'accorder donnerait aussi '
                . 'la création — un verbe que la recette n\'écrit pas. Le reste de l\'octroi (%s) est rendu.',
                $grant->roleKey,
                $rendering->rendered === [] ? 'rien' : implode(', ', $rendering->rendered),
            );
        }

        if (in_array(PlanGrant::VERB_CREER, $rendering->missing, true) && ! $restriction) {
            return sprintf(
                'la création sans la suppression ne peut pas être rendue pour le rôle « %s » sur ce dossier : '
                . 'un autre octroi actif y accorde la suppression, et la restriction qui approcherait la '
                . 'nuance lui retirerait l\'effacement du travail des autres — un verbe que la recette lui '
                . 'écrit. Le reste de l\'octroi (%s) est rendu.',
                $grant->roleKey,
                $rendering->rendered === [] ? 'rien' : implode(', ', $rendering->rendered),
            );
        }

        return sprintf(
            'le rôle « %s » demande %s, que ce serveur de fichiers ne sait pas rendre séparément du reste. '
            . 'Le reste de l\'octroi (%s) est rendu.',
            $grant->roleKey,
            $missing,
            $rendering->rendered === [] ? 'rien' : implode(', ', $rendering->rendered),
        );
    }
}
