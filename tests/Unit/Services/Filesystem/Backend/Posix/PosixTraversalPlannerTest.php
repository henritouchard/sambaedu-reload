<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\PlanNodeNature;
use App\Models\DirectoryTemplate;
use App\Services\Filesystem\Backend\Posix\PosixTraversal;
use App\Services\Filesystem\Backend\Posix\PosixTraversalPlanner;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 62.5 — LA RÈGLE DE DÉRIVATION, TESTÉE À NU.
 *
 * `TestCase` PUR : aucune base, aucune simulation d'exécution, aucun conteneur. Ce
 * n'est pas de l'ascétisme, c'est la propriété du planificateur — il ne consomme que
 * le plan. Le jour où ce fichier aurait besoin d'un décor, c'est que la dérivation
 * aurait cessé d'être pure, et il vaut mieux que ça se voie ici que nulle part.
 */
class PosixTraversalPlannerTest extends TestCase
{
    private const EQUIPE = 11;

    private const CLASSE = 7;

    private const ELEVE = 101;

    private function planner(): PosixTraversalPlanner
    {
        return new PosixTraversalPlanner();
    }

    private function plan(PlanNode ...$nodes): FilePlan
    {
        return new FilePlan('@arbre', 'Classe_3A', [], $nodes);
    }

    /** @param list<PlanGrant> $grants */
    private function node(string $path, array $grants = [], PlanNodeNature $nature = PlanNodeNature::Partagee): PlanNode
    {
        return new PlanNode($path, 'Nœud ' . $path, $nature, $grants);
    }

    /** @param list<string> $verbs */
    private function grant(string $role, PlanSubject $subject, array $verbs = [PlanGrant::VERB_LIRE]): PlanGrant
    {
        return new PlanGrant($role, $subject, $verbs);
    }

    /**
     * @param  list<PosixTraversal>  $traversals
     * @return list<string>
     */
    private function subjectIds(array $traversals): array
    {
        return array_map(static fn (PosixTraversal $t): string => $t->subject->type . '#' . $t->subject->id, $traversals);
    }

    // =========================================================================
    // LE CAS CENTRAL
    // =========================================================================

    /**
     * Un rôle qui ne reçoit quelque chose QU'EN PROFONDEUR obtient un couloir sur
     * CHAQUE ancêtre déclaré — et rien de plus nulle part.
     */
    #[Test]
    public function a_deep_grant_opens_a_corridor_on_every_declared_ancestor(): void
    {
        $classe = PlanSubject::group(self::CLASSE);

        $plan = $this->plan(
            $this->node(PlanNode::ROOT_PATH, [$this->grant('equipe', PlanSubject::group(self::EQUIPE))]),
            $this->node('a'),
            $this->node('a/b'),
            $this->node('a/b/c', [$this->grant('classe', $classe)]),
        );

        foreach ([PlanNode::ROOT_PATH, 'a', 'a/b'] as $ancestor) {
            $traversals = $this->planner()->forNode($plan, $plan->node($ancestor));

            self::assertCount(1, $traversals, "ancêtre « {$ancestor} »");
            self::assertSame(self::CLASSE, $traversals[0]->subject->id);
            self::assertSame(['classe'], $traversals[0]->roleKeys);
            self::assertSame(['a/b/c'], $traversals[0]->nodePaths);
        }

        // Le nœud profond lui-même n'a personne à servir.
        self::assertSame([], $this->planner()->forNode($plan, $plan->node('a/b/c')));
    }

    /**
     * **LE PIÈGE CENTRAL, dit en une assertion : le couloir n'accorde RIEN DE PLUS.**
     *
     * Le rôle qui lit `a/b/c` traverse `a` et `a/b`. Il ne peut ni les LISTER, ni y
     * lire, ni y créer, éditer ou supprimer quoi que ce soit — et la façon dont on le
     * vérifie ici est la seule honnête : le plan de ces ancêtres ne porte AUCUN
     * octroi pour lui, donc rien de ce que la compilation écrit pour un octroi ne le
     * concerne. La forme exacte de l'entrée posée est vérifiée là où elle est écrite
     * ({@see PosixTraversalBackendTest}).
     */
    #[Test]
    public function the_corridor_never_carries_a_single_verb(): void
    {
        $classe = PlanSubject::group(self::CLASSE);

        $plan = $this->plan(
            $this->node('a'),
            $this->node('a/b'),
            $this->node('a/b/c', [$this->grant('classe', $classe, PlanGrant::VERBS)]),
        );

        foreach (['a', 'a/b'] as $ancestor) {
            $node = $plan->node($ancestor);

            // Rien n'a été ajouté au PLAN : ni octroi, ni verbe, ni rien.
            self::assertSame([], $node->grants, "le plan de « {$ancestor} » a été modifié");
            self::assertCount(1, $this->planner()->forNode($plan, $node));
        }
    }

