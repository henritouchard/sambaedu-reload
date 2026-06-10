<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Models\User;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Service d'upload + déduplication wallpaper.
 *
 * Refonte bibliothèque (2026-06) : l'image uploadée est normalisée (resize
 * 1920×1080 JPEG q85), content-addressée par checksum (`<sha256>.jpg`) et
 * stockée dans la bibliothèque `config('wallpapers.library_path')` (storage/).
 * Un asset identique n'est écrit qu'une fois (dédup) ; l'assignation
 * (owner, type) pointe sur l'asset via `asset_id`. Plus de convention de nom
 * `<type>@<key>.jpg` — le lien est explicite en DB.
 *
 * Écriture atomique (tmp + rename) pour éviter les lectures de JPG corrompu.
 */
class WallpaperUploadService
{
    public function __construct(
        private readonly WallpaperAssetCollector $collector = new WallpaperAssetCollector(),
    ) {
        // Protection contre les bombes pixel (post-review #10)
        WallpaperComposer::configureImagickLimits();
    }

    /**
     * Stocke un wallpaper/lockscreen et retourne l'assignation.
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

        $asset = $this->ingestAsset($file);

        return $this->upsertAssignment($asset, $type, $owner, $isDefault);
    }

    /**
     * Assigne un asset EXISTANT de la bibliothèque à (owner, type) — sans
     * réupload. Utilisé par le sélecteur de bibliothèque de l'UI.
     */
    public function assignExisting(
        WallpaperAsset $asset,
        string $type,
        ?Model $owner = null,
        bool $isDefault = false,
    ): Wallpaper {
        $this->assertValidType($type);
        if ($isDefault && $owner !== null) {
            throw new \InvalidArgumentException('isDefault et owner sont mutuellement exclusifs.');
        }

        return $this->upsertAssignment($asset, $type, $owner, $isDefault);
    }

    /**
     * Crée ou met à jour l'assignation (owner, type) → asset, et collecte
     * l'ancien asset s'il devient orphelin.
     */
    private function upsertAssignment(
        WallpaperAsset $asset,
        string $type,
        ?Model $owner,
        bool $isDefault,
    ): Wallpaper {
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

        // Pour un défaut étab, on AJOUTE `is_default` au WHERE afin de ne pas
        // matcher des rows orphans historiques (post-review #4).
        $matchCriteria = [
            'type' => $type,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
        ];
        if ($owner === null) {
            $matchCriteria['is_default'] = true;
        }

        // Asset précédemment assigné (pour GC si remplacement).
        $previousAssetId = Wallpaper::query()->where($matchCriteria)->value('asset_id');

        /** @var Wallpaper $wallpaper */
        $wallpaper = Wallpaper::updateOrCreate(
            $matchCriteria,
            [
                'name' => $displayName,
                'asset_id' => $asset->id,
                'is_default' => $owner === null && $isDefault,
                'uploaded_by' => $uploadedBy,
            ],
        );

        if ($previousAssetId !== null && (int) $previousAssetId !== $asset->id) {
            $this->collector->collectIfOrphan((int) $previousAssetId);
        }

        Log::info('[WallpaperUpload] assigned', [
            'id' => $wallpaper->id,
            'type' => $type,
            'owner' => $ownerType . ':' . $ownerId,
            'asset_id' => $asset->id,
        ]);

        return $wallpaper;
    }

    /**
     * Ajoute une image à la bibliothèque SANS l'assigner (flux UI
     * « sélectionner puis valider »). Normalise + déduplique, retourne l'asset.
     */
    public function ingest(UploadedFile $file): WallpaperAsset
    {
        $this->assertValidFile($file);

        return $this->ingestAsset($file);
    }

    /**
     * Normalise l'image et la déduplique en asset de bibliothèque.
     * Écrit le fichier `<checksum>.jpg` dans `library_path` s'il n'existe pas.
     */
    private function ingestAsset(UploadedFile $file): WallpaperAsset
    {
        $libraryDir = WallpaperAsset::libraryPath();
        if (! is_dir($libraryDir)) {
            if (! @mkdir($libraryDir, 0755, true) && ! is_dir($libraryDir)) {
                throw new \RuntimeException("Impossible de créer {$libraryDir}");
            }
        }

        // Normalisation vers un fichier temporaire dans la bibliothèque.
        $tmp = $libraryDir . '/.upload-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $this->normalizeTo($file, $tmp);

            $checksum = hash_file('sha256', $tmp);
            if ($checksum === false) {
                throw new \RuntimeException('Échec calcul checksum.');
            }
            $filename = $checksum . '.jpg';
            $target = $libraryDir . '/' . $filename;
            $size = filesize($tmp);
            $byteSize = $size !== false ? $size : null;

            if (is_file($target)) {
                // Contenu déjà présent : on jette le tmp, on réutilise l'asset.
                @unlink($tmp);
            } else {
                @chmod($tmp, 0644);
                if (! @rename($tmp, $target)) {
                    @unlink($tmp);
                    throw new \RuntimeException("Échec rename {$tmp} → {$target}");
                }
            }

            /** @var Authenticatable|null $user */
            $user = Auth::user();
            $authId = ($user !== null && method_exists($user, 'getAuthIdentifier'))
                ? $user->getAuthIdentifier()
                : null;
            $uploadedBy = is_int($authId) ? $authId : (is_numeric($authId) ? (int) $authId : null);

            try {
                return WallpaperAsset::firstOrCreate(
                    ['checksum' => $checksum],
                    [
                        'filename' => $filename,
                        'original_name' => $file->getClientOriginalName(),
                        'byte_size' => $byteSize,
                        'uploaded_by' => $uploadedBy,
                    ],
                );
            } catch (QueryException $e) {
                // Course entre deux uploads identiques simultanés : le 2e perd
                // la course d'INSERT (violation unique checksum). On récupère
                // l'asset créé par le gagnant (review F2).
                $existing = WallpaperAsset::query()->where('checksum', $checksum)->first();
                if ($existing !== null) {
                    return $existing;
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }

    /**
     * Resize Imagick 1920×1080 + JPEG qualité 85 vers $target (ou copie brute
     * si Imagick absent — fallback test).
     */
    private function normalizeTo(UploadedFile $file, string $target): void
    {
        if (class_exists('Imagick')) {
            $imagick = new \Imagick($file->getRealPath());
            $imagick->resizeImage(1920, 1080, \Imagick::FILTER_QUADRATIC, 1, true);
            $imagick->setImageFormat('jpg');
            $imagick->setImageCompressionQuality(85);
            $imagick->writeImage($target);
            $imagick->destroy();
        } else {
            copy($file->getRealPath(), $target);
        }
    }

    private function ownerDisplayName(Model $owner): string
    {
        return match (true) {
            $owner instanceof User => (string) ($owner->login ?? $owner->getKey()),
            default => (string) ($owner->getAttribute('name') ?? $owner->getKey()),
        };
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

        // MIME check en complément de l'extension (client-controllable) — post-review #5.
        $mime = (string) $file->getMimeType();
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp'];
        if (! in_array($mime, $allowedMimes, true)) {
            throw new \InvalidArgumentException("Type MIME non autorisé : {$mime}");
        }
    }
}
