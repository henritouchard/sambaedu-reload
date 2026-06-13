<?php

use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 25.5 — Surface progression du déploiement (AC1, AC4).
 *
 * LECTURE SEULE : agrège, par ring, la version CIBLÉE
 * (`agent_release_rings.release.version`) vs les versions RAPPORTÉES par les
 * postes du groupe (`workstations.agent_reported_version`, persistée par la
 * greffe report 25.5). Montre l'avancée de la canari (1 poste → 1 salle →
 * parc) : combien de postes sont à jour / en retard / jamais vus, et la
 * fraîcheur de la donnée.
 *
 * Jointure lecture seule `agent_release_rings × workstation_group_workstation
 * × workstations` via les relations Eloquent (`workstationGroup->workstations`).
 * Zéro AD (aucun LdapRecord/Kerberos/samba-tool), zéro écriture — la frontière
 * `agent_*` est respectée, aucun Gate (lecture, l'accès page `can:computer.install`
 * suffit). Pas de pagination : le nombre de rings est borné (un ring = un
 * groupe ciblé), l'agrégation se fait par comptage, pas par liste de postes.
 */
return new class extends Component {
    #[Computed]
    public function rings()
    {
        return AgentReleaseRing::query()
            ->with(['release', 'workstationGroup.workstations'])
            ->get()
            ->map(function (AgentReleaseRing $ring) {
                $targetVersion = $ring->release?->version;
                $workstations = $ring->workstationGroup?->workstations ?? collect();

                $upToDate = 0;
                $behind = 0;
                $neverSeen = 0;

                foreach ($workstations as $ws) {
                    if ($ws->agent_reported_version === null) {
                        $neverSeen++;
                    } elseif ($targetVersion !== null && $ws->agent_reported_version === $targetVersion) {
                        $upToDate++;
                    } else {
                        $behind++;
                    }
                }

                return [
                    'id' => $ring->id,
                    'group' => $ring->workstationGroup?->name ?? '—',
                    'is_physical' => (bool) ($ring->workstationGroup?->is_physical ?? true),
                    'target_version' => $targetVersion,
                    'total' => $workstations->count(),
                    'up_to_date' => $upToDate,
                    'behind' => $behind,
                    'never_seen' => $neverSeen,
                    'last_report_at' => $workstations
                        ->pluck('agent_reported_version_at')
                        ->filter()
                        ->max(),
                ];
            });
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
