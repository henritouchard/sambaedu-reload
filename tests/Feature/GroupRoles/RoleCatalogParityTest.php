<?php

declare(strict_types=1);

namespace Tests\Feature\GroupRoles;

use App\Models\GroupRole;
use App\Models\Pivot\UserGroupUserPivot;
use App\Support\RoleCatalog;
use Database\Seeders\GroupRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.1 — LA PARITÉ D'AFFICHAGE, FIGÉE EN LITTÉRAUX.
 *
 * La table de libellés de la story 60.2 est supprimée ; le catalogue la
 * remplace. Ce fichier est la preuve exécutable que la substitution n'a RIEN
 * changé à l'écran : ce sont les neuf épingles de son test, reprises
 * intégralement, portées sur le nouveau point de lecture.
 *
 * Si l'une d'elles tombe, ce n'est pas le test qu'il faut corriger — c'est que la
 * suppression de la classe a fait bouger un libellé que personne n'avait demandé
 * de changer.
 */
class RoleCatalogParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GroupRoleSeeder::class);
    }

    #[Test]
    public function a_class_reads_its_edge_roles_in_school_terms(): void
    {
        $this->assertSame('Élève', RoleCatalog::label('classe', 'member'));
        $this->assertSame('Enseignant', RoleCatalog::label('classe', 'manager'));
        $this->assertSame('Professeur principal', RoleCatalog::label('classe', 'owner'));
    }

    #[Test]
    public function the_same_stored_role_reads_differently_by_group_type(): void
    {
        // C'est TOUT l'objet de la table : une seule valeur stockée, trois
        // lectures métier. Avant 60.2, trois écrans disaient « Prof » partout.
        $this->assertSame('Enseignant', RoleCatalog::label('classe', 'manager'));
        $this->assertSame('Porteur', RoleCatalog::label('projet', 'manager'));
        $this->assertSame('Référent', RoleCatalog::label('equipe', 'manager'));
    }

    #[Test]
    public function an_untranslated_type_falls_back_to_the_catalog_labels(): void
    {
        foreach ([null, '', 'cours', 'matiere', 'matiere_classe', 'custom', 'inconnu'] as $type) {
            $this->assertSame('Membre', RoleCatalog::label($type, 'member'));
            $this->assertSame('Gestionnaire', RoleCatalog::label($type, 'manager'));
            $this->assertSame('Propriétaire', RoleCatalog::label($type, 'owner'));
        }
    }

    #[Test]
    public function a_partially_translated_type_keeps_the_generic_fallback_for_the_rest(): void
    {
        // `projet` ne tranche que `manager` : les deux autres rôles gardent le
        // libellé du catalogue. Recopier les trois entrées pour chaque type serait
        // une invitation à la divergence.
        $this->assertSame('Porteur', RoleCatalog::label('projet', 'manager'));
        $this->assertSame('Membre', RoleCatalog::label('projet', 'member'));
        $this->assertSame('Propriétaire', RoleCatalog::label('projet', 'owner'));
    }

    #[Test]
    public function the_type_is_matched_case_insensitively_and_trimmed(): void
    {
        $this->assertSame('Élève', RoleCatalog::label('Classe', 'member'));
        $this->assertSame('Élève', RoleCatalog::label('  classe ', 'member'));
    }

    #[Test]
    public function a_dirty_edge_role_reads_as_the_least_endowed_one(): void
    {
        // Même normalisation que les écrans de groupes depuis 42.3 : jamais une
        // valeur technique ni un vide rendus comme texte visible.
        foreach ([null, '', 'prof', 'PP', 'contributor'] as $dirty) {
            $this->assertSame('Élève', RoleCatalog::label('classe', $dirty));
            $this->assertSame('Membre', RoleCatalog::label('projet', $dirty));
        }
    }

    #[Test]
    public function no_stored_value_is_ever_rendered_as_visible_text(): void
    {
        foreach (['classe', 'projet', 'equipe', 'cours', null] as $type) {
            foreach (UserGroupUserPivot::roles() as $role) {
                $label = RoleCatalog::label($type, $role);
                $this->assertNotSame($role, $label, 'valeur technique rendue telle quelle : ' . $role);
                $this->assertNotSame('', trim($label));
            }
        }
    }

    #[Test]
    public function the_options_cover_the_whole_catalog_in_its_display_order(): void
    {
        $options = RoleCatalog::options('classe');

        $this->assertSame(array_values(UserGroupUserPivot::roles()), array_keys($options));
        $this->assertSame(
            ['member' => 'Élève', 'manager' => 'Enseignant', 'owner' => 'Professeur principal'],
            $options,
        );

        $this->assertSame(
            ['Membre', 'Porteur', 'Propriétaire'],
            array_values(RoleCatalog::options('projet')),
        );
    }

    /**
     * Le renommage du vocabulaire STOCKÉ a été EXAMINÉ et ÉCARTÉ (story 60.2) : le
     * rôle d'arête n'est pas un niveau d'accès. Ce test épingle la décision — si
     * un jour `contributeur`/`lecteur` apparaissent comme libellés, c'est que le
     * glissement a recommencé.
     */
    #[Test]
    public function the_labels_never_borrow_the_access_vocabulary(): void
    {
        foreach (['classe', 'projet', 'equipe', 'cours', null] as $type) {
            foreach (RoleCatalog::options($type) as $label) {
                $this->assertStringNotContainsStringIgnoringCase('contributeur', $label);
                $this->assertStringNotContainsStringIgnoringCase('lecteur', $label);
                $this->assertStringNotContainsStringIgnoringCase('écriture', $label);
            }
        }
    }

    // =========================================================================
    // Ce que le catalogue ajoute, et que la constante ne savait pas faire
    // =========================================================================

    #[Test]
    public function the_display_order_follows_the_catalog_not_the_insertion_order(): void
    {
        GroupRole::where('key', 'owner')->update(['sort_order' => 0]);
        RoleCatalog::flush();

        $this->assertSame(['owner', 'member', 'manager'], RoleCatalog::keys());
        $this->assertSame(['owner', 'member', 'manager'], array_keys(RoleCatalog::options('classe')));
    }

    #[Test]
    public function a_new_role_gets_its_label_from_the_catalog_in_every_group_type(): void
    {
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        $this->assertSame('Tuteur', RoleCatalog::label(null, 'tuteur'));
        // Aucune surcharge par type ne le concerne : il se lit pareil partout.
        $this->assertSame('Tuteur', RoleCatalog::label('classe', 'tuteur'));
        $this->assertArrayHasKey('tuteur', RoleCatalog::options('equipe'));
    }

    /**
     * AC8 — un renommage de libellé suit à l'écran là où le repli générique
     * s'appliquait, et la surcharge par TYPE continue de primer là où elle existe.
     */
    #[Test]
    public function renaming_a_label_follows_on_screen_but_never_beats_a_type_override(): void
    {
        GroupRole::where('key', 'manager')->update(['label' => 'Encadrant']);
        RoleCatalog::flush();

        $this->assertSame('Encadrant', RoleCatalog::label(null, 'manager'));
        $this->assertSame('Encadrant', RoleCatalog::label('cours', 'manager'));

        // La table transitoire des surcharges (donnée de 62.3) prime toujours.
        $this->assertSame('Enseignant', RoleCatalog::label('classe', 'manager'));
        $this->assertSame('Porteur', RoleCatalog::label('projet', 'manager'));
    }

    /**
     * Le jeton `@member` des recettes n'est PAS un rôle et ne rentre jamais au
     * catalogue : le slug snake_case l'exclut par construction.
     */
    #[Test]
    public function the_reserved_tree_token_can_never_become_a_role_key(): void
    {
        $this->assertNotContains('@member', RoleCatalog::keys());

        // Le slug ne produit jamais un jeton réservé : le « @ » n'y survit pas,
        // et le motif de clé (lettre puis `[a-z0-9_]`) le refuserait de toute
        // façon à l'écriture.
        foreach (['@member', '@ member', 'rôle @member'] as $attempt) {
            $slug = GroupRole::slugify($attempt);
            $this->assertStringNotContainsString('@', $slug);
            $this->assertMatchesRegularExpression(GroupRole::KEY_PATTERN, $slug);
        }
    }
}
