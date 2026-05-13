<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use Illuminate\Support\Facades\Log;

/**
 * Scanner filesystem des templates de scripts applications.
 *
 * Story 16.7 — port natif de `read_application_scripts()`
 * (`sambaedu/includes/applications.inc.php:39-189`).
 *
 * **Scan iso-legacy** :
 *  1. `/usr/share/sambaedu/applications/<app>/` (distribution, lu en premier)
 *  2. `/etc/sambaedu/applications/<app>/`       (local, surcharge si même clé)
 *
 * **Types de fichiers reconnus** :
 *  - `scripts.json` — index JSON avec entrées `file`/`os`/`action`/`context`/…
 *  - `redirects.json` — index JSON pour les redirections de profil (logon)
 *  - `<action>[-<context>][@<group>].<windows|linux>` — script direct
 *    avec includes/excludes par groupe (legacy nommage)
 *  - `packages[@<group>].list` — liste apt (linux startup)
 *
 * **Sécurité (AC6.2)** : paths hardcodés, validation `realpath()` contre les
 * symlinks malveillants, fichiers en dehors des préfixes autorisés ignorés.
 *
 * @legacy-port path="sambaedu/includes/applications.inc.php:39-189 (read_application_scripts)"
 * @see WinePrefixScanner Pattern similaire scan FS (Story 16.3c).
 */
final class ApplicationTemplatesScanner
{
    /** Chemin distribution (lu en premier — surchargé par /etc/). */
    public const DEFAULT_PACKAGE_PATH = '/usr/share/sambaedu/applications/';

    /** Chemin local (surcharge package). */
    public const DEFAULT_LOCAL_PATH = '/etc/sambaedu/applications/';

    /**
     * Liste les scripts disponibles pour un contexte runtime.
     *
     * Retourne un tableau de scripts iso-legacy avec les clés :
     *  `type`, `app`, `name`, `file`, `path`, `action`, `context`, `remote`,
     *  `excludes`, `includes`, `excludes_apps`, `includes_apps`, `os`,
     *  `interpreter`, `script` (array de lignes).
     *
     * Iso-legacy ordre : package puis local, les valeurs locales gagnent
     * (cf. fonction `merge_applications` legacy).
     *
     * @return list<array<string,mixed>>
     */
    public function scan(?string $packagePath = null, ?string $localPath = null): array
    {
        $paths = [
            'package' => $packagePath ?? self::DEFAULT_PACKAGE_PATH,
            'local'   => $localPath ?? self::DEFAULT_LOCAL_PATH,
        ];

        $scripts = [];
        foreach ($paths as $type => $basePath) {
            if (! is_dir($basePath)) {
                continue;
            }
            $realBase = realpath($basePath);
            if ($realBase === false) {
                continue;
            }
            $this->scanBase($realBase . '/', $type, $scripts);
        }

        return array_values($scripts);
    }

