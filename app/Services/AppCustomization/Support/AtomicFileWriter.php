<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\Support;

use Illuminate\Support\Facades\Log;

/**
 * Écriture atomique `tmp + rename` d'un fichier partagé.
 *
 * Factorisation story 4.8 (AC 5, 6, 13). Pattern issu de la mémoire
 * `feedback_atomic_write.md` : les clients legacy lisent les fichiers
 * `/etc/sambaedu/applications/*.json` en concurrence, on veut éviter toute
 * lecture partielle (ftruncate → write → fsync → close intermédiaire).
 *
 * Le tmp est placé dans le même dossier que la cible (sinon `rename()` across
 * filesystems n'est plus atomique — man rename(2)).
 */
final class AtomicFileWriter
{
    /**
     * Écrit `$contents` dans `$targetPath` de façon atomique.
     * Crée le dossier parent si absent.
     *
     * @return bool  true si succès.
     */
    public static function write(string $targetPath, string $contents, int $mode = 0644): bool
    {
        $dir = dirname($targetPath);

        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                Log::error('[AtomicFileWriter] cannot create directory', ['dir' => $dir]);
                return false;
            }
        }

        $tmp = $dir . '/.' . basename($targetPath) . '.tmp-' . bin2hex(random_bytes(6));

        try {
            $bytes = @file_put_contents($tmp, $contents);
            if ($bytes === false) {
                @unlink($tmp);
                Log::error('[AtomicFileWriter] file_put_contents failed', ['tmp' => $tmp]);
                return false;
            }
            @chmod($tmp, $mode);

            if (! @rename($tmp, $targetPath)) {
                @unlink($tmp);
                Log::error('[AtomicFileWriter] rename failed', [
                    'tmp' => $tmp,
                    'target' => $targetPath,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            @unlink($tmp);
            Log::error('[AtomicFileWriter] exception', [
                'target' => $targetPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