    // =========================================================================
    // LES QUATRE EXCLUSIONS
    // =========================================================================

    #[Test]
    public function a_subject_already_served_on_the_ancestor_gets_no_corridor(): void
    {
        $classe = PlanSubject::group(self::CLASSE);

        $plan = $this->plan(
            $this->node('a', [$this->grant('classe', $classe)]),
            $this->node('a/b', [$this->grant('classe', $classe, PlanGrant::VERBS)]),
        );

        self::assertSame([], $this->planner()->forNode($plan, $plan->node('a')));
    }

    /**
     * **UNE SUSPENSION N'EST JAMAIS PERCÉE.** L'entrée du sujet sur l'ancêtre est
     * explicitement vide ; écrire le couloir l'écraserait, et la relecture dirait
     * « suspension non appliquée ». Fermer le couloir ferme les pièces.
     */
    #[Test]
    public function a_suspended_entry_on_the_ancestor_is_never_pierced(): void
    {
        $classe = PlanSubject::group(self::CLASSE);

        $plan = $this->plan(
            new PlanNode('_echange', 'Échange', PlanNodeNature::Activable, [
                new PlanGrant('classe', $classe, [PlanGrant::VERB_LIRE], suspendable: true, suspended: true),
            ], active: false),
            $this->node('_echange/depot', [$this->grant('classe', $classe, PlanGrant::VERBS)]),
        );

        self::assertSame([], $this->planner()->forNode($plan, $plan->node('_echange')));
    }

    /** Un octroi profond SUSPENDU ne donne rien : un couloir vers un dossier vidé. */
    #[Test]
    public function a_suspended_deep_grant_derives_nothing(): void
    {
        $classe = PlanSubject::group(self::CLASSE);

        $plan = $this->plan(
            $this->node('a'),
            new PlanNode('a/b', 'Échange', PlanNodeNature::Activable, [
                new PlanGrant('classe', $classe, PlanGrant::VERBS, suspendable: true, suspended: true),
            ], active: false),
        );

        self::assertSame([], $this->planner()->forNode($plan, $plan->node('a')));
    }

    /**
     * **LE NOMINATIF NE DÉRIVE JAMAIS**, sous ses deux formes : le jeton réservé de
     * la recette, et le sujet nominatif d'un nœud par membre assemblé à la main. Sans
     * la seconde, une classe de 250 élèves poserait 250 entrées nominatives sur la
     * racine — là où l'arbre historique n'en porte aucune.
     */
    #[Test]
    public function the_enumerated_member_never_derives_under_either_of_its_two_forms(): void
    {
        $plan = $this->plan(
            $this->node(PlanNode::ROOT_PATH),
            new PlanNode('bmartin', 'Dossier personnel', PlanNodeNature::ParMembre, [
                new PlanGrant(DirectoryTemplate::TREE_ROLE_MEMBER, PlanSubject::user(self::ELEVE), PlanGrant::VERBS),
            ]),
            new PlanNode('cpetit', 'Dossier personnel', PlanNodeNature::ParMembre, [
                // Plan assemblé HORS recette : la clé de rôle est quelconque, seul le
                // mécanisme dit que ce sujet est le membre énuméré.
                new PlanGrant('@nominatif', PlanSubject::user(self::ELEVE + 1), PlanGrant::VERBS),
            ]),
        );

        self::assertSame([], $this->planner()->forNode($plan, $plan->node(PlanNode::ROOT_PATH)));
    }

