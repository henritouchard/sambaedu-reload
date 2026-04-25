<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Config\SambaEduConfig;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Story 5.1b — AC 11 : aucun shellout `xfs_quota` / `quota` ne doit
 * être déclenché par le rendu du listing /users. Tout passe par la
 * colonne `users.quota_snapshot`.
 *
 * On utilise `Process::fake()` + `Process::assertNothingRan()` : si un
 * composant enfant invoquait un shellout via la façade Process, le test
 * échouerait. Les appels PHP natifs `exec()` ne sont pas interceptés par
 * la façade mais le rendu du listing a été explicitement réécrit pour
 * ne plus invoquer XfsQuotaService.
 */
class UsersIndexPageNoShelloutTest extends TestCase
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

        $seMock = Mockery::mock(SambaEduConfig::class);
        $seMock->shouldReceive('getCurrentEstablishmentCode')->andReturn(null);
        $this->app->instance(SambaEduConfig::class, $seMock);
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('user_group_user');
            Schema::dropIfExists('user_groups');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function createTablesIfNeeded(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

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

        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('display_name')->nullable();
            $table->timestamps();
        });

        Schema::create('user_group_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('user_group_id');
            $table->primary(['user_id', 'user_group_id']);
        });

        // Table minimale pour la Livewire SFC `delegation-modal` dont le `mount()`
        // interroge WorkstationGroup::physical(). On ne seed rien : la liste reste
        // vide, ce qui suffit au rendu initial.
        Schema::create('workstation_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('display_name', 255)->nullable();
            $table->boolean('is_physical')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->createdTables = true;
    }

    public function test_no_xfs_shellout_is_triggered_when_rendering_users_listing(): void
    {
        // Seed 25 users (plus d'une page) avec un snapshot varié.
        for ($i = 0; $i < 25; $i++) {
            User::query()->create([
                'login' => "user-{$i}",
                'firstname' => "First{$i}",
                'lastname' => "Last{$i}",
                'role' => 'eleve',
                'is_active' => true,
                'quota_snapshot' => [
                    'home' => [
                        'used_kb' => 10000,
                        'soft_kb' => 100000,
                        'hard_kb' => 120000,
                        'used_mb' => 10,
                        'soft_mb' => 98,
                        'hard_mb' => 117,
                        'percent' => ($i * 4) % 100,
                        'is_over_soft' => false,
                        'is_over_hard' => false,
                        'grace_days' => null,
                    ],
                    'captured_at' => '2026-04-23T03:00:00+02:00',
                ],
            ]);
        }

        Process::fake();

        Livewire::test('pages::users.index');

        // Aucun shellout via la façade Process ne doit avoir été exécuté.
        Process::assertNothingRan();
    }
}
