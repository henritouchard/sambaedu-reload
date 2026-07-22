<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use App\Models\Application;
use App\Models\AppProfile;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\WorkstationApplicationStatus;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\ErrorLoggerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * Tests du shim SQL MySQL→Eloquent (story 1bis.3).
 *
 * Vérifie que chaque fonction shimmée retourne les données
 * dans le format exact attendu par le code legacy.
 *
 * Utilise SQLite in-memory avec tables créées manuellement.
 */
class SqlShimTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        // Désactiver les observers (LDAP/AD sync) qui cassent les tests
        WorkstationGroup::unsetEventDispatcher();
        Workstation::unsetEventDispatcher();

        // Le shim `info_poste_statut()` cache ses résultats en APCu sous une
        // clé md5(id_poste + serialize(list_app)). Avec DatabaseTransactions,
        // les IDs SQLite repartent à 1 à chaque test → même clé md5 → cache
        // hit du test précédent. On purge à chaque setUp pour garantir
        // l'isolation des tests.
        if (function_exists('apcu_clear_cache') && apcu_enabled()) {
            apcu_clear_cache();
        }

        $this->loadShim();
    }

    private function loadShim(): void
    {
        if (!defined('SQL_SHIM_LOADED')) {
            require_once base_path('legacy/wpkg_libsql.php');
        }
    }

    private function createTables(): void
    {
        // workstations
        if (!Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('os')->nullable();
                $table->string('ip')->nullable();
                $table->string('mac')->nullable();
                $table->string('uuid')->nullable();
                $table->string('status')->default('active');
                $table->timestamp('last_report_at')->nullable();
                $table->string('report_sha')->nullable();
                $table->string('log_path')->nullable();
                $table->string('report_path')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->boolean('managed_by_control_hub')->default(false);
                $table->timestamps();
            });
        }

        // workstation_groups
        if (!Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table) {
                $table->id();
                $table->string('controlhub_id')->nullable();
                $table->string('name');
                $table->boolean('is_physical')->default(false);
                $table->string('display_name')->nullable();
                $table->text('description')->nullable();
                $table->string('app_profile_name')->nullable();
                $table->integer('parent_id')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('locked')->nullable();
                $table->boolean('managed_by_control_hub')->default(false);
                $table->timestamps();
            });
        }

        // workstation_group_workstation
        if (!Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workstation_id');
                $table->foreignId('workstation_group_id');
                $table->timestamps();
            });
        }

        // applications
        if (!Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $table) {
                $table->id();
                $table->string('controlhub_id')->nullable();
                $table->timestamp('controlhub_version')->nullable();
                $table->boolean('managed_by_control_hub')->default(false);
                $table->integer('depot_id')->nullable();
                $table->string('app_id');
                $table->string('name');
                $table->string('version')->nullable();
                $table->string('category')->nullable();
                $table->string('compatibility')->nullable();
                $table->string('branch')->nullable();
                $table->text('xml')->nullable();
                $table->string('xml_url')->nullable();
                $table->string('xml_sha')->nullable();
                $table->string('log_url')->nullable();
                $table->string('status')->nullable();
                $table->string('installed_version')->nullable();
                $table->string('installer_url')->nullable();
                $table->string('installer_sha256')->nullable();
                $table->string('installer_filename')->nullable();
                $table->integer('installer_size')->nullable();
                $table->string('local_xml_path')->nullable();
                $table->string('local_installer_path')->nullable();
                $table->text('description')->nullable();
                $table->string('icon_url')->nullable();
                $table->string('author')->nullable();
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamps();
            });
        }

        // depots
        if (!Schema::hasTable('depots')) {
            Schema::create('depots', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('url');
                $table->boolean('is_primary')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('xml_hash')->nullable();
                $table->timestamps();
            });
        }

        // depot_applications
        if (!Schema::hasTable('depot_applications')) {
            Schema::create('depot_applications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('depot_id');
                $table->string('app_id');
                $table->string('name');
                $table->string('version')->nullable();
                $table->string('category')->nullable();
                $table->string('compatibility')->nullable();
                $table->string('branch')->nullable()->default('stable');
                $table->text('xml')->nullable();
                $table->string('xml_url')->nullable();
                $table->string('xml_sha')->nullable();
                $table->string('log_url')->nullable();
                $table->text('description')->nullable();
                $table->string('author')->nullable();
                $table->string('icon_url')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamps();
            });
        }

        // app_profiles
        if (!Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('controlhub_id')->nullable();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('ad_guid')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // app_profile_application
        if (!Schema::hasTable('app_profile_application')) {
            Schema::create('app_profile_application', function (Blueprint $table) {
                $table->id();
                $table->foreignId('app_profile_id');
                $table->foreignId('application_id');
                $table->timestamps();
            });
        }

        // app_profile_workstation_group
        if (!Schema::hasTable('app_profile_workstation_group')) {
            Schema::create('app_profile_workstation_group', function (Blueprint $table) {
                $table->id();
                $table->foreignId('app_profile_id');
                $table->foreignId('workstation_group_id');
                $table->timestamps();
            });
        }

        // workstation_application_status
        if (!Schema::hasTable('workstation_application_status')) {
            Schema::create('workstation_application_status', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workstation_id')->constrained('workstations')->onDelete('cascade');
                $table->foreignId('application_id')->constrained('applications')->onDelete('cascade');
                $table->string('installed_version', 255)->nullable();
                $table->string('status', 20);
                $table->boolean('reboot_required')->default(false);
                $table->timestamp('reported_at')->nullable();
                $table->timestamps();
            });
        }

        // application_dependencies
        if (!Schema::hasTable('application_dependencies')) {
            Schema::create('application_dependencies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id');
                $table->foreignId('required_application_id');
                $table->timestamps();
            });
        }

        // installation_logs
        if (!Schema::hasTable('installation_logs')) {
            Schema::create('installation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id');
                $table->string('status')->default('pending');
                $table->string('version')->nullable();
                $table->text('message')->nullable();
                $table->timestamps();
            });
        }

        // error_logs
        if (!Schema::hasTable('error_logs')) {
            Schema::create('error_logs', function (Blueprint $table) {
                $table->id();
                $table->string('source');
                $table->text('message');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createWorkstation(array $attrs = []): Workstation
    {
        return Workstation::create(array_merge([
            'name' => 'PC-TEST-01',
            'os' => 'Windows 10',
            'ip' => '192.168.1.100',
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'uuid' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'status' => 'active',
            'report_sha' => 'sha256test',
            'log_path' => '/var/log/test.log',
            'report_path' => '/var/rapport/test.xml',
        ], $attrs));
    }

    private function createGroup(array $attrs = []): WorkstationGroup
    {
        return WorkstationGroup::create(array_merge([
            'name' => 'SALLE-101',
            'display_name' => 'Salle 101',
            'is_active' => true,
        ], $attrs));
    }

    private function makeApp(array $attrs = []): Application
    {
        return Application::create(array_merge([
            'app_id' => 'firefox-esr',
            'name' => 'Firefox ESR',
            'version' => '115.0',
            'category' => 'Internet',
            'compatibility' => '64',
            'xml_sha' => 'sha512abc',
        ], $attrs));
    }

    private function createDepot(array $attrs = []): Depot
    {
        return Depot::create(array_merge([
            'name' => 'Dépôt Principal',
            'url' => 'https://depot.example.com',
            'is_primary' => true,
            'is_active' => true,
            'xml_hash' => 'hash123',
        ], $attrs));
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Connexion (no-ops)
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_connexion_db_wpkg_returns_object(): void
    {
        $link = connexion_db_wpkg([]);
        $this->assertInstanceOf(\stdClass::class, $link);
    }

    public function test_deconnexion_db_wpkg_is_noop(): void
    {
        $link = connexion_db_wpkg([]);
        deconnexion_db_wpkg($link);
        $this->assertTrue(true); // No exception
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Lecture postes
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_info_postes_returns_correct_format(): void
    {
        $ws = $this->createWorkstation();

        $result = info_postes([]);

        $this->assertArrayHasKey(strtolower($ws->name), $result);
        $entry = $result[strtolower($ws->name)];

        $this->assertEquals($ws->id, $entry['id']);
        $this->assertEquals(strtolower($ws->name), $entry['nom_poste']);
        $this->assertEquals('Windows 10', $entry['OS_poste']);
        $this->assertEquals('192.168.1.100', $entry['IP_poste']);
        // Le mutator Workstation::setMacAttribute force le lowercase canonique
        // (iso MacAddressNormalizer). Ce shim retourne la valeur DB telle quelle.
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $entry['mac_address_poste']);
        $this->assertEquals('sha256test', $entry['sha_rapport_poste']);
        $this->assertEquals('/var/log/test.log', $entry['file_log_poste']);
        $this->assertEquals('/var/rapport/test.xml', $entry['file_rapport_poste']);
        $this->assertEquals('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $entry['uuid_poste']);
        $this->assertEquals(0, $entry['flag_poste']); // active = 0
    }

    public function test_info_postes_protected_flag(): void
    {
        $this->createWorkstation(['status' => 'protected']);

        $result = info_postes([]);
        $entry = array_values($result)[0];

        $this->assertEquals(1, $entry['flag_poste']);
    }

    public function test_info_postes_uuid_returns_indexed_by_uuid(): void
    {
        $ws = $this->createWorkstation();
        $this->createWorkstation(['name' => 'PC-NO-UUID', 'uuid' => null]);

        $result = info_postes_uuid([]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $result);
        $this->assertEquals($ws->id, $result['a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11']['id']);
    }

    public function test_info_poste_parcs_returns_groups(): void
    {
        $ws = $this->createWorkstation();
        $group = $this->createGroup();
        $group->workstations()->attach($ws->id);

        $result = info_poste_parcs([], $ws->name);

        $this->assertArrayHasKey($group->name, $result);
        $entry = $result[$group->name];
        $this->assertEquals($group->id, $entry['id_parc']);
        $this->assertEquals($ws->id, $entry['id_poste']);
        $this->assertEquals($group->name, $entry['nom_parc']);
        $this->assertEquals('Salle 101', $entry['nom_parc_wpkg']);
    }

    public function test_info_poste_parcs_returns_empty_for_unknown(): void
    {
        $result = info_poste_parcs([], 'UNKNOWN-PC');
        $this->assertEmpty($result);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Lecture parcs
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_info_parcs_returns_correct_format(): void
    {
        $group = $this->createGroup(['ad_guid' => 'guid-123']);

        $result = info_parcs([]);

        $this->assertArrayHasKey($group->name, $result);
        $entry = $result[$group->name];
        $this->assertEquals($group->id, $entry['id']);
        $this->assertEquals($group->name, $entry['nom_parc']);
        $this->assertEquals('Salle 101', $entry['nom_parc_wpkg']);
        $this->assertEquals('guid-123', $entry['uuid']);
    }

    public function test_info_parc_postes_returns_workstations(): void
    {
        $ws = $this->createWorkstation();
        $group = $this->createGroup();
        $group->workstations()->attach($ws->id);

        $result = info_parc_postes([], $group->name);

        $this->assertArrayHasKey($ws->name, $result);
        $entry = $result[$ws->name];
        $this->assertEquals($ws->id, $entry['id_poste']);
        $this->assertEquals($ws->name, $entry['nom_poste']);
        $this->assertEquals('Windows 10', $entry['OS_poste']);
    }

    public function test_info_parc_appli_returns_applications(): void
    {
        $group = $this->createGroup();
        $app = $this->makeApp();
        $profile = AppProfile::create(['name' => 'profile_test', 'is_active' => true]);
        $profile->applications()->attach($app->id);
        $group->appProfiles()->attach($profile->id);

        $result = info_parc_appli([], $group->name);

        $this->assertArrayHasKey($app->id, $result);
        $entry = $result[$app->id];
        $this->assertEquals($app->id, $entry['id_app']);
        $this->assertEquals('firefox-esr', $entry['id_nom_app']);
        $this->assertEquals('Firefox ESR', $entry['nom_app']);
        $this->assertEquals('115.0', $entry['version_app']);
        $this->assertEquals('Internet', $entry['categorie_app']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Lecture applications
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_liste_applications_returns_md5_keyed(): void
    {
        $app = $this->makeApp();

        $result = liste_applications([]);
        $md5Key = hash('md5', $app->app_id);

        $this->assertArrayHasKey($md5Key, $result);
        $entry = $result[$md5Key];
        $this->assertEquals($app->id, $entry['id_app']);
        $this->assertEquals('firefox-esr', $entry['id_nom_app']);
        $this->assertEquals('Firefox ESR', $entry['nom_app']);
        $this->assertEquals('115.0', $entry['version_app']);
        $this->assertEquals(1, $entry['active_app']);
    }

    public function test_info_categorie_returns_grouped_counts(): void
    {
        $before = info_categorie([]);
        $internetBefore = isset($before['internet']) ? (int) $before['internet']['nb_app'] : 0;

        $this->makeApp(['app_id' => 'firefox-test', 'category' => 'Internet']);
        $this->makeApp(['app_id' => 'chrome-test', 'name' => 'Chrome Test', 'category' => 'Internet']);

        $result = info_categorie([]);

        $this->assertArrayHasKey('internet', $result);
        $this->assertEquals($internetBefore + 2, (int) $result['internet']['nb_app']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Lecture dépôts
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_info_depot_returns_active_depots(): void
    {
        $depot = $this->createDepot();

        $result = info_depot([]);

        $this->assertArrayHasKey($depot->id, $result);
        $entry = $result[$depot->id];
        $this->assertEquals($depot->name, $entry['nom_depot']);
        $this->assertEquals($depot->url, $entry['url_depot']);
        $this->assertEquals(1, $entry['depot_principal']);
    }

    public function test_info_all_depot_returns_all(): void
    {
        $countBefore = count(info_all_depot([]));
        $this->createDepot();
        $this->createDepot(['name' => 'Second', 'url' => 'https://second.test', 'is_active' => false, 'is_primary' => false]);

        $result = info_all_depot([]);
        $this->assertCount($countBefore + 2, $result);
    }

    public function test_info_depot_appli_returns_depot_applications(): void
    {
        $depot = $this->createDepot();
        $depotApp = DepotApplication::create([
            'depot_id' => $depot->id,
            'app_id' => 'vlc',
            'name' => 'VLC Media Player',
            'version' => '3.0.20',
            'category' => 'Multimedia',
            'branch' => 'stable',
        ]);

        $result = info_depot_appli([], $depot->id);

        $this->assertCount(1, $result);
        $this->assertEquals('vlc', $result[0]['id_nom_app']);
        $this->assertEquals('VLC Media Player', $result[0]['nom_app']);
        $this->assertEquals('3.0.20', $result[0]['version']);
    }

    public function test_info_depot_principal_returns_primary(): void
    {
        $depot = $this->createDepot();

        $result = info_depot_principal([]);

        $ids = array_column($result, 'id_depot');
        $this->assertContains($depot->id, $ids);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Mise en forme
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_mise_en_forme_personnalisee_returns_defaults(): void
    {
        $result = mise_en_forme_personnalisee([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('warning_bg', $result);
        $this->assertEquals('#EE0000', $result['warning_bg']);
        $this->assertArrayHasKey('ok_bg', $result);
        $this->assertEquals('#00DD00', $result['ok_bg']);
    }

    public function test_mise_en_forme_info_returns_structured_defaults(): void
    {
        $result = mise_en_forme_info([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('warning_bg', $result);
        $entry = $result['warning_bg'];
        $this->assertEquals('warning_bg', $entry['label']);
        $this->assertArrayHasKey('value', $entry);
        $this->assertArrayHasKey('test', $entry);
        $this->assertArrayHasKey('default', $entry);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Écriture postes
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_insert_poste_info_wpkg_creates_workstation(): void
    {
        $info = [
            'nom_poste' => 'NEW-PC-01',
            'typewin' => 'Windows 11',
            'datetime' => '2026-03-26 10:00:00',
            'ip' => '10.0.0.1',
            'mac_address' => '11:22:33:44:55:66',
            'sha256' => 'newsha',
            'logfile' => '/var/log/new.log',
            'rapportfile' => '/var/rapport/new.xml',
            'uuid_poste' => 'b1eebc99-9c0b-4ef8-bb6d-6bb9bd380a22',
            'flag_poste' => 0,
        ];

        $id = insert_poste_info_wpkg([], $info);

        $this->assertGreaterThan(0, $id);
        $ws = Workstation::find($id);
        $this->assertNotNull($ws);
        $this->assertEquals('NEW-PC-01', $ws->name);
        $this->assertEquals('Windows 11', $ws->os);
        $this->assertEquals('active', $ws->status);
    }

    public function test_insert_poste_protected_flag(): void
    {
        $info = [
            'nom_poste' => 'PROTECTED-PC',
            'typewin' => 'Win10',
            'flag_poste' => 1,
        ];

        $id = insert_poste_info_wpkg([], $info);
        $ws = Workstation::find($id);
        $this->assertEquals('protected', $ws->status);
    }

    public function test_update_poste_info_wpkg_updates_workstation(): void
    {
        $ws = $this->createWorkstation();

        $info = [
            'nom_poste' => $ws->name,
            'typewin' => 'Windows 11',
            'datetime' => '2026-03-26 12:00:00',
            'ip' => '10.0.0.2',
            'mac_address' => 'FF:EE:DD:CC:BB:AA',
            'sha256' => 'updated_sha',
            'logfile' => '/var/log/updated.log',
            'rapportfile' => '/var/rapport/updated.xml',
        ];

        $id = update_poste_info_wpkg([], $info);

        $this->assertEquals($ws->id, $id);
        $ws->refresh();
        $this->assertEquals('Windows 11', $ws->os);
        $this->assertEquals('10.0.0.2', $ws->ip);
    }

    public function test_delete_poste_info_wpkg_removes_all(): void
    {
        $ws = $this->createWorkstation();
        $group = $this->createGroup();
        $group->workstations()->attach($ws->id);
        $app = $this->makeApp();
        WorkstationApplicationStatus::create([
            'workstation_id' => $ws->id,
            'application_id' => $app->id,
            'status' => 'installed',
        ]);

        delete_poste_info_wpkg([], $ws->id);

        $this->assertNull(Workstation::find($ws->id));
        $this->assertEquals(0, WorkstationApplicationStatus::where('workstation_id', $ws->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Écriture rapports
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_insert_info_app_poste_creates_report(): void
    {
        $ws = $this->createWorkstation();
        $app = $this->makeApp();

        insert_info_app_poste([], $ws->id, $app->id, [
            'id_nom_app' => $app->app_id,
            'Revision' => '115.0',
            'Status' => 'Installed',
            'Reboot' => 0,
        ]);

        $report = WorkstationApplicationStatus::where('workstation_id', $ws->id)->where('application_id', $app->id)->first();
        $this->assertNotNull($report);
        $this->assertEquals('installed', $report->status);
        $this->assertEquals('115.0', $report->installed_version);
    }

    public function test_delete_info_app_poste_removes_all_reports(): void
    {
        $ws = $this->createWorkstation();
        $app1 = $this->makeApp(['app_id' => 'app1', 'name' => 'App 1']);
        $app2 = $this->makeApp(['app_id' => 'app2', 'name' => 'App 2']);
        WorkstationApplicationStatus::create(['workstation_id' => $ws->id, 'application_id' => $app1->id, 'status' => 'installed']);
        WorkstationApplicationStatus::create(['workstation_id' => $ws->id, 'application_id' => $app2->id, 'status' => 'installed']);

        delete_info_app_poste([], $ws->id);

        $this->assertEquals(0, WorkstationApplicationStatus::where('workstation_id', $ws->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Écriture applications
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_insert_applications_creates_app(): void
    {
        $id = insert_applications([], [
            'id_nom_app' => 'new-app',
            'nom_app' => 'New App',
            'version_app' => '1.0',
            'compatibilite_app' => '64',
            'categorie_app' => 'Utilitaires',
        ]);

        $this->assertGreaterThan(0, $id);
        $app = Application::find($id);
        $this->assertEquals('new-app', $app->app_id);
        $this->assertEquals('New App', $app->name);
    }

    public function test_update_applications_updates_app(): void
    {
        $app = $this->makeApp();

        update_applications([], $app->id, [
            'id_nom_app' => 'firefox-esr',
            'nom_app' => 'Firefox ESR Updated',
            'version_app' => '120.0',
        ]);

        $app->refresh();
        $this->assertEquals('Firefox ESR Updated', $app->name);
        $this->assertEquals('120.0', $app->version);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Dépendances
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_insert_and_delete_dependances(): void
    {
        $app1 = $this->makeApp(['app_id' => 'app1', 'name' => 'App 1']);
        $app2 = $this->makeApp(['app_id' => 'app2', 'name' => 'App 2']);

        insert_dependance([], $app1->id, $app2->id);

        $count = DB::table('application_dependencies')
            ->where('application_id', $app1->id)
            ->where('required_application_id', $app2->id)
            ->count();
        $this->assertEquals(1, $count);

        delete_dependances([]);
        $this->assertEquals(0, DB::table('application_dependencies')->count());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Écriture parcs
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_insert_parc_creates_group(): void
    {
        $id = insert_parc([], 'NEW-PARC', 'guid-new');

        $this->assertGreaterThan(0, $id);
        $group = WorkstationGroup::find($id);
        $this->assertEquals('NEW-PARC', $group->name);
        $this->assertEquals('guid-new', $group->ad_guid);
    }

    public function test_update_parc_updates_group(): void
    {
        $group = $this->createGroup();

        update_parc([], $group->id, 'RENAMED-PARC', 'new-guid');

        $group->refresh();
        $this->assertEquals('RENAMED-PARC', $group->name);
        $this->assertEquals('new-guid', $group->ad_guid);
    }

    public function test_delete_parc_wpkg_removes_group_and_associations(): void
    {
        $ws = $this->createWorkstation();
        $group = $this->createGroup();
        $group->workstations()->attach($ws->id);

        delete_parc_wpkg([], $group->id);

        $this->assertNull(WorkstationGroup::find($group->id));
        // Workstation still exists
        $this->assertNotNull(Workstation::find($ws->id));
    }

    public function test_insert_parc_profile_attaches_workstation(): void
    {
        $ws = $this->createWorkstation();
        $group = $this->createGroup();

        insert_parc_profile([], $ws->id, $group->id);

        $this->assertTrue($group->workstations()->where('workstation_id', $ws->id)->exists());
    }

    public function test_delete_parc_profile_detaches_workstation(): void
    {
        $ws = $this->createWorkstation();
        $group = $this->createGroup();
        $group->workstations()->attach($ws->id);

        delete_parc_profile([], $ws->id, $group->id);

        $this->assertFalse($group->workstations()->where('workstation_id', $ws->id)->exists());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Dépôts écriture
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_update_hash_depot_updates_hash(): void
    {
        $depot = $this->createDepot();

        update_hash_depot([], $depot->id, 'new_hash_value');

        $depot->refresh();
        $this->assertEquals('new_hash_value', $depot->xml_hash);
    }

    public function test_update_depot_principal_switches_primary(): void
    {
        $depot1 = $this->createDepot();
        $depot2 = $this->createDepot([
            'name' => 'Second',
            'url' => 'https://second.test',
            'is_primary' => false,
        ]);

        $result = update_depot_principal([], $depot2->id);

        $this->assertEquals(1, $result);
        $depot1->refresh();
        $depot2->refresh();
        $this->assertFalse($depot1->is_primary);
        $this->assertTrue($depot2->is_primary);
    }

    public function test_insert_appli_depot_creates_or_updates(): void
    {
        $depot = $this->createDepot();

        insert_appli_depot([], [
            'id_depot' => $depot->id,
            'id_nom_app' => 'new-depot-app',
            'nom_app' => 'New Depot App',
            'xml' => '<xml/>',
            'url_xml' => 'https://example.com/app.xml',
            'sha_xml' => 'shatest',
            'url_log' => '',
            'categorie' => 'Test',
            'compatibilite' => '64',
            'version' => '1.0',
            'branche' => 'stable',
            'date' => '2026-03-26',
        ]);

        $this->assertEquals(1, DepotApplication::where('app_id', 'new-depot-app')->count());

        // Second call should update, not duplicate
        insert_appli_depot([], [
            'id_depot' => $depot->id,
            'id_nom_app' => 'new-depot-app',
            'nom_app' => 'New Depot App Updated',
            'version' => '2.0',
            'branche' => 'stable',
        ]);

        $this->assertEquals(1, DepotApplication::where('app_id', 'new-depot-app')->count());
        $app = DepotApplication::where('app_id', 'new-depot-app')->first();
        $this->assertEquals('New Depot App Updated', $app->name);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Maintenance
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_maintenance_poste_protection(): void
    {
        $ws = $this->createWorkstation(['status' => 'active']);

        $result = maintenance_poste_protection([], $ws->id);

        $this->assertEquals($ws->name, $result);
        $ws->refresh();
        $this->assertEquals('protected', $ws->status);
    }

    public function test_maintenance_poste_protection_already_protected(): void
    {
        $ws = $this->createWorkstation(['status' => 'protected']);

        $result = maintenance_poste_protection([], $ws->id);
        $this->assertEquals(0, $result);
    }

    public function test_maintenance_poste_deprotection(): void
    {
        $ws = $this->createWorkstation(['status' => 'protected']);

        $result = maintenance_poste_deprotection([], $ws->id);

        $this->assertEquals($ws->name, $result);
        $ws->refresh();
        $this->assertEquals('active', $ws->status);
    }

    public function test_maintenance_poste_reset_wpkg(): void
    {
        $ws = $this->createWorkstation();

        $result = maintenance_poste_reset_wpkg([], $ws->id);

        $this->assertEquals($ws->name, $result);
        $ws->refresh();
        $this->assertEquals('miaou', $ws->report_sha);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Structure / utilitaires
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_test_parent_returns_1(): void
    {
        $this->assertEquals(1, test_parent([]));
    }

    public function test_test_mef_is_noop(): void
    {
        test_mef([]);
        $this->assertTrue(true); // No exception
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Erreur pour fonctions non shimmées
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_not_implemented_logs_error(): void
    {
        $mock = $this->mock(ErrorLoggerService::class);
        $mock->shouldReceive('log')
            ->once()
            ->with('legacy', \Mockery::pattern('/Fonction SQL non shimmée/'));

        $result = _sql_shim_not_implemented('fake_function');
        $this->assertEquals([], $result);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Rapport poste complet
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_info_poste_rapport_returns_md5_keyed(): void
    {
        $ws = $this->createWorkstation();
        $app = $this->makeApp();

        WorkstationApplicationStatus::create([
            'workstation_id' => $ws->id,
            'application_id' => $app->id,
            'installed_version' => '115.0',
            'status' => 'installed',
            'reboot_required' => false,
            'reported_at' => now(),
        ]);

        $result = info_poste_rapport([], $ws->name);

        $md5Key = hash('md5', $app->app_id);
        $this->assertArrayHasKey($md5Key, $result);
        $this->assertArrayHasKey('statut', $result);
        $entry = $result[$md5Key];
        $this->assertEquals($app->app_id, $entry['id_nom_app']);
        $this->assertEquals('installed', $entry['statut_poste_app']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — set_entite_apps sync
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_set_entite_apps_syncs_parc_applications(): void
    {
        $group = $this->createGroup();
        $app1 = $this->makeApp(['app_id' => 'app1', 'name' => 'App 1']);
        $app2 = $this->makeApp(['app_id' => 'app2', 'name' => 'App 2']);

        $result = set_entite_apps([], [$app1->id, $app2->id], $group->name, 'parc');

        $this->assertEquals(2, $result['in']);

        // Vérifier qu'un profil a été créé et les apps assignées
        $profile = $group->appProfiles()->first();
        $this->assertNotNull($profile);
        $this->assertEquals(2, $profile->applications()->count());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — info_sha_postes
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_info_sha_postes_returns_sha_by_path(): void
    {
        $this->createWorkstation();

        $result = info_sha_postes([]);

        $this->assertArrayHasKey('/var/rapport/test.xml', $result);
        $this->assertEquals('sha256test', $result['/var/rapport/test.xml']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — Guard double chargement
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_shim_defines_guard_constants(): void
    {
        $this->assertTrue(defined('SQL_SHIM_LOADED'));
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — info_postes_parcs
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_info_postes_parcs_returns_indexed_by_workstation(): void
    {
        $ws = $this->createWorkstation();
        $group = $this->createGroup();
        $group->workstations()->attach($ws->id);

        $result = info_postes_parcs([]);

        $this->assertArrayHasKey($ws->id, $result);
        $this->assertArrayHasKey($group->name, $result[$ws->id]);
        $entry = $result[$ws->id][$group->name];
        $this->assertEquals($group->id, $entry['id_parc']);
        $this->assertEquals($ws->id, $entry['id_poste']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — info_poste_statut
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_info_poste_statut_all_ok(): void
    {
        $ws = $this->createWorkstation();
        $app = $this->makeApp();
        WorkstationApplicationStatus::create([
            'workstation_id' => $ws->id,
            'application_id' => $app->id,
            'installed_version' => '115.0',
            'status' => 'installed',
        ]);

        $result = info_poste_statut([], $ws->id, [$app->id]);

        $this->assertEquals(1, $result['Ok']);
        $this->assertEquals(0, $result['MaJ']);
        $this->assertEquals(0, $result['Not_Ok-']);
        $this->assertEquals(0, $result['Status']);
    }

    public function test_info_poste_statut_version_mismatch(): void
    {
        $ws = $this->createWorkstation();
        $app = $this->makeApp(['version' => '120.0']);
        WorkstationApplicationStatus::create([
            'workstation_id' => $ws->id,
            'application_id' => $app->id,
            'installed_version' => '115.0',
            'status' => 'installed',
        ]);

        $result = info_poste_statut([], $ws->id, [$app->id]);

        $this->assertEquals(0, $result['Ok']);
        $this->assertEquals(1, $result['MaJ']);
        $this->assertEquals(1, $result['Status']);
    }

    public function test_info_poste_statut_not_installed(): void
    {
        $ws = $this->createWorkstation();
        $app = $this->makeApp();

        $result = info_poste_statut([], $ws->id, [$app->id]);

        $this->assertEquals(1, $result['Not_Ok-']);
        $this->assertEquals(2, $result['Status']);
    }

    public function test_info_poste_statut_unwanted_app(): void
    {
        $ws = $this->createWorkstation();
        $app = $this->makeApp();
        $extra = $this->makeApp(['app_id' => 'unwanted', 'name' => 'Unwanted']);
        WorkstationApplicationStatus::create([
            'workstation_id' => $ws->id,
            'application_id' => $extra->id,
            'installed_version' => '1.0',
            'status' => 'installed',
        ]);

        $result = info_poste_statut([], $ws->id, [$app->id]);

        $this->assertEquals(1, $result['Not_Ok+']);
        $this->assertEquals(2, $result['Status']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — info_poste_appli_full / info_parc_appli_full
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_info_poste_appli_full_returns_apps_with_deps(): void
    {
        $ws = $this->createWorkstation();
        $group = $this->createGroup();
        $group->workstations()->attach($ws->id);
        $app = $this->makeApp();
        $dep = $this->makeApp(['app_id' => 'dep-lib', 'name' => 'Dep Lib']);
        $profile = AppProfile::create(['name' => 'profile_test', 'is_active' => true]);
        $profile->applications()->attach($app->id);
        $group->appProfiles()->attach($profile->id);
        DB::table('application_dependencies')->insert([
            'application_id' => $app->id,
            'required_application_id' => $dep->id,
        ]);

        $result = info_poste_appli_full([], $ws->name);

        $this->assertArrayHasKey($app->id, $result);
        $this->assertEquals($ws->name, $result[$app->id]['poste']);
        $this->assertArrayHasKey($dep->id, $result);
        $this->assertContains('firefox-esr', $result[$dep->id]['depends']);
    }

    public function test_info_poste_appli_full_unknown_returns_empty(): void
    {
        $this->assertEmpty(info_poste_appli_full([], 'UNKNOWN'));
    }

    public function test_info_parc_appli_full_returns_apps_with_deps(): void
    {
        $group = $this->createGroup();
        $app = $this->makeApp();
        $dep = $this->makeApp(['app_id' => 'dep-lib', 'name' => 'Dep Lib']);
        $profile = AppProfile::create(['name' => 'profile_test', 'is_active' => true]);
        $profile->applications()->attach($app->id);
        $group->appProfiles()->attach($profile->id);
        DB::table('application_dependencies')->insert([
            'application_id' => $app->id,
            'required_application_id' => $dep->id,
        ]);

        $result = info_parc_appli_full([], $group->name);

        $this->assertArrayHasKey($app->id, $result);
        $this->assertEquals($group->name, $result[$app->id]['parc']);
        $this->assertArrayHasKey($dep->id, $result);
        $this->assertContains('firefox-esr', $result[$dep->id]['depends']);
    }

    public function test_info_parc_appli_full_deduplicates_depends(): void
    {
        $group = $this->createGroup();
        $app = $this->makeApp();
        $dep = $this->makeApp(['app_id' => 'dep-lib', 'name' => 'Dep Lib']);
        // Deux profils qui assignent la même app → dépendance calculée deux fois
        $profile1 = AppProfile::create(['name' => 'profile_1', 'is_active' => true]);
        $profile2 = AppProfile::create(['name' => 'profile_2', 'is_active' => true]);
        $profile1->applications()->attach($app->id);
        $profile2->applications()->attach($app->id);
        $group->appProfiles()->attach([$profile1->id, $profile2->id]);
        DB::table('application_dependencies')->insert([
            'application_id' => $app->id,
            'required_application_id' => $dep->id,
        ]);

        $result = info_parc_appli_full([], $group->name);

        $this->assertCount(1, $result[$dep->id]['depends']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — info_application_postes / info_application_rapport
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_info_application_postes_returns_workstations(): void
    {
        $ws = $this->createWorkstation();
        $group = $this->createGroup();
        $group->workstations()->attach($ws->id);
        $app = $this->makeApp();
        $profile = AppProfile::create(['name' => 'profile_test', 'is_active' => true]);
        $profile->applications()->attach($app->id);
        $group->appProfiles()->attach($profile->id);

        $result = info_application_postes([], $app->app_id);

        $this->assertArrayHasKey($ws->name, $result);
        $this->assertEquals($ws->id, $result[$ws->name]['info_poste']['id_poste']);
        $this->assertArrayHasKey($group->name, $result[$ws->name]['parc']);
    }

    public function test_info_application_postes_unknown_returns_empty(): void
    {
        $this->assertEmpty(info_application_postes([], 'nonexistent'));
    }

    public function test_info_application_rapport_returns_report_by_ws(): void
    {
        $ws = $this->createWorkstation();
        $app = $this->makeApp();
        WorkstationApplicationStatus::create([
            'workstation_id' => $ws->id,
            'application_id' => $app->id,
            'installed_version' => '115.0',
            'status' => 'installed',
            'reboot_required' => false,
        ]);

        $result = info_application_rapport([], $app->app_id);

        $this->assertArrayHasKey($ws->name, $result);
        $entry = $result[$ws->name];
        $this->assertEquals($ws->id, $entry['id_poste']);
        $this->assertEquals('installed', $entry['statut_poste_app']);
        $this->assertEquals('115.0', $entry['revision_poste_app']);
        $this->assertEquals(0, $entry['reboot_poste_app']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — insert_journal_app
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_insert_journal_app_creates_log(): void
    {
        $app = $this->makeApp();

        insert_journal_app([], $app->id, [
            'operation_journal_app' => 'install',
            'user_journal_app' => 'admin',
            'date_journal_app' => '2026-03-26',
        ]);

        $log = DB::table('installation_logs')
            ->where('application_id', $app->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertEquals('install', $log->status);
        $this->assertEquals('admin - 2026-03-26', $log->message);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — truncate_table_profiles
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_truncate_table_profiles_clears_all_pivots(): void
    {
        $ws = $this->createWorkstation();
        $group = $this->createGroup();
        $group->workstations()->attach($ws->id);
        $app = $this->makeApp();
        $profile = AppProfile::create(['name' => 'profile_test', 'is_active' => true]);
        $profile->applications()->attach($app->id);
        $group->appProfiles()->attach($profile->id);

        truncate_table_profiles([]);

        $this->assertEquals(0, DB::table('app_profile_application')->count());
        $this->assertEquals(0, DB::table('app_profile_workstation_group')->count());
        $this->assertEquals(0, DB::table('workstation_group_workstation')->count());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — set_appli_entites
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_set_appli_entites_removes_from_unlisted_groups(): void
    {
        $group1 = $this->createGroup(['name' => 'GROUP-1']);
        $group2 = $this->createGroup(['name' => 'GROUP-2']);
        $app = $this->makeApp();

        // Assigner l'app aux deux groupes via insert_application_profile
        insert_application_profile([], 'parc', $group1->id, $app->id);
        insert_application_profile([], 'parc', $group2->id, $app->id);

        // Retirer du groupe 2 en ne listant que groupe 1
        $result = set_appli_entites([], [$group1->id], 'parc', $app->app_id);

        $this->assertEquals(1, $result['out']);
        $profile2 = $group2->fresh()->appProfiles()->first();
        if ($profile2) {
            $this->assertFalse($profile2->applications()->where('applications.id', $app->id)->exists());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — maintenance_liste_poste
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_maintenance_liste_poste_filters_by_flag(): void
    {
        $this->createWorkstation(['name' => 'ACTIVE-PC', 'status' => 'active']);
        $this->createWorkstation(['name' => 'PROTECTED-PC', 'status' => 'protected']);

        // flag=0 → active seulement
        $result = maintenance_liste_poste([], 0, -1);
        $names = array_column($result, 'nom_poste');
        $this->assertContains('active-pc', $names);
        $this->assertNotContains('protected-pc', $names);
    }

    public function test_maintenance_liste_poste_filters_by_uuid(): void
    {
        $this->createWorkstation(['name' => 'WITH-UUID', 'uuid' => 'c2eebc99-9c0b-4ef8-bb6d-6bb9bd380a33']);
        $this->createWorkstation(['name' => 'NO-UUID', 'uuid' => null]);

        // uuid=1 → avec uuid seulement
        $result = maintenance_liste_poste([], -1, 1);
        $names = array_column($result, 'nom_poste');
        $this->assertContains('with-uuid', $names);
        $this->assertNotContains('no-uuid', $names);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — maintenance_poste_suppression
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_maintenance_poste_suppression_marks_for_deletion(): void
    {
        $ws = $this->createWorkstation(['uuid' => null]);

        $result = maintenance_poste_suppression([], $ws->id);

        $this->assertEquals($ws->name, $result);
        $ws->refresh();
        $this->assertEquals('pending-deletion', $ws->status);
    }

    public function test_maintenance_poste_suppression_idempotent(): void
    {
        $ws = $this->createWorkstation(['uuid' => null]);

        maintenance_poste_suppression([], $ws->id);
        $result = maintenance_poste_suppression([], $ws->id);

        $this->assertEquals($ws->name, $result);
    }

    public function test_maintenance_poste_suppression_with_uuid_returns_zero(): void
    {
        $ws = $this->createWorkstation(['uuid' => 'c2eebc99-9c0b-4ef8-bb6d-6bb9bd380a33']);

        $result = maintenance_poste_suppression([], $ws->id);

        $this->assertEquals(0, $result);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — info_appli_version_depot
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_info_appli_version_depot_returns_depot_app_info(): void
    {
        $depot = $this->createDepot();
        $depotApp = DepotApplication::create([
            'depot_id' => $depot->id,
            'app_id' => 'vlc',
            'name' => 'VLC Media Player',
            'version' => '3.0.20',
            'category' => 'Multimedia',
            'branch' => 'stable',
        ]);

        $result = info_appli_version_depot([], $depot->id, 'vlc');

        $this->assertCount(1, $result);
        $this->assertEquals('vlc', $result[0]['id_nom_app']);
        $this->assertEquals('3.0.20', $result[0]['version']);
        $this->assertEquals('stable', $result[0]['branche']);
    }

    public function test_info_appli_version_depot_empty_for_unknown(): void
    {
        $depot = $this->createDepot();

        $result = info_appli_version_depot([], $depot->id, 'nonexistent');

        $this->assertEmpty($result);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TESTS — update_sha_xml_journal (path traversal protection)
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_update_sha_xml_journal_rejects_path_traversal(): void
    {
        $app = $this->makeApp();
        DB::table('installation_logs')->insert([
            'application_id' => $app->id,
            'status' => 'install',
            'message' => '../../../etc/passwd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $originalSha = $app->xml_sha;
        update_sha_xml_journal([], '/tmp/xml/');

        $app->refresh();
        $this->assertEquals($originalSha, $app->xml_sha);
    }
}
