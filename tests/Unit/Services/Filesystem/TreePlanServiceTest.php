<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\PlanAnchor;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Models\DirectoryTemplate;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Filesystem\TreePlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\ClassTreeRecipe;
use Tests\Unit\Services\Filesystem\Plan\PlanNeutralityMarkers;

/**
 * Story 60.2 — LA CHAÎNE COMPLÈTE : groupe réel en base → plan neutre.
 *
 * Ces tests-ci ont besoin d'une base, et c'est normal : l'assembleur requête. Ce
 * qu'ils N'ONT PAS besoin, c'est d'un faux processus ou d'un faux système de
 * fichiers — si un jour l'un d'eux en réclame un, c'est que la ligne de coupe a
 * bougé.
 *
 * Le test PIVOT de ce fichier est {@see edge_role_subjects_do_not_depend_on_the_headcount()} :
 * il matérialise la conclusion de la revue de la story 60.1 — la garde de la
 * mesure vit dans la couche des stratégies, pas dans le résolveur pur.
 */
class TreePlanServiceTest extends TestCase
{
    use ClassTreeRecipe;
    use PlanNeutralityMarkers;
    use RefreshDatabase;

    private TreePlanService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Les observers de groupe et d'appartenance projettent vers l'annuaire et
        // synchronisent des ACL : rien de tout cela n'a sa place dans un test de
        // RÉSOLUTION, qui ne lit que SQL.
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        UserGroupUserPivotObserver::disableAdResync();

        $this->service = new TreePlanService();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        UserGroupUserPivotObserver::enableAdResync();

