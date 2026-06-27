<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment\Http;

use App\Models\Application;
use App\Models\Workstation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 17.6 / AC2 / AC5 / AC6.1 — Endpoint `/wpkg/winget_out.php`.
 *
 * Parité JSON `winget_out.php` : décision {install?, upgrade?, uninstall?},
 * Content-Type text/json, JSON_PRETTY_PRINT. ≥3 scénarios de parité
 * (machine vierge / apps à jour / apps à upgrader + désinstaller avec
 * surcharge /etc/) + validations + flag.
 */
class WingetOutEndpointTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();

        // Flag winget activé par défaut pour la majorité des scénarios.
        Config::set('sambaedu.wpkg.winget_enabled', true);

        // Isole du kill-switch legacy : l'.env /vm a LEGACY_CONFIG_CHANNEL_ENABLED=false
        // (→ 410), ce qui masquerait le comportement testé ici. Défaut host = true.
        Config::set('sambaedu.legacy_config_channel_enabled', true);

        // Par défaut, catalogues pointant vers des fichiers absents → [].
        $this->pointCatalogsToAbsent();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = [];
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    /**
     * AC5.2 — flag winget off → 400 Bad request (parité `:23-26`).
     */
    #[Test]
    public function it_returns_400_when_winget_disabled(): void
    {
        Config::set('sambaedu.wpkg.winget_enabled', false);

        $response = $this->post('/wpkg/winget_out.php', [
            'machine' => 'PC1',
            'list' => '[]',
            'action' => 'list',
        ]);

        $response->assertStatus(400);
    }

    /**
     * AC2.5 — validation : action != "list" / machine vide / list vide → 400.
     */
    #[Test]
    public function it_returns_400_on_invalid_params(): void
    {
        // action != list
        $this->post('/wpkg/winget_out.php', ['machine' => 'PC1', 'list' => '[]', 'action' => 'install'])
            ->assertStatus(400);

        // machine vide
        $this->post('/wpkg/winget_out.php', ['machine' => '', 'list' => '[]', 'action' => 'list'])
            ->assertStatus(400);

        // list vide (string vide)
        $this->post('/wpkg/winget_out.php', ['machine' => 'PC1', 'list' => '', 'action' => 'list'])
            ->assertStatus(400);

        // list non-JSON → décodage échoue → 400 (D7).
        $this->post('/wpkg/winget_out.php', ['machine' => 'PC1', 'list' => 'not-json', 'action' => 'list'])
            ->assertStatus(400);
    }

    /**
     * Scénario 1 — Machine vierge (`list = []`) → tout en install.
     */
    #[Test]
    public function scenario_machine_vierge_tout_en_install(): void
    {
        $w = Workstation::create(['name' => 'PC-VIERGE', 'status' => 'active']);
        $app = Application::create([
            'app_id' => 'firefox',
            'name' => 'Firefox',
            // S1 : loadByAppIds filtre ->installed() (helper partagé linux/winget).
            'status' => 'installed',
            'xml' => '<package id="firefox"><windows type="winget" id="Mozilla.Firefox"/></package>',
        ]);
        $w->applications()->attach($app);

        $response = $this->post('/wpkg/winget_out.php', [
            'machine' => 'PC-VIERGE',
            'list' => '[]',
            'action' => 'list',
        ]);

        $response->assertOk();
        // Parité mimetype text/json (non-standard legacy ; Laravel ajoute
        // ; charset=utf-8 — pattern natif accepté, iso 16.13).
        self::assertStringStartsWith('text/json', (string) $response->headers->get('Content-Type'));

        $json = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('install', $json);
        self::assertArrayNotHasKey('upgrade', $json);
        self::assertArrayNotHasKey('uninstall', $json);
        self::assertSame('Mozilla.Firefox', $json['install'][0]['Id']);
        self::assertSame('winget', $json['install'][0]['Source']);
    }

    /**
     * Scénario 2 — Apps installées à jour (IsUpdateAvailable=false) → ni
     * install ni upgrade. Réponse JSON vide `{}`.
     */
    #[Test]
    public function scenario_apps_a_jour_aucune_decision(): void
    {
        $w = Workstation::create(['name' => 'PC-AJOUR', 'status' => 'active']);
        $app = Application::create([
            'app_id' => 'firefox',
            'name' => 'Firefox',
            'status' => 'installed',
            'xml' => '<package id="firefox"><windows type="winget" id="Mozilla.Firefox"/></package>',
        ]);
        $w->applications()->attach($app);

        $list = json_encode([
            ['Id' => 'Mozilla.Firefox', 'InstalledVersion' => '120.0', 'IsUpdateAvailable' => false, 'AvailableVersions' => []],
        ]);

        $response = $this->post('/wpkg/winget_out.php', [
            'machine' => 'PC-AJOUR',
            'list' => $list,
            'action' => 'list',
        ]);

        $response->assertOk();
        $json = json_decode((string) $response->getContent(), true);
        self::assertSame([], $json);
    }

    /**
     * Scénario 3 — Apps à upgrader + app à désinstaller + surcharge /etc/
     * de add.json. Vérifie upgrade + uninstall + merge add /etc/.
     */
    #[Test]
    public function scenario_upgrade_uninstall_avec_surcharge_etc(): void
    {
        $w = Workstation::create(['name' => 'PC-FULL', 'status' => 'active']);
        $firefox = Application::create([
            'app_id' => 'firefox',
            'name' => 'Firefox',
            'status' => 'installed',
            'xml' => '<package id="firefox"><windows type="winget" id="Mozilla.Firefox"/></package>',
        ]);
        $w->applications()->attach($firefox);

        // Surcharge /etc/ : ajoute une app winget supplémentaire (7-Zip).
        $this->writeCatalog('sambaedu.wpkg.winget_catalog_add_local', [
            ['Id' => '7zip.7zip', 'Source' => 'winget'],
        ]);
        // remove.json /usr/share/ : Bing News à désinstaller.
        $this->writeCatalog('sambaedu.wpkg.winget_catalog_remove_default', [
            ['Id' => 'Microsoft.BingNews', 'Name' => 'News'],
        ]);

        $list = json_encode([
            // Firefox installé avec MAJ dispo → upgrade.
            ['Id' => 'Mozilla.Firefox', 'InstalledVersion' => '120.0', 'IsUpdateAvailable' => true, 'AvailableVersions' => ['125.0', '124.0']],
            // Bing News installé + présent dans remove.json → uninstall.
            ['Id' => 'Microsoft.BingNews', 'InstalledVersion' => '1.0', 'IsUpdateAvailable' => false, 'AvailableVersions' => []],
        ]);

        $response = $this->post('/wpkg/winget_out.php', [
            'machine' => 'PC-FULL',
            'list' => $list,
            'action' => 'list',
        ]);

        $response->assertOk();
        $json = json_decode((string) $response->getContent(), true);

        // 7-Zip (add /etc/) absent localement → install.
        self::assertArrayHasKey('install', $json);
        self::assertSame('7zip.7zip', $json['install'][0]['Id']);

        // Firefox → upgrade vers 125.0.
        self::assertArrayHasKey('upgrade', $json);
        self::assertSame('Mozilla.Firefox', $json['upgrade'][0]['Id']);
        self::assertSame('125.0', $json['upgrade'][0]['AvailableVersion']);

        // Bing News → uninstall.
        self::assertArrayHasKey('uninstall', $json);
        self::assertSame('Microsoft.BingNews', $json['uninstall'][0]['Id']);
    }

    /**
     * #5 (review) — Machine inconnue (resolver retourne vide, donc liste XML
     * vide) + `add.json` peuplé → toutes les entrées `add.json` partent en
     * `install` (comportement baseline `add.json`, parité legacy : `$add` est
     * mergé dans `$liste` même sans app XML). `list = []` côté poste.
     */
    #[Test]
    public function scenario_machine_inconnue_add_json_part_en_install(): void
    {
        // PAS de Workstation seedée → resolver retourne une collection vide.
        // add.json /usr/share/ peuplé avec 2 entrées baseline.
        $this->writeCatalog('sambaedu.wpkg.winget_catalog_add_default', [
            ['Id' => 'Microsoft.PowerToys', 'Source' => 'winget'],
            ['Id' => 'Git.Git', 'Source' => 'winget'],
        ]);

        $response = $this->post('/wpkg/winget_out.php', [
            'machine' => 'PC-INCONNUE',
            'list' => '[]',
            'action' => 'list',
        ]);

        $response->assertOk();
        $json = json_decode((string) $response->getContent(), true);

        self::assertArrayHasKey('install', $json);
        self::assertArrayNotHasKey('upgrade', $json);
        self::assertArrayNotHasKey('uninstall', $json);

        $installedIds = array_column($json['install'], 'Id');
        sort($installedIds);
        self::assertSame(['Git.Git', 'Microsoft.PowerToys'], $installedIds);
    }

    /**
     * AC2.6 — Aucune écriture de fichier temporaire /tmp/winget_*.json.
     */
    #[Test]
    public function it_does_not_write_tmp_files(): void
    {
        $tmpFilesBefore = glob('/tmp/winget_*.json') ?: [];

        $w = Workstation::create(['name' => 'PC-NOTMP', 'status' => 'active']);
        $app = Application::create([
            'app_id' => 'firefox',
            'name' => 'Firefox',
            'status' => 'installed',
            'xml' => '<package id="firefox"><windows type="winget" id="Mozilla.Firefox"/></package>',
        ]);
        $w->applications()->attach($app);

        $this->post('/wpkg/winget_out.php', ['machine' => 'PC-NOTMP', 'list' => '[]', 'action' => 'list'])
            ->assertOk();

        $tmpFilesAfter = glob('/tmp/winget_*.json') ?: [];
        self::assertSame($tmpFilesBefore, $tmpFilesAfter, 'Aucun fichier /tmp/winget_*.json ne doit être créé.');
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private function pointCatalogsToAbsent(): void
    {
        foreach ([
            'sambaedu.wpkg.winget_catalog_add_local',
            'sambaedu.wpkg.winget_catalog_add_default',
            'sambaedu.wpkg.winget_catalog_remove_local',
            'sambaedu.wpkg.winget_catalog_remove_default',
        ] as $key) {
            Config::set($key, sys_get_temp_dir() . '/winget-absent-' . uniqid() . '.json');
        }
    }

    /**
     * @param  list<array<string, string>>  $content
     */
    private function writeCatalog(string $configKey, array $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'winget-cat-');
        file_put_contents($path, json_encode($content));
        $this->tmpFiles[] = $path;
        Config::set($configKey, $path);
    }
}
