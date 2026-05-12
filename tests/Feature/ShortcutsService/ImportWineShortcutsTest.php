<?php

declare(strict_types=1);

namespace Tests\Feature\ShortcutsService;

use App\Services\ShortcutsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature `ShortcutsService::importWineShortcuts` — Story 16.3c AC2.2.
 *
 * Hook test : binding container `legacy.get_wine_shortcuts` permet de
 * remplacer le helper legacy par un mock côté tests sans charger
 * `legacy/bootstrap.php`.
 */
class ImportWineShortcutsTest extends TestCase
{
    private string $tmpShortcutsFile;
    private ShortcutsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpShortcutsFile = sys_get_temp_dir() . '/wine-shortcuts-test-' . bin2hex(random_bytes(4)) . '.json';

        // On crée le service avec une sous-classe qui pointe vers un fichier
        // /tmp pour ne pas toucher au vrai `/etc/sambaedu/...`.
        $tmp = $this->tmpShortcutsFile;
        $this->service = new class(
            $this->app->make(\App\Services\FileManagerService::class),
            $this->app->make(\App\Services\ImageManagerService::class),
        ) extends ShortcutsService {
            public string $tmpPath = '';
            public function __construct($fm, $im)
            {
                parent::__construct($fm, $im);
            }
            public function setShortcutsFile(string $path): void
            {
                $r = new \ReflectionClass(ShortcutsService::class);
                $p = $r->getProperty('shortcutsFile');
                $p->setAccessible(true);
                $p->setValue($this, $path);
            }
        };
        $this->service->setShortcutsFile($tmp);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpShortcutsFile)) {
            @unlink($this->tmpShortcutsFile);
        }
        parent::tearDown();
    }

    private function bindLegacyMock(array $shortcuts): void
    {
        $this->app->bind('legacy.get_wine_shortcuts', function () use ($shortcuts) {
            return fn(string $app) => $shortcuts;
        });
    }

    #[Test]
    public function it_calls_legacy_helper_and_returns_added_count(): void
    {
        $this->bindLegacyMock([
            ['name' => 'FirefoxWine', 'linux' => ['link' => 'env WINEPREFIX=...']],
            ['name' => 'NotepadPlusWine', 'linux' => ['link' => 'env WINEPREFIX=...']],
        ]);

        $count = $this->service->importWineShortcuts('firefox');

        $this->assertSame(2, $count);
    }

    #[Test]
    public function it_atomically_merges_new_shortcuts_into_existing_json(): void
    {
        // Pré-existe : un raccourci.
        file_put_contents($this->tmpShortcutsFile, json_encode([
            ['name' => 'ExistingShortcut'],
        ]));

        $this->bindLegacyMock([
            ['name' => 'NewWineShortcut'],
        ]);

        $count = $this->service->importWineShortcuts('firefox');
        $this->assertSame(1, $count);

        $merged = json_decode((string) file_get_contents($this->tmpShortcutsFile), true);
        $names = array_column($merged, 'name');
        $this->assertContains('ExistingShortcut', $names);
        $this->assertContains('NewWineShortcut', $names);
    }

    #[Test]
    public function it_creates_shortcuts_file_when_absent(): void
    {
        // Pas de fichier préalable.
        $this->assertFileDoesNotExist($this->tmpShortcutsFile);

        $this->bindLegacyMock([
            ['name' => 'Fresh'],
        ]);

        $count = $this->service->importWineShortcuts('');
        $this->assertSame(1, $count);
        $this->assertFileExists($this->tmpShortcutsFile);
    }

    #[Test]
    public function it_handles_empty_shortcut_list_gracefully(): void
    {
        $this->bindLegacyMock([]);

        $count = $this->service->importWineShortcuts('firefox');
        $this->assertSame(0, $count);
    }

    #[Test]
    public function it_logs_gpo_channel_action_type(): void
    {
        $this->bindLegacyMock([['name' => 'X']]);

        \Illuminate\Support\Facades\Log::shouldReceive('channel')
            ->with('gpo')
            ->andReturnSelf()
            ->shouldReceive('log')
            ->atLeast()->once();

        $this->service->importWineShortcuts('firefox');
    }
}
