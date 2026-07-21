<?php

namespace App\Services;

use App\Models\Shortcut;
use App\Services\FileManagerService;
use App\Services\ImageManagerService;
use App\Services\Shortcuts\ShortcutIconAssetService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class ShortcutsService
{
    private string $shortcutsFile = '/etc/sambaedu/applications/shortcuts/shortcuts.json';
    private string $iconsPath = '/etc/sambaedu/applications/shortcuts/';
    private FileManagerService $fileManager;
    private ImageManagerService $imageManager;
    private ShortcutIconAssetService $iconAssetService;

    public function __construct(
        FileManagerService $fileManager,
        ImageManagerService $imageManager,
        ?ShortcutIconAssetService $iconAssetService = null,
    ) {
        $this->fileManager = $fileManager;
        $this->imageManager = $imageManager;
        $this->iconAssetService = $iconAssetService ?? new ShortcutIconAssetService();
    }

    /**
     * Récupérer tous les raccourcis (fichier principal + .inc.json)
     */
    public function getAllShortcuts(): array
    {
        $shortcuts = $this->fileManager->readJson($this->shortcutsFile) ?? [];

        // Ajouter les raccourcis depuis les fichiers .inc.json en préservant les clés
        $includeShortcuts = $this->loadIncludeShortcuts();
        foreach ($includeShortcuts as $key => $shortcut) {
            $shortcuts[$key] = $shortcut;
        }

        // Nettoyer les raccourcis sans nom et inclure la clé dans les données
        foreach ($shortcuts as $key => $value) {
            if (!isset($value["name"])) {
                unset($shortcuts[$key]);
            } else {
                // Inclure la clé dans les données du raccourci
                $shortcuts[$key]['key'] = $key;
            }
        }

        return $shortcuts;
    }

    /**
     * Récupérer un raccourci par sa clé
     */
    public function getShortcutByKey(string $key): ?array
    {
        $shortcuts = $this->getAllShortcuts();

        return $shortcuts[$key] ?? null;
    }

    /**
     * Récupérer un raccourci par son nom
     * @return array|null Le raccourci avec sa clé, ou null si non trouvé
     */
    public function getShortcutByName(string $name): ?array
    {
        $shortcuts = $this->getAllShortcuts();

        foreach ($shortcuts as $key => $shortcut) {
            if (isset($shortcut['name']) && $shortcut['name'] === $name) {
                $shortcut['key'] = $key;
                return $shortcut;
            }
        }

        return null;
    }


    /**
     * Charger les raccourcis depuis les fichiers .inc.json
     */
    private function loadIncludeShortcuts(): array
    {
        $shortcuts = [];
        $paths = [
            $this->iconsPath
        ];

        foreach ($paths as $path) {
            $files = $this->fileManager->listFiles($path, '*.inc.json');

            foreach ($files as $filePath) {
                $data = $this->fileManager->readJson($filePath);
                if (is_array($data)) {
                    $shortcuts = array_merge_recursive($data, $shortcuts);
                }
            }
        }

        return $shortcuts;
    }

    /**
     * Filtrer les raccourcis selon les critères
     */
    public function filterShortcuts(array $shortcuts, array $filters): array
    {
        $filtered = $shortcuts;

        // Filtre par recherche
        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $filtered = array_filter($filtered, function ($shortcut) use ($search) {
                return strpos(strtolower($shortcut['name'] ?? ''), $search) !== false ||
                    strpos(strtolower($shortcut['owner'] ?? ''), $search) !== false;
            });
        }

        // Filtre par type
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $filtered = array_filter($filtered, function ($shortcut) use ($filters) {
                if ($filters['type'] === 'url') {
                    return isset($shortcut['windows']['args']) &&
                        preg_match("/^http/", $shortcut['windows']['args']);
                } elseif ($filters['type'] === 'app') {
                    return !isset($shortcut['windows']['args']) ||
                        !preg_match("/^http/", $shortcut['windows']['args']);
                }
                return true;
            });
        }

        // Filtre par emplacement
        if (!empty($filters['place']) && $filters['place'] !== 'all') {
            $filtered = array_filter($filtered, function ($shortcut) use ($filters) {
                return ($shortcut['place'] ?? '') === $filters['place'];
            });
        }

        // Filtre par propriétaire
        if (!empty($filters['owner'])) {
            $owner = strtolower($filters['owner']);
            $filtered = array_filter($filtered, function ($shortcut) use ($owner) {
                $owners = preg_split('/(\s*,*\s*)*,+(\s*,*\s*)*/', $shortcut['owner'] ?? '');
                foreach ($owners as $o) {
                    if (strpos(strtolower($o), $owner) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }

        return $filtered;
    }

    /**
     * Sauvegarder les raccourcis dans le fichier JSON
     */
    public function saveShortcuts(array $shortcuts): bool
    {
        return $this->fileManager->writeJson($this->shortcutsFile, $shortcuts);
    }

    /**
     * Supprimer un raccourci
     * @param bool $force Forcer la suppression même si global=true (utilisé par ControlHub)
     */
    public function deleteShortcut(string $key, bool $force = false): bool
    {
        // Récupérer seulement les raccourcis du fichier principal
        $shortcuts = $this->getMainShortcuts();

        if (!isset($shortcuts[$key])) {
            return false;
        }

        $shortcut = $shortcuts[$key];

        // Bloquer la suppression des raccourcis globaux sauf si forcé
        if (!$force && !empty($shortcut['global'])) {
            return false;
        }

        unset($shortcuts[$key]);

        // Supprimer les fichiers d'icônes associés
        if (isset($shortcut['name'])) {
            $this->deleteShortcutIcons($shortcut['name']);
        }

        return $this->saveShortcuts($shortcuts);
    }

    /**
     * Vérifie si un raccourci est protégé (global)
     */
    public function isGlobalShortcut(string $key): bool
    {
        $shortcuts = $this->getMainShortcuts();
        return !empty($shortcuts[$key]['global']);
    }

    /**
     * Vérifie si un raccourci est géré par le ControlHub (a la clé global)
     */
    public function isControlHubShortcut(string $name): bool
    {
        $shortcut = $this->getShortcutByName($name);
        return $shortcut !== null && !empty($shortcut['global']);
    }

    /**
     * Gérer l'upload d'une icône pour un raccourci
     */
    public function handleIconUpload(UploadedFile $file, string $shortcutName): ?string
    {
        return $this->imageManager->handleIconUpload(
            $file,
            $shortcutName,
            $this->iconsPath
        );
    }

    /**
     * Supprimer les icônes d'un raccourci
     */
    private function deleteShortcutIcons(string $name): void
    {
        $this->imageManager->deleteIconFiles($name, $this->iconsPath);
    }

    /**
     * Récupérer seulement les raccourcis du fichier principal (sans les .inc.json)
     */
    private function getMainShortcuts(): array
    {
        return $this->fileManager->readJson($this->shortcutsFile) ?? [];
    }

    /**
     * Ajouter ou modifier un raccourci
     * @return string|false La clé du raccourci créé/modifié ou false en cas d'échec
     */
    public function saveShortcut(array $shortcutData): string|false
    {
        // Récupérer seulement les raccourcis du fichier principal
        $shortcuts = $this->getMainShortcuts();

        // Extraire la clé avant de la supprimer des données
        $key = $shortcutData['key'] ?? null;

        // Si pas de clé fournie, c'est un nouveau raccourci
        if ($key === null) {
            $key = uniqid();
        }

        // Créer les données du raccourci sans la clé technique
        $shortcutToSave = [
            'name' => $shortcutData['name'],
            'owner' => $shortcutData['owner'] ?? '',
            'place' => $shortcutData['place'] ?? 'desktop',
            'windows' => [
                'link' => $shortcutData['windows_link'] ?? '',
                'args' => $shortcutData['windows_args'] ?? '',
                'path' => $shortcutData['windows_path'] ?? '',
                'icon' => $shortcutData['windows_icon'] ?? ''
            ],
            'linux' => [
                'link' => $shortcutData['linux_link'] ?? '',
                'args' => $shortcutData['linux_args'] ?? '',
                'path' => $shortcutData['linux_path'] ?? '',
                'icon' => $shortcutData['linux_icon'] ?? '',
                'startupwmclass' => $shortcutData['linux_startupwmclass'] ?? ''
            ]
        ];

        // Ajouter la propriété global si définie
        if (isset($shortcutData['global'])) {
            $shortcutToSave['global'] = (bool) $shortcutData['global'];
        }

        // Sauvegarder à la clé existante (update) ou nouvelle clé (create)
        $shortcuts[$key] = $shortcutToSave;

        if ($this->saveShortcuts($shortcuts)) {
            return $key;
        }

        return false;
    }

    /**
     * Sauvegarder un raccourci avec gestion d'upload d'icône
     * @return string|false La clé du raccourci créé/modifié ou false en cas d'échec
     */
    public function saveShortcutWithIcon(array $shortcutData, ?UploadedFile $iconFile = null): string|false
    {
        // Gérer l'upload d'icône si fournie
        if ($iconFile) {
            $iconPath = $this->handleIconUpload($iconFile, $shortcutData['name']);
            if ($iconPath) {
                // `windows_icon` reste le NOM NU (legacy + UI — INCHANGÉ).
                $shortcutData['windows_icon'] = $iconPath;

                // Story 27.7 (AC1) : content-adressage de l'icône uploadée vers
                // le dossier servi par Apache + persistance filename/checksum sur
                // le raccourci DB s'il existe (le provider lit la DB). Le `.ico`
                // legacy name-addressed (`<name>.ico`) vient d'être produit par
                // handleIconUpload — on le content-adresse en plus (jamais à sa
                // place). Fail-soft : un échec asset ne casse pas la sauvegarde
                // du raccourci (le backfill rattrapera).
                $this->persistIconAsset($shortcutData['name']);
            }
        }

        return $this->saveShortcut($shortcutData);
    }

    /**
     * Content-adresse l'icône uploadée `<name>.ico` et persiste
     * `icon_asset`/`icon_checksum` sur le raccourci DB correspondant (résolu par
     * nom nu — la même clé que `windows_icon`). Story 27.7, AC1.
     *
     * Idempotent + fail-soft : source absente / pas de raccourci DA encore
     * importé → no-op silencieux (le backfill artisan rattrape).
     */
    public function persistIconAsset(string $shortcutName): void
    {
        $sourceIco = rtrim($this->iconsPath, '/') . '/' . $shortcutName . '.ico';
        $asset = $this->iconAssetService->contentAddress($sourceIco);
        if ($asset === null) {
            return;
        }

        // Lie tous les raccourcis DB dont l'icône uploadée == ce nom nu
        // (windows_icon OU icon_path), exactement la résolution du provider.
        Shortcut::query()
            ->where('windows_icon', $shortcutName)
            ->orWhere('icon_path', $shortcutName)
            ->update([
                'icon_asset' => $asset['asset'],
                'icon_checksum' => $asset['checksum'],
            ]);
    }

    /**
     * Obtenir les options de filtres
     */
    public function getFilterOptions(): array
    {
        return [
            'type' => [
                'all' => 'Tous les types',
                'url' => 'Sites web',
                'app' => 'Applications'
            ],
            'place' => [
                'all' => 'Tous les emplacements',
                'desktop' => 'Bureau',
                'startup' => 'Démarrage automatique',
                'taskbar' => 'Barre des tâches (seulement Linux)'
            ]
        ];
    }

    /**
     * Vérifier si un raccourci est une URL
     */
    public function isUrlShortcut(array $shortcut): bool
    {
        return isset($shortcut['windows']['args']) &&
            preg_match("/^http/", $shortcut['windows']['args']);
    }

    /**
     * Obtenir l'URL de l'icône d'un raccourci
     */
    public function getShortcutIconUrl(string $name): string
    {
        // Chercher le fichier PNG dans le dossier des icônes
        $iconPath = $this->iconsPath . '/' . $name . '.png';

        if (file_exists($iconPath)) {
            return route('shortcuts.icon', ['name' => $name]);
        }

        // Fallback vers l'icône par défaut
        return asset('elements/images/system-run.png');
    }

    /**
     * Importe les raccourcis Wine depuis le scan du dossier Wine partagé.
     *
     * Story 16.3c — AC1.4, AC2.2.
     *
     * Story 38.4 : port NATIF de `get_wine_shortcuts` ({@see scanWineShortcuts})
     * — scanne `/home/{se4install_name}/Bureau/*.desktop`, parse les containers
     * Wine et copie les icônes dans `/etc/sambaedu/applications/shortcuts/`,
     * sans plus AUCUN `require` du legacy `/var/www/sambaedu`.
     *
     * Merge dans `/etc/sambaedu/applications/shortcuts/shortcuts.json` :
     * - Lecture JSON existant (gracieux si fichier absent)
     * - `array_merge` iso-legacy `gpo/wine.php:67`
     * - Atomic write : `flock(LOCK_EX)` + tmp + rename (parité Story 15.1
     *   `AtomicFileWriter`, anti-corruption si 2 admins lancent simultanément)
     *
     * Retour : nombre de raccourcis Wine ajoutés (= `count($newShortcuts)`).
     *
     * @param string $application Nom du conteneur Wine (sans le préfixe `wine-`).
     *                            Chaîne vide = conteneur par défaut `.wine`.
     * @return int Nombre de raccourcis Wine ajoutés au merge.
     * @throws \RuntimeException Si l'atomic write échoue après lock acquis.
     *
     * Story 38.4 — `get_wine_shortcuts` porté nativement ({@see scanWineShortcuts}),
     * l'ancien `@todo Story 16.4` est soldé.
     */
    public function importWineShortcuts(string $application): int
    {
        $log = \App\Gpo\Support\GpoLogger::action('gpo.wine.shortcuts.generate', null, [
            'application' => $application,
        ]);

        try {
            $log->step('loading wine shortcuts via legacy shim');

            $newShortcuts = $this->fetchWineShortcutsLegacy($application);
            $addedCount = count($newShortcuts);

            $log->step('merging shortcuts.json', [
                'added_count' => $addedCount,
            ]);

            $this->atomicMergeShortcuts($newShortcuts);

            $log->success([
                'added_count' => $addedCount,
                'application' => $application,
            ]);

            return $addedCount;
        } catch (\Throwable $e) {
            $log->failure($e);
            throw $e;
        }
    }

    /**
     * Récupère les raccourcis Wine — port natif de `get_wine_shortcuts`
     * (Story 38.4, ex-`@todo Story 16.4`). Plus AUCUN `require` legacy.
     *
     * Un hook test reste supporté : le binding container
     * `legacy.get_wine_shortcuts` (utilisé par les tests pour injecter un jeu
     * déterministe sans toucher au FS). Sinon, scan natif du dossier Wine.
     * ```php
     * app()->bind('legacy.get_wine_shortcuts', fn() => fn($app) => [...]);
     * ```
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchWineShortcutsLegacy(string $application): array
    {
        // Hook test override : binding container `legacy.get_wine_shortcuts`.
        if (app()->bound('legacy.get_wine_shortcuts')) {
            $fn = app('legacy.get_wine_shortcuts');
            if (is_callable($fn)) {
                $result = $fn($application);
                return is_array($result) ? $result : [];
            }
        }

        return $this->scanWineShortcuts($application);
    }

    /**
     * Scan natif de `/home/<se4install_name>/Bureau/*.desktop` — port 1:1 du
     * legacy `get_wine_shortcuts` (`shortcuts.inc.php:523`).
     *
     * Pour chaque `.desktop` dont la ligne `Exec=env "WINEPREFIX=…" wine "…"`
     * matche : construit l'entrée `linux.link` (WINEPREFIX réécrit vers
     * `$HOME/.wine`), copie l'icône trouvée récursivement sous
     * `~/.local/share/icons` vers
     * `/etc/sambaedu/applications/shortcuts/<name>.png` (chemin de DONNÉES
     * existant, conservé — pas un require).
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanWineShortcuts(string $application): array
    {
        $se4install = (string) config('sambaedu.se4install_name', 'se4install');
        $desktopDir = '/home/' . $se4install . '/Bureau/';
        $iconsRoot = '/home/' . $se4install . '/.local/share/icons';

        if (! is_dir($desktopDir)) {
            return [];
        }

        // Parité legacy : rend l'arbre .local lisible avant scan des icônes.
        @exec('sudo chmod -R 755 /home/' . escapeshellarg($se4install) . '/.local 2>/dev/null');

        $result = [];
        $entries = @scandir($desktopDir) ?: [];

        foreach ($entries as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'desktop') {
                continue;
            }

            $link = $this->readDesktopEntry($desktopDir . $file);
            if ($link === null || ! isset($link['Exec'])) {
                continue;
            }

            if (preg_match('/^env "WINEPREFIX=(.*)" wine "(.*)"$/', $link['Exec'], $m) !== 1) {
                continue;
            }

            $prefix = $m[1];
            $exe = $m[2];
            $name = $link['Name'] ?? pathinfo($file, PATHINFO_FILENAME);

            $entry = [
                'name' => $name,
                'linux' => [
                    'link' => 'env WINEPREFIX="$HOME/.wine" wine "' . $exe . '"',
                ],
                'owner' => '',
                'place' => '',
            ];

            if (isset($link['Path'])) {
                $entry['linux']['path'] = (string) preg_replace(
                    '#' . preg_quote($prefix, '#') . '#',
                    '$HOME/.wine',
                    $link['Path'],
                );
            }
            if (isset($link['StartupWMClass'])) {
                $entry['startupwmclass'] = $link['StartupWMClass'];
            }

            // Recherche + copie de l'icône (chemin de données existant).
            if (isset($link['Icon'])) {
                $this->copyWineIcon($iconsRoot, (string) $link['Icon'], (string) $name);
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Parse un fichier `.desktop` (paires `clé=valeur`) — port `read_shortcut`.
     *
     * @return array<string,string>|null
     */
    private function readDesktopEntry(string $path): ?array
    {
        $lines = @file($path);
        if (! is_array($lines)) {
            return null;
        }

        $entry = [];
        foreach ($lines as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $entry[$parts[0]] = trim($parts[1]);
            }
        }

        return $entry;
    }

    /**
     * Recherche récursive de l'icône `$iconName` sous `$iconsRoot` et copie du
     * premier match vers `/etc/sambaedu/applications/shortcuts/<name>.png`
     * (parité legacy). Best-effort (aucune exception si absent).
     */
    private function copyWineIcon(string $iconsRoot, string $iconName, string $name): void
    {
        if (! is_dir($iconsRoot)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($iconsRoot, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $f) {
                if (pathinfo($f, PATHINFO_FILENAME) === $iconName) {
                    @copy($f->getPathname(), '/etc/sambaedu/applications/shortcuts/' . $name . '.png');
                    break;
                }
            }
        } catch (\Throwable) {
            // best-effort : l'absence d'icône ne bloque pas l'import.
        }
    }

    /**
     * Merge atomique `$newShortcuts` dans `$this->shortcutsFile` avec `flock`.
     *
     * Iso-legacy merge `array_merge($shortcuts, get_wine_shortcuts(...))`.
     * Atomic write iso pattern Story 15.1 :
     *   1. Acquire `flock(LOCK_EX)` sur le fichier
     *   2. Lire le contenu actuel
     *   3. Écrire le merge dans `<filename>.tmp.<pid>`
     *   4. `rename()` atomique tmp → final
     *   5. Release lock
     *
     * @param array<int, array<string, mixed>> $newShortcuts
     */
    private function atomicMergeShortcuts(array $newShortcuts): void
    {
        $file = $this->shortcutsFile;
        $dir = dirname($file);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Ouvre ou crée le fichier verrou (mode 'c+' = read/write, create if missing).
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            throw new \RuntimeException("ShortcutsService::importWineShortcuts: cannot open {$file} for locking");
        }

        try {
            if (! flock($fh, LOCK_EX)) {
                throw new \RuntimeException("ShortcutsService::importWineShortcuts: cannot acquire flock on {$file}");
            }

            // Re-lire le fichier sous lock (anti-race).
            $existingJson = stream_get_contents($fh);
            $existing = [];
            if (is_string($existingJson) && $existingJson !== '') {
                $decoded = json_decode($existingJson, true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }

            // Iso-legacy merge `array_merge($shortcuts, get_wine_shortcuts(...))`.
            $merged = array_merge($existing, $newShortcuts);

            $encoded = json_encode(
                $merged,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            if ($encoded === false) {
                throw new \RuntimeException("ShortcutsService::importWineShortcuts: json_encode failed");
            }

            // Atomic write : tmp + rename. tmp dans le même dossier (sinon
            // rename cross-FS échoue).
            $tmp = $file . '.tmp.' . getmypid();
            $bytes = @file_put_contents($tmp, $encoded);
            if ($bytes === false) {
                throw new \RuntimeException("ShortcutsService::importWineShortcuts: write failed on {$tmp}");
            }

            if (! @rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException("ShortcutsService::importWineShortcuts: rename {$tmp} → {$file} failed");
            }
        } finally {
            // Release lock + close.
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * Importe les raccourcis depuis le fichier JSON vers la base de données.
     * Utilisé par la page sync-from-ad pour peupler la table shortcuts.
     *
     * - Les raccourcis existants (même clé) sont mis à jour.
     * - Les nouveaux raccourcis sont créés.
     * - Les raccourcis avec global=true deviennent is_global=true en DB.
     *
     * @return array Résumé de l'import : ['created' => int, 'updated' => int, 'errors' => int]
     */
    public function importFromJson(): array
    {
        $allShortcuts = $this->getAllShortcuts();

        $created = 0;
        $updated = 0;
        $errors = 0;

        foreach ($allShortcuts as $key => $data) {
            try {
                $attributes = [
                    'name' => $data['name'],
                    'owner' => $data['owner'] ?? null,
                    'place' => $data['place'] ?? Shortcut::PLACE_DESKTOP,
                    'is_global' => !empty($data['global']),
                    'windows_link' => $data['windows']['link'] ?? null,
                    'windows_args' => $data['windows']['args'] ?? null,
                    'windows_path' => $data['windows']['path'] ?? null,
                    'windows_icon' => $data['windows']['icon'] ?? null,
                    'linux_link' => $data['linux']['link'] ?? null,
                    'linux_args' => $data['linux']['args'] ?? null,
                    'linux_path' => $data['linux']['path'] ?? null,
                    'linux_icon' => $data['linux']['icon'] ?? null,
                    'linux_startupwmclass' => $data['linux']['startupwmclass'] ?? null,
                ];

                $attributes = $this->normalizeWebTarget($attributes);

                $existing = Shortcut::findByKey($key);

                if ($existing) {
                    $existing->update($attributes);
                    $updated++;
                } else {
                    Shortcut::create(array_merge(['key' => $key], $attributes));
                    $created++;
                }
            } catch (\Exception $e) {
                Log::error("ShortcutsService::importFromJson error for key '{$key}': " . $e->getMessage());
                $errors++;
            }
        }

        Log::info("ShortcutsService::importFromJson completed", [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'total' => count($allShortcuts),
        ]);

        $repaired = $this->repairWebTargets();

        Log::info("ShortcutsService::importFromJson completed", [
            'web_repaired' => $repaired,
        ]);

        return compact('created', 'updated', 'errors') + ['web_repaired' => $repaired];
    }

    /**
     * Normalise un raccourci de type « site web » vers une cible exécutable.
     *
     * Le legacy stocke dans `windows.link` soit un chemin de navigateur, soit
     * une SENTINELLE (`default`, `microsoft-edge`) qu'il ne traduisait qu'au
     * moment de générer le `.lnk`. Importées telles quelles, ces sentinelles
     * arrivent à `IShellLink::SetPath()` côté agent, qui n'accepte qu'un chemin
     * de fichier : le poste affiche « l'élément auquel ce raccourci renvoie a
     * été modifié ou déplacé ».
     *
     * On applique donc ici la traduction que le legacy faisait en aval, et on
     * pose `is_url` au passage — l'import ne le renseignait pas.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function normalizeWebTarget(array $attributes): array
    {
        $probe = new Shortcut($attributes);

        if (! $probe->looksLikeUrlShortcut()) {
            return $attributes;
        }

        $url = $probe->getUrl();
        if ($url === null) {
            return $attributes;
        }

        // `detectBrowserKey()` sait relire les sentinelles legacy comme les
        // chemins de navigateur du catalogue.
        return array_merge(
            $attributes,
            Shortcut::webTargetAttributes($url, $probe->detectBrowserKey())
        );
    }

    /**
     * Réécrit les raccourcis web déjà en base dont la cible n'est pas un
     * exécutable.
     *
     * L'import ne couvre que les raccourcis présents dans le JSON legacy ; ceux
     * créés directement dans SE5 avant le correctif portent la même cible
     * invalide. Idempotent : un raccourci déjà normalisé n'est pas réécrit.
     *
     * @return int nombre de raccourcis réparés
     */
    public function repairWebTargets(): int
    {
        $repaired = 0;

        foreach (Shortcut::query()->where('is_global', false)->cursor() as $shortcut) {
            if (! $shortcut->isUrlShortcut()) {
                continue;
            }

            $url = $shortcut->getUrl();
            if ($url === null) {
                continue;
            }

            $expected = Shortcut::webTargetAttributes($url, $shortcut->detectBrowserKey());

            $alreadyCorrect = true;
            foreach ($expected as $column => $value) {
                if ((string) $shortcut->{$column} !== (string) $value) {
                    $alreadyCorrect = false;
                    break;
                }
            }

            if ($alreadyCorrect) {
                continue;
            }

            $shortcut->update($expected);
            $repaired++;
        }

        return $repaired;
    }
}
