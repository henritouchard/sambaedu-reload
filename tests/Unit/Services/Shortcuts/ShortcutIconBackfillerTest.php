<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shortcuts;

use App\Models\Shortcut;
use App\Services\Shortcuts\ShortcutIconBackfiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.7 (AC5) — backfill name-addressed → content-addressed.
 * Copie (jamais supprime), dédup checksum, idempotent, missing fail-soft.
 */
class ShortcutIconBackfillerTest extends TestCase
{
    use RefreshDatabase;

    private string $servedDir;

    private string $legacyDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->servedDir = sys_get_temp_dir() . '/se-bf-served-' . uniqid();
        $this->legacyDir = sys_get_temp_dir() . '/se-bf-legacy-' . uniqid();
        @mkdir($this->legacyDir, 0755, true);
        config()->set('shortcut_icons.served_path', $this->servedDir);
        config()->set('shortcut_icons.legacy_path', $this->legacyDir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->servedDir, $this->legacyDir] as $dir) {
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '/*') ?: []);
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    private function shortcut(string $name, ?string $windowsIcon): Shortcut
    {
        return Shortcut::create([
            'key' => $name . '-' . uniqid(),
            'name' => $name,
            'place' => Shortcut::PLACE_DESKTOP,
            'is_active' => true,
            'windows_icon' => $windowsIcon,
        ]);
    }

    #[Test]
    public function backfills_bare_name_shortcuts_and_persists_columns(): void
    {
        file_put_contents($this->legacyDir . '/Calculatrice.ico', 'ico-calc');
        $checksum = hash('sha256', 'ico-calc');
        $sc = $this->shortcut('Calculatrice', 'Calculatrice');

        $stats = (new ShortcutIconBackfiller())->run();

        $sc->refresh();
        self::assertSame($checksum . '.ico', $sc->icon_asset);
        self::assertSame($checksum, $sc->icon_checksum);
        self::assertFileExists($this->servedDir . '/' . $checksum . '.ico');
        // Legacy COPIÉ, jamais supprimé.
        self::assertFileExists($this->legacyDir . '/Calculatrice.ico');
        self::assertSame(1, $stats['assets']);
        self::assertSame(1, $stats['linked']);
        self::assertSame(0, $stats['missing']);
    }

    #[Test]
    public function real_path_icon_is_never_backfilled(): void
    {
        // Chemin réel → hors backfill (jamais tenté).
        $sc = $this->shortcut('Firefox', 'C:\\Program Files\\firefox.exe,0');

        $stats = (new ShortcutIconBackfiller())->run();

        $sc->refresh();
        self::assertNull($sc->icon_asset);
        self::assertNull($sc->icon_checksum);
        self::assertSame(0, $stats['linked']);
    }

    #[Test]
    public function missing_legacy_ico_is_counted_not_failed(): void
    {
        $sc = $this->shortcut('Ghost', 'Ghost'); // pas de Ghost.ico sur disque

        $stats = (new ShortcutIconBackfiller())->run();

        $sc->refresh();
        self::assertNull($sc->icon_asset);
        self::assertSame(1, $stats['missing']);
        self::assertSame(0, $stats['linked']);
    }

    #[Test]
    public function is_idempotent_rerun_is_noop_and_dedups_checksum(): void
    {
        file_put_contents($this->legacyDir . '/A.ico', 'same');
        file_put_contents($this->legacyDir . '/B.ico', 'same'); // même contenu → même checksum
        $this->shortcut('A', 'A');
        $this->shortcut('B', 'B');

        $first = (new ShortcutIconBackfiller())->run();
        self::assertSame(1, $first['assets'], 'dédup checksum : 1 seul asset pour 2 noms au même contenu');
        self::assertSame(2, $first['linked']);

        $second = (new ShortcutIconBackfiller())->run();
        self::assertSame(2, $second['linked'], 're-run = lie toujours (no-op en écriture)');
        // Un seul fichier servi (content-addressed).
        self::assertCount(1, glob($this->servedDir . '/*.ico'));
    }
}
