<?php

declare(strict_types=1);

namespace App\Services\Agent\Releases;

use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\Workstation;
use Illuminate\Support\Facades\Log;

/**
 * Résolution du manifest de release pour un poste.
 *
 * Règle de résolution (machine-only, PAS de TargetContext — il est fait pour
 * la compilation d'état (poste, user) ; ici règle de récence dédiée) :
 *
 *  1. rings des WorkstationGroups du poste (pivot global, salles +
 *     parcs confondus) — s'il y en a PLUSIEURS, la ligne ring la plus
 *     récemment modifiée (`updated_at`) gagne + warning
 *     `agent.release.ring_conflict` : la règle la plus récente gagne.
 *     Couvre canari (ciblage lab posé après le parc) et
 *     rollback (re-ciblage stable posé après) ;
 *  2. aucun ring → version stable (`is_stable = true`) — jamais une canari
 * par accident ;
 *  3. ni ring ni stable → null (le controller répond 404 `no_release`).
 *
 * Retourne le contrat wire `{version, hash, url}` — `url` ABSOLUE construite
 * à la réponse via `route('agent.v1.release.download')` : jamais de chemin
 * relatif, jamais d'URL figée en DB.
 *
 * Lecture seule Postgres, zéro AD (critère Keycloak). Ce service est
 * appelé à chaque check-in — résolution en O(2 requêtes) (rings des groupes
 * du poste + fallback stable), le log nominal vit en debug côté controller.
 */
class ReleaseManifestService
{
    /**
     * Manifest applicable au poste, ou null si aucune release ne le couvre.
     *
     * @return array{version: string, hash: string, url: string}|null
     */
    public function manifestFor(Workstation $workstation): ?array
    {
        // orderByDesc('id') : déterminisme du fallback même si l'invariant
        // single-stable est cassé par une écriture concurrente : la plus
        // récente gagne, jamais un aléa d'ordre de lecture.
        $release = $this->resolveRingRelease($workstation)
            ?? AgentRelease::query()->where('is_stable', true)->orderByDesc('id')->first();

        if ($release === null) {
            return null;
        }

        return [
            'version' => $release->version,
            'hash' => $release->hash,
            'url' => route('agent.v1.release.download', ['filename' => $release->filename]),
        ];
    }

    /**
     * Manifest de la version STABLE, sans aucun poste résolu — chemin
     * d'amorçage LAN. L'appelant (script GPO / WinPE) n'a
     * pas de token, donc aucun ring à résoudre : on sert TOUJOURS la stable
     * (`is_stable`), jamais une canari (un poste sans ring retombe déjà sur la
     * stable côté {@see manifestFor()} — ici on court-circuite la résolution
     * par ring qui exigerait un Workstation).
     *
     * Même tie-break que {@see manifestFor()} (`orderByDesc('id')` —
     * déterminisme si l'invariant single-stable est cassé
     * par une écriture concurrente). Null si aucune stable publiée → le
     * controller répond 404 `no_release`.
     *
     * @return array{version: string, hash: string, url: string}|null
     */
    public function stableManifest(): ?array
    {
        $release = AgentRelease::query()
            ->where('is_stable', true)
            ->orderByDesc('id')
            ->first();

        if ($release === null) {
            return null;
        }

        return [
            'version' => $release->version,
            'hash' => $release->hash,
            // URL ABSOLUE de l'endpoint d'amorçage — jamais un chemin
            // relatif ni figé en DB. URL FIXE : le binaire
            // stable est résolu côté serveur, le filename ne transite pas.
            'url' => route('agent.v1.stable.download'),
        ];
    }

    /**
     * Release ciblée par les rings du poste — récence puis
     * tie-break id desc (déterminisme : les timestamps SQLite/PG peuvent
     * coïncider à la seconde). Un ring orphelin (release manquante — état
     * transitoire impossible avec les FK cascade, défensif quand même) est
     * ignoré : le poste retombe sur le candidat suivant puis la stable.
     */
    private function resolveRingRelease(Workstation $workstation): ?AgentRelease
    {
        $groupIds = $workstation->groups()->pluck('workstation_groups.id')->all();
        if ($groupIds === []) {
            return null;
        }

        $rings = AgentReleaseRing::query()
            ->with('release')
            ->whereIn('workstation_group_id', $groupIds)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        // Conflit = ambiguïté RÉELLE : plusieurs rings pointant la MÊME
        // release (salle + parc alignés) ne warnent pas — sinon le canal
        // warning est pollué à chaque check-in du cas banal.
        if ($rings->pluck('agent_release_id')->unique()->count() > 1) {
            Log::channel('agent')->warning('[ReleaseManifestService] agent.release.ring_conflict', [
                'action_type' => 'agent.release.ring_conflict',
                'workstation_id' => $workstation->id,
                'group_ids' => $rings->pluck('workstation_group_id')->all(),
            ]);
        }

        $winner = $rings->first(
            fn (AgentReleaseRing $ring): bool => $ring->release !== null,
        );

        return $winner?->release;
    }
}
