<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment\Http;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.6 / AC2.1 / AC2.4 / AC6.2 — Endpoint WingetOut piloté par SystemSetting.
 *
 * Vérifie que :
 *   - DB override true → 200 même si env reste false (sans config:cache)
 *   - DB override false → 400 même si env est true
 *   - Non-régression : sans clé DB, comportement identique à l'existant (env)
 *   - Effet immédiat : modifier SystemSetting dans le même cycle → reflète immédiatement
 */
#[Group('wpkg-deploy')]
#[Group('story-15-6')]
class WingetOutSettingsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();
        // Reset la clé DB entre les tests.
        SystemSetting::query()->whereIn('key', ['wpkg.winget_enabled', 'wpkg.allowed_ips'])->delete();
        // env défaut : false (fail-closed)
        Config::set('sambaedu.wpkg.winget_enabled', false);
    }

    protected function tearDown(): void
    {
        SystemSetting::query()->whereIn('key', ['wpkg.winget_enabled', 'wpkg.allowed_ips'])->delete();
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    /**
     * AC2.1 — DB override true > env false → 200 (winget activé depuis DB, env reste false).
     * AC2.4 — Effet immédiat sans config:cache.
     */
    #[Test]
    public function db_true_overrides_env_false_returns_200(): void
    {
        // env reste false.
        Config::set('sambaedu.wpkg.winget_enabled', false);

        // DB override → true.
        SystemSetting::set('wpkg.winget_enabled', true);

        $response = $this->post('/wpkg/winget_out.php', [
            'machine' => 'PC-TEST',
            'list' => '[]',
            'action' => 'list',
        ]);

        // 200 : winget activé depuis DB même si env = false.
        $response->assertStatus(200);
    }

    /**
     * AC2.1 — DB override false > env true → 400.
     */
    #[Test]
    public function db_false_overrides_env_true_returns_400(): void
    {
        Config::set('sambaedu.wpkg.winget_enabled', true);
        SystemSetting::set('wpkg.winget_enabled', false);

        $response = $this->post('/wpkg/winget_out.php', [
            'machine' => 'PC-TEST',
            'list' => '[]',
            'action' => 'list',
        ]);

        $response->assertStatus(400);
    }

    /**
     * AC1.3 / Non-régression — Sans clé DB, comportement identique à l'existant (env false → 400).
     */
    #[Test]
    public function no_db_key_falls_back_to_env_false_returns_400(): void
    {
        Config::set('sambaedu.wpkg.winget_enabled', false);
        // Pas de clé DB.

        $response = $this->post('/wpkg/winget_out.php', [
            'machine' => 'PC-TEST',
            'list' => '[]',
            'action' => 'list',
        ]);

        $response->assertStatus(400);
    }

    /**
     * Non-régression — Sans clé DB, env true → 200.
     */
    #[Test]
    public function no_db_key_falls_back_to_env_true_returns_200(): void
    {
        Config::set('sambaedu.wpkg.winget_enabled', true);
        // Pas de clé DB.

        $response = $this->post('/wpkg/winget_out.php', [
            'machine' => 'PC-TEST',
            'list' => '[]',
            'action' => 'list',
        ]);

        // 200 (winget activé via env)
        $response->assertStatus(200);
    }
}
