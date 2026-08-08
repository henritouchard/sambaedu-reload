<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Plan;

use App\Models\NetworkShareAssignable;
use App\Models\Pivot\UserGroupUserPivot;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\ShareService;
use App\Support\RoleCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.1 — le helper de nommage du plan, et son ÉQUIVALENCE avec ce qui
 * existe déjà sous la ligne de contrat.
 *
 * Le namespace du plan n'importe aucun service d'exécution — c'est verrouillé par
 * un test d'architecture. La contrepartie de cette coupe est une recopie : le
 * dé-préfixage, le motif de segment, le vocabulaire de rôle d'arête et le
 * vocabulaire d'accès sont redéclarés. Une recopie non surveillée dérive ; ce
 * fichier est la surveillance.
 *
 * C'est ICI, et seulement ici, que les deux mondes se rencontrent : le test a le
 * droit d'importer les deux côtés, la production non.
 */
class GroupNameNormalizerTest extends TestCase
{
    // =========================================================================
    // Équivalences ÉPINGLÉES (le prix de la coupe)
    // =========================================================================

    /**
     * @return list<array{0:string}>
     */
    public static function classNames(): array
    {
        return [
            ['Classe_3emeA'],      // le cas nominal : casse préservée
            ['classe_3emeA'],      // préfixe en minuscules (annuaire hérité)
            ['CLASSE_3emeA'],
            ['3emeA'],             // déjà dé-préfixé
            ['Classe_Classe_X'],   // piège du double préfixe : un SEUL retrait
            ['Classe_6e-2'],
            ['Classe_6e.2'],
            ['.cachee'],           // refusé : premier caractère « . »
            ['Classe_.cachee'],    // refusé après dé-préfixage
            ['Classe_3eme A'],     // refusé : espace
            ['Classe_3eme/A'],     // refusé : séparateur
            ['Classe_'],           // refusé : vide après dé-préfixage
            ['Matiere_Maths@6A'],  // refusé : le « @ » n'est pas un segment sûr
        ];
    }

    #[Test]
    #[DataProvider('classNames')]
    public function bare_name_behaves_exactly_like_the_historical_implementation(string $rawName): void
    {
        $historical = app(ShareService::class)->bareClassName($rawName);

        $this->assertSame(
            $historical,
            GroupNameNormalizer::bareName($rawName, 'classe'),
            'La recopie a dérivé de l\'implémentation historique sur « ' . $rawName . ' ».',
        );
    }

    #[Test]
    public function the_segment_pattern_is_the_very_pattern_used_below_the_line(): void
    {
        $this->assertSame(
            NetworkShareService::DIRECTORY_NAME_PATTERN,
            GroupNameNormalizer::SEGMENT_PATTERN,
            'Le motif de segment du plan doit rester identique à celui du provisioning.',
        );
    }

    /**
     * Story 62.1 — l'ÉQUIVALENCE ÉPINGLÉE a changé de nature, pas d'objet.
     *
     * Le vocabulaire de rôle d'arête n'est plus une constante recopiée : c'est un
     * catalogue administrable, et le plan le reçoit par injection. Ce qui reste à
     * surveiller, c'est que les DEUX LECTURES disent la même chose — sinon on a
     * simplement remplacé une recopie qui pouvait dériver par une couture qui le
     * peut aussi.
     *
     * Le REPLI du normalizer (aucun résolveur installé) doit valoir exactement le
     * plancher historique ; avec le résolveur installé, il doit valoir le
     * catalogue.
     */
    #[Test]
    public function the_edge_role_vocabulary_is_the_stored_one(): void
    {
        // Avec le résolveur installé au boot : le catalogue fait foi, et il est
        // identique à ce que le pivot expose.
        $this->assertSame(
            array_values(UserGroupUserPivot::roles()),
            GroupNameNormalizer::edgeRoles(),
            'Le vocabulaire de rôle d\'arête du plan doit rester celui qui est STOCKÉ.',
        );

        // Sans résolveur (tests purs du namespace, application non démarrée) : le
        // repli littéral vaut le plancher historique, ni plus ni moins.
        try {
            GroupNameNormalizer::useEdgeRoles(null);

            $this->assertSame(
                RoleCatalog::HISTORICAL_KEYS,
                GroupNameNormalizer::edgeRoles(),
                'Le repli du normalizer doit valoir exactement le plancher historique.',
            );
        } finally {
            GroupNameNormalizer::useEdgeRoles(static fn (): array => RoleCatalog::keys());
        }
    }

