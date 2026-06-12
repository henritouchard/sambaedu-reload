<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 25.1 — Release publiée du binaire agent desired-state (D6, FR24).
 *
 * Une ligne = une version distribuable : `version` (unique, domaine fermé
 * validé en code — piège SQLite varchar), `hash` SHA-256 VÉRIFIÉ contre le
 * fichier réel à la création (impossible de publier un artefact incohérent),
 * `filename` du binaire dans `config('agent.releases_path')`. L'`url` du
 * manifest n'est PAS stockée : elle est calculée à la réponse
 * (`route('agent.v1.release.download')`, URL absolue — décision n° 2).
 *
 * Écrit UNIQUEMENT par {@see \App\Services\Agent\Releases\ReleaseCreationService}
 * (création vérifiée, swap stable transactionnel) ; lu par
 * {@see \App\Services\Agent\Releases\ReleaseManifestService} (résolution par
 * ring) et {@see \App\Http\Controllers\Api\V1\Agent\ReleaseController}
 * (serving binaire — seul un filename présent ici est servi).
 *
 * `is_stable` : version par défaut des postes sans ring — au plus une ligne
 * à true (invariant transactionnel du service, AC1/AC3).
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
     * Rings ciblant cette release (FK cascade : supprimés avec elle — AC3).
     */
    public function rings(): HasMany
    {
        return $this->hasMany(AgentReleaseRing::class);
    }
}