    /**
     * L'AUDIENCE portée par un nœud par membre, elle, dérive normalement : c'est UN
     * sujet, donc UNE entrée par ancêtre — jamais une par personne. Confondre les
     * deux aurait rendu l'exclusion trop large, et le dossier personnel inatteignable
     * pour l'équipe enseignante.
     */
    #[Test]
    public function an_audience_carried_by_a_per_member_node_still_derives(): void
    {
        $equipe = PlanSubject::group(self::EQUIPE, 'manager');

        $plan = $this->plan(
            $this->node(PlanNode::ROOT_PATH),
            new PlanNode('bmartin', 'Dossier personnel', PlanNodeNature::ParMembre, [
                new PlanGrant(DirectoryTemplate::TREE_ROLE_MEMBER, PlanSubject::user(self::ELEVE), PlanGrant::VERBS),
                new PlanGrant('equipe', $equipe, PlanGrant::VERBS),
            ]),
            new PlanNode('cpetit', 'Dossier personnel', PlanNodeNature::ParMembre, [
                new PlanGrant(DirectoryTemplate::TREE_ROLE_MEMBER, PlanSubject::user(self::ELEVE + 1), PlanGrant::VERBS),
                new PlanGrant('equipe', $equipe, PlanGrant::VERBS),
            ]),
        );

        $traversals = $this->planner()->forNode($plan, $plan->node(PlanNode::ROOT_PATH));

        self::assertCount(1, $traversals, 'deux dossiers personnels, UN seul couloir d\'audience');
        self::assertSame(self::EQUIPE, $traversals[0]->subject->id);
        self::assertSame(['bmartin', 'cpetit'], $traversals[0]->nodePaths);
    }

    /**
     * Review 62.5 #1 — une personne DÉSIGNÉE, répétée sur chaque dossier personnel,
     * n'est pas le membre énuméré et doit obtenir son couloir.
     *
     * Le critère de mécanisme seul (« sur un nœud par membre, tout sujet nominatif
     * est le membre énuméré ») confondait deux individus que rien n'oblige à se
     * ressembler : celui qui change à chaque nœud, et celui qui est le même partout
     * — un CPE, un référent, résolus par la stratégie `designated`. Le second
     * recevait un accès complet sur chaque dossier d'élève sans pouvoir en atteindre
     * aucun depuis l'extérieur : un mirage, exactement ce que cette story élimine.
     *
     * On les sépare par la répétition, qui est une propriété du plan lui-même.
     */
    #[Test]
    public function a_designated_individual_repeated_on_every_personal_folder_still_derives(): void
    {
        $cpe = PlanSubject::user(self::ELEVE + 900);

        $plan = $this->plan(
            $this->node(PlanNode::ROOT_PATH),
            new PlanNode('bmartin', 'Dossier personnel', PlanNodeNature::ParMembre, [
                new PlanGrant(DirectoryTemplate::TREE_ROLE_MEMBER, PlanSubject::user(self::ELEVE), PlanGrant::VERBS),
                new PlanGrant('cpe', $cpe, PlanGrant::VERBS),
            ]),
            new PlanNode('cpetit', 'Dossier personnel', PlanNodeNature::ParMembre, [
                new PlanGrant(DirectoryTemplate::TREE_ROLE_MEMBER, PlanSubject::user(self::ELEVE + 1), PlanGrant::VERBS),
                new PlanGrant('cpe', $cpe, PlanGrant::VERBS),
            ]),
        );

        $traversals = $this->planner()->forNode($plan, $plan->node(PlanNode::ROOT_PATH));

        self::assertCount(1, $traversals, 'un individu désigné, UN couloir');
        self::assertSame($cpe->sortKey(), $traversals[0]->subject->sortKey());
        self::assertSame(['bmartin', 'cpetit'], $traversals[0]->nodePaths);
    }

    /**
     * L'autre moitié : les membres énumérés, eux, ne dérivent toujours PAS —
     * un par nœud, donc jamais répétés. C'est cette exclusion qui empêche N entrées
     * nominatives de s'accumuler sur la racine.
     */
    #[Test]
    public function enumerated_members_derive_no_corridor_however_many_there_are(): void
    {
        $nodes = [$this->node(PlanNode::ROOT_PATH)];
        for ($i = 0; $i < 12; $i++) {
            $nodes[] = new PlanNode('eleve' . $i, 'Dossier personnel', PlanNodeNature::ParMembre, [
                // Clé de rôle QUELCONQUE : un plan assemblé contre le contrat n'est
                // pas tenu d'employer le jeton de recette.
                new PlanGrant('@nominatif', PlanSubject::user(self::ELEVE + $i), PlanGrant::VERBS),
            ]);
        }

        $plan = $this->plan(...$nodes);

        self::assertSame([], $this->planner()->forNode($plan, $plan->node(PlanNode::ROOT_PATH)));
    }

