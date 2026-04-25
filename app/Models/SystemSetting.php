<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Story 5.1c — Réglages système clé/valeur (JSON).
 *
 * Stocke des paramètres applicatifs globaux en pattern K/V JSON. Première
 * utilisation : onglet "Quotas & FS" de /admin/settings (defaults profils,
 * TTL trash, toggle purge auto). Conçu pour être extensible (futurs onglets
 * DHCP/CUPS/...).
 *
 * Helpers statiques :
 *   - SystemSetting::get('quota.defaults', $default = null)
 *   - SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false])
 *
 * Le cast `value => 'array'` normalise pgsql/sqlite (cf. migration).
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SystemSetting extends Model
{
    protected $table = 'system_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Lit un setting. Retourne `$default` si la clé n'existe pas.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        if ($setting === null) {
            return $default;
        }

        return $setting->value ?? $default;
    }

    /**
     * Persiste une valeur (upsert sur la clé). La valeur est stockée en JSON
     * grâce au cast `array` ci-dessus — accepte tout type sérialisable.
     */
    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    /**
     * Supprime un setting (sortie de cache / reset).
     */
    public static function forget(string $key): void
    {
        static::query()->where('key', $key)->delete();
    }
}
