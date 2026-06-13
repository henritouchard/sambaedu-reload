<?php

use App\Components\Traits\WithToasts;
use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\WorkstationGroup;
use App\Services\Agent\Releases\ReleaseCreationService;
use App\Services\Agent\Releases\ReleaseOperationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Story 25.5 — Surface releases & rings (AC2, AC3).
 *
 * Seconde façade (à côté des commandes artisan 25.1) sur le SEUL écrivain des
 * tables release/ring : {@see ReleaseCreationService}. L'UI n'écrit JAMAIS
 * `agent_releases` / `agent_release_rings` directement — elle appelle
 * `target()` (cibler/rollback un ring → log `agent.release.targeted`) et
 * `promote()` (déplacer la stable par défaut → log `agent.release.promoted`).
 *
 * Toute `ReleaseOperationException` (version inconnue — cas défensif, la version
 * vient d'une liste fermée) est catchée → `toastError`, jamais une 500. Chaque
 * action mutante est gardée par `Gate::authorize('computer.install')` (double
 * protection : la page ET l'action adressable via /livewire/update).
 *
 * Pas de modèle d'ordre des rings (décision actée) : la promotion « 1 poste →
 * 1 salle → parc » reste du jugement humain — l'admin choisit le groupe ET la
 * version. Aucune auto-promotion.
 */
return new class extends Component {
    use WithToasts;

    /** Modale « cibler un ring sur une version ». */
    public bool $isTargetOpen = false;

    public ?int $targetGroupId = null;

    public ?string $targetVersion = null;

    /** Modale « définir la stable par défaut ». */
    public bool $isPromoteOpen = false;

    public ?string $promoteVersion = null;

    #[Computed]
    public function releases()
    {
        return AgentRelease::query()
            ->withCount('rings')
            ->orderByDesc('is_stable')
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function rings()
    {
        return AgentReleaseRing::query()
            ->with(['release', 'workstationGroup'])
            ->orderByDesc('updated_at')
            ->get();
    }

    #[Computed]
    public function groups()
    {
        return WorkstationGroup::query()
            ->active()
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function stableRelease(): ?AgentRelease
    {
        return $this->releases->firstWhere('is_stable', true);
    }

    // ── Cibler un ring sur une version ────────────────────────────────────

    public function openTarget(?int $groupId = null): void
    {
        Gate::authorize('computer.install');

        $this->targetGroupId = $groupId;
        $this->targetVersion = $this->stableRelease?->version;
        $this->isTargetOpen = true;
    }

    public function confirmTarget(): void
    {
        Gate::authorize('computer.install');

        $group = $this->targetGroupId !== null
            ? WorkstationGroup::query()->find($this->targetGroupId)
            : null;

        if ($group === null) {
            $this->toastError('Sélectionnez un groupe (ring) cible.');

            return;
        }
        if ($this->targetVersion === null || $this->targetVersion === '') {
            $this->toastError('Sélectionnez une version à cibler.');

            return;
        }

        try {
            // SEUL écrivain : le service émet `agent.release.targeted` et tient
            // la récence (touch). L'UI ne save() jamais le modèle ring.
            app(ReleaseCreationService::class)->target($this->targetVersion, $group);
        } catch (ReleaseOperationException $e) {
            $this->toastError("Ciblage impossible : {$e->getMessage()}");

            return;
        }

        $version = $this->targetVersion;
        $groupName = $group->name;
        $this->closeTarget();
        unset($this->rings, $this->releases);
        $this->toastSuccess("Ring « {$groupName} » ciblé sur la version {$version} — les postes du groupe convergeront à leur prochain check-in.");
    }

    public function closeTarget(): void
    {
        $this->isTargetOpen = false;
        $this->targetGroupId = null;
        $this->targetVersion = null;
    }

    /**
     * Rollback d'un ring : re-cible le ring sur la version stable par défaut
     * (le manifest fera reconverger les postes). C'est un `target()` — jamais
     * une suppression de ligne (la récence `updated_at` garantit la primauté).
     */
    public function rollbackRing(int $ringId): void
    {
        Gate::authorize('computer.install');

        $ring = AgentReleaseRing::query()->with('workstationGroup')->find($ringId);
        if ($ring === null || $ring->workstationGroup === null) {
            $this->toastError('Ring introuvable.');

            return;
        }

        $stable = $this->stableRelease;
        if ($stable === null) {
            $this->toastError('Aucune version stable par défaut : publiez/définissez une stable avant de rollback un ring.');

            return;
        }

        try {
            app(ReleaseCreationService::class)->target($stable->version, $ring->workstationGroup);
        } catch (ReleaseOperationException $e) {
            $this->toastError("Rollback impossible : {$e->getMessage()}");

            return;
        }

        unset($this->rings, $this->releases);
        $this->toastSuccess("Ring « {$ring->workstationGroup->name} » re-ciblé sur la stable {$stable->version} — rollback armé.");
    }

    // ── Définir / rollback la stable par défaut ───────────────────────────

    public function openPromote(string $version): void
    {
        Gate::authorize('computer.install');

        $this->promoteVersion = $version;
        $this->isPromoteOpen = true;
    }

    public function confirmPromote(): void
    {
        Gate::authorize('computer.install');

        if ($this->promoteVersion === null || $this->promoteVersion === '') {
            $this->closePromote();
            $this->toastError('Aucune version sélectionnée.');

            return;
        }

        try {
            // `promote()` déplace le pointeur stable global → log
            // `agent.release.promoted`. C'est l'avance/rollback du défaut parc.
            app(ReleaseCreationService::class)->promote($this->promoteVersion);
        } catch (ReleaseOperationException $e) {
            $this->closePromote();
            $this->toastError("Promotion impossible : {$e->getMessage()}");

            return;
        }

        $version = $this->promoteVersion;
        $this->closePromote();
        unset($this->releases, $this->rings);
        $this->toastSuccess("Version {$version} définie comme stable par défaut — les postes sans ring la suivront.");
    }

    public function closePromote(): void
    {
        $this->isPromoteOpen = false;
        $this->promoteVersion = null;
    }
};
?>

<div class="flex flex-col gap-6">
    {{-- Releases publiées --}}
    <div>
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-lg font-semibold">Releases publiées</h2>
            <button type="button" class="btn btn-sm btn-primary" wire:click="openTarget">
                <i class="fa-solid fa-bullseye"></i> Cibler un ring
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Version</th>
                        <th>Hash (court)</th>
                        <th>Stable&nbsp;?</th>
                        <th>Rings ciblés</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->releases as $release)
                        <tr wire:key="release-{{ $release->id }}">
                            <td class="font-mono font-semibold">{{ $release->version }}</td>
                            <td class="font-mono text-xs text-base-content/60">{{ \Illuminate\Support\Str::limit($release->hash, 12, '…') }}</td>
                            <td>
                                @if ($release->is_stable)
                                    <span class="badge badge-success">stable par défaut</span>
                                @else
                                    <span class="text-base-content/40">—</span>
                                @endif
                            </td>
                            <td class="text-sm text-base-content/60">{{ $release->rings_count }}</td>
                            <td class="text-right whitespace-nowrap">
                                @unless ($release->is_stable)
                                    <button type="button" class="btn btn-sm btn-ghost"
                                        wire:click="openPromote('{{ $release->version }}')">
                                        <i class="fa-solid fa-star"></i> Définir stable
                                    </button>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-base-content/50 py-8">
                                Aucune release publiée (utilisez <code>agent:release:create</code> côté CLI).
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Rings (groupes ciblés) --}}
    <div>
        <h2 class="text-lg font-semibold mb-2">Rings ciblés</h2>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ring (groupe)</th>
                        <th>Version ciblée</th>
                        <th>Dernier ciblage</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rings as $ring)
                        <tr wire:key="ring-{{ $ring->id }}">
                            <td>
                                {{ $ring->workstationGroup?->name ?? '—' }}
                                @if ($ring->workstationGroup && ! $ring->workstationGroup->is_physical)
                                    <span class="badge badge-ghost badge-sm">parc logique</span>
                                @endif
                            </td>
                            <td class="font-mono">{{ $ring->release?->version ?? '—' }}</td>
                            <td class="text-sm text-base-content/60">{{ $ring->updated_at?->diffForHumans() ?? '—' }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button type="button" class="btn btn-sm btn-ghost"
                                    wire:click="openTarget({{ $ring->workstation_group_id }})">
                                    <i class="fa-solid fa-bullseye"></i> Re-cibler
                                </button>
                                <button type="button" class="btn btn-sm btn-ghost text-warning"
                                    wire:click="rollbackRing({{ $ring->id }})"
                                    wire:confirm="Re-cibler ce ring sur la version stable par défaut (rollback) ?">
                                    <i class="fa-solid fa-rotate-left"></i> Rollback
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-base-content/50 py-8">
                                Aucun ring ciblé — tous les postes suivent la stable par défaut.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modale : cibler un ring sur une version --}}
    <x-molecules.modal wire:model="isTargetOpen" title="Cibler un ring sur une version"
        icon="fa-bullseye text-primary" size="max-w-xl" height="h-auto" closeMethod="closeTarget">
        <x-molecules.modal.section title="Choix du ring et de la version">
            <p class="text-sm text-base-content/60 mb-3">
                Le « ring suivant » est votre jugement (1 poste → 1 salle → parc), pas une mécanique.
                Les postes du groupe convergeront vers cette version à leur prochain check-in.
            </p>
            <div class="form-control mb-3">
                <label class="label"><span class="label-text">Ring (groupe)</span></label>
                <select class="select select-bordered" wire:model="targetGroupId">
                    <option value="">— Sélectionner un groupe —</option>
                    @foreach ($this->groups as $group)
                        <option value="{{ $group->id }}">
                            {{ $group->name }}{{ $group->is_physical ? '' : ' (parc logique)' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-control">
                <label class="label"><span class="label-text">Version</span></label>
                <select class="select select-bordered" wire:model="targetVersion">
                    <option value="">— Sélectionner une version —</option>
                    @foreach ($this->releases as $release)
                        <option value="{{ $release->version }}">
                            {{ $release->version }}{{ $release->is_stable ? ' (stable par défaut)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeTarget">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="confirmTarget">
                <i class="fa-solid fa-bullseye"></i> Cibler
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- Modale : définir la stable par défaut --}}
    <x-molecules.modal wire:model="isPromoteOpen" title="Définir la stable par défaut"
        icon="fa-star text-warning" size="max-w-lg" height="h-auto" closeMethod="closePromote">
        <x-molecules.modal.section title="Promotion de la stable">
            <p class="text-sm">
                Définir la version <span class="font-mono font-semibold">{{ $promoteVersion }}</span>
                comme stable par défaut. Tous les postes <strong>sans ring</strong> la suivront à leur
                prochain check-in. Les rings ciblés explicitement ne sont pas affectés.
            </p>
            <p class="text-sm text-base-content/60 mt-2">
                C'est aussi le levier de rollback du défaut parc : pointez la stable sur une version
                antérieure pour faire reconverger les postes non ciblés.
            </p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closePromote">Annuler</button>
            <button type="button" class="btn btn-warning" wire:click="confirmPromote">
                <i class="fa-solid fa-star"></i> Définir comme stable
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</div>
