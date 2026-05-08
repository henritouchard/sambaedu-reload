<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Reports;

use App\Models\Workstation;
use App\Wpkg\Deployment\Models\WorkstationApiSecret;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.5 / AC1.2 + AC6.1 — Tests middleware Bearer auth.
 *
 * Couvre :
 *   - Bearer valide → 200
 *   - Bearer invalide → 401
 *   - Bearer revoked → 401
 *   - Bearer absent + IP non locale → 403 (compat 9.4)
 *   - Bearer absent + IP locale → fallback Phase 1 → 200
 *   - Bearer expiré (rotation chevauchement épuisée) → 401
 *   - Bearer ancien dans la fenêtre rotation → 200
 */
final class WorkstationBearerAuthTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Config::set('sambaedu.wpkg.report_ingestion_allowed_ips', '127.0.0.1,::1');
        Config::set('sambaedu.wpkg.secret_rotation_overlap_days', 7);

        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('wpkg_deployment_workstation_status');
            Schema::dropIfExists('wpkg_deployments');
            Schema::dropIfExists('workstation_api_secrets');
            Schema::dropIfExists('workstation_application_status');
            Schema::dropIfExists('applications');
            Schema::dropIfExists('app_profile_workstation_group');
            Schema::dropIfExists('app_profile_workstation');
            Schema::dropIfExists('app_profiles');
            Schema::dropIfExists('workstation_group_workstation');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('workstations');
        }

        parent::tearDown();
    }

    private function createTablesIfNeeded(): void
    {
        if (Schema::hasTable('workstations')) {
            return;
        }

        $this->createdTables = true;

        // Story 15.5 / Fix #4 — `WpkgReportIngestionService` ne shortcuit plus
        // sur `Schema::hasTable('wpkg_deployments')` ; les tables de relations
        // (groups/profiles) doivent exister pour que la corrélation eager-load
        // ne crashe pas.
        Schema::create('workstation_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
        Schema::create('workstation_group_workstation', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workstation_id');
            $t->unsignedBigInteger('workstation_group_id');
            $t->timestamps();
        });
        Schema::create('app_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
        Schema::create('app_profile_workstation', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('app_profile_id');
            $t->unsignedBigInteger('workstation_id');
            $t->timestamps();
        });
        Schema::create('app_profile_workstation_group', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('app_profile_id');
            $t->unsignedBigInteger('workstation_group_id');
            $t->timestamps();
        });

        Schema::create('workstations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('status', 20)->default('active');
            $table->string('ip', 45)->nullable();
            $table->string('mac', 17)->nullable();
            $table->string('os', 100)->nullable();
            $table->timestamp('last_report_at')->nullable();
            $table->string('report_sha', 64)->nullable();
            $table->text('log_path')->nullable();
            $table->text('report_path')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 100)->unique();
            $table->string('name', 255)->nullable();
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
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['workstation_id', 'application_id']);
        });

        Schema::create('workstation_api_secrets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workstation_id')->unique();
            $table->string('secret_hash', 255);
            $table->string('previous_secret_hash', 255)->nullable();
            $table->timestamp('previous_valid_until')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // wpkg_deployments + wpkg_deployment_workstation_status nécessaires
        // pour que ActiveDeploymentForWorkstationQuery::find() fonctionne sans
        // erreur (pas de match → return null, comportement attendu en bearer auth tests).
        Schema::create('wpkg_deployments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('triggered_by')->nullable();
            $t->timestamp('triggered_at');
            $t->json('target_scope')->nullable();
            $t->string('status', 20)->default('pending');
            $t->json('summary')->nullable();
            $t->timestamps();
        });

        Schema::create('wpkg_deployment_workstation_status', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('deployment_id');
            $t->unsignedBigInteger('workstation_id');
            $t->unsignedBigInteger('app_profile_id')->nullable();
            $t->timestamp('client_reported_at')->nullable();
            $t->string('client_status', 20)->default('pending');
            $t->json('details')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamps();
        });
    }

    private function makeWorkstation(string $hostname = 'PC-AUTH-01'): Workstation
    {
        return Workstation::create([
            'name' => $hostname,
            'status' => 'active',
        ]);
    }

    private function makeSecret(Workstation $w, string $clearSecret): WorkstationApiSecret
    {
        return WorkstationApiSecret::create([
            'workstation_id' => $w->id,
            'secret_hash' => Hash::make($clearSecret),
        ]);
    }

    private function postReport(string $hostname, string $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $serverHeaders = ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $key => $value) {
            $serverHeaders['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $this->call(
            'POST',
            "/api/v1/wpkg/reports/{$hostname}",
            [],
            [],
            [],
            $serverHeaders,
            $body,
        );
    }

    private function buildReport(string $hostname): string
    {
        return "2026-05-08 09:30:00 {$hostname} AA:BB:CC:DD:EE:FF [10.0.0.42]\n"
            . "ID: firefox\nRevision: 115.0\nReboot: false\nStatus: Installed\n---\n";
    }

    #[Test]
    public function valid_bearer_returns_200(): void
    {
        $w = $this->makeWorkstation();
        $secret = 'test-secret-32-bytes-aaaaaaaaaaaa';
        $this->makeSecret($w, $secret);
        \App\Models\Application::create(['app_id' => 'firefox', 'name' => 'firefox']);

        $response = $this->postReport($w->name, $this->buildReport($w->name), [
            'Authorization' => "Bearer {$secret}",
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'processed']);
    }

    #[Test]
    public function invalid_bearer_returns_401(): void
    {
        $w = $this->makeWorkstation();
        $this->makeSecret($w, 'real-secret');

        $response = $this->postReport($w->name, $this->buildReport($w->name), [
            'Authorization' => 'Bearer wrong-secret',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function revoked_bearer_returns_401(): void
    {
        $w = $this->makeWorkstation();
        $secret = 'revoked-secret';
        $row = $this->makeSecret($w, $secret);
        $row->update(['revoked_at' => now()]);

        $response = $this->postReport($w->name, $this->buildReport($w->name), [
            'Authorization' => "Bearer {$secret}",
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function bearer_absent_with_local_ip_falls_back_phase1(): void
    {
        $w = $this->makeWorkstation();
        \App\Models\Application::create(['app_id' => 'firefox', 'name' => 'firefox']);

        $response = $this->postReport($w->name, $this->buildReport($w->name));

        // 127.0.0.1 (default REMOTE_ADDR test) → Phase 1 OK → 200
        $response->assertStatus(200);
    }

    #[Test]
    public function bearer_absent_with_non_local_ip_returns_403(): void
    {
        $w = $this->makeWorkstation();

        $response = $this->call(
            'POST',
            "/api/v1/wpkg/reports/{$w->name}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'text/plain',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '192.168.100.200',
            ],
            $this->buildReport($w->name),
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function previous_secret_in_rotation_window_is_accepted(): void
    {
        $w = $this->makeWorkstation();
        $oldSecret = 'old-secret-still-valid';
        $newSecret = 'new-secret-current';

        WorkstationApiSecret::create([
            'workstation_id' => $w->id,
            'secret_hash' => Hash::make($newSecret),
            'previous_secret_hash' => Hash::make($oldSecret),
            'previous_valid_until' => now()->addDays(3),
            'rotated_at' => now()->subDays(1),
        ]);

        \App\Models\Application::create(['app_id' => 'firefox', 'name' => 'firefox']);

        $response = $this->postReport($w->name, $this->buildReport($w->name), [
            'Authorization' => "Bearer {$oldSecret}",
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function expired_previous_secret_is_rejected(): void
    {
        $w = $this->makeWorkstation();
        $oldSecret = 'old-secret-expired';
        $newSecret = 'current-secret';

        WorkstationApiSecret::create([
            'workstation_id' => $w->id,
            'secret_hash' => Hash::make($newSecret),
            'previous_secret_hash' => Hash::make($oldSecret),
            'previous_valid_until' => now()->subDays(1),
            'rotated_at' => now()->subDays(8),
        ]);

        $response = $this->postReport($w->name, $this->buildReport($w->name), [
            'Authorization' => "Bearer {$oldSecret}",
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function legacy_route_remains_functional_for_local_ip(): void
    {
        // Story 9.4 non-régression : la route legacy `/api/wpkg/reports/{hostname}`
        // doit continuer à fonctionner depuis localhost (Phase 1 fallback).
        $w = $this->makeWorkstation();
        \App\Models\Application::create(['app_id' => 'firefox', 'name' => 'firefox']);

        $response = $this->call(
            'POST',
            "/api/wpkg/reports/{$w->name}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            $this->buildReport($w->name),
        );

        $response->assertStatus(200);
        $response->assertJson(['status' => 'processed']);
    }
}
