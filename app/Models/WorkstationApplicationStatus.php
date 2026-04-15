<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Wireable;

/**
 * Statut d'installation d'une application sur un poste (rapport WPKG)
 *
 * @property int $id
 * @property int $workstation_id
 * @property int $application_id
 * @property string|null $installed_version
 * @property string $status installed, not-installed, error, upgrading, downgrading, unknown
 * @property bool $reboot_required
 * @property \Carbon\Carbon|null $reported_at
 */
class WorkstationApplicationStatus extends Model implements Wireable
{
    protected $table = 'workstation_application_status';

    protected $fillable = [
        'workstation_id',
        'application_id',
        'installed_version',
        'status',
        'reboot_required',
        'reported_at',
        'message',
    ];

    protected $casts = [
        'workstation_id' => 'integer',
        'application_id' => 'integer',
        'reboot_required' => 'boolean',
        'reported_at' => 'datetime',
    ];

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function scopeInstalled(Builder $query): Builder
    {
        return $query->where('status', 'installed');
    }

    public function scopeNotInstalled(Builder $query): Builder
    {
        return $query->where('status', 'not-installed');
    }

    public function scopeNeedsReboot(Builder $query): Builder
    {
        return $query->where('reboot_required', true);
    }

    public function isInstalled(): bool
    {
        return $this->status === 'installed';
    }

    public function needsReboot(): bool
    {
        return $this->reboot_required === true;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'installed' => 'Installe',
            'not-installed' => 'Non installe',
            'upgrading', 'downgrading' => 'En cours',
            'error' => 'Echec',
            default => $this->status ?? 'Inconnu',
        };
    }

    public function toLivewire(): array
    {
        return [
            'id' => $this->id,
        ];
    }

    public static function fromLivewire($value): static
    {
        return static::findOrFail($value['id']);
    }
}
