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
    public function admin_sees_unlinked_gpo_as_residual_info(): void
    {
        // Story 27.5 — une GPO `se4_wpkg` non liée n'est PLUS une alerte
        // bloquante : l'agent déclenche WPKG indépendamment des liaisons. La
        // section liaisons est désormais purement informative.
        $this->actingAs($this->makeAdmin());
        $this->bindSync($this->makeReport([
            'linkedOus' => [],
            'severity' => WpkgGpoSyncSeverity::Warning,
            'messages' => ['GPO `se4_wpkg` existe mais n\'est liée à aucune OU.'],
        ]));

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(200)
            ->assertSeeHtml('data-testid="unlinked-info"')
            ->assertSee('non liée')
            ->assertSee('Sans effet sur WPKG');
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
    public function admin_sees_agent_trigger_explainer(): void
    {
        // Story 27.5 / 27.6 — l'encart explique que l'agent (canal desired-state)
        // est désormais le seul déclencheur de WPKG, à la place de la GPO.
        $this->actingAs($this->makeAdmin());
        $this->bindSync($this->makeReport());

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(200)
            ->assertSeeHtml('data-testid="agent-trigger-explainer"')
            ->assertSee('agent déclenche WPKG')
            ->assertSee('bundle WPKG natif');
    }

    #[Test]
    public function page_never_publishes_and_has_no_publish_action(): void
    {
        // Story 27.5 (D2) — la publication de `se4_wpkg` est RETIRÉE : l'agent
        // est le seul déclencheur de WPKG. Le bouton/modale de publication a été
        // supprimé et la synchro ne doit JAMAIS recevoir `publish()` — ni au
        // mount, ni au re-audit.
        $this->actingAs($this->makeAdmin());

        /** @var WpkgGpoSynchronizer&\Mockery\MockInterface $mock */
        $mock = Mockery::mock(WpkgGpoSynchronizer::class);
        $mock->shouldReceive('audit')->andReturn($this->makeReport());
        $mock->shouldNotReceive('publish'); // RETIRÉ — aucune écriture SYSVOL.
        $this->app->bind(WpkgGpoSynchronizer::class, fn () => $mock);

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(200)
            // Plus de bouton ni de modale de publication.
            ->assertDontSeeHtml('data-testid="open-publish-modal"')
            ->assertDontSeeHtml('data-testid="modal-confirm-publish"')
            // Le re-audit reste une lecture pure (aucun publish()).
            ->call('refresh')
            ->assertSet('hasError', false);
    }
}
