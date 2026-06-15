<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shortcuts;

use App\Services\Shortcuts\ShortcutIconAssetService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.7 (AC1) — content-adressage d'un `.ico` vers le dossier servi.
 * Filename = `<sha256>.ico`, copie (jamais déplacement), idempotent.
 */
class ShortcutIconAssetServiceTest extends TestCase
{
    private string $servedDir;

    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->servedDir = sys_get_temp_dir() . '/se-icons-served-' . uniqid();
        $this->sourceDir = sys_get_temp_dir() . '/se-icons-src-' . uniqid();
        @mkdir($this->sourceDir, 0755, true);
        config()->set('shortcut_icons.served_path', $this->servedDir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->servedDir, $this->sourceDir] as $dir) {
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '/*') ?: []);
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function content_addresses_ico_and_returns_filename_and_checksum(): void
    {
        $source = $this->sourceDir . '/Calculatrice.ico';
        file_put_contents($source, 'contenu-ico');
        $checksum = hash('sha256', 'contenu-ico');

        $result = (new ShortcutIconAssetService())->contentAddress($source);

        self::assertSame([
            'asset' => $checksum . '.ico',
            'checksum' => $checksum,
        ], $result);
        self::assertFileExists($this->servedDir . '/' . $checksum . '.ico');
        // La source legacy n'est JAMAIS supprimée (copie, rollback-safe).
        self::assertFileExists($source);
    }

    #[Test]
    public function is_idempotent_same_content_not_rewritten(): void
    {
        $source = $this->sourceDir . '/x.ico';
        file_put_contents($source, 'abc');
        $svc = new ShortcutIconAssetService();

        $first = $svc->contentAddress($source);
        $target = $this->servedDir . '/' . $first['checksum'] . '.ico';
        $mtime = filemtime($target);

        clearstatcache();
        $second = $svc->contentAddress($source);

        self::assertSame($first, $second);
        self::assertSame($mtime, filemtime($target), 'fichier présent = jamais réécrit');
    }

    #[Test]
    public function missing_source_returns_null_fail_soft(): void
    {
        self::assertNull((new ShortcutIconAssetService())->contentAddress($this->sourceDir . '/nope.ico'));
    }

    #[Test]
    public function filename_is_content_derived_never_user_controlled(): void
    {
        // Le filename servi est ENTIÈREMENT dérivé du contenu (hash hex) : aucun
        // `..`/séparateur possible (garde-fou sécurité, piège n° 1/n° 3).
        $source = $this->sourceDir . '/../evil name,%.ico';
        file_put_contents($this->sourceDir . '/evilsrc.ico', 'payload');

        $result = (new ShortcutIconAssetService())->contentAddress($this->sourceDir . '/evilsrc.ico');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\.ico$/', $result['asset']);
    }
}