    /**
     * Scanne une base path et merge les scripts trouvés dans `$scripts`.
     *
     * @param  array<string, array<string,mixed>>  $scripts  Indexé par hash sha256.
     */
    private function scanBase(string $basePath, string $type, array &$scripts): void
    {
        $realBase = realpath($basePath);
        if ($realBase === false || ! is_dir($realBase)) {
            return;
        }
        $realBase = rtrim($realBase, '/') . '/';

        $entries = @scandir($realBase, SCANDIR_SORT_ASCENDING);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $app) {
            if ($app === '.' || $app === '..') {
                continue;
            }
            // Sécurité : refuse les noms d'application contenant des séparateurs
            // ou des dot-dot — pas censé arriver via scandir mais defense in depth.
            if (preg_match('#[/\\\\]|\.\.#', $app) === 1) {
                Log::channel('daily')->warning('[ApplicationTemplatesScanner] suspicious app entry', [
                    'app' => $app,
                    'base' => $realBase,
                ]);
                continue;
            }
            $appDir = $realBase . $app;
            if (! is_dir($appDir)) {
                continue;
            }
            // Path traversal : vérifie que `$appDir` reste sous `$realBase` après
            // résolution symlink.
            $realAppDir = realpath($appDir);
            if ($realAppDir === false || ! str_starts_with($realAppDir . '/', $realBase)) {
                Log::channel('daily')->warning('[ApplicationTemplatesScanner] path traversal blocked', [
                    'app' => $app,
                    'resolved' => $realAppDir,
                    'base' => $realBase,
                ]);
                continue;
            }
            $this->scanApp($realAppDir . '/', $app, $type, $scripts);
        }
    }

    /**
     * Scanne un dossier d'application (`/etc/sambaedu/applications/<app>/`).
     *
     * @param  array<string, array<string,mixed>>  $scripts
     */
    private function scanApp(string $appDir, string $appName, string $type, array &$scripts): void
    {
        // 1. scripts.json (index JSON).
        $jsonPath = $appDir . 'scripts.json';
        if (is_file($jsonPath)) {
            $raw = @file_get_contents($jsonPath);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $name => $entry) {
                        if (! is_array($entry) || ! isset($entry['file'])) {
                            continue;
                        }
                        $entry['type'] = $type;
                        $entry['app'] = (string) ($entry['app'] ?? $appName);
                        $entry['name'] = (string) $name;
                        $entry['path'] = $appDir;
                        $entry['context'] = (string) ($entry['context'] ?? '');
                        $entry['remote'] = (bool) ($entry['remote'] ?? false);
                        if (empty($entry['interpreter'])) {
                            $entry['interpreter'] = ($entry['os'] ?? '') === 'windows' ? 'cmd' : 'bash';
                        }
                        $scriptFile = $appDir . $entry['file'];
                        if (is_file($scriptFile)) {
                            $lines = @file($scriptFile);
                            if ($lines !== false) {
                                $entry['script'] = $lines;
                            }
                        }
                        $this->mergeScripts($scripts, $entry);
                    }
                }
            }
        }

        // 2. redirects.json (legacy : `interpreter=redirects`).
        $redirectsPath = $appDir . 'redirects.json';
        if (is_file($redirectsPath)) {
            $raw = @file_get_contents($redirectsPath);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $name => $entry) {
                        if (! is_array($entry)) {
                            continue;
                        }
                        $base = array_merge($entry, [
                            'type' => $type,
                            'app' => (string) ($entry['app'] ?? $appName),
                            'name' => (string) $name,
                            'action' => 'logon',
                            'path' => $appDir,
                            'context' => '',
                            'remote' => false,
                            'os' => 'windows',
                            'interpreter' => 'redirects',
                            'file' => '',
                        ]);
                        $this->mergeScripts($scripts, $base, prepend: true);

                        $systemVariant = $base;
                        $systemVariant['context'] = 'system';
                        $this->mergeScripts($scripts, $systemVariant, prepend: true);
                    }
                }
            }
        }

        // 3. Ancien mécanisme : nom de fichier `<action>[-<context>][@<group>].(windows|linux)`
        //    ou `packages[@<group>].list`.
        $entries = @scandir($appDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $appDir . $file;
            if (! is_file($filePath)) {
                continue;
            }

            if (preg_match('/^(.*)(@(.*))?\.(windows|linux)$/U', $file, $m) === 1) {
                $os = $m[4];
                $interpreter = $os === 'windows' ? 'cmd' : 'bash';
                $a = explode('-', $m[1]);
                $action = $a[0];
                $context = $a[1] ?? '';
                [$include, $exclude] = $this->parseGroupFilter($m[3] ?? '');

                $lines = @file($filePath);
                if ($lines === false) {
                    continue;
                }
                $this->mergeScripts($scripts, [
                    'type' => $type,
                    'app' => $appName,
                    'name' => $appName,
                    'file' => $file,
                    'path' => $appDir,
                    'action' => $action,
                    'context' => $context,
                    'remote' => false,
                    'excludes' => $exclude,
                    'includes' => $include,
                    'os' => $os,
                    'interpreter' => $interpreter,
                    'script' => $lines,
                ]);
            } elseif (preg_match('/^packages(@(.*))?\.list$/U', $file, $m) === 1) {
                [$include, $exclude] = $this->parseGroupFilter($m[2] ?? '');

                $lines = @file($filePath);
                if ($lines === false) {
                    continue;
                }
                $script = implode(' ', array_map('rtrim', $lines)) . ' ';
                $this->mergeScripts($scripts, [
                    'type' => $type,
                    'app' => $appName,
                    'name' => $appName,
                    'file' => $file,
                    'path' => $appDir,
                    'action' => 'startup',
                    'context' => '',
                    'remote' => false,
                    'excludes' => $exclude,
                    'includes' => $include,
                    'os' => 'linux',
                    'interpreter' => 'apt',
                    'script' => [$script],
                ]);
            }
        }
    }

    /**
     * Parse le suffixe `@group` (ou `@-group` pour exclusion).
     *
     * @return array{0: list<string>, 1: list<string>} [includes, excludes]
     */
    private function parseGroupFilter(string $group): array
    {
        if ($group === '') {
            return [[], []];
        }
        if (preg_match('/^-(.*)$/', $group, $mm) === 1) {
            return [[], [$mm[1]]];
        }
        return [[$group], []];
    }

    /**
     * Iso-legacy `merge_applications` — merge incrémental indexé par hash.
     *
     * @param  array<string, array<string,mixed>>  $scripts
     * @param  array<string,mixed>  $entry
     */
    private function mergeScripts(array &$scripts, array $entry, bool $prepend = false): void
    {
        $entry['includes'] = $entry['includes'] ?? [];
        $entry['excludes'] = $entry['excludes'] ?? [];
        $entry['includes_apps'] = $entry['includes_apps'] ?? [];
        $entry['excludes_apps'] = $entry['excludes_apps'] ?? [];

        $hash = hash('sha256', sprintf(
            '%s|%s|%s|%s|%s|%s|%s|%s',
            $entry['os'] ?? '',
            $entry['action'] ?? '',
            $entry['app'] ?? '',
            $entry['name'] ?? '',
            $entry['context'] ?? '',
            (string) ($entry['remote'] ?? false),
            $entry['interpreter'] ?? '',
            $entry['file'] ?? '',
        ));
        $entry['hash'] = $hash;

        if (isset($scripts[$hash])) {
            $existing = $scripts[$hash];
            $scripts[$hash]['includes'] = array_values(array_unique(array_merge($existing['includes'], $entry['includes'])));
            $scripts[$hash]['excludes'] = array_values(array_unique(array_merge($existing['excludes'], $entry['excludes'])));
            $scripts[$hash]['includes_apps'] = array_values(array_unique(array_merge($existing['includes_apps'], $entry['includes_apps'])));
            $scripts[$hash]['excludes_apps'] = array_values(array_unique(array_merge($existing['excludes_apps'], $entry['excludes_apps'])));
            $scripts[$hash]['script'] = $entry['script'] ?? $existing['script'] ?? [];
            return;
        }

        if ($prepend) {
            $scripts = [$hash => $entry] + $scripts;
        } else {
            $scripts[$hash] = $entry;
        }
    }
}
