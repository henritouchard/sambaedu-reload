<?php

namespace Tests\Feature\Shortcuts;

use Tests\TestCase;
use App\Models\Shortcut;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

/**
 * Test de création de raccourcis via l'interface locale (Livewire).
 *
 * Vérifie la logique métier de création/mise à jour/suppression
 * des raccourcis locaux (non ControlHub) telle qu'utilisée par
 * les pages new/index.blade.php et [id]/index.blade.php.
 *
 * Utilise SQLite in-memory (phpunit.xml) sans migrations.
 */
class ShortcutCreationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shortcut_assignables');
        Schema::dropIfExists('shortcuts');
        parent::tearDown();
    }

    private function createTables(): void
    {
        if (!Schema::hasTable('shortcuts')) {
            Schema::create('shortcuts', function (Blueprint $table) {
                $table->id();
                $table->uuid('controlhub_id')->nullable()->unique();
                $table->string('key', 100)->unique();
                $table->string('name', 255);
                $table->string('owner', 512)->nullable();
                $table->string('place', 20)->default('desktop');
                $table->boolean('is_global')->default(false);
                $table->string('windows_link', 512)->nullable();
                $table->text('windows_args')->nullable();
                $table->string('windows_path', 512)->nullable();
                $table->string('windows_icon', 512)->nullable();
                $table->string('linux_link', 512)->nullable();
                $table->text('linux_args')->nullable();
                $table->string('linux_path', 512)->nullable();
                $table->string('linux_icon', 512)->nullable();
                $table->string('linux_startupwmclass', 255)->nullable();
                $table->string('icon_path', 512)->nullable();
                $table->json('ad_users')->nullable();
                $table->json('ad_user_groups')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shortcut_assignables')) {
            Schema::create('shortcut_assignables', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shortcut_id');
                $table->unsignedBigInteger('assignable_id');
                $table->string('assignable_type');
                $table->timestamps();

                $table->unique(['shortcut_id', 'assignable_id', 'assignable_type'], 'shortcut_assignable_unique');
            });
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function createLocalShortcut(string $name, array $overrides = []): Shortcut
    {
        return Shortcut::create(array_merge([
            'key' => Str::slug($name) . '_' . time() . '_' . rand(1000, 9999),
            'name' => $name,
            'place' => 'desktop',
            'is_global' => false,
        ], $overrides));
    }

    // =========================================================================
    // CREATION TESTS
    // =========================================================================

    /** @test */
    public function create_shortcut_with_minimal_fields(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox');

        $this->assertNotNull($shortcut->id);
        $this->assertEquals('Firefox', $shortcut->name);
        $this->assertEquals('desktop', $shortcut->place);
        $this->assertFalse($shortcut->is_global);
        $this->assertNull($shortcut->controlhub_id);
    }

    /** @test */
    public function create_shortcut_with_windows_config(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox', [
            'windows_link' => 'C:\\Program Files\\Mozilla Firefox\\firefox.exe',
            'windows_args' => '--private-window',
            'windows_path' => 'C:\\Users\\$user\\Documents',
        ]);

        $this->assertEquals('C:\\Program Files\\Mozilla Firefox\\firefox.exe', $shortcut->windows_link);
        $this->assertEquals('--private-window', $shortcut->windows_args);
        $this->assertEquals('C:\\Users\\$user\\Documents', $shortcut->windows_path);
    }

    /** @test */
    public function create_shortcut_with_linux_config(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox', [
            'linux_link' => '/usr/bin/firefox',
            'linux_args' => '--private-window',
            'linux_path' => '/home/$user/Documents',
            'linux_startupwmclass' => 'Firefox',
        ]);

        $this->assertEquals('/usr/bin/firefox', $shortcut->linux_link);
        $this->assertEquals('--private-window', $shortcut->linux_args);
        $this->assertEquals('/home/$user/Documents', $shortcut->linux_path);
        $this->assertEquals('Firefox', $shortcut->linux_startupwmclass);
    }

    /** @test */
    public function create_shortcut_with_full_config(): void
    {
        $shortcut = $this->createLocalShortcut('LibreOffice Writer', [
            'place' => 'startup',
            'windows_link' => 'C:\\Program Files\\LibreOffice\\program\\swriter.exe',
            'windows_args' => '',
            'windows_path' => 'C:\\Users\\$user\\Documents',
            'linux_link' => '/usr/bin/libreoffice',
            'linux_args' => '--writer',
            'linux_path' => '/home/$user',
            'linux_startupwmclass' => 'libreoffice-writer',
        ]);

        $this->assertEquals('LibreOffice Writer', $shortcut->name);
        $this->assertEquals('startup', $shortcut->place);
        $this->assertFalse($shortcut->is_global);

        // Windows
        $this->assertEquals('C:\\Program Files\\LibreOffice\\program\\swriter.exe', $shortcut->windows_link);
        $this->assertEquals('C:\\Users\\$user\\Documents', $shortcut->windows_path);

        // Linux
        $this->assertEquals('/usr/bin/libreoffice', $shortcut->linux_link);
        $this->assertEquals('--writer', $shortcut->linux_args);
        $this->assertEquals('libreoffice-writer', $shortcut->linux_startupwmclass);
    }

    /** @test */
    public function create_url_shortcut(): void
    {
        $shortcut = $this->createLocalShortcut('Pronote', [
            'windows_link' => 'C:\\Program Files\\Internet Explorer\\iexplore.exe',
            'windows_args' => 'https://pronote.example.com',
        ]);

        $this->assertTrue($shortcut->isUrlShortcut());
    }

    /** @test */
    public function create_non_url_shortcut(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox', [
            'windows_link' => 'C:\\Program Files\\Mozilla Firefox\\firefox.exe',
            'windows_args' => '--private-window',
        ]);

        $this->assertFalse($shortcut->isUrlShortcut());
    }

    /** @test */
    public function create_shortcut_with_all_places(): void
    {
        $desktop = $this->createLocalShortcut('Desktop App', ['place' => 'desktop']);
        $startup = $this->createLocalShortcut('Startup App', ['place' => 'startup']);
        $taskbar = $this->createLocalShortcut('Taskbar App', ['place' => 'taskbar']);

        $this->assertEquals('desktop', $desktop->place);
        $this->assertEquals('Bureau', $desktop->getPlaceLabel());

        $this->assertEquals('startup', $startup->place);
        $this->assertEquals('Démarrage automatique', $startup->getPlaceLabel());

        $this->assertEquals('taskbar', $taskbar->place);
        $this->assertEquals('Barre des tâches', $taskbar->getPlaceLabel());
    }

    /** @test */
    public function create_shortcut_generates_unique_key(): void
    {
        $s1 = $this->createLocalShortcut('Firefox');
        $s2 = $this->createLocalShortcut('Firefox');

        $this->assertNotEquals($s1->key, $s2->key);
    }

    /** @test */
    public function create_shortcut_with_ad_assignments(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox', [
            'ad_users' => ['jean.dupont', 'marie.martin'],
            'ad_user_groups' => ['Profs', 'Admins'],
        ]);

        $this->assertEquals(['jean.dupont', 'marie.martin'], $shortcut->ad_users);
        $this->assertEquals(['Profs', 'Admins'], $shortcut->ad_user_groups);
    }

    /** @test */
    public function ad_assignments_default_to_null(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox');

        $this->assertNull($shortcut->ad_users);
        $this->assertNull($shortcut->ad_user_groups);
    }

    // =========================================================================
    // PERSISTENCE TESTS
    // =========================================================================

    /** @test */
    public function shortcut_persists_in_database(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox', [
            'windows_link' => 'C:\\firefox.exe',
            'linux_link' => '/usr/bin/firefox',
        ]);

        $this->assertDatabaseHas('shortcuts', [
            'id' => $shortcut->id,
            'name' => 'Firefox',
            'windows_link' => 'C:\\firefox.exe',
            'linux_link' => '/usr/bin/firefox',
            'is_global' => false,
        ]);
    }

    /** @test */
    public function shortcut_findable_by_key(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox');

        $found = Shortcut::findByKey($shortcut->key);

        $this->assertNotNull($found);
        $this->assertEquals($shortcut->id, $found->id);
        $this->assertEquals('Firefox', $found->name);
    }

    /** @test */
    public function shortcut_findable_by_name(): void
    {
        $this->createLocalShortcut('Firefox');

        $found = Shortcut::findByName('Firefox');

        $this->assertNotNull($found);
        $this->assertEquals('Firefox', $found->name);
    }

    // =========================================================================
    // UPDATE TESTS
    // =========================================================================

    /** @test */
    public function update_shortcut_fields(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox', [
            'place' => 'desktop',
            'windows_link' => 'C:\\old\\firefox.exe',
        ]);

        $shortcut->update([
            'name' => 'Firefox ESR',
            'place' => 'taskbar',
            'windows_link' => 'C:\\new\\firefox.exe',
            'windows_args' => '--safe-mode',
            'linux_link' => '/usr/bin/firefox-esr',
        ]);

        $shortcut->refresh();

        $this->assertEquals('Firefox ESR', $shortcut->name);
        $this->assertEquals('taskbar', $shortcut->place);
        $this->assertEquals('C:\\new\\firefox.exe', $shortcut->windows_link);
        $this->assertEquals('--safe-mode', $shortcut->windows_args);
        $this->assertEquals('/usr/bin/firefox-esr', $shortcut->linux_link);
    }

    /** @test */
    public function update_ad_assignments(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox');

        $shortcut->update([
            'ad_users' => ['user1', 'user2'],
            'ad_user_groups' => ['GroupeA'],
        ]);

        $shortcut->refresh();

        $this->assertEquals(['user1', 'user2'], $shortcut->ad_users);
        $this->assertEquals(['GroupeA'], $shortcut->ad_user_groups);

        // Update again
        $shortcut->update([
            'ad_users' => ['user3'],
            'ad_user_groups' => [],
        ]);

        $shortcut->refresh();

        $this->assertEquals(['user3'], $shortcut->ad_users);
        $this->assertEquals([], $shortcut->ad_user_groups);
    }

    // =========================================================================
    // DELETE TESTS
    // =========================================================================

    /** @test */
    public function delete_shortcut(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox');
        $id = $shortcut->id;

        $shortcut->delete();

        $this->assertNull(Shortcut::find($id));
        $this->assertDatabaseMissing('shortcuts', ['id' => $id]);
    }

    // =========================================================================
    // SCOPE TESTS
    // =========================================================================

    /** @test */
    public function scope_local_excludes_global(): void
    {
        $this->createLocalShortcut('Local App');
        $this->createLocalShortcut('Global App', ['is_global' => true]);

        $locals = Shortcut::local()->get();

        $this->assertCount(1, $locals);
        $this->assertEquals('Local App', $locals->first()->name);
    }

    /** @test */
    public function scope_global_excludes_local(): void
    {
        $this->createLocalShortcut('Local App');
        $this->createLocalShortcut('Global App', ['is_global' => true]);

        $globals = Shortcut::global()->get();

        $this->assertCount(1, $globals);
        $this->assertEquals('Global App', $globals->first()->name);
    }

    /** @test */
    public function scope_by_place_filters_correctly(): void
    {
        $this->createLocalShortcut('Desktop App', ['place' => 'desktop']);
        $this->createLocalShortcut('Startup App', ['place' => 'startup']);
        $this->createLocalShortcut('Taskbar App', ['place' => 'taskbar']);

        $this->assertCount(1, Shortcut::byPlace('desktop')->get());
        $this->assertCount(1, Shortcut::byPlace('startup')->get());
        $this->assertCount(1, Shortcut::byPlace('taskbar')->get());
    }

    // =========================================================================
    // MODEL METHODS TESTS
    // =========================================================================

    /** @test */
    public function windows_config_returns_correct_array(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox', [
            'windows_link' => 'C:\\firefox.exe',
            'windows_args' => '--safe-mode',
            'windows_path' => 'C:\\Users',
            'windows_icon' => 'firefox.png',
        ]);

        $config = $shortcut->getWindowsConfig();

        $this->assertEquals('C:\\firefox.exe', $config['link']);
        $this->assertEquals('--safe-mode', $config['args']);
        $this->assertEquals('C:\\Users', $config['path']);
        $this->assertEquals('firefox.png', $config['icon']);
    }

    /** @test */
    public function linux_config_returns_correct_array(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox', [
            'linux_link' => '/usr/bin/firefox',
            'linux_args' => '--private',
            'linux_path' => '/home/$user',
            'linux_startupwmclass' => 'Firefox',
        ]);

        $config = $shortcut->getLinuxConfig();

        $this->assertEquals('/usr/bin/firefox', $config['link']);
        $this->assertEquals('--private', $config['args']);
        $this->assertEquals('/home/$user', $config['path']);
        $this->assertEquals('Firefox', $config['startupwmclass']);
    }

    /** @test */
    public function legacy_format_roundtrip(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox', [
            'place' => 'desktop',
            'windows_link' => 'C:\\firefox.exe',
            'windows_args' => 'https://mozilla.org',
            'linux_link' => '/usr/bin/firefox',
            'linux_startupwmclass' => 'Firefox',
        ]);

        $legacy = $shortcut->toLegacyFormat();

        $this->assertEquals('Firefox', $legacy['name']);
        $this->assertEquals('desktop', $legacy['place']);
        $this->assertFalse($legacy['global']);
        $this->assertEquals('C:\\firefox.exe', $legacy['windows']['link']);
        $this->assertEquals('https://mozilla.org', $legacy['windows']['args']);
        $this->assertEquals('/usr/bin/firefox', $legacy['linux']['link']);
        $this->assertEquals('Firefox', $legacy['linux']['startupwmclass']);
    }

    // =========================================================================
    // FULL CREATION FLOW (simulates Livewire save logic)
    // =========================================================================

    /** @test */
    public function full_creation_flow_simulates_livewire_save(): void
    {
        // Simulates the logic in new/index.blade.php save() method
        $name = 'Mon Application';
        $place = 'desktop';
        $windowsLink = 'C:\\Program Files\\MonApp\\app.exe';
        $windowsArgs = '--start';
        $windowsPath = 'C:\\Users\\$user';
        $linuxLink = '/usr/bin/monapp';
        $linuxArgs = '--start';
        $linuxPath = '/home/$user';
        $linuxStartupwmclass = 'monapp';

        $key = Str::slug($name) . '_' . time();

        $shortcut = Shortcut::create([
            'key' => $key,
            'name' => $name,
            'place' => $place,
            'is_global' => false,
            'windows_link' => $windowsLink,
            'windows_args' => $windowsArgs,
            'windows_path' => $windowsPath,
            'linux_link' => $linuxLink,
            'linux_args' => $linuxArgs,
            'linux_path' => $linuxPath,
            'linux_startupwmclass' => $linuxStartupwmclass,
        ]);

        // Verify creation
        $this->assertNotNull($shortcut->id);
        $this->assertStringStartsWith('mon-application_', $shortcut->key);

        // Verify retrieval by key (as done by the detail page)
        $found = Shortcut::findByKey($key);
        $this->assertNotNull($found);
        $this->assertEquals('Mon Application', $found->name);
        $this->assertEquals('desktop', $found->place);
        $this->assertFalse($found->is_global);
        $this->assertEquals($windowsLink, $found->windows_link);
        $this->assertEquals($windowsArgs, $found->windows_args);
        $this->assertEquals($windowsPath, $found->windows_path);
        $this->assertEquals($linuxLink, $found->linux_link);
        $this->assertEquals($linuxArgs, $found->linux_args);
        $this->assertEquals($linuxPath, $found->linux_path);
        $this->assertEquals($linuxStartupwmclass, $found->linux_startupwmclass);
    }

    /** @test */
    public function full_update_flow_simulates_livewire_save(): void
    {
        // Create
        $shortcut = $this->createLocalShortcut('Firefox', [
            'windows_link' => 'C:\\firefox.exe',
            'linux_link' => '/usr/bin/firefox',
        ]);

        // Simulates the logic in [id]/index.blade.php save() method
        $shortcut->update([
            'name' => 'Firefox ESR',
            'place' => 'taskbar',
            'windows_link' => 'C:\\firefox-esr.exe',
            'windows_args' => '--safe-mode',
            'windows_path' => 'C:\\Users\\$user',
            'linux_link' => '/usr/bin/firefox-esr',
            'linux_args' => '--safe-mode',
            'linux_path' => '/home/$user',
            'linux_startupwmclass' => 'firefox-esr',
        ]);

        // Reload (as done by loadShortcut())
        $found = Shortcut::findByKey($shortcut->key);

        $this->assertEquals('Firefox ESR', $found->name);
        $this->assertEquals('taskbar', $found->place);
        $this->assertEquals('C:\\firefox-esr.exe', $found->windows_link);
        $this->assertEquals('--safe-mode', $found->windows_args);
        $this->assertEquals('/usr/bin/firefox-esr', $found->linux_link);
        $this->assertEquals('firefox-esr', $found->linux_startupwmclass);
    }

    /** @test */
    public function full_delete_flow_simulates_livewire_delete(): void
    {
        $shortcut = $this->createLocalShortcut('Firefox');
        $key = $shortcut->key;

        // Simulates the logic in [id]/index.blade.php delete() method
        $shortcut->delete();

        $this->assertNull(Shortcut::findByKey($key));
        $this->assertEquals(0, Shortcut::count());
    }
}
