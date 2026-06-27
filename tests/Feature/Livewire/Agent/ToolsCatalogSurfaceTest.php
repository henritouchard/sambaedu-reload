<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Agent;

use App\Models\AgentTool;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use ZipArchive;

/**
 * Story 25.6 — surface « Tools » du catalogue agent (AC2, AC3, AC6).
 *
 * Façade UI sur le SEUL écrivain `AgentToolService` : upload (délègue
 * `upload()`), toggle (délègue `toggle()`). Gate `server.admin` sur chaque
 * mutation (adressabilité /livewire/update). Refus métier → `toastError`,
 * jamais 500. Retours via toasts.
 */
class ToolsCatalogSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.agent._partials.tools-catalog';

    private User $admin;

    private string $toolsDir;

    private string $workDir;

    protected function setUp(): void
    {
        // Ces tests uploadent un portable ZIP réel (ZipArchive). Skip propre si
        // ext-zip absent (vert sur /vm). Le skip DOIT précéder parent::setUp() :
        // RefreshDatabase y ouvre une transaction qui resterait orpheline si on
        // skippait après (→ cascade « cannot start a transaction within a
        // transaction » sur les tests suivants).
        if (! class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip absent : ces tests exigent ZipArchive (vert sur /vm).');
        }

        parent::setUp();

        Permission::firstOrCreate(['name' => 'server.admin', 'guard_name' => 'web']);
        $this->admin = User::query()->create(['login' => 'tool-admin', 'role' => 'prof', 'is_active' => true]);
        $this->admin->givePermissionTo('server.admin');
        $this->actingAs($this->admin);

        $this->toolsDir = storage_path('framework/testing/tools-' . uniqid());
        $this->workDir = storage_path('framework/testing/work-' . uniqid());
        File::ensureDirectoryExists($this->toolsDir);
        File::ensureDirectoryExists($this->workDir);
        config(['agent.tools_path' => $this->toolsDir]);
        config(['agent.tool_max_upload_bytes' => 200 * 1024 * 1024]);
    }

    protected function tearDown(): void
    {
        // Le skip ext-zip court-circuite setUp avant l'init des répertoires.
        if (isset($this->toolsDir)) {
            File::deleteDirectory($this->toolsDir);
        }
        if (isset($this->workDir)) {
            File::deleteDirectory($this->workDir);
        }

        parent::tearDown();
    }

    private function validPortable(): UploadedFile
    {
        $path = $this->workDir . '/' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('Rainmeter.exe', "MZ\x90\x00fake");
        $zip->addFromString('Skins/SambaEduOverlay/SambaEduOverlay.ini', "[Variables]\n");
        $zip->close();

        // `createWithContent` → `Illuminate\Http\Testing\File` (porte la
        // propriété `->name` que la sérialisation de fichier Livewire lit ;
        // un `UploadedFile` brut ne l'a pas → "Undefined property $name").
        return UploadedFile::fake()->createWithContent('portable.zip', file_get_contents($path));
    }

    // ── AC2/AC6 — upload via le service, SHA-256 serveur ─────────────────

    #[Test]
    public function upload_persists_a_tool_through_the_service(): void
    {
        Livewire::test(self::COMPONENT)
            ->set('version', '4.5.18')
            ->set('archive', $this->validPortable())
            ->call('importTool')
            ->assertDispatched('toastMagic', fn ($e, $p) => ($p['status'] ?? null) === 'success');

        $tool = AgentTool::query()->where('key', 'rainmeter')->sole();
        self::assertSame('sambaedu-rainmeter-4.5.18.zip', $tool->filename);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tool->sha256);
        self::assertFalse($tool->enabled, 'un premier upload reste désactivé');
    }

    #[Test]
    public function invalid_structure_zip_toasts_error_not_500(): void
    {
        // ZIP sans Rainmeter.exe → refus métier → toastError, zéro écriture.
        $path = $this->workDir . '/bad.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('README.txt', 'no exe here');
        $zip->close();

        Livewire::test(self::COMPONENT)
            ->set('version', '1.0')
            ->set('archive', UploadedFile::fake()->createWithContent('portable.zip', file_get_contents($path)))
            ->call('importTool')
            ->assertDispatched('toastMagic', fn ($e, $p) => ($p['status'] ?? null) === 'error');

        self::assertSame(0, AgentTool::query()->count());
    }

    // ── AC3 — toggle via le service ──────────────────────────────────────

    #[Test]
    public function toggle_flips_enabled_through_the_service(): void
    {
        AgentTool::query()->create([
            'key' => 'rainmeter', 'name' => 'Rainmeter (overlay)',
            'filename' => 'sambaedu-rainmeter-4.5.18.zip', 'sha256' => str_repeat('a', 64),
            'size' => 1, 'enabled' => false,
        ]);

        Livewire::test(self::COMPONENT)
            ->call('toggle')
            ->assertDispatched('toastMagic', fn ($e, $p) => ($p['status'] ?? null) === 'success');

        self::assertTrue(AgentTool::query()->where('key', 'rainmeter')->first()->enabled);
    }

    // ── AC3/piège #9 — Gate sur CHAQUE mutation (/livewire/update) ───────

    #[Test]
    public function upload_is_gated_by_permission(): void
    {
        // withoutExceptionHandling : l'AuthorizationException propage telle
        // quelle (pas de rendu via la vue d'erreur Vite — iso ReleasesRings).
        $this->withoutExceptionHandling();
        $this->expectException(AuthorizationException::class);

        $noPerm = User::query()->create(['login' => 'no-perm', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($noPerm);

        Livewire::test(self::COMPONENT)
            ->set('version', '1.0')
            ->set('archive', $this->validPortable())
            ->call('importTool');
    }

    #[Test]
    public function toggle_is_gated_by_permission(): void
    {
        AgentTool::query()->create([
            'key' => 'rainmeter', 'name' => 'Rainmeter (overlay)',
            'filename' => 'sambaedu-rainmeter-4.5.18.zip', 'sha256' => str_repeat('a', 64),
            'size' => 1, 'enabled' => false,
        ]);

        $this->withoutExceptionHandling();
        $this->expectException(AuthorizationException::class);

        $noPerm = User::query()->create(['login' => 'no-perm-2', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($noPerm);

        Livewire::test(self::COMPONENT)->call('toggle');
    }
}
