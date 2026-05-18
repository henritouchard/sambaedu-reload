<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\User;
use App\ScriptsOs\Models\ScriptExecutionLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.12 — AC4.2 (≥6 cas).
 *
 * On teste le component Livewire SFC indirectement via le path Blade
 * `pages::admin.settings.scripts-logs.index` que Livewire/Folio résolvent.
 */
class ScriptLogsIndexTest extends TestCase
{
    use IssuesWorkstationJwt;

    private string $componentName = 'pages::admin.settings.scripts-logs.index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
        Cache::store('array')->flush();
        config(['cache.default' => 'array']);
    }

    private function asAdmin(): User
    {
        $user = User::factory()->create();
        Gate::define('server.admin', fn ($user) => true);
        $this->actingAs($user);

        return $user;
    }

    private function asNonAdmin(): User
    {
        $user = User::factory()->create();
        Gate::define('server.admin', fn ($user) => false);
        $this->actingAs($user);

        return $user;
    }

    #[Test]
    public function mount_as_admin_renders_default_filters(): void
    {
        $this->asAdmin();
        $logs = ScriptExecutionLog::factory()->recent(1)->count(10)->create();
        $firstUuid = $logs->first()->workstation_uuid;

        Livewire::test($this->componentName)
            ->assertSet('sortBy', 'started_at')
            ->assertSet('sortDir', 'desc')
            ->assertSee('Logs')
            // Post review Opus-D — vérifier rendu HTML effectif (et pas seulement state).
            ->assertSeeHtml('data-testid="logs-table"')
            ->assertSeeHtml('data-testid="dashboard-banner"')
            ->assertSeeHtml('data-testid="filters-panel"')
            // UUID limité à 16 chars dans le rendu via Str::limit().
            ->assertSee(\Illuminate\Support\Str::limit($firstUuid, 16, '…'));
    }

    #[Test]
    public function mount_as_non_admin_aborts_403(): void
    {
        $this->asNonAdmin();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        try {
            Livewire::test($this->componentName);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    #[Test]
    public function filter_status_failure_shows_only_failures(): void
    {
        $this->asAdmin();
        ScriptExecutionLog::factory()->recent(1)->count(4)->create(); // success
        $failures = ScriptExecutionLog::factory()->failed()->recent(1)->count(3)->create();

        $tested = Livewire::test($this->componentName)
            ->set('filterStatus', 'failure');

        $logs = $tested->viewData('logs');
        self::assertSame(3, $logs->total());

        // Post review Opus-D — vérifier que le rendu HTML reflète bien le filtre :
        // la row failure doit être affichée, et le badge "badge-error" présent.
        $failureUuid = $failures->first()->workstation_uuid;
        $tested
            ->assertSee(\Illuminate\Support\Str::limit($failureUuid, 16, '…'))
            ->assertSeeHtml('badge-error');
    }

    #[Test]
    public function filter_workstation_uuid_isolates_workstation(): void
    {
        $this->asAdmin();
        $target = strtolower((string) Str::uuid());
        ScriptExecutionLog::factory()->forWorkstation($target)->recent(1)->count(2)->create();
        ScriptExecutionLog::factory()->recent(1)->count(5)->create();

        $tested = Livewire::test($this->componentName)
            ->set('filterWorkstationUuid', $target);

        $logs = $tested->viewData('logs');
        self::assertSame(2, $logs->total());
    }

    #[Test]
    public function failures_only_toggle_filters_failures(): void
    {
        $this->asAdmin();
        ScriptExecutionLog::factory()->recent(1)->count(4)->create();
        ScriptExecutionLog::factory()->failed()->recent(1)->count(2)->create();

        $tested = Livewire::test($this->componentName)
            ->call('toggleFailuresOnly');

        $logs = $tested->viewData('logs');
        self::assertSame(2, $logs->total());
        $tested->assertSet('filterFailuresOnly', true);

        // Post review Opus-D — vérifier rendu HTML : bouton actif + libellé.
        $tested
            ->assertSeeHtml('data-testid="toggle-failures-only"')
            ->assertSee('Tous les logs'); // libellé quand filterFailuresOnly=true
    }

    #[Test]
    public function clear_filters_resets_state(): void
    {
        $this->asAdmin();
        Livewire::test($this->componentName)
            ->set('filterStatus', 'failure')
            ->set('filterFailuresOnly', true)
            ->set('filterWorkstationUuid', 'aaaa')
            ->call('clearFilters')
            ->assertSet('filterStatus', '')
            ->assertSet('filterWorkstationUuid', '')
            ->assertSet('filterFailuresOnly', false);
    }

    #[Test]
    public function sort_by_column_toggles_direction(): void
    {
        $this->asAdmin();
        Livewire::test($this->componentName)
            ->call('sortByColumn', 'started_at')
            ->assertSet('sortDir', 'asc')
            ->call('sortByColumn', 'started_at')
            ->assertSet('sortDir', 'desc');
    }

    #[Test]
    public function pagination_50_per_page(): void
    {
        $this->asAdmin();
        ScriptExecutionLog::factory()->recent(1)->count(75)->create();

        $tested = Livewire::test($this->componentName);
        $logs = $tested->viewData('logs');

        self::assertSame(50, $logs->perPage());
        self::assertSame(75, $logs->total());

        // Post review Opus-D — bandeau d'indicateurs visible quand il y a des logs.
        $tested->assertSeeHtml('data-testid="failure-rate"');
    }
}
