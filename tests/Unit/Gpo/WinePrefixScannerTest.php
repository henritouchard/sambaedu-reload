<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Services\WinePrefixScanner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `WinePrefixScanner` — Story 16.3c AC6.2.
 */
class WinePrefixScannerTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBase = sys_get_temp_dir() . '/wine-scanner-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpBase, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpBase);
        parent::tearDown();
    }

    private function recursiveDelete(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->recursiveDelete($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }

    #[Test]
    public function list_returns_empty_when_dir_missing(): void
    {
        $scanner = new WinePrefixScanner();
        $missing = $this->tmpBase . '/does-not-exist';

        $this->assertSame([], $scanner->list($missing));
    }

    #[Test]
    public function list_parses_wine_prefix_subdirs(): void
    {
        mkdir($this->tmpBase . '/wine-firefox');
        mkdir($this->tmpBase . '/wine-photoshop');
        mkdir($this->tmpBase . '/wine-autocad');

        $scanner = new WinePrefixScanner();
        $list = $scanner->list($this->tmpBase);

        // Tri alpha case-insensitive.
        $this->assertSame(['autocad', 'firefox', 'photoshop'], $list);
    }

    #[Test]
    public function list_ignores_non_wine_dirs(): void
    {
        mkdir($this->tmpBase . '/wine-app1');
        mkdir($this->tmpBase . '/other-dir');
        mkdir($this->tmpBase . '/wine');         // pas de suffixe → ignoré (regex `wine-.+`)
        mkdir($this->tmpBase . '/wine-');        // suffixe vide → ignoré
        file_put_contents($this->tmpBase . '/wine-fake.txt', 'x'); // fichier, pas dossier — mais scanner accepte tout matching la regex

        $scanner = new WinePrefixScanner();
        $list = $scanner->list($this->tmpBase);

        // `wine-fake.txt` matche la regex `wine-(.+)` → `fake.txt` retourné
        // (parité legacy `dir()` qui n'isfile-check pas non plus).
        $this->assertContains('app1', $list);
        $this->assertContains('fake.txt', $list);
        $this->assertNotContains('', $list);
        $this->assertNotContains('other-dir', $list);
    }

    #[Test]
    public function exists_returns_true_for_empty_string_default_container(): void
    {
        $scanner = new WinePrefixScanner();
        $this->assertTrue($scanner->exists('', $this->tmpBase));
    }

    #[Test]
    public function exists_returns_true_for_matching_prefix(): void
    {
        mkdir($this->tmpBase . '/wine-firefox');
        $scanner = new WinePrefixScanner();
        $this->assertTrue($scanner->exists('firefox', $this->tmpBase));
        $this->assertFalse($scanner->exists('chrome', $this->tmpBase));
    }

    #[Test]
    public function list_uses_config_fallback_when_base_path_null(): void
    {
        // Override config + override path d'instance → on appelle list(null)
        // et on vérifie qu'aucun crash n'a lieu et qu'on tape le default
        // (chemin non existant en CI → liste vide).
        config(['sambaedu.gpo.wine.prefix_base' => $this->tmpBase]);
        mkdir($this->tmpBase . '/wine-fromconfig');

        $scanner = new WinePrefixScanner();
        $this->assertContains('fromconfig', $scanner->list(null));
    }
}
