<?php

declare(strict_types=1);

namespace App\Services\Windows;

use App\Models\Workstation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

readonly class WorkstationLogReadResult
{
    public function __construct(
        public ?string $content,
        public bool $missing,
        public bool $truncated,
        public ?string $filename = null,
    ) {}
}

class WorkstationLogReader
{
    private const MAX_BYTES = 256 * 1024;                         // 256 KB
    private const TRUNCATED_FOOTER = "\n… (tronqué à 256 KB)\n";
    private const CACHE_TTL = 60;                                 // secondes

    public function read(Workstation $ws): WorkstationLogReadResult
    {
        if ($ws->log_path === null || $ws->log_path === '') {
            return new WorkstationLogReadResult(null, true, false);
        }

        // 1. basename() neutralise tout préfixe ../
        $filename = basename($ws->log_path);

        // 2. Validation regex stricte : alphanum + . _ - uniquement, suffixe .log obligatoire
        //    Remplace le str_ends_with() qui devient redondant.
        if (!preg_match('/^[A-Za-z0-9._-]+\.log$/i', $filename)) {
            return new WorkstationLogReadResult(null, true, false);
        }

        // 3. Null byte interdit
        if (str_contains($filename, "\0")) {
            return new WorkstationLogReadResult(null, true, false);
        }

        // 4. Base dir : refus explicite des valeurs dégénérées
        $baseDir = rtrim((string) config('sambaedu.wpkg.reports_inbox', ''), '/');
        if ($baseDir === '' || $baseDir === '/' || !is_dir($baseDir)) {
            Log::warning('[WorkstationLog] reports_inbox invalide', [
                'workstation_id' => $ws->id,
                'base_dir'       => $baseDir,
            ]);
            return new WorkstationLogReadResult(null, true, false, $filename);
        }

        $resolvedPath = $baseDir . '/' . $filename;
        if (!file_exists($resolvedPath)) {
            return new WorkstationLogReadResult(null, true, false, $filename);
        }

        // 5. realpath des deux côtés + containment (anti path traversal + symlinks)
        $realBase = realpath($baseDir);
        $realResolved = realpath($resolvedPath);
        if ($realBase === false || $realResolved === false) {
            return new WorkstationLogReadResult(null, true, false, $filename);
        }
        if (!str_starts_with($realResolved, $realBase . DIRECTORY_SEPARATOR)) {
            Log::warning('[WorkstationLog] Path traversal détecté', [
                'workstation_id' => $ws->id,
                'log_path'       => $ws->log_path,
            ]);
            return new WorkstationLogReadResult(null, true, false);
        }

        // 6. Cache : clé dépend du mtime → invalidation automatique si le fichier change
        //    Ne cache pas les null (évite 60s d'unavailability si le fichier redevient lisible)
        $mtime = @filemtime($realResolved) ?: 0;
        $cacheKey = "wpkg-log:{$ws->id}:{$filename}:{$mtime}";

        /** @var array{content: string|null, truncated: bool, filename: string}|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached === null) {
            $cached = $this->loadFromDisk($realResolved, $filename);
            if ($cached !== null && $cached['content'] !== null) {
                Cache::put($cacheKey, $cached, self::CACHE_TTL);
            }
        }

        if ($cached === null || $cached['content'] === null) {
            return new WorkstationLogReadResult(null, true, false, $filename);
        }

        return new WorkstationLogReadResult(
            content: $cached['content'],
            missing: false,
            truncated: $cached['truncated'],
            filename: $cached['filename'] ?? $filename,
        );
    }

    /**
     * Lit le fichier depuis le disque et retourne un tableau structuré, ou null en cas d'échec.
     *
     * @return array{content: string|null, truncated: bool, filename: string}|null
     */
    private function loadFromDisk(string $realResolved, string $filename): ?array
    {
        $handle = fopen($realResolved, 'rb');
        if ($handle === false) {
            return null;
        }

        $stat = fstat($handle);
        $filesize = ($stat !== false && isset($stat['size'])) ? (int) $stat['size'] : null;

        $raw = stream_get_contents($handle, self::MAX_BYTES);
        fclose($handle);

        if ($raw === false) {
            return null;
        }

        $truncated = ($filesize !== null && $filesize > self::MAX_BYTES);
        $content = $this->convertToUtf8($raw);
        if ($truncated) {
            $content .= self::TRUNCATED_FOOTER;
        }

        return ['content' => $content, 'truncated' => $truncated, 'filename' => $filename];
    }

    /**
     * Convertit un contenu binaire en UTF-8.
     *
     * Encodage canonique des logs WPKG : CP850 (DOS Western European, page de codes par défaut
     * de cscript/cmd sur Windows FR). Confirmé par analyse d'un échantillon réel
     * (octets 0x82 → 'é', 0x88 → 'ê').
     *
     * Si certains scripts émettent autre chose (UTF-8/UTF-16 avec BOM), on les détecte avant
     * de retomber sur le fallback CP850.
     */
    private function convertToUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xFF\xFE")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }

        // Cas par défaut : CP850 (encodage canonique Windows FR cscript)
        // Guard : certaines builds mbstring (Alpine slim, distroless) n'ont pas CP850.
        // Fallback Windows-1252 qui partage les positions latines avec CP850 pour ASCII étendu.
        static $cp850Available = null;
        if ($cp850Available === null) {
            $cp850Available = in_array('CP850', mb_list_encodings(), true);
        }

        if ($cp850Available) {
            return mb_convert_encoding($raw, 'UTF-8', 'CP850');
        }

        // Fallback : Windows-1252 (best-effort)
        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
}
