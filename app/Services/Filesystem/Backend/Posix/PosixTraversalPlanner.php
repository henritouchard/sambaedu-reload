<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Enums\PlanNodeNature;
use App\Models\DirectoryTemplate;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 62.5 — LA TRAVERSÉE SE CALCULE, ET ELLE SE CALCULE **ICI**, SOUS LA LIGNE
 * DE CONTRAT.
 *
 * ---------------------------------------------------------------------------
 * **LA DÉCISION, ET SES QUATRE RAISONS.**
 *
 * L'epic laissait le choix : dériver la traversée dans le RÉSOLVEUR (en vocabulaire
 * neutre, sous forme d'octrois supplémentaires posés sur les ancêtres) ou dans le
 * BACKEND (en entrées d'accès, invisibles du plan). Le choix est tranché : **c'est
 * un savoir de backend**, et il ne remonte jamais au-dessus de la ligne. Aucun
 * fichier du namespace du plan, aucune ligne de sa sérialisation, aucune ligne du
 * comparateur d'état ne bouge à cause de cette classe.
 *
 *  1. **La traversée n'est pas une intention, c'est un mécanisme.** Le vocabulaire
 *     du plan est FERMÉ à quatre verbes depuis la story 62.4, et « traverser » n'en
 *     est pas un cinquième : il n'exprime aucune volonté d'administrateur, il
 *     constate ce qu'un chemin exige pour être parcouru. Un octroi de plan que
 *     personne n'a écrit ferait MENTIR le plan, l'aperçu de la story 62.6 et
 *     l'encart de dérive — trois surfaces qui promettent de ne montrer QUE ce qui a
 *     été saisi.
 *  2. **La clôture de la story 60.1 serait corrompue.** Elle est « influençable
 *     UNIQUEMENT en écrivant ou en retirant un octroi », et un test l'épingle. Un
 *     octroi de traversée injecté par le résolveur sortirait mécaniquement des rôles
 *     de la clôture de chaque ancêtre — exactement ce que ce test interdit, et pour
 *     une raison qui n'a rien de formel : la clôture est ce qui dit à un backend à
 *     propagation sur QUI refermer.
 *  3. **Sur un backend à propagation, une traversée dans le plan serait une
 *     FUITE.** Le sondage d'ouverture d'epic l'a MESURÉ contre une instance réelle :
 *     un partage posé sur un ANCÊTRE descend sur tout le sous-arbre. Un « octroi de
 *     traversée » exprimé dans le plan s'y matérialiserait donc en accès RÉEL
 *     propagé à tout ce qui est dessous — l'exact inverse de « la traversée
 *     n'accorde rien de plus ».
 *  4. **Le coût du choix est nul.** Ce serveur de fichiers est le seul qui ait
 *     besoin d'un couloir : les plans de fichiers distants de l'Epic 61 donnent
 *     l'accès DIRECT au dossier partagé, sans exiger quoi que ce soit de ses
 *     ancêtres. L'objection « chaque backend devrait la refaire » décrit donc un
 *     coût que personne ne paiera.
 *
 * ---------------------------------------------------------------------------
 * **LA RÈGLE, ET CE QU'ELLE REFUSE DE FAIRE.**
 *
 * Pour un nœud N, les sujets de traversée sont ceux des octrois **actifs et
 * RENDUS** portés par les descendants STRICTS déclarés de N, moins ceux qui ont
 * déjà une entrée à eux sur N. Quatre exclusions, et chacune répare un sur-octroi
 * ou un silence :
 *
 *  - **« RENDUS », pas « écrits ».** Un octroi dont la matrice de dégradation
 *    ({@see PosixVerbRendering}) ne rend rien du tout n'écrit aucune entrée sur son
 *    propre nœud. Lui dériver un couloir ouvrirait un passage vers RIEN, et
 *    sèmerait une entrée orpheline que personne ne saurait relier à quoi que ce
 *    soit.
 *  - **Une SUSPENSION n'est jamais percée.** Si le sujet porte déjà une entrée sur
 *    l'ancêtre — fût-elle explicitement vide, c'est-à-dire suspendue — on ne dérive
 *    RIEN. Un sujet n'a qu'une entrée par répertoire : écrire le couloir écraserait
 *    la forme matérialisée de la suspension, et la relecture dirait « suspension non
 *    appliquée ». Fermer le couloir ferme les pièces, et c'est le sens même d'une
 *    suspension : quand l'espace d'échange est fermé, ce qui est dessous l'est
 *    aussi.
 *  - **Un octroi profond SUSPENDU ne dérive rien non plus.** Il ne donne rien
 *    aujourd'hui ; lui ouvrir un couloir donnerait un passage vers un dossier
 *    volontairement vidé.
 *  - **L'octroi du MEMBRE ÉNUMÉRÉ ne dérive JAMAIS** (jeton réservé
 *    {@see DirectoryTemplate::TREE_ROLE_MEMBER} côté recette, sujet nominatif d'un
 *    nœud par membre côté mécanisme — voir {@see isEnumeratedMemberGrant()} pour
 *    lequel des deux critères attrape quoi). Dériver ces octrois poserait UNE
 *    ENTRÉE PAR MEMBRE sur chaque ancêtre partagé : la racine d'une classe de 250
 *    élèves porterait 250 entrées nominatives là où l'arbre historique n'en porte
 *    aucune — le référentiel figé changerait, et le garde-fou d'échelle
 *    ({@see PosixAclCompiler::NOMINATIVE_ENTRIES_CEILING}) serait percuté à terme.
 *    L'atteignabilité des membres est garantie AUTREMENT, et STATIQUEMENT : la
 *    validation de recette exige qu'un octroi d'audience couvrant les membres du
 *    rôle d'arête existe sur chaque ancêtre déclaré
 *    ({@see DirectoryTemplate::assertValidTreeSpec()}). Une garantie de validation
 *    plutôt qu'une garantie de pose : elle refuse la recette au lieu de la rattraper
 *    en écrivant 250 lignes.
 *
 * **Et surtout : le couloir n'accorde RIEN DE PLUS.** L'entrée dérivée est la
 * traversée SEULE — passer devant la porte, jamais entrer, jamais lire. Un rôle qui
 * lit `a/b/c` obtient de quoi traverser `a` et `a/b`, et ne peut ni les lister, ni y
 * lire un fichier, ni y créer, éditer ou supprimer quoi que ce soit. C'est la
 * propriété centrale de cette story, et elle est testée en toutes lettres.
 *
 * ---------------------------------------------------------------------------
 * **UNE SEULE VÉRITÉ, TROIS CONSOMMATEURS.** La pose, la relecture et le contrôle
 * d'impact sur le seed appellent tous CETTE méthode. Deux calculs qui divergeraient
 * donneraient soit une idempotence rompue (repose à chaque passage), soit une dérive
 * invisible — les deux défauts que l'epic paie le plus cher.
 *
 * **PUR** : le plan entre, des sujets sortent. Aucune projection vers un nom
 * système, aucun processus, aucune requête. La traduction des sujets appartient au
 * compilateur, au moment d'écrire.
 */
