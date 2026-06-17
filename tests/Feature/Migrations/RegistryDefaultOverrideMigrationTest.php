<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.3ter — schéma : override de valeur au pivot + métadonnées d'éditeur au
 * catalogue (AC1 / AC7).
 *
 * Joue les migrations 27.3 (catalogue + pivot + seeder) PUIS 27.3ter (value au
 * pivot, options/warning au catalogue, migration de données D6/D7) et vérifie :
 *   - `registry_setting_assignables.value` présent, NULLABLE ;
 *   - `registry_settings.options` + `registry_settings.warning` présents, NULLABLE ;
 *   - D6 : défaut EnableLUA = 1 (posture sûre) ; D7 : warning d'EnableLUA non vide ;
 *     options seedées pour les réglages à choix.
 */
class RegistryDefaultOverrideMigrationTest extends TestCase
{
    /** @var array<int,object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        // On repart d'un schéma propre (les tables 27.3 sont créées par RefreshDB
        // au boot ; on les drop pour rejouer les migrations dans l'ordre figé).
        Schema::dropIfExists('registry_setting_assignables');
        Schema::dropIfExists('registry_settings');

        $this->migrations = [
            require base_path('database/migrations/2026_06_16_130000_create_registry_settings_table.php'),
            require base_path('database/migrations/2026_06_16_130100_create_registry_setting_assignables_table.php'),
            require base_path('database/migrations/2026_06_16_130200_seed_registry_settings_catalog.php'),
            require base_path('database/migrations/2026_06_17_090000_add_value_to_registry_setting_assignables.php'),
            require base_path('database/migrations/2026_06_17_090100_add_options_and_warning_to_registry_settings.php'),
            require base_path('database/migrations/2026_06_17_090200_seed_registry_defaults_options_and_warnings.php'),
            require base_path('database/migrations/2026_06_17_090300_add_overrides_locked_to_registry_settings.php'),
        ];

        foreach ($this->migrations as $migration) {
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('registry_setting_assignables');
        Schema::dropIfExists('registry_settings');
        parent::tearDown();
    }

    #[Test]
    public function pivot_has_nullable_value_column(): void
    {
        self::assertTrue(Schema::hasColumn('registry_setting_assignables', 'value'));

        $settingId = DB::table('registry_settings')->where('key', 'show_file_extensions')->value('id');

        // Insertion avec value=null doit réussir (NULLABLE) — override inerte.
        DB::table('registry_setting_assignables')->insert([
            'registry_setting_id' => $settingId,
            'assignable_type' => 'App\\Models\\WorkstationGroup',
            'assignable_id' => 999,
            'value' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertDatabaseHas('registry_setting_assignables', [
            'registry_setting_id' => $settingId,
            'assignable_id' => 999,
            'value' => null,
        ]);
    }

    #[Test]
    public function catalog_has_overrides_locked_defaulting_false(): void
    {
        self::assertTrue(Schema::hasColumn('registry_settings', 'overrides_locked'));

        // Les réglages seedés ne sont PAS gelés par défaut.
        $locked = DB::table('registry_settings')->where('key', 'show_file_extensions')->value('overrides_locked');
        self::assertFalse((bool) $locked, 'overrides_locked défaut = false');
    }

    #[Test]
    public function catalog_has_nullable_options_and_warning(): void
    {
        self::assertTrue(Schema::hasColumn('registry_settings', 'options'));
        self::assertTrue(Schema::hasColumn('registry_settings', 'warning'));

        // Un réglage sans options/warning reste valide (NULLABLE).
        DB::table('registry_settings')->insert([
            'key' => 'plain_setting',
            'label' => 'Sans options',
            'hive' => 'HKCU',
            'path' => 'Software\\X',
            'name' => 'Plain',
            'type' => 'REG_SZ',
            'value' => 'abc',
            'options' => null,
            'warning' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertDatabaseHas('registry_settings', ['key' => 'plain_setting', 'options' => null, 'warning' => null]);
    }

    #[Test]
    public function d6_enable_lua_default_is_safe_posture(): void
    {
        // D6 : défaut EnableLUA = 1 (UAC ACTIVÉ — posture sûre diffusée à la flotte).
        $value = DB::table('registry_settings')->where('key', 'disable_uac')->value('value');
        self::assertSame('1', (string) $value, 'EnableLUA défaut = 1 (posture sûre, D6)');
    }

    #[Test]
    public function d7_enable_lua_has_warning(): void
    {
        $warning = DB::table('registry_settings')->where('key', 'disable_uac')->value('warning');
        self::assertNotNull($warning);
        self::assertStringContainsStringIgnoringCase('UAC', (string) $warning);
    }

    #[Test]
    public function choice_settings_have_options(): void
    {
        foreach (['show_file_extensions', 'show_hidden_files', 'disable_uac'] as $key) {
            $options = DB::table('registry_settings')->where('key', $key)->value('options');
            self::assertNotNull($options, "options seedées pour {$key}");
            $decoded = json_decode((string) $options, true);
            self::assertIsArray($decoded);
            self::assertNotEmpty($decoded);
        }
    }

    #[Test]
    public function down_is_symmetric(): void
    {
        // down() des 3 migrations 27.3ter doit retirer colonnes/données sans erreur.
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }

        self::assertFalse(Schema::hasTable('registry_settings'));
        self::assertFalse(Schema::hasTable('registry_setting_assignables'));
    }
}
