<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ring de distribution : UN WorkstationGroup existant → UNE
 * version cible (le ring n'est PAS une nouvelle entité, c'est la
 * réutilisation du concept pivot WorkstationGroup — salle physique OU parc
 * logique, indifféremment).
 *
 * `workstation_group_id` UNIQUE en base : un groupe ne pointe qu'une version
 * à la fois. L'`updated_at` EST la donnée de récence : si un
 * poste matche plusieurs rings, la ligne la plus récemment modifiée gagne
 * (+ warning `agent.release.ring_conflict`) — couvre le canari (ciblage lab
 * posé après le ciblage parc) comme le rollback (re-ciblage stable posé
 * après).
 *
 * Écrit UNIQUEMENT par
 * {@see \App\Services\Agent\Releases\ReleaseCreationService::target()}
 * (updateOrCreate + touch — l'UI passera par le même service) ; lu par
 * {@see \App\Services\Agent\Releases\ReleaseManifestService}. Le canal agent
 * LIT les WorkstationGroups, n'y écrit jamais (frontière `agent_*`).
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
