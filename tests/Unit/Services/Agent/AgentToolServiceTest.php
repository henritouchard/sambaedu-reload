<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\AgentTool;
use App\Services\Agent\Tools\AgentToolException;
use App\Services\Agent\Tools\AgentToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Story 25.6 — `AgentToolService` (AC1, AC2, AC3, D5).
 *
 * SEUL écrivain de `agent_tools`. On valide : l'upload OK (SHA-256 CALCULÉ
 * SERVEUR, filename dérivé de la version, mono-version qui remplace), et les
 * refus (extension, MIME, taille, structure ZIP, version malformée) qui
 * laissent AUCUNE ligne écrite ni fichier orphelin. Toggle GLOBAL. ZIP factices
 * en tmp via `config(['agent.tools_path' => …])` — jamais le vrai storage/.
 * Sans HTTP (le contrat HTTP vit dans `ToolManifestSkinEndpointTest`).
 */
class AgentToolServiceTest extends TestCase
{
    use RefreshDatabase;

    private AgentToolService $service;

    private string $toolsDir;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AgentToolService();
        $this->toolsDir = storage_path('framework/testing/tools-' . uniqid());
        $this->workDir = storage_path('framework/testing/work-' . uniqid());
        File::ensureDirectoryExists($this->toolsDir);
        File::ensureDirectoryExists($this->workDir);
        config(['agent.tools_path' => $this->toolsDir]);
        config(['agent.tool_max_upload_bytes' => 200 * 1024 * 1024]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->toolsDir);
        File::deleteDirectory($this->workDir);

