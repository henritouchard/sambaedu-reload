<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 3.11 — D4 / D5 / D10 / D11.
 *
 * Intention de réinstallation OS armée par poste (poste unique ou fan-out
 * salle/groupe/multi-sélection). **Une ligne = un poste.**
 *
 * Table dédiée (jamais `Workstation::programmed_action`, réservé au suivi
 * post-install). Lue au boot par {@see \App\Ipxe\Services\IpxeService::resolveProgrammedAction()}
 * et déclenchée par le tick {@see \App\Services\Parc\WorkstationReinstallService::triggerDue()}.
 *
 * @property int $id
 * @property int $workstation_id
 * @property string $target_action Valeur enum IpxeAdminAction (whitelist install-only D9)
 * @property string $status armed|serving|installing|done|failed|canceled
 * @property int $boot_served_count
 * @property string|null $initiated_by user:<id> | group:<id>
 * @property int|null $created_by_user_id
 * @property \Carbon\CarbonInterface|null $scheduled_at null = immédiat
 * @property \Carbon\CarbonInterface|null $triggered_at reboot PXE forcé déclenché
 * @property \Carbon\CarbonInterface|null $boot_served_at
 * @property \Carbon\CarbonInterface|null $expires_at
 * @property \Carbon\CarbonInterface $created_at
 * @property \Carbon\CarbonInterface $updated_at
 */
class WorkstationReinstallRequest extends Model
{
    use HasFactory;

    protected $table = 'workstation_reinstall_requests';

    /** @var list<string> */
    protected $fillable = [
        'workstation_id',
        'target_action',
        'status',
        'boot_served_count',
        'initiated_by',
        'created_by_user_id',
        'scheduled_at',
        'triggered_at',
        'boot_served_at',
        'expires_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'boot_served_count' => 'integer',
        'scheduled_at' => 'datetime',
        'triggered_at' => 'datetime',
        'boot_served_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // Cycle de vie (D4).
    public const STATUS_ARMED = 'armed';
    public const STATUS_SERVING = 'serving';
    public const STATUS_INSTALLING = 'installing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    /**
     * Statuts terminaux : la requête ne sera plus servie ni déclenchée, et
     * n'est plus annulable.
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_DONE,
        self::STATUS_FAILED,
        self::STATUS_CANCELED,
    ];

    /**
     * Statuts « actifs » (non terminaux) : une requête dans cet état est
     * servie au boot (resolveProgrammedAction) et compte dans le plafond de
     * concurrence (D11).
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_ARMED,
        self::STATUS_SERVING,
        self::STATUS_INSTALLING,
    ];

    /**
     * Statuts « en vol » comptés pour le plafond de concurrence du tick
     * (D11). Une requête `armed` mais déjà déclenchée (`triggered_at`) est en
     * vol ; une requête `armed` pas encore déclenchée ne l'est pas (c'est un
     * candidat au déclenchement). En pratique `triggerDue()` filtre sur
     * `triggered_at`, donc on inclut ici tous les statuts actifs.
     *
     * @var list<string>
     */
    public const IN_FLIGHT_STATUSES = [
        self::STATUS_ARMED,
        self::STATUS_SERVING,
        self::STATUS_INSTALLING,
    ];

    /**
     * Libellés français des statuts, pour affichage. Le statut brut ne doit
     * jamais remonter dans l'UI.
     *
     * Ils sont rédigés pour **se suffire à eux-mêmes** : le badge de la fiche
     * poste les affiche seuls, sans préfixe « Réinstallation — » qui ferait
     * doublon (« Réinstallation — Installation en cours »).
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_ARMED => 'Réinstallation programmée',
        self::STATUS_SERVING => 'Réinstallation démarrée',
        self::STATUS_INSTALLING => 'Installation en cours',
        self::STATUS_DONE => 'Réinstallation terminée',
        self::STATUS_FAILED => 'Réinstallation échouée',
        self::STATUS_CANCELED => 'Réinstallation annulée',
    ];

    /**
     * Libellé français du statut courant (fallback sur le statut brut si une
     * valeur inattendue traîne en base).
     */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Une requête n'est annulable que tant que l'installeur n'a pas la main :
     * une fois en `installing`, le payload est délivré et la machine installe
     * — annuler côté serveur ne l'arrêterait pas, le bouton mentirait donc à
     * l'admin. Le sweep TTL reste le filet pour une install qui ne rapporte
     * jamais son OOBE.
     */
    public function isCancelable(): bool
    {
        return ! $this->isTerminal() && $this->status !== self::STATUS_INSTALLING;
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Requêtes actives (non terminales) — celles servies au boot et candidates
     * au déclenchement.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * TTL dépassé (garde anti-boucle D5). Une requête expirée doit passer
     * `failed` et libérer son slot de concurrence.
     */
    public function isExpired(?Carbon $now = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        $now ??= Carbon::now();

        return $this->expires_at->lessThan($now);
    }

    /**
     * Plafond de serves atteint (garde anti-boucle D5) : un poste qui échoue
     * en boucle au chargement kernel/initrd ne doit pas réinstaller indéfiniment.
     */
    public function hasExceededServeCap(): bool
    {
        $cap = (int) config('ipxe.reinstall.max_boot_serves', 8);

        return $cap > 0 && $this->boot_served_count >= $cap;
    }
}
