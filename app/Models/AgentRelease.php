<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Release publiée du binaire agent desired-state.
 *
 * Une ligne = une version distribuable : `version` (unique, domaine fermé
 * validé en code — piège SQLite varchar), `hash` SHA-256 VÉRIFIÉ contre le
 * fichier réel à la création (impossible de publier un artefact incohérent),
 * `filename` du binaire dans `config('agent.releases_path')`. L'`url` du
 * manifest n'est PAS stockée : elle est calculée à la réponse
 * (`route('agent.v1.release.download')`, URL absolue).
 *
 * Écrit UNIQUEMENT par {@see \App\Services\Agent\Releases\ReleaseCreationService}
 * (création vérifiée, swap stable transactionnel) ; lu par
 * {@see \App\Services\Agent\Releases\ReleaseManifestService} (résolution par
 * ring) et {@see \App\Http\Controllers\Api\V1\Agent\ReleaseController}
 * (serving binaire — seul un filename présent ici est servi).
 *
 * `is_stable` : version par défaut des postes sans ring — au plus une ligne
 * À true (invariant transactionnel du service).
 */
class AgentRelease extends Model
{
    protected $fillable = [
        'version',
        'hash',
        'filename',
        'is_stable',
    ];

    protected $casts = [
        'is_stable' => 'boolean',
    ];

    /**
     * Rings ciblant cette release (FK cascade : supprimés avec elle —).
     */
    public function rings(): HasMany
    {
        return $this->hasMany(AgentReleaseRing::class);
    }
}
