<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Story 54.2 (FR36 socle) — Entrée d'audit append-only du cycle de vie d'une
 * extension.
 *
 * Écrite EXCLUSIVEMENT par {@see \App\Services\Extensions\ExtensionLifecycleService}
 * (seul écrivain de `extensions.status` du projet), DANS LA MÊME transaction
 * que la mutation de `status` (atomicité acte ↔ trace). Une transition sans sa
 * ligne d'audit ne peut pas exister ; un no-op (état déjà atteint) n'écrit
 * AUCUNE ligne — le journal trace des transitions réelles, pas des clics.
 *
 * Table append-only : toute tentative d'UPDATE lève une LogicException (calque
 * {@see CapabilityOverrideAuditLog} 29.5 / {@see ControlHubLinkAuditLog} 32.1).
 * Les FK sont `nullOnDelete` pour garder la ligne lisible après suppression des
 * entités référencées ; les colonnes dénormalisées (`extension_key`,
 * `extension_name`, `actor_login`) préservent la lisibilité.
 *
 * Patron MAISON (`QuotaAuditLog::log()` lineage) — Spatie activitylog n'est
 * PAS une dépendance du projet.
 *
 * ⚠️ GARDE-FOU : aucun mot « central ». Vocabulaire « amont » / `Upstream`.
 *
 * ## Story 56.1 — événements de SOURCE (FR36)
 *
 * La table est ÉTENDUE, pas doublée : `action` est un string libre
 * EXPRESSÉMENT prévu extensible (docblock de la migration 54.2). Les actes
 * d'administration d'une source (`source_add`, `source_enable`,
 * `source_disable`, `source_remove`) et l'échec de vérification d'un catalogue
 * (`source_sync_failed`) s'écrivent par la fabrique dédiée {@see self::logSource()},
 * avec `extension_id = null` et `extension_key` / `extension_name` à `''` — la
 * cible est la SOURCE, pas une extension.
 *
 * Même discipline que 54.2 : la trace est écrite DANS la transaction de l'acte,
 * un no-op (désactiver une source déjà désactivée) n'écrit AUCUNE ligne, et
 * `source_sync_failed` n'est consigné qu'à la **transition** vers l'état
 * d'erreur — un re-échec quotidien de la synchro planifiée n'empile pas de
 * lignes. Une synchro RÉUSSIE n'est pas auditée (c'est de la télémétrie :
 * `last_synced_at` la porte), un dépôt injoignable non plus (transitoire
 * réseau, porté par `sync_status`).
 *
 * @property int $id
 * @property int|null $extension_id
 * @property string $extension_key
 * @property string $extension_name
 * @property int|null $extension_source_id
 * @property string $source_key
 * @property string $action           'integrate' | 'uninstall' | 'source_*' | 'install' | 'remove' | 'install_failed' (string libre)
 * @property string $details          catégorie COURTE d'échec — jamais d'URL, jamais de secret (56.2)
 * @property int|null $actor_user_id
 * @property string|null $actor_login
 * @property \Carbon\Carbon $created_at
 * @property-read Extension|null $extension
 * @property-read ExtensionSource|null $source
 * @property-read User|null $actor
 */
class ExtensionAuditLog extends Model
{
    /** @var string */
    protected $table = 'extension_audit_logs';

    /** Append-only : un seul created_at, pas d'updated_at. */
    public $timestamps = false;

    // Actions reconnues — dérivées CÔTÉ SERVEUR (jamais du flag client).
    // String libre en base (pas d'enum() DB) : l'Epic 56 étend sans migration.
    public const ACTION_INTEGRATE = 'integrate';
    public const ACTION_UNINSTALL = 'uninstall';

    // Story 56.1 — actes d'administration d'une SOURCE (fabrique `logSource()`).
    public const ACTION_SOURCE_ADD = 'source_add';
    public const ACTION_SOURCE_ENABLE = 'source_enable';
    public const ACTION_SOURCE_DISABLE = 'source_disable';
    public const ACTION_SOURCE_REMOVE = 'source_remove';

    /** Échec de VÉRIFICATION d'un catalogue distant — consigné à la TRANSITION seulement. */
    public const ACTION_SOURCE_SYNC_FAILED = 'source_sync_failed';

    // Story 56.2 — cycle d'une extension `app` (moteur `ext:install` / `ext:remove`).
    public const ACTION_INSTALL = 'install';
    public const ACTION_REMOVE = 'remove';

    /**
     * Échec d'une TENTATIVE d'installation — signature/hash refusé, source
     * inexploitable, ou échec à une étape privilégiée.
     *
     * ⚠️ Contrairement à `source_sync_failed`, il n'y a PAS de dédoublonnage à
     * la transition : une synchro est une tâche planifiée qui se répète toute
     * seule, une installation est un ACTE de l'opérateur. Chaque tentative
     * mérite sa ligne (décision 56.2 #7).
     */
    public const ACTION_INSTALL_FAILED = 'install_failed';

