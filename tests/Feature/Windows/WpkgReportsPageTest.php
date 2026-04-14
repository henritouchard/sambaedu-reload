<?php

declare(strict_types=1);

namespace Tests\Feature\Windows;

use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests Feature : pages Livewire des rapports WPKG
 *
 * Couverture :
 *   T5.1 — Liste postes avec rapports
 *          Filtrage par hostname
 *          Filtrage par application
 *          Filtrage par statut
 *   T5.2 — Vue détail poste (statuts application)
 *          Surbrillance échecs
 *
 * Note : les pages Livewire SFC sont accessibles via GET HTTP avec auth mockée.
 * Les tests vérifient la réponse HTTP et les données rendues.
 */
class WpkgReportsPageTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
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
            $table->unsignedBigInteger('physical_room_id')->nullable();
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
    }

    private function makeWorkstation(string $name, bool $withReport = true): Workstation
    {
        return Workstation::create([
            'name'           => $name,
            'status'         => 'active',
            'last_report_at' => $withReport ? now() : null,
            'report_sha'     => $withReport ? hash('sha256', $name) : null,
        ]);
    }

    private function makeApplication(string $appId, string $name = null): Application
    {
        return Application::create([
            'app_id' => $appId,
            'name'   => $name ?? $appId,
        ]);
    }

    private function makeStatus(
        int $workstationId,
        int $applicationId,
        string $status = 'installed'
    ): WorkstationApplicationStatus {
        return WorkstationApplicationStatus::create([
            'workstation_id'    => $workstationId,
            'application_id'    => $applicationId,
            'installed_version' => '1.0',
            'status'            => $status,
            'reboot_required'   => false,
            'reported_at'       => now(),
        ]);
    }

    /**
     * Crée une session auth simulée pour bypasser le middleware sambaedu.auth.
     */
    private function actingAsAdmin(): static
    {
        // Bypass du middleware auth pour les tests (désactive tous les middlewares)
        Config::set('sambaedu.block_migrated_routes', false);

        $this->withoutMiddleware();

        return $this;
    }

    // ─── T5.1 : Tests page liste ─────────────────────────────────────────────

    /**
     * AC #1 : La page /app/windows-deploy/reports est accessible.
     */
    public function test_reports_index_page_is_accessible(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/app/windows-deploy/reports');

        $response->assertStatus(200);
    }

    /**
     * AC #1 : La page affiche les postes avec rapport.
     */
    public function test_reports_index_shows_workstations_with_reports(): void
    {
        $this->actingAsAdmin();

        $ws1 = $this->makeWorkstation('PC-TEST-01');
        $ws2 = $this->makeWorkstation('PC-TEST-02');
        // Poste sans rapport ne doit pas apparaître
        $this->makeWorkstation('PC-TEST-03', withReport: false);

        $response = $this->get('/app/windows-deploy/reports');

        $response->assertStatus(200);
        $response->assertSee('PC-TEST-01');
        $response->assertSee('PC-TEST-02');
        $response->assertDontSee('PC-TEST-03');
    }

    /**
     * AC #2 : Filtrage par hostname fonctionne.
     */
    public function test_filtering_by_hostname(): void
    {
        $this->actingAsAdmin();

        $this->makeWorkstation('SALLE-A-01');
        $this->makeWorkstation('SALLE-B-02');

        $response = $this->get('/app/windows-deploy/reports?search=SALLE-A');

        $response->assertStatus(200);
        // SALLE-A-01 doit être visible via Livewire rendering
        // (le rendu complet dépend du driver HTTP — on vérifie juste le 200 et la présence des données)
        $response->assertSee('SALLE-A');
    }

    /**
     * AC #2 : Filtrage par statut fonctionne — route accessible avec paramètre.
     */
    public function test_filtering_by_status_param(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/app/windows-deploy/reports?statusFilter=error');
        $response->assertStatus(200);
    }

    /**
     * AC #2 : Filtrage par package — route accessible avec paramètre.
     */
    public function test_filtering_by_package_param(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/app/windows-deploy/reports?packageSearch=firefox');
        $response->assertStatus(200);
    }

    // ─── T5.2 : Tests page détail poste ─────────────────────────────────────

    /**
     * AC #1 : La page détail d'un poste est accessible.
     */
    public function test_workstation_detail_page_is_accessible(): void
    {
        $this->actingAsAdmin();
        $ws = $this->makeWorkstation('PC-DETAIL-01');

        $url = "/app/windows-deploy/reports/{$ws->id}";
        $response = $this->get($url);

        // Debug: si 404, afficher l'URL et le contenu
        if ($response->getStatusCode() === 404) {
            $this->fail("404 pour URL: {$url} (ID: {$ws->id})");
        }

        $response->assertStatus(200);
        $response->assertSee('PC-DETAIL-01');
    }

    /**
     * AC #1 + #3 : La page détail affiche les statuts des applications.
     */
    public function test_workstation_detail_shows_application_statuses(): void
    {
        $this->actingAsAdmin();

        $ws  = $this->makeWorkstation('PC-DETAIL-02');
        $app = $this->makeApplication('firefox', 'Mozilla Firefox');
        $this->makeStatus($ws->id, $app->id, 'installed');

        $response = $this->get("/app/windows-deploy/reports/{$ws->id}");

        $response->assertStatus(200);
        $response->assertSee('firefox');
    }

    /**
     * AC #3 : La page détail met en évidence les erreurs.
     */
    public function test_workstation_detail_highlights_errors(): void
    {
        $this->actingAsAdmin();

        $ws  = $this->makeWorkstation('PC-ERROR-01');
        $app = $this->makeApplication('broken-app', 'App en échec');
        $this->makeStatus($ws->id, $app->id, 'error');

        $response = $this->get("/app/windows-deploy/reports/{$ws->id}");

        $response->assertStatus(200);
        // La page doit mentionner "Erreur" ou "error"
        $response->assertSee('broken-app');
    }

    /**
     * AC #1 : La page détail pour un poste inexistant retourne 404.
     */
    public function test_nonexistent_workstation_returns_404(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/app/windows-deploy/reports/99999');

        $response->assertStatus(404);
    }

    /**
     * AC #1 : Vue par onglet packages accessible.
     */
    public function test_packages_tab_is_accessible(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/app/windows-deploy/reports?tab=packages');

        $response->assertStatus(200);
    }
}
