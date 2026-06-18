<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\WpkgGpoSyncReport;
use App\Gpo\Enums\WpkgGpoSyncSeverity;
use App\Gpo\Services\WpkgGpoSynchronizer;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\TestCase;

/**
 * Tests Feature page Livewire `/admin/settings/gpo/wpkg-deployment` — Story 16.6 (AC3.*, AC5.2) + Story 16.9.
 */
class WpkgDeploymentPageTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->bootstrapSpatieTables();
        // Story 15.6 : le composant charge system_settings dans mount().
        if (! Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function makeAdmin(string $login = 'admin-wpkg'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeReport(array $overrides = []): WpkgGpoSyncReport
    {
        $defaults = [
            'gpoExists' => true,
            'gpoGuid' => '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            'gpoDisplayName' => 'se4_wpkg',
            'gpoPath' => '\\\\example.org\\sysvol\\example.org\\Policies\\{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            'linkedOus' => ['OU=Computers,DC=example,DC=org'],
            'expectedHostsXmlUrl' => 'http://se4fs.example.org/wpkg/hosts.xml',
            'expectedProfilesXmlUrl' => 'http://se4fs.example.org/wpkg/profiles.xml',
            'templatePath' => '/usr/share/sambaedu/gpo/se4_wpkg.zip',
            'templateExists' => true,
            'templateLastModified' => new DateTimeImmutable('2026-05-01T10:00:00Z'),
            'detectedPlaceholders' => ['SE4FS_NAME'],
            'unknownPlaceholders' => [],
            'bearerCoverage' => [],
            'bearerTableAvailable' => false,
            'severity' => WpkgGpoSyncSeverity::Ok,
            'messages' => [],
            'operationId' => 'test-op-1',
        ];
        return new WpkgGpoSyncReport(...array_merge($defaults, $overrides));
    }

    private function bindSync(WpkgGpoSyncReport $auditReport, ?WpkgGpoSyncReport $publishReport = null, ?\Throwable $publishThrow = null): WpkgGpoSynchronizer
    {
        $mock = Mockery::mock(WpkgGpoSynchronizer::class);
        $mock->shouldReceive('audit')->andReturn($auditReport);
        if ($publishThrow !== null) {
            $mock->shouldReceive('publish')->andThrow($publishThrow);
        } else {
            $mock->shouldReceive('publish')->andReturn($publishReport ?? $auditReport);
        }
        $this->app->bind(WpkgGpoSynchronizer::class, fn () => $mock);
        return $mock;
    }

    #[Test]
    public function admin_sees_ok_state_when_all_pass(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSync($this->makeReport());

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(200)
            ->assertSee('se4_wpkg')
            ->assertSee('OU=Computers,DC=example,DC=org')
            ->assertSeeHtml('badge-success');
    }

    #[Test]
    public function admin_sees_gpo_not_found_error(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSync($this->makeReport([
            'gpoExists' => false,
            'gpoGuid' => null,
            'gpoDisplayName' => null,
            'gpoPath' => null,
            'linkedOus' => [],
            'severity' => WpkgGpoSyncSeverity::Error,
            'messages' => ['GPO `se4_wpkg` introuvable dans l\'AD — publication initiale requise.'],
        ]));

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(200)
            ->assertSee('introuvable')
            ->assertSeeHtml('badge-error');
    }

    #[Test]
    public function admin_sees_unlinked_gpo_warning(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSync($this->makeReport([
            'linkedOus' => [],
            'severity' => WpkgGpoSyncSeverity::Warning,
            'messages' => ['GPO `se4_wpkg` existe mais n\'est liée à aucune OU.'],
        ]));

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(200)
            ->assertSee('GPO non liée')
            ->assertSeeHtml('badge-warning');
    }

    #[Test]
    public function audit_button_reloads_report_without_side_effect(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSync($this->makeReport());

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->call('refresh')
            ->assertStatus(200)
            ->assertSet('hasError', false);
    }

    #[Test]
    public function admin_can_open_publish_modal(): void
    {
        $this->actingAs($this->makeAdmin());
        $this->bindSync($this->makeReport());

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->call('openPublishModal')
            ->assertSet('isPublishModalOpen', true)
            ->assertSet('forceFlag', false);
    }

    #[Test]
    public function confirm_publish_is_retired_noop_and_never_calls_publish(): void
    {
        // Story 27.5 (D2) — la publication de `se4_wpkg` est RETIRÉE : l'agent
        // est le seul déclencheur de WPKG. `confirmPublish` ne publie plus (no-op
        // informatif + re-audit lecture seule) → la synchro ne doit JAMAIS
        // recevoir `publish()`.
        $this->actingAs($this->makeAdmin());

        /** @var WpkgGpoSynchronizer&\Mockery\MockInterface $mock */
        $mock = Mockery::mock(WpkgGpoSynchronizer::class);
        $mock->shouldReceive('audit')->andReturn($this->makeReport());
        $mock->shouldNotReceive('publish'); // RETIRÉ — aucune écriture SYSVOL.
        $this->app->bind(WpkgGpoSynchronizer::class, fn () => $mock);

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->call('openPublishModal')
            ->set('forceFlag', true)
            ->call('confirmPublish')
            ->assertSet('isPublishModalOpen', false)
            ->assertSet('forceFlag', false)
            ->assertSet('isPublishing', false);
    }
}