    /**
     * Story 56.3 — mise à jour d'une extension `app` déjà installée
     * (`ext:update` / bouton « Mettre à jour »).
     *
     * Acte à part entière, distinct d'`install` : ce qu'il change, c'est la
     * VERSION qui tourne, pas la présence de l'extension. Les confondre
     * rendrait le journal incapable de répondre à « depuis quand tourne-t-on
     * cette version-là ». Même doctrine d'échec qu'`install_failed` : une ligne
     * PAR TENTATIVE, catégorie courte, jamais d'URL ni de secret.
     */
    public const ACTION_UPDATE = 'update';

    public const ACTION_UPDATE_FAILED = 'update_failed';

    /** Acteur conventionnel d'une synchro planifiée (aucun utilisateur connecté). */
    public const ACTOR_SYSTEM = 'system';

    /** Borne de la colonne `details` (alignée sur la migration 56.2). */
    public const DETAILS_MAX = 500;

    protected $fillable = [
        'extension_id',
        'extension_key',
        'extension_name',
        'extension_source_id',
        'source_key',
        'action',
        'details',
        'actor_user_id',
        'actor_login',
        'created_at',
    ];

    protected $casts = [
        'extension_id' => 'integer',
        'extension_source_id' => 'integer',
        'actor_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    // ========================================================================
    // APPEND-ONLY GUARD (calque CapabilityOverrideAuditLog / ControlHubLinkAuditLog)
    // ========================================================================

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
                'ExtensionAuditLog est append-only — UPDATE interdit.'
            );
        }

        return parent::save($options);
    }

    // ========================================================================
    // FABRIQUE (calque CapabilityOverrideAuditLog::log)
    // ========================================================================

    /**
     * Consigne une transition du cycle de vie. Appelée DANS la transaction de
     * la mutation `status` (atomicité acte ↔ trace).
     *
     * Story 56.2 — signature ÉLARGIE, jamais dupliquée : `$details` est
     * optionnel (`''` pour les actes 54.2, une CATÉGORIE courte pour un
     * `install_failed`) et `$actorUserId` accepte `null` avec
     * `$actorLogin = self::ACTOR_SYSTEM` pour l'acteur CLI. Les appels 54.2
     * existants continuent de passer un `int` et restent valides verbatim — on
     * ne crée pas une seconde fabrique là où un paramètre par défaut suffit.
     *
     * ⚠️ `$details` ne doit JAMAIS porter d'URL ni de secret (règle `last_error`
     * de 56.1) : le journal d'audit est lisible par tout admin, et une URL de
     * dépôt peut porter un jeton. Le détail complet va dans `Log::`.
     */
    public static function log(
        ?int $extensionId,
        string $extensionKey,
        string $extensionName,
        string $action,
        ?int $actorUserId,
        ?string $actorLogin,
        string $details = '',
    ): self {
        return self::create([
            'extension_id' => $extensionId,
            'extension_key' => $extensionKey,
            'extension_name' => $extensionName,
            'action' => $action,
            'details' => Str::limit($details, self::DETAILS_MAX, ''),
            'actor_user_id' => $actorUserId,
            'actor_login' => $actorLogin,
            'created_at' => now(),
        ]);
    }

    /**
     * Story 56.1 — Consigne un acte portant sur une SOURCE (ajout, activation,
     * désactivation, retrait, échec de vérification du catalogue).
     *
     * Appelée DANS la transaction de l'acte (atomicité acte ↔ trace). Les
     * colonnes d'extension restent VIDES : la cible est la source. `source_key`
     * est dénormalisée pour que la trace d'un RETRAIT reste lisible une fois la
     * ligne `extension_sources` disparue (la FK passe alors à `null`).
     *
     * `$actorLogin` vaut `'system'` pour une synchro planifiée (aucun
     * utilisateur connecté), avec `$actorUserId = null`.
     */
    public static function logSource(
        ?int $sourceId,
        string $sourceKey,
        string $action,
        ?int $actorUserId,
        ?string $actorLogin,
    ): self {
        return self::create([
            'extension_id' => null,
            'extension_key' => '',
            'extension_name' => '',
            'extension_source_id' => $sourceId,
            'source_key' => $sourceKey,
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'actor_login' => $actorLogin,
            'created_at' => now(),
        ]);
    }

    // ========================================================================
    // RELATIONS
    // ========================================================================

    /** La source concernée (peut être null si retirée du registre). */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ExtensionSource::class, 'extension_source_id');
    }

    /** L'extension concernée (peut être null si supprimée du registre). */
    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_id');
    }

    /** L'acteur de la transition (peut être null si supprimé). */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeForExtension(Builder $query, int $extensionId): Builder
    {
        return $query->where('extension_id', $extensionId);
    }
}
