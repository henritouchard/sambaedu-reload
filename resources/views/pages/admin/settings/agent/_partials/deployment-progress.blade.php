<?php

use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\Workstation;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 25.5 — Surface progression du déploiement (AC1, AC4).
 *
 * LECTURE SEULE : agrège, par ring, la version CIBLÉE
 * (`agent_release_rings.release.version`) vs les versions RAPPORTÉES par les
 * postes (`workstations.agent_reported_version`, persistée par la greffe report
 * 25.5). Montre l'avancée de la canari (1 poste → 1 salle → parc) : combien de
 * postes sont à jour / en retard / jamais vus, et la fraîcheur de la donnée.
 *
 * **Ring EFFECTIF (pas multi-comptage)** : un poste appartient typiquement à un
 * groupe physique ET 1-2 groupes logiques (pivot global 4.11), donc à plusieurs
 * rings. Mais le manifest ne lui sert qu'UNE version. On l'attribue donc à un
 * seul ring — celui qui gouverne réellement sa version cible = le plus
 * récemment ciblé parmi ses groupes (récence, FR4 « la plus récente gagne »,
 * iso {@see \App\Services\Agent\Releases\ReleaseManifestService::resolveRingRelease()}).
 * Sans ce dédoublonnage, un poste compté dans chaque ring apparaîtrait « en
 * retard » dans les rings qui ne le servent pas.
 *
 * Jointure lecture seule `agent_release_rings × workstation_group_workstation
 * × workstations` via les relations Eloquent, résolue EN MÉMOIRE (zéro N+1).
 * Zéro AD (aucun LdapRecord/Kerberos/samba-tool), zéro écriture — la frontière
 * `agent_*` est respectée, aucun Gate (lecture, l'accès page `can:server.admin`
 * suffit). Pas de pagination : le nombre de rings est borné (un ring = un
 * groupe ciblé), l'agrégation se fait par comptage, pas par liste de postes.
 */
return new class extends Component {
    #[Computed]
    public function rings()
    {
        // Rings ciblés, du plus récent au plus ancien : c'est À LA FOIS l'ordre
        // d'affichage ET l'ordre de résolution du ring effectif (récence FR4 —
        // iso ReleaseManifestService::resolveRingRelease, tie-break id desc).
        $rings = AgentReleaseRing::query()
            ->with(['release', 'workstationGroup'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        // Compteurs par ring, init à zéro (préserve l'ordre d'affichage).
        $stats = [];
        foreach ($rings as $ring) {
            $stats[$ring->id] = [
                'id' => $ring->id,
                'group' => $ring->workstationGroup?->name ?? '—',
                'is_physical' => (bool) ($ring->workstationGroup?->is_physical ?? true),
                'target_version' => $ring->release?->version,
                'total' => 0,
                'up_to_date' => 0,
                'behind' => 0,
                'never_seen' => 0,
                'last_report_at' => null,
            ];
        }

        $targetedGroupIds = $rings->pluck('workstation_group_id')->unique()->all();
        if ($targetedGroupIds === []) {
            return collect(array_values($stats));
        }

        // Ordre de résolution = rings AVEC une release, du plus récent au plus
        // ancien (un ring orphelin — release nulle, état défensif — est ignoré :
        // le poste retombe sur le candidat suivant puis la stable, iso AC3).
        $resolutionOrder = $rings->filter(fn (AgentReleaseRing $r): bool => $r->release !== null);

        // Postes appartenant à AU MOINS un groupe ciblé, avec leurs SEULES
        // appartenances ciblées (lecture seule, zéro AD, zéro N+1).
        $workstations = Workstation::query()
            ->whereHas('groups', fn ($q) => $q->whereIn('workstation_groups.id', $targetedGroupIds))
            ->with(['groups' => fn ($q) => $q->whereIn('workstation_groups.id', $targetedGroupIds)])
            ->get();

        foreach ($workstations as $ws) {
            $wsGroupIds = $ws->groups->pluck('id')->all();

            // Ring EFFECTIF : le plus récemment ciblé parmi les groupes du poste
            // (= la version que le manifest lui sert vraiment). Comptage UNIQUE,
            // jamais dans un ring qui ne le gouverne pas → plus de faux « en
            // retard » pour un poste multi-rings (physique + logiques).
            $effective = $resolutionOrder->first(
                fn (AgentReleaseRing $r): bool => in_array($r->workstation_group_id, $wsGroupIds, true),
            );

            // Aucun ring avec release ne le couvre → il suit la stable par
            // défaut, hors progression par ring (la vue ne liste que les rings).
            if ($effective === null) {
                continue;
            }

            $rid = $effective->id;
            $stats[$rid]['total']++;

            if ($ws->agent_reported_version === null) {
                $stats[$rid]['never_seen']++;
            } elseif ($ws->agent_reported_version === $effective->release->version) {
                $stats[$rid]['up_to_date']++;
            } else {
                $stats[$rid]['behind']++;
            }

            $reportedAt = $ws->agent_reported_version_at;
            if ($reportedAt !== null
                && ($stats[$rid]['last_report_at'] === null || $reportedAt->gt($stats[$rid]['last_report_at']))) {
                $stats[$rid]['last_report_at'] = $reportedAt;
            }
        }

        return collect(array_values($stats));
    }

    #[Computed]
    public function stableVersion(): ?string
    {
        return AgentRelease::query()->where('is_stable', true)->value('version');
    }
};
?>

<div class="flex flex-col gap-3">
    <div class="alert alert-info">
        <i class="fa-solid fa-chart-line"></i>
        <span>
            Progression du déploiement par ring : version ciblée vs versions rapportées par les postes.
            @if ($this->stableVersion)
                Stable par défaut : <span class="font-mono font-semibold">{{ $this->stableVersion }}</span>.
            @endif
        </span>
    </div>

    <div class="overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Ring (groupe)</th>
                    <th>Version ciblée</th>
                    <th>Postes</th>
                    <th>À jour</th>
                    <th>En retard</th>
                    <th>Jamais vus</th>
                    <th>Dernier rapport</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rings as $ring)
                    <tr wire:key="progress-{{ $ring['id'] }}">
                        <td>
                            {{ $ring['group'] }}
                            @unless ($ring['is_physical'])
                                <span class="badge badge-ghost badge-sm">parc logique</span>
                            @endunless
                        </td>
                        <td class="font-mono">{{ $ring['target_version'] ?? '—' }}</td>
                        <td>{{ $ring['total'] }}</td>
                        <td>
                            <span class="badge badge-success">{{ $ring['up_to_date'] }}</span>
                        </td>
                        <td>
                            @if ($ring['behind'] > 0)
                                <span class="badge badge-warning">{{ $ring['behind'] }}</span>
                            @else
                                <span class="text-base-content/40">0</span>
                            @endif
                        </td>
                        <td>
                            @if ($ring['never_seen'] > 0)
                                <span class="badge badge-ghost">{{ $ring['never_seen'] }}</span>
                            @else
                                <span class="text-base-content/40">0</span>
                            @endif
                        </td>
                        <td class="text-sm text-base-content/60">
                            {{ $ring['last_report_at']?->diffForHumans() ?? 'jamais' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-base-content/50 py-8">
                            Aucun ring ciblé — la progression apparaîtra dès qu'un ring sera ciblé sur une version.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
