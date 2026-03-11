<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Service de gestion des images réutilisable
 * Centralise toutes les opérations d'images pour l'application
 */
class ImageManagerService
{
    private FileManagerService $fileManager;

    public function __construct(FileManagerService $fileManager)
    {
        $this->fileManager = $fileManager;
    }

    /**
     * Traiter l'upload d'un fichier d'icône
     */
    public function handleIconUpload(UploadedFile $file, string $name, string $uploadPath = '/etc/sambaedu/applications/shortcuts/'): ?string
    {
        try {
            // Valider le fichier
            if (!$file->isValid()) {
                Log::warning('Fichier d\'icône invalide');
                return null;
            }

            // Vérifier la taille (2MB max)
            if ($file->getSize() > 2 * 1024 * 1024) {
                Log::warning('Fichier d\'icône trop volumineux: ' . $file->getSize() . ' bytes');
                return null;
            }

            // Vérifier le type MIME
            if (!$this->isValidImageType($file)) {
                Log::warning('Type MIME d\'icône non supporté: ' . $file->getMimeType());
                return null;
            }

            // Créer le dossier de destination si nécessaire
            $this->fileManager->createDirectory($uploadPath);

            // Traiter l'image et créer les deux formats (.png et .ico)
            $success = $this->createIconFiles($file, $name, $uploadPath);

            if ($success) {
                // Retourner le nom du fichier (sans extension) comme dans le legacy
                return $name;
            }

            return null;
        } catch (Exception $e) {
            Log::error('Erreur upload icône: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Vérifier si le type de fichier est supporté
     */
    public function isValidImageType(UploadedFile $file): bool
    {
        $allowedMimes = [
            'image/png', 
            'image/jpeg', 
            'image/gif', 
            'image/x-icon', 
            'image/vnd.microsoft.icon'
        ];
        
        return in_array($file->getMimeType(), $allowedMimes);
    }

    /**
     * Générer le nom de fichier pour l'icône
     */
    public function generateIconFilename(string $name, string $extension): string
    {
        // Utiliser le nom du raccourci avec extension appropriée comme dans le legacy
        if (strtolower($extension) === 'ico') {
            $filename = $name . '.ico';
        } else {
            $filename = $name . '.png';
        }

        // Nettoyer le nom de fichier pour éviter les caractères problématiques
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    }

    /**
     * Créer les fichiers d'icônes (.png et .ico) comme dans le legacy
     */
    public function createIconFiles(UploadedFile $file, string $name, string $uploadPath): bool
    {
        try {
            $pngPath = $uploadPath . $name . '.png';
            $icoPath = $uploadPath . $name . '.ico';

            // Créer d'abord le fichier PNG redimensionné à 128x128
            $pngSuccess = $this->convertImageToPng($file->getPathname(), $pngPath, 128, 128);
            
            if (!$pngSuccess) {
                Log::error("Impossible de créer le fichier PNG: $pngPath");
                return false;
            }

            // Créer le fichier ICO à partir du PNG créé
            $icoSuccess = $this->convertPngToIco($pngPath, $icoPath);
            
            if (!$icoSuccess) {
                Log::warning("Impossible de créer le fichier ICO: $icoPath (PNG créé avec succès)");
                // On retourne true car au moins le PNG a été créé
                return true;
            }

            return true;
        } catch (Exception $e) {
            Log::error('Erreur lors de la création des fichiers d\'icônes: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Convertir un PNG en ICO
     */
    public function convertPngToIco(string $pngPath, string $icoPath): bool
    {
        try {
            // Utiliser Imagick si disponible (comme dans le legacy)
            if (class_exists('Imagick')) {
                $imagick = new \Imagick($pngPath);
                $imagick->setImageFormat('ico');
                $imagick->writeImage($icoPath);
                $imagick->destroy();
                return true;
            }

            // Pas de fallback GD pour ICO, format trop complexe
            Log::warning('Imagick non disponible, impossible de créer le fichier ICO');
            return false;
        } catch (Exception $e) {
            Log::error('Erreur conversion PNG vers ICO: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Traiter le fichier image selon son type
     */
    public function processImageFile(UploadedFile $file, string $destinationPath): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'ico' || $extension === 'png') {
            // Déplacer le fichier directement
            return $file->move(dirname($destinationPath), basename($destinationPath)) !== false;
        } else {
            // Convertir en PNG
            return $this->convertImageToPng($file->getPathname(), $destinationPath);
        }
    }

    /**
     * Convertir une image en PNG avec redimensionnement
     */
    public function convertImageToPng(string $sourcePath, string $destinationPath, int $width = 128, int $height = 128): bool
    {
        try {
            // Utiliser Imagick si disponible (comme dans le legacy)
            if (class_exists('Imagick')) {
                return $this->convertWithImagick($sourcePath, $destinationPath, $width, $height);
            }

            // Fallback avec GD
            return $this->convertWithGD($sourcePath, $destinationPath, $width, $height);
        } catch (Exception $e) {
            Log::error('Erreur conversion image: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Conversion avec Imagick
     */
    private function convertWithImagick(string $sourcePath, string $destinationPath, int $width, int $height): bool
    {
        try {
            $imagick = new \Imagick($sourcePath);
            $imagick->resizeImage($width, $height, \Imagick::FILTER_QUADRATIC, 1);
            $imagick->setImageFormat('png');
            $imagick->writeImage($destinationPath);
            $imagick->destroy();
            return true;
        } catch (Exception $e) {
            Log::error('Erreur Imagick: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Conversion avec GD
     */
    private function convertWithGD(string $sourcePath, string $destinationPath, int $width, int $height): bool
    {
        try {
            $sourceImage = $this->createImageFromFile($sourcePath);
            
            if (!$sourceImage) {
                return false;
            }

            // Redimensionner
            $resized = imagecreatetruecolor($width, $height);
            
            // Préserver la transparence pour PNG et GIF
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefill($resized, 0, 0, $transparent);
            
            imagecopyresampled(
                $resized, $sourceImage, 
                0, 0, 0, 0, 
                $width, $height, 
                imagesx($sourceImage), imagesy($sourceImage)
            );
            
            // Sauvegarder en PNG
            $result = imagepng($resized, $destinationPath);
            
            imagedestroy($sourceImage);
            imagedestroy($resized);
            
            return $result;
        } catch (Exception $e) {
            Log::error('Erreur GD: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Créer une ressource image depuis un fichier
     */
    private function createImageFromFile(string $sourcePath)
    {
        $imageInfo = getimagesize($sourcePath);
        
        if (!$imageInfo) {
            return false;
        }
        
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($sourcePath);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($sourcePath);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($sourcePath);
            default:
                return false;
        }
    }

    /**
     * Redimensionner une image existante
     */
    public function resizeImage(string $sourcePath, string $destinationPath, int $width, int $height): bool
    {
        if (!$this->fileManager->exists($sourcePath)) {
            Log::error("Image source inexistante: $sourcePath");
            return false;
        }

        return $this->convertImageToPng($sourcePath, $destinationPath, $width, $height);
    }

    /**
     * Supprimer les fichiers d'icônes associés à un nom
     */
    public function deleteIconFiles(string $name, string $iconsPath = '/etc/sambaedu/applications/shortcuts/'): void
    {
        $iconFiles = [
            $iconsPath . $name . '.png',
            $iconsPath . $name . '.ico',
            $iconsPath . $name . '.gif',
            $iconsPath . $name . '.jpg'
        ];

        $this->fileManager->deleteMultiple($iconFiles);
    }

    /**
     * Obtenir les dimensions d'une image
     */
    public function getImageDimensions(string $imagePath): ?array
    {
        if (!$this->fileManager->exists($imagePath)) {
            return null;
        }

        $imageInfo = getimagesize($imagePath);
        
        if (!$imageInfo) {
            return null;
        }

        return [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'type' => $imageInfo[2],
            'mime' => $imageInfo['mime']
        ];
    }

    /**
     * Vérifier si un fichier est une image valide
     */
    public function isValidImage(string $imagePath): bool
    {
        return $this->getImageDimensions($imagePath) !== null;
    }

    /**
     * Vérifier si les outils de conversion d'images sont disponibles
     */
    public function checkImageTools(): array
    {
        return [
            'imagick' => class_exists('Imagick'),
            'gd' => extension_loaded('gd'),
            'supports_ico' => class_exists('Imagick'), // ICO nécessite Imagick
        ];
    }
}
