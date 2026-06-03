<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Identité externe persistante d'un acteur fédéré (technicien flotte, hors-AD).
 *
 * Distincte de l'AD/LdapUser : JAMAIS écrite dans l'AD, JAMAIS synchronisée,
 * JAMAIS hard-delete (soft-delete) — cf. archi « identité persistante ≠ accès ».
 *
 * Upsert au login fédéré, clé = `external_sub` (claim `sub` du JWT, stable
 * côté IdP). Une reconnexion réutilise le même enregistrement.
 *
 * --------------------------------------------------------------------------
 * CYCLE DE VIE (Story 20.2 — 4 états + transitions)
 * --------------------------------------------------------------------------
 *
 *  1. **Active**       `is_active=true`, `deleted_at=null`, `anonymized_at=null`
 *                      → login autorisé (si JWT valide). État nominal au 1er
 *                        login et à chaque reconnexion.
 *
 *  2. **Désactivée**   `is_active=false`, `deleted_at=null`
 *                      → login refusé 403 (`identity_revoked`). L'identité est
 *                        CONSERVÉE ; un fresh login ne la réactive JAMAIS (la
 *                        réactivation est une action admin — Story 20.3).
 *                        Transition : `deactivate(reason)`.
 *
 *  3. **Soft-deletée** `deleted_at != null`
 *                      → login refusé 403 ; ligne conservée pour l'audit,
 *                        résolvable via `withTrashed()` (corrélation 20.4).
 *                        Transition : `softDeleteWithReason(reason)`.
 *
 *  4. **Anonymisée**   `anonymized_at != null` (+ soft-deletée + `is_active=false`)
 *                      → PII (`name`/`email`/`login`) vidée, `external_sub`
 *                        réécrit en `anon:<hmac-sha256>` (D-5). La ligne SURVIT (FK
 *                        `users.external_identity_id` + audit 20.4), n'est plus
 *                        une donnée personnelle (RGPD). État TERMINAL introduit
 *                        par 20.2 (anti-résurrection D-4 : une reconnexion sur
 *                        l'ancien `sub` ne matche plus et est refusée 403).
 *                        Transition : `anonymize()` (idempotente, jamais
 *                        hard-delete).
 *
 * Toutes les transitions sont portées par
 * {@see \App\Auth\Federated\ExternalIdentityLifecycleService}.
 *
 * @property int $id
 * @property string $external_sub
 * @property string $issuer
 * @property string|null $login
 * @property string|null $name
 * @property string|null $email
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $anonymized_at
 * @property string|null $deactivated_reason
 * @property string|null $deleted_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class ExternalIdentity extends Model
{
    use SoftDeletes;

    /** @var string */
    protected $table = 'external_identities';

    /** @var array<int,string> */
    protected $fillable = [
        'external_sub',
        'issuer',
        'login',
        'name',
        'email',
        'is_active',
        'last_login_at',
        'anonymized_at',
        'deactivated_reason',
        'deleted_reason',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'anonymized_at' => 'datetime',
    ];

    /**
     * Les utilisateurs Eloquent provisionnés pour cette identité externe
     * (D-4 : principal de session = App\Models\User marqué `source='federated'`).
     *
     * @return HasMany<User>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'external_identity_id');
    }

    /**
     * État « anonymisée » (Story 20.2) : la PII a été purgée en fin de
     * rétention. `anonymized_at` fait foi (garde d'idempotence D-5).
     */
    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    /**
     * Identités déjà anonymisées (PII purgée). État terminal.
     *
     * @param  Builder<ExternalIdentity>  $query
     * @return Builder<ExternalIdentity>
     */
    public function scopeAnonymized(Builder $query): Builder
    {
        return $query->whereNotNull('anonymized_at');
    }

    /**
     * Identités dont la rétention PII est EXPIRÉE et qui ne sont PAS encore
     * anonymisées : `last_login_at < now - ttlDays` (ou jamais connectée et
     * créée avant le seuil), `anonymized_at IS NULL`.
     *
     * Base de sélection de `federated:purge-identities` (Story 20.2 — D-6).
     * `withTrashed()` est laissé à l'appelant : une identité déjà soft-deletée
     * mais non anonymisée peut encore porter de la PII à purger.
     *
     * @param  Builder<ExternalIdentity>  $query
     * @return Builder<ExternalIdentity>
     */
    public function scopeRetentionExpired(Builder $query, int $ttlDays): Builder
    {
        $threshold = Carbon::now()->subDays(max(0, $ttlDays));

        return $query
            ->whereNull('anonymized_at')
            ->where(function (Builder $q) use ($threshold): void {
                $q->where('last_login_at', '<', $threshold)
                    ->orWhere(function (Builder $inner) use ($threshold): void {
                        // Identité jamais connectée : on se rabat sur la date de
                        // création comme dernière activité connue.
                        $inner->whereNull('last_login_at')
                            ->where('created_at', '<', $threshold);
                    });
            });
    }
}
