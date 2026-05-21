<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob;
use App\Ipxe\Iso\Services\WindowsIsoSourcesReader;
use App\Models\User;
use App\Models\WindowsIsoDownload;
use Database\Seeders\PermissionSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;
use Tests\Traits\CreatesWindowsIsoSchema;

/**
 * Story 3.6 — AC5.* — Tests Feature du composant Livewire SFC
 * `pages::admin.ipxe.iso-windows.index`.
 *
 * Couvre :
 *  - Auth admin / non-admin / anonymous (via mount() abort_unless).
 *  - Affichage des 4 sources via WindowsIsoSourcesReader.
 *  - submitDownload() OK → dispatch modale.
 *  - confirmDownload() OK → row pending + dispatch Job + toast success.
 *  - confirmDownload() validation error → toast error + pas de row.
 *  - confirmDownload() lock pris → toast error.
 *  - cancelDownload() OK → status=cancelled.
 *  - cancelDownload() no-op sur terminal.
 *  - Polling refresh → relit sources et downloads.
 *  - Modale confirm dispatch.
 *  - Historique limit 10.
 */
class WindowsIsoWindowsLivewireTest extends TestCase
{
    use CreatesPermissionSchema;
    use CreatesWindowsIsoSchema;
    use DatabaseTransactions;

    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionSchema();
        $this->createWindowsIsoSchema();
        (new PermissionSeeder())->run();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        // Sources reader filesystem isolé.
        $this->tmpBase = sys_get_temp_dir() . '/ipxe-iso-livewire-' . uniqid();
        mkdir($this->tmpBase, 0755, true);
        config([
            'ipxe.iso_management.deployed_os_base_path' => $this->tmpBase,
            'ipxe.iso_management.allowed_url_hosts' => [
                'software-static.download.prss.microsoft.com',
                'software-download.microsoft.com',
                'download.microsoft.com',
            ],
            'ipxe.iso_management.global_lock_key' => 'ipxe.iso.download.test-lw-lock',
            'ipxe.iso_management.global_lock_ttl' => 60,
            'ipxe.iso_management.queue_name'      => 'ipxe_iso_downloads_test',
            'cache.default'                       => 'array',
        ]);
        Cache::lock('ipxe.iso.download.test-lw-lock')->forceRelease();

        // Re-binder le reader pour pointer sur le tmpBase.
        $this->app->singleton(WindowsIsoSourcesReader::class, fn () => new WindowsIsoSourcesReader(new Filesystem()));

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Cache::lock('ipxe.iso.download.test-lw-lock')->forceRelease();
        if (is_dir($this->tmpBase)) {
            foreach (glob($this->tmpBase . '/*') as $f) {
                if (is_dir($f)) {
                    foreach (glob($f . '/*') as $g) {
                        @unlink($g);
                    }
                    @rmdir($f);
                }
            }
            @rmdir($this->tmpBase);
        }
        $this->dropWindowsIsoSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        $u = User::query()->create([
            'login'     => 'admin-iso-lw-' . uniqid(),
            'role'      => 'prof',
            'is_active' => true,
        ]);
        $u->givePermissionTo('server.admin');

