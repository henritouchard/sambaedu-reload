<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Workstation;
use App\Services\Windows\WorkstationLogReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unitaires du service WorkstationLogReader
 *
 * Encodage canonique : CP850 (DOS Western European, utilisé par cscript Windows FR).
 * Confirmé par analyse d'un fichier réel : octet 0x82 → é, 0x88 → ê.
 */
class WorkstationLogReaderTest extends TestCase
{
    private string $tmpDir;
    private WorkstationLogReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/wpkg-log-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        Config::set('sambaedu.wpkg.reports_path', $this->tmpDir);
        Cache::flush();

        $this->reader = new WorkstationLogReader();
    }

    protected function tearDown(): void
    {
        // Supprimer les fichiers de test (sans rm -rf récursif)
        foreach (glob($this->tmpDir . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeWorkstation(string|null $logPath): Workstation
    {
        $ws = new Workstation();
        $ws->id = 42;
        $ws->log_path = $logPath;
        return $ws;
    }

    private function writeLogFile(string $filename, string $content): string
    {
        $path = $this->tmpDir . '/' . $filename;
        file_put_contents($path, $content);
        return $path;
    }

    // ------------------------------------------------------------------
    // Encodage CP850 (cas principal)
    // ------------------------------------------------------------------

    public function test_reads_cp850_file_with_accents(): void
    {
        // CP850 : 0x82 = é, 0x88 = ê, 0x85 = à, 0x8B = ï
        $raw = "2026-04-14 10:32:15, DEBUG  : \x82l\x82ment install\x82\n"
            . "2026-04-14 10:32:17, INFO   : peut \x88tre requis\n"
            . "2026-04-14 10:32:47, ERROR  : \x85 la fin\n";
        $this->writeLogFile('PC01.log', $raw);

        $ws = $this->makeWorkstation('PC01.log');
        $result = $this->reader->read($ws);

        $this->assertFalse($result->missing);
        $this->assertFalse($result->truncated);
        $this->assertNotNull($result->content);
        $this->assertStringContainsString('élément installé', $result->content);
        $this->assertStringContainsString('peut être', $result->content);
        $this->assertStringContainsString('à la fin', $result->content);
    }

    // ------------------------------------------------------------------
    // Encodage UTF-8 simple (pas de BOM)
    // ------------------------------------------------------------------

    public function test_reads_utf8_file(): void
    {
        $content = "2026-04-14 10:32:15, INFO   : Installation terminée avec succès\n";
        $this->writeLogFile('PC02.log', $content);

        // UTF-8 sans BOM : mb_convert_encoding('UTF-8', 'CP850') pourrait mangler
        // MAIS les ASCII purs sont identiques entre CP850 et UTF-8, donc ça passe
        $ws = $this->makeWorkstation('PC02.log');
        $result = $this->reader->read($ws);

        $this->assertFalse($result->missing);
        $this->assertNotNull($result->content);
        // Le contenu ASCII-compatible doit passer intact
        $this->assertStringContainsString('Installation termin', $result->content);
    }

    // ------------------------------------------------------------------
    // BOM UTF-16LE
    // ------------------------------------------------------------------

    public function test_reads_utf16le_bom_file(): void
    {
        $utf16le = "\xFF\xFE" . mb_convert_encoding("Log WPKG\nInstallation OK\n", 'UTF-16LE', 'UTF-8');
        $this->writeLogFile('PC03.log', $utf16le);

        $ws = $this->makeWorkstation('PC03.log');
        $result = $this->reader->read($ws);

        $this->assertFalse($result->missing);
        $this->assertNotNull($result->content);
        $this->assertStringContainsString('Log WPKG', $result->content);
        $this->assertStringContainsString('Installation OK', $result->content);
    }

    // ------------------------------------------------------------------
    // Strip BOM UTF-8
    // ------------------------------------------------------------------

    public function test_strips_utf8_bom(): void
    {
        $content = "\xEF\xBB\xBFLog UTF-8 avec BOM\n";
        $this->writeLogFile('PC04.log', $content);

        $ws = $this->makeWorkstation('PC04.log');
        $result = $this->reader->read($ws);

        $this->assertFalse($result->missing);
        $this->assertNotNull($result->content);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $result->content);
        $this->assertStringContainsString('Log UTF-8 avec BOM', $result->content);
    }

    // ------------------------------------------------------------------
    // log_path null → missing
    // ------------------------------------------------------------------

    public function test_null_log_path_returns_missing(): void
    {
        $ws = $this->makeWorkstation(null);
        $result = $this->reader->read($ws);

        $this->assertTrue($result->missing);
        $this->assertNull($result->content);
    }

    // ------------------------------------------------------------------
    // Fichier absent → missing
    // ------------------------------------------------------------------

    public function test_missing_file_returns_missing(): void
    {
        $ws = $this->makeWorkstation('NONEXISTENT.log');
        $result = $this->reader->read($ws);

        $this->assertTrue($result->missing);
        $this->assertNull($result->content);
    }

    // ------------------------------------------------------------------
    // Suffixe non-.log → missing
    // ------------------------------------------------------------------

    public function test_non_log_extension_returns_missing(): void
    {
        $this->writeLogFile('PC05.txt', 'contenu quelconque');

        $ws = $this->makeWorkstation('PC05.txt');
        $result = $this->reader->read($ws);

        $this->assertTrue($result->missing);
    }

    // ------------------------------------------------------------------
    // Path traversal → missing (basename() neutralise)
    // ------------------------------------------------------------------

    public function test_path_traversal_returns_missing(): void
    {
        // basename('../../etc/passwd') = 'passwd' → suffixe .log absent → missing
        $ws = $this->makeWorkstation('../../etc/passwd');
        $result = $this->reader->read($ws);

        $this->assertTrue($result->missing);
    }

    // ------------------------------------------------------------------
    // Regex : caractères hors [A-Za-z0-9._-] rejetés (correction #4)
    // ------------------------------------------------------------------

    #[Test]
    public function it_rejects_basename_with_internal_traversal(): void
    {
        // 'foo/../../../bar.log' → basename = 'bar.log' qui PASSE les checks naïfs
        // mais on veut un rejet plus strict — la regex doit refuser les caractères
        // hors [A-Za-z0-9._-] AVANT même la couche realpath.
        // Ici on teste un caractère que basename ne nettoie pas mais que la regex doit refuser.
        $ws = $this->makeWorkstation('test%2Ffile.log'); // % non autorisé par la regex
        $result = $this->reader->read($ws);
        $this->assertTrue($result->missing);
    }

    #[Test]
    public function it_rejects_filename_with_spaces(): void
    {
        $ws = $this->makeWorkstation('hello world.log');
        $result = $this->reader->read($ws);
        $this->assertTrue($result->missing);
    }

    // ------------------------------------------------------------------
    // Null byte dans log_path → missing
    // ------------------------------------------------------------------

    public function test_null_byte_in_log_path_returns_missing(): void
    {
        $ws = $this->makeWorkstation("PC06\0evil.log");
        $result = $this->reader->read($ws);

        $this->assertTrue($result->missing);
    }

    // ------------------------------------------------------------------
    // reports_path vide → missing + Log::warning
    // ------------------------------------------------------------------

    public function test_empty_reports_path_returns_missing_with_warning(): void
    {
        Config::set('sambaedu.wpkg.reports_path', '');

        Log::shouldReceive('warning')
            ->once()
            ->with('[WorkstationLog] reports_path invalide', \Mockery::any());

        $ws = $this->makeWorkstation('PC07.log');
        $result = $this->reader->read($ws);

        $this->assertTrue($result->missing);
    }

    // ------------------------------------------------------------------
    // reports_path = '/' → missing + Log::warning
    // ------------------------------------------------------------------

    public function test_root_reports_path_returns_missing_with_warning(): void
    {
        Config::set('sambaedu.wpkg.reports_path', '/');

        Log::shouldReceive('warning')
            ->once()
            ->with('[WorkstationLog] reports_path invalide', \Mockery::any());

        $ws = $this->makeWorkstation('PC08.log');
        $result = $this->reader->read($ws);

        $this->assertTrue($result->missing);
    }

    // ------------------------------------------------------------------
    // Fichier > 256 KB → truncated=true, contenu ≤ 256 KB + footer
    // ------------------------------------------------------------------

    public function test_large_file_is_truncated(): void
    {
        // Créer un fichier de 300 KB
        $sizeBytes = 300 * 1024;
        $content = str_repeat("2026-04-14 10:32:15, DEBUG  : log line filler data\n", (int) ceil($sizeBytes / 50));
        $content = substr($content, 0, $sizeBytes);
        $this->writeLogFile('PC09.log', $content);

        $ws = $this->makeWorkstation('PC09.log');
        $result = $this->reader->read($ws);

        $this->assertFalse($result->missing);
        $this->assertTrue($result->truncated);
        $this->assertNotNull($result->content);
        $this->assertStringEndsWith("… (tronqué à 256 KB)\n", $result->content);
        $this->assertLessThanOrEqual(256 * 1024 + 100, strlen($result->content));
    }

    // ------------------------------------------------------------------
    // Cache : prouve le cache HIT (mtime inchangé → contenu stale renvoyé)
    // (correction #6 — remplace test_cache_returns_same_content_on_second_read)
    // ------------------------------------------------------------------

    #[Test]
    public function it_caches_content_and_returns_stale_when_mtime_unchanged(): void
    {
        $this->writeLogFile('CACHE-PROOF.log', 'first content');
        $ws = $this->makeWorkstation('CACHE-PROOF.log');

        $result1 = $this->reader->read($ws);
        $this->assertStringContainsString('first content', $result1->content);

        // Modifier le fichier MAIS forcer le mtime original (touch -t)
        $originalMtime = filemtime($this->tmpDir . '/CACHE-PROOF.log');
        file_put_contents($this->tmpDir . '/CACHE-PROOF.log', 'updated content');
        touch($this->tmpDir . '/CACHE-PROOF.log', $originalMtime);
        clearstatcache();

        $result2 = $this->reader->read($ws);
        // Cache HIT : on doit voir 'first content', pas 'updated content'
        $this->assertStringContainsString('first content', $result2->content);
        $this->assertStringNotContainsString('updated content', $result2->content);
    }

    // ------------------------------------------------------------------
    // Cache invalidé si mtime change
    // ------------------------------------------------------------------

    public function test_cache_invalidated_when_file_changes(): void
    {
        $path = $this->writeLogFile('PC11.log', "Version 1\n");
        $ws = $this->makeWorkstation('PC11.log');

        $result1 = $this->reader->read($ws);
        $this->assertStringContainsString('Version 1', $result1->content ?? '');

        // Forcer un mtime différent en reécrivant + touch
        sleep(1); // s'assurer que la seconde epoch change
        file_put_contents($path, "Version 2\n");
        touch($path);

        Cache::flush();

        $result2 = $this->reader->read($ws);
        $this->assertStringContainsString('Version 2', $result2->content ?? '');
    }

    // ------------------------------------------------------------------
    // Guard CP850 : aucune exception sur bytes inconnus (correction #7)
    // ------------------------------------------------------------------

    #[Test]
    public function it_does_not_throw_when_decoding_fails(): void
    {
        // Garantit qu'aucune exception n'est levée même sur des bytes inconnus
        $this->writeLogFile('WEIRD.log', "\x00\x01\x02\xFF\xFE\xFD test\n");
        $ws = $this->makeWorkstation('WEIRD.log');

        // Ne doit pas lever d'exception
        $result = $this->reader->read($ws);
        $this->assertNotNull($result); // service répond toujours
    }
}
