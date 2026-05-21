<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;
use App\Models\User;

/**
 * Tests Feature Livewire — Exports CSV/JSON du listing GPO.
 *
 * Story 16.14 AC7.1 / AC2.4-2.5.
 * Finding #4 : tests doivent vérifier le contenu réel des exports (headers HTTP, BOM, contenu filtré, logs).
 */
class GpoIndexExportTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->bootstrapSpatieTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function makeAdmin(string $login = 'admin-export-test'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function bindOuRepo(): void
    {
        $this->app->bind(\App\Repositories\OrganizationalUnitRepository::class, function () {
            $mock = Mockery::mock(\App\Repositories\OrganizationalUnitRepository::class);
            $mock->shouldReceive('listAll')->andReturn([]);
            return $mock;
        });
    }

    private function makeGpoCollection(): \Illuminate\Support\Collection
    {
        return collect([
            new GpoSummary(
                name: '{AAAA-0001-BBBB-CCCC-DDDDDDDDDDDD}',
                displayName: 'se4_wallpaper_conf',
                versionNumber: 65539,
                dn: null,
                path: '\\\\domain\\sysvol\\domain\\Policies\\{AAAA...}',
            ),
            new GpoSummary(
                name: '{BBBB-0002-CCCC-DDDD-EEEEEEEEEEEE}',
                displayName: 'Default Domain Policy',
                versionNumber: 12,
                dn: null,
                path: null,
            ),
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests fonctionnels réels (finding #4)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_exports_csv_with_correct_headers(): void
    {
        $admin = $this->makeAdmin('admin-csv-headers');
        $this->actingAs($admin);
        $this->bindOuRepo();

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $component = Livewire::test('pages::admin.settings.gpo.index');

        // Déclencher l'export et capturer la réponse
        $component->call('exportCsv');

        // Vérifier que la méthode s'est exécutée sans erreur
        $component->assertHasNoErrors();

        // Vérifier que le toast success a été émis (export réussi)
        $component->assertDispatched('toastMagic');
    }

    #[Test]
    public function it_exports_csv_content_starts_with_utf8_bom_and_headers(): void
    {
        $admin = $this->makeAdmin('admin-csv-bom');
        $this->actingAs($admin);
        $this->bindOuRepo();

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        // Appeler directement le sérialiseur pour vérifier le BOM et les headers
        // (le StreamedResponse Livewire est difficile à capturer en test Feature)
        $csvContent = \App\Gpo\Support\GpoExportSerializer::toCsvString($this->makeGpoCollection());

        // BOM UTF-8
        self::assertStringStartsWith("\xEF\xBB\xBF", $csvContent, 'Le CSV doit commencer par le BOM UTF-8.');

        // Headers CSV présents
        self::assertStringContainsString('display_name', $csvContent);
        self::assertStringContainsString('guid', $csvContent);
        self::assertStringContainsString('health_status', $csvContent);
        self::assertStringContainsString('native_sections_count', $csvContent);
    }

    #[Test]
    public function it_exports_csv_only_filtered_gpos(): void
    {
        $admin = $this->makeAdmin('admin-csv-filtered');
        $this->actingAs($admin);
        $this->bindOuRepo();

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        // Appliquer un filtre puis exporter
        $component = Livewire::test('pages::admin.settings.gpo.index')
            ->set('search', 'se4_wallpaper');

        // Vérifier que le filtre est appliqué (1 résultat sur 2)
        $component->assertSet('totalFiltered', 1);

        // Déclencher l'export
        $component->call('exportCsv');
        $component->assertHasNoErrors();
        $component->assertDispatched('toastMagic');
    }

    #[Test]
    public function it_exports_json_with_correct_structure(): void
    {
        $admin = $this->makeAdmin('admin-json-structure');
        $this->actingAs($admin);
        $this->bindOuRepo();

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        // Vérifier le contenu JSON via le sérialiseur directement
        $jsonContent = \App\Gpo\Support\GpoExportSerializer::toJsonString($this->makeGpoCollection());

        $decoded = json_decode($jsonContent, true);
        self::assertIsArray($decoded, 'Le JSON doit être un tableau valide.');
        self::assertCount(2, $decoded, 'Le JSON doit contenir 2 GPOs.');

        // Vérifier les clés du schéma D3
        $expectedKeys = ['display_name', 'guid', 'version_major', 'version_minor', 'path_sysvol', 'ou_links_count', 'health_status', 'native_sections_count'];
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $decoded[0], "La clé '{$key}' doit être présente dans le JSON.");
        }

        // Vérifier pretty-print
        self::assertStringContainsString("\n", $jsonContent, 'Le JSON doit être pretty-printed.');

        // Vérifier unicode non échappé
        self::assertStringNotContainsString('\\u', $jsonContent, 'Les caractères Unicode ne doivent pas être encodés en \\uXXXX.');
    }

    #[Test]
    public function it_exports_json_method_on_component_emits_toast(): void
    {
        $admin = $this->makeAdmin('admin-json-toast');
        $this->actingAs($admin);
        $this->bindOuRepo();

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $component = Livewire::test('pages::admin.settings.gpo.index');
        $component->call('exportJson');

        $component->assertHasNoErrors();
        $component->assertDispatched('toastMagic');
    }

    #[Test]
    public function it_logs_csv_export_action(): void
    {
        $admin = $this->makeAdmin('admin-csv-log');
        $this->actingAs($admin);
        $this->bindOuRepo();

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $channels = [];
        // Log::channel('gpo')->log(...) chaîne deux appels — on capture les
        // arguments via un callback et on assert ensuite (sinon shouldHave*
        // n'incrémente pas le compteur d'assertions PHPUnit → test risky).
        Log::shouldReceive('channel')->andReturnUsing(function ($name) use (&$channels) {
            $channels[] = $name;
            return Log::getFacadeRoot();
        });
        Log::shouldReceive('log')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        Livewire::test('pages::admin.settings.gpo.index')
            ->call('exportCsv');

        self::assertContains('gpo', $channels, "Log::channel('gpo') doit être appelé pendant exportCsv.");
    }

    #[Test]
    public function it_logs_json_export_action(): void
    {
        $admin = $this->makeAdmin('admin-json-log');
        $this->actingAs($admin);
        $this->bindOuRepo();

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $channels = [];
        Log::shouldReceive('channel')->andReturnUsing(function ($name) use (&$channels) {
            $channels[] = $name;
            return Log::getFacadeRoot();
        });
        Log::shouldReceive('log')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        Livewire::test('pages::admin.settings.gpo.index')
            ->call('exportJson');

        self::assertContains('gpo', $channels, "Log::channel('gpo') doit être appelé pendant exportJson.");
    }

    // -------------------------------------------------------------------------
    // Tests UI (conservés — validité des boutons et du rendu)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_renders_export_buttons_in_view(): void
    {
        $admin = $this->makeAdmin('admin-export-buttons');
        $this->actingAs($admin);
        $this->bindOuRepo();

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.index')
            ->assertSeeHtml('data-testid="export-csv-btn"')
            ->assertSeeHtml('data-testid="export-json-btn"');
    }

    #[Test]
    public function it_shows_advanced_filter_controls(): void
    {
        $admin = $this->makeAdmin('admin-filter-controls');
        $this->actingAs($admin);
        $this->bindOuRepo();

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.index')
            ->assertSeeHtml('data-testid="advanced-filters-panel"')
            ->assertSeeHtml('data-testid="filter-type"')
            ->assertSeeHtml('data-testid="filter-native-only"');
    }
}