        return $u;
    }

    private function makeTeacher(): User
    {
        return User::query()->create([
            'login'     => 'teacher-iso-lw-' . uniqid(),
            'role'      => 'prof',
            'is_active' => true,
        ]);
    }

    private function seedVersion(string $folder, string $content): void
    {
        @mkdir($this->tmpBase . '/' . $folder, 0755, true);
        file_put_contents($this->tmpBase . '/' . $folder . '/version', $content);
    }

    /* =================================================================
     * Auth
     * ================================================================= */

    #[Test]
    public function it_aborts_with_403_when_user_lacks_server_admin_permission(): void
    {
        $this->actingAs($this->makeTeacher());
        $this->expectExceptionMessage('server.admin');

        Livewire::test('pages::admin.ipxe.iso-windows.index');
    }

    #[Test]
    public function it_renders_for_admin_with_server_admin(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::admin.ipxe.iso-windows.index')
            ->assertOk()
            ->assertSee('Versions Windows déployées')
            ->assertSee('Nouveau téléchargement')
            ->assertSee('Historique');
    }

    /* =================================================================
     * Sources display
     * ================================================================= */

    #[Test]
    public function it_displays_filesystem_sources_in_mount(): void
    {
        $this->seedVersion('Win10', 'Win10_22H2.iso');
        $this->seedVersion('Win11', 'Win11_24H2.iso');
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::admin.ipxe.iso-windows.index')
            ->assertSet('sources.win10.current', 'Win10_22H2.iso')
            ->assertSet('sources.win11.current', 'Win11_24H2.iso')
            ->assertSet('sources.win10.old', null)
            ->assertSet('sources.win11.old', null);
    }

    #[Test]
    public function it_displays_non_deployee_when_sources_missing(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::admin.ipxe.iso-windows.index')
            ->assertSee('non déployée');
    }

    /* =================================================================
     * Submit / Confirm
     * ================================================================= */

    #[Test]
    public function it_opens_confirm_modal_when_submitting_a_valid_url(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::admin.ipxe.iso-windows.index')
            ->set('url', 'https://software-static.download.prss.microsoft.com/path/Win11_24H2.iso')
            ->call('submitDownload')
            ->assertDispatched('open-confirm-modal');
    }

    #[Test]
    public function it_creates_pending_row_and_dispatches_job_on_confirm(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test('pages::admin.ipxe.iso-windows.index')
            ->set('url', 'https://software-static.download.prss.microsoft.com/Win11_24H2.iso')
            ->call('confirmDownload');

        $download = WindowsIsoDownload::query()->latest('id')->first();
        self::assertNotNull($download);
        self::assertSame('Win11', $download->version);
        self::assertSame('Win11_24H2.iso', $download->iso_name);
        self::assertSame(WindowsIsoDownloadStatus::Pending, $download->status);
        self::assertSame($admin->id, $download->initiated_by_user_id);

        Queue::assertPushed(DownloadWindowsIsoJob::class);
    }

    #[Test]
    public function it_shows_validation_error_toast_for_invalid_host(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::admin.ipxe.iso-windows.index')
            ->set('url', 'https://evil.com/Win11.iso')
            ->call('confirmDownload')
            ->assertDispatched('toastMagic', status: 'error');

        self::assertSame(0, WindowsIsoDownload::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_shows_lock_error_toast_when_global_lock_is_held(): void
    {
        $this->actingAs($this->makeAdmin());

        // Acquière le lock pour simuler un download déjà en cours.
        $lock = Cache::lock('ipxe.iso.download.test-lw-lock', 60);
        self::assertTrue($lock->get());

        try {
            Livewire::test('pages::admin.ipxe.iso-windows.index')
                ->set('url', 'https://software-static.download.prss.microsoft.com/Win11_24H2.iso')
                ->call('confirmDownload')
                ->assertDispatched('toastMagic', status: 'error');
        } finally {
            $lock->release();
        }

        self::assertSame(0, WindowsIsoDownload::query()->count());
    }

    /* =================================================================
     * Cancel
     * ================================================================= */

    #[Test]
    public function it_cancels_a_running_download_and_marks_cancelled(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $running = WindowsIsoDownload::factory()->downloading()->create(['initiated_by_user_id' => $admin->id]);

        Livewire::test('pages::admin.ipxe.iso-windows.index')
            ->call('cancelDownload', $running->id);

        $running->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Cancelled, $running->status);
    }

    #[Test]
    public function it_is_noop_when_cancelling_a_terminal_download(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $done = WindowsIsoDownload::factory()->success()->create(['initiated_by_user_id' => $admin->id]);

        Livewire::test('pages::admin.ipxe.iso-windows.index')
            ->call('cancelDownload', $done->id)
            ->assertDispatched('toastMagic', status: 'info');

        $done->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Success, $done->status);
    }

    /* =================================================================
     * History
     * ================================================================= */

    #[Test]
    public function it_lists_at_most_ten_downloads_in_history(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        WindowsIsoDownload::factory()->count(15)->success()->create(['initiated_by_user_id' => $admin->id]);

        $component = Livewire::test('pages::admin.ipxe.iso-windows.index');
        $downloads = $component->get('downloads');
        self::assertCount(10, $downloads);
    }

    /* =================================================================
     * Polling
     * ================================================================= */

    #[Test]
    public function it_sets_current_running_when_download_is_active(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $running = WindowsIsoDownload::factory()->downloading()->create(['initiated_by_user_id' => $admin->id]);

        $component = Livewire::test('pages::admin.ipxe.iso-windows.index');
        $current = $component->get('currentRunning');
        self::assertNotNull($current);
        self::assertSame($running->id, $current['id']);
    }

    #[Test]
    public function it_sets_current_running_to_null_when_no_active_download(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        WindowsIsoDownload::factory()->success()->create(['initiated_by_user_id' => $admin->id]);

        $component = Livewire::test('pages::admin.ipxe.iso-windows.index');
        self::assertNull($component->get('currentRunning'));
    }

    /* =================================================================
     * Corrections post-review 2026-05-21
     * ================================================================= */

    /**
     * #9 (post-review) — Ajout test Feature anonymous Livewire.
     *
     * Le `mount()` du composant fait `abort_unless(Auth::check() && ...)`.
     * Sans `actingAs()`, on doit lever un abort 403.
     */
    #[Test]
    public function it_aborts_with_403_for_anonymous_user(): void
    {
        // PAS de `actingAs()` — user anonyme.
        $this->expectExceptionMessage('server.admin');

        Livewire::test('pages::admin.ipxe.iso-windows.index');
    }

    /**
     * #10 (post-review) — Test polling transition terminal.
     *
     * Sur un download `running` (downloading), on simule l'écriture DB par
     * le Worker (status → success) puis on call `refresh()`. Le composant
     * doit dispatcher `toastMagic` status `success` (transition terminal
     * détectée pendant polling — logique `lastTerminalNotified` du SFC).
     */
    #[Test]
    public function it_toasts_on_running_to_terminal_transition(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $running = WindowsIsoDownload::factory()->downloading()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $admin->id,
        ]);

        $component = Livewire::test('pages::admin.ipxe.iso-windows.index');
        // Le mount voit le download en cours.
        self::assertNotNull($component->get('currentRunning'));

        // Simule la fin par le Worker (transition downloading → success).
        $running->update([
            'status'       => WindowsIsoDownloadStatus::Success,
            'completed_at' => now(),
            'exit_code'    => 0,
        ]);

        // Polling tick : refresh manuel (équivaut à wire:poll.60s tick).
        $component->call('refresh')
            ->assertDispatched('toastMagic', status: 'success');

        // currentRunning bascule à null après détection transition terminal.
        self::assertNull($component->get('currentRunning'));
    }

    /**
     * #15 (post-review) — Test lock released on cancel during job.
     *
     * L'orchestrator `cancel()` doit `forceRelease()` le lock global pour
     * permettre à un nouveau download d'être lancé immédiatement après
     * une annulation, sans attendre les 2h de TTL.
     */
    #[Test]
    public function it_releases_lock_when_admin_cancels_during_job_run(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        // 1) Acquière le lock applicatif (simule l'orchestrator l'a posé).
        $lockKey = (string) config('ipxe.iso_management.global_lock_key');
        $lock = Cache::lock($lockKey, 60);
        self::assertTrue($lock->get());

        $running = WindowsIsoDownload::factory()->downloading()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $admin->id,
        ]);

        // 2) Admin clique "Annuler" via le composant Livewire.
        Livewire::test('pages::admin.ipxe.iso-windows.index')
            ->call('cancelDownload', $running->id);

        $running->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Cancelled, $running->status);

        // 3) Le lock doit être release immédiatement (pas après TTL 60s).
        // On vérifie en tentant d'acquérir un nouveau lock.
        $newLock = Cache::lock($lockKey, 60);
        self::assertTrue(
            $newLock->get(),
            'Le lock global doit être release immédiatement après cancelDownload (pas zombi).',
        );
        $newLock->release();
    }
}
