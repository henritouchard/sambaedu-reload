<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipxe;

use App\Http\Controllers\Controller;
use App\Ipxe\Iso\Services\WindowsIsoUrlValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Dépôt manuel d'une ISO Windows par chunks (page admin
 * `/admin/ipxe/iso-windows`).
 *
 * Pourquoi du chunké et pas un upload Livewire classique : une ISO Win10/Win11
 * pèse 4 à 8 Go. Un POST multipart unique de cette taille exigerait
 * `upload_max_filesize`/`post_max_size` > 8G, tiendrait une connexion ouverte
 * de longues minutes sans reprise possible, et passerait par le temp Livewire.
 * Ici chaque chunk est un petit POST `application/octet-stream` (raw body, donc
 * borné par `post_max_size` seul, pas `upload_max_filesize`) que le serveur
 * réassemble en append dans un `.part`. Avantages : plafonds PHP modestes,
 * reprise après coupure (le client reprend au chunk `received`), 1× l'espace
 * disque (append puis rename atomique au finalize côté orchestrator).
 *
 * Sécurité :
 *  - Route sous le groupe admin (`sambaedu.auth` + `sambaedu.admin`) +
 *    `can:server.admin` + CSRF (header `X-CSRF-TOKEN`).
 *  - `uploadId` strictement validé comme UUID → aucune traversée de chemin.
 *  - Nom de fichier validé (charset blanc + `.iso`) dès le 1er chunk.
 *  - Cap de taille totale (`upload_max_total_bytes`).
 *
 * Le finalize (validation finale + rename + dispatch Job) vit dans le
 * composant Livewire qui délègue à
 * {@see \App\Ipxe\Iso\Services\WindowsIsoDownloadOrchestrator::submitUpload()}.
 */
