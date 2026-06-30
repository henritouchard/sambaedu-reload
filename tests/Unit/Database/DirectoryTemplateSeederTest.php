<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Models\DirectoryTemplate;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 34.3 — catalogue (4 recettes) + idempotence du seeder (Q3 option B).
 */
class DirectoryTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeds_exactly_the_four_expected_recipes(): void
    {
        (new DirectoryTemplateSeeder())->run();

        $this->assertSame(4, DirectoryTemplate::count());

        foreach ([
            DirectoryTemplate::KEY_DIRECTION_TO_ALL,
            DirectoryTemplate::KEY_PROFS_TO_ELEVES,
            DirectoryTemplate::KEY_USER_TO_USER,
            DirectoryTemplate::KEY_GROUP_SPACE,
        ] as $key) {
            $this->assertDatabaseHas('directory_templates', ['key' => $key]);
        }
    }

    #[Test]
    public function eleves_to_profs_template_is_not_seeded(): void
    {
        // Q1 — casiers « élèves → profs » REPORTÉ à 34.x : pas de recette livrée.
        (new DirectoryTemplateSeeder())->run();

        $this->assertDatabaseMissing('directory_templates', ['key' => 'eleves_to_profs']);
        $this->assertDatabaseMissing('directory_templates', ['key' => 'rendus']);
    }

    #[Test]
    public function every_recipe_respects_the_mount_only_invariant(): void
    {
        (new DirectoryTemplateSeeder())->run();

        foreach (DirectoryTemplate::all() as $tpl) {
            $this->assertTrue(
                $tpl->respectsMountOnlyInvariant(),
                "La recette {$tpl->key} ne doit grant aucune ACL sur un WorkstationGroup.",
            );
            $this->assertNotEmpty($tpl->roles());
        }
    }

    #[Test]
    public function db_contains_only_the_canonical_keys_after_seed(): void
    {
        // M-2 (review opus) — garde-fou anti-dérive : le sélecteur UI lit
        // `DirectoryTemplate::all()` sans filtre, donc toute clé orpheline
        // apparaîtrait. On épingle l'ensemble EXACT des clés en DB ; ce test
        // casse si le catalogue dérive (recette ajoutée/retirée non intentionnelle).
        (new DirectoryTemplateSeeder())->run();

        $expected = [
            DirectoryTemplate::KEY_DIRECTION_TO_ALL,
            DirectoryTemplate::KEY_PROFS_TO_ELEVES,
            DirectoryTemplate::KEY_USER_TO_USER,
            DirectoryTemplate::KEY_GROUP_SPACE,
        ];
        sort($expected);

        $actual = DirectoryTemplate::pluck('key')->all();
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function reseed_is_idempotent_and_does_not_duplicate(): void
    {
        $first = (new DirectoryTemplateSeeder())->run();
        $this->assertSame(4, $first['created']);
        $this->assertSame(0, $first['updated']);

        $second = (new DirectoryTemplateSeeder())->run();
        $this->assertSame(0, $second['created']);
        $this->assertSame(4, $second['updated']);

        $this->assertSame(4, DirectoryTemplate::count());
    }
}
