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
 * @property string|null $source_url    URL Microsoft saisie (publique) — null si dépôt manuel
 * @property string $source             'url' (curl serveur) | 'upload' (fichier déposé)
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
        'source',
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

    /** Origine de l'ISO : téléchargement curl serveur. */
    public const SOURCE_URL = 'url';

    /** Origine de l'ISO : fichier déposé par l'admin (uploader chunké). */
    public const SOURCE_UPLOAD = 'upload';

    /**
     * L'ISO provient-elle d'un dépôt manuel (upload) plutôt que d'un
     * téléchargement curl depuis Microsoft ? Détermine si le Job saute la
     * phase `Downloading` (le fichier est déjà sur disque).
     */
    public function isUpload(): bool
    {
        return $this->source === self::SOURCE_UPLOAD;
    }

    /**
     * Relation vers l'admin qui a déclenché le téléchargement.
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /**
     * Numéro de version (sans le préfixe `Win`) — ex. `Win11` → `'11'`.
     *
     * Conservé comme accesseur pratique (affichage/historique). Le Job
     * d'extraction passe désormais la version complète (`Win10`/`Win11`) à
     * {@see \App\Ipxe\Iso\Services\WindowsIsoExtractor}.
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
