<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Models;

use App\Wpkg\Deployment\Models\WorkstationApiSecret;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.5 / AC5.2 — Tests unit du modèle `WorkstationApiSecret`.
 *
 * Couvre :
 *   - verify(secret) → true sur match `secret_hash`.
 *   - verify(secret) → true sur match `previous_secret_hash` dans la fenêtre.
 *   - verify(secret) → false sur match `previous_secret_hash` hors fenêtre.
 *   - isRevoked() reflète `revoked_at`.
 *   - touchLastUsed() met à jour `last_used_at`.
 */
final class WorkstationApiSecretTest extends TestCase
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
                $t->string('name', 100);
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

    private function makeSecret(array $overrides = []): WorkstationApiSecret
    {
        $wsId = \Illuminate\Support\Facades\DB::table('workstations')->insertGetId([
            'name' => 'PC-' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WorkstationApiSecret::create(array_merge([
            'workstation_id' => $wsId,
            'secret_hash' => Hash::make('current-secret'),
        ], $overrides));
    }

    #[Test]
    public function verify_matches_current_hash(): void
    {
        $row = $this->makeSecret();

        $this->assertTrue($row->verify('current-secret'));
        $this->assertFalse($row->verify('wrong-secret'));
    }

    #[Test]
    public function verify_matches_previous_hash_within_window(): void
    {
        $row = $this->makeSecret([
            'previous_secret_hash' => Hash::make('old-secret'),
            'previous_valid_until' => now()->addDays(3),
        ]);

        $this->assertTrue($row->verify('old-secret'));
    }

    #[Test]
    public function verify_rejects_previous_hash_outside_window(): void
    {
        $row = $this->makeSecret([
            'previous_secret_hash' => Hash::make('old-secret'),
            'previous_valid_until' => now()->subDays(1),
        ]);

        $this->assertFalse($row->verify('old-secret'));
    }

    #[Test]
    public function is_revoked_reflects_revoked_at(): void
    {
        $row = $this->makeSecret();
        $this->assertFalse($row->isRevoked());

        $row->update(['revoked_at' => now()]);
        $this->assertTrue($row->fresh()->isRevoked());
    }

    #[Test]
    public function touch_last_used_updates_column(): void
    {
        $row = $this->makeSecret();
        $this->assertNull($row->last_used_at);

        $row->touchLastUsed();

        $this->assertNotNull($row->fresh()->last_used_at);
    }
}
