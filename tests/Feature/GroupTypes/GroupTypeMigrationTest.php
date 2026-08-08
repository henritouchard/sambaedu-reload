<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypes;

use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.2 — LE TEST PIVOT : la migration de reprise.
 *
 * `user_groups.type` est une chaîne LIBRE depuis quatre ans. La reprise doit donc
 * faire deux choses à la fois, et ce fichier vérifie qu'elle fait EXACTEMENT ces
 * deux-là, sans une écriture de plus :
 *
 *  1. poser les NEUF valeurs recensées dans le code vivant, dans l'ordre des
 *     `<select>` historiques ;
 *  2. DÉCOUVRIR ce que la base porte réellement, à la valeur EXACTE — sans jamais
 *     renommer, normaliser ou fusionner. `class` reste `class`, à côté de
 *     `classe` : deux lignes, parce que ce sont deux valeurs, et que l'écran doit
 *     montrer la donnée telle qu'elle est.
 *
 * La contre-épreuve est aussi importante que la reprise : **aucune ligne de
 * `user_groups` ne bouge**. C'est la matérialisation exécutable du garde-fou
 * d'epic « aucune valeur perdue NI RENOMMÉE ».
 */
class GroupTypeMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Les neuf clés statiques, dans l'ordre des pickers historiques. */
    private const STATIC_KEYS = [
        'custom', 'classe', 'cours', 'matiere', 'matiere_classe', 'projet', 'equipe', 'role', 'function',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        UserGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function the_table_exists_with_its_columns(): void
    {
        $this->assertTrue(Schema::hasTable('group_types'));

        foreach (['id', 'key', 'label', 'icon', 'sort_order', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('group_types', $column),
                "la colonne « {$column} » manque à group_types",
            );
        }
    }

    #[Test]
    public function the_nine_static_types_are_seeded_in_picker_order_with_their_labels_and_icons(): void
    {
        $rows = DB::table('group_types')->orderBy('sort_order')->get();

        $this->assertSame(self::STATIC_KEYS, $rows->pluck('key')->all());

        $this->assertSame([
            'Personnalisé', 'Classe', 'Cours', 'Matière', 'Matière / Classe',
            'Projet', 'Équipe', 'Rôle', 'Fonction',
        ], $rows->pluck('label')->all());

        // Chacune porte une icône : l'écran des types ne doit pas être une liste
        // de trous.
        foreach ($rows as $row) {
            $this->assertNotNull($row->icon, "le type « {$row->key} » n'a pas d'icône seedée");
            $this->assertStringStartsWith('fa-solid ', (string) $row->icon);
        }

        $this->assertSame(range(1, 9), $rows->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
    }

    /**
     * Le CŒUR : une base brownfield, ses valeurs exotiques, et ce qu'il en advient.
     *
     * On rejoue la migration à la main après avoir semé la base : c'est
     * exactement l'ordre du monde réel (les groupes existent depuis quatre ans,
     * la migration arrive après).
     */
    #[Test]
    public function stored_types_are_discovered_at_their_exact_value_and_never_renamed(): void
    {
        // Une base « en place » : la casse, l'orthographe et le vocabulaire d'un
        // parc qui a vécu. `class` n'est PAS une faute à corriger — c'est une
        // valeur que des groupes portent.
        $fixtures = [
            'Classe_3A' => 'classe',
            'Vieux_3B' => 'class',
            'Divers' => 'autre',
            'Admins' => 'admin',
            'Majuscule' => 'Custom',
            'Vide' => '',
        ];

        foreach ($fixtures as $name => $type) {
            DB::table('user_groups')->insert([
                'name' => $name,
                'display_name' => $name,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $before = DB::table('user_groups')->orderBy('name')->get(['name', 'type'])->toArray();

        $this->replayMigration();

        $keys = DB::table('group_types')->orderBy('sort_order')->pluck('key')->all();

        // Les neuf statiques en tête, dans leur ordre…
        $this->assertSame(self::STATIC_KEYS, array_slice($keys, 0, 9));

        // …puis les découvertes, aux valeurs EXACTES. `Custom` est une ligne
        // DISTINCTE de `custom` : la casse n'est pas normalisée, sinon deux
        // groupes qu'aucune requête ne rapproche seraient présentés comme un seul.
        $discovered = array_slice($keys, 9);
        sort($discovered);
        $this->assertSame(['Custom', 'admin', 'autre', 'class'], $discovered);

        // `classe` était déjà au catalogue : pas de doublon. La chaîne vide n'est
        // pas un type : elle n'entre pas.
        $this->assertSame(1, DB::table('group_types')->where('key', 'classe')->count());
        $this->assertSame(0, DB::table('group_types')->where('key', '')->count());

        // Le libellé de secours est `ucfirst` — le repli exact des `match`
        // d'affichage remplacés par cette story.
        $this->assertSame('Class', DB::table('group_types')->where('key', 'class')->value('label'));
        $this->assertSame('Autre', DB::table('group_types')->where('key', 'autre')->value('label'));
        $this->assertNull(DB::table('group_types')->where('key', 'class')->value('icon'));

        // LA CONTRE-ÉPREUVE : la migration LIT `user_groups`, elle n'y écrit pas.
        $this->assertEquals($before, DB::table('user_groups')->orderBy('name')->get(['name', 'type'])->toArray());
    }

    #[Test]
    public function the_migration_is_idempotent(): void
    {
        UserGroup::create(['name' => 'Vieux_3B', 'type' => 'class']);

        $this->replayMigration();
        $first = DB::table('group_types')->orderBy('id')->pluck('key')->all();

        $this->replayMigration();
        $second = DB::table('group_types')->orderBy('id')->pluck('key')->all();

        $this->assertSame($first, $second, 'rejouer la migration ne doit produire aucun doublon');
        $this->assertSame(count($first), count(array_unique($first)));
    }

    #[Test]
    public function a_stored_type_longer_than_the_column_bound_is_refused_not_truncated(): void
    {
        // SQLite ne borne pas les varchar : la valeur entre en base. Tronquer
        // pour la cataloguer serait RENOMMER — on ne la catalogue pas, et on le
        // journalise.
        $tooLong = str_repeat('x', 60);
        DB::table('user_groups')->insert([
            'name' => 'Monstre',
            'display_name' => 'Monstre',
            'type' => $tooLong,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->replayMigration();

        $this->assertSame(0, DB::table('group_types')->where('key', 'like', 'xxx%')->count());
        $this->assertSame($tooLong, DB::table('user_groups')->where('name', 'Monstre')->value('type'));
    }

    /** Rejoue l'`up()` de la migration sur la base courante (la table existe déjà : seule la reprise s'exécute). */
    private function replayMigration(): void
    {
        $migration = require database_path('migrations/2026_08_08_160000_create_group_types_table.php');
        $migration->up();
    }
}
