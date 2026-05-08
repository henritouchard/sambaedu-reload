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
 * Story 15.5 / AC5.4 — Tests unit `wpkg:rotate-secret`.
 */
final class RotateWorkstationSecretCommandTest extends TestCase
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
    public function rotates_existing_secret_with_overlap_window(): void
    {
        $w = Workstation::create(['name' => 'PC-ROT', 'status' => 'active']);
        WorkstationApiSecret::create([
            'workstation_id' => $w->id,
            'secret_hash' => Hash::make('old-secret'),
        ]);

        $this->artisan('wpkg:rotate-secret PC-ROT')->assertSuccessful();

        $row = WorkstationApiSecret::first();
        $this->assertNotNull($row->previous_secret_hash);
        $this->assertNotNull($row->previous_valid_until);
        $this->assertNotNull($row->rotated_at);

        // Le `previous_secret_hash` matche l'ancien clear secret.
        $this->assertTrue(Hash::check('old-secret', $row->previous_secret_hash));
        // Le `secret_hash` ne matche plus l'ancien.
        $this->assertFalse(Hash::check('old-secret', $row->secret_hash));
    }

    #[Test]
    public function provisions_if_no_secret_exists(): void
    {
        Workstation::create(['name' => 'PC-NEW', 'status' => 'active']);

        $this->artisan('wpkg:rotate-secret PC-NEW')->assertSuccessful();

        $this->assertSame(1, WorkstationApiSecret::count());
        $row = WorkstationApiSecret::first();
        $this->assertNull($row->previous_secret_hash);
        $this->assertNull($row->rotated_at);
    }

    #[Test]
    public function fails_for_unknown_hostname(): void
    {
        $this->artisan('wpkg:rotate-secret PC-UNKNOWN')
            ->assertFailed();
    }

    #[Test]
    public function rotation_lifts_revoked_state(): void
    {
        $w = Workstation::create(['name' => 'PC-REVOKED', 'status' => 'active']);
        WorkstationApiSecret::create([
            'workstation_id' => $w->id,
            'secret_hash' => Hash::make('old'),
            'revoked_at' => now()->subDay(),
        ]);

        $this->artisan('wpkg:rotate-secret PC-REVOKED')->assertSuccessful();

        $row = WorkstationApiSecret::first();
        $this->assertNull($row->revoked_at);
    }
}
