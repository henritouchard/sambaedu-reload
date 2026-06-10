<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Asset de la bibliothèque de wallpapers — un fichier image physique,
 * dédupliqué par `checksum`, référencé par 0..n assignations {@see Wallpaper}.
 *
 * Le fichier vit sous `config('wallpapers.library_path')` (storage/, donc
 * sauvegardable). `absolutePath` reconstruit le chemin complet à la volée :
 * déplacer la bibliothèque = une ligne de config, sans toucher la DB.
 *
 * @property int $id
 * @property string $filename
 * @property string|null $original_name
 * @property string $checksum
 * @property int|null $byte_size
 * @property int|null $uploaded_by
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class WallpaperAsset extends Model
{
    use HasFactory;

    protected $table = 'wallpaper_assets';

    protected $fillable = [
        'filename',
        'original_name',
        'checksum',
        'byte_size',
        'uploaded_by',
    ];

    protected $casts = [
        'byte_size' => 'integer',
    ];

    /** Assignations (owner → asset) référençant cet asset. */
    public function wallpapers(): HasMany
    {
        return $this->hasMany(Wallpaper::class, 'asset_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Répertoire racine de la bibliothèque (storage/, configurable). */
    public static function libraryPath(): string
    {
        return rtrim((string) config('wallpapers.library_path', storage_path('app/wallpaper')), '/');
    }

    /**
     * Chemin absolu du fichier sur disque. Retourne '' si le `filename` est
     * vide ou malformé (séparateur de chemin) — défense contre un chemin qui
     * résoudrait vers un répertoire ou hors de la bibliothèque (review F7).
     */
    public function getAbsolutePathAttribute(): string
    {
        $filename = (string) $this->filename;
        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return '';
        }

        return self::libraryPath() . '/' . $filename;
    }
}
