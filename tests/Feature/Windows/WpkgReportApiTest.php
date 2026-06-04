<?php

declare(strict_types=1);

namespace Tests\Feature\Windows;

use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests Feature : endpoint POST /api/wpkg/reports/{hostname}
 *
 * Couverture :
 *   - POST rapport valide → 200, SHA + statuts persistés
 *   - POST rapport identique (même SHA) → 304, pas de double insert
 *   - POST depuis IP non-locale → 403
 *   - POST rapport malformé → 422
 *   - POST pour hostname inconnu → 404
 *
 * Convention :
 *   - DatabaseTransactions (rollback auto)
 *   - $this->withoutVite() dans setUp()
 *   - Tables créées manuellement si SQLite :memory:
 */
class WpkgReportApiTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Autoriser les requêtes locales (127.0.0.1 est l'IP de test par défaut)
        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', '127.0.0.1,::1');

        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('wpkg_deployment_workstation_status');
            Schema::dropIfExists('wpkg_deployments');
            Schema::dropIfExists('app_profile_workstation_group');
            Schema::dropIfExists('app_profile_workstation');
            Schema::dropIfExists('app_profiles');
            Schema::dropIfExists('workstation_group_workstation');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('workstation_application_status');
            Schema::dropIfExists('applications');
            Schema::dropIfExists('workstations');
        }
        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createTablesIfNeeded(): void
    {
        if (Schema::hasTable('workstations')) {
            return;
        }

        $this->createdTables = true;

        Schema::create('workstations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('os', 100)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('mac', 17)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_report_at')->nullable();
            $table->string('report_sha', 64)->nullable();
            $table->text('log_path')->nullable();
            $table->text('report_path')->nullable();
            $table->string('ad_dn', 512)->nullable();
            $table->string('ad_guid', 36)->nullable();
            $table->boolean('managed_by_control_hub')->default(false);
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 100)->unique();
            $table->string('name', 255)->nullable();
            $table->string('version', 50)->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();
        });

        Schema::create('workstation_application_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workstation_id');
            $table->unsignedBigInteger('application_id');
            $table->string('installed_version', 255)->nullable();
            $table->string('status', 20);
            $table->boolean('reboot_required')->default(false);
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
            $table->unique(['workstation_id', 'application_id']);
        });

        // Story 15.5 — corrélation rapport → déploiement actif via
        // ActiveDeploymentForWorkstationQuery (eager-load groups + appProfiles).
        Schema::create('workstation_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workstation_group_workstation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workstation_id');
            $table->unsignedBigInteger('workstation_group_id');
            $table->timestamps();
        });

        Schema::create('app_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('app_profile_workstation', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_profile_id');
            $table->unsignedBigInteger('workstation_id');
            $table->timestamps();
        });

        Schema::create('app_profile_workstation_group', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_profile_id');
            $table->unsignedBigInteger('workstation_group_id');
            $table->timestamps();
        });

        Schema::create('wpkg_deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestamp('triggered_at');
            $table->json('target_scope')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('wpkg_deployment_workstation_status', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('deployment_id');
            $table->unsignedBigInteger('workstation_id');
            $table->unsignedBigInteger('app_profile_id')->nullable();
            $table->timestamp('client_reported_at')->nullable();
            $table->string('client_status', 20)->default('pending');
            $table->json('details')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Retourne le contenu du rapport de test valide.
     */
    private function validReportContent(): string
    {
        return file_get_contents(
            base_path('tests/fixtures/wpkg/reports/valid_report.txt')
        );
    }

    /**
     * Crée une workstation en base pour les tests.
     */
    private function makeWorkstation(string $name = 'PC-SALLE-01'): Workstation
    {
        return Workstation::create([
            'name'   => $name,
            'status' => 'active',
        ]);
    }

    /**
     * Crée une application en base pour les tests.
     */
    private function makeApplication(string $appId): Application
    {
        return Application::create([
            'app_id' => $appId,
            'name'   => $appId,
        ]);
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    /**
     * AC #4 : POST rapport valide → 200, SHA + statuts persistés.
     */
    public function test_post_valid_report_returns_200_and_persists(): void
    {
        $ws = $this->makeWorkstation();
        $this->makeApplication('firefox');
        $this->makeApplication('libreoffice');
        $this->makeApplication('vlc');

        $content = $this->validReportContent();

        $response = $this->postJson(
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            ['Content-Type' => 'text/plain']
        );

        // PHPUnit ne permet pas d'envoyer du texte brut via postJson, on utilise call()
        $response = $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $content
        );

        $response->assertStatus(200);
        $response->assertJson(['status' => 'processed']);
        $this->assertArrayHasKey('packages_count', $response->json());

        // SHA doit être persisté
        $ws->refresh();
        $this->assertEquals(hash('sha256', $content), $ws->report_sha);
        $this->assertNotNull($ws->last_report_at);

        // Statuts d'application persistés
        $count = WorkstationApplicationStatus::where('workstation_id', $ws->id)->count();
        $this->assertGreaterThan(0, $count);
    }

    /**
     * AC #4 : POST rapport identique (même SHA) → 200 unchanged (Fix #10 : 304 → 200).
     */
    public function test_post_identical_report_returns_200_unchanged(): void
    {
        $ws = $this->makeWorkstation();
        $content = $this->validReportContent();
        $sha = hash('sha256', $content);

        // Pré-charger le SHA pour simuler un rapport déjà ingéré
        $ws->update(['report_sha' => $sha]);

        $response = $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $content
        );

        $response->assertStatus(200);
        $response->assertJson(['status' => 'unchanged']);
    }

    /**
     * Fix #9 : POST avec rapport malformé → 422.
     * Utilise le fixture malformed_report.txt (aucun bloc valide, pas de séparateur ---).
     */
    public function test_malformed_report_returns_422(): void
    {
        $this->makeWorkstation();

        $malformed = file_get_contents(
            base_path('tests/fixtures/wpkg/reports/malformed_report.txt')
        );

        $response = $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $malformed
        );

        $response->assertStatus(422);
    }

    /**
     * Fix #1 : header contient "Windows 10" → workstation.os = 'Windows 10' après ingestion.
     */
    public function test_os_windows10_detected_from_header(): void
    {
        $ws = $this->makeWorkstation();
        $this->makeApplication('firefox');

        // Rapport dont la première ligne contient "Windows 10"
        $content = "2024-01-15 08:30:00 PC-SALLE-01 Windows 10 AA:BB:CC:DD:EE:FF [10.0.0.50]\n"
            . "ID: firefox\nRevision: 125.0\nReboot: false\nStatus: Installed\n---\n";

        $response = $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $content
        );

        $response->assertStatus(200);

        $ws->refresh();
        $this->assertEquals('Windows 10', $ws->os);
        $this->assertEquals('PC-SALLE-01.log', $ws->log_path);
        $this->assertEquals('PC-SALLE-01.txt', $ws->report_path);
    }

    /**
     * AC #5 : POST depuis IP non-locale → 403.
     */
    public function test_post_from_non_local_ip_returns_403(): void
    {
        $this->makeWorkstation();

        // Simuler une IP externe
        $response = $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'   => 'text/plain',
                'HTTP_ACCEPT'    => 'application/json',
                'REMOTE_ADDR'    => '192.168.100.200',
                'HTTP_X_FORWARDED_FOR' => '192.168.100.200',
            ],
            'some content'
        );

        $response->assertStatus(403);
    }

    /**
     * AC #4 : POST rapport malformé → 422.
     */
    public function test_post_empty_report_returns_422(): void
    {
        $this->makeWorkstation();

        $response = $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            '' // corps vide
        );

        $response->assertStatus(422);
    }

    /**
     * AC #4 : POST pour hostname inconnu → 404.
     */
    public function test_post_for_unknown_hostname_returns_404(): void
    {
        $response = $this->call(
            'POST',
            '/api/wpkg/reports/MACHINE-INCONNUE',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $this->validReportContent()
        );

        $response->assertStatus(404);
    }

    /**
     * AC #4 : Idempotence — deux appels successifs avec le même contenu
     * ne doublent pas les entrées en base.
     */
    public function test_duplicate_ingestion_does_not_double_insert(): void
    {
        $ws = $this->makeWorkstation();
        $this->makeApplication('firefox');
        $content = $this->validReportContent();

        // Premier appel
        $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $content
        );

        $countAfterFirst = WorkstationApplicationStatus::where('workstation_id', $ws->id)->count();

        // Deuxième appel avec même contenu → SHA identique → 304
        $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $content
        );

        $countAfterSecond = WorkstationApplicationStatus::where('workstation_id', $ws->id)->count();

        $this->assertEquals($countAfterFirst, $countAfterSecond, 'Un double insert a eu lieu malgré SHA identique.');
    }

    /**
     * Fix #3 : POST avec BOM UTF-8 en tête → 200 + parse OK.
     */
    public function test_post_report_with_bom_returns_200(): void
    {
        $this->makeWorkstation();
        $this->makeApplication('firefox');
        $this->makeApplication('libreoffice');
        $this->makeApplication('vlc');

        $content = $this->validReportContent();
        // Préfixer avec le BOM UTF-8
        $contentWithBom = "\xEF\xBB\xBF" . $content;

        $response = $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $contentWithBom
        );

        // Le contenu avec BOM doit être parsé correctement (200, pas 422)
        $response->assertStatus(200);
        $response->assertJson(['status' => 'processed']);
    }

    /**
     * Fix #5 : POST avec Content-Type invalide → 415.
     */
    public function test_post_with_invalid_content_type_returns_415(): void
    {
        $this->makeWorkstation();

        $response = $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"some":"json"}'
        );

        $response->assertStatus(415);
        $response->assertJson(['error' => 'unsupported_media_type']);
    }

    /**
     * Fix #5 : POST avec payload trop gros (Content-Length > 2 MiB) → 413.
     */
    public function test_post_with_oversized_payload_returns_413(): void
    {
        $this->makeWorkstation();

        $response = $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'   => 'text/plain',
                'HTTP_ACCEPT'    => 'application/json',
                'CONTENT_LENGTH' => '3000000', // 3 MiB — Symfony lit CONTENT_LENGTH (pas HTTP_CONTENT_LENGTH)
            ],
            str_repeat('x', 100) // body court mais Content-Length header menteur (test du check header)
        );

        $response->assertStatus(413);
        $response->assertJson(['error' => 'payload_too_large']);
    }

    /**
     * Test que le Cache::lock est utilisé (pas de deadlock, exécution normale).
     */
    public function test_cache_lock_released_after_ingestion(): void
    {
        $this->makeWorkstation();
        $this->makeApplication('firefox');
        $content = $this->validReportContent();

        $this->call(
            'POST',
            '/api/wpkg/reports/PC-SALLE-01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $content
        );

        // Le verrou doit être libéré après l'ingestion
        $lock = Cache::lock('wpkg-report:PC-SALLE-01', 1);
        $acquired = $lock->get();
        $this->assertTrue($acquired, 'Le verrou cache na pas été libéré après lingestion.');
        if ($acquired) {
            $lock->release();
        }
    }
}
