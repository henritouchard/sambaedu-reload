<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Story 34.1 — ligne du pivot polymorphe `network_share_assignables`.
 *
 * Modèle dédié (plutôt qu'un simple `MorphPivot`) pour exposer `assignments()`
 * comme un `hasMany` itérable côté {@see NetworkShareService} (chaque ligne
 * porte sa cible polymorphe + son `access`). Les types polymorphes AUTORISÉS
 * sont `User | UserGroup | WorkstationGroup` — validés applicativement
 * (cf. {@see NetworkShare::ALLOWED_ASSIGNABLE_TYPES}).
 *
 * @property int $id
 * @property int $network_share_id
 * @property int $assignable_id
 * @property string $assignable_type
 * @property string $access  `ro|rw`
 */
class NetworkShareAssignable extends Model
{
    public const ACCESS_RO = 'ro';
    public const ACCESS_RW = 'rw';

    protected $table = 'network_share_assignables';

    protected $fillable = [
        'network_share_id',
        'assignable_id',
        'assignable_type',
        'access',
    ];

    /**
     * Cible polymorphe : User | UserGroup | WorkstationGroup (FQCN stocké en
     * clair, iso `shortcut_assignables` — pas de morph map projet).
     */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    public function networkShare(): BelongsTo
    {
        return $this->belongsTo(NetworkShare::class, 'network_share_id');
    }

    /**
     * `true` si l'assignation accorde la lecture-écriture (POSIX `rwx`), `false`
     * pour la lecture seule (`rx`). Tout ce qui n'est pas `rw` = `ro` (sûr par
     * défaut).
     */
    public function isWritable(): bool
    {
        return $this->access === self::ACCESS_RW;
    }
}
