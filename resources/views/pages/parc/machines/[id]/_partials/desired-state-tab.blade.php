<?php

use App\Models\Workstation;
use App\Services\Agent\Reporting\DesiredStateOriginService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Story 37.1 — Onglet « État cible » de la fiche POSTE (consultation pure).
 *
 * Affiche les RACCOURCIS et les APPLICATIONS résolus pour la machine, avec le
 * badge d'ORIGINE de chaque item (réglage propre, hérité d'un parc/salle, socle
 * commun, contrat amont, dépendance). Aucune mutation, aucun formulaire : la
 * projection lecture seule vit dans {@see DesiredStateOriginService} (chemin de
 * consultation parallèle, pipeline agent SANCTUARISÉ).
 *
 * Les raccourcis ciblés par UTILISATEUR / GROUPE d'utilisateurs sont EXCLUS
 * (décision D3 — session-dépendants) : une note le signale et renvoie à la fiche
 * du raccourci.
 *
 * **#[Lazy] (correction post-review P1)** — la bascule vers cet onglet montait le
 * SFC EN SYNCHRONE dans le même roundtrip Livewire que le re-rendu de la page
 * parente (fiche poste, ~1600 lignes de Blade) : navigation ressentie lente alors
 * que le service est rapide (≤ 25 ms). Le lazy-loading rend d'abord un squelette
 * ({@see self::placeholder()}) puis charge le contenu réel dans un second
 * roundtrip isolé — la page parente n'est plus re-rendue en même temps.
 */
new #[Lazy] class extends Component {
    /**
     * Poste consulté — verrou serveur-autoritatif (#[Locked]) : l'hydratation via
     * le paramètre `mount` reste autorisée, toute mutation client lève.
     */
    #[Locked]
    public int $workstationId;

    public function mount(int $workstationId): void
    {
        $this->workstationId = $workstationId;
    }

    /**
     * Squelette rendu instantanément pendant le chargement lazy (évite le saut de
     * layout : dimensions proches du contenu réel — 2 cards titre + lignes).
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="space-y-6 mt-4" role="status" aria-busy="true">
            <div class="alert alert-info shadow-sm">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <p class="font-medium">État cible du poste</p>
                    <p class="text-sm opacity-80">Chargement de l'état cible…</p>
                </div>
            </div>
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body space-y-3">
                    <div class="skeleton h-6 w-40"></div>
                    <div class="skeleton h-4 w-full"></div>
                    <div class="skeleton h-4 w-5/6"></div>
                    <div class="skeleton h-4 w-2/3"></div>
                </div>
            </div>
            <div class="card bg-base-100 shadow-sm border border-base-200">
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
    public function workstation(): ?Workstation
    {
        return Workstation::query()->find($this->workstationId);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function shortcuts(): array
    {
        $ws = $this->workstation;

        return $ws === null ? [] : app(DesiredStateOriginService::class)->shortcutsFor($ws);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function applications(): array
    {
        $ws = $this->workstation;

        return $ws === null ? [] : app(DesiredStateOriginService::class)->applicationsFor($ws);
    }
}; ?>

<div class="space-y-6 mt-4">
    <div class="alert alert-info shadow-sm">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <p class="font-medium">État cible du poste</p>
            <p class="text-sm opacity-80">
                Tout ce que ce poste va recevoir — raccourcis et applications — avec l'<strong>origine</strong> de
                chaque item (réglage propre, hérité d'un parc/salle, socle commun, contrat amont, dépendance).
                Vue en <strong>consultation</strong> : l'édition reste dans les onglets dédiés.
            </p>
        </div>
    </div>

    {{-- ── Raccourcis ─────────────────────────────────────────────────────── --}}
    <div class="card bg-base-100 shadow-sm border border-base-200">
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
                                    Aucun raccourci résolu pour ce poste.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p class="text-xs opacity-60 mt-2">
                <i class="fa-solid fa-user-clock mr-1"></i>
                Les raccourcis ciblés par utilisateur ou groupe d'utilisateurs dépendent de la session et ne sont
                pas listés ici — consultez la fiche du raccourci.
            </p>
        </div>
    </div>

    {{-- ── Applications ───────────────────────────────────────────────────── --}}
    <div class="card bg-base-100 shadow-sm border border-base-200">
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
                            <th>Identifiant WPKG</th>
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
                                    Aucune application résolue pour ce poste.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
