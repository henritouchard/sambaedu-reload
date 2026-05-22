<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Gpo\Support\GpoLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Commande artisan `gpo:applications:audit` — Story 17.3.
 *
 * Audit lecture pure du template GPO `se4_applications` livré par le paquet
 * Debian `sambaedu-gpo` (path par défaut `/usr/share/sambaedu/gpo/se4_applications.zip`,
 * overridable via `GPO_APPLICATIONS_TEMPLATE_PATH` env / config
 * `sambaedu.gpo.applications_template.path` / option `--path=`).
 *
 * Mode de fonctionnement :
 *  - **Scan ZIP** (production) si le path est un fichier `.zip` (ext-zip requise).
 *  - **Scan répertoire** (mode dégradé testing + VM dev sambaedu-gpo packagé)
 *    si le path est un répertoire — iso pattern `WpkgGpoSynchronizer::scanDirectoryPlaceholders`
 *    Story 16.6 D6.
 *
 * Pour chaque fichier `.cmd|.bat|.ini|.xml|.reg|.inf|.adm|.admx|.adml|.txt|.ps1|.vbs` :
 *  - Détection encodage UTF-16LE + conversion (iso 16.6).
 *  - Extraction des URLs HTTP (regex `https?://[^"\s)]+`).
 *  - Extraction des placeholders `###_KEY_###` (regex `###_([A-Z][A-Z0-9_]*)_###`).
 *  - Classification `legacy_match` (true si une URL contient `gpo/applications.php`).
 *  - Recommandation (`substitute_post_extraction`, `ok`).
 *
 * Garde-fous (parité 16.6 review fix #C) :
 *  - `MAX_ZIP_FILES = 1000` — refus d'un ZIP anormalement gros (zip bomb).
 *  - `MAX_ZIP_ENTRY_BYTES = 10 * 1024 * 1024` — cap par entrée (10 Mo).
 *
 * Comparaison whitelist : les placeholders détectés sont comparés à la
 * whitelist `config('sambaedu.gpo.applications.substitutions.whitelist')`
 * + les `SPECIALISE_CONFIG_KEYS` legacy (domain, samba_domain, se4fs_name…).
 * Les clés hors whitelist sont rapportées dans `unknown_placeholders`.
 *
 * Exit codes (parité 16.6) :
 *  - `0` : OK — aucune URL legacy détectée ET aucun placeholder inconnu.
 *  - `2` : WARNING — au moins une URL legacy détectée OU au moins un
 *    placeholder hors whitelist (non bloquant, audit-only).
 *  - `1` : ERROR — template absent / ZIP corrompu / ZipArchive::open échec.
 *
 * Note sécurité (post-review 17.3 Q2) : pas de validation `realpath` /
 * préfixe `TEMPLATE_PATH_PREFIX` sur `--path=` (vs 16.6
 * `WpkgGpoSynchronizer::TEMPLATE_PATH_PREFIX`). Justification : commande CLI
 * uniquement (pas d'exposition HTTP/UI), l'opérateur a déjà accès shell —
 * risque path traversal nul. À renforcer si la commande est ré-exposée en
 * UI HTTP plus tard (out-of-scope D8).
 */
final class AuditApplicationsGpoTemplateCommand extends Command
{
    /** Path par défaut du template (parité 16.6 D2). */
    public const DEFAULT_TEMPLATE_PATH = '/usr/share/sambaedu/gpo/se4_applications.zip';

    /** Limite garde-fou ZIP (iso 16.6 review fix #C). */
    public const MAX_ZIP_FILES = 1000;

    /** Cap par entrée ZIP (10 Mo, iso 16.6). */
    public const MAX_ZIP_ENTRY_BYTES = 10 * 1024 * 1024;

    /**
     * Extensions de fichiers susceptibles de contenir des placeholders/URLs.
     * Filtre iso 16.6 (`scanTemplatePlaceholders` ligne 407 / `scanDirectoryPlaceholders` ligne 471).
     */
    private const TEXT_EXTENSIONS = ['xml', 'cmd', 'bat', 'ini', 'reg', 'inf', 'adm', 'admx', 'adml', 'txt', 'ps1', 'vbs'];

    /**
     * Clés `SPECIALISE_CONFIG_KEYS` propagées par le shim legacy `specialise_gpo`
     * (iso `WpkgGpoSynchronizer::SPECIALISE_CONFIG_KEYS` ligne 76-85).
     * Ces clés sont toujours acceptées (placeholders legacy gérés out-of-whitelist).
     */
    private const SPECIALISE_CONFIG_KEYS = [
        'DOMAIN',
        'SAMBA_DOMAIN',
        'SE4FS_NAME',
        'SE4AD_NAME',
        'DOMAIN_SID',
        'SE4INSTALL_NAME',
        'LDAP_BASE_DN',
        'CLOUD_NAME',
    ];

    protected $signature = 'gpo:applications:audit
                            {--json : Output JSON structuré (stdout machine-readable).}
                            {--path= : Override path template (défaut config sambaedu.gpo.applications_template.path).}';

    protected $description = 'Audit lecture pure du template GPO `se4_applications` (Story 17.3) — détecte les `.cmd` orchestrateurs pointant encore vers `gpo/applications.php` legacy.';

    public function handle(): int
    {
        $asJson = (bool) $this->option('json');
        $overridePath = (string) ($this->option('path') ?? '');
        $path = $overridePath !== ''
            ? $overridePath
            : (string) config('sambaedu.gpo.applications_template.path', self::DEFAULT_TEMPLATE_PATH);

        // Post-review 17.3 #S7 — iso convention 16.6 : `GpoLogger::action` au
        // lieu de `Log::channel('gpo')->info(...)`. Émet automatiquement les
        // logs `start` / `success` / `failure` + `operation_id` propagé.
        $operationId = (string) Str::uuid();
        $log = GpoLogger::action('gpo.applications.audit', operationId: $operationId, context: [
            'template_path' => $path,
            'mode_json' => $asJson,
        ]);

        if (! is_file($path) && ! is_dir($path)) {
            $msg = sprintf(
                'Template introuvable `%s` — vérifier installation paquet sambaedu-gpo.',
                $path,
            );
            $this->emitError($msg, $asJson, ['template_path' => $path]);
            $log->failure(new RuntimeException($msg));

            return 1;
        }

        try {
            $files = $this->scanTemplate($path, $log);
        } catch (RuntimeException $e) {
            $this->emitError($e->getMessage(), $asJson, ['template_path' => $path]);
            $log->failure($e);

            return 1;
        }

        $allPlaceholders = [];
        foreach ($files as $f) {
            foreach ($f['placeholders'] as $p) {
                $allPlaceholders[$p] = true;
            }
        }
        $unknownPlaceholders = $this->diffWhitelist(array_keys($allPlaceholders));

        $legacyCount = 0;
        $okCount = 0;
        foreach ($files as &$f) {
            $f['legacy_match'] = $this->hasLegacyUrl($f['urls']);
            $f['recommendation'] = $this->classifyFile($f['urls'], $f['legacy_match']);
            if ($f['legacy_match']) {
                $legacyCount++;
            } else {
                $okCount++;
            }
        }
        unset($f);

        $summary = [
            'total_files' => count($files),
            'legacy_count' => $legacyCount,
            'ok_count' => $okCount,
            'unknown_placeholders_count' => count($unknownPlaceholders),
        ];

        $exitCode = ($legacyCount > 0 || $unknownPlaceholders !== []) ? 2 : 0;

        if ($asJson) {
            $this->outputJson($path, $files, $summary, $unknownPlaceholders);
        } else {
            $this->outputHuman($path, $files, $summary, $unknownPlaceholders);
        }

        $log->success([
            'severity' => $exitCode === 0 ? 'ok' : 'warning',
            'summary' => $summary,
        ]);

        return $exitCode;
    }

    /**
     * Scan le template (ZIP ou répertoire) et retourne la liste des fichiers
     * texte avec leurs URLs HTTP et placeholders détectés.
     *
     * Post-review 17.3 #S7 — `$log` propagé pour `step('warning', ...)` sur
     * troncature ZIP / décodage UTF-16 raté (iso convention 16.6).
     *
     * @return list<array{path: string, urls: list<string>, placeholders: list<string>}>
     */
    private function scanTemplate(string $path, ?\App\Gpo\Support\GpoActionLog $log = null): array
    {
        if (is_dir($path)) {
            return $this->scanDirectory($path, $log);
        }
        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException(
                'ext-zip absente — impossible de scanner un fichier .zip. Fournir un répertoire via --path ou installer ext-zip.',
            );
        }

        $files = [];
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException(sprintf('Impossible d\'ouvrir le template `%s` (ZIP corrompu ?).', $path));
        }
        try {
            if ($zip->numFiles > self::MAX_ZIP_FILES) {
                throw new RuntimeException(sprintf(
                    'Template ZIP trop volumineux (%d entrées > %d max) — refusé pour défense en profondeur.',
                    $zip->numFiles,
                    self::MAX_ZIP_FILES,
                ));
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                if (! $this->isTextFile($name)) {
                    continue;
                }
                $raw = $zip->getFromIndex($i, self::MAX_ZIP_ENTRY_BYTES);
                if (! is_string($raw) || $raw === '') {
                    continue;
                }
                if (strlen($raw) >= self::MAX_ZIP_ENTRY_BYTES) {
                    $log?->step('zip entry truncated', [
                        'path' => $name,
                        'limit' => self::MAX_ZIP_ENTRY_BYTES,
                    ], 'warning');
                    continue;
                }
                $raw = $this->decodeIfUtf16($raw, $name, $log);
                $files[] = [
                    'path' => $name,
                    'urls' => $this->extractUrls($raw),
                    'placeholders' => $this->extractPlaceholders($raw),
                ];
            }
        } finally {
            $zip->close();
        }

        return $files;
    }

    /**
     * Scan récursif d'un répertoire (mode dégradé testing + VM dev sambaedu-gpo
     * packagé en arborescence). Iso `WpkgGpoSynchronizer::scanDirectoryPlaceholders`
     * 16.6.
     *
     * @return list<array{path: string, urls: list<string>, placeholders: list<string>}>
     */
    private function scanDirectory(string $dir, ?\App\Gpo\Support\GpoActionLog $log = null): array
    {
        $files = [];
        $rootLength = strlen(rtrim($dir, '/')) + 1;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            if (! $this->isTextFile($entry->getFilename())) {
                continue;
            }
            $raw = @file_get_contents($entry->getPathname());
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $raw = $this->decodeIfUtf16($raw, $entry->getPathname(), $log);
            $relativePath = substr($entry->getPathname(), $rootLength);
            $files[] = [
                'path' => $relativePath,
                'urls' => $this->extractUrls($raw),
                'placeholders' => $this->extractPlaceholders($raw),
            ];
        }

        // Tri stable pour rendre la sortie déterministe (utile pour tests).
        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $files;
    }

    private function isTextFile(string $name): bool
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, self::TEXT_EXTENSIONS, true);
    }

    /**
     * Décode UTF-16LE → UTF-8 si BOM ou ratio NUL élevé détecté
     * (typique des fichiers `Registry.pol` et `scripts.ini` MS).
     * Iso 16.6 lignes 425-437.
     */
    private function decodeIfUtf16(string $raw, string $path, ?\App\Gpo\Support\GpoActionLog $log = null): string
    {
        if (! str_starts_with($raw, "\xFF\xFE") && ! (substr_count($raw, "\x00") > strlen($raw) / 4)) {
            return $raw;
        }
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
        $log?->step('utf16 decode failed', [
            'path' => $path,
            'length' => strlen($raw),
        ], 'warning');

        return $raw;
    }

    /**
     * @return list<string>
     */
    private function extractUrls(string $raw): array
    {
        // Post-review 17.3 #S2 — exclure aussi `'`, `<`, `>` pour éviter les
        // faux positifs dans les `.reg`/`.xml` du template GPO (capture
        // `http://example.com'>` jusqu'à l'apostrophe / chevron fermant).
        if (preg_match_all('#https?://[^"\s)\'<>]+#', $raw, $matches) === false) {
            return [];
        }
        $urls = array_values(array_unique($matches[0]));
        sort($urls);

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(string $raw): array
    {
        if (preg_match_all('/###_([A-Z][A-Z0-9_]*)_###/', $raw, $matches) === false) {
            return [];
        }
        $placeholders = array_values(array_unique($matches[1]));
        sort($placeholders);

        return $placeholders;
    }

    /**
     * @param  list<string>  $urls
     */
    private function hasLegacyUrl(array $urls): bool
    {
        foreach ($urls as $u) {
            if (str_contains($u, '/gpo/applications.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $urls
     */
    private function classifyFile(array $urls, bool $legacyMatch): string
    {
        // Post-review 17.3 #S3 — invariant : `$urls === []` implique forcément
        // `$legacyMatch === false` (cf. `extractUrls`/`hasLegacyUrl` scope).
        // Le early-return reste pour clarté sémantique (fichier sans URL = ok).
        if ($urls === []) {
            return 'ok';
        }
        if ($legacyMatch) {
            return 'substitute_post_extraction';
        }

        return 'ok';
    }

    /**
     * Compare les placeholders détectés à la whitelist `config(...)` et aux
     * clés `SPECIALISE_CONFIG_KEYS` legacy (iso 16.6 `diffWhitelist`).
     *
     * @param  list<string>  $detected
     * @return list<string>
     */
    private function diffWhitelist(array $detected): array
    {
        $whitelist = array_map(
            'strtoupper',
            array_keys((array) config('sambaedu.gpo.applications.substitutions.whitelist', [])),
        );
        foreach (self::SPECIALISE_CONFIG_KEYS as $k) {
            $whitelist[] = $k;
        }
        $whitelist = array_values(array_unique($whitelist));
        $unknown = array_values(array_filter(
            $detected,
            static fn (string $k): bool => ! in_array($k, $whitelist, true),
        ));
        sort($unknown);

        return $unknown;
    }

    /**
     * @param  list<array<string,mixed>>  $files
     * @param  list<string>  $unknownPlaceholders
     */
    private function outputJson(string $path, array $files, array $summary, array $unknownPlaceholders): void
    {
        $this->line(json_encode([
            'template_path' => $path,
            'files' => $files,
            'summary' => $summary,
            'unknown_placeholders' => $unknownPlaceholders,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  list<array<string,mixed>>  $files
     * @param  list<string>  $unknownPlaceholders
     */
    private function outputHuman(string $path, array $files, array $summary, array $unknownPlaceholders): void
    {
        $this->info('--- gpo:applications:audit ---');
        $this->line('Template path : ' . $path);
        $this->line('Total fichiers : ' . $summary['total_files']);
        $this->line('Legacy match   : ' . $summary['legacy_count']);
        $this->line('OK             : ' . $summary['ok_count']);
        $this->line('Placeholders inconnus : ' . $summary['unknown_placeholders_count']);
        $this->newLine();

        $rows = [];
        foreach ($files as $f) {
            $rows[] = [
                $f['path'],
                $this->truncate(implode(', ', $f['urls']), 60),
                $f['legacy_match'] ? 'true' : 'false',
                $f['recommendation'],
                $this->truncate(implode(', ', $f['placeholders']), 40),
            ];
        }
        $this->table(
            ['Fichier', 'URLs', 'legacy_match', 'recommendation', 'Placeholders'],
            $rows,
        );

        if ($unknownPlaceholders !== []) {
            $this->newLine();
            $this->warn('Placeholders hors whitelist (à étudier — risque parc-wide non substitué) :');
            foreach ($unknownPlaceholders as $p) {
                $this->line('  - ###_' . $p . '_###');
            }
        }
    }

    private function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max - 3) . '...';
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function emitError(string $msg, bool $asJson, array $context = []): void
    {
        if ($asJson) {
            $this->line(json_encode(
                array_merge(['error' => $msg], $context),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->error($msg);
        }
    }
}
