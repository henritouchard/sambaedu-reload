<div class="flex flex-col gap-4 flex-1 min-h-0">
    <!-- Filtres -->
    <div class="flex-shrink-0 card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4">
            <div class="flex flex-wrap gap-4 items-end">
                <!-- Recherche -->
                <div class="form-control flex-1 min-w-[200px]">
                    <label class="label py-1">
                        <span class="label-text text-xs">Rechercher</span>
                    </label>
                    <input type="text" wire:model.live.debounce.300ms="appSearch" class="input input-bordered input-sm"
                        placeholder="Nom, identifiant..." />
                </div>

                <!-- Filtre catégorie -->
                <div class="form-control min-w-[180px]">
                    <label class="label py-1">
                        <span class="label-text text-xs">Catégorie</span>
                    </label>
                    <select wire:model.live="categoryFilter" class="select select-bordered select-sm">
                        <option value="">Toutes les catégories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Bouton reset -->
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resetAppFilters"
                    title="Réinitialiser les filtres">
                    <i class="fa-solid fa-rotate-left"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Tableau des applications -->
    <div class="card bg-base-100 shadow-sm border border-base-200 flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="card-body p-0 flex flex-col flex-1 min-h-0">
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <input type="checkbox" class="checkbox checkbox-sm" />
                            </th>
                            <th>Application</th>
                            <th>Identifiant</th>
                            <th>Version</th>
                            <th>Catégorie</th>
                            <th class="text-center">Compatibilité</th>
                            <th class="text-center">Dépôt</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->applications as $app)
                            <tr wire:key="app-{{ $app->id }}" class="hover cursor-pointer"
                                onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.parc-settings.applications.show', $app->id) }}'">
                                <td class="checkbox-cell p-0">
                                    <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                        <input type="checkbox" class="checkbox checkbox-sm"
                                            wire:model.live="selectedApps" value="{{ $app->id }}" />
                                    </label>
                                </td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="avatar placeholder">
                                            <div class="bg-primary/10 text-primary rounded w-10 h-10">
                                                <i class="fa-solid fa-cube"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <span class="font-medium">{{ $app->name }}</span>
                                            @if ($app->branch && $app->branch !== 'stable')
                                                <span
                                                    class="badge badge-{{ $app->branch === 'testing' ? 'warning' : 'info' }} badge-xs ml-1">
                                                    {{ $app->branch }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <code class="text-xs bg-base-200 px-2 py-1 rounded">{{ $app->app_id }}</code>
                                </td>
                                <td>
                                    <span class="badge badge-ghost">{{ $app->version ?? '-' }}</span>
                                </td>
                                <td>
                                    <span class="text-sm">{{ $app->category ?? '-' }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="text-xs text-base-content/70">
                                        {{ $app->compatibility ?? '-' }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-sm badge-ghost">
                                        {{ $app->depot?->name ?? '-' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-8 text-base-content/60">
                                    <i class="fa-solid fa-cube text-4xl mb-2 opacity-30"></i>
                                    <p>Aucune application trouvée</p>
                                    @if ($appSearch || $categoryFilter)
                                        <button type="button" class="btn btn-ghost btn-sm mt-2"
                                            wire:click="resetAppFilters">
                                            Réinitialiser les filtres
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if ($this->applications instanceof \Illuminate\Pagination\LengthAwarePaginator)
                <x-molecules.pagination :paginator="$this->applications" :allowedPerPage="$allowedPerPage" perPageModel="appsPerPage"
                    itemLabel="application" itemLabelPlural="applications" />
            @endif
        </div>
    </div>

    <!-- Actions groupées -->
    @if (count($selectedApps) > 0)
        <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
            <div class="card bg-base-100 shadow-xl border border-base-300">
                <div class="card-body py-3 px-4 flex-row items-center gap-4">
                    <span class="text-sm font-medium">
                        {{ count($selectedApps) }} application(s) sélectionnée(s)
                    </span>
                    <div class="divider divider-horizontal m-0"></div>
                    <div class="dropdown dropdown-top">
                        <label tabindex="0" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-cog"></i>
                            Actions
                            <i class="fa-solid fa-chevron-up ml-1"></i>
                        </label>
                        <ul tabindex="0"
                            class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-56 border border-base-300 mb-2">
                            <li>
                                <button type="button" wire:click="addAppsToProfile">
                                    <i class="fa-solid fa-folder-plus"></i>
                                    Ajouter à un profil
                                </button>
                            </li>
                            <li>
                                <button type="button" wire:click="deployApps">
                                    <i class="fa-solid fa-rocket"></i>
                                    Déployer sur un groupe
                                </button>
                            </li>
                        </ul>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('selectedApps', [])">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
