{{--
    Story 15.4 / AC4 — Clone parc → parc.
    Sélection du parc cible + preview du diff (added/removed) avant
    confirmation synchrone. Insertion d'une ligne `wpkg_deployments` (UUID)
    via AppProfileService::cloneConfiguration.
--}}
<div class="modal modal-open">
    <div class="modal-box max-w-4xl">
        <h3 class="font-bold text-lg mb-4">
            <i class="fa-solid fa-clone mr-2"></i>
            Cloner cette configuration vers un autre parc
        </h3>

        <div class="form-control mb-4">
            <input type="text" wire:model.live.debounce.300ms="cloneTargetSearch"
                class="input input-bordered"
                placeholder="Rechercher le parc cible..." />
        </div>

        @if ($cloneTargetGroupId === null)
            <div class="max-h-72 overflow-y-auto border border-base-200 rounded-lg">
                @forelse ($this->availableCloneTargets as $candidate)
                    <button type="button"
                        wire:key="clone-target-{{ $candidate->id }}"
                        wire:click="previewCloneTo({{ $candidate->id }})"
                        class="w-full text-left flex items-center gap-3 p-3 hover:bg-base-200 border-b border-base-200 last:border-b-0">
                        <i class="fa-solid fa-folder-tree text-secondary"></i>
                        <div class="flex-1">
                            <div class="font-medium">{{ $candidate->name }}</div>
                            <div class="text-xs text-base-content/60">
                                {{ $candidate->workstations_count ?? $candidate->workstations()->count() }} poste(s)
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-base-content/40"></i>
                    </button>
                @empty
                    <div class="p-6 text-center text-base-content/60">Aucun parc disponible.</div>
                @endforelse
            </div>
        @else
            @php $preview = $clonePreview; @endphp
            <div class="space-y-3">
                <div class="alert">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        <div class="font-medium">Aperçu du clone</div>
                        <div class="text-sm">
                            Cible : parc #{{ $cloneTargetGroupId }} — la configuration cible sera <strong>écrasée</strong>
                            par celle de la source.
                        </div>
                    </div>
                </div>

                @if ($preview)
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="card bg-success/5 border border-success/20">
                            <div class="card-body p-4">
                                <h4 class="font-semibold text-success">Ajouts</h4>
                                <div class="text-xs">
                                    <div>Profils : {{ count($preview['profiles']['added']) }}</div>
                                    <div>Apps directes : {{ count($preview['applications']['added']) }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="card bg-error/5 border border-error/20">
                            <div class="card-body p-4">
                                <h4 class="font-semibold text-error">Retraits</h4>
                                <div class="text-xs">
                                    <div>Profils : {{ count($preview['profiles']['removed']) }}</div>
                                    <div>Apps directes : {{ count($preview['applications']['removed']) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('cloneTargetGroupId', null)">
                    <i class="fa-solid fa-arrow-left mr-1"></i> Changer de cible
                </button>
            </div>
        @endif

        <div class="modal-action">
            <button type="button" class="btn" wire:click="closeCloneModal">Annuler</button>
            <button type="button" class="btn btn-primary"
                wire:click="executeClone"
                wire:confirm="Confirmer le clone ? La configuration cible sera écrasée."
                @disabled($cloneTargetGroupId === null)>
                <i class="fa-solid fa-check mr-1"></i>
                Cloner
            </button>
        </div>
    </div>
    <div class="modal-backdrop bg-black/50" wire:click="closeCloneModal"></div>
</div>
