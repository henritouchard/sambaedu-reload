<?php

use App\Models\WorkstationGroup;
use App\Services\Agent\Reporting\DesiredStateOriginService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Story 37.1 — Onglet « État cible » de la page PARC / SALLE (consultation pure).
 *
 * Affiche la CONTRIBUTION de ce groupe (décision D4) : raccourcis assignés à CE
 * groupe, applications qu'il apporte (directes + via profils), + les PLANCHERS
 * hérités (socle commun `is_parc_default`, contrat amont). Les réglages propres à
 * chaque poste membre restent visibles sur leur fiche. Projection lecture seule
 * ({@see DesiredStateOriginService}) — pipeline agent SANCTUARISÉ.
 *
 * **#[Lazy] (correction post-review P1)** — même motivation que le SFC poste : la
 * bascule montait ce SFC en synchrone dans le même roundtrip que le re-rendu de la
 * page parc (~2000 lignes de Blade). Le lazy rend d'abord un squelette puis charge
 * le contenu réel dans un roundtrip isolé.
 */
new #[Lazy] class extends Component {
    /** Parc/salle consulté — verrou serveur-autoritatif. */
    #[Locked]
    public int $groupId;

    public function mount(int $groupId): void
    {
        $this->groupId = $groupId;
    }

    /**
     * Squelette rendu instantanément pendant le chargement lazy (évite le saut de
     * layout : 2 cards titre + lignes, dimensions proches du contenu réel).
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="space-y-6 mt-4" role="status" aria-busy="true">
            {{-- Titre NEUTRE : le groupe n'est pas chargé au rendu du squelette
                 (mount court-circuité) — on ne peut pas trancher salle/parc ici. --}}
            <div class="alert alert-info shadow-sm">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <p class="font-medium">État cible</p>
                    <p class="text-sm opacity-80">Chargement de l'état cible…</p>
                </div>
            </div>
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body space-y-3">
                    <div class="skeleton h-6 w-40"></div>
                    <div class="skeleton h-4 w-full"></div>
                    <div class="skeleton h-4 w-5/6"></div>
                    <div class="skeleton h-4 w-2/3"></div>
                </div>
            </div>
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body space-y-3">
                    <div class="skeleton h-6 w-40"></div>
                    <div class="skeleton h-4 w-full"></div>
                    <div class="skeleton h-4 w-5/6"></div>
                </div>
            </div>
        </div>
        HTML;
    }

    #[Computed]
    public function group(): ?WorkstationGroup
    {
        return WorkstationGroup::query()->find($this->groupId);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function shortcuts(): array
    {
        $group = $this->group;

        return $group === null ? [] : app(DesiredStateOriginService::class)->shortcutsForGroup($group);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function applications(): array
    {
        $group = $this->group;

        return $group === null ? [] : app(DesiredStateOriginService::class)->applicationsForGroup($group);
    }
}; ?>

{{-- Review #5 — vocabulaire salle/parc : les textes de la vue suivent la nature
     physique/logique du groupe (cohérent D6, badges room_self/group_self). --}}
@php $isRoom = $this->group?->is_physical === true; @endphp
<div class="space-y-6 mt-4">
    <div class="alert alert-info shadow-sm">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <p class="font-medium">État cible {{ $isRoom ? 'de la salle' : 'du parc' }}</p>
            <p class="text-sm opacity-80">
                La <strong>contribution de {{ $isRoom ? 'cette salle' : 'ce parc' }}</strong> à l'état cible de ses postes : raccourcis assignés,
                applications apportées (directes ou via profils), plus les <strong>planchers</strong> hérités
                (socle commun, contrat amont). Les réglages propres à chaque poste membre sont visibles sur
                leur fiche.
                {{-- Review #6b — écart assumé D4 rendu VISIBLE : les ordres amont
                     ciblés par label sont poste-portés, pas affichés ici. --}}
                Les ordres d'installation du contrat amont ciblés par label sont propres aux postes qui
                portent ce label — consultez les fiches des postes concernés.
            </p>
        </div>
    </div>

    {{-- ── Raccourcis ─────────────────────────────────────────────────────── --}}
    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <h2 class="card-title text-base">
                <i class="fa-solid fa-link text-primary"></i>
                Raccourcis
                <span class="badge badge-ghost">{{ count($this->shortcuts) }}</span>
            </h2>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Raccourci</th>
                            <th>Cible</th>
                            <th>Emplacement</th>
                            <th>Origine</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->shortcuts as $row)
                            <tr wire:key="{{ $row['key'] }}">
                                <td class="font-medium">{{ $row['label'] }}</td>
                                <td class="text-xs opacity-70"><code class="break-all">{{ $row['detail'] }}</code></td>
                                <td class="text-xs opacity-70">{{ $row['place_label'] }}</td>
                                <td>
                                    @include('pages.parc._partials.desired-state-origins', ['origins' => $row['origins']])
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center opacity-60 py-6">
                                    {{ $isRoom ? 'Cette salle' : 'Ce parc' }} n'assigne aucun raccourci.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ── Applications ───────────────────────────────────────────────────── --}}
    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <h2 class="card-title text-base">
                <i class="fa-solid fa-cube text-primary"></i>
                Applications
                <span class="badge badge-ghost">{{ count($this->applications) }}</span>
            </h2>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Application</th>
                            <th>Identifiant application</th>
                            <th>Origine</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->applications as $row)
                            <tr wire:key="{{ $row['key'] }}">
                                <td class="font-medium">{{ $row['label'] }}</td>
                                <td class="text-xs opacity-70"><code>{{ $row['detail'] }}</code></td>
                                <td>
                                    @include('pages.parc._partials.desired-state-origins', ['origins' => $row['origins']])
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center opacity-60 py-6">
                                    {{ $isRoom ? 'Cette salle' : 'Ce parc' }} n'apporte aucune application.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
