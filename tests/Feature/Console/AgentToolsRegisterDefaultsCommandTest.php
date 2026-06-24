<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AgentTool;
use App\Services\Agent\Tools\AgentToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Story 27.17 — provisioning serveur `agent:tools:register-defaults`.
 *
 * Enregistre le portable Rainmeter EMBARQUÉ (via `AgentToolService::registerEmbedded`).
 * Couvre : présence (l'outil est inscrit dans `agent_tools`), idempotence (un
 * 2e passage ne re-crée rien), fail-soft (source absente → SUCCESS + warning,
 * jamais d'échec d'install/update).
 */
class AgentToolsRegisterDefaultsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $toolsDir;

    private string $embeddedDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->toolsDir = storage_path('framework/testing/tools-' . uniqid());
        $this->embeddedDir = storage_path('framework/testing/embedded-' . uniqid());
        File::ensureDirectoryExists($this->toolsDir);
        File::ensureDirectoryExists($this->embeddedDir);
        config(['agent.tools_path' => $this->toolsDir]);
        config(['agent.tool_max_upload_bytes' => 200 * 1024 * 1024]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->toolsDir);
        File::deleteDirectory($this->embeddedDir);
        parent::tearDown();
    }

    private function requireZip(): void
    {
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip absent : ce cas exige un portable ZIP réel (vert sur /vm).');
        }
    }

    private function makeEmbeddedPortable(string $version = '0.1'): string
    {
        $path = $this->embeddedDir . "/sambaedu-rainmeter-{$version}.zip";
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('Rainmeter.exe', "MZ\x90\x00fake-exe");
        $zip->addFromString('Skins/SambaEduOverlay/SambaEduOverlay.ini', "[Variables]\n");
        $zip->close();

        return $path;
    }

    #[Test]
    public function registers_the_embedded_rainmeter_portable(): void
    {
        $this->requireZip();
        $path = $this->makeEmbeddedPortable('0.1');
        self::assertSame(0, AgentTool::query()->count());

        $this->artisan('agent:tools:register-defaults', ['--path' => $path])
            ->assertSuccessful();

        $tool = AgentTool::query()->where('key', AgentToolService::RAINMETER_KEY)->first();
        self::assertNotNull($tool);
        self::assertSame('sambaedu-rainmeter-0.1.zip', $tool->filename);
        self::assertNotEmpty($tool->sha256, 'SHA-256 calculé serveur');
        // Premier enregistrement = désactivé (l'admin l'active explicitement).
        self::assertFalse((bool) $tool->enabled);
        self::assertFileExists($this->toolsDir . '/sambaedu-rainmeter-0.1.zip');
    }

    #[Test]
    public function is_idempotent_second_run_does_not_recreate(): void
    {
        $this->requireZip();
        $path = $this->makeEmbeddedPortable('0.1');

        $this->artisan('agent:tools:register-defaults', ['--path' => $path])->assertSuccessful();
        $first = AgentTool::query()->where('key', AgentToolService::RAINMETER_KEY)->firstOrFail();

        // 2e passage : no-op (la clé existe déjà). On modifie enabled pour vérifier
        // que le register ne réécrit pas l'état admin.
        $first->enabled = true;
        $first->save();

        $this->artisan('agent:tools:register-defaults', ['--path' => $path])->assertSuccessful();

        self::assertSame(1, AgentTool::query()->where('key', AgentToolService::RAINMETER_KEY)->count());
        $reloaded = AgentTool::query()->where('key', AgentToolService::RAINMETER_KEY)->firstOrFail();
        self::assertTrue((bool) $reloaded->enabled, 'l\'état admin n\'est pas écrasé par un 2e register');
    }

    #[Test]
    public function fail_soft_when_source_is_missing(): void
    {
        // Aucune source : --path inexistant. La commande doit SORTIR EN SUCCESS
        // (un required sans source résolvable → warning, JAMAIS échec d'update).
        $this->artisan('agent:tools:register-defaults', ['--path' => $this->embeddedDir . '/absent.zip'])
            ->assertSuccessful();

        self::assertSame(0, AgentTool::query()->count(), 'aucune écriture quand la source est absente');
    }

    #[Test]
    public function fail_soft_when_discovery_finds_no_embedded_portable(): void
    {
        // Cas RÉEL « découverte vide » : pas de --path, et le répertoire de
        // découverte ne contient AUCUN `sambaedu-rainmeter-*.zip`. La commande
        // doit warn et sortir en SUCCESS, SANS jamais ouvrir d'archive (donc sans
        // dépendance ext-zip — pas de skip : ce test tourne partout).
        config(['agent.tools_embedded_path' => $this->embeddedDir]); // dossier vide (setUp)

        $this->artisan('agent:tools:register-defaults')
            ->expectsOutputToContain('Aucun portable Rainmeter embarqué trouvé')
            ->assertSuccessful();

        self::assertSame(0, AgentTool::query()->count(), 'aucune écriture quand la découverte est vide');
    }
}
