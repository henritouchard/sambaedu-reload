<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Entrée d'audit append-only de la transition de lien amont
 * `active → severed` (rupture du lien de management controlHub).
 *
 * Consigne CHAQUE rupture explicite du lien : le contrat concerné, l'origine du
 * signal (commande artisan / endpoint controlHub authentifié), l'acteur
 * dénormalisé, et un récapitulatif (items levés, apps conservées, valeurs
 * matérialisées). La trace est écrite DANS LA MÊME transaction que la transition
 * `link_state = severed` (atomicité acte ↔ trace —).
 *
 * Table append-only : toute tentative d'UPDATE lève une LogicException (calque
 * {@see CapabilityOverrideAuditLog} / {@see DelegationHistory}). La FK
 * `controlhub_contract_id` est `nullOnDelete` pour garder la ligne lisible après
 * suppression du contrat ; les colonnes dénormalisées (`actor_label`, `origin`,
 * `summary`) préservent la lisibilité.
 *
 * Patron MAISON (`QuotaAuditLog::log()` / `CapabilityOverrideAuditLog::log()`) —
 * Spatie activitylog n'est PAS une dépendance du projet.
 *
 * ⚠️ Convention de nommage : aucun mot « central ». Vocabulaire « amont » / `Upstream` /
 * `ControlHub*`.
 *
 * @property int $id
 * @property int|null $controlhub_contract_id
 * @property string $from_state         'active'
 * @property string $to_state           'severed'
 * @property string $origin             'command' | 'api'
 * @property string|null $actor_label
 * @property string|null $reason
 * @property array<string,mixed>|null $summary
 * @property \Carbon\Carbon $created_at
 */
class ControlHubLinkAuditLog extends Model
{
    /** @var string */
    protected $table = 'controlhub_link_audit_logs';

    /** Append-only : un seul created_at, pas d'updated_at. */
    public $timestamps = false;

    // Origines reconnues du signal de rupture (dérivées CÔTÉ SERVEUR).
    public const ORIGIN_COMMAND = 'command';
    public const ORIGIN_API = 'api';

    protected $fillable = [
        'controlhub_contract_id',
        'from_state',
        'to_state',
        'origin',
        'actor_label',
        'reason',
        'summary',
        'created_at',
    ];

    protected $casts = [
        'controlhub_contract_id' => 'integer',
        'summary' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Bloque tout UPDATE : la table est append-only.
     *
     * Les INSERT restent autorisés ($this->exists vaut false tant que la ligne
     * n'a pas été persistée). Une fois la ligne écrite, ->save() / ->update()
     * lève une LogicException.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException(
                'ControlHubLinkAuditLog est append-only — UPDATE interdit.'
            );
        }

        return parent::save($options);
    }

    /**
     * Consigne une transition de lien. Appelée DANS la transaction de la rupture
     * (atomicité acte ↔ trace —).
     *
     * @param  array<string,mixed>  $summary  récap (items_lifted, apps_preserved, values_materialized)
     */
    public static function log(
        ?int $contractId,
        string $fromState,
        string $toState,
        string $origin,
        ?string $actorLabel,
        ?string $reason,
        array $summary,
    ): self {
        return self::create([
            'controlhub_contract_id' => $contractId,
            'from_state' => $fromState,
            'to_state' => $toState,
            'origin' => $origin,
            'actor_label' => $actorLabel,
            'reason' => $reason,
            'summary' => $summary,
            'created_at' => now(),
        ]);
    }

    /** Le contrat amont rompu (peut être null si supprimé). */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ControlHubContract::class, 'controlhub_contract_id');
    }

    public function scopeForOrigin(Builder $query, string $origin): Builder
    {
        return $query->where('origin', $origin);
    }
}
