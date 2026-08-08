<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypes;

use App\Models\GroupType;
use App\Support\GroupTypeCatalog;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.2 — LA PARITÉ D'AFFICHAGE, FIGÉE EN LITTÉRAUX.
 *
 * Trois `match` divergents meurent avec cette story : celui de la fiche groupe
 * (qui ignorait `role`/`function` et rendait donc « Role »/« Function »), celui de
 * la fiche utilisateur (la forme la plus riche), et l'absence de tout traitement
 * dans le tiroir de sélection, qui rendait la valeur technique brute.
 *
 * Ce fichier est ce qui prouve que leur mort n'a rien changé à l'écran — à la
 * seule divergence NOMMÉE et VOULUE près : les deux fiches lisent désormais
 * « Rôle » et « Fonction ».
 */
class GroupTypeCatalogParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GroupTypeSeeder::class);
    }

    /**
     * Les onze épingles de l'AC5, une par une. Aucune n'est dérivée d'une autre :
     * une régression de libellé doit faire tomber la ligne qui la nomme.
     */
    #[Test]
    public function every_label_is_pinned_literally(): void
    {
        $this->assertSame('Classe', GroupTypeCatalog::label('classe'));
        $this->assertSame('Équipe', GroupTypeCatalog::label('equipe'));
        $this->assertSame('Projet', GroupTypeCatalog::label('projet'));
        $this->assertSame('Cours', GroupTypeCatalog::label('cours'));
        $this->assertSame('Matière', GroupTypeCatalog::label('matiere'));
        $this->assertSame('Matière / Classe', GroupTypeCatalog::label('matiere_classe'));
        $this->assertSame('Personnalisé', GroupTypeCatalog::label('custom'));
        $this->assertSame('Rôle', GroupTypeCatalog::label('role'));
        $this->assertSame('Fonction', GroupTypeCatalog::label('function'));
    }

    /**
     * `matiere` et `matiere_classe` sont DEUX types, pas une simplification à
     * faire : la maille du cloisonnement matière est `matiere_classe`.
     */
    #[Test]
    public function matiere_and_matiere_classe_are_two_distinct_lines(): void
    {
        $this->assertNotSame(GroupTypeCatalog::label('matiere'), GroupTypeCatalog::label('matiere_classe'));
        $this->assertContains('matiere', GroupTypeCatalog::keys());
        $this->assertContains('matiere_classe', GroupTypeCatalog::keys());
    }

    #[Test]
    public function the_defensive_fallbacks_never_render_a_technical_value(): void
    {
        $this->assertSame('Autre', GroupTypeCatalog::label(null));
        $this->assertSame('Autre', GroupTypeCatalog::label(''));

        // `other_group` est le vocabulaire LDAP de routage d'unité
        // d'organisation, jamais une ligne du catalogue : il n'est pas seedé, et
        // il se lit « Autre » comme le faisaient les trois vues.
        $this->assertSame('Autre', GroupTypeCatalog::label('other_group'));
        $this->assertNotContains('other_group', GroupTypeCatalog::keys());

        // Toute autre inconnue : `ucfirst`, comportement des `match` remplacés.
        $this->assertSame('Club', GroupTypeCatalog::label('club'));
        $this->assertSame('Class', GroupTypeCatalog::label('class'));
    }

    #[Test]
    public function options_follow_the_historic_picker_order_with_custom_first(): void
    {
        $this->assertSame([
            'custom' => 'Personnalisé',
            'classe' => 'Classe',
            'cours' => 'Cours',
            'matiere' => 'Matière',
            'matiere_classe' => 'Matière / Classe',
            'projet' => 'Projet',
            'equipe' => 'Équipe',
            'role' => 'Rôle',
            'function' => 'Fonction',
        ], GroupTypeCatalog::options());
    }

    #[Test]
    public function icons_come_from_the_catalog_with_a_generic_fallback(): void
    {
        $this->assertSame('fa-solid fa-graduation-cap', GroupTypeCatalog::icon('classe'));
        $this->assertSame('fa-solid fa-briefcase', GroupTypeCatalog::icon('function'));

        // Inconnue, vide, absente : l'icône générique, jamais un trou.
        $this->assertSame(GroupTypeCatalog::DEFAULT_ICON, GroupTypeCatalog::icon('club'));
        $this->assertSame(GroupTypeCatalog::DEFAULT_ICON, GroupTypeCatalog::icon(''));
        $this->assertSame(GroupTypeCatalog::DEFAULT_ICON, GroupTypeCatalog::icon(null));

        // Un type sans icône déclarée (le cas des valeurs DÉCOUVERTES en base).
        GroupType::create(['key' => 'club', 'label' => 'Club', 'icon' => null, 'sort_order' => 50]);
        $this->assertSame(GroupTypeCatalog::DEFAULT_ICON, GroupTypeCatalog::icon('club'));
    }

    /**
     * `isKnown()` est INSENSIBLE À LA CASSE : c'est la garde d'accrochage qui
     * l'appelle, et l'accrochage est normalisé en minuscules à l'écriture alors
     * que `user_groups.type` ne l'a jamais été.
     */
    #[Test]
    public function is_known_is_case_insensitive_and_refuses_the_empty(): void
    {
        $this->assertTrue(GroupTypeCatalog::isKnown('classe'));
        $this->assertTrue(GroupTypeCatalog::isKnown('CLASSE'));
        $this->assertTrue(GroupTypeCatalog::isKnown('Classe'));

        $this->assertFalse(GroupTypeCatalog::isKnown('classse'));
        $this->assertFalse(GroupTypeCatalog::isKnown(''));
        $this->assertFalse(GroupTypeCatalog::isKnown(null));

        // Une valeur DÉCOUVERTE à une casse exotique se reconnaît aussi en
        // minuscules — sinon un accrochage légitime serait refusé.
        GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 60]);
        $this->assertTrue(GroupTypeCatalog::isKnown('CLUB'));
    }

    #[Test]
    public function a_type_created_at_runtime_enters_the_vocabulary(): void
    {
        $this->assertNotContains('club', GroupTypeCatalog::keys());

        GroupType::create(['key' => 'club', 'label' => 'Club', 'icon' => 'fa-solid fa-guitar', 'sort_order' => 10]);

        // La mémo a été vidée par le hook `saved` : pas besoin de flush manuel.
        $this->assertContains('club', GroupTypeCatalog::keys());
        $this->assertSame('Club', GroupTypeCatalog::label('club'));
        $this->assertSame('fa-solid fa-guitar', GroupTypeCatalog::icon('club'));
    }

    #[Test]
    public function the_seeder_is_idempotent_and_only_seeds_the_nine(): void
    {
        GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 42]);
        GroupType::where('key', 'classe')->update(['label' => 'Division']);

        $this->seed(GroupTypeSeeder::class);

        $this->assertSame(10, GroupType::count());
        // Le re-seed resynchronise la baseline de code…
        $this->assertSame('Classe', GroupType::where('key', 'classe')->value('label'));
        // …et ne touche PAS aux types qui ne sont pas de la baseline.
        $this->assertSame('Club', GroupType::where('key', 'club')->value('label'));
    }
}
