<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 8.1 — Modèle Eloquent d'une réservation DHCP.
 *
 * Source de vérité SER pour les réservations DHCP. L'export vers
 * `/etc/sambaedu/reservations.inc` est dérivé de cette table par
 * `DhcpService::exportReservationsFile()` à chaque mutation.
 *
 * Sources possibles (colonne `source`) :
 *  - `manual`           : créée depuis l'UI Livewire (cas courant).
 *  - `import`           : créée par import CSV (FR22).
 *  - `legacy-migration` : créée par l'étape 10 `/sync-from-ad`
 *                         (parsing one-shot `/etc/sambaedu/reservations.inc`).
 *
 * @property int          $id
 * @property string       $name
 * @property string       $mac          Format normalisé `xx:xx:xx:xx:xx:xx`
 * @property string       $ip           IPv4 (validation applicative)
 * @property int|null     $workstation_id
 * @property string|null  $description
 * @property string       $source       enum(manual, import, legacy-migration)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read Workstation|null $workstation
 */
class DhcpReservation extends Model
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_LEGACY_MIGRATION = 'legacy-migration';

    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_IMPORT,
        self::SOURCE_LEGACY_MIGRATION,
    ];

    protected $table = 'dhcp_reservations';

    protected $fillable = [
        'name',
        'mac',
        'ip',
        'workstation_id',
        'description',
        'source',
    ];

    protected $casts = [
        'workstation_id' => 'integer',
    ];

    /**
     * Story 8.1 — La réservation peut être liée à un poste de l'inventaire.
     * Lien optionnel (la suppression de la machine met `workstation_id` à
     * NULL — la réservation survit indépendamment).
     */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    /**
     * Scope par origine.
     */
    public function scopeBySource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    /**
     * Recherche les Workstation dont le `name` matche le nom d'une réservation
     * (utilisé pour le selector lien optionnel).
     */
    public function scopeMatchingWorkstationName(Builder $query, string $name): Builder
    {
        return $query->whereHas('workstation', function (Builder $q) use ($name) {
            $q->where('name', $name);
        });
    }
}