final class PosixTraversalPlanner
{
    /**
     * Les couloirs à ouvrir sur ce nœud, triés par clé de sujet (déterministe).
     *
     * @return list<PosixTraversal>
     */
    public function forNode(FilePlan $plan, PlanNode $node): array
    {
        // Un sujet qui a déjà une entrée ici — ACTIVE OU SUSPENDUE — n'a pas besoin
        // d'un couloir, et surtout ne doit pas en recevoir un : ce serait écraser
        // son entrée, donc percer une suspension.
        $served = [];
        foreach ($node->grants as $grant) {
            $served[$grant->subject->sortKey()] = true;
        }

        /** @var array<string, array{subject: PlanSubject, roles: array<string,bool>, paths: array<string,bool>}> $wanted */
        $wanted = [];

        foreach ($plan->nodes as $deep) {
            if (! self::isStrictDescendant($node->path, $deep->path)) {
                continue;
            }

            // La restriction de suppression est une décision de NŒUD : elle change
            // ce que la matrice rend, donc elle doit être recalculée pour le nœud
            // profond qu'on regarde, jamais empruntée à celui d'à côté.
            $restriction = PosixAclCompiler::restrictsDeletion($deep);

            foreach ($deep->grants as $grant) {
                if (! $grant->isActive()) {
                    continue;
                }
                if (self::isEnumeratedMemberGrant($plan, $deep, $grant)) {
                    continue;
                }
                if (PosixVerbRendering::of($grant->verbs, $restriction)->isEmpty()) {
                    continue;
                }

                $key = $grant->subject->sortKey();
                if (isset($served[$key])) {
                    continue;
                }

                if (! isset($wanted[$key])) {
                    $wanted[$key] = ['subject' => $grant->subject, 'roles' => [], 'paths' => []];
                }
                $wanted[$key]['roles'][$grant->roleKey] = true;
                $wanted[$key]['paths'][$deep->path] = true;
            }
        }

        ksort($wanted, SORT_STRING);

        $traversals = [];
        foreach ($wanted as $entry) {
            $roles = array_keys($entry['roles']);
            sort($roles, SORT_STRING);
            $paths = array_keys($entry['paths']);
            sort($paths, SORT_STRING);

            $traversals[] = new PosixTraversal($entry['subject'], $roles, $paths);
        }

        return $traversals;
    }

