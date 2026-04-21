<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property AppKind $app_kind
 * @property string|null $customizable_type
 * @property int|null $customizable_id
 * @property array<string,mixed> $policies_json
 * @property bool $is_default
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class AppCustomization extends Model
{
    use HasFactory;

    protected $table = 'app_customizations';

    protected $fillable = [
        'app_kind',
        'customizable_type',
        'customizable_id',
        'policies_json',
        'is_default',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'app_kind' => AppKind::class,
        'policies_json' => 'array',
        'is_default' => 'boolean',
    ];

    public function customizable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope : filtre par AppKind (enum ou alias string).
     */
    public function scopeOfKind(Builder $query, AppKind|string $kind): Builder
    {
        $value = $kind instanceof AppKind ? $kind->value : $kind;
        return $query->where('app_kind', $value);
    }

    /**
     * Scope : enregistrements par défaut établissement (scope global NULL/NULL).
     */
    public function scopeDefaults(Builder $query): Builder
    {
        return $query->whereNull('customizable_id')
            ->whereNull('customizable_type')
            ->where('is_default', true);
    }

    /**
     * Scope : filtre par scope concret (Model) ou global (null).
     */
    public function scopeForScope(Builder $query, ?Model $scope): Builder
    {
        if ($scope === null) {
            return $query->whereNull('customizable_id')
                ->whereNull('customizable_type');
        }

        return $query->where('customizable_type', $scope::class)
            ->where('customizable_id', $scope->getKey());
    }
}
