<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Config\SambaEduConfig;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Tests Feature Livewire de la colonne Utilisation sur le listing /users
 * (story 5.1b — AC 9 cas 1-3).
 *
 * Couvre les 3 seuils de coloration :
 *   1. percent < 70 → badge-success (vert)
 *   2. 70 <= percent < 90 → badge-warning (orange)
 *   3. percent >= 90 ou is_over_soft → badge-error (rouge)
 */
class UsersIndexPageQuotaColumnTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createTablesIfNeeded();

        // Mock SEConfig — retourne null (pas de code établissement) pour
        // désactiver la branche "Externe" qui complexifierait le test.
        $seMock = Mockery::mock(SambaEduConfig::class);
        $seMock->shouldReceive('getCurrentEstablishmentCode')->andReturn(null);
        $this->app->instance(SambaEduConfig::class, $seMock);
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('user_group_user');
            Schema::dropIfExists('user_groups');
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function createTablesIfNeeded(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('password', 255)->nullable();
                $table->string('fullname', 255)->nullable();
                $table->string('firstname', 255)->nullable();
                $table->string('lastname', 255)->nullable();
                $table->string('email', 255)->nullable();
                $table->string('phone', 50)->nullable();
                $table->text('description')->nullable();
                $table->string('dn', 500)->nullable();
                $table->string('ad_guid', 100)->nullable();
                $table->string('school_code', 100)->nullable();
                $table->string('school_name', 255)->nullable();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->json('ad_right_profiles')->nullable();
                $table->unsignedInteger('ad_rights_bitmask')->default(0);
                $table->timestamp('ad_synced_at')->nullable();
                $table->timestamp('pwd_reset_at')->nullable();
                $table->string('remember_token', 100)->nullable();
                $table->json('quota_snapshot')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type')->nullable();
                $table->string('display_name')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('user_group_id');
                $table->primary(['user_id', 'user_group_id']);
            });
            $this->createdTables = true;
        }
    }

    private function makeUserWithPercent(string $login, ?int $percent, bool $overSoft = false): User
    {
        $snapshot = $percent === null ? null : [
            'home' => [
                'used_kb' => 10000,
                'soft_kb' => 100000,
                'hard_kb' => 120000,
                'used_mb' => 10,
                'soft_mb' => 98,
                'hard_mb' => 117,
                'percent' => $percent,
                'is_over_soft' => $overSoft,
                'is_over_hard' => false,
                'grace_days' => null,
            ],
            'captured_at' => '2026-04-23T03:00:00+02:00',
        ];

        return User::query()->create([
            'login' => $login,
            'firstname' => 'First',
            'lastname' => 'Last',
            'role' => 'eleve',
            'is_active' => true,
            'quota_snapshot' => $snapshot,
        ]);
    }

    public function test_it_shows_quota_percent_badge_success_below_70(): void
    {
        $this->makeUserWithPercent('alice-low', 40);

        Livewire::test('pages::users.index')
            ->assertSee('40%')
            ->assertSeeHtml('badge-success');
    }

    public function test_it_shows_badge_warning_between_70_and_90(): void
    {
        $this->makeUserWithPercent('bob-mid', 85);

        Livewire::test('pages::users.index')
            ->assertSee('85%')
            ->assertSeeHtml('badge-warning');
    }

    public function test_it_shows_badge_error_above_90(): void
    {
        $this->makeUserWithPercent('carol-high', 95);

        Livewire::test('pages::users.index')
            ->assertSee('95%')
            ->assertSeeHtml('badge-error');
    }

    public function test_it_shows_dash_for_user_without_snapshot(): void
    {
        $this->makeUserWithPercent('no-snap', null);

        Livewire::test('pages::users.index')
            ->assertSee('no-snap')
            // Pas de pourcentage ni de badge orange/rouge rendu pour le quota.
            ->assertDontSee('badge-warning')
            ->assertDontSee('badge-error');
    }
}
