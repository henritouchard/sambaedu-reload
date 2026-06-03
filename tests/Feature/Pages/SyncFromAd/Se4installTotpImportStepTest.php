<?php

declare(strict_types=1);

namespace Tests\Feature\Pages\SyncFromAd;

use App\Models\ServiceCredential;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Étape 11 « Importer le TOTP de se4install » dans la page /sync-from-ad.
 *
 * Adoption non-destructive du token legacy : aucune écriture AD, idempotent,
 * no-op si le fichier est absent (cas parc sans TOTP).
 */
class Se4installTotpImportStepTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private ?string $hashesFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        (new PermissionSeeder())->run();

        if (!Schema::hasTable('service_credentials')) {
            Schema::create('service_credentials', function (Blueprint $table) {
                $table->id();
                $table->string('name', 64)->unique();
                $table->text('secret')->nullable();
                $table->text('totp_secret')->nullable();
                $table->unsignedBigInteger('totp_applied_counter')->nullable();
                $table->timestamps();
            });
        }

        // Aucune écriture AD attendue pour l'import (adoption).
        $this->mock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('changePasswordInAd');
        });
    }

    protected function tearDown(): void
    {
        if ($this->hashesFile !== null) {
            @unlink($this->hashesFile);
        }
        Schema::dropIfExists('service_credentials');
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        $u = User::query()->create(['login' => 'sm-totp-' . uniqid(), 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function useHashesFixture(array $data): void
    {
        $this->hashesFile = tempnam(sys_get_temp_dir(), 'hashes_');
        file_put_contents($this->hashesFile, json_encode($data));
        config(['sambaedu.se4install_hashes_file' => $this->hashesFile]);
    }

    public function test_step_imports_token_and_adopts_without_ad_write(): void
    {
        config(['sambaedu.se4install_passwd' => 'legacy-base']);
        $this->useHashesFixture(['se4install' => ['token' => 'JBSWY3DPEHPK3PXP', 'hash' => 'x']]);

        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::sync-from-ad.index')
            ->call('runStep', 'se4install_totp')
            ->assertSet('steps.se4install_totp.status', 'success');

        $rec = ServiceCredential::firstWhere('name', 'se4install');
        $this->assertSame('JBSWY3DPEHPK3PXP', $rec->totp_secret);
        $this->assertSame('legacy-base', $rec->secret);
    }

    public function test_step_is_skipped_when_hashes_file_absent(): void
    {
        config(['sambaedu.se4install_hashes_file' => '/nonexistent/hashes']);

        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::sync-from-ad.index')
            ->call('runStep', 'se4install_totp')
            ->assertSet('steps.se4install_totp.status', 'skipped');

        $this->assertNull(ServiceCredential::firstWhere('name', 'se4install'));
    }
}