    /**
     * Story 62.1 — un rôle NOUVEAU du catalogue traverse le plan sans rejet, dès
     * lors que le résolveur runtime est installé.
     */
    #[Test]
    public function a_new_catalogued_role_is_known_to_the_plan(): void
    {
        $this->assertFalse(GroupNameNormalizer::isKnownEdgeRole('tuteur'));

        try {
            GroupNameNormalizer::useEdgeRoles(static fn (): array => ['member', 'manager', 'owner', 'tuteur']);

            $this->assertTrue(GroupNameNormalizer::isKnownEdgeRole('tuteur'));
            $this->assertTrue(GroupNameNormalizer::isKnownEdgeRole('member'));
            $this->assertFalse(GroupNameNormalizer::isKnownEdgeRole('inconnu'));
        } finally {
            GroupNameNormalizer::useEdgeRoles(static fn (): array => RoleCatalog::keys());
        }
    }

    /**
     * Une source qui rendrait une liste VIDE ne doit pas vider le vocabulaire :
     * elle refuserait `member`, donc toute arête, donc tout plan.
     */
    #[Test]
    public function an_empty_resolver_falls_back_instead_of_emptying_the_vocabulary(): void
    {
        try {
            GroupNameNormalizer::useEdgeRoles(static fn (): array => []);

            $this->assertSame(RoleCatalog::HISTORICAL_KEYS, GroupNameNormalizer::edgeRoles());
            $this->assertTrue(GroupNameNormalizer::isKnownEdgeRole('member'));
        } finally {
            GroupNameNormalizer::useEdgeRoles(static fn (): array => RoleCatalog::keys());
        }
    }

    /**
     * Story 62.4 — L'ÉPINGLE RETOURNÉE.
     *
     * Elle affirmait, depuis 60.1, que le plan RÉUTILISAIT le vocabulaire binaire
     * des assignations. Ce n'est plus vrai, et la supprimer aurait laissé le
     * nouveau monde sans témoin : elle affirme donc désormais l'inverse, et la
     * propriété qui le rend tenable.
     *
     *  - les deux vocabulaires sont DISJOINTS : le plan parle verbes, l'assignation
     *    parle deux niveaux, et aucune valeur de l'un n'est une valeur de l'autre —
     *    une confusion de vocabulaire ne peut plus passer inaperçue ;
     *  - la traduction est TOTALE dans les deux sens : toute valeur d'un côté a une
     *    image de l'autre. C'est ce qui garantit qu'aucune valeur ne « tombe »
     *    silencieusement à la frontière.
     */
    #[Test]
    public function the_plan_speaks_verbs_and_the_assignment_stays_binary(): void
    {
        $binary = [NetworkShareAssignable::ACCESS_RO, NetworkShareAssignable::ACCESS_RW];

        $this->assertSame(
            [],
            array_intersect($binary, PlanGrant::VERBS),
            'les deux vocabulaires doivent rester DISJOINTS : sinon une valeur mal placée passerait inaperçue',
        );

        // assignation → plan : les deux niveaux ont une image, et elles diffèrent.
        $toPlan = [
            NetworkShareAssignable::ACCESS_RO => [PlanGrant::VERB_LIRE],
            NetworkShareAssignable::ACCESS_RW => PlanGrant::VERBS,
        ];
        $this->assertSame($binary, array_keys($toPlan), 'la traduction doit couvrir TOUT le vocabulaire binaire');
        $this->assertNotSame($toPlan[NetworkShareAssignable::ACCESS_RO], $toPlan[NetworkShareAssignable::ACCESS_RW]);

        // plan → assignation : les 15 combinaisons non vides ont toutes une image.
        $images = [];
        foreach ($this->everyVerbCombination() as $verbs) {
            $images[] = array_intersect(PlanGrant::MUTATION_VERBS, $verbs) !== []
                ? NetworkShareAssignable::ACCESS_RW
                : NetworkShareAssignable::ACCESS_RO;
        }
        $this->assertCount(15, $images, 'les 15 combinaisons non vides doivent toutes être traduites');
        $seen = array_values(array_unique($images));
        sort($seen);
        $this->assertSame($binary, $seen, 'les DEUX niveaux doivent être atteints : la traduction est SURJECTIVE');
    }

    /**
     * Les 15 combinaisons NON VIDES des quatre verbes. Le vide n'en est pas une :
     * ce n'est pas un octroi.
     *
     * @return list<list<string>>
     */
    private function everyVerbCombination(): array
    {
        $out = [];
        for ($mask = 1; $mask < 16; $mask++) {
            $verbs = [];
            foreach (PlanGrant::VERBS as $index => $verb) {
                if (($mask & (1 << $index)) !== 0) {
                    $verbs[] = $verb;
                }
            }
            $out[] = $verbs;
        }

        return $out;
    }

