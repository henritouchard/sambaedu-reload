<div class="flex flex-col gap-4 h-full">
    <!-- Barre d'actions -->
    <div class="flex-shrink-0 flex justify-between items-center">
        <div class="form-control w-64">
            <input type="text" wire:model.live.debounce.300ms="appSearch" class="input input-bordered input-sm"
                placeholder="Rechercher une application..." />
        </div>
        <button type="button" class="btn btn-primary btn-sm" wire:click="openAddAppsModal">
            <i class="fa-solid fa-plus mr-1"></i>
            Ajouter des applications
        </button>
    </div>

    <!-- Liste des applications -->
    <div class="card bg-base-100 shadow-sm border border-base-200 flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="card-body p-0 flex flex-col flex-1 min-h-0">
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th>Application</th>
                            <th>Identifiant</th>
                            <th>Version</th>
                            <th>Catégorie</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->profileApplications as $app)
                            <tr wire:key="profile-app-{{ $app->id }}">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="avatar placeholder">
                                            <div class="bg-primary/10 text-primary rounded w-8 h-8">
                                                <i class="fa-solid fa-cube text-sm"></i>
                                            </div>
                                        </div>
                                        <span class="font-medium">{{ $app->name }}</span>
                                        @if ($app->branch && $app->branch !== 'stable')
                                            <span class="badge badge-warning badge-xs" title="{{ $app->branch }}">
                                                {{ $app->branch }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <code class="text-xs bg-base-200 px-2 py-1 rounded">{{ $app->app_id }}</code>
                                </td>
                                <td>
                                    <span class="badge badge-ghost badge-sm">{{ $app->version }}</span>
                                </td>
                                <td>
                                    <span class="text-sm">{{ $app->category }}</span>
                                </td>
                                <td class="text-right">
                                    <button type="button" class="btn btn-ghost btn-xs text-error"
                                        wire:click="removeApplication({{ $app->id }})"
                                        wire:confirm="Retirer cette application du profil ?" title="Retirer">
                                        <i class="fa-solid fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-8 text-base-content/60">
                                    <i class="fa-solid fa-cube text-4xl mb-2 opacity-30"></i>
                                    <p>Aucune application dans ce profil</p>
                                    <button type="button" class="btn btn-primary btn-sm mt-2"
                                        wire:click="openAddAppsModal">
                                        <i class="fa-solid fa-plus mr-1"></i>
                                        Ajouter des applications
                                    </button>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if (
                $this->profileApplications instanceof \Illuminate\Pagination\LengthAwarePaginator &&
                    $this->profileApplications->hasPages())
                <div class="border-t border-base-200 p-4">
                    {{ $this->profileApplications->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
