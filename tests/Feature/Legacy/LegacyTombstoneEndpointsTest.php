<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Models\LegacyCatchallLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 38.2 — Tombstones natifs du canal client legacy.
 *
 * Vérifie par famille de format : statut + Content-Type exact + corps inerte,
 * POST multipart sans CSRF accepté, ligne DB `source='tombstone'` (+ machine/user
 * extraits + troncature), passthrough `os=linux` de `applications.php`, et
 * inertie de `applications.php` à TOUTE combinaison de paramètres.
 */
class LegacyTombstoneEndpointsTest extends TestCase
{
    private string $legacyTmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyTmpDir = sys_get_temp_dir() . '/sambaedu_tombstone_test_' . uniqid();
        mkdir($this->legacyTmpDir, 0777, true);

        Config::set('sambaedu.legacy_path', $this->legacyTmpDir);
        Config::set('sambaedu.legacy_base_url', 'http://127.0.0.1:80');
        Config::set('sambaedu.block_migrated_routes', true);
        Config::set('sambaedu.blocked_legacy_routes', []);
        Config::set('sambaedu.allowed_legacy_routes', []);

        if (! Schema::hasTable('legacy_catchall_logs')) {
            Schema::create('legacy_catchall_logs', function (Blueprint $table) {
                $table->id();
                $table->string('source', 16)->default('catchall')->index();
                $table->string('method', 10);
                $table->string('path', 2048);
                $table->string('ip', 45);
                $table->string('machine', 255)->nullable();
                $table->string('user_login', 255)->nullable();
                $table->text('query_string')->nullable();
                $table->text('referer')->nullable();
                $table->timestamp('created_at');
            });
        }

