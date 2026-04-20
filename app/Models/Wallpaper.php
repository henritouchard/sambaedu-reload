<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $name
 * @property string $path
 * @property string $type
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property bool $is_default
 * @property int|null $uploaded_by
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class Wallpaper extends Model
{
    use HasFactory;

    public const TYPE_WALLPAPER = 'wallpaper';
    public const TYPE_LOCKSCREEN = 'lockscreen';

    protected $table = 'wallpapers';

    protected $fillable = [
        'name',
        'path',
        'type',
        'owner_type',
        'owner_id',
        'is_default',
        'uploaded_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
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
