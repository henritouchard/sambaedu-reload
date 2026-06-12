<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 25.1 — Ring de distribution : UN WorkstationGroup existant → UNE
 * version cible (D6 × D1 : le ring n'est PAS une nouvelle entité, c'est la
 * réutilisation du concept pivot WorkstationGroup — salle physique OU parc
 * logique, indifféremment).
 *
 * `workstation_group_id` UNIQUE en base : un groupe ne pointe qu'une version
 * à la fois. L'`updated_at` EST la donnée de récence (décision n° 4) : si un
 * poste matche plusieurs rings, la ligne la plus récemment modifiée gagne
 * (+ warning `agent.release.ring_conflict`) — couvre le canari (ciblage lab
 * posé après le ciblage parc) comme le rollback (re-ciblage stable posé
 * après).
 *
 * Écrit UNIQUEMENT par
 * {@see \App\Services\Agent\Releases\ReleaseCreationService::target()}
 * (updateOrCreate + touch — l'UI 25.5 passera par le même service) ; lu par
 * {@see \App\Services\Agent\Releases\ReleaseManifestService}. Le canal agent
 * LIT les WorkstationGroups, n'y écrit jamais (frontière `agent_*`, AC5).
 */
class AgentReleaseRing extends Model
{
    protected $fillable = [
        'workstation_group_id',
        'agent_release_id',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(AgentRelease::class, 'agent_release_id');
    }

    public function workstationGroup(): BelongsTo
    {
        return $this->belongsTo(WorkstationGroup::class);
    }
}