    /**
     * Un octroi que la matrice ne rend PAS DU TOUT n'écrit aucune entrée sur son
     * propre nœud : lui ouvrir un couloir mènerait à rien, et sèmerait une entrée
     * orpheline.
     */
    #[Test]
    public function a_grant_that_renders_nothing_opens_no_corridor_towards_nothing(): void
    {
        $plan = $this->plan(
            $this->node('a'),
            // « supprimer » seul : le seul levier disponible donnerait aussi la
            // création, donc rien n'est rendu (story 62.4).
            $this->node('a/b', [$this->grant('classe', PlanSubject::group(self::CLASSE), [PlanGrant::VERB_SUPPRIMER])]),
        );

        self::assertSame([], $this->planner()->forNode($plan, $plan->node('a')));
    }

    // =========================================================================
    // Cas limites de forme
    // =========================================================================

    /** Servi sur `a/b`, pas sur `a` : le couloir n'apparaît que là où il manque. */
    #[Test]
    public function the_corridor_appears_only_where_the_subject_is_not_already_served(): void
    {
        $classe = PlanSubject::group(self::CLASSE);

        $plan = $this->plan(
            $this->node('a'),
            $this->node('a/b', [$this->grant('classe', $classe)]),
            $this->node('a/b/c', [$this->grant('classe', $classe, PlanGrant::VERBS)]),
        );

        self::assertCount(1, $this->planner()->forNode($plan, $plan->node('a')));
        self::assertSame([], $this->planner()->forNode($plan, $plan->node('a/b')));
    }

    /** Deux rôles, un seul sujet : UN couloir, qui nomme les deux rôles. */
    #[Test]
    public function two_roles_pointing_at_the_same_subject_yield_a_single_corridor(): void
    {
        $cible = PlanSubject::group(self::CLASSE);

        $plan = $this->plan(
            $this->node('a'),
            $this->node('a/b', [
                $this->grant('classe', $cible),
                $this->grant('referents', $cible, PlanGrant::VERBS),
            ]),
        );

        $traversals = $this->planner()->forNode($plan, $plan->node('a'));

        self::assertCount(1, $traversals);
        self::assertSame(['classe', 'referents'], $traversals[0]->roleKeys);
    }

    /**
     * Le SÉPARATEUR est obligatoire : sans lui, `_travail` serait l'ancêtre de
     * `_travailleurs` et le couloir s'ouvrirait sur un dossier voisin.
     */
    #[Test]
    public function a_sibling_whose_name_starts_like_the_ancestor_is_not_a_descendant(): void
    {
        $plan = $this->plan(
            $this->node('_travail'),
            $this->node('_travailleurs', [$this->grant('classe', PlanSubject::group(self::CLASSE))]),
        );

        self::assertSame([], $this->planner()->forNode($plan, $plan->node('_travail')));
    }

    /** Le jeton racine n'est descendant de personne, et tout le monde en descend. */
    #[Test]
    public function the_root_token_is_an_ancestor_of_everything_and_a_descendant_of_nothing(): void
    {
        $plan = $this->plan(
            $this->node(PlanNode::ROOT_PATH, [$this->grant('classe', PlanSubject::group(self::CLASSE))]),
            $this->node('a', [$this->grant('equipe', PlanSubject::group(self::EQUIPE))]),
        );

        self::assertSame(
            ['user_group#' . self::EQUIPE],
            $this->subjectIds($this->planner()->forNode($plan, $plan->node(PlanNode::ROOT_PATH))),
        );
        self::assertSame([], $this->planner()->forNode($plan, $plan->node('a')));
    }

    /**
     * DÉTERMINISME : deux appels sur le même plan rendent la même chose, dans le
     * même ordre, quel que soit l'ordre de déclaration des nœuds profonds. Sans
     * cette propriété, la comparaison désiré/observé serait bruitée d'un passage à
     * l'autre.
     */
    #[Test]
    public function the_derivation_is_deterministic_whatever_the_order_of_the_deep_nodes(): void
    {
        $deep = [
            $this->node('a/x', [$this->grant('r1', PlanSubject::group(30))]),
            $this->node('a/y', [$this->grant('r2', PlanSubject::group(12))]),
            $this->node('a/z', [$this->grant('r3', PlanSubject::user(4))]),
        ];

        $first = $this->planner()->forNode(
            $this->plan($this->node('a'), ...$deep),
            $this->node('a'),
        );
        $second = $this->planner()->forNode(
            $this->plan($this->node('a'), ...array_reverse($deep)),
            $this->node('a'),
        );

        self::assertSame($this->subjectIds($first), $this->subjectIds($second));
        self::assertSame(['user#4', 'user_group#12', 'user_group#30'], $this->subjectIds($first));
    }
}
