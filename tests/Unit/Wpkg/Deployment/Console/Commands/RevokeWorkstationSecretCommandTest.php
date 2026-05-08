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
 * Story 15.5 / AC5.5 — Tests unit `wpkg:revoke-secret`.
 */
final class RevokeWorkstationSecretCommandTest extends TestCase
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
    public function sets_revoked_at_on_existing_secret(): void
    {
        $w = Workstation::create(['name' => 'PC-REV', 'status' => 'active']);
        WorkstationApiSecret::create([
            'workstation_id' => $w->id,
            'secret_hash' => Hash::make('s'),
        ]);

        $this->artisan('wpkg:revoke-secret PC-REV')->assertSuccessful();

        $row = WorkstationApiSecret::first();
        $this->assertNotNull($row->revoked_at);
    }

    #[Test]
    public function noop_for_already_revoked(): void
    {
        $w = Workstation::create(['name' => 'PC-REV2', 'status' => 'active']);
        $previouslyRevokedAt = now()->subDay();
        WorkstationApiSecret::create([
            'workstation_id' => $w->id,
            'secret_hash' => Hash::make('s'),
            'revoked_at' => $previouslyRevokedAt,
        ]);

        $this->artisan('wpkg:revoke-secret PC-REV2')->assertSuccessful();

        $row = WorkstationApiSecret::first();
        $this->assertNotNull($row->revoked_at);
        $this->assertSame(
            $previouslyRevokedAt->format('Y-m-d H:i:s'),
            $row->revoked_at->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function fails_for_unknown_hostname(): void
    {
        $this->artisan('wpkg:revoke-secret PC-UNKNOWN')->assertFailed();
    }
}
