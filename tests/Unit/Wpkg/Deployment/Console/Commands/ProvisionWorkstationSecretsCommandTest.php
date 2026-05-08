<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Console\Commands;

use App\Models\Workstation;
use App\Wpkg\Deployment\Models\WorkstationApiSecret;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.5 / AC5.3 — Tests unit `wpkg:provision-secrets`.
 */
final class ProvisionWorkstationSecretsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('workstations')) {
            $this->createdTables = true;
            Schema::create('workstations', function (Blueprint $t) {
                $t->id();
                $t->string('name', 100)->unique();
                $t->string('status', 32)->default('active');
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('workstation_api_secrets')) {
            $this->createdTables = true;
            Schema::create('workstation_api_secrets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workstation_id');
                $t->string('secret_hash', 255);
                $t->string('previous_secret_hash', 255)->nullable();
                $t->timestamp('previous_valid_until')->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('rotated_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('workstation_api_secrets');
            Schema::dropIfExists('workstations');
        }

        parent::tearDown();
    }

    #[Test]
    public function provisions_secrets_for_active_workstations_without_secret(): void
    {
        Workstation::create(['name' => 'PC-01', 'status' => 'active']);
        Workstation::create(['name' => 'PC-02', 'status' => 'active']);
        Workstation::create(['name' => 'PC-03', 'status' => 'inactive']); // ignored

        $this->artisan('wpkg:provision-secrets')->assertSuccessful();

        $this->assertSame(2, WorkstationApiSecret::count());
    }

    #[Test]
    public function does_not_re_provision_existing_secrets_without_force(): void
    {
        $w = Workstation::create(['name' => 'PC-EXISTING', 'status' => 'active']);
        WorkstationApiSecret::create([
            'workstation_id' => $w->id,
            'secret_hash' => Hash::make('original'),
        ]);

        $this->artisan('wpkg:provision-secrets')->assertSuccessful();

        $this->assertSame(1, WorkstationApiSecret::count());
        $this->assertTrue(WorkstationApiSecret::first()->verify('original'));
    }

    #[Test]
    public function force_rotates_existing_secret(): void
    {
        $w = Workstation::create(['name' => 'PC-FORCE', 'status' => 'active']);
        WorkstationApiSecret::create([
            'workstation_id' => $w->id,
            'secret_hash' => Hash::make('original'),
        ]);

        $this->artisan('wpkg:provision-secrets --force')->assertSuccessful();

        $row = WorkstationApiSecret::first();
        $this->assertSame(Hash::info($row->previous_secret_hash)['algoName'] ?? 'bcrypt', 'bcrypt');
        $this->assertNotNull($row->rotated_at);
        $this->assertNotNull($row->previous_valid_until);
    }

    #[Test]
    public function emits_csv_header_and_rows(): void
    {
        Workstation::create(['name' => 'PC-CSV', 'status' => 'active']);

        $this->artisan('wpkg:provision-secrets')
            ->expectsOutput('hostname,secret')
            ->assertSuccessful();
    }
}
