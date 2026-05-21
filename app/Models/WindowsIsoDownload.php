<?php

declare(strict_types=1);

namespace App\Models;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 3.6 — D9 / AC1.2.
 *
 * Modèle Eloquent d'une tentative de téléchargement d'ISO Windows depuis
 * la page admin SE5 `/admin/ipxe/iso-windows`.
 *
 * Le modèle est placé sous `App\Models\*` (cohérence `MachineBootLog`,
 * `Workstation`, etc. — l'app range les modèles de domaine ici plutôt que
 * sous le sous-namespace métier `App\Ipxe\Iso\*`). En revanche, l'enum
 * `WindowsIsoDownloadStatus` et le Job `DownloadWindowsIsoJob` vivent
 * sous `App\Ipxe\Iso\*` (frontière D1).
 *
 * @property int $id
 * @property string $version            'Win10' | 'Win11'
 * @property string $iso_name           'Win11_24H2.iso'
 * @property string $source_url         URL Microsoft saisie (publique)
 * @property WindowsIsoDownloadStatus $status
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int|null $exit_code
 * @property string|null $error
 * @property int|null $initiated_by_user_id  FK users — Q2 Henri 2026-05-21 :
 *                                        nullable + nullOnDelete (préserve
 *                                        l'audit trail si admin supprimé).
 * @property string|null $host_ip         IPv4/IPv6 — Opus-D : validé via
 *                                        FILTER_VALIDATE_IP côté orchestrator.
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class WindowsIsoDownload extends Model
{
    use HasFactory;

    protected $table = 'windows_iso_downloads';

    /** @var list<string> */
    protected $fillable = [
        'version',
        'iso_name',
        'source_url',
        'status',
        'started_at',
        'completed_at',
        'exit_code',
        'error',
        'initiated_by_user_id',
        'host_ip',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'status'       => WindowsIsoDownloadStatus::class,
    ];

    /**
     * Whitelist applicative des versions supportées (D9 — pas de CHECK DB
     * pour portabilité SQLite/Postgres).
     *
     * @var list<string>
     */
    public const VERSIONS = ['Win10', 'Win11'];

    /**
     * Relation vers l'admin qui a déclenché le téléchargement.
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /**
     * Numéro de version (sans le préfixe `Win`) — utilisé pour
     * `install-win-iso.sh <version_num> <iso_name>`.
     *
     * Exemple : `Win11` → `'11'`.
     */
    public function versionNum(): string
    {
        return str_replace('Win', '', $this->version);
    }

    /**
     * Le download est-il dans un état non-terminal (polling actif) ?
     */
    public function isRunning(): bool
    {
        return $this->status->isRunning();
    }

    /**
     * Le download est-il terminé (success | failed | cancelled) ?
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
