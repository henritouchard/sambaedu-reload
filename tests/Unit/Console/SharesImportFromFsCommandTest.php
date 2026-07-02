<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Epic 34 — Tests de la commande d'import `shares:import-from-fs`.
 *
 * Vérifie le contrat de sûreté : dry-run par défaut (aucune écriture), et
 * matérialisation correcte des mappables + provisioning en `--apply`.
 */
class SharesImportFromFsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/netshare-import-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRoot)) {
            @rmdir($this->tempRoot);
        }
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function fakeGetfacl(): void
    {
        // getfacl (inspection) renvoie l'échantillon ; les autres commandes
        // (mkdir/setfacl/chown/chgrp du provisioning) tombent sur le fake par
        // défaut = succès.
        Process::fake([
            'sudo getfacl *' => Process::result(
                output: "user::rwx\nuser:alice:rwx\ngroup:classe_6a:r-x\nother::---\n",
                exitCode: 0,
            ),
            '*' => Process::result(output: '', exitCode: 0),
        ]);
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        User::factory()->create(['login' => 'alice']);
        UserGroup::factory()->create(['name' => '6a', 'type' => 'classe', 'ad_dn' => null]);
        $this->fakeGetfacl();

        $this->artisan('shares:import-from-fs', ['path' => '/var/sambaedu/Classes/Classe_6A'])
            ->assertExitCode(0);

        self::assertSame(0, NetworkShare::count());
        self::assertSame(0, NetworkShareAssignable::count());
    }

    #[Test]
    public function apply_creates_share_with_mapped_assignments(): void
    {
        $alice = User::factory()->create(['login' => 'alice']);
        $classe = UserGroup::factory()->create(['name' => '6a', 'type' => 'classe', 'ad_dn' => null]);
        $this->fakeGetfacl();

        $this->artisan('shares:import-from-fs', [
            'path' => '/var/sambaedu/Classes/Classe_6A',
            '--apply' => true,
        ])->assertExitCode(0);

        $share = NetworkShare::firstWhere('directory_name', '6A');
        self::assertNotNull($share, 'le basename Classe_6A est assaini en directory_name 6A');
        self::assertSame(2, $share->assignments()->count());

        $user = NetworkShareAssignable::where('assignable_type', User::class)->first();
        self::assertSame($alice->id, $user->assignable_id);
        self::assertSame('rw', $user->access);

        $group = NetworkShareAssignable::where('assignable_type', UserGroup::class)->first();
        self::assertSame($classe->id, $group->assignable_id);
        self::assertSame('ro', $group->access);
    }

    #[Test]
    public function refuses_when_directory_name_collides(): void
    {
        User::factory()->create(['login' => 'alice']);
        UserGroup::factory()->create(['name' => '6a', 'type' => 'classe', 'ad_dn' => null]);
        NetworkShare::factory()->create(['directory_name' => '6A']);
        $this->fakeGetfacl();

        $this->artisan('shares:import-from-fs', [
            'path' => '/var/sambaedu/Classes/Classe_6A',
            '--apply' => true,
        ])->assertExitCode(1);

        // Aucun second share créé.
        self::assertSame(1, NetworkShare::count());
    }

    #[Test]
    public function strict_mode_blocks_on_unmappable_entries(): void
    {
        // Personne pour matcher `user:ghost` → non-mappable → --strict échoue (2).
        Process::fake([
            'sudo getfacl *' => Process::result(output: "user::rwx\nuser:ghost:rwx\nother::---\n", exitCode: 0),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $this->artisan('shares:import-from-fs', [
            'path' => '/var/sambaedu/Classes/Classe_6A',
            '--apply' => true,
            '--strict' => true,
        ])->assertExitCode(2);

        self::assertSame(0, NetworkShare::count());
    }
}
