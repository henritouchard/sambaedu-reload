<?php

declare(strict_types=1);

namespace Tests\Unit\ScriptsOs\Services;

use App\ScriptsOs\Enums\ScriptExecutionAction;
use App\ScriptsOs\Enums\ScriptExecutionOs;
use App\ScriptsOs\Services\WrapperScriptRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.12 — AC3.1 (≥4 cas).
 */
class WrapperScriptRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WrapperScriptRenderer::clearCache();
    }

    #[Test]
    public function it_renders_windows_wrapper_with_powershell_and_endpoint_url(): void
    {
        $renderer = $this->app->make(WrapperScriptRenderer::class);

        $out = $renderer->wrap(
            'echo hello',
            ScriptExecutionAction::LOGON,
            ScriptExecutionOs::WINDOWS,
        );

        self::assertStringContainsString('Invoke-RestMethod', $out);
        self::assertStringContainsString('Bearer ', $out);
        self::assertStringContainsString('/api/v1/script-execution-logs', $out);
        self::assertStringContainsString('certutil -decode', $out);
        // Pas de tag PHP brut dans la sortie
        self::assertStringNotContainsString('<?php', $out);
        self::assertStringNotContainsString('?>', $out);
    }

    #[Test]
    public function it_renders_linux_wrapper_with_curl_and_jq(): void
    {
        $renderer = $this->app->make(WrapperScriptRenderer::class);

        $out = $renderer->wrap(
            'echo bonjour',
            ScriptExecutionAction::STARTUP,
            ScriptExecutionOs::LINUX,
        );

        // Post review Q5 — `-k` retiré (TLS strict Phase 2, CA SambaEdu requis).
        self::assertStringContainsString('curl -fsS', $out);
        self::assertStringNotContainsString('curl -kfsS', $out);
        self::assertStringContainsString('jq', $out);
        self::assertStringContainsString('base64 -d', $out);
        self::assertStringContainsString('/var/lib/sambaedu/auth.json', $out);
        self::assertStringContainsString('/api/v1/script-execution-logs', $out);
        self::assertStringNotContainsString('<?php', $out);
        self::assertStringNotContainsString('?>', $out);
    }

    #[Test]
    public function each_render_produces_a_distinct_correlation_id(): void
    {
        $renderer = $this->app->make(WrapperScriptRenderer::class);

        $a = $renderer->wrap('echo a', ScriptExecutionAction::LOGON, ScriptExecutionOs::LINUX);
        $b = $renderer->wrap('echo b', ScriptExecutionAction::LOGON, ScriptExecutionOs::LINUX);

        // Extract correlation_id (UUID)
        preg_match("/CORR='([0-9a-f-]{36})'/", $a, $ma);
        preg_match("/CORR='([0-9a-f-]{36})'/", $b, $mb);

        self::assertNotEmpty($ma[1] ?? '');
        self::assertNotEmpty($mb[1] ?? '');
        self::assertNotSame($ma[1], $mb[1]);
    }

    #[Test]
    public function script_content_is_base64_encoded(): void
    {
        $renderer = $this->app->make(WrapperScriptRenderer::class);
        $original = "echo $(date)\nexit 7";
        $expectedB64 = base64_encode($original);

        $out = $renderer->wrap($original, ScriptExecutionAction::LOGON, ScriptExecutionOs::LINUX);

        self::assertStringContainsString($expectedB64, $out);
        // Le contenu brut ne doit pas apparaître tel quel (sinon il y aurait
        // une fuite d'échappement Blade).
        self::assertStringNotContainsString("exit 7", str_replace($expectedB64, '', $out));
    }

    #[Test]
    public function clear_cache_resets_static_state(): void
    {
        $renderer = $this->app->make(WrapperScriptRenderer::class);
        $renderer->wrap('echo x', ScriptExecutionAction::LOGON, ScriptExecutionOs::WINDOWS);

        WrapperScriptRenderer::clearCache();

        // Pas d'erreur, et nouveau rendu fonctionne :
        $out = $renderer->wrap('echo y', ScriptExecutionAction::LOGON, ScriptExecutionOs::WINDOWS);
        self::assertStringContainsString('Invoke-RestMethod', $out);
    }

    /**
     * Story 16.12 post-review Q5 — TLS strict Phase 2.
     * Le wrapper Windows ne doit plus contenir `-SkipCertificateCheck`
     * (validation CA root SambaEdu requise — fail-closed si CA absent).
     */
    #[Test]
    public function windows_wrapper_does_not_skip_certificate_check(): void
    {
        $renderer = $this->app->make(WrapperScriptRenderer::class);

        $out = $renderer->wrap(
            'echo hello',
            ScriptExecutionAction::LOGON,
            ScriptExecutionOs::WINDOWS,
        );

        self::assertStringNotContainsString('-SkipCertificateCheck', $out);
    }

    /**
     * Story 16.12 post-review Q2 (F2) — Wrapper Windows b64 splitté en chunks
     * de 4000 chars max avec `>> echo` multiples (cmd.exe limite 8191/ligne).
     *
     * Vérifie qu'un script user de 8 KB (b64 ~10.6 KB) produit :
     *   - au moins 3 lignes `>>"%B64_FILE%" echo ...`
     *   - aucune ligne du bloc rendu > 4100 chars (marge de sécurité)
     *   - les chunks concaténés (sans newlines de séparation) = b64 original
     */
    #[Test]
    public function it_handles_large_scripts_via_chunks(): void
    {
        $renderer = $this->app->make(WrapperScriptRenderer::class);

        // Script user de ~8 KB → b64 ~10.6 KB → minimum 3 chunks de 4000.
        $largeScript = str_repeat("echo line\n", 800); // ~7.8 KB
        $expectedB64 = base64_encode($largeScript);
        self::assertGreaterThan(8000, strlen($expectedB64));

        $out = $renderer->wrap(
            $largeScript,
            ScriptExecutionAction::LOGON,
            ScriptExecutionOs::WINDOWS,
        );

        // 1. Au moins 3 lignes `>>"%B64_FILE%" echo ...` (3 chunks de 4000 chars
        //    pour b64 ~10.6 KB → ceil(10600/4000) = 3).
        $count = preg_match_all('/>>"%B64_FILE%" echo /', $out, $matches);
        self::assertGreaterThanOrEqual(3, $count, sprintf(
            'Attendu au moins 3 lignes `>> echo` pour un b64 de %d chars, trouvé %d.',
            strlen($expectedB64),
            $count
        ));

        // 2. Aucune ligne du wrapper > 4100 chars (marge pour le préfixe `>>"%B64_FILE%" echo `).
        foreach (preg_split("/\r?\n/", $out) as $line) {
            self::assertLessThanOrEqual(
                4100,
                strlen($line),
                'Ligne wrapper trop longue (> 4100 chars) → cmd.exe va tronquer à 8191.',
            );
        }

        // 3. Les chunks concaténés reproduisent le b64 original (donc le decode
        //    serveur côté `certutil -decode` reconstruit le script user exact).
        preg_match_all('/>>"%B64_FILE%" echo (\S+)/', $out, $chunkMatches);
        $reconstructed = implode('', $chunkMatches[1]);
        self::assertSame($expectedB64, $reconstructed);
        self::assertSame($largeScript, base64_decode($reconstructed, true));
    }
}
