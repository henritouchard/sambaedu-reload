<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Écriture atomique `tmp + rename` d'un fichier partagé.
 *
 * Story 15.1 (consolidation Story 4.8). Pattern issu de `feedback_atomic_write`
 * — les clients legacy / Windows lisent les fichiers de policies et de
 * déploiement (firefox/thunderbird policies, hosts.xml, profiles.xml, .ini)
 * en concurrence ; on veut éviter toute lecture partielle.
 *
 * Garanties :
 *   - tmp dans le **même dossier** que la cible (sinon `rename(2)` cross-FS
 *     n'est plus atomique) ;
 *   - suffixe `pid` + bytes aléatoires : évite les collisions multi-process
 *     (FPM workers) ET intra-process (plusieurs writes concurrents) ;
 *   - `fsync()` sur le descripteur tmp avant rename : garantit que les blocs
 *     sont sur disque avant que `rename(2)` rende le fichier visible.
 */
final class AtomicFileWriter
{
    /**
     * Écrit `$contents` dans `$targetPath` de façon atomique.
     * Crée le dossier parent si absent.
     *
     * @return bool true si succès.
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

        $tmp = $dir
            . '/.' . basename($targetPath)
            . '.tmp.' . getmypid()
            . '.' . bin2hex(random_bytes(6));

        $fh = null;
        try {
            $fh = @fopen($tmp, 'wb');
            if ($fh === false) {
                Log::error('[AtomicFileWriter] fopen failed', ['tmp' => $tmp]);
                return false;
            }

            $written = @fwrite($fh, $contents);
            if ($written === false || $written !== strlen($contents)) {
                @fclose($fh);
                @unlink($tmp);
                Log::error('[AtomicFileWriter] fwrite failed', [
                    'tmp' => $tmp,
                    'expected' => strlen($contents),
                    'written' => $written === false ? 'false' : (string) $written,
                ]);
                return false;
            }

            // fsync force la persistance disque avant que rename rende le
            // fichier visible — sans ça, un crash kernel post-rename peut
            // exposer un fichier vide aux lecteurs.
            // @fsync est volontairement silencieux : certains FS (tmpfs,
            // overlayfs CI) ne le supportent pas, ce n'est pas une erreur
            // bloquante.
            if (function_exists('fsync')) {
                @fsync($fh);
            }

            @fclose($fh);
            $fh = null;

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
            if (is_resource($fh)) {
                @fclose($fh);
            }
            @unlink($tmp);
            Log::error('[AtomicFileWriter] exception', [
                'target' => $targetPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
