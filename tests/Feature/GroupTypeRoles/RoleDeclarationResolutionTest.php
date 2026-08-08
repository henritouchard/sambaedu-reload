<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypeRoles;

use App\Models\GroupRole;
use App\Models\GroupTypeRole;
use App\Support\RoleCatalog;
use Database\Seeders\GroupRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.3 — AC3/AC4 : la RÉSOLUTION lit la donnée, et le vocabulaire
 * attribuable en découle.
 *
 * `RoleCatalogParityTest` prouve que rien n'a bougé à l'écran. Ce fichier prouve
 * ce que la bascule APPORTE : des libellés qui suivent l'administration, un
 * régime déclaré face à un régime de repli, et une précédence de casse qui ne
 * doit rien au hasard d'un tri.
 */
class RoleDeclarationResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Le catalogue de RÔLES vient du seeder (62.1) ; les DÉCLARATIONS, elles,
        // viennent de la migration (62.3). L'asymétrie est voulue et docblockée
        // là-bas : c'est elle qui laisse la parité tenir sans seeder.
        $this->seed(GroupRoleSeeder::class);
    }

    // =========================================================================
    // Le libellé LOCAL suit la donnée
    // =========================================================================

    #[Test]
    public function editing_a_local_label_changes_what_that_type_reads(): void
    {
        $this->assertSame('Enseignant', RoleCatalog::label('classe', 'manager'));

        GroupTypeRole::where('group_type_key', 'classe')
            ->where('group_role_key', 'manager')
            ->firstOrFail()
            ->update(['label' => 'Professeur']);

        $this->assertSame('Professeur', RoleCatalog::label('classe', 'manager'));
        // Et NULLE PART ailleurs : la surcharge est locale par construction.
        $this->assertSame('Porteur', RoleCatalog::label('projet', 'manager'));
        $this->assertSame('Gestionnaire', RoleCatalog::label('cours', 'manager'));
    }

    /**
     * Une déclaration à `label = null` lit le CATALOGUE — c'est ce qui sépare
     * « déclaré » de « surchargé », et c'est aussi ce qui répare le point reporté
     * par la review 62.1 #4 : un renommage administré n'est plus masqué là où
     * aucune surcharge n'a été voulue.
     */
    #[Test]
    public function a_declaration_without_a_local_label_follows_the_catalog(): void
    {
        $this->assertSame('Membre', RoleCatalog::label('projet', 'member'));

        GroupRole::where('key', 'member')->firstOrFail()->update(['label' => 'Participant']);
        RoleCatalog::flush();

        $this->assertSame('Participant', RoleCatalog::label('projet', 'member'));
        // Là où une surcharge EXISTE, elle prime toujours — et elle est désormais
        // visible et modifiable à l'écran des types.
        $this->assertSame('Élève', RoleCatalog::label('classe', 'member'));
    }

    /** Une chaîne vide saisie n'est pas une surcharge : elle vaut `null`. */
    #[Test]
    public function an_empty_local_label_is_stored_as_no_override(): void
    {
        $declaration = GroupTypeRole::where('group_type_key', 'classe')
            ->where('group_role_key', 'member')
            ->firstOrFail();

        $declaration->label = '   ';
        $declaration->save();

        $this->assertNull($declaration->fresh()->label);
        $this->assertSame('Membre', RoleCatalog::label('classe', 'member'));
    }

    /** Un rôle NON déclaré reste AFFICHÉ : la lecture ne refuse jamais rien. */
    #[Test]
    public function an_undeclared_role_is_still_readable_on_that_type(): void
    {
        // `owner` n'est pas déclaré sur `projet` — une arête héritée le porte
        // pourtant peut-être, et elle doit se lire.
        $this->assertSame('Propriétaire', RoleCatalog::label('projet', 'owner'));
        $this->assertNotContains('owner', RoleCatalog::assignableKeys('projet'));
    }

    // =========================================================================
    // Les deux régimes : déclaré / repli
    // =========================================================================

    #[Test]
    public function a_declared_type_restricts_to_its_declarations_in_catalog_order(): void
    {
        $this->assertSame(['member', 'manager', 'owner'], RoleCatalog::assignableKeys('classe'));
        $this->assertSame(['member', 'manager'], RoleCatalog::assignableKeys('projet'));
        $this->assertSame(['member', 'manager'], RoleCatalog::assignableKeys('equipe'));
    }

    #[Test]
    public function a_type_without_any_declaration_keeps_the_whole_catalog(): void
    {
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        foreach ([null, '', 'cours', 'matiere', 'custom', 'inconnu'] as $type) {
            $this->assertSame(
                ['member', 'manager', 'owner', 'tuteur'],
                RoleCatalog::assignableKeys($type),
                'régime de repli attendu pour « ' . var_export($type, true) . ' »',
            );
        }
    }

    /**
     * L'ORDRE des rôles attribuables est celui du CATALOGUE, pas celui de la
     * déclaration : sans ça, décocher puis recocher un rôle réordonnerait un
     * select sous les doigts de l'utilisateur.
     */
    #[Test]
    public function the_assignable_order_follows_the_catalog_not_the_declaration(): void
    {
        GroupRole::where('key', 'owner')->update(['sort_order' => 0]);
        RoleCatalog::flush();

        $this->assertSame(['owner', 'member', 'manager'], RoleCatalog::assignableKeys('classe'));
    }

    /**
     * Déclarer un rôle personnalisé le rend attribuable — c'est l'aboutissement
     * du catalogue de 62.1, qui livrait un `tuteur` créable mais inattribuable.
     */
    #[Test]
    public function declaring_a_custom_role_makes_it_assignable_on_that_type_only(): void
    {
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);
        GroupTypeRole::create([
            'group_type_key' => 'projet',
            'group_role_key' => 'tuteur',
            'label' => 'Tuteur de projet',
        ]);

        $this->assertSame(['member', 'manager', 'tuteur'], RoleCatalog::assignableKeys('projet'));
        $this->assertSame('Tuteur de projet', RoleCatalog::label('projet', 'tuteur'));

        // La classe, elle, n'a pas bougé.
        $this->assertSame(['member', 'manager', 'owner'], RoleCatalog::assignableKeys('classe'));
    }

    // =========================================================================
    // Normalisation et homonymie de casse
    // =========================================================================

    #[Test]
    public function the_type_is_matched_case_insensitively_and_trimmed_for_assignability_too(): void
    {
        foreach (['Classe', '  classe ', 'CLASSE'] as $variant) {
            $this->assertSame(['member', 'manager', 'owner'], RoleCatalog::assignableKeys($variant));
            $this->assertSame('Élève', RoleCatalog::label($variant, 'member'));
        }
    }

    /**
     * HOMONYMIE DE CASSE — comportement DOCBLOCKÉ, pas laissé au hasard.
     *
     * `Custom` est une ligne légitime du catalogue de types (découverte en base
     * par la migration 62.2), distincte de `custom`. Si les deux déclarent :
     *  - un groupe stocké `Custom` lit les déclarations de `Custom` (exact) ;
     *  - un groupe stocké `CUSTOM` — qui n'apparie exactement ni l'un ni l'autre —
     *    lit celles du PREMIER déclarant dans l'ordre du catalogue de types, et
     *    ENTIÈREMENT : on ne fusionne jamais deux vocabulaires.
     */
    #[Test]
    public function an_exact_type_key_beats_its_case_homonym(): void
    {
        // Ligne HÉRITÉE : elle entre comme la migration 62.2 l'insère — par
        // `DB::table`, sans passer par la garde de slug qui, elle, ne vaut que
        // pour une clé SAISIE (review 62.2 #1).
        $this->insertInheritedType('Custom', 'Custom hérité', 42);
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        // `custom` (sort_order 1, donc PREMIER au catalogue) déclare member.
        GroupTypeRole::create(['group_type_key' => 'custom', 'group_role_key' => 'member', 'label' => 'Adhérent']);
        // `Custom` (sort_order 42, donc dernier) déclare tuteur.
        GroupTypeRole::create(['group_type_key' => 'Custom', 'group_role_key' => 'tuteur', 'label' => 'Parrain']);

        // Correspondance EXACTE : chacun lit les siennes.
        $this->assertSame(['tuteur'], RoleCatalog::assignableKeys('Custom'));
        $this->assertSame('Parrain', RoleCatalog::label('Custom', 'tuteur'));
        $this->assertSame(['member'], RoleCatalog::assignableKeys('custom'));
        $this->assertSame('Adhérent', RoleCatalog::label('custom', 'member'));

        // Aucune correspondance exacte : le PREMIER déclarant du catalogue gagne,
        // et il gagne ENTIÈREMENT — `tuteur` de `Custom` n'est pas fusionné.
        $this->assertSame(['member'], RoleCatalog::assignableKeys('CUSTOM'));
        $this->assertSame('Adhérent', RoleCatalog::label('CUSTOM', 'member'));
    }

    /**
     * Clé de type HÉRITÉE non-slug (review 62.2 #1) : déclarable, résoluble.
     */
    #[Test]
    public function an_inherited_non_slug_type_key_can_carry_declarations(): void
    {
        $this->insertInheritedType('class', 'Class (hérité)', 40);

        GroupTypeRole::create(['group_type_key' => 'class', 'group_role_key' => 'member', 'label' => 'Écolier']);

        $this->assertSame(['member'], RoleCatalog::assignableKeys('class'));
        $this->assertSame('Écolier', RoleCatalog::label('class', 'member'));
        // Et `classe` n'a pas été contaminée par son voisin orthographique.
        $this->assertSame('Élève', RoleCatalog::label('classe', 'member'));
    }

    /** Une clé de type HÉRITÉE, posée comme la migration 62.2 la pose. */
    private function insertInheritedType(string $key, string $label, int $sortOrder): void
    {
        DB::table('group_types')->insert([
            'key' => $key,
            'label' => $label,
            'icon' => null,
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // Mémo, flush, lecture défensive
    // =========================================================================

    /**
     * La mémo des déclarations est vidée par le MÊME `flush()` que le catalogue.
     *
     * C'est la leçon de la review 62.1 #1, appliquée d'emblée : une mémo séparée
     * avec son propre vidage manquerait le `Queue::before` du provider et le
     * `setUp()` des tests, et un worker au long cours resterait sur une carte
     * périmée pendant une heure.
     */
    #[Test]
    public function a_write_made_elsewhere_is_seen_after_the_single_flush(): void
    {
        $this->assertSame('Porteur', RoleCatalog::label('projet', 'manager'));

        // Écriture « faite ailleurs » : aucun événement Eloquent, donc aucun
        // flush automatique — c'est ce que voit un worker.
        DB::table('group_type_roles')
            ->where('group_type_key', 'projet')
            ->where('group_role_key', 'manager')
            ->update(['label' => 'Chef de projet']);

        $this->assertSame('Porteur', RoleCatalog::label('projet', 'manager'), 'la mémo doit être périmée');

        RoleCatalog::flush();

        $this->assertSame('Chef de projet', RoleCatalog::label('projet', 'manager'));
    }

    #[Test]
    public function an_eloquent_write_flushes_the_memo_by_itself(): void
    {
        $this->assertSame('Référent', RoleCatalog::label('equipe', 'manager'));

        GroupTypeRole::where('group_type_key', 'equipe')
            ->where('group_role_key', 'manager')
            ->firstOrFail()
            ->update(['label' => 'Animateur']);

        $this->assertSame('Animateur', RoleCatalog::label('equipe', 'manager'));

        GroupTypeRole::where('group_type_key', 'equipe')
            ->where('group_role_key', 'manager')
            ->firstOrFail()
            ->delete();

        $this->assertSame('Gestionnaire', RoleCatalog::label('equipe', 'manager'));
        $this->assertSame(['member'], RoleCatalog::assignableKeys('equipe'));
    }
}
