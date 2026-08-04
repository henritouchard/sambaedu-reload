<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Plan;

use App\Models\NetworkShareAssignable;
use App\Models\Pivot\UserGroupUserPivot;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\ShareService;
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

    #[Test]
    public function the_edge_role_vocabulary_is_the_stored_one(): void
    {
        $this->assertSame(
            array_values(UserGroupUserPivot::ROLES),
            GroupNameNormalizer::EDGE_ROLES,
            'Le vocabulaire de rôle d\'arête du plan doit rester celui qui est STOCKÉ.',
        );
    }

    #[Test]
    public function the_access_vocabulary_is_the_existing_neutral_one(): void
    {
        // Le plan réutilise le vocabulaire NEUTRE déjà en service (`ro|rw`) — il
        // n'invente pas un dialecte, et il n'emprunte surtout aucun mode système.
        $this->assertSame(NetworkShareAssignable::ACCESS_RO, PlanGrant::ACCESS_RO);
        $this->assertSame(NetworkShareAssignable::ACCESS_RW, PlanGrant::ACCESS_RW);
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

    #[Test]
    public function edge_roles_are_a_closed_vocabulary(): void
    {
        $this->assertTrue(GroupNameNormalizer::isKnownEdgeRole('member'));
        $this->assertTrue(GroupNameNormalizer::isKnownEdgeRole('owner'));
        $this->assertFalse(GroupNameNormalizer::isKnownEdgeRole('prof_principal'));
        $this->assertFalse(GroupNameNormalizer::isKnownEdgeRole(null));
        $this->assertFalse(GroupNameNormalizer::isKnownEdgeRole(42));
    }
}
