<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Pivot\UserGroupUserPivot;
use App\Support\EdgeRoleLabels;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 60.2 — la table CANONIQUE des libellés de rôle d'arête.
 *
 * Classe pure : `PHPUnit\Framework\TestCase` nu, aucune application à démarrer.
 */
class EdgeRoleLabelsTest extends TestCase
{
    #[Test]
    public function a_class_reads_its_edge_roles_in_school_terms(): void
    {
        $this->assertSame('Élève', EdgeRoleLabels::label('classe', 'member'));
        $this->assertSame('Enseignant', EdgeRoleLabels::label('classe', 'manager'));
        $this->assertSame('Professeur principal', EdgeRoleLabels::label('classe', 'owner'));
    }

    #[Test]
    public function the_same_stored_role_reads_differently_by_group_type(): void
    {
        // C'est TOUT l'objet de la table : une seule valeur stockée, trois
        // lectures métier. Avant, trois écrans disaient « Prof » partout.
        $this->assertSame('Enseignant', EdgeRoleLabels::label('classe', 'manager'));
        $this->assertSame('Porteur', EdgeRoleLabels::label('projet', 'manager'));
        $this->assertSame('Référent', EdgeRoleLabels::label('equipe', 'manager'));
    }

    #[Test]
    public function an_untranslated_type_falls_back_to_neutral_labels(): void
    {
        foreach ([null, '', 'cours', 'matiere', 'matiere_classe', 'custom', 'inconnu'] as $type) {
            $this->assertSame('Membre', EdgeRoleLabels::label($type, 'member'));
            $this->assertSame('Gestionnaire', EdgeRoleLabels::label($type, 'manager'));
            $this->assertSame('Propriétaire', EdgeRoleLabels::label($type, 'owner'));
        }
    }

    #[Test]
    public function a_partially_translated_type_keeps_the_generic_fallback_for_the_rest(): void
    {
        // `projet` ne tranche que `manager` : les deux autres rôles gardent le
        // repli. Recopier les trois entrées pour chaque type serait une invitation
        // à la divergence.
        $this->assertSame('Porteur', EdgeRoleLabels::label('projet', 'manager'));
        $this->assertSame('Membre', EdgeRoleLabels::label('projet', 'member'));
        $this->assertSame('Propriétaire', EdgeRoleLabels::label('projet', 'owner'));
    }

    #[Test]
    public function the_type_is_matched_case_insensitively_and_trimmed(): void
    {
        $this->assertSame('Élève', EdgeRoleLabels::label('Classe', 'member'));
        $this->assertSame('Élève', EdgeRoleLabels::label('  classe ', 'member'));
    }

    #[Test]
    public function a_dirty_edge_role_reads_as_the_least_endowed_one(): void
    {
        // Même normalisation que les écrans de groupes depuis 42.3 : jamais une
        // valeur technique ni un vide rendus comme texte visible.
        foreach ([null, '', 'prof', 'PP', 'contributor'] as $dirty) {
            $this->assertSame('Élève', EdgeRoleLabels::label('classe', $dirty));
            $this->assertSame('Membre', EdgeRoleLabels::label('projet', $dirty));
        }
    }

    #[Test]
    public function no_stored_value_is_ever_rendered_as_visible_text(): void
    {
        foreach (['classe', 'projet', 'equipe', 'cours', null] as $type) {
            foreach (UserGroupUserPivot::ROLES as $role) {
                $label = EdgeRoleLabels::label($type, $role);
                $this->assertNotSame($role, $label, 'valeur technique rendue telle quelle : ' . $role);
                $this->assertNotSame('', trim($label));
            }
        }
    }

    #[Test]
    public function the_options_cover_the_whole_stored_vocabulary_in_its_own_order(): void
    {
        $options = EdgeRoleLabels::options('classe');

        $this->assertSame(array_values(UserGroupUserPivot::ROLES), array_keys($options));
        $this->assertSame(['Élève', 'Enseignant', 'Professeur principal'], array_values($options));

        $this->assertSame(
            ['Membre', 'Porteur', 'Propriétaire'],
            array_values(EdgeRoleLabels::options('projet')),
        );
    }

    /**
     * Le renommage du vocabulaire stocké a été EXAMINÉ et ÉCARTÉ : le rôle d'arête
     * n'est pas un niveau d'accès. Ce test épingle la décision — si un jour
     * `contributor`/`reader` apparaissent comme libellés, c'est que le glissement
     * a recommencé.
     */
    #[Test]
    public function the_labels_never_borrow_the_access_vocabulary(): void
    {
        foreach (['classe', 'projet', 'equipe', 'cours', null] as $type) {
            foreach (EdgeRoleLabels::options($type) as $label) {
                $this->assertStringNotContainsStringIgnoringCase('contributeur', $label);
                $this->assertStringNotContainsStringIgnoringCase('lecteur', $label);
                $this->assertStringNotContainsStringIgnoringCase('écriture', $label);
            }
        }
    }
}
