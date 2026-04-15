@php $deployment = $this->deploymentStatuses; @endphp

@if ($deployment['success']->isNotEmpty() || $deployment['errors']->isNotEmpty() || $deployment['in_progress']->isNotEmpty())
<div class="card bg-base-100 shadow-sm border border-base-200">
    <div class="card-body">
        <div class="flex items-center justify-between mb-4">
            <h3 class="card-title text-lg">
                <i class="fa-solid fa-chart-bar mr-2"></i>
                Déploiement des applications
            </h3>
        </div>

        {{-- Onglets --}}
        <div role="tablist" class="tabs tabs-boxed bg-base-200 w-fit mb-4">
            <button type="button" role="tab"
                class="tab {{ $deploymentTab === 'success' ? 'tab-active' : '' }}"
                aria-selected="{{ $deploymentTab === 'success' ? 'true' : 'false' }}"
                wire:click="$set('deploymentTab', 'success')">
                <i class="fa-solid fa-check mr-2 text-success"></i>
                Succès
                <span class="badge badge-sm ml-2 badge-success">{{ $deployment['success']->count() }}</span>
            </button>
            <button type="button" role="tab"
                class="tab {{ $deploymentTab === 'errors' ? 'tab-active' : '' }}"
                aria-selected="{{ $deploymentTab === 'errors' ? 'true' : 'false' }}"
                wire:click="$set('deploymentTab', 'errors')">
                <i class="fa-solid fa-xmark mr-2 text-error"></i>
                Échecs
                @if ($deployment['errors']->isNotEmpty())
                    <span class="badge badge-sm ml-2 badge-error">{{ $deployment['errors']->count() }}</span>
                @else
                    <span class="badge badge-sm ml-2 badge-ghost">0</span>
                @endif
            </button>
            <button type="button" role="tab"
                class="tab {{ $deploymentTab === 'in_progress' ? 'tab-active' : '' }}"
                aria-selected="{{ $deploymentTab === 'in_progress' ? 'true' : 'false' }}"
                wire:click="$set('deploymentTab', 'in_progress')">
                <i class="fa-solid fa-rotate mr-2 text-info"></i>
                En cours
                @if ($deployment['in_progress']->isNotEmpty())
                    <span class="badge badge-sm ml-2 badge-info">{{ $deployment['in_progress']->count() }}</span>
                @else
                    <span class="badge badge-sm ml-2 badge-ghost">0</span>
                @endif
            </button>
        </div>

        {{-- Contenu onglets --}}
        @php
            $items = match($deploymentTab) {
                'success' => $deployment['success'],
                'in_progress' => $deployment['in_progress'],
                default => $deployment['errors'],
            };
        @endphp
        @if ($items->isEmpty())
            <p class="text-base-content/50 text-sm py-4 text-center">Aucune application dans cette catégorie.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr class="bg-base-200">
                            <th>Application</th>
                            <th>Version installée</th>
                            <th class="text-center">Statut</th>
                            <th>Dernier rapport</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $status)
                            <tr class="hover">
                                <td>
                                    @if ($status->application)
                                        <a href="{{ route('app.parc-settings.applications.show', $status->application->id) }}"
                                            class="font-medium hover:underline">
                                            {{ $status->application->name ?? $status->application->app_id }}
                                        </a>
                                        <div class="text-xs text-base-content/50 font-mono">{{ $status->application->app_id }}</div>
                                    @else
                                        <div class="font-medium">—</div>
                                    @endif
                                </td>
                                <td class="font-mono text-sm">{{ $status->installed_version ?: '—' }}</td>
                                <td class="text-center">
                                    @if ($status->status === 'installed')
                                        <span class="badge badge-success badge-sm">Installé</span>
                                    @elseif ($status->status === 'upgrading')
                                        <span class="badge badge-info badge-sm">
                                            <span class="loading loading-spinner loading-xs mr-1"></span>
                                            Mise à jour
                                        </span>
                                    @elseif ($status->status === 'downgrading')
                                        <span class="badge badge-info badge-sm">
                                            <span class="loading loading-spinner loading-xs mr-1"></span>
                                            Rétrogradation
                                        </span>
                                    @elseif ($status->status === 'error')
                                        <button type="button"
                                            class="badge badge-error badge-sm cursor-pointer hover:badge-outline"
                                            wire:click="openDeploymentModal({{ $status->id }})"
                                            wire:loading.attr="disabled" wire:target="openDeploymentModal({{ $status->id }})">
                                            <span wire:loading.remove wire:target="openDeploymentModal({{ $status->id }})">Erreur ↗</span>
                                            <span wire:loading wire:target="openDeploymentModal({{ $status->id }})"><span class="loading loading-spinner loading-xs"></span></span>
                                        </button>
                                    @else
                                        <button type="button"
                                            class="badge badge-warning badge-sm cursor-pointer hover:badge-outline"
                                            wire:click="openDeploymentModal({{ $status->id }})"
                                            wire:loading.attr="disabled" wire:target="openDeploymentModal({{ $status->id }})">
                                            <span wire:loading.remove wire:target="openDeploymentModal({{ $status->id }})">Non installé ↗</span>
                                            <span wire:loading wire:target="openDeploymentModal({{ $status->id }})"><span class="loading loading-spinner loading-xs"></span></span>
                                        </button>
                                    @endif
                                </td>
                                <td class="text-sm text-base-content/60">
                                    {{ $status->reported_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

{{-- Modale détail statut --}}
@if ($deploymentModalStatusId)
    @php $modalStatus = $this->deploymentModalStatus; @endphp
    @teleport('body')
        <dialog class="modal modal-open">
            <div class="modal-box max-w-lg">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="font-bold text-lg">{{ $modalStatus?->application?->name ?? '—' }}</h3>
                        <p class="text-sm text-base-content/60 font-mono">{{ $modalStatus?->application?->app_id ?? '' }}</p>
                    </div>
                    <button type="button" wire:click="closeDeploymentModal" class="btn btn-sm btn-circle btn-ghost">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="bg-base-200 rounded-lg p-3">
                        <p class="text-xs text-base-content/60">Version installée</p>
                        <p class="font-mono font-medium">{{ $modalStatus?->installed_version ?: '—' }}</p>
                    </div>
                    <div class="bg-base-200 rounded-lg p-3">
                        <p class="text-xs text-base-content/60">Dernier rapport</p>
                        <p class="font-medium">{{ $modalStatus?->reported_at?->format('d/m/Y H:i') ?? '—' }}</p>
                    </div>
                    <div class="bg-base-200 rounded-lg p-3">
                        <p class="text-xs text-base-content/60">Statut</p>
                        @php
                            $statusLabel = match($modalStatus?->status) {
                                'error' => 'Erreur',
                                'not-installed' => 'Non installé',
                                default => $modalStatus?->status ?? '—',
                            };
                            $statusBadge = $modalStatus?->status === 'error' ? 'badge-error' : 'badge-warning';
                        @endphp
                        <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
                    </div>
                    @if ($modalStatus?->reboot_required)
                    <div class="bg-warning/10 rounded-lg p-3">
                        <p class="text-xs text-base-content/60">Redémarrage</p>
                        <p class="font-medium text-warning">
                            <i class="fa-solid fa-rotate-right mr-1"></i>Requis
                        </p>
                    </div>
                    @endif
                </div>

                <div class="bg-base-200 rounded-lg p-3 max-h-48 overflow-y-auto">
                    <p class="text-xs text-base-content/60 mb-1">Détail</p>
                    @if ($modalStatus?->message)
                        <pre class="text-xs font-mono whitespace-pre-wrap break-all">{{ $modalStatus->message }}</pre>
                    @else
                        <p class="text-sm text-base-content/50 italic">Aucun détail disponible.</p>
                    @endif
                </div>
            </div>
            <form method="dialog" class="modal-backdrop" wire:click="closeDeploymentModal">
                <button>close</button>
            </form>
        </dialog>
    @endteleport
@endif
@else
{{-- Aucun rapport --}}
<div class="card bg-base-100 shadow-sm border border-base-200">
    <div class="card-body py-6 flex flex-col items-center text-center">
        <i class="fa-solid fa-chart-bar text-3xl text-base-content/20 mb-2"></i>
        <p class="text-sm text-base-content/50">Aucun rapport d'installation disponible pour ce poste.</p>
    </div>
</div>
@endif
