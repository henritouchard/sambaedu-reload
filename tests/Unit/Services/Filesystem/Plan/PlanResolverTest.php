<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Plan;

use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Models\DirectoryTemplate;
use App\Models\UserGroup;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanResolutionContext;
use App\Services\Filesystem\Plan\PlanResolver;
use App\Services\Filesystem\Plan\PlanSubject;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.1 — la résolution : (recette + appartenances) → plan.
 *
 * Aucune base, aucun faux processus, aucun disque : c'est le dividende de la
 * ligne de coupe. Si un jour ces tests ont besoin d'un `Process::fake()`, c'est
 * que la coupe a bougé.
 */
class PlanResolverTest extends TestCase
{
    use ClassTreeRecipe;

    private PlanResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PlanResolver();
    }

    /** @return list<string> */
    private function paths(\App\Services\Filesystem\Plan\FilePlan $plan): array
    {
        return array_map(static fn ($n): string => $n->path, $plan->nodes);
    }

    // =========================================================================
    // Substitution : vocabulaire FERMÉ
    // =========================================================================

    #[Test]
    public function the_root_is_relative_and_the_bare_name_avoids_the_double_prefix(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        // Le groupe s'appelle DÉJÀ « Classe_3emeA » : sans dé-préfixage, le motif
        // produirait « Classe_Classe_3emeA ».
        $this->assertSame('Classes/Classe_3emeA', $plan->rootPath);
        $this->assertStringStartsNotWith('/', $plan->rootPath);
    }

    #[Test]
    public function the_raw_group_name_placeholder_preserves_case(): void
    {
        $template = $this->classTreeTemplate();
        $template->path_pattern = 'Partages/{group.name}';

        $plan = $this->resolver->resolve($template, $this->classTreeContext());

        $this->assertSame('Partages/Classe_3emeA', $plan->rootPath);
    }

    #[Test]
    public function an_unresolvable_group_name_fails_loudly(): void
    {
        $context = new PlanResolutionContext(
            groupId: 7,
            groupName: 'Classe_3eme A',   // espace : segment non sûr
            groupType: 'classe',
            roleTargets: ['equipe' => [PlanSubject::group(11)]],
        );

        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/segment de chemin sûr/u');

        $this->resolver->resolve($this->classTreeTemplate(), $context);
    }

    #[Test]
    public function a_recipe_without_a_tree_cannot_be_resolved(): void
    {
        $template = $this->classTreeTemplate();
        $template->path_pattern = null;
        $template->nodes_spec = null;

        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/ne porte aucun arbre/u');

        $this->resolver->resolve($template, $this->classTreeContext());
    }

    // =========================================================================
    // Les quatre natures
    // =========================================================================

    #[Test]
    public function the_four_natures_are_all_carried_into_the_plan(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $natures = array_values(array_unique(array_map(
            static fn ($n): string => $n->nature->value,
            $plan->nodes,
        )));
        sort($natures);

        $this->assertSame(['activable', 'contenu_libre', 'par_membre', 'partagee'], $natures);
    }

    #[Test]
    public function only_the_free_content_node_relinquishes_authority_over_its_children(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $this->assertFalse($plan->node('_travail/devoirs')->governsChildren());
        $this->assertSame(PlanNodeNature::ContenuLibre, $plan->node('_travail/devoirs')->nature);

        foreach (['_travail', '_profs', '_echange'] as $path) {
            $this->assertTrue($plan->node($path)->governsChildren(), $path);
        }
    }

    #[Test]
    public function the_quota_travels_through_the_plan_without_anything_executing_it(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $this->assertSame(2147483648, $plan->node('bmartin')->plafond);
        $this->assertNull($plan->node('_travail')->plafond);
    }

    // =========================================================================
    // Expansion par membre
    // =========================================================================

    #[Test]
    public function a_per_member_node_yields_one_node_per_member_carrying_the_targeted_edge_role(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        // Deux membres `member` (les deux élèves) — le `manager` et l'`owner` sont
        // dans le même groupe mais ne portent pas le rôle visé.
        $this->assertContains('bmartin', $this->paths($plan));
        $this->assertContains('cpetit', $this->paths($plan));
        $this->assertNotContains('alecoz', $this->paths($plan));
        $this->assertNotContains('ddurand', $this->paths($plan));
    }

    #[Test]
    public function a_per_member_node_with_no_matching_member_yields_zero_node_and_a_valid_plan(): void
    {
        $context = new PlanResolutionContext(
            groupId: self::GROUP_CLASSE_ID,
            groupName: 'Classe_3emeA',
            groupType: 'classe',
            members: [['id' => 101, 'login' => 'alecoz', 'edge_role' => 'manager']],
            roleTargets: [
                'equipe' => [PlanSubject::group(self::GROUP_EQUIPE_ID)],
                'classe' => [PlanSubject::group(self::GROUP_CLASSE_ID)],
                'referents' => [],
            ],
        );

        $plan = $this->resolver->resolve($this->classTreeTemplate(), $context);

        $this->assertSame(['_echange', '_profs', '_travail', '_travail/devoirs'], $this->paths($plan));
    }

    #[Test]
    public function the_nominative_grant_targets_the_internal_identity_never_the_login(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $node = $plan->node('bmartin');
        $nominative = array_values(array_filter(
            $node->grants,
            static fn (PlanGrant $g): bool => $g->roleKey === DirectoryTemplate::TREE_ROLE_MEMBER,
        ));

        $this->assertCount(1, $nominative);
        $this->assertSame(PlanSubject::TYPE_USER, $nominative[0]->subject->type);
        $this->assertSame(self::USER_BRUNO_ID, $nominative[0]->subject->id);
        $this->assertSame(PlanGrant::VERBS, $nominative[0]->verbs);

        // Le login n'est QUE le nom du dossier.
        $this->assertSame('bmartin', $node->path);
    }

    #[Test]
    public function an_unsafe_member_login_fails_the_whole_resolution(): void
    {
        $context = new PlanResolutionContext(
            groupId: self::GROUP_CLASSE_ID,
            groupName: 'Classe_3emeA',
            groupType: 'classe',
            members: [['id' => 102, 'login' => '../evasion', 'edge_role' => 'member']],
            roleTargets: ['equipe' => [PlanSubject::group(11)]],
        );

        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/ne peut pas servir de segment de chemin/u');

        $this->resolver->resolve($this->classTreeTemplate(), $context);
    }

    // =========================================================================
    // Activable : suspendre n'est ni supprimer, ni omettre
    // =========================================================================

    #[Test]
    public function an_inactive_node_stays_in_the_plan_with_its_suspendable_grants_suspended(): void
    {
        $active = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());
        $inactive = $this->resolver->resolve(
            $this->classTreeTemplate(),
            $this->classTreeContext(['_echange' => false]),
        );

        // 1. Le nœud EXISTE dans les deux cas — jamais de variation structurelle.
        $this->assertSame($this->paths($active), $this->paths($inactive));

        $on = $active->node('_echange');
        $off = $inactive->node('_echange');

        $this->assertTrue($on->active);
        $this->assertFalse($off->active);

        // 2. Le même NOMBRE d'octrois : suspendre n'est pas retirer.
        $this->assertCount(count($on->grants), $off->grants);

        // 3. L'octroi suspendable de la classe est suspendu…
        $classeOff = $this->grantForRole($off, 'classe');
        $this->assertTrue($classeOff->suspendable);
        $this->assertTrue($classeOff->suspended);
        $this->assertFalse($classeOff->isActive());
        $this->assertSame(PlanGrant::VERBS, $classeOff->verbs, 'les verbes restent écrits : l\'octroi est suspendu, pas effacé');

        // 4. … et celui de l'équipe reste actif.
        $equipeOff = $this->grantForRole($off, 'equipe');
        $this->assertFalse($equipeOff->suspendable);
        $this->assertTrue($equipeOff->isActive());
    }

    #[Test]
    public function an_activable_node_is_active_by_default(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $this->assertTrue($plan->node('_echange')->active);
        $this->assertTrue($this->grantForRole($plan->node('_echange'), 'classe')->isActive());
    }

    #[Test]
    public function an_activation_state_on_a_non_activable_node_is_ignored(): void
    {
        // Rien, dans ce vocabulaire, ne permet de faire disparaître un nœud : la
        // désactivation d'un nœud qui n'est pas activable n'a simplement pas de
        // prise.
        $plan = $this->resolver->resolve(
            $this->classTreeTemplate(),
            $this->classTreeContext(['_profs' => false, '_travail' => false]),
        );

        $this->assertTrue($plan->node('_profs')->active);
        $this->assertTrue($plan->node('_travail')->active);
        $this->assertNotNull($plan->node('_profs'));
    }

    // =========================================================================
    // AC9 — la clôture, et sa distinction d'avec les deux autres états
    // =========================================================================

    #[Test]
    public function a_node_without_any_grant_for_a_role_carries_that_role_in_its_closure(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        // Le dossier des enseignants : la classe n'a AUCUN octroi ici. En POSIX ce
        // silence suffit ; sur un plan de fichiers à propagation d'ancêtre, il
        // fabrique une fuite. La clôture le dit à voix haute.
        $this->assertSame(['classe'], $plan->node('_profs')->closure);
        $this->assertSame([], $this->grantsForRole($plan->node('_profs'), 'classe'));
    }

    #[Test]
    public function adding_a_grant_removes_the_role_from_the_closure(): void
    {
        $template = $this->classTreeTemplate();
        $nodes = $template->nodes();
        $nodes[2]['grants'][] = ['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]];
        $template->nodes_spec = $nodes;

        $plan = $this->resolver->resolve($template, $this->classTreeContext());

        $this->assertNotContains('classe', $plan->node('_profs')->closure);
        $this->assertCount(1, $this->grantsForRole($plan->node('_profs'), 'classe'));
    }

    #[Test]
    public function the_three_states_are_pairwise_distinct(): void
    {
        $plan = $this->resolver->resolve(
            $this->classTreeTemplate(),
            $this->classTreeContext(['_echange' => false]),
        );

        $travail = $plan->node('_travail');   // octroi ACTIF pour la classe
        $echange = $plan->node('_echange');   // octroi SUSPENDU pour la classe
        $profs = $plan->node('_profs');       // classe dans la CLÔTURE

        // Actif ≠ suspendu.
        $this->assertTrue($this->grantForRole($travail, 'classe')->isActive());
        $this->assertFalse($this->grantForRole($echange, 'classe')->isActive());

        // Suspendu ≠ absent : l'octroi suspendu EST là, l'autre n'existe pas.
        $this->assertCount(1, $this->grantsForRole($echange, 'classe'));
        $this->assertCount(0, $this->grantsForRole($profs, 'classe'));

        // Clôture ≠ suspension : un rôle suspendu n'est PAS dans la clôture (il a
        // bien reçu un octroi, il est seulement vidé).
        $this->assertNotContains('classe', $echange->closure);
        $this->assertContains('classe', $profs->closure);

        // Et un rôle actif n'est dans aucune des deux listes.
        $this->assertNotContains('classe', $travail->closure);
    }

    #[Test]
    public function a_nominative_grant_does_not_discharge_the_audience_role_from_the_closure(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $node = $plan->node('bmartin');

        // L'élève a son octroi nominatif, l'équipe a le sien — mais la CLASSE n'a
        // rien reçu sur le dossier personnel d'un de ses membres. C'est très
        // exactement ce qu'un plan de fichiers à propagation doit refermer, sans
        // quoi chaque élève lit le dossier de son voisin.
        $this->assertContains('classe', $node->closure);
        $this->assertNotContains('equipe', $node->closure);
        $this->assertSame(['classe', 'referents'], $node->closure);
    }

    #[Test]
    public function no_spec_field_can_author_a_closure(): void
    {
        // Tenter de SAISIR une clôture est refusé : le vocabulaire de nœud est
        // fermé, la clôture n'en fait pas partie, et aucun champ ne l'approche.
        foreach ([['closure' => ['equipe']], ['excluded_roles' => ['equipe']], ['deny' => ['classe']]] as $injection) {
            $template = $this->classTreeTemplate();
            $nodes = $template->nodes();
            $nodes[2] = array_merge($nodes[2], $injection);
            $template->nodes_spec = $nodes;

            $rejected = false;
            try {
                $this->resolver->resolve($template, $this->classTreeContext());
            } catch (\App\Exceptions\Filesystem\InvalidTreeSpecException) {
                $rejected = true;
            }

            $this->assertTrue($rejected, 'champ accepté à tort : ' . implode(',', array_keys($injection)));
        }
    }

    #[Test]
    public function the_only_lever_on_the_closure_is_the_grant_list(): void
    {
        // Tous les autres champs du nœud peuvent bouger : la clôture ne bouge pas.
        $template = $this->classTreeTemplate();
        $nodes = $template->nodes();
        $nodes[2]['plafond'] = 4096;
        $nodes[2]['label'] = 'Espace des enseignants (renommé)';
        $template->nodes_spec = $nodes;

        $plan = $this->resolver->resolve($template, $this->classTreeContext());

        $this->assertSame(['classe'], $plan->node('_profs')->closure);
        $this->assertSame(4096, $plan->node('_profs')->plafond);
    }

    #[Test]
    public function a_recipe_role_without_any_target_is_still_a_role_of_the_plan(): void
    {
        $context = new PlanResolutionContext(
            groupId: self::GROUP_CLASSE_ID,
            groupName: 'Classe_3emeA',
            groupType: 'classe',
            members: [],
            roleTargets: [
                'equipe' => [PlanSubject::group(self::GROUP_EQUIPE_ID)],
                'classe' => [PlanSubject::group(self::GROUP_CLASSE_ID)],
            ],
        );

        $plan = $this->resolver->resolve($this->classTreeTemplate(), $context);

        // `referents` est déclaré par la recette mais sans cible : il figure dans
        // le plan avec zéro sujet, et n'émet donc aucun octroi.
        $this->assertSame(['classe', 'equipe', 'referents'], $plan->roleKeys());
        $this->assertSame([], $plan->roles['referents']);
        $this->assertSame([], $this->grantsForRole($plan->node('_profs'), 'referents'));

        // MAIS il n'entre PAS dans la clôture de `_profs` pour autant : la recette
        // lui y accorde un octroi, et la clôture est STRUCTURELLE — elle se calcule
        // sur ce que la recette ÉCRIT, jamais sur l'effectif du jour. Fermer un
        // accès accordé au motif que personne ne l'occupe cette année obligerait à
        // le rouvrir à la première arrivée. C'est le comportement voulu, et cette
        // assertion est ce qui l'empêche de dériver.
        $this->assertNotContains('referents', $plan->node('_profs')->closure);

        // Contrôle : sur ce même nœud, `classe` — qui n'a AUCUN octroi écrit — est
        // bien dans la clôture, alors que son audience, elle, est peuplée.
        $this->assertContains('classe', $plan->node('_profs')->closure);
    }

    // =========================================================================
    // Octrois d'audience : forme abstraite, jamais une énumération
    // =========================================================================

    #[Test]
    public function an_audience_grant_names_the_group_not_its_members(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $grant = $this->grantForRole($plan->node('_travail'), 'classe');

        $this->assertSame(PlanSubject::TYPE_USER_GROUP, $grant->subject->type);
        $this->assertSame(self::GROUP_CLASSE_ID, $grant->subject->id);
        $this->assertNull($grant->subject->edgeRole);

        // Aucun des quatre membres n'apparaît comme sujet d'un octroi d'audience.
        foreach ($plan->node('_travail')->grants as $g) {
            $this->assertNotSame(PlanSubject::TYPE_USER, $g->subject->type);
        }
    }

    #[Test]
    public function an_audience_can_be_qualified_by_an_edge_role(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $grant = $this->grantForRole($plan->node('_profs'), 'referents');

        $this->assertSame(PlanSubject::TYPE_USER_GROUP, $grant->subject->type);
        $this->assertSame('owner', $grant->subject->edgeRole);
    }

    #[Test]
    public function an_edge_role_cannot_qualify_a_user_subject(): void
    {
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/sujet de type groupe/u');

        new PlanSubject(PlanSubject::TYPE_USER, 42, 'manager');
    }

    #[Test]
    public function a_subject_is_never_a_login(): void
    {
        $this->expectException(PlanResolutionException::class);

        new PlanSubject(PlanSubject::TYPE_USER, 0);
    }

    // =========================================================================
    // Aucun deny exprimable
    // =========================================================================

    /**
     * Story 62.4 — L'ÉPINGLE RETOURNÉE : le vocabulaire n'est plus « les deux
     * niveaux positifs », c'est « les quatre verbes positifs ».
     *
     * Ce qui NE change pas, et qui est tout l'objet du test : il reste impossible
     * d'écrire une interdiction. Ni « aucun », ni un refus, ni un mode système, ni
     * une liste vide — cette dernière étant le seul ajout de la story à la liste
     * des refus, parce qu'un octroi qui ne donne rien serait indiscernable d'une
     * suspension appliquée.
     */
    #[Test]
    public function no_verb_other_than_the_four_positive_ones_can_exist(): void
    {
        $this->assertSame(['lire', 'editer', 'creer', 'supprimer'], PlanGrant::VERBS);

        foreach ([['none'], ['deny'], ['---'], ['rwx'], [''], ['ro'], ['rw'], []] as $forbidden) {
            $rejected = false;
            try {
                new PlanGrant('equipe', PlanSubject::group(1), $forbidden);
            } catch (PlanResolutionException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'octroi accepté à tort : ' . json_encode($forbidden));
        }
    }

    /**
     * Story 62.4 — les verbes sont un ENSEMBLE ORDONNÉ CANONIQUEMENT, et c'est ce
     * qui fait tenir le déterminisme octet pour octet de la story 60.1 : deux
     * saisies du même octroi, dans deux ordres, se sérialisent identiquement.
     */
    #[Test]
    public function verbs_are_deduplicated_and_ordered_canonically(): void
    {
        $grant = new PlanGrant('equipe', PlanSubject::group(1), ['supprimer', 'lire', 'supprimer', 'editer']);

        $this->assertSame(['lire', 'editer', 'supprimer'], $grant->verbs);
        $this->assertSame(
            $grant->sortKey(),
            (new PlanGrant('equipe', PlanSubject::group(1), ['lire', 'editer', 'supprimer']))->sortKey(),
        );
    }

    #[Test]
    public function a_non_suspendable_grant_cannot_be_suspended(): void
    {
        $this->expectException(PlanResolutionException::class);

        new PlanGrant('equipe', PlanSubject::group(1), PlanGrant::VERBS, suspendable: false, suspended: true);
    }

    // =========================================================================
    // Contexte : entrées mal formées
    // =========================================================================

    #[Test]
    public function a_member_with_an_unknown_edge_role_is_refused_at_the_door(): void
    {
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/rôle d\'arête inconnu/u');

        new PlanResolutionContext(
            groupId: 7,
            groupName: 'Classe_3emeA',
            groupType: 'classe',
            members: [['id' => 1, 'login' => 'x', 'edge_role' => 'prof_principal']],
        );
    }

    #[Test]
    public function a_role_target_must_be_an_internal_identity_not_a_name(): void
    {
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/identité interne/u');

        new PlanResolutionContext(
            groupId: 7,
            groupName: 'Classe_3emeA',
            groupType: 'classe',
            roleTargets: ['equipe' => ['equipe_3emea']],
        );
    }

    #[Test]
    public function the_context_never_touches_an_eloquent_model(): void
    {
        // Le contexte se construit à partir d'identités, pas de modèles : c'est ce
        // qui rend la résolution testable sans base.
        $group = new UserGroup(['name' => 'Classe_3emeA', 'type' => 'classe']);

        $context = new PlanResolutionContext(
            groupId: 7,
            groupName: (string) $group->name,
            groupType: (string) $group->type,
            roleTargets: ['equipe' => [PlanSubject::group(11)]],
        );

        $this->assertSame('Classe_3emeA', $context->groupName);
        $this->assertSame([], $context->members);
    }

    // =========================================================================
    // Story 60.2 — le nommage de chemin des mailles « matière × classe »
    // =========================================================================

    private function matiereTemplate(string $pattern): DirectoryTemplate
    {
        return new DirectoryTemplate([
            'key' => 'matiere_share',
            'label' => 'Partage de matière',
            'roles_spec' => [
                [
                    'key' => 'equipe',
                    'label' => 'Enseignants de la matière',
                    'maille' => UserGroup::class,
                    'group_type' => 'matiere_classe',
                    'verbs' => PlanGrant::VERBS,
                    'cardinality' => 'one',
                ],
            ],
            'path_pattern' => $pattern,
            'nodes_spec' => [
                [
                    'path' => '_travail',
                    'label' => 'Documents de travail',
                    'nature' => 'partagee',
                    'grants' => [['role' => 'equipe', 'verbs' => PlanGrant::VERBS]],
                ],
            ],
        ]);
    }

    private function matiereContext(string $groupName, string $groupType = 'matiere_classe'): PlanResolutionContext
    {
        return new PlanResolutionContext(
            groupId: 21,
            groupName: $groupName,
            groupType: $groupType,
            roleTargets: ['equipe' => [PlanSubject::group(21)]],
        );
    }

    #[Test]
    public function a_matiere_classe_group_resolves_its_two_halves_into_two_path_segments(): void
    {
        $plan = $this->resolver->resolve(
            $this->matiereTemplate('Matieres/{group.classe}/{group.matiere}'),
            $this->matiereContext('Matiere_Math@3emeA'),
        );

        // La structure de l'ancien système, retrouvée SANS perte : le « @ »
        // n'entre pas dans un segment, et le plan porte l'identité interne du
        // groupe — rien n'a besoin d'être ré-analysé depuis le chemin.
        $this->assertSame('Matieres/3emeA/Math', $plan->rootPath);
    }

    #[Test]
    public function the_bare_name_of_a_matiere_classe_group_still_fails_explicitly(): void
    {
        // Comportement 60.1 CONSERVÉ : le « @ » n'est pas un segment sûr, donc
        // `{group.bare_name}` n'est pas fourni pour ce type — et un placeholder
        // non fourni fait échouer la résolution, jamais silencieusement.
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/non résolvable/u');

        $this->resolver->resolve(
            $this->matiereTemplate('Matieres/{group.bare_name}'),
            $this->matiereContext('Matiere_Math@3emeA'),
        );
    }

    #[Test]
    public function the_matiere_placeholders_are_not_available_outside_that_type(): void
    {
        // La maille du groupe EST la maille du cloisonnement : `matiere` nu
        // désigne les enseignants d'une discipline, pas une matière dans une
        // classe. Ses deux moitiés n'existent pas.
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/\{group\.classe\}.*non résolvable|non résolvable/u');

        $this->resolver->resolve(
            $this->matiereTemplate('Matieres/{group.classe}/{group.matiere}'),
            $this->matiereContext('Matiere_Math', 'matiere'),
        );
    }

    #[Test]
    public function a_name_without_a_single_at_sign_fails_explicitly(): void
    {
        foreach (['Matiere_Math', 'Matiere_A@B@C', 'Matiere_Ma th@3A', 'Matiere_@3A'] as $impossible) {
            try {
                $this->resolver->resolve(
                    $this->matiereTemplate('Matieres/{group.classe}/{group.matiere}'),
                    $this->matiereContext($impossible),
                );
                $this->fail('la résolution aurait dû échouer explicitement sur « ' . $impossible . ' »');
            } catch (PlanResolutionException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_matiere_classe_group_can_still_use_its_raw_name_when_it_is_safe(): void
    {
        // Un groupe typé matière×classe dont le nom ne porte PAS de « @ » garde le
        // comportement ordinaire : son nom nu reste disponible.
        $plan = $this->resolver->resolve(
            $this->matiereTemplate('Matieres/{group.bare_name}'),
            $this->matiereContext('Matiere_Math'),
        );

        $this->assertSame('Matieres/Math', $plan->rootPath);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return list<PlanGrant> */
    private function grantsForRole(\App\Services\Filesystem\Plan\PlanNode $node, string $roleKey): array
    {
        return array_values(array_filter(
            $node->grants,
            static fn (PlanGrant $g): bool => $g->roleKey === $roleKey,
        ));
    }

    private function grantForRole(\App\Services\Filesystem\Plan\PlanNode $node, string $roleKey): PlanGrant
    {
        $grants = $this->grantsForRole($node, $roleKey);
        $this->assertCount(1, $grants, "un seul octroi attendu pour « {$roleKey} » sur « {$node->path} »");

        return $grants[0];
    }
}