        parent::tearDown();
    }

    // =========================================================================
    // Décor
    // =========================================================================

    private function classGroup(string $name = '3emeA', string $type = 'classe'): UserGroup
    {
        return UserGroup::create(['name' => $name, 'display_name' => $name, 'type' => $type]);
    }

    /**
     * @param  array<string, int>  $byEdgeRole  rôle d'arête => nombre de membres
     */
    private function withMembers(UserGroup $group, array $byEdgeRole, string $loginPrefix = 'u'): UserGroup
    {
        $index = 0;
        foreach ($byEdgeRole as $edgeRole => $count) {
            for ($i = 0; $i < $count; $i++) {
                $user = User::factory()->create(['login' => sprintf('%s%04d', $loginPrefix, $index++)]);
                $group->users()->attach($user->id, ['role' => $edgeRole]);
            }
        }

        return $group->fresh(['users']) ?? $group;
    }

    /** @return list<PlanSubject> */
    private function subjectsOf(FilePlan $plan, string $roleKey): array
    {
        return $plan->roles[$roleKey] ?? [];
    }

    // =========================================================================
    // AC5 — la chaîne complète, et son absence de recette
    // =========================================================================

    #[Test]
    public function a_group_whose_type_has_no_attached_recipe_yields_null(): void
    {
        // L'absence de recette est l'état NORMAL de la quasi-totalité des types :
        // lever ici obligerait chaque appelant à rattraper le cas ordinaire.
        $this->assertNull($this->service->planFor($this->classGroup()));

        $this->autoResolvableClassTreeTemplate()->save();
        $this->assertNull($this->service->planFor($this->classGroup('Arts', 'projet')));
        $this->assertNull($this->service->planFor($this->classGroup('Sans', '')));
    }

    #[Test]
    public function a_real_class_group_resolves_its_whole_tree(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();

        $group = $this->withMembers($this->classGroup('3emeA'), ['manager' => 1, 'owner' => 1, 'member' => 2]);

        $plan = $this->service->planFor($group);

        $this->assertNotNull($plan);
        // Story 60.5 — la racine du plan est UN segment : la ZONE est portée par
        // l'ancre logique, plus par un segment de tête du motif de chemin.
        $this->assertSame('Classe_3emeA', $plan->rootPath);
        $this->assertSame(PlanAnchor::Classes, $plan->anchor);

        // 5 nœuds fixes (racine comprise) + 1 nœud par élève.
        $paths = array_map(static fn ($n): string => $n->path, $plan->nodes);
        sort($paths);
        $this->assertSame(
            ['.', '_echange', '_profs', '_travail', '_travail/devoirs', 'u0002', 'u0003'],
            $paths,
        );
    }

    #[Test]
    public function a_brownfield_prefixed_group_name_does_not_double_the_prefix(): void
    {
        // Le repliement 4.13 stocke le nom NU, mais des lignes préfixées
        // subsistent sur les instances en place. Les deux formes doivent donner le
        // MÊME chemin.
        $this->autoResolvableClassTreeTemplate()->save();

        $plan = $this->service->planFor($this->classGroup('Classe_3emeA'));

        $this->assertNotNull($plan);
        $this->assertSame('Classe_3emeA', $plan->rootPath);
    }

    #[Test]
    public function the_same_state_resolves_to_the_same_bytes_twice(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();
        $group = $this->withMembers($this->classGroup('3emeA'), ['manager' => 2, 'member' => 3]);

        $first = $this->service->planFor($group);
        $second = $this->service->planFor($group->fresh(['users']));

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->toJson(), $second->toJson(), 'sans déterminisme, la détection d\'écart serait mort-née');
    }

    #[Test]
    public function an_unpersisted_group_is_refused_at_the_door(): void
    {
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/identité interne/u');

        $this->service->contextFor(new UserGroup(['name' => '3emeA', 'type' => 'classe']), $this->autoResolvableClassTreeTemplate(null));
    }

    // =========================================================================
    // AC2 — LA GARDE DE LA MESURE
    // =========================================================================

    #[Test]
    public function edge_role_subjects_do_not_depend_on_the_headcount(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();

        // LE MÊME groupe, résolu à 3 membres puis à 300.
        $group = $this->withMembers($this->classGroup('6A'), ['manager' => 1, 'member' => 2], 'p');
        $aTrois = $this->service->planFor($group);

        $this->withMembers($group, ['manager' => 7, 'member' => 290], 'g');
        $aTroisCents = $this->service->planFor($group->fresh(['users']));

        $this->assertNotNull($aTrois);
        $this->assertNotNull($aTroisCents);

        // Les SUJETS de l'audience sont rigoureusement les mêmes — même type,
        // même identité (celle du groupe), même rôle d'arête. C'est ce qui rend
        // l'octroi compilable en groupe dérivé au lieu d'une pose quadratique
        // d'entrées nominatives, qui butterait sur sa limite dure.
        $forme = static fn (PlanSubject $s): string => $s->type . '#' . $s->id . '@' . (string) $s->edgeRole;

        $this->assertSame(
            array_map($forme, $this->subjectsOf($aTrois, 'equipe')),
            array_map($forme, $this->subjectsOf($aTroisCents, 'equipe')),
            'l\'audience a changé de forme avec l\'effectif : la garde de la mesure est tombée',
        );
        $this->assertCount(
            1,
            $this->subjectsOf($aTroisCents, 'equipe'),
            'un rôle d\'arête listé ⇒ UN sujet abstrait, que le groupe compte 3 membres ou 300',
        );

        // Et l'ENSEMBLE des sujets d'audience du plan entier est le même : ce que
        // l'effectif fait varier, ce sont les NŒUDS, jamais les audiences.
        $audienceSubjects = static function (FilePlan $plan) use ($forme): array {
            $keys = [];
            foreach ($plan->nodes as $node) {
                foreach ($node->grants as $grant) {
                    if ($grant->subject->type === PlanSubject::TYPE_USER_GROUP) {
                        $keys[$forme($grant->subject)] = true;
                    }
                }
            }
            $keys = array_keys($keys);
            sort($keys);

            return $keys;
        };
        $this->assertSame($audienceSubjects($aTrois), $audienceSubjects($aTroisCents));

        // Seuls les nœuds PAR MEMBRE varient avec l'effectif — et ils coûtent une
        // entrée par nœud, jamais une audience entière sur un même nœud.
        $this->assertCount(5 + 2, $aTrois->nodes, '5 nœuds fixes (racine comprise) + 2 élèves');
        $this->assertCount(5 + 292, $aTroisCents->nodes, '5 nœuds fixes (racine comprise) + 292 élèves');
    }

    #[Test]
    public function each_listed_edge_role_yields_exactly_one_abstract_subject(): void
    {
        // La recette SEEDÉE ne liste qu'un rôle d'arête (arbitrage 60.5 : le
        // surensemble est déjà dans l'annuaire). Le MÉCANISME, lui, doit valoir
        // pour n'importe quelle liste : on l'éprouve donc sur une variante à deux
        // rôles, plutôt que de compter sur ce que la recette du jour se trouve
        // contenir — une propriété du mécanisme ne doit pas dépendre d'une donnée.
        $template = $this->autoResolvableClassTreeTemplate();
        $roles = $template->roles_spec;
        $roles[0]['resolution']['edge_roles'] = ['manager', 'owner'];
        $template->roles_spec = $roles;
        $template->save();

        $group = $this->withMembers($this->classGroup('3emeA'), ['manager' => 1, 'owner' => 1]);

        $subjects = $this->subjectsOf($this->service->planFor($group), 'equipe');

        $this->assertCount(2, $subjects);
        foreach ($subjects as $subject) {
            $this->assertSame(PlanSubject::TYPE_USER_GROUP, $subject->type);
            $this->assertSame($group->id, $subject->id, 'le sujet désigne LE GROUPE, qualifié d\'un rôle d\'arête');
        }
        $this->assertSame(['manager', 'owner'], array_map(static fn (PlanSubject $s): string => (string) $s->edgeRole, $subjects));
    }

    #[Test]
    public function an_audience_is_emitted_even_when_nobody_carries_the_role(): void
    {
        // Le sujet est STRUCTUREL, pas un effectif : une classe sans professeur
        // principal cette année garde son audience « propriétaires », vide
        // aujourd'hui, peuplée demain, sans réécriture de plan.
        $this->autoResolvableClassTreeTemplate()->save();
        $group = $this->withMembers($this->classGroup('3emeA'), ['member' => 2]);

        $subjects = $this->subjectsOf($this->service->planFor($group), 'equipe');

        $this->assertCount(1, $subjects);
        $this->assertSame('manager', (string) $subjects[0]->edgeRole);
    }

    #[Test]
    public function no_role_audience_is_ever_a_list_of_users(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();
        $group = $this->withMembers($this->classGroup('3emeA'), ['manager' => 2, 'owner' => 1, 'member' => 5]);

        $plan = $this->service->planFor($group);
        $this->assertNotNull($plan);

        foreach ($plan->roles as $roleKey => $subjects) {
            foreach ($subjects as $subject) {
                $this->assertSame(
                    PlanSubject::TYPE_USER_GROUP,
                    $subject->type,
                    sprintf('le rôle « %s » a été résolu en sujets utilisateur : c\'est une audience énumérée', $roleKey),
                );
            }
        }

        // La SEULE énumération nominative du système reste celle des nœuds par
        // membre : un sujet utilisateur par nœud, jamais une audience.
        $nominatifs = [];
        foreach ($plan->nodes as $node) {
            foreach ($node->grants as $grant) {
                if ($grant->subject->type === PlanSubject::TYPE_USER) {
                    $nominatifs[$node->path][] = $grant->subject->id;
                }
            }
        }
        $this->assertCount(5, $nominatifs, 'un nœud par élève');
        foreach ($nominatifs as $path => $ids) {
            $this->assertCount(1, $ids, 'plus d\'un sujet utilisateur sur « ' . $path . ' » : c\'est une audience');
        }
    }

    #[Test]
    public function a_designated_user_target_stays_perfectly_legitimate(): void
    {
        // NON-RÉGRESSION NOMMÉE (revue 60.1, finding #1). Le correctif « rejeter
        // les sujets utilisateur en bloc » casserait cette recette LIVRÉE : deux
        // rôles de maille utilisateur, cardinalité un. La garde de la mesure porte
        // sur l'ÉNUMÉRATION d'une audience, pas sur le type du sujet.
        $template = new DirectoryTemplate([
            'key' => DirectoryTemplate::KEY_USER_TO_USER,
            'label' => 'Utilisateur ↔ utilisateur',
            'roles_spec' => [
                ['key' => 'user_a', 'label' => 'A', 'maille' => User::class, 'group_type' => null, 'access' => 'rw', 'cardinality' => 'one'],
                ['key' => 'user_b', 'label' => 'B', 'maille' => User::class, 'group_type' => null, 'access' => 'rw', 'cardinality' => 'one'],
            ],
            'path_pattern' => 'Echanges/{group.bare_name}',
            'nodes_spec' => [[
                'path' => '_commun',
                'label' => 'Espace commun',
                'nature' => 'partagee',
                'grants' => [
                    ['role' => 'user_a', 'access' => 'rw'],
                    ['role' => 'user_b', 'access' => 'rw'],
                ],
            ]],
        ]);

        $group = $this->classGroup('Echange1', 'custom');
        $alice = User::factory()->create(['login' => 'alecoz']);
        $bruno = User::factory()->create(['login' => 'bmartin']);

        $plan = $this->service->planUsing($group, $template, [
            'user_a' => [PlanSubject::user((int) $alice->id)],
            'user_b' => PlanSubject::user((int) $bruno->id),
        ]);

        $this->assertSame([PlanSubject::TYPE_USER], array_values(array_unique(
            array_map(static fn (PlanSubject $s): string => $s->type, $plan->roles['user_a'])
        )));
        $this->assertSame((int) $bruno->id, $plan->roles['user_b'][0]->id);
    }

    #[Test]
    public function a_cardinality_of_one_refuses_a_second_designated_target(): void
    {
        $template = new DirectoryTemplate([
            'key' => 'un_seul',
            'label' => 'Un seul',
            'roles_spec' => [
                ['key' => 'cible', 'label' => 'Cible', 'maille' => User::class, 'group_type' => null, 'access' => 'rw', 'cardinality' => 'one'],
            ],
            'path_pattern' => 'Echanges/{group.bare_name}',
            'nodes_spec' => [[
                'path' => '_commun', 'label' => 'Commun', 'nature' => 'partagee',
                'grants' => [['role' => 'cible', 'access' => 'rw']],
            ]],
        ]);

        $group = $this->classGroup('Echange1', 'custom');

        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/une seule cible/u');

        $this->service->planUsing($group, $template, [
            'cible' => [PlanSubject::user(1), PlanSubject::user(2)],
        ]);
    }

    #[Test]
    public function a_designated_target_must_be_an_internal_identity(): void
    {
        $template = new DirectoryTemplate([
            'key' => 'un_seul',
            'label' => 'Un seul',
            'roles_spec' => [
                ['key' => 'cible', 'label' => 'Cible', 'maille' => User::class, 'group_type' => null, 'access' => 'rw', 'cardinality' => 'many'],
            ],
            'path_pattern' => 'Echanges/{group.bare_name}',
            'nodes_spec' => [[
                'path' => '_commun', 'label' => 'Commun', 'nature' => 'partagee',
                'grants' => [['role' => 'cible', 'access' => 'rw']],
            ]],
        ]);

        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/identité interne/u');

        $this->service->planUsing($this->classGroup('E', 'custom'), $template, ['cible' => ['alecoz']]);
    }

    // =========================================================================
    // AC3 — les stratégies `self` et `pattern`
    // =========================================================================

    #[Test]
    public function the_self_strategy_designates_the_whole_materialization_group(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();
        $group = $this->withMembers($this->classGroup('3emeA'), ['member' => 2]);

        $subjects = $this->subjectsOf($this->service->planFor($group), 'classe');

        $this->assertCount(1, $subjects);
        $this->assertSame($group->id, $subjects[0]->id);
        $this->assertNull($subjects[0]->edgeRole, 'sans qualification d\'arête : tout le groupe');
    }

    #[Test]
    public function the_pattern_strategy_finds_a_related_group_in_sql(): void
    {
        $related = UserGroup::create(['name' => 'Projet_Arts', 'type' => 'projet']);
        $group = $this->classGroup('Arts', 'custom');

        $plan = $this->service->planUsing($group, $this->patternTemplate('Projet_{group.bare_name}'));

        $this->assertSame([$related->id], array_map(
            static fn (PlanSubject $s): int => $s->id,
            $plan->roles['apparente'],
        ));
    }

    #[Test]
    public function the_related_group_lookup_is_case_insensitive_like_the_rest_of_the_domain(): void
    {
        $related = UserGroup::create(['name' => 'projet_arts', 'type' => 'projet']);

        $plan = $this->service->planUsing(
            $this->classGroup('Arts', 'custom'),
            $this->patternTemplate('Projet_{group.bare_name}'),
        );

        $this->assertSame($related->id, $plan->roles['apparente'][0]->id);
    }

    #[Test]
    public function a_missing_related_group_fails_explicitly_and_names_what_was_expected(): void
    {
        // Fail-closed, même doctrine que le pré-contrôle du chemin figé : jamais
        // un plan partiel, qui se comparerait « conforme » à un état incomplet.
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/Projet_Arts/u');

        $this->service->planUsing(
            $this->classGroup('Arts', 'custom'),
            $this->patternTemplate('Projet_{group.bare_name}'),
        );
    }

    #[Test]
    public function an_unknown_placeholder_in_a_name_pattern_is_refused_by_the_recipe(): void
    {
        $this->expectException(\App\Exceptions\Filesystem\InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/placeholder inconnu/u');

        $this->service->planUsing(
            $this->classGroup('Arts', 'custom'),
            $this->patternTemplate('Projet_{group.inconnu}'),
        );
    }

    private function patternTemplate(string $pattern): DirectoryTemplate
    {
        return new DirectoryTemplate([
            'key' => 'apparente',
            'label' => 'Apparenté par motif',
            'roles_spec' => [[
                'key' => 'apparente',
                'label' => 'Groupe apparenté',
                'maille' => UserGroup::class,
                'group_type' => null,
                'access' => 'rw',
                'cardinality' => 'one',
                'resolution' => ['strategy' => 'pattern', 'pattern' => $pattern],
            ]],
            'path_pattern' => 'Espaces/{group.bare_name}',
            'nodes_spec' => [[
                'path' => '_commun',
                'label' => 'Espace commun',
                'nature' => 'partagee',
                'grants' => [['role' => 'apparente', 'access' => 'rw']],
            ]],
        ]);
    }

    // =========================================================================
    // Lecture des appartenances
    // =========================================================================

    #[Test]
    public function only_the_edge_role_is_read_never_the_stale_head_teacher_flag(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();

        $group = $this->classGroup('3emeA');
        $prof = User::factory()->create(['login' => 'pdupont', 'role' => 'prof']);
        $eleve = User::factory()->create(['login' => 'eleve01']);

        // Le drapeau historique dit « professeur principal », l'arête dit
        // « membre » : c'est l'ARÊTE qui gouverne, le drapeau est mort depuis 42.2.
        $group->users()->attach($prof->id, ['role' => 'member', 'is_head_teacher' => true]);
        $group->users()->attach($eleve->id, ['role' => 'member']);

        $plan = $this->service->planFor($group->fresh(['users']));
        $this->assertNotNull($plan);

        $paths = array_map(static fn ($n): string => $n->path, $plan->nodes);
        $this->assertContains('pdupont', $paths, 'l\'arête « member » lui vaut un dossier personnel');
        $this->assertContains('eleve01', $paths);
    }

    #[Test]
    public function a_dirty_edge_role_is_read_as_the_least_endowed_one(): void
    {
        // Donnée héritée : même normalisation que les écrans de groupes depuis
        // 42.3. Refuser tout le plan pour une ligne sale rendrait la chaîne
        // inutilisable sur les instances en place, sans rien protéger.
        $this->autoResolvableClassTreeTemplate()->save();

        $group = $this->classGroup('3emeA');
        $user = User::factory()->create(['login' => 'sale01']);
        $group->users()->attach($user->id, ['role' => '']);

        $plan = $this->service->planFor($group->fresh(['users']));
        $this->assertNotNull($plan);

        $this->assertContains('sale01', array_map(static fn ($n): string => $n->path, $plan->nodes));
    }

    #[Test]
    public function a_federated_member_never_earns_a_personal_node(): void
    {
        // Un compte fédéré n'est JAMAIS synchronisé vers l'annuaire : ni identité
        // d'annuaire, ni compte système. Or ces membres ne servent QU'À fabriquer
        // des nœuds personnels et des octrois nominatifs. En laisser passer un
        // produirait, à l'exécution, une entrée d'accès visant un compte
        // inexistant — argument invalide, et le partage à moitié posé.
        //
        // Et ce n'est pas hypothétique : le picker de l'écran de groupe ne filtre
        // pas la source, donc ce rattachement est faisable aujourd'hui.
        $this->autoResolvableClassTreeTemplate()->save();

        $group = $this->classGroup('3emeA');
        $reel = User::factory()->create(['login' => 'eleve01', 'source' => 'ad']);
        $federe = User::factory()->create(['login' => 'ext01', 'source' => 'federated']);
        $group->users()->attach($reel->id, ['role' => 'member']);
        $group->users()->attach($federe->id, ['role' => 'member']);

        $plan = $this->service->planFor($group->fresh(['users']));
        $this->assertNotNull($plan);

        $paths = array_map(static fn ($n): string => $n->path, $plan->nodes);
        $this->assertContains('eleve01', $paths, 'le membre réel garde son dossier personnel');
        $this->assertNotContains('ext01', $paths, 'un compte fédéré ne doit AUCUN nœud personnel');

        // Et aucun octroi nominatif du plan ne vise son identité interne — c'est
        // ce sujet-là qui deviendrait une entrée d'accès vers un compte système
        // inexistant.
        $nominativeIds = [];
        foreach ($plan->nodes as $node) {
            foreach ($node->grants as $grant) {
                if ($grant->subject->type === PlanSubject::TYPE_USER) {
                    $nominativeIds[] = $grant->subject->id;
                }
            }
        }

        $this->assertContains($reel->id, $nominativeIds);
        $this->assertNotContains($federe->id, $nominativeIds, 'aucun octroi nominatif ne peut viser un compte fédéré');
    }

    #[Test]
    public function the_attachment_matches_whatever_the_case(): void
    {
        // L'accrochage s'apparie à `user_groups.type`. Un désaccord de casse
        // rendrait `null` — c'est-à-dire « pas de recette », l'état NORMAL d'un
        // type qui n'en a pas : l'échec serait indiscernable d'une absence
        // légitime. La résolution par motif compare déjà en minuscules ; ici
        // aussi, et la valeur est normalisée à l'écriture.
        $template = $this->autoResolvableClassTreeTemplate();
        $template->attached_group_type = 'Classe';
        $template->save();

        $this->assertSame('classe', $template->fresh()->attached_group_type);
        $this->assertNotNull(DirectoryTemplate::attachedTo('classe'));
        $this->assertNotNull(DirectoryTemplate::attachedTo('CLASSE'));
        $this->assertNotNull($this->service->planFor($this->classGroup('3emeA', 'classe')));
    }

    // =========================================================================
    // AC6 — la maille « matière × classe », de bout en bout
    // =========================================================================

    #[Test]
    public function a_matiere_classe_group_resolves_through_the_whole_chain(): void
    {
        $template = new DirectoryTemplate([
            'key' => 'matiere_share',
            'label' => 'Partage de matière',
            'attached_group_type' => 'matiere_classe',
            'roles_spec' => [[
                'key' => 'groupe',
                'label' => 'Le groupe matière×classe',
                'maille' => UserGroup::class,
                'group_type' => 'matiere_classe',
                'access' => 'rw',
                'cardinality' => 'one',
                'resolution' => ['strategy' => 'self'],
            ]],
            'path_pattern' => 'Matieres/{group.classe}/{group.matiere}',
            'nodes_spec' => [[
                'path' => '_travail',
                'label' => 'Documents de travail',
                'nature' => 'partagee',
                'grants' => [['role' => 'groupe', 'access' => 'rw']],
            ]],
        ]);
        $template->save();

        $group = UserGroup::create(['name' => 'Matiere_Math@3emeA', 'type' => 'matiere_classe']);

        $plan = $this->service->planFor($group);

        $this->assertNotNull($plan);
        $this->assertSame('Matieres/3emeA/Math', $plan->rootPath);

        // La garde de neutralité vaut AUSSI pour ce plan-là : la décomposition
        // n'introduit aucun terme du monde d'en dessous.
        $this->assertPlanIsNeutral($plan, 'plan matière×classe');
    }

    // =========================================================================
    // AC8 — la garde de neutralité, exercée sur un plan du NOUVEAU service
    // =========================================================================

    #[Test]
    public function a_plan_produced_by_the_whole_chain_stays_neutral(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();
        $group = $this->withMembers($this->classGroup('3emeA'), ['manager' => 1, 'owner' => 1, 'member' => 3]);

        $plan = $this->service->planFor($group);
        $this->assertNotNull($plan);

        // Le plan doit être REPRÉSENTATIF, sinon la garde ne garde rien : quatre
        // natures, des sujets d'arête, des octrois nominatifs.
        $natures = array_values(array_unique(array_map(static fn ($n): string => $n->nature->value, $plan->nodes)));
        sort($natures);
        $this->assertSame(['activable', 'contenu_libre', 'par_membre', 'partagee'], $natures);
        $this->assertCount(1, $this->subjectsOf($plan, 'equipe'));

        $this->assertPlanIsNeutral($plan, 'plan issu de la chaîne complète');

        // Et aucun chemin absolu, comme pour le décor de la story 60.1.
        $offenders = [];
        $serializable = $plan->toArray();
        array_walk_recursive($serializable, static function (mixed $value) use (&$offenders): void {
            if (is_string($value) && str_starts_with($value, '/')) {
                $offenders[] = $value;
            }
        });
        $this->assertSame([], $offenders);
    }

    #[Test]
    public function an_attached_recipe_that_stopped_being_auto_resolvable_is_refused_at_read_time(): void
    {
        // L'accrochage peut arriver par un chemin qui ne passe pas par le modèle
        // (import, correction manuelle en base) : on revérifie à la LECTURE.
        $template = $this->autoResolvableClassTreeTemplate();
        $template->save();

        $roles = $template->roles_spec;
        unset($roles[0]['resolution']);
        \Illuminate\Support\Facades\DB::table('directory_templates')
            ->where('id', $template->id)
            ->update(['roles_spec' => json_encode($roles)]);

        $this->expectException(\App\Exceptions\Filesystem\InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/matérialisation/u');

        $this->service->planFor($this->classGroup('3emeA'));
    }
}
