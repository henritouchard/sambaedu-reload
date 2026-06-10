<?php

declare(strict_types=1);

namespace App\Models;

use App\Dto\Overlay\OverlayAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Signal overlay « posté » (canal push→pull).
 *
 * Un producteur enregistre une ligne ; elle est renvoyée à chaque poll tant
 * qu'elle est active, puis disparaît à `expires_at`. Le ciblage utilise des
 * jokers : `workstation_uuid` / `user_login` null = « tout ».
 *
 * @property int $id
 * @property string $kind
 * @property string $severity
 * @property string $title
 * @property string $text
 * @property string|null $workstation_uuid
 * @property string|null $user_login
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class OverlaySignal extends Model
{
    protected $table = 'overlay_signals';

    protected $fillable = [
        'kind',
        'severity',
        'title',
        'text',
        'workstation_uuid',
        'user_login',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Restreint aux signaux actifs pour un poste + user donnés.
     *
     * Match si :
     *  - (workstation_uuid null OU = $uuid) ET
     *  - (user_login null OU = $login) ET
     *  - (expires_at null OU > maintenant).
     *
     * @param  Builder<OverlaySignal>  $query
     * @return Builder<OverlaySignal>
     */
    public function scopeActiveFor(Builder $query, string $uuid, string $login): Builder
    {
        return $query
            ->where(function (Builder $w) use ($uuid): void {
                $w->whereNull('workstation_uuid')->orWhere('workstation_uuid', $uuid);
            })
            ->where(function (Builder $w) use ($login): void {
                // Login vide = pas de session → uniquement les signaux joker
                // (user_login null). Évite qu'un user_login='' fasse fuiter un
                // signal vers tout poste sans session (review finding F).
                $w->whereNull('user_login');
                if ($login !== '') {
                    $w->orWhere('user_login', $login);
                }
            })
            ->where(function (Builder $w): void {
                $w->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id');
    }

    /**
     * Projette le signal stocké en alerte de payload (`source = posted`).
     */
    public function toAlert(): OverlayAlert
    {
        return new OverlayAlert(
            id: 'sig-' . $this->id,
            source: OverlayAlert::SOURCE_POSTED,
            kind: (string) $this->kind,
            severity: (string) $this->severity,
            title: (string) $this->title,
            text: (string) $this->text,
            // UTC explicite — cohérence cross-champ (review finding M).
            expiresAt: $this->expires_at?->copy()->utc()->toIso8601String(),
        );
    }
}