class WindowsIsoUploadController extends Controller
{
    /** Un `uploadId` DOIT être un UUID — empêche toute traversée de chemin. */
    private const UPLOAD_ID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly WindowsIsoUrlValidator $validator,
    ) {}

    /**
     * Reçoit un chunk (raw body) et l'append au `.part` du dépôt.
     *
     * Query params : uploadId, index, total, chunkSize, filename, version.
     * Body : octets du chunk (`application/octet-stream`).
     */
    public function chunk(Request $request): JsonResponse
    {
        if (! (bool) config('ipxe.iso_management.upload_enabled', true)
            || ! (bool) config('ipxe.iso_management.enabled', true)) {
            return $this->err('Le dépôt manuel d\'ISO est désactivé.', 403);
        }

        $uploadId   = (string) $request->query('uploadId', '');
        $index      = (int) $request->query('index', -1);
        $total      = (int) $request->query('total', 0);
        $chunkSize  = (int) $request->query('chunkSize', 0);
        $filename   = (string) $request->query('filename', '');
        $version    = (string) $request->query('version', '');

        if (preg_match(self::UPLOAD_ID_REGEX, $uploadId) !== 1) {
            return $this->err('Identifiant de dépôt invalide.', 422);
        }
        if ($index < 0 || $total < 1 || $index >= $total) {
            return $this->err('Index de chunk invalide.', 422);
        }
        if ($chunkSize < 1 || $chunkSize > 100 * 1024 * 1024) {
            return $this->err('Taille de chunk invalide.', 422);
        }

        // Valide nom + version dès le 1er chunk (échoue tôt, avant de
        // téléverser plusieurs Go pour rien).
        if ($index === 0) {
            try {
                $this->validator->validateUploadFilename($filename, $version);
            } catch (\Throwable $e) {
                return $this->err($e->getMessage(), 422);
            }
        }

        $dir = $this->ensureTmpDir();
        if ($dir === null) {
            return $this->err('Dossier de dépôt inaccessible (droits filesystem ?).', 500);
        }

        // Purge best-effort des dépôts partiels abandonnés au démarrage.
        if ($index === 0) {
            $this->purgeStale($dir);
        }

        $partPath = $dir . '/' . $uploadId . '.part';
        $metaPath = $dir . '/' . $uploadId . '.json';

        $meta = $this->readMeta($metaPath);
        if ($index === 0 && $meta === null) {
            $meta = [
                'filename'   => $filename,
                'version'    => $version,
                'chunkSize'  => $chunkSize,
                'totalChunks' => $total,
                'received'   => 0,
            ];
        }
        if ($meta === null) {
            return $this->err('Dépôt inconnu — recommencez depuis le début.', 409, 0);
        }

        $received = (int) ($meta['received'] ?? 0);
        $metaChunkSize = (int) ($meta['chunkSize'] ?? $chunkSize);

        $body = $request->getContent();
        $bodyLen = strlen($body);
        if ($bodyLen < 1) {
            return $this->err('Chunk vide.', 422, $received);
        }
        // Un chunk ne peut dépasser la taille déclarée (le dernier peut être ≤).
        if ($bodyLen > $metaChunkSize) {
            return $this->err('Chunk trop volumineux.', 422, $received);
        }

        // Cap taille totale.
        $maxTotal = (int) config('ipxe.iso_management.upload_max_total_bytes', 10 * 1024 * 1024 * 1024);
        $expectedPriorSize = $received * $metaChunkSize;
        if (($expectedPriorSize + $bodyLen) > $maxTotal) {
            @unlink($partPath);
            @unlink($metaPath);
            $maxGo = round($maxTotal / (1024 * 1024 * 1024), 1);
            return $this->err("Fichier trop volumineux (limite {$maxGo} Go).", 422);
        }

        // Idempotence : un chunk déjà appliqué (retry après réponse perdue) est
        // un no-op succès.
        if ($index < $received) {
            return $this->ok($received, $received >= (int) $meta['totalChunks']);
        }
        // Gap : le client doit reprendre à `received`.
        if ($index !== $received) {
            return $this->err('Chunk hors séquence — reprenez au chunk indiqué.', 409, $received);
        }

        // Self-heal : si un append précédent du MÊME index a partiellement
        // écrit (crash entre write et maj meta), on tronque au dernier état
        // cohérent avant de ré-appender.
        clearstatcache(true, $partPath);
        $currentSize = is_file($partPath) ? (int) filesize($partPath) : 0;
        if ($currentSize > $expectedPriorSize) {
            $fh = @fopen($partPath, 'r+');
            if ($fh !== false) {
                @ftruncate($fh, $expectedPriorSize);
                @fclose($fh);
            }
        }

        $written = @file_put_contents($partPath, $body, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            return $this->err('Écriture du chunk échouée (espace disque / droits ?).', 500, $received);
        }

        $meta['received'] = $received + 1;
        $this->writeMeta($metaPath, $meta);

        $complete = $meta['received'] >= (int) $meta['totalChunks'];

        return $this->ok((int) $meta['received'], $complete);
    }

    // ---- Helpers -----------------------------------------------------------

    private function ensureTmpDir(): ?string
    {
        $dir = rtrim((string) config('ipxe.iso_management.upload_tmp_path', storage_path('install/iso/.uploads')), '/');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    /** @return array<string, mixed>|null */
    private function readMeta(string $metaPath): ?array
    {
        if (! is_file($metaPath)) {
            return null;
        }
        $raw = @file_get_contents($metaPath);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $meta */
    private function writeMeta(string $metaPath, array $meta): void
    {
        @file_put_contents($metaPath, json_encode($meta), LOCK_EX);
    }

    /**
     * Purge les `.part`/`.json` plus vieux que `upload_stale_ttl` — évite
     * l'accumulation d'uploads abandonnés (coupure réseau, onglet fermé).
     */
    private function purgeStale(string $dir): void
    {
        $ttl = (int) config('ipxe.iso_management.upload_stale_ttl', 86400);
        $now = time();
        foreach (glob($dir . '/*.{part,json}', GLOB_BRACE) ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && ($now - $mtime) > $ttl) {
                @unlink($file);
            }
        }
    }

    private function ok(int $received, bool $complete): JsonResponse
    {
        return response()->json(['ok' => true, 'received' => $received, 'complete' => $complete]);
    }

    private function err(string $message, int $status, ?int $received = null): JsonResponse
    {
        if ($status >= 500) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->warning('ipxe.iso.upload.chunk_error', [
                'message' => $message,
                'status'  => $status,
            ]);
        }
        $payload = ['ok' => false, 'error' => $message];
        if ($received !== null) {
            $payload['received'] = $received;
        }

        return response()->json($payload, $status);
    }
}
