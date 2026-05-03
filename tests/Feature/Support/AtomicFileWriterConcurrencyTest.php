<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\AtomicFileWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test feature concurrent AtomicFileWriter (Story 15.1 / AC5.1).
 *
 * Vérifie que des lecteurs concurrents ne capturent jamais un état partiel :
 * un producer écrit en boucle un payload `<MARKER>:<payload-md5>`, un reader
 * fork lit en boucle. L'invariant : si le fichier existe, son md5 doit
 * matcher le marker contenu (== contenu complet jamais tronqué).
 *
 * Si `pcntl_fork` n'est pas disponible (rare en CLI mais possible sur Windows
 * ou container minimal), le test est skippé avec un message explicite.
 */
class AtomicFileWriterConcurrencyTest extends TestCase
{
    private string $tmpDir;
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/atomic-concurrency-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->target = $this->tmpDir . '/payload.txt';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            $b = basename($f);
            if ($b !== '.' && $b !== '..' && is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function readers_never_observe_a_partial_or_truncated_file(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork indisponible sur cet environnement.');
        }

        $deadline = microtime(true) + 2.5;
        $expectedSize = 64 * 1024;

        $producerPid = pcntl_fork();
        if ($producerPid === -1) {
            $this->fail('pcntl_fork producer failed');
        }

        if ($producerPid === 0) {
            // Producer
            $i = 0;
            while (microtime(true) < $deadline) {
                $payload = str_repeat('A' . ($i % 10), $expectedSize / 2);
                $hash = md5($payload);
                $blob = $hash . ':' . $payload;
                AtomicFileWriter::write($this->target, $blob);
                ++$i;
                usleep(50);
            }
            exit(0);
        }

        $readerPid = pcntl_fork();
        if ($readerPid === -1) {
            $this->fail('pcntl_fork reader failed');
        }

        if ($readerPid === 0) {
            // Reader : on écrit le statut dans un fichier sentinel, puis on
            // sort avec un code de retour clair.
            $sentinel = $this->tmpDir . '/reader-status.txt';
            $partials = 0;
            $reads = 0;
            while (microtime(true) < $deadline) {
                if (! file_exists($this->target)) {
                    usleep(20);
                    continue;
                }
                $blob = @file_get_contents($this->target);
                if ($blob === false || $blob === '') {
                    usleep(20);
                    continue;
                }
                ++$reads;
                $sep = strpos($blob, ':');
                if ($sep === false || $sep !== 32) {
                    ++$partials;
                    continue;
                }
                $hash = substr($blob, 0, 32);
                $payload = substr($blob, 33);
                if (md5($payload) !== $hash) {
                    ++$partials;
                }
                usleep(20);
            }
            file_put_contents($sentinel, "{$reads}:{$partials}");
            exit($partials === 0 ? 0 : 1);
        }

        // Parent : attend les deux enfants.
        pcntl_waitpid($producerPid, $producerStatus);
        pcntl_waitpid($readerPid, $readerStatus);

        $sentinel = $this->tmpDir . '/reader-status.txt';
        $stats = file_exists($sentinel) ? file_get_contents($sentinel) : 'unavailable';
        [$reads, $partials] = file_exists($sentinel)
            ? array_map('intval', explode(':', $stats))
            : [0, -1];

        $this->assertGreaterThan(
            0,
            $reads,
            'Le reader devrait avoir lu au moins une fois le fichier (writer trop lent ?).'
        );
        $this->assertSame(
            0,
            $partials,
            "Reader a observé {$partials} lecture(s) partielle(s)/incohérente(s) sur {$reads} (status reader exit={$readerStatus})."
        );
    }
}
