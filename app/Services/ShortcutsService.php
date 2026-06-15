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
    private function persistIconAsset(string $shortcutName): void
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
     * Délègue à `get_wine_shortcuts($config, $application)` legacy (port en
     * shim `@legacy-port` — `@todo Story 16.4` reprise native). Le helper
     * legacy scanne `/home/{se4install_name}/Bureau/*.desktop`, parse les
     * containers Wine et copie les icônes dans
     * `/etc/sambaedu/applications/shortcuts/`.
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
     * @legacy-port path="sambaedu/includes/shortcuts.inc.php:523"
     * @todo Story 16.4 — porter `get_wine_shortcuts` en service natif (scan
     *       FS direct + parsing .desktop sans dépendre du legacy bootstrap).
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
     * Récupère les raccourcis Wine via le helper legacy `get_wine_shortcuts`.
     *
     * Charge `shortcuts.inc.php` à la demande si la fonction n'est pas
     * disponible (cas testing — `legacy/bootstrap.php` skippé). Override
     * possible via container binding pour les tests :
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

        // Chargement conditionnel iso-legacy : requiert config + bootstrap legacy.
        if (! function_exists('get_wine_shortcuts')) {
            $this->loadLegacyShortcuts();
        }

        if (! function_exists('get_wine_shortcuts')) {
            throw new \RuntimeException(
                'ShortcutsService::importWineShortcuts: legacy function get_wine_shortcuts() unavailable. '
                . 'Vérifier que legacy/bootstrap.php a chargé shortcuts.inc.php.',
            );
        }

        $config = $this->resolveLegacyConfig();
        $shortcuts = call_user_func('get_wine_shortcuts', $config, $application);
        return is_array($shortcuts) ? $shortcuts : [];
    }

    /**
     * Charge `shortcuts.inc.php` legacy à la demande.
     *
     * @legacy-port path="sambaedu/includes/shortcuts.inc.php"
     */
    private function loadLegacyShortcuts(): void
    {
        $legacyPath = config('sambaedu.legacy_path', '/var/www/sambaedu');
        $shortcutsInc = rtrim($legacyPath, '/') . '/includes/shortcuts.inc.php';

        if (is_file($shortcutsInc) && is_readable($shortcutsInc)) {
            require_once $shortcutsInc;
        }
    }

    /**
     * Récupère le `$config` legacy (dict) requis par `get_wine_shortcuts`.
     *
     * Délègue à `get_config()` du legacy si la fonction existe, sinon
     * fallback minimal avec `se4install_name` depuis env/config.
     *
     * @return array<string, mixed>
     */
    private function resolveLegacyConfig(): array
    {
        if (function_exists('get_config')) {
            $config = call_user_func('get_config');
            return is_array($config) ? $config : [];
        }

        return [
            'se4install_name' => config('sambaedu.se4install_name', 'se4install'),
        ];
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

        return compact('created', 'updated', 'errors');
    }
}
