<div class="flex flex-col gap-4 flex-1 min-h-0">
    <!-- Résumé dépôt + Sélecteur -->
    <div class="flex-shrink-0 card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4">
            <div class="flex flex-wrap gap-4 items-end">
                <!-- Sélection du dépôt -->
                <div class="form-control min-w-[250px]">
                    <label class="label py-1">
                        <span class="label-text text-xs">Dépôt</span>
                    </label>
                    <select wire:model.live="depotId" class="select select-bordered select-sm">
                        @foreach ($this->depots as $depot)
                            <option value="{{ $depot->id }}">
                                {{ $depot->name }}
                                @if ($depot->is_primary)
                                    (principal)
                                @endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Recherche -->
                <div class="form-control flex-1 min-w-[200px]">
                    <label class="label py-1">
                        <span class="label-text text-xs">Rechercher</span>
                    </label>
                    <input type="text" wire:model.live.debounce.300ms="depotSearch"
                        class="input input-bordered input-sm" placeholder="Nom, identifiant..." />
                </div>

                <!-- Filtre catégorie -->
                <div class="form-control min-w-[180px]">
                    <label class="label py-1">
                        <span class="label-text text-xs">Catégorie</span>
                    </label>
                    <select wire:model.live="depotCategoryFilter" class="select select-bordered select-sm">
                        <option value="">Toutes les catégories</option>
                        @foreach ($this->depotCategories as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Filtre branche -->
                <div class="form-control min-w-[150px]">
                    <label class="label py-1">
                        <span class="label-text text-xs">Branche</span>
                    </label>
                    <select wire:model.live="depotBranchFilter" class="select select-bordered select-sm">
                        <option value="">Toutes</option>
                        @foreach ($this->depotBranches as $branch)
                            <option value="{{ $branch }}">{{ ucfirst($branch) }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Bouton reset -->
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resetDepotFilters"
                    title="Réinitialiser les filtres">
                    <i class="fa-solid fa-rotate-left"></i>
                </button>
            </div>

            @if ($depotSyncMessage)
                <div class="alert alert-info alert-sm mt-3">
                    <i class="fa-solid fa-info-circle"></i>
                    <span>{{ $depotSyncMessage }}</span>
                </div>
            @endif
        </div>
    </div>

    <!-- Stats du dépôt -->
    @php $dStats = $this->depotStats; @endphp
    <div class="flex-shrink-0 flex gap-3 flex-wrap">
        <div class="stat bg-base-100 shadow-sm border border-base-200 rounded-lg p-3 min-w-[140px]">
            <div class="stat-title text-xs">Total</div>
            <div class="stat-value text-lg">{{ $dStats['total'] }}</div>
        </div>
        <div class="stat bg-base-100 shadow-sm border border-base-200 rounded-lg p-3 min-w-[140px]">
            <div class="stat-title text-xs">Installées</div>
            <div class="stat-value text-lg text-success">{{ $dStats['installed'] }}</div>
        </div>
        <div class="stat bg-base-100 shadow-sm border border-base-200 rounded-lg p-3 min-w-[140px]">
            <div class="stat-title text-xs">Mises à jour</div>
            <div class="stat-value text-lg text-warning">{{ $dStats['updatable'] }}</div>
        </div>
    </div>

    <!-- Tableau des applications du dépôt -->
    <div class="card bg-base-100 shadow-sm border border-base-200 flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="card-body p-0 flex flex-col flex-1 min-h-0">
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <input type="checkbox" class="checkbox checkbox-sm"
                                    wire:click="{{ count($selectedDepotInstallApps) > 0 ? 'deselectAllDepotInstallApps' : 'selectAllDepotInstallApps' }}"
                                    @if (count($selectedDepotInstallApps) > 0) checked @endif />
                            </th>
                            <th>Application</th>
                            <th>Version</th>
                            <th>Catégorie</th>
                            <th>Branche</th>
                            <th class="text-center">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->depotApplications as $app)
                            <tr wire:key="depot-tab-app-{{ $app->id }}" class="hover cursor-pointer"
                                wire:click="toggleDepotInstallAppSelection({{ $app->id }})">
                                <td>
                                    @if (! $app->is_installed)
                                        <input type="checkbox" class="checkbox checkbox-sm"
                                            @if (in_array($app->id, $selectedDepotInstallApps)) checked @endif />
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="avatar placeholder">
                                            <div class="bg-primary/10 text-primary rounded w-10 h-10">
                                                <i class="fa-solid fa-cube"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="font-medium">{{ $app->name }}</div>
                                            <div class="text-xs text-base-content/60">
                                                <code class="bg-base-200 px-1 rounded">{{ $app->app_id }}</code>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-ghost badge-sm">{{ $app->version ?? '-' }}</span>
                                    @if ($app->is_installed && $app->has_update)
                                        <div class="text-xs text-warning mt-1">
                                            Local: {{ $app->local_version ?? '?' }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="text-sm">{{ $app->category ?? '-' }}</span>
                                </td>
                                <td>
                                    @if ($app->branch)
                                        @php
                                            $branchColor = match ($app->branch) {
                                                'stable' => 'badge-success',
                                                'testing' => 'badge-warning',
                                                'manuel' => 'badge-info',
                                                default => 'badge-ghost',
                                            };
                                        @endphp
                                        <span class="badge {{ $branchColor }} badge-sm">{{ ucfirst($app->branch) }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($app->is_installed && $app->has_update)
                                        <span class="badge badge-warning badge-sm gap-1">
                                            <i class="fa-solid fa-arrow-up text-xs"></i>
                                            Mise à jour
                                        </span>
                                    @elseif ($app->is_installed)
                                        <span class="badge badge-success badge-sm gap-1">
                                            <i class="fa-solid fa-check text-xs"></i>
                                            Installée
                                        </span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">
                                            Non installée
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-8 text-base-content/60">
                                    <i class="fa-solid fa-warehouse text-4xl mb-2 opacity-30"></i>
                                    <p>Aucune application trouvée sur ce dépôt</p>
                                    @if ($depotSearch || $depotCategoryFilter || $depotBranchFilter)
                                        <button type="button" class="btn btn-ghost btn-sm mt-2"
                                            wire:click="resetDepotFilters">
                                            Réinitialiser les filtres
                                        </button>
                                    @else
                                        <p class="text-sm mt-1">Cliquez sur "Synchroniser" pour récupérer les applications</p>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if ($this->depotApplications instanceof \Illuminate\Pagination\LengthAwarePaginator)
                <x-molecules.pagination :paginator="$this->depotApplications" :allowedPerPage="$allowedPerPage" perPageModel="depotPerPage"
                    itemLabel="application" itemLabelPlural="applications" />
            @endif
        </div>
    </div>

    <!-- Actions groupées -->
    @if (count($selectedDepotInstallApps) > 0)
        <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
            <div class="card bg-base-100 shadow-xl border border-base-300">
                <div class="card-body py-3 px-4 flex-row items-center gap-4">
                    <span class="text-sm font-medium">
                        {{ count($selectedDepotInstallApps) }} application(s) sélectionnée(s)
                    </span>
                    <div class="divider divider-horizontal m-0"></div>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="installFromDepot"
                        wire:loading.attr="disabled" wire:target="installFromDepot">
                        <span wire:loading.remove wire:target="installFromDepot">
                            <i class="fa-solid fa-download mr-1"></i>
                            Ajouter au catalogue
                        </span>
                        <span wire:loading wire:target="installFromDepot">
                            <i class="fa-solid fa-spinner fa-spin mr-1"></i>
                            Installation...
                        </span>
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm"
                        wire:click="$set('selectedDepotInstallApps', [])">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
