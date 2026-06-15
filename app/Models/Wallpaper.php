<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StateMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Assignation wallpaper/lockscreen : (owner, type) → asset de bibliothèque.
 *
 * Le fichier image n'est plus porté ici (colonne `path` supprimée) mais par
 * {@see WallpaperAsset} via `asset_id` — un même asset peut être assigné à
 * plusieurs owners. Le chemin disque est délégué à `asset->absolutePath`.
 *
 * @property int $id
 * @property string $name
 * @property int|null $asset_id
 * @property string $type
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property bool $is_default
 * @property int|null $uploaded_by
 * @property \App\Enums\StateMode|null $mode Mode d'application desired-state (strict/default) — Story 27.1
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 * @property-read WallpaperAsset|null $asset
 */
class Wallpaper extends Model
{
    use HasFactory;

    public const TYPE_WALLPAPER = 'wallpaper';
    public const TYPE_LOCKSCREEN = 'lockscreen';

    protected $table = 'wallpapers';

    protected $fillable = [
        'name',
        'asset_id',
        'type',
        'owner_type',
        'owner_id',
        'is_default',
        'uploaded_by',
        'mode',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'mode' => StateMode::class,
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** Asset de bibliothèque (fichier image) référencé par cette assignation. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(WallpaperAsset::class, 'asset_id');
    }

    /** Chemin absolu du fichier, délégué à l'asset ('' si non assigné). */
    public function getAbsolutePathAttribute(): string
    {
        return $this->asset?->absolutePath ?? '';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeDefaults(Builder $query): Builder
    {
        return $query->where('is_default', true)->whereNull('owner_id');
    }
}
