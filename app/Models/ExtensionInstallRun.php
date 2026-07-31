<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Extensions\ExtensionInstallService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 56.3 — L'ÉTAT d'une opération d'extension `app` lancée depuis l'UI.
 *
 * Une ligne par tentative (`install` / `update` / `remove`), écrite par
 * {@see \App\Services\Extensions\ExtensionOperationRunner} (création `pending`)
 * puis par {@see \App\Jobs\RunExtensionOperationJob} (passage `running`,
 * progression étape par étape, terminus `success` / `failed`).
 *
 * ⚠️ **Ce n'est PAS un journal d'audit.** `extension_audit_logs` répond à « qui
 * a décidé quoi » (append-only, conservé) ; cette table répond à « où en
 * est-on » (mutable, lue pendant les quelques minutes que dure une
 * installation). Le moteur {@see \App\Services\Extensions\ExtensionInstallService}
 * IGNORE totalement cette table : le seul pont est le callback `$onStep` que
 * lui passe le Job — c'est ce qui garde la CLI fonctionnelle sans run, et le
 * moteur testable sans base de runs.
 *
 * ## Staleness : on n'invente pas de janitor
 *
 * Un worker tué laisse un run `running` orphelin. On ne le « répare » pas (une
 * tâche de nettoyage serait de la sur-conception pour un cas rare) :
 * {@see self::isStale()} le fait afficher « interrompu » et cesser de bloquer
 * l'UI. L'unique arbitre réel de la concurrence reste le verrou du moteur, qui
 * expire seul au bout de 600 s. Un run stale N'EST JAMAIS retraité.
 *
 * ⚠️ La comparaison de fraîcheur se fait CÔTÉ PHP (`updated_at` vs `now()`),
 * jamais en SQL : les sessions PostgreSQL du projet sont en UTC alors que
 * l'application vit à Paris — un `now()` SQL décalerait le verdict de deux
 * heures (fiche mémoire « Fuseau session Postgres »).
 *
 * @property int $id
 * @property int $extension_id
 * @property string $operation
 * @property string $status
 * @property string $current_step
 * @property list<string>|null $steps
 * @property string $error
 * @property int|null $requested_by_user_id
 * @property string $requested_by_login
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Extension|null $extension
 */
class ExtensionInstallRun extends Model
{
    use HasFactory;

    protected $table = 'extension_install_runs';

    // ── Opérations ──────────────────────────────────────────────────────────
    //
    // Vocabulaire défini UNE SEULE FOIS, dans le moteur (ce sont exactement ses
    // trois méthodes publiques) et réexposé ici parce que c'est la valeur
    // persistée. Redéclarer les littéraux garantirait la dérive du jour où une
    // quatrième opération apparaîtra.
    public const OPERATION_INSTALL = ExtensionInstallService::OPERATION_INSTALL;

    public const OPERATION_UPDATE = ExtensionInstallService::OPERATION_UPDATE;

    public const OPERATION_REMOVE = ExtensionInstallService::OPERATION_REMOVE;

    /** @var list<string> */
    public const OPERATIONS = [
        self::OPERATION_INSTALL,
        self::OPERATION_UPDATE,
        self::OPERATION_REMOVE,
    ];

    // ── Statuts ─────────────────────────────────────────────────────────────
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    /**
     * Catégories d'échec TECHNIQUES du Job — les seules qui ne viennent pas du
     * moteur. Tout le reste de la colonne `error` est une catégorie du moteur
     * (déjà en français, déjà courte, déjà dépourvue d'URL et de secret).
     */
    public const ERROR_ENGINE_BUSY = 'engine_busy';

    public const ERROR_UNEXPECTED = 'unexpected';

    public const ERROR_EXTENSION_GONE = 'extension_gone';

    public const ERROR_INTERRUPTED = 'interrupted';

    /** Refus de CONTRAT du moteur, catégorisés (cf. ExtensionInstallException). */
    public const ERROR_UNKNOWN_EXTENSION = 'unknown_extension';

    public const ERROR_AMBIGUOUS_KEY = 'ambiguous_key';