        LegacyCatchallLog::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->legacyTmpDir);
        LegacyCatchallLog::query()->delete();
        parent::tearDown();
    }

    /* ================================================================
     * Famille SCRIPT (commentaire cmd/bash no-op, text/plain)
     * ================================================================ */

    #[Test]
    public function applications_returns_inert_cmd_comment_by_default(): void
    {
        $response = $this->get('/gpo/applications.php');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        self::assertStringStartsWith('REM ', (string) $response->getContent());
        self::assertStringEndsWith("\r\n", (string) $response->getContent());
    }

    /**
     * AC2 — `applications.php` répond 200 script no-op à TOUTE combinaison de
     * params (action, user, machine, ret=0, context…) — aucune branche 4xx/5xx.
     */
    #[Test]
    public function applications_is_inert_for_every_parameter_combination(): void
    {
        $matrix = [
            ['action' => 'logon'],
            ['action' => 'logoff'],
            ['action' => 'startup'],
            ['action' => 'shutdown'],
            ['ret' => '0'],
            ['ret' => '0', 'action' => 'logon', 'context' => 'system'],
            ['user' => 'jdoe', 'machine' => 'PC-01'],
            [],
        ];

        foreach ($matrix as $params) {
            $response = $this->get('/gpo/applications.php?' . http_build_query($params));

            $response->assertOk();
            self::assertStringStartsWith('REM ', (string) $response->getContent());
        }
    }

    #[Test]
    public function applications_uses_bash_comment_when_os_is_linux_only_via_passthrough(): void
    {
        // os=linux = exception bornée : passthrough catchall (proxy legacy),
        // PAS de script tombstone. On crée le fichier legacy + fake le proxy.
        mkdir($this->legacyTmpDir . '/gpo', 0777, true);
        file_put_contents($this->legacyTmpDir . '/gpo/applications.php', '<?php echo "linux"; ?>');

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('# linux apps', 200, ['Content-Type' => 'text/plain'])]);

        $response = $this->get('/gpo/applications.php?os=linux');

        $response->assertOk();
        $response->assertSee('# linux apps');

        // Le hit est loggé par le CATCHALL (source défaut), PAS comme tombstone.
        self::assertFalse(
            LegacyCatchallLog::query()->where('source', 'tombstone')->exists(),
            'Le passthrough os=linux ne doit PAS créer de ligne source=tombstone.',
        );
        self::assertTrue(
            LegacyCatchallLog::query()
                ->where('path', 'gpo/applications.php')
                ->where('source', '!=', 'tombstone')
                ->exists(),
            'Le passthrough os=linux doit être loggé par le catchall (source != tombstone).',
        );
    }

    #[Test]
    public function no_internet_out_returns_inert_script(): void
    {
        $response = $this->get('/gpo/no_internet_out.php');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        self::assertStringStartsWith('REM ', (string) $response->getContent());
    }

    #[Test]
    public function cloud_out_returns_inert_script(): void
    {
        $response = $this->get('/partages/cloud_out.php');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        self::assertStringStartsWith('REM ', (string) $response->getContent());
    }

    #[Test]
    public function download_prefix_returns_inert_script(): void
    {
        $response = $this->get('/wpkg/download_prefix.php');
        $response->assertOk();
        self::assertStringStartsWith('REM ', (string) $response->getContent());
    }

    #[Test]
    public function ipxe_windows_action_returns_inert_cmd_script(): void
    {
        $response = $this->get('/ipxe/Win10/action.php?action=install');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        self::assertStringStartsWith('REM ', (string) $response->getContent());
    }

    /* ================================================================
     * Famille BASH STRICT (/ipxe/linux/action.php — toujours #)
     * ================================================================ */

    #[Test]
    public function ipxe_linux_action_always_returns_bash_comment(): void
    {
        $response = $this->get('/ipxe/linux/action.php');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        self::assertStringStartsWith('# ', (string) $response->getContent());
        self::assertStringEndsWith("\n", (string) $response->getContent());
        // Jamais un `exit` (le démon eval le corps en boucle).
        self::assertStringNotContainsString('exit', (string) $response->getContent());
    }

    /* ================================================================
     * Famille 204 No Content
     * ================================================================ */

    #[Test]
    public function wallpaper_out_returns_204(): void
    {
        $response = $this->get('/gpo/wallpaper_out.php');
        $response->assertNoContent();
        self::assertSame('', (string) $response->getContent());
    }

    #[Test]
    public function shortcuts_file_and_icon_return_204(): void
    {
        $this->get('/gpo/shortcuts_out.php?action=file')->assertNoContent();
        $this->get('/gpo/shortcuts_out.php?action=icon')->assertNoContent();
    }

    #[Test]
    public function shortcuts_without_file_action_returns_inert_script(): void
    {
        $response = $this->get('/gpo/shortcuts_out.php?action=logon');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        self::assertStringStartsWith('REM ', (string) $response->getContent());
    }

    #[Test]
    public function shortcuts_uses_bash_comment_for_linux(): void
    {
        $response = $this->get('/gpo/shortcuts_out.php?os=linux&action=logon');
        $response->assertOk();
        self::assertStringStartsWith('# ', (string) $response->getContent());
    }

    /* ================================================================
     * Famille JSON vide {}
     * ================================================================ */

    #[Test]
    public function json_endpoints_return_empty_object(): void
    {
        foreach (['veyon_out', 'associations_out', 'firefox_out', 'thunderbird_out'] as $endpoint) {
            $response = $this->get("/gpo/{$endpoint}.php");
            $response->assertOk();
            self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
            self::assertSame('{}', (string) $response->getContent());
        }
    }

    /* ================================================================
     * Famille XML vide valide
     * ================================================================ */

    #[Test]
    public function wpkg_xml_endpoints_return_valid_empty_xml(): void
    {
        $cases = [
            '/wpkg/hosts_xml_out.php' => '<wpkg/>',
            '/wpkg/profiles_xml_out.php' => '<profiles/>',
            '/wpkg/packages_xml_out.php' => '<packages/>',
        ];

        foreach ($cases as $url => $root) {
            $response = $this->get($url);
            $response->assertOk();
            $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
            $body = (string) $response->getContent();
            self::assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $body);
            self::assertStringContainsString($root, $body);
            self::assertNotFalse(simplexml_load_string($body), "XML invalide pour {$url}");
        }
    }

    #[Test]
    public function ipxe_win10_xml_endpoints_return_valid_empty_xml(): void
    {
        $sysprep = (string) $this->get('/ipxe/Win10/sysprep.xml.php')->getContent();
        self::assertStringContainsString('<unattend/>', $sysprep);
        self::assertNotFalse(simplexml_load_string($sysprep));

        $unattend = (string) $this->get('/ipxe/Win10/unattend.xml.php')->getContent();
        self::assertStringContainsString('urn:schemas-microsoft-com:unattend', $unattend);
        self::assertNotFalse(simplexml_load_string($unattend));
    }

    /* ================================================================
     * Famille 200 corps vide (puits de logs)
     * ================================================================ */

    #[Test]
    public function wpkg_log_returns_empty_body(): void
    {
        $response = $this->get('/wpkg/wpkg_log.php');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        self::assertSame('', (string) $response->getContent());
    }

    /* ================================================================
     * POST multipart sans CSRF
     * ================================================================ */

    #[Test]
    public function tombstone_accepts_post_without_csrf_token(): void
    {
        // Aucun _token — withoutMiddleware(['web']) court-circuite VerifyCsrfToken.
        $response = $this->post('/gpo/applications.php', ['action' => 'logon', 'machine' => 'PC-01']);
        $response->assertOk();
        self::assertStringStartsWith('REM ', (string) $response->getContent());
    }

    /* ================================================================
     * Observabilité — ligne DB source='tombstone' + machine/user
     * ================================================================ */

    #[Test]
    public function tombstone_hit_is_logged_with_source_and_identity(): void
    {
        $this->post('/gpo/applications.php', [
            'action' => 'logon',
            'machine' => 'PC-01',
            'user' => 'jdoe',
        ]);

        $this->assertDatabaseHas('legacy_catchall_logs', [
            'source' => 'tombstone',
            'method' => 'POST',
            'path' => 'gpo/applications.php',
            'machine' => 'PC-01',
            'user_login' => 'jdoe',
        ]);
    }

    #[Test]
    public function tombstone_machine_falls_back_to_poste_param(): void
    {
        $this->get('/gpo/wallpaper_out.php?poste=PC-42');

        $this->assertDatabaseHas('legacy_catchall_logs', [
            'source' => 'tombstone',
            'path' => 'gpo/wallpaper_out.php',
            'machine' => 'PC-42',
        ]);
    }

    #[Test]
    public function tombstone_truncates_overlong_machine_to_column_width(): void
    {
        $long = str_repeat('a', 300);
        $this->get('/gpo/wallpaper_out.php?machine=' . $long);

        $row = LegacyCatchallLog::query()
            ->where('source', 'tombstone')
            ->where('path', 'gpo/wallpaper_out.php')
            ->first();

        self::assertNotNull($row);
        self::assertSame(255, mb_strlen((string) $row->machine));
    }

    /**
     * Piège — corps exécuté = message FIXE : un paramètre de requête ne doit
     * JAMAIS être réfléchi dans le corps servi (injection dans un script CALLé).
     */
    #[Test]
    public function tombstone_body_never_reflects_request_parameters(): void
    {
        $sentinel = 'INJECT_ME_9137';
        $response = $this->get('/gpo/applications.php?action=' . $sentinel . '&user=' . $sentinel);

        self::assertStringNotContainsString($sentinel, (string) $response->getContent());
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
