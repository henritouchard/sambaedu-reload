<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\ScriptsOs\Models\ScriptExecutionLog;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.12 — AC5.1 / AC5.2 (≥4 cas).
 */
class ScriptLogsDetailTest extends TestCase
{
    use IssuesWorkstationJwt;

    private string $componentName = 'pages::admin.settings.scripts-logs.[id].index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
        Cache::store('array')->flush();
    }

    private function asAdmin(): Authenticatable
    {
        return $this->mockAuthAs(true);
    }

    private function mockAuthAs(bool $isAdmin): Authenticatable
    {
        // cf. ScriptLogsIndexTest::mockAuthAs — pattern iso MocksAdminUser.
        $user = Mockery::mock(Authenticatable::class, Authorizable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $user->shouldReceive('can')->andReturn($isAdmin);

        Gate::define('server.admin', fn ($u) => $isAdmin);
        $this->actingAs($user);

        return $user;
    }

    #[Test]
    public function it_renders_metadata_for_existing_log(): void
    {
        $this->asAdmin();
        $log = ScriptExecutionLog::factory()->create([
            'stdout_excerpt' => 'Hello world output',
            'stderr_excerpt' => 'Hello stderr',
        ]);

        // Post review Opus-D — vérifie rendu HTML effectif (UUID, stdout, stderr,
        // correlation_id) plutôt que juste l'état Livewire interne.
        Livewire::test($this->componentName, ['id' => $log->id])
            ->assertSee($log->workstation_uuid)
            ->assertSee('Hello world output')
            ->assertSee('Hello stderr')
            ->assertSee($log->correlation_id);
    }

    #[Test]
    public function it_aborts_404_for_unknown_id(): void
    {
        $this->asAdmin();
        $unknownUuid = '00000000-0000-4000-8000-000000000000';

        Livewire::test($this->componentName, ['id' => $unknownUuid])
            ->assertStatus(404);
    }

    #[Test]
    public function copy_stdout_dispatches_event(): void
    {
        $this->asAdmin();
        $log = ScriptExecutionLog::factory()->create([
            'stdout_excerpt' => 'output to copy',
        ]);

        Livewire::test($this->componentName, ['id' => $log->id])
            ->call('copyStdout')
            ->assertDispatched('copy-to-clipboard');
    }

    #[Test]
    public function it_escapes_xss_in_stdout(): void
    {
        $this->asAdmin();
        $xss = '<script>alert("xss")</script>';
        $log = ScriptExecutionLog::factory()->create([
            'stdout_excerpt' => $xss,
        ]);

        $rendered = Livewire::test($this->componentName, ['id' => $log->id])->html();

        // Le HTML rendu doit contenir la version échappée
        self::assertStringContainsString('&lt;script&gt;', $rendered);
        // Et NE doit PAS contenir le tag brut <script>alert(
        self::assertStringNotContainsString('<script>alert("xss")</script>', $rendered);
    }

    #[Test]
    public function non_admin_is_forbidden(): void
    {
        $this->mockAuthAs(false);

        $log = ScriptExecutionLog::factory()->create();

        Livewire::test($this->componentName, ['id' => $log->id])
            ->assertStatus(403);
    }
}
