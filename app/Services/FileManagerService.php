<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Service de gestion des fichiers réutilisable
 * Centralise toutes les opérations de fichiers pour les services SE4
 */
class FileManagerService
{
    /**
     * Lire le contenu d'un fichier
     */
    public function read(string $filePath): ?string
    {
        try {
            if (!file_exists($filePath)) {
                return null;
            }

            return file_get_contents($filePath);
        } catch (Exception $e) {
            Log::error("Erreur lors de la lecture du fichier {$filePath}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Lire et décoder un fichier JSON
     */
    public function readJson(string $filePath): ?array
    {
        $content = $this->read($filePath);
        
        if ($content === null) {
            return null;
        }

        try {
            return json_decode($content, true);
        } catch (Exception $e) {
            Log::error("Erreur lors du décodage JSON du fichier {$filePath}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Écrire du contenu dans un fichier
     */
    public function write(string $filePath, string $content, bool $createDir = true): bool
    {
        try {
            if ($createDir) {
                $dir = dirname($filePath);
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0755, true)) {
                        Log::error("Impossible de créer le répertoire: {$dir}");
                        return false;
                    }
                }
            }

            return file_put_contents($filePath, $content) !== false;
        } catch (Exception $e) {
            Log::error("Erreur lors de l'écriture du fichier {$filePath}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Écrire un tableau en JSON dans un fichier
     */
    public function writeJson(string $filePath, array $data, bool $createDir = true, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): bool
    {
        try {
            $json = json_encode($data, $flags);
            if ($json === false) {
                Log::error("Erreur lors de l'encodage JSON pour le fichier {$filePath}");
                return false;
            }

            return $this->write($filePath, $json, $createDir);
        } catch (Exception $e) {
            Log::error("Erreur lors de l'écriture JSON du fichier {$filePath}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ajouter du contenu à la fin d'un fichier
     */
    public function append(string $filePath, string $content, bool $createDir = true): bool
    {
        try {
            if ($createDir) {
                $dir = dirname($filePath);
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0755, true)) {
                        Log::error("Impossible de créer le répertoire: {$dir}");
                        return false;
                    }
                }
            }

            return file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX) !== false;
        } catch (Exception $e) {
            Log::error("Erreur lors de l'ajout au fichier {$filePath}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer un fichier
     */
    public function delete(string $filePath): bool
    {
        try {
            if (!file_exists($filePath)) {
                return true; // Déjà supprimé
            }

            return unlink($filePath);
        } catch (Exception $e) {
            Log::error("Erreur lors de la suppression du fichier {$filePath}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer plusieurs fichiers
     */
    public function deleteMultiple(array $filePaths): array
    {
        $results = [];
        
        foreach ($filePaths as $filePath) {
            $results[$filePath] = $this->delete($filePath);
        }

        return $results;
    }

    /**
     * Copier un fichier
     */
    public function copy(string $source, string $destination, bool $createDir = true): bool
    {
        try {
            if (!file_exists($source)) {
                Log::error("Le fichier source n'existe pas: {$source}");
                return false;
            }

            if ($createDir) {
                $dir = dirname($destination);
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0755, true)) {
                        Log::error("Impossible de créer le répertoire: {$dir}");
                        return false;
                    }
                }
            }

            return copy($source, $destination);
        } catch (Exception $e) {
            Log::error("Erreur lors de la copie de {$source} vers {$destination}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Déplacer/renommer un fichier
     */
    public function move(string $source, string $destination, bool $createDir = true): bool
    {
        try {
            if (!file_exists($source)) {
                Log::error("Le fichier source n'existe pas: {$source}");
                return false;
            }

            if ($createDir) {
                $dir = dirname($destination);
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0755, true)) {
                        Log::error("Impossible de créer le répertoire: {$dir}");
                        return false;
                    }
                }
            }

            return rename($source, $destination);
        } catch (Exception $e) {
            Log::error("Erreur lors du déplacement de {$source} vers {$destination}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Vérifier si un fichier existe
     */
    public function exists(string $filePath): bool
    {
        return file_exists($filePath);
    }

    /**
     * Obtenir les informations d'un fichier
     */
    public function getInfo(string $filePath): ?array
    {
        try {
            if (!file_exists($filePath)) {
                return null;
            }

            $stat = stat($filePath);
            return [
                'size' => $stat['size'],
                'modified' => $stat['mtime'],
                'created' => $stat['ctime'],
                'permissions' => substr(sprintf('%o', fileperms($filePath)), -4),
                'is_readable' => is_readable($filePath),
                'is_writable' => is_writable($filePath),
                'is_executable' => is_executable($filePath)
            ];
        } catch (Exception $e) {
            Log::error("Erreur lors de la récupération des informations du fichier {$filePath}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Créer un répertoire
     */
    public function createDirectory(string $dirPath, int $permissions = 0755, bool $recursive = true): bool
    {
        try {
            if (is_dir($dirPath)) {
                return true; // Déjà existant
            }

            return mkdir($dirPath, $permissions, $recursive);
        } catch (Exception $e) {
            Log::error("Erreur lors de la création du répertoire {$dirPath}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer un répertoire (vide)
     */
    public function deleteDirectory(string $dirPath): bool
    {
        try {
            if (!is_dir($dirPath)) {
                return true; // Déjà supprimé
            }

            return rmdir($dirPath);
        } catch (Exception $e) {
            Log::error("Erreur lors de la suppression du répertoire {$dirPath}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer un répertoire et tout son contenu (récursif)
     */
    public function deleteDirectoryRecursive(string $dirPath): bool
    {
        try {
            if (!is_dir($dirPath)) {
                return true; // Déjà supprimé
            }

            $files = array_diff(scandir($dirPath), ['.', '..']);
            
            foreach ($files as $file) {
                $filePath = $dirPath . DIRECTORY_SEPARATOR . $file;
                
                if (is_dir($filePath)) {
                    $this->deleteDirectoryRecursive($filePath);
                } else {
                    $this->delete($filePath);
                }
            }

            return $this->deleteDirectory($dirPath);
        } catch (Exception $e) {
            Log::error("Erreur lors de la suppression récursive du répertoire {$dirPath}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Lister les fichiers d'un répertoire
     */
    public function listFiles(string $dirPath, string $pattern = '*'): array
    {
        try {
            if (!is_dir($dirPath)) {
                return [];
            }

            $files = glob($dirPath . DIRECTORY_SEPARATOR . $pattern);
            return array_filter($files, 'is_file');
        } catch (Exception $e) {
            Log::error("Erreur lors du listage des fichiers dans {$dirPath}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Lister les répertoires d'un répertoire
     */
    public function listDirectories(string $dirPath): array
    {
        try {
            if (!is_dir($dirPath)) {
                return [];
            }

            $items = scandir($dirPath);
            $directories = [];
            
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    $itemPath = $dirPath . DIRECTORY_SEPARATOR . $item;
                    if (is_dir($itemPath)) {
                        $directories[] = $itemPath;
                    }
                }
            }

            return $directories;
        } catch (Exception $e) {
            Log::error("Erreur lors du listage des répertoires dans {$dirPath}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Modifier les permissions d'un fichier
     */
    public function chmod(string $filePath, int $permissions): bool
    {
        try {
            if (!file_exists($filePath)) {
                Log::error("Le fichier n'existe pas: {$filePath}");
                return false;
            }

            return chmod($filePath, $permissions);
        } catch (Exception $e) {
            Log::error("Erreur lors du changement de permissions du fichier {$filePath}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtenir la taille d'un fichier en octets
     */
    public function getSize(string $filePath): ?int
    {
        try {
            if (!file_exists($filePath)) {
                return null;
            }

            return filesize($filePath);
        } catch (Exception $e) {
            Log::error("Erreur lors de la récupération de la taille du fichier {$filePath}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculer le hash d'un fichier
     *
     * @throws \RuntimeException Si le fichier n'existe pas
     */
    public function hashFile(string $path, string $algo = 'sha256'): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Fichier introuvable pour le calcul de hash: {$path}");
        }

        return hash_file($algo, $path);
    }

    /**
     * Telecharger un fichier avec verification optionnelle de hash
     *
     * Utilise le pattern sink pour eviter de charger le fichier en memoire.
     * Cree le repertoire parent si inexistant.
     *
     * @throws \RuntimeException Si le telechargement echoue ou si le hash ne correspond pas
     */
    public function downloadWithHash(
        string $url,
        string $targetPath,
        ?string $sha512 = null,
        ?string $sha256 = null,
        ?string $md5 = null,
        int $timeout = 300,
    ): string {
        // Creer le repertoire parent si inexistant
        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Impossible de creer le repertoire: {$dir}");
        }

        // timeout = plafond total du transfert (généreux pour les gros installeurs).
        // connectTimeout = échec rapide si l'hôte est injoignable.
        // Garde bas-débit : coupe un transfert réellement bloqué (< 1 Ko/s pendant 60 s)
        // au lieu d'attendre le plafond total. Distingue « lent mais progresse » de « bloqué ».
        $response = Http::timeout($timeout)
            ->connectTimeout(30)
            ->withOptions([
                'sink' => $targetPath,
                'curl' => [
                    CURLOPT_LOW_SPEED_LIMIT => 1024,
                    CURLOPT_LOW_SPEED_TIME => 60,
                ],
            ])
            ->get($url);

        if (!$response->successful()) {
            @unlink($targetPath);
            throw new \RuntimeException("Echec telechargement HTTP {$response->status()} pour {$url}");
        }

        if (!file_exists($targetPath)) {
            throw new \RuntimeException("Echec telechargement: fichier non cree pour {$url}");
        }

        // Verifier les hash dans l'ordre de priorite
        $checks = [
            'sha512' => $sha512,
            'sha256' => $sha256,
            'md5' => $md5,
        ];

        foreach ($checks as $algo => $expected) {
            if ($expected === null) {
                continue;
            }

            $computed = hash_file($algo, $targetPath);
            if ($computed === false) {
                @unlink($targetPath);
                throw new \RuntimeException("Impossible de calculer le hash {$algo} pour {$targetPath}");
            }
            if (strtolower($computed) !== strtolower($expected)) {
                @unlink($targetPath);
                throw new \RuntimeException(
                    "Hash {$algo} invalide. Attendu: {$expected}, Calcule: {$computed}"
                );
            }
        }

        return $targetPath;
    }

    /**
     * Extraire une archive .tar.gz vers un répertoire cible
     *
     * @throws \RuntimeException Si l'archive est introuvable ou corrompue
     */
    public function extractTarGz(string $archivePath, string $targetDir): void
    {
        if (!file_exists($archivePath)) {
            throw new \RuntimeException("Archive introuvable: {$archivePath}");
        }

        // exec('tar') plutot que PharData : plus fiable sur grosses archives (bug PharData PHP 8.2+)
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new \RuntimeException("Impossible de creer le repertoire cible: {$targetDir}");
        }

        $escapedArchive = escapeshellarg($archivePath);
        $escapedTarget = escapeshellarg($targetDir);

        exec("tar xzf {$escapedArchive} -C {$escapedTarget} 2>&1", $output, $code);

        if ($code !== 0) {
            throw new \RuntimeException(
                "Erreur extraction tar.gz {$archivePath}: " . implode("\n", $output)
            );
        }
    }

    /**
     * Extraire une archive .zip vers un répertoire cible
     *
     * @throws \RuntimeException Si l'archive est introuvable ou corrompue
     */
    public function extractZip(string $archivePath, string $targetDir): void
    {
        if (!file_exists($archivePath)) {
            throw new \RuntimeException("Archive introuvable: {$archivePath}");
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new \RuntimeException("Impossible de creer le repertoire cible: {$targetDir}");
        }

        $zip = new \ZipArchive();
        $result = $zip->open($archivePath);

        if ($result !== true) {
            throw new \RuntimeException("Archive ZIP corrompue ou invalide: {$archivePath} (code: {$result})");
        }

        $zip->extractTo($targetDir);
        $zip->close();
    }

    /**
     * Obtenir la taille d'un fichier formatée (KB, MB, GB)
     */
    public function getFormattedSize(string $filePath): ?string
    {
        $size = $this->getSize($filePath);
        
        if ($size === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}
