<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les paramètres globaux des quotas
 * 
 * @property int $id
 * @property string $partition /home ou /var/sambaedu
 * @property int $grace_period_days Période de grâce en jours
 * @property int $default_overage_percent Dépassement autorisé par défaut (%)
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class QuotaSetting extends Model implements Wireable
{
    protected $table = 'quota_settings';

    protected $fillable = [
        'partition',
        'grace_period_days',
        'default_overage_percent',
    ];

    protected $casts = [
        'grace_period_days' => 'integer',
        'default_overage_percent' => 'integer',
    ];

    /**
     * Récupère ou crée les paramètres pour une partition
     */
    public static function forPartition(string $partition): self
    {
        return self::firstOrCreate(
            ['partition' => $partition],
            [
                'grace_period_days' => 7,
                'default_overage_percent' => 20,
            ]
        );
    }

    /**
     * Calcule le quota hard à partir du soft et du pourcentage de dépassement
     */
    public function calculateHardQuota(int $softQuotaMb): int
    {
        if ($softQuotaMb === 0) {
            return 0;
        }
        return (int) round($softQuotaMb * (1 + $this->default_overage_percent / 100));
    }

    /**
     * Implémentation Wireable pour Livewire
     */
    public function toLivewire(): array
    {
        return $this->toArray();
    }

    public static function fromLivewire($value): static
    {
        if (is_array($value) && isset($value['id'])) {
            return static::find($value['id']) ?? new static($value);
        }
        return new static($value);
    }
}
