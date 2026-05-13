<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Services\ApplicationTemplatesScanner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — AC5.1 + AC6.2.
 *
 * Tests Unit `ApplicationTemplatesScanner` : scan FS hardcodés + path
 * traversal bloqué + merge package/local + 3 formats reconnus.
 */
class ApplicationTemplatesScannerTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/se4fs-tpl-' . uniqid();
        mkdir($this->tmpRoot . '/package', 0o755, recursive: true);
        mkdir($this->tmpRoot . '/local', 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            if (is_dir($p) && ! is_link($p)) {
                $this->rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    #[Test]
    public function it_returns_empty_when_paths_do_not_exist(): void
    {
        $scanner = new ApplicationTemplatesScanner();
        $result = $scanner->scan('/no/such/path/package/', '/no/such/path/local/');
        self::assertSame([], $result);
    }

    #[Test]
    public function it_scans_action_dot_os_files_with_lines(): void
    {
        $appDir = $this->tmpRoot . '/local/myapp';
        mkdir($appDir, 0o755, recursive: true);
        file_put_contents($appDir . '/startup.linux', "echo hello\n");

        $scanner = new ApplicationTemplatesScanner();
        $result = $scanner->scan($this->tmpRoot . '/package/', $this->tmpRoot . '/local/');
        self::assertCount(1, $result);
        $entry = $result[0];
        self::assertSame('startup', $entry['action']);
        self::assertSame('linux', $entry['os']);
        self::assertSame('bash', $entry['interpreter']);
        self::assertSame('myapp', $entry['app']);
        self::assertSame('local', $entry['type']);
        self::assertSame(['echo hello' . "\n"], $entry['script']);
    }

    #[Test]
    public function it_scans_scripts_json(): void
    {
        // Note iso-legacy : le legacy scanne d'abord scripts.json, puis
        // le pattern `<action>(@group).<os>` peut matcher le même fichier `.windows`
        // séparément. On vérifie la présence du JSON entry plutôt que le count
        // strict (cf. comportement legacy `merge_applications` qui distingue par
        // hash sha256 de (os, action, app, name, context, remote, interpreter, file)).
        $appDir = $this->tmpRoot . '/package/myapp';
        mkdir($appDir, 0o755, recursive: true);
        file_put_contents($appDir . '/scripts.json', json_encode([
            'main' => [
                'file' => 'someScript.windows',
                'os' => 'windows',
                'action' => 'logon',
                'context' => '',
            ],
        ]));
        file_put_contents($appDir . '/someScript.windows', "REM hello\r\n");

        $scanner = new ApplicationTemplatesScanner();
        $result = $scanner->scan($this->tmpRoot . '/package/', $this->tmpRoot . '/local/');
        self::assertNotEmpty($result);
        // Une entrée doit avoir name='main' (issue du scripts.json).
        $jsonEntry = null;
        foreach ($result as $r) {
            if (($r['name'] ?? null) === 'main') {
                $jsonEntry = $r;
                break;
            }
        }
        self::assertNotNull($jsonEntry, 'L\'entrée scripts.json `main` doit être présente');
        self::assertSame('cmd', $jsonEntry['interpreter']);
        self::assertSame("REM hello\r\n", $jsonEntry['script'][0]);
    }

    #[Test]
    public function it_scans_packages_dot_list(): void
    {
        $appDir = $this->tmpRoot . '/local/utils';
        mkdir($appDir, 0o755, recursive: true);
        file_put_contents($appDir . '/packages.list', "git\nvim\n");

        $scanner = new ApplicationTemplatesScanner();
        $result = $scanner->scan($this->tmpRoot . '/package/', $this->tmpRoot . '/local/');
        self::assertCount(1, $result);
        $entry = $result[0];
        self::assertSame('apt', $entry['interpreter']);
        self::assertSame('startup', $entry['action']);
        self::assertSame('linux', $entry['os']);
        self::assertStringContainsString('git', $entry['script'][0]);
        self::assertStringContainsString('vim', $entry['script'][0]);
    }

    #[Test]
    public function it_merges_package_and_local_priorizing_local(): void
    {
        // Même hash (même os/action/app/name/context/remote/interpreter/file)
        // → local doit gagner sur le contenu.
        $pkgDir = $this->tmpRoot . '/package/myapp';
        $localDir = $this->tmpRoot . '/local/myapp';
        mkdir($pkgDir, 0o755, recursive: true);
        mkdir($localDir, 0o755, recursive: true);
        file_put_contents($pkgDir . '/startup.linux', "echo pkg\n");
        file_put_contents($localDir . '/startup.linux', "echo local\n");

        $scanner = new ApplicationTemplatesScanner();
        $result = $scanner->scan($this->tmpRoot . '/package/', $this->tmpRoot . '/local/');
        self::assertCount(1, $result);
        // Le local arrive en second, son `script` écrase celui du package.
        self::assertSame(['echo local' . "\n"], $result[0]['script']);
    }

    #[Test]
    public function it_blocks_path_traversal_via_symlink(): void
    {
        // Crée un symlink vers un répertoire hors base.
        $outside = $this->tmpRoot . '/outside-attack';
        mkdir($outside . '/danger', 0o755, recursive: true);
        file_put_contents($outside . '/danger/startup.linux', "echo evil\n");

        $appLinkDir = $this->tmpRoot . '/local';
        if (! @symlink($outside . '/danger', $appLinkDir . '/escape')) {
            self::markTestSkipped('symlink non supporté sur ce FS');
        }

        $scanner = new ApplicationTemplatesScanner();
        $result = $scanner->scan($this->tmpRoot . '/package/', $appLinkDir . '/');
        // Le symlink pointe vers un dossier hors `local/` → doit être rejeté
        // par la guarde realpath(). Scan retourne vide ou aucune entrée 'evil'.
        $hasEvil = false;
        foreach ($result as $entry) {
            if (str_contains($entry['script'][0] ?? '', 'evil')) {
                $hasEvil = true;
                break;
            }
        }
        self::assertFalse($hasEvil, 'Path traversal via symlink doit être bloqué');
    }

    #[Test]
    public function it_handles_include_exclude_group_filter(): void
    {
        $appDir = $this->tmpRoot . '/local/myapp';
        mkdir($appDir, 0o755, recursive: true);
        file_put_contents($appDir . '/startup@profs.linux', "echo profs\n");
        file_put_contents($appDir . '/startup@-eleves.linux', "echo not-eleves\n");

        $scanner = new ApplicationTemplatesScanner();
        $result = $scanner->scan($this->tmpRoot . '/package/', $this->tmpRoot . '/local/');
        self::assertCount(2, $result);
        $includes = array_column($result, 'includes');
        $excludes = array_column($result, 'excludes');
        // Au moins un avec ['profs'] inclus et un avec ['eleves'] exclus.
        self::assertNotEmpty(array_filter($includes, fn($v) => in_array('profs', $v, true)));
        self::assertNotEmpty(array_filter($excludes, fn($v) => in_array('eleves', $v, true)));
    }
}
