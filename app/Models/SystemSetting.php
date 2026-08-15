<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Story 5.1c — Réglages système clé/valeur (JSON).
 *
 * Stocke des paramètres applicatifs globaux en pattern K/V JSON : réglages de
 * corbeille, emplacements des fichiers, politique de fichiers… Conçu pour être
 * extensible.
 *
 * ⚠️ **Un réglage stocké ici n'est PAS un réglage appliqué.** Cette table a porté
 * une grille de plafonds par défaut que personne ne lisait, pendant que l'écran
 * répondait « Réglages enregistrés » ; la story 63.4 l'a supprimée et a déplacé le
 * plafond là où la résolution le lit ({@see \App\Models\QuotaRule}). Avant d'ajouter
 * une clé ici, vérifier qu'elle a un LECTEUR.
 *
 * Helpers statiques :
 *   - SystemSetting::get('quota.trash', $default = null)
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