        parent::tearDown();
    }

    /**
     * Fabrique un .zip portable sur disque et en construit un UploadedFile.
     *
     * @param array<string,string> $entries
     */
    private function makeUpload(array $entries, string $originalName = 'portable.zip', string $mime = 'application/zip'): UploadedFile
    {
        $path = $this->workDir . '/' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            if (str_ends_with($name, '/')) {
                $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $zip->addFromString($name, $content);
            }
        }
        $zip->close();

        // test=true → pas de vérif is_uploaded_file (UploadedFile factice).
        return new UploadedFile($path, $originalName, $mime, null, true);
    }

    private function validPortable(): UploadedFile
    {
        return $this->makeUpload([
            'Rainmeter.exe' => "MZ\x90\x00fake-exe",
            'Skins/SambaEduOverlay/SambaEduOverlay.ini' => "[Variables]\n",
        ]);
    }

    private function assertRejected(string $reason, callable $call): void
    {
        $before = AgentTool::query()->count();
        try {
            $call();
            self::fail("Une AgentToolException était attendue (raison {$reason}).");
        } catch (AgentToolException $e) {
            self::assertSame($reason, $e->reason, "raison attendue: {$reason}, obtenue: {$e->reason}");
        }
        self::assertSame($before, AgentTool::query()->count(), 'aucune ligne ne doit être écrite sur refus');
    }

    // ── AC2 — upload valide : SHA-256 serveur + filename dérivé ──────────

    #[Test]
    public function valid_upload_computes_server_sha256_and_derives_filename(): void
    {
        $file = $this->validPortable();
        $expectedSha = hash_file('sha256', $file->getRealPath());

        $tool = $this->service->upload($file, '4.5.18', uploadedBy: null);

        self::assertSame('rainmeter', $tool->key);
        self::assertSame('sambaedu-rainmeter-4.5.18.zip', $tool->filename);
        self::assertSame($expectedSha, $tool->sha256, 'le SHA-256 doit être calculé serveur sur le fichier stocké');
        self::assertGreaterThan(0, $tool->size);
        self::assertFalse($tool->enabled, 'un premier upload reste désactivé par défaut');
        self::assertFileExists($this->toolsDir . '/sambaedu-rainmeter-4.5.18.zip');
    }

    #[Test]
    public function client_declared_filename_is_never_trusted_filename_is_derived(): void
    {
        // Nom client hostile/traversal : ignoré, le serveur dérive de la version.
        $file = $this->makeUpload([
            'Rainmeter.exe' => 'MZ',
            'Skins/x.ini' => 'x',
        ], originalName: '../../etc/passwd.zip');

        $tool = $this->service->upload($file, '1.0', uploadedBy: null);

        self::assertSame('sambaedu-rainmeter-1.0.zip', $tool->filename);
        self::assertStringNotContainsString('..', $tool->filename);
    }

    // ── AC2 — refus : version, extension, MIME, taille, structure ────────

    #[Test]
    public function malformed_version_is_rejected(): void
    {
        $this->assertRejected('invalid_version', fn () => $this->service->upload($this->validPortable(), '../evil'));
    }

    #[Test]
    public function non_zip_extension_is_rejected(): void
    {
        $file = $this->makeUpload(['Rainmeter.exe' => 'MZ', 'Skins/x' => 'x'], originalName: 'portable.exe');
        $this->assertRejected('invalid_extension', fn () => $this->service->upload($file, '1.0'));
    }

    #[Test]
    public function wrong_mime_is_rejected(): void
    {
        $file = $this->makeUpload(['Rainmeter.exe' => 'MZ', 'Skins/x' => 'x'], originalName: 'portable.zip', mime: 'image/png');
        $this->assertRejected('invalid_mime', fn () => $this->service->upload($file, '1.0'));
    }

    #[Test]
    public function oversized_archive_is_rejected(): void
    {
        config(['agent.tool_max_upload_bytes' => 4]); // borne minuscule
        $file = $this->validPortable();
        $this->assertRejected('too_large', fn () => $this->service->upload($file, '1.0'));
    }

    #[Test]
    public function zip_without_rainmeter_exe_is_rejected(): void
    {
        $file = $this->makeUpload(['Skins/SambaEduOverlay/x.ini' => 'x']); // pas de Rainmeter.exe
        $this->assertRejected('invalid_structure', fn () => $this->service->upload($file, '1.0'));
    }

    #[Test]
    public function zip_without_skins_dir_is_rejected(): void
    {
        $file = $this->makeUpload(['Rainmeter.exe' => 'MZ', 'README.txt' => 'x']); // pas de Skins/
        $this->assertRejected('invalid_structure', fn () => $this->service->upload($file, '1.0'));
    }

    #[Test]
    public function corrupt_zip_is_rejected(): void
    {
        $path = $this->workDir . '/corrupt.zip';
        file_put_contents($path, "not-a-zip-at-all");
        $file = new UploadedFile($path, 'portable.zip', 'application/zip', null, true);
        $this->assertRejected('invalid_zip', fn () => $this->service->upload($file, '1.0'));
    }

    // ── AC2/D5 — mono-version : un nouvel upload remplace l'archive ──────

    #[Test]
    public function reupload_replaces_active_version_and_purges_old_file(): void
    {
        $this->service->upload($this->validPortable(), '4.5.18');
        self::assertFileExists($this->toolsDir . '/sambaedu-rainmeter-4.5.18.zip');

        $tool = $this->service->upload($this->validPortable(), '4.6.0');

        self::assertSame(1, AgentTool::query()->count(), 'mono-version : une seule ligne pour la key');
        self::assertSame('sambaedu-rainmeter-4.6.0.zip', $tool->filename);
        self::assertFileExists($this->toolsDir . '/sambaedu-rainmeter-4.6.0.zip');
        self::assertFileDoesNotExist($this->toolsDir . '/sambaedu-rainmeter-4.5.18.zip', 'ancienne archive purgée');
    }

    #[Test]
    public function reupload_preserves_enabled_state(): void
    {
        $tool = $this->service->upload($this->validPortable(), '4.5.18');
        $this->service->toggle($tool, true);

        $reuploaded = $this->service->upload($this->validPortable(), '4.6.0');

        self::assertTrue($reuploaded->enabled, 'un re-upload d\'un outil déjà actif reste actif');
    }

    // ── P8 — aucun fichier orphelin après refus / échec post-move ────────

    #[Test]
    public function rejected_upload_leaves_no_orphan_file_on_disk(): void
    {
        // Refus à l'étape 1 (version malformée) — ne dépend PAS de ext-zip :
        // un fichier .zip factice suffit, le refus survient avant toute lecture
        // du ZIP. On vérifie qu'AUCUN fichier ne traîne dans tools_path.
        $path = $this->workDir . '/dummy.zip';
        file_put_contents($path, 'PK-not-a-real-zip');
        $file = new UploadedFile($path, 'portable.zip', 'application/zip', null, true);

        $this->assertRejected('invalid_version', fn () => $this->service->upload($file, '../evil'));

        self::assertCount(0, glob($this->toolsDir . '/*'), 'aucun fichier ne doit rester dans tools_path après un refus');
    }

    #[Test]
    public function db_failure_after_move_leaves_no_orphan_file(): void
    {
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip absent : ce cas exige un portable ZIP réel (vert sur /vm).');
        }

        // Le fichier est posé sur disque par moveConfined, PUIS l'écriture DB
        // échoue (table absente → QueryException). Le .zip déplacé ne doit pas
        // rester orphelin (P1).
        \Illuminate\Support\Facades\Schema::drop('agent_tools');

        try {
            $this->service->upload($this->validPortable(), '4.5.18');
            self::fail('Une exception DB était attendue (table agent_tools absente).');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(AgentToolException::class, $e, 'l\'échec DB n\'est pas un refus métier');
        }

        self::assertCount(0, glob($this->toolsDir . '/*'), 'le .zip déplacé doit être nettoyé après échec DB (aucun orphelin)');
    }

    // ── AC3 — toggle GLOBAL ──────────────────────────────────────────────

    #[Test]
    public function toggle_flips_enabled_flag(): void
    {
        $tool = $this->service->upload($this->validPortable(), '4.5.18');
        self::assertFalse($tool->enabled);

        $this->service->toggle($tool, true);
        self::assertTrue(AgentTool::query()->where('key', 'rainmeter')->first()->enabled);

        $this->service->toggle($tool->refresh(), false);
        self::assertFalse(AgentTool::query()->where('key', 'rainmeter')->first()->enabled);
    }
}
