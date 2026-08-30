<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Shortcut;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aperçu d'icône d'un raccourci, quand deux foyers coexistent.
 *
 * Le `<name>.png` du formulaire d'upload garde la priorité — c'est ce que
 * l'interface a toujours montré. Le `<sha256>.ico` content-adressé est le seul
 * foyer qu'alimente le contrat amont : sans repli sur lui, un raccourci imposé
 * s'affichait générique alors que son icône était tirée et servie.
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM.
 */
class ShortcutIconUrlTest extends TestCase
{
    private string $servedDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servedDir = sys_get_temp_dir().'/shortcut-icons-'.uniqid();
        mkdir($this->servedDir, 0o755, true);
        config()->set('shortcut_icons.served_path', $this->servedDir);
        config()->set('shortcut_icons.route_path', 'assets/shortcut-icons');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->servedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->servedDir);

        parent::tearDown();
    }

    private function shortcut(array $attrs = []): Shortcut
    {
        return new Shortcut(array_merge([
            'key' => 'racc',
            'name' => 'Un raccourci sans icône locale '.uniqid(),
            'place' => Shortcut::PLACE_DESKTOP,
        ], $attrs));
    }

    #[Test]
    public function falls_back_to_the_content_addressed_icon_when_no_legacy_png_exists(): void
    {
        $checksum = str_repeat('a', 64);
        file_put_contents($this->servedDir.'/'.$checksum.'.ico', 'ico');

        $url = $this->shortcut([
            'icon_asset' => $checksum.'.ico',
            'icon_checksum' => $checksum,
        ])->iconUrl();

        self::assertStringContainsString('assets/shortcut-icons/'.$checksum.'.ico', $url);
    }

    #[Test]
    public function ignores_an_icon_asset_whose_file_is_gone(): void
    {
        $url = $this->shortcut(['icon_asset' => str_repeat('b', 64).'.ico'])->iconUrl();

        self::assertStringContainsString('system-run.png', $url);
    }

    #[Test]
    public function a_shortcut_without_any_icon_gets_the_generic_one(): void
    {
        self::assertStringContainsString('system-run.png', $this->shortcut()->iconUrl());
    }
}