    // =========================================================================
    // Comportement propre du helper
    // =========================================================================

    #[Test]
    public function a_type_prefix_is_stripped_case_insensitively_and_case_is_preserved(): void
    {
        $this->assertSame('3emeA', GroupNameNormalizer::bareName('Classe_3emeA', 'classe'));
        $this->assertSame('3emeA', GroupNameNormalizer::bareName('CLASSE_3emeA', 'classe'));
        $this->assertSame('3emeA', GroupNameNormalizer::bareName('Equipe_3emeA', 'equipe'));
        $this->assertSame('3emeA', GroupNameNormalizer::bareName('PP_3emeA', 'equipe'));
        $this->assertSame('Arts', GroupNameNormalizer::bareName('Projet_Arts', 'projet'));
    }

    #[Test]
    public function a_prefix_that_does_not_belong_to_the_type_is_not_a_prefix(): void
    {
        // On ne devine pas : « Classe_X » sur un groupe qui n'est pas une classe
        // est un NOM, pas un préfixe. Le dé-préfixage silencieux d'un nom légitime
        // fabriquerait deux groupes distincts au même chemin.
        $this->assertSame('Classe_X', GroupNameNormalizer::bareName('Classe_X', 'equipe'));
        $this->assertSame('Classe_X', GroupNameNormalizer::bareName('Classe_X', null));
        $this->assertSame('Classe_X', GroupNameNormalizer::bareName('Classe_X', 'inconnu'));
    }

    #[Test]
    public function only_one_prefix_is_ever_stripped(): void
    {
        $this->assertSame('Classe_X', GroupNameNormalizer::bareName('Classe_Classe_X', 'classe'));
    }

    #[Test]
    public function unsafe_segments_are_rejected(): void
    {
        foreach (['', '.cache', 'a b', 'a/b', 'a;b', '..', '.', 'a$b', 'a`b'] as $segment) {
            $this->assertFalse(GroupNameNormalizer::isSafeSegment($segment), 'segment accepté à tort : ' . $segment);
        }
    }

    #[Test]
    public function safe_relative_paths_are_relative_and_traversal_free(): void
    {
        $this->assertTrue(GroupNameNormalizer::isSafeRelativePath('Classes/Classe_3emeA'));
        $this->assertTrue(GroupNameNormalizer::isSafeRelativePath('_travail/devoirs'));

        foreach (['/absolu', '/var/quelque-chose', 'a//b', '../evasion', 'a/../b', 'a/./b', '', 'a/'] as $path) {
            $this->assertFalse(GroupNameNormalizer::isSafeRelativePath($path), 'chemin accepté à tort : ' . $path);
        }
    }

    // =========================================================================
    // Story 60.3 — la RACINE devient un chemin de nœud valide, et rien d'autre
    // ne change
    // =========================================================================

    #[Test]
    public function the_root_token_is_a_valid_node_path(): void
    {
        $this->assertTrue(GroupNameNormalizer::isSafeNodePath(GroupNameNormalizer::ROOT_NODE_PATH));
        $this->assertTrue(GroupNameNormalizer::isSafeNodePath('_profs'));
        $this->assertTrue(GroupNameNormalizer::isSafeNodePath('_travail/devoirs'));
    }

    /**
     * Le jeton racine vaut ENTIER, jamais en morceau : l'ouverture porte sur la
     * position racine, pas sur le caractère.
     */
    #[Test]
    public function the_root_token_is_only_valid_whole_never_as_a_segment(): void
    {
        foreach (['./x', 'a/./b', './', '..', '.cache', 'a/.', '/.', ''] as $path) {
            $this->assertFalse(
                GroupNameNormalizer::isSafeNodePath($path),
                'chemin de nœud accepté à tort : ' . $path,
            );
        }
    }

    /**
     * La RACINE D'UN PLAN, elle, n'a pas bougé : un plan enraciné sur « le
     * dossier courant » serait un chemin non résolu déguisé. C'est exactement la
     * raison pour laquelle le prédicat de nœud est SÉPARÉ.
     */
    #[Test]
    public function the_plan_root_predicate_still_refuses_the_root_token(): void
    {
        $this->assertFalse(GroupNameNormalizer::isSafeRelativePath(GroupNameNormalizer::ROOT_NODE_PATH));
        $this->assertFalse(GroupNameNormalizer::isSafeSegment(GroupNameNormalizer::ROOT_NODE_PATH));
    }

