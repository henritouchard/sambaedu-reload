<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WorkstationGroup;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Service d'upload + redimensionnement wallpaper.
 *
 * Story 4.7 — AC 8.
 *
 * Compat legacy : le nom de fichier respecte `<type>@<key>.jpg` avec
 * `key = $owner->name` (salles/groupes), `$owner->login` (users),
 * `wallpaper.jpg` / `lockscreen.jpg` (défauts étab). Permet un rollback safe
 * (si on désactive la route Laravel, le legacy reprend directement).
 *
 * Écriture atomique (tmp + rename) pour éviter que les clients lisant en
 * concurrence voient du JPG corrompu (cf. mémoire `feedback_atomic_write.md`).
 */
class WallpaperUploadService
{
    public function __construct()
    {
        // Protection contre les bombes pixel (post-review #10)
        WallpaperComposer::configureImagickLimits();
    }

    /**
     * Stocke un wallpaper/lockscreen.
     *
     * @param  string  $type  'wallpaper' | 'lockscreen'
     * @param  Model|null  $owner  User / UserGroup / WorkstationGroup ; null = défaut étab
     */
    public function store(
        UploadedFile $file,
        string $type,
        ?Model $owner = null,
        bool $isDefault = false,
    ): Wallpaper {
        $this->assertValidType($type);
        $this->assertValidFile($file);

        if ($isDefault && $owner !== null) {
            throw new \InvalidArgumentException('isDefault et owner sont mutuellement exclusifs.');
        }

        $filename = $this->resolveFilename($type, $owner, $isDefault);
        // Post-review #F : basename défensif — coupe tout `/` ou `..` qui
        // aurait pu survivre au sanitize (protège contre path traversal même
        // si la regex upstream change).
        $filename = basename($filename);
        $targetDir = rtrim((string) config('wallpapers.storage_path'), '/');
        $targetPath = $targetDir . '/' . $filename;

        if (! is_dir($targetDir)) {
            if (! @mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
                throw new \RuntimeException("Impossible de créer {$targetDir}");
            }
        }

        $this->writeAtomic($file, $targetPath);

        /** @var Authenticatable|null $user */
        $user = Auth::user();
        $authId = ($user !== null && method_exists($user, 'getAuthIdentifier'))
            ? $user->getAuthIdentifier()
            : null;
        $uploadedBy = is_int($authId) ? $authId : (is_numeric($authId) ? (int) $authId : null);

        $ownerType = $owner !== null ? $owner::class : null;
        $ownerId = $owner !== null ? (int) $owner->getKey() : null;
        $displayName = $owner !== null
            ? $this->ownerDisplayName($owner)
            : ($isDefault ? 'défaut étab' : 'unknown');

        // Post-review #4 : pour un upload défaut étab, on AJOUTE `is_default`
        // dans le WHERE afin de ne PAS matcher des rows orphans historiques
        // `(type, NULL, NULL, is_default=false)` qui auraient été seedés.
        // Pour un owner concret, (type, owner_type, owner_id) suffit.
        $matchCriteria = [
            'type' => $type,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
        ];
        if ($owner === null) {
            $matchCriteria['is_default'] = true;
        }

        /** @var Wallpaper $wallpaper */
        $wallpaper = Wallpaper::updateOrCreate(
            $matchCriteria,
            [
                'name' => $displayName,
                'path' => $targetPath,
                'is_default' => $owner === null && $isDefault,
                'uploaded_by' => $uploadedBy,
            ],
        );

        Log::info('[WallpaperUpload] stored', [
            'id' => $wallpaper->id,
            'type' => $type,
            'owner' => $ownerType . ':' . $ownerId,
            'path' => $targetPath,
        ]);

        return $wallpaper;
    }

    /**
     * Nom de fichier conforme au legacy :
     *   - défaut étab  → `wallpaper.jpg` / `lockscreen.jpg`
     *   - User         → `<type>@<login>.jpg`
     *   - UserGroup    → `<type>@<name>.jpg`
     *   - WorkstationGroup → `<type>@<name>.jpg`
     */
    public function resolveFilename(string $type, ?Model $owner, bool $isDefault): string
    {
        if ($owner === null) {
            // Défaut étab : `wallpaper.jpg` / `lockscreen.jpg`. Le flag
            // `isDefault` est déjà validé en amont ; le nom de fichier est
            // identique dans les deux branches, on retourne directement.
            return $type . '.jpg';
        }

        $key = $this->ownerFilesystemKey($owner);
        if ($key === '') {
            throw new \InvalidArgumentException('Owner sans clé filesystem (name/login) définie.');
        }

        // Sanitize : remplace les caractères problématiques (espaces / /)
        $safeKey = preg_replace('/[^\p{L}\p{N}_\-\.]+/u', '_', $key) ?? $key;
        return $type . '@' . $safeKey . '.jpg';
    }

    private function ownerFilesystemKey(Model $owner): string
    {
        return match (true) {
            $owner instanceof User => (string) $owner->login,
            $owner instanceof UserGroup, $owner instanceof WorkstationGroup => (string) $owner->name,
            default => (string) ($owner->getAttribute('name') ?? $owner->getAttribute('login') ?? ''),
        };
    }

    private function ownerDisplayName(Model $owner): string
    {
        return match (true) {
            $owner instanceof User => (string) ($owner->login ?? $owner->getKey()),
            default => (string) ($owner->getAttribute('name') ?? $owner->getKey()),
        };
    }

    /**
     * Écriture atomique : tmp dans le même dir → rename.
     * Applique aussi le resize Imagick 1920×1080 + JPEG qualité 85.
     */
    private function writeAtomic(UploadedFile $file, string $targetPath): void
    {
        $dir = dirname($targetPath);
        $tmp = $dir . '/.' . basename($targetPath) . '.tmp-' . bin2hex(random_bytes(6));

        try {
            if (class_exists('Imagick')) {
                $imagick = new \Imagick($file->getRealPath());
                $imagick->resizeImage(1920, 1080, \Imagick::FILTER_QUADRATIC, 1, true);
                $imagick->setImageFormat('jpg');
                $imagick->setImageCompressionQuality(85);
                $imagick->writeImage($tmp);
                $imagick->destroy();
            } else {
                // Fallback : copie directe
                copy($file->getRealPath(), $tmp);
            }
            @chmod($tmp, 0644);
            if (! @rename($tmp, $targetPath)) {
                @unlink($tmp);
                throw new \RuntimeException("Échec rename {$tmp} → {$targetPath}");
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }

    private function assertValidType(string $type): void
    {
        if (! in_array($type, [Wallpaper::TYPE_WALLPAPER, Wallpaper::TYPE_LOCKSCREEN], true)) {
            throw new \InvalidArgumentException("Type invalide : {$type}");
        }
    }

    private function assertValidFile(UploadedFile $file): void
    {
        $max = (int) config('wallpapers.max_upload_size', 10_485_760);
        if ($file->getSize() > $max) {
            throw new \InvalidArgumentException("Fichier trop volumineux (> {$max} octets).");
        }
        $allowed = (array) config('wallpapers.allowed_extensions', ['jpg', 'jpeg', 'png']);
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($ext, $allowed, true)) {
            throw new \InvalidArgumentException("Extension non supportée : {$ext}");
        }

        // Post-review #5 : MIME check en complément de l'extension (qui est
        // client-controllable). Défense en profondeur avec Imagick en aval.
        $mime = (string) $file->getMimeType();
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp'];
        if (! in_array($mime, $allowedMimes, true)) {
            throw new \InvalidArgumentException("Type MIME non autorisé : {$mime}");
        }
    }
}
