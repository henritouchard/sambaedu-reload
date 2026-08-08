<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypes;

use App\Models\GroupType;
use App\Models\User;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Support\GroupTypeCatalog;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Review 62.2 #1 — une clé HÉRITÉE, non conforme au slug, reste utilisable.
 *
 * **Le défaut que ces tests épinglent.** La garde de format s'appliquait à chaque
 * `save()`, sans regarder si la clé changeait — contrairement à la garde
 * d'immuabilité, juste en dessous, qui ne s'applique qu'à une clé modifiée. Or la
 * migration de reprise insère délibérément les valeurs DÉCOUVERTES telles quelles,
 * sans les normaliser : c'est l'objet même de l'AC1, et quatre ans de colonne libre
 * en produisent (`Custom`, `class`, et pire). Aucune ne respecte le slug.
 *
 * Conséquence, avant correction : ces lignes étaient **injouables**. Renommer leur
 * seul libellé levait une exception non interceptée, et — beaucoup plus grave —
 * « monter »/« descendre » n'importe quel type réécrit `sort_order` sur TOUTE la
 * liste : une seule ligne héritée cassait donc le réordonnancement du catalogue
 * entier, sans que rien ne désigne la coupable.
 *
 * Le format se contrôle à la SAISIE. Il ne peut pas refuser un héritage qu'on
 * s'est justement interdit de renommer.
 */
class GroupTypeInheritedKeyIsUsableTest extends TestCase
{
    use RefreshDatabase;

    private const TAB = 'pages::admin.settings.groups._partials.types-tab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GroupRoleSeeder::class);
        $this->seed(GroupTypeSeeder::class);
        GroupTypeCatalog::flush();

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();

        $this->actingAs(User::create(['login' => 'inherited-admin', 'role' => 'admin', 'is_active' => true]));
        Gate::before(fn ($user, string $ability) => $ability === 'server.admin' ? true : null);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    /** Insertion SQL nue, comme la migration de reprise : aucune garde de modèle. */
    private function insertInheritedType(string $key, string $label): int
    {
        return (int) DB::table('group_types')->insertGetId([
            'key' => $key,
            'label' => $label,
            'sort_order' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function renaming_the_label_of_an_inherited_non_slug_key_is_allowed(): void
    {
        $id = $this->insertInheritedType('Custom', 'Custom');

        $type = GroupType::findOrFail($id);
        $type->label = 'Personnalisé (ancien)';
        $type->save();

        $this->assertSame('Personnalisé (ancien)', (string) GroupType::findOrFail($id)->label);
        $this->assertSame('Custom', (string) GroupType::findOrFail($id)->key, 'la clé ne bouge pas');
    }

    #[Test]
    public function the_key_stays_immutable_even_when_it_was_inherited(): void
    {
        // La correction assouplit le FORMAT, jamais l'immuabilité : on ne doit pas
        // pouvoir « réparer » une clé héritée en la renommant, sinon les groupes
        // qui la portent deviendraient orphelins.
        $id = $this->insertInheritedType('Custom', 'Custom');

        $type = GroupType::findOrFail($id);
        $type->key = 'custom_ancien';

        $this->expectException(\InvalidArgumentException::class);
        $type->save();
    }

    #[Test]
    public function a_newly_typed_key_is_still_refused_when_malformed(): void
    {
        // L'autre moitié : la garde de format doit continuer de mordre à la SAISIE.
        $type = new GroupType(['key' => 'Pas Un Slug', 'label' => 'Bidon', 'sort_order' => 50]);

        $this->expectException(\InvalidArgumentException::class);
        $type->save();
    }

    #[Test]
    public function reordering_the_catalogue_survives_an_inherited_key(): void
    {
        // LE test qui compte : « descendre » un type SANS RAPPORT réécrit toute la
        // liste. Une ligne héritée y suffisait à tout casser.
        $this->insertInheritedType('Custom', 'Custom');
        $classe = GroupType::where('key', 'classe')->firstOrFail();

        Livewire::test(self::TAB)
            ->call('moveDown', $classe->id)
            ->assertHasNoErrors();

        $this->assertTrue(true, 'le réordonnancement ne lève plus');
    }

    #[Test]
    public function editing_from_the_screen_never_renders_a_five_hundred(): void
    {
        $id = $this->insertInheritedType('Équipe-2024', 'Équipe 2024');

        Livewire::test(self::TAB)
            ->call('openEdit', $id)
            ->set('label', 'Équipe 2024 (archivée)')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Équipe 2024 (archivée)', (string) GroupType::findOrFail($id)->label);
    }
}