    #[Test]
    public function edge_roles_are_a_closed_vocabulary(): void
    {
        $this->assertTrue(GroupNameNormalizer::isKnownEdgeRole('member'));
        $this->assertTrue(GroupNameNormalizer::isKnownEdgeRole('owner'));
        $this->assertFalse(GroupNameNormalizer::isKnownEdgeRole('prof_principal'));
        $this->assertFalse(GroupNameNormalizer::isKnownEdgeRole(null));
        $this->assertFalse(GroupNameNormalizer::isKnownEdgeRole(42));
    }

    // =========================================================================
    // Story 60.2 — la décomposition « matière × classe »
    // =========================================================================

    #[Test]
    public function a_matiere_classe_name_decomposes_into_two_safe_segments(): void
    {
        $this->assertSame(
            ['matiere' => 'Math', 'classe' => '3emeA'],
            GroupNameNormalizer::matiereClasseParts('Matiere_Math@3emeA'),
        );
    }

    #[Test]
    public function the_type_prefix_is_stripped_case_insensitively_and_is_optional(): void
    {
        $expected = ['matiere' => 'Maths', 'classe' => '6A'];

        $this->assertSame($expected, GroupNameNormalizer::matiereClasseParts('Matiere_Maths@6A'));
        $this->assertSame($expected, GroupNameNormalizer::matiereClasseParts('matiere_Maths@6A'), 'annuaire hérité en minuscules');
        $this->assertSame($expected, GroupNameNormalizer::matiereClasseParts('Maths@6A'), 'nom déjà dé-préfixé');
    }

    #[Test]
    public function the_case_of_each_half_is_preserved(): void
    {
        $this->assertSame(
            ['matiere' => 'Physique-Chimie', 'classe' => '3emeA'],
            GroupNameNormalizer::matiereClasseParts('Matiere_Physique-Chimie@3emeA'),
        );
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public static function undecomposableNames(): array
    {
        return [
            ['Matiere_Maths', 'aucun « @ » : rien à décomposer, on ne devine pas la classe'],
            ['Matiere_A@B@C', 'deux « @ » : découper demanderait de choisir un côté'],
            ['Matiere_@6A', 'moitié gauche vide'],
            ['Matiere_Maths@', 'moitié droite vide'],
            ['Matiere_Ma ths@6A', 'espace : la moitié gauche n\'est pas un segment sûr'],
            ['Matiere_Maths@6/A', 'séparateur de chemin dans la moitié droite'],
            ['Matiere_.cachee@6A', 'point en premier caractère'],
            ['Matiere_', 'nom réduit à son seul préfixe'],
            ['', 'nom vide'],
        ];
    }

    #[Test]
    #[DataProvider('undecomposableNames')]
    public function an_undecomposable_name_yields_null_never_a_guess(string $rawName, string $why): void
    {
        $this->assertNull(GroupNameNormalizer::matiereClasseParts($rawName), $why);
    }

    #[Test]
    public function the_bare_name_of_a_matiere_classe_group_still_refuses_the_at_sign(): void
    {
        // Le « @ » n'entre JAMAIS dans un segment de chemin : c'est la garantie
        // que le motif de segment reste la copie exacte de celui du provisioning.
        // La décomposition est la seule porte de sortie, et elle est explicite.
        $this->assertNull(GroupNameNormalizer::bareName('Matiere_Maths@6A', 'matiere_classe'));
        $this->assertFalse(GroupNameNormalizer::isSafeSegment('Maths@6A'));
    }

    #[Test]
    public function the_decomposition_never_loses_information(): void
    {
        $parts = GroupNameNormalizer::matiereClasseParts('Matiere_Math@6A');
        $homonyme = GroupNameNormalizer::bareName('Matiere_Math-6A', 'matiere_classe');

        // La normalisation naïve du séparateur (« @ » → « - ») produirait
        // EXACTEMENT le segment d'un AUTRE groupe, réellement nommé « Math-6A ».
        // Voilà la perte qu'on refuse : deux groupes distincts, un seul chemin, et
        // plus aucun moyen de remonter du chemin au groupe.
        $this->assertSame($homonyme, str_replace('@', '-', 'Math@6A'));

        // La décomposition garde les deux moitiés séparées : rien ne collisionne.
        $this->assertSame(['matiere' => 'Math', 'classe' => '6A'], $parts);
    }
}
