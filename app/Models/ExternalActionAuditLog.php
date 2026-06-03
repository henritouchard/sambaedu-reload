<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 20.4 — Journal d'audit DÉNORMALISÉ des actions externes (D-1/D-5/D-6).
 *
 * Calqué sur {@see QuotaAuditLog} (patron d'audit métier interrogeable du
 * projet) : table Eloquent, méthode-fabrique statique, casts, scopes,
 * `timestamps=false` + `occurred_at` posé à la main.
 *
 * Les 4 colonnes d'identité (`actor_login`, `actor_external_sub`,
 * `actor_name`, `actor_role`) sont COPIÉES au moment de l'action par
 * {@see \App\Http\Middleware\Auth\AuditExternalAction}. Elles restent la
 * SOURCE DE LECTURE même après que l'`ExternalIdentity` corrélée a été
 * soft-deletée puis anonymisée (Story 20.2). Les FK
 * `external_identity_id`/`user_id` sont best-effort `set null` et ne servent
 * JAMAIS la lecture (corrélation forensique optionnelle uniquement — D-5).
 *
 * @property int $id
 * @property string $actor_login
 * @property string|null $actor_external_sub
 * @property string|null $actor_name
 * @property string|null $actor_role
 * @property string $source
 * @property string $http_method
 * @property string|null $route_name
 * @property string $path
 * @property string|null $action_label
 * @property int $status_code
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property int|null $external_identity_id
 * @property int|null $user_id
 */
class ExternalActionAuditLog extends Model
{
    protected $table = 'external_action_audit_logs';

    /** Iso `QuotaAuditLog` : pas de created_at/updated_at, `occurred_at` manuel (D-6). */
    public $timestamps = false;

    /** Origine externe au MVP (discrimine vs AD locale — AC2). */
    public const SOURCE_FEDERATED = 'federated';

    protected $fillable = [
        'actor_login',
        'actor_external_sub',
        'actor_name',
        'actor_role',
        'source',
        'http_method',
        'route_name',
        'path',
        'action_label',
        'status_code',
        'occurred_at',
        'external_identity_id',
        'user_id',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'occurred_at' => 'datetime',
    ];

    /**
     * Relation best-effort vers l'identité externe (corrélation forensique).
     * NE PAS utiliser pour la lecture du journal : les valeurs lisibles sont
     * les colonnes dénormalisées `actor_*` (D-5).
     *
     * @return BelongsTo<ExternalIdentity, ExternalActionAuditLog>
     */
    public function externalIdentity(): BelongsTo
    {
        return $this->belongsTo(ExternalIdentity::class, 'external_identity_id');
    }

    /**
     * Relation best-effort vers le `User` fédéré (corrélation forensique).
     *
     * @return BelongsTo<User, ExternalActionAuditLog>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope : actions d'origine fédérée (externe). Au MVP toutes les lignes le
     * sont, mais le scope rend la requête explicite et extensible (Q-3).
     *
     * @param  Builder<ExternalActionAuditLog>  $query
     * @return Builder<ExternalActionAuditLog>
     */
    public function scopeFederated(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_FEDERATED);
    }

    /**
     * Scope : actions d'un acteur donné (par son login dénormalisé). Lit la
     * colonne COPIÉE, pas une jointure — reste correct après anonymisation.
     *
     * @param  Builder<ExternalActionAuditLog>  $query
     * @return Builder<ExternalActionAuditLog>
     */
    public function scopeForActor(Builder $query, string $login): Builder
    {
        return $query->where('actor_login', $login);
    }

    /**
     * Crée une ligne d'audit dénormalisée (iso `QuotaAuditLog::log()`).
     * `occurred_at` posé à la main (D-6). `source` par défaut `federated`.
     */
    public static function record(
        string $actorLogin,
        ?string $actorExternalSub,
        ?string $actorName,
        ?string $actorRole,
        string $httpMethod,
        string $path,
        int $statusCode,
        ?string $routeName = null,
        ?string $actionLabel = null,
        ?int $externalIdentityId = null,
        ?int $userId = null,
        string $source = self::SOURCE_FEDERATED,
    ): self {
        return self::create([
            'actor_login' => $actorLogin,
            'actor_external_sub' => $actorExternalSub,
            'actor_name' => $actorName,
            'actor_role' => $actorRole,
            'source' => $source,
            'http_method' => $httpMethod,
            'route_name' => $routeName,
            'path' => $path,
            'action_label' => $actionLabel,
            'status_code' => $statusCode,
            'occurred_at' => now(),
            'external_identity_id' => $externalIdentityId,
            'user_id' => $userId,
        ]);
    }
}