    /**
     * Cet octroi est-il celui du MEMBRE ÉNUMÉRÉ de son nœud ?
     *
     * **Deux formulations de la même chose, et il faut les deux.**
     *
     *  - le JETON RÉSERVÉ ({@see DirectoryTemplate::TREE_ROLE_MEMBER}) est la façon
     *    dont une RECETTE l'écrit, et la validation garantit qu'il ne vit que sur un
     *    nœud par membre ;
     *  - mais un plan n'arrive pas toujours d'une recette : il peut être assemblé
     *    directement (le contrat le permet, et la garde d'échelle du dépôt s'en sert
     *    pour construire 205 dossiers personnels d'un coup). Là, la clé de rôle est
     *    quelconque, et ne regarder qu'elle laisserait dériver 205 couloirs
     *    nominatifs sur la racine — précisément le sur-octroi que l'exclusion existe
     *    pour empêcher. Le critère de MÉCANISME est donc ajouté : sur un nœud PAR
     *    MEMBRE, un sujet nominatif est le membre énuméré, quel que soit le nom qu'on
     *    ait donné à son rôle.
     *
     * L'audience portée par un nœud par membre (l'équipe enseignante sur le dossier
     * d'un élève), elle, dérive normalement : c'est UN sujet, donc UNE entrée par
     * ancêtre — jamais une entrée par personne.
     */
    private static function isEnumeratedMemberGrant(FilePlan $plan, PlanNode $node, PlanGrant $grant): bool
    {
        if ($grant->roleKey === DirectoryTemplate::TREE_ROLE_MEMBER) {
            return true;
        }

        if ($node->nature !== PlanNodeNature::ParMembre
            || $grant->subject->type !== PlanSubject::TYPE_USER) {
            return false;
        }

        // Review 62.5 #1 — le critère de mécanisme seul confondait DEUX individus
        // que rien n'oblige à se ressembler : le membre énuméré (une personne
        // DIFFÉRENTE par nœud) et une personne DÉSIGNÉE, fixe, répétée sur chaque
        // dossier personnel — un CPE, un référent, résolus par la stratégie
        // `designated`, qui existe et est légitime. Le second se voyait refuser
        // tout couloir : il recevait un accès complet sur chaque dossier d'élève et
        // ne pouvait structurellement en atteindre aucun depuis l'extérieur.
        //
        // On les sépare par CONSTRUCTION, sans rien demander au plan : le membre
        // énuméré n'apparaît QUE sur son propre nœud, une personne désignée
        // apparaît sur TOUS. Deux occurrences suffisent donc à trancher.
        //
        // Reste un cas indécidable : un plan qui ne compte qu'UN SEUL nœud par
        // membre. Les deux s'y présentent à l'identique, et on choisit alors de ne
        // pas dériver — le sens qui n'accorde rien de trop. Ce n'est pas silencieux
        // pour autant : un couloir attendu et absent est rapporté en écart par
        // l'inspection, avec son détail.
        return self::countsAsEnumeratedByRepetition($plan, $grant);
    }

    /**
     * Le sujet n'apparaît-il que sur UN nœud par membre ?
     *
     * `true` ⇒ on le tient pour le membre énuméré (pas de couloir).
     * `false` ⇒ il est répété, donc désigné : il dérive comme n'importe quelle
     * audience.
     */
    private static function countsAsEnumeratedByRepetition(FilePlan $plan, PlanGrant $grant): bool
    {
        $seen = 0;

        foreach ($plan->nodes as $node) {
            if ($node->nature !== PlanNodeNature::ParMembre) {
                continue;
            }
            foreach ($node->grants as $other) {
                if ($other->subject->sortKey() === $grant->subject->sortKey()) {
                    $seen++;

                    if ($seen > 1) {
                        return false;
                    }

                    break;
                }
            }
        }

        return true;
    }

    /**
     * `$candidate` est-il un descendant STRICT de `$ancestor` ?
     *
     * Le jeton racine ({@see PlanNode::ROOT_PATH}) n'est pas un segment de chemin :
     * il désigne la racine du plan, donc tout autre nœud lui est descendant, et il
     * n'est descendant de personne. Le traiter comme une chaîne ordinaire ferait
     * échouer la comparaison de préfixe dans les deux sens.
     */
    private static function isStrictDescendant(string $ancestor, string $candidate): bool
    {
        if ($ancestor === $candidate) {
            return false;
        }
        if ($candidate === PlanNode::ROOT_PATH) {
            return false;
        }
        if ($ancestor === PlanNode::ROOT_PATH) {
            return true;
        }

        // Le séparateur est OBLIGATOIRE : sans lui, `_travail` serait l'ancêtre de
        // `_travailleurs`, et le couloir s'ouvrirait sur un dossier voisin.
        return str_starts_with($candidate, $ancestor . '/');
    }
}
