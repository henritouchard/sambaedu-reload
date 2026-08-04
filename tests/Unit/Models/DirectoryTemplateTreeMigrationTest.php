<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DirectoryTemplate;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.1 — la migration est ADDITIVE, NULLABLE et RÉVERSIBLE.
 *
 * La preuve d'iso-comportement tient en une phrase : après migration, les quatre
 * recettes livrées n'ont ni motif de chemin ni nœud. Elles se matérialisent donc
 * exactement comme avant, et le seeder n'a pas eu à bouger.
 */
class DirectoryTemplateTreeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_04_120000_add_tree_spec_to_directory_templates.php';

    #[Test]
    public function the_two_columns_exist_and_the_seeded_recipes_stay_treeless(): void
    {
        $this->assertTrue(Schema::hasColumn('directory_templates', 'path_pattern'));
        $this->assertTrue(Schema::hasColumn('directory_templates', 'nodes_spec'));

        (new DirectoryTemplateSeeder())->run();

        $this->assertSame(4, DirectoryTemplate::count());

        foreach (DirectoryTemplate::all() as $template) {
            $this->assertNull($template->path_pattern, "La recette {$template->key} ne doit porter aucun motif de chemin.");
            $this->assertNull($template->nodes_spec, "La recette {$template->key} ne doit porter aucun nœud.");
            $this->assertFalse($template->hasTreeSpec());
            $this->assertSame([], $template->nodes());

            // Une recette sans arbre est valide par définition : rien à valider.
            $template->assertValidTreeSpec();
        }
    }

    #[Test]
    public function the_migration_is_reversible(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('directory_templates', 'path_pattern'));
        $this->assertFalse(Schema::hasColumn('directory_templates', 'nodes_spec'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('directory_templates', 'path_pattern'));
        $this->assertTrue(Schema::hasColumn('directory_templates', 'nodes_spec'));

        // Et les données de 34.3 ont survécu au va-et-vient : la migration ne
        // touche QUE les deux colonnes qu'elle ajoute.
        (new DirectoryTemplateSeeder())->run();
        $this->assertSame(4, DirectoryTemplate::count());
    }
}
