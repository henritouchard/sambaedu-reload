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
 * Écrit par {@see \App\Services\Agent\Tools\AgentToolService} (écrivain
 * PRINCIPAL — pattern `ReleaseCreationService`) : l'UI Livewire n'appelle jamais
 * `save()`/`updateOrCreate()` directement. Story 39.4 — SECOND écrivain
 * en CRÉATION SEULE : {@see \App\Services\ControlHub\ArtifactPullService} peut
 * `updateOrCreate(['key' => ...])` un outil tiré du canal ④ amont, mais
 * UNIQUEMENT quand la clé est absente localement (précédence AC8 : le pull ne
 * remplace jamais une source locale). Tout observer/effet de bord censé être
 * exclusif à `AgentToolService` doit donc couvrir aussi ce chemin. Lu par
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
