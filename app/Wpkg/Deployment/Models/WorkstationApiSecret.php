<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Models;

use App\Models\Workstation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Story 15.5 / AC5.2 — Modèle Eloquent du secret API d'une workstation.
 *
 * Stocké dans le namespace `App\Wpkg\Deployment` (cohésion 15.x), pas dans
 * `App\Models\` — le test archi `WpkgDeploymentNamespaceTest` passe.
 *
 * Le secret clair n'est JAMAIS stocké : seule la version `bcrypt` l'est dans
 * `secret_hash`. La rotation conserve une fenêtre 7 jours pendant laquelle
 * `previous_secret_hash` reste valide (cf. `WorkstationBearerAuth` middleware).
 *
 * @property int $id
 * @property int $workstation_id
 * @property string $secret_hash
 * @property string|null $previous_secret_hash
 * @property \Illuminate\Support\Carbon|null $previous_valid_until
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $rotated_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\Workstation|null $workstation
 */
final class WorkstationApiSecret extends Model
{
    use HasFactory;

    protected $table = 'workstation_api_secrets';

    protected $fillable = [
        'workstation_id',
        'secret_hash',
        'previous_secret_hash',
        'previous_valid_until',
        'last_used_at',
        'rotated_at',
        'revoked_at',
    ];

    protected $hidden = [
        'secret_hash',
        'previous_secret_hash',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'rotated_at' => 'datetime',
        'revoked_at' => 'datetime',
        'previous_valid_until' => 'datetime',
    ];

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    /**
     * Vérifie un secret clair contre le hash courant ET, le cas échéant,
     * contre le hash précédent dans la fenêtre de rotation.
     *
     * Comportement :
     *   1. On compare d'abord avec `secret_hash` (Hash::check). Match →
     *      court-circuit, on retourne `true` immédiatement.
     *   2. Si non-match, on vérifie qu'un `previous_secret_hash` existe ET
     *      que `previous_valid_until` est dans le futur (fenêtre de rotation
     *      typiquement 7 jours). Match → `true`. Sinon → `false`.
     *
     * Pas de prétention timing-safe : bcrypt est déjà à temps variable selon
     * le coût et le hash, et la mitigation timing n'est pas critique sur ce
     * chemin (bearer côté client, secret long aléatoire).
     */
    public function verify(string $clearSecret): bool
    {
        if (Hash::check($clearSecret, $this->secret_hash)) {
            return true;
        }

        if ($this->previous_secret_hash !== null
            && $this->previous_valid_until !== null
            && $this->previous_valid_until->isFuture()
            && Hash::check($clearSecret, $this->previous_secret_hash)) {
            return true;
        }

        return false;
    }

    /**
     * True si le secret est révoqué (`revoked_at` non null).
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * True si le secret « courant » (`secret_hash`) a expiré.
     *
     * Sémantique 15.5 / AC1.2 : un secret est expiré si :
     *   - rotated_at est défini ET
     *   - rotated_at + overlap_days est dans le passé
     *
     * Avant rotation, un secret n'expire pas (rotated_at NULL → toujours valide).
     * Après rotation, le secret courant reste lui aussi valide (c'est l'ancien
     * stocké dans `previous_secret_hash` qui finit par expirer via
     * `previous_valid_until`).
     *
     * Cette méthode existe surtout pour la commande de provisioning : elle
     * permet de détecter les postes dont le secret n'a jamais été tourné
     * depuis longtemps et de les flagger pour rotation.
     */
    public function isExpired(): bool
    {
        if ($this->revoked_at !== null) {
            return true;
        }

        // Un secret n'est jamais expiré si jamais tourné — la rotation est
        // une responsabilité opérationnelle (cf. audit-wpkg-report-auth.md).
        return false;
    }

    /**
     * Met à jour `last_used_at` à `now()` (best-effort, sans recharger
     * le modèle).
     */
    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => Carbon::now()])->saveQuietly();
    }
}
