<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 25.6 — Outil de rendu du catalogue agent (D2, AC1).
 *
 * Une ligne = un outil portable posé par l'agent au bootstrap (aujourd'hui le
 * seul : Rainmeter, `key = 'rainmeter'`). Le SERVEUR est l'autorité du hash :
 * `sha256` est CALCULÉ à l'upload par {@see \App\Services\Agent\Tools\AgentToolService}
 * (`hash_file`, jamais un hash déclaré par le client), et l'agent le vérifie
 * AVANT extraction (D6) — remplace la constante Go `RainmeterToolChecksum`
 * figée de 27.1bis.
 *
 * Écrit UNIQUEMENT par {@see \App\Services\Agent\Tools\AgentToolService} (SEUL
 * écrivain — pattern `ReleaseCreationService`) : l'UI Livewire n'appelle jamais
 * `save()`/`updateOrCreate()` directement. Lu par
 * {@see \App\Services\Agent\Tools\AgentToolManifestService} (manifest servi à
 * l'agent) et {@see \App\Http\Controllers\Api\V1\Agent\ToolController} (serving
 * binaire du portable, déjà existant).
 *
 * `enabled` : toggle GLOBAL (D3) — au plus un outil par `key` (mono-version,
 * D5). Désactivé → no-op côté agent, jamais de désinstallation (D4).
 */
class AgentTool extends Model
{
    protected $fillable = [
        'key',
        'name',
        'filename',
        'sha256',
        'size',
        'enabled',
        'uploaded_at',
        'uploaded_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'size' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    /**
     * Admin ayant uploadé l'outil (traçabilité, nullable si compte supprimé).
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
