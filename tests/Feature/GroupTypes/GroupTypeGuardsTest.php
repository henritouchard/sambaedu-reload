<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypes;

use App\Constants\Ldap\FunctionGroups;
use App\Constants\Ldap\MainGroups;
use App\Exceptions\Filesystem\InvalidTreeSpecException;
use App\Models\DirectoryTemplate;
use App\Models\GroupType;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\UserGroupService;
use App\Support\GroupTypeCatalog;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Story 62.2 — LES GARDES : immuabilité de la clé, vocabulaire aux deux points
 * d'écriture, refus de suppression nommés.
 *
 * Chaque test de refus vérifie DEUX choses : que le refus a lieu, et qu'il n'a
 * RIEN écrit. Un refus qui laisse une trace est un demi-refus.
 */
class GroupTypeGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GroupTypeSeeder::class);
        UserGroupObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    // =========================================================================
    // AC2 — la clé : dérivée, bornée, figée
    // =========================================================================

    #[Test]
    public function the_key_is_derived_from_the_label(): void
    {
        $this->assertSame('club_de_lecture', GroupType::slugify('Club de lecture'));
        $this->assertSame('equipe_projet', GroupType::slugify('Équipe projet'));
        $this->assertSame('', GroupType::slugify('   '));
        // Une clé commence par une lettre : un slug qui débute par un chiffre est
        // un accident de saisie, pas une clé.
        $this->assertSame('e_classe', GroupType::slugify('3e classe'));
    }

    #[Test]
    public function the_key_is_capped_at_the_bound_of_the_carried_column(): void
    {
        $this->assertSame(50, GroupType::KEY_MAX_LENGTH);
        $this->assertSame(50, strlen(GroupType::slugify(str_repeat('a', 80))));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/trop longue/');
        GroupType::create(['key' => str_repeat('a', 51), 'label' => 'Trop long', 'sort_order' => 99]);
    }

    #[Test]
    public function a_malformed_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalide/');
        GroupType::create(['key' => 'Club De Lecture', 'label' => 'Club', 'sort_order' => 99]);
    }

    #[Test]
    public function the_key_is_immutable_once_created(): void
    {
        $type = GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);

        $type->key = 'cercle';

        try {
            $type->save();
            $this->fail('la clé aurait dû être refusée');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }

        $this->assertSame('club', GroupType::find($type->id)->key);
    }

    #[Test]
    public function the_label_and_the_icon_stay_modifiable(): void
    {
        $type = GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);

        $type->label = 'Cercle';
        $type->icon = 'fa-solid fa-guitar';
        $type->save();

        $this->assertSame('club', $type->fresh()->key);
        $this->assertSame('Cercle', GroupTypeCatalog::label('club'));
        $this->assertSame('fa-solid fa-guitar', GroupTypeCatalog::icon('club'));
    }

    // =========================================================================
    // AC4 — le vocabulaire au point d'étranglement du service
    // =========================================================================

    /**
     * On éprouve `validateData()` DIRECTEMENT, par réflexion.
     *
     * `createGroup()` écrit dans l'annuaire avant toute chose : le traverser ici
     * n'éprouverait pas la garde de vocabulaire, il éprouverait un mock de LDAP.
     * La garde est le point d'étranglement, et c'est lui qu'on interroge.
     */
    private function validateType(string $type): array
    {
        $method = new ReflectionMethod(UserGroupService::class, 'validateData');

        return $method->invoke(app(UserGroupService::class), ['name' => 'Echecs', 'type' => $type], null);
    }

    #[Test]
    public function the_service_accepts_a_type_freshly_created_in_the_catalog(): void
    {
        GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);

        $this->assertSame('club', $this->validateType('club')['type']);
    }

    #[Test]
    public function the_service_accepts_every_catalog_key(): void
    {
        foreach (GroupTypeCatalog::keys() as $key) {
            $this->assertSame($key, $this->validateType($key)['type']);
        }
    }

    #[Test]
    public function the_service_refuses_a_type_outside_the_catalog_and_names_the_vocabulary(): void
    {
        try {
            $this->validateType('club');
            $this->fail('un type hors catalogue aurait dû être refusé');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('club', $e->getMessage());
            $this->assertStringContainsString('classe', $e->getMessage(), 'le message doit LISTER le vocabulaire');
        }

        $this->assertSame(0, UserGroup::where('name', 'Echecs')->count());
    }

    /**
     * La comparaison est EXACTE : `Classe` n'est pas `classe`.
     *
     * Relâcher la casse ici créerait, à la première saisie, un groupe `Classe`
     * que `attachedTo('classe')` apparie mais que `where('type', 'classe')` ne
     * compte pas — l'écran et la résolution ne parleraient plus du même monde.
     */
    #[Test]
    public function the_service_comparison_is_case_sensitive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validateType('Classe');
    }

    /**
     * LA FRONTIÈRE : la garde vit au SERVICE, pas sur le modèle.
     *
     * Des dizaines de tests et le balayage d'annuaire écrivent `UserGroup`
     * directement. Poser la garde sur le modèle casserait ces chemins-là, qui
     * n'ont jamais été le problème.
     */
    #[Test]
    public function a_direct_eloquent_write_keeps_its_exotic_type(): void
    {
        $group = UserGroup::create(['name' => 'Divers', 'type' => 'autre']);

        $this->assertSame('autre', $group->fresh()->type);
        $this->assertFalse(GroupTypeCatalog::isKnown('autre'));
    }

    /**
     * COHÉRENCE DU BALAYAGE : tout ce que la détection AD peut retourner
     * appartient au plancher du catalogue.
     *
     * Si quelqu'un ajoute une détection sans seeder le type correspondant, ce
     * test tombe — et il tombe AVANT que le balayage n'écrive en base un type
     * que l'écran ne saurait pas nommer et qu'aucune recette ne pourrait viser.
     */
    #[Test]
    public function every_type_the_ad_sweep_can_produce_belongs_to_the_catalog(): void
    {
        $detect = new ReflectionMethod(UserGroupService::class, 'detectTypeFromAdGroupName');
        $service = app(UserGroupService::class);

        $samples = [
            'Matiere_maths@3A', 'Classe_3A', 'Equipe_3A', 'PP_3A', 'Cours_maths',
            'Projet_jardin', 'Matiere_maths', 'NImporteQuoi',
        ];

        foreach (array_merge($samples, MainGroups::all(), FunctionGroups::all()) as $cn) {
            $type = $detect->invoke($service, (string) $cn);

            $this->assertContains(
                $type,
                GroupTypeCatalog::keys(),
                sprintf('le balayage produirait « %s » (CN « %s »), absent du catalogue', $type, $cn),
            );
        }
    }

    // =========================================================================
    // AC6 — le vocabulaire au second point d'écriture : l'accrochage
    // =========================================================================

    #[Test]
    public function attaching_a_template_to_an_unknown_type_is_refused_by_name(): void
    {
        try {
            DirectoryTemplate::create([
                'key' => 'recette_fantome',
                'label' => 'Recette fantôme',
                'attached_group_type' => 'classse',
                'roles_spec' => [],
                'nodes_spec' => [],
            ]);
            $this->fail('un accrochage à un type inconnu aurait dû être refusé');
        } catch (InvalidTreeSpecException $e) {
            $this->assertStringContainsString('classse', $e->getMessage());
            $this->assertStringContainsString('recette_fantome', $e->getMessage());
        }

        $this->assertSame(0, DirectoryTemplate::where('key', 'recette_fantome')->count());
    }

    #[Test]
    public function attaching_to_a_dynamically_discovered_type_is_accepted(): void
    {
        // Un type qui n'est PAS de la baseline de code : la migration l'a
        // découvert en base, ou l'admin l'a créé. Il vaut vocabulaire.
        GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);

        $template = DirectoryTemplate::create([
            'key' => 'recette_club',
            'label' => 'Recette club',
            'attached_group_type' => 'club',
            'roles_spec' => [],
            'nodes_spec' => [],
        ]);

        $this->assertSame('club', $template->fresh()->attached_group_type);
    }

    /**
     * L'invariant 60.5 est INCHANGÉ : un type ne porte qu'une recette d'ARBRE,
     * mais plusieurs recettes PLATES.
     *
     * Non-régression : la story 62.2 ne re-durcit pas l'unicité relâchée en 60.5.
     */
    #[Test]
    public function the_single_tree_attachment_invariant_is_untouched(): void
    {
        $tree = DirectoryTemplate::create([
            'key' => 'arbre_classe',
            'label' => 'Arbre de classe',
            'attached_group_type' => 'classe',
            'path_pattern' => 'Classes',
            'roles_spec' => [],
            'nodes_spec' => [],
        ]);

        // Une recette PLATE sur le même type : acceptée.
        $flat = DirectoryTemplate::create([
            'key' => 'plate_classe',
            'label' => 'Recette plate',
            'attached_group_type' => 'classe',
            'roles_spec' => [],
            'nodes_spec' => [],
        ]);

        $this->assertSame(2, DirectoryTemplate::where('attached_group_type', 'classe')->count());
        $this->assertSame('arbre_classe', DirectoryTemplate::attachedTo('classe')->key);

        // Un SECOND arbre : refusé, message inchangé.
        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/porte déjà la recette d\'arbre/');
        DirectoryTemplate::create([
            'key' => 'arbre_rival',
            'label' => 'Arbre rival',
            'attached_group_type' => 'classe',
            'path_pattern' => 'Autre',
            'roles_spec' => [],
            'nodes_spec' => [],
        ]);
    }

    // =========================================================================
    // AC7 — les refus de suppression
    // =========================================================================

    #[Test]
    public function the_nine_static_types_are_never_deletable_even_unused(): void
    {
        foreach (GroupType::PROTECTED_KEYS as $key) {
            $type = GroupType::where('key', $key)->firstOrFail();

            $this->assertTrue($type->isProtected(), "« {$key} » devrait être structurel");

            $refusal = $type->deletionRefusal();
            $this->assertNotNull($refusal, "« {$key} » ne devrait pas être supprimable");
            $this->assertStringContainsString('structurel', $refusal);
        }

        $this->assertSame(9, GroupType::count());
    }

    #[Test]
    public function a_type_carried_by_groups_is_refused_with_its_count(): void
    {
        $type = GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);
        UserGroup::create(['name' => 'Echecs', 'type' => 'club']);
        UserGroup::create(['name' => 'Photo', 'type' => 'club']);

        $refusal = $type->deletionRefusal();

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('2 groupes portent ce type', $refusal);

        // AUCUNE écriture sur refus : ni le type, ni les groupes.
        $this->assertSame(1, GroupType::where('key', 'club')->count());
        $this->assertSame(2, UserGroup::where('type', 'club')->count());
    }

    #[Test]
    public function a_type_targeted_by_a_template_is_refused(): void
    {
        $type = GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);
        DirectoryTemplate::create([
            'key' => 'recette_club',
            'label' => 'Recette club',
            'attached_group_type' => 'club',
            'roles_spec' => [],
            'nodes_spec' => [],
        ]);

        $refusal = $type->deletionRefusal();

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('1 arborescence', $refusal);
        $this->assertSame(1, DirectoryTemplate::where('key', 'recette_club')->count());
    }

    #[Test]
    public function a_type_without_group_or_template_is_deletable(): void
    {
        $type = GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);

        $this->assertNull($type->deletionRefusal());

        $type->delete();

        $this->assertSame(0, GroupType::where('key', 'club')->count());
        $this->assertNotContains('club', GroupTypeCatalog::keys());
    }

    /**
     * Le comptage de GROUPES est EXACT, celui des RECETTES est insensible à la
     * casse.
     *
     * L'asymétrie est voulue : `user_groups.type` n'a jamais été normalisé (chacun
     * compte les siens, l'écran montre la réalité) ; `attached_group_type`, lui,
     * EST normalisé en minuscules à l'écriture depuis 60.5, et `attachedTo()`
     * compare déjà en `LOWER()`.
     */
    #[Test]
    public function group_counting_is_exact_while_template_counting_is_case_insensitive(): void
    {
        $type = GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);

        UserGroup::create(['name' => 'Echecs', 'type' => 'club']);
        UserGroup::create(['name' => 'Photo', 'type' => 'Club']); // casse différente

        $this->assertSame(1, GroupType::countGroups('club'));

        // On force la casse en base pour éprouver la lecture (l'écriture, elle,
        // normalise — c'est bien pour cela que la lecture doit être tolérante).
        DirectoryTemplate::create([
            'key' => 'recette_club',
            'label' => 'Recette club',
            'attached_group_type' => 'club',
            'roles_spec' => [],
            'nodes_spec' => [],
        ]);
        DB::table('directory_templates')->where('key', 'recette_club')->update(['attached_group_type' => 'CLUB']);

        $this->assertSame(1, GroupType::countTemplates('club'));
        $this->assertSame(['groups' => 1, 'templates' => 1], $type->usage());
    }

    #[Test]
    public function the_attachment_view_separates_the_tree_from_the_flat_ones(): void
    {
        $classe = GroupType::where('key', 'classe')->firstOrFail();

        $this->assertSame(['tree' => null, 'flat' => 0], $classe->attachment());

        DirectoryTemplate::create([
            'key' => 'arbre_classe',
            'label' => 'Arbre de classe',
            'attached_group_type' => 'classe',
            'path_pattern' => 'Classes',
            'roles_spec' => [],
            'nodes_spec' => [],
        ]);
        DirectoryTemplate::create([
            'key' => 'plate_classe',
            'label' => 'Recette plate',
            'attached_group_type' => 'classe',
            'roles_spec' => [],
            'nodes_spec' => [],
        ]);

        $this->assertSame(['tree' => 'arbre_classe', 'flat' => 1], $classe->attachment());
    }
}