    public const ERROR_UNKNOWN_SOURCE = 'unknown_source';

    public const ERROR_LINK_NOT_SUPPORTED = 'link_not_supported';

    /** @var list<string> */
    protected $fillable = [
        'extension_id',
        'operation',
        'status',
        'current_step',
        'steps',
        'error',
        'requested_by_user_id',
        'requested_by_login',
        'started_at',
        'finished_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'extension_id' => 'integer',
        'steps' => 'array',
        'requested_by_user_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        // Review 56.3 #3 — distingue un succès qui a AGI d'un no-op sur écran
        // périmé (AC5 : toast info, pas toast succès). Hors `$fillable` :
        // écrit uniquement par le Job, à la clôture du run.
        'changed' => 'boolean',
    ];

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_id');
    }

    // ========================================================================
    // État
    // ========================================================================

    /** Le run n'a pas atteint son terminus (`pending` ou `running`). */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    /**
     * Run ACTIF dont plus rien ne bouge depuis trop longtemps : le worker a été
     * tué, la file a été vidée, ou le Job a dépassé son timeout sans pouvoir
     * écrire son terminus.
     *
     * Un tel run est affiché « interrompu » et ne bloque plus l'UI — un worker
     * mort ne doit pas condamner la bibliothèque. Il n'est PAS retraité, et il
     * n'est pas non plus réécrit : la ligne dit ce qu'elle a vraiment observé.
     */
    public function isStale(int $timeoutSeconds): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $reference = $this->updated_at ?? $this->created_at;

        if ($reference === null) {
            return true;
        }

        return $reference->lt(now()->subSeconds(max(1, $timeoutSeconds)));
    }

    /** Les runs qui n'ont pas atteint leur terminus. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    // ========================================================================
    // Libellés (affichage — jamais de logique métier)
    // ========================================================================

    /** @return array<string, string> */
    public static function operationLabels(): array
    {
        return [
            self::OPERATION_INSTALL => 'Intégration',
            self::OPERATION_UPDATE => 'Mise à jour',
            self::OPERATION_REMOVE => 'Désinstallation',
        ];
    }

    public function operationLabel(): string
    {
        return self::operationLabels()[$this->operation] ?? $this->operation;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'En attente',
            self::STATUS_RUNNING => 'En cours',
            self::STATUS_SUCCESS => 'Terminée',
            self::STATUS_FAILED => 'En échec',
            default => $this->status,
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'badge-ghost',
            self::STATUS_RUNNING => 'badge-info',
            self::STATUS_SUCCESS => 'badge-success',
            self::STATUS_FAILED => 'badge-error',
            default => 'badge-ghost',
        };
    }

    /**
     * Traduit la catégorie d'échec en phrase lisible.
     *
     * Les catégories TECHNIQUES (celles du Job et les refus de contrat du
     * moteur) sont traduites ici ; toute autre valeur est déjà une catégorie
     * française produite par le moteur et s'affiche telle quelle. Aucune de ces
     * chaînes ne porte d'URL ni de secret — c'est la règle `last_error`.
     */
    public function errorLabel(): string
    {
        return match ($this->error) {
            '' => '',
            self::ERROR_ENGINE_BUSY => 'Une autre opération d\'extension occupait le moteur.',
            self::ERROR_UNEXPECTED => 'Erreur inattendue — consultez les journaux serveur.',
            self::ERROR_EXTENSION_GONE => 'L\'extension a disparu du registre avant l\'exécution.',
            self::ERROR_INTERRUPTED => 'Opération interrompue (worker arrêté ou délai dépassé).',
            self::ERROR_UNKNOWN_EXTENSION => 'Extension inconnue du registre.',
            self::ERROR_AMBIGUOUS_KEY => 'Cette clé est publiée par plusieurs sources — opération à mener en ligne de commande.',
            self::ERROR_UNKNOWN_SOURCE => 'La source ne publie plus cette extension.',
            self::ERROR_LINK_NOT_SUPPORTED => 'Cette extension est un lien : elle n\'installe aucun composant.',
            default => $this->error,
        };
    }
}
