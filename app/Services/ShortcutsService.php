<?php

namespace App\Services;

use App\Models\Shortcut;
use App\Services\FileManagerService;
use App\Services\ImageManagerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class ShortcutsService
{
    private string $shortcutsFile = '/etc/sambaedu/applications/shortcuts/shortcuts.json';
    private string $iconsPath = '/etc/sambaedu/applications/shortcuts/';
    private FileManagerService $fileManager;
    private ImageManagerService $imageManager;

    public function __construct(FileManagerService $fileManager, ImageManagerService $imageManager)
    {
        $this->fileManager = $fileManager;
        $this->imageManager = $imageManager;
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
                $shortcutData['windows_icon'] = $iconPath;
            }
        }

        return $this->saveShortcut($shortcutData);
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
