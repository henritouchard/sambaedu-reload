{{--
    Story 15.4 / Décision A — Onglet Applications WPKG du parc.
    Réutilise méthodes Livewire et computed du composant parent.
--}}
@php
    $attachedProfiles = $this->wpkgAttachedProfiles;
    $attachedApps = $this->wpkgAttachedApplications;
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        @can('assign-wpkg-workstationGroup', $group)
            <button type="button" class="btn btn-outline btn-sm gap-2"
                wire:click="openBulkCategoryModal">
                <i class="fa-solid fa-tags"></i>
                Bulk catégorie
            </button>
            <button type="button" class="btn btn-outline btn-sm gap-2"
                wire:click="openCloneModal">
                <i class="fa-solid fa-clone"></i>
                Cloner cette configuration vers...
            </button>
        @endcan
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- Profils applicatifs assignés au parc --}}
        <div class="card bg-base-100 shadow-sm border border-base-300">
            <div class="card-body">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="card-title text-base">
                        <i class="fa-solid fa-layer-group text-primary"></i>
                        Profils applicatifs
                        <span class="badge badge-ghost">{{ $attachedProfiles->count() }}</span>
                    </h3>
                    @can('assign-wpkg-workstationGroup', $group)
                        <button type="button" class="btn btn-primary btn-sm gap-2"
                            wire:click="openAttachWpkgProfileModal">
                            <i class="fa-solid fa-plus"></i>
                            Ajouter
                        </button>
                    @endcan
                </div>

                @if ($attachedProfiles->isEmpty())
                    <div class="text-center py-6 text-base-content/60 text-sm">
                        Aucun profil applicatif rattaché à ce parc.
                    </div>
                @else
                    <ul class="divide-y divide-base-200">
                        @foreach ($attachedProfiles as $profile)
                            <li wire:key="grp-attached-profile-{{ $profile->id }}"
                                class="flex items-center justify-between py-2">
                                <div>
                                    <div class="font-medium">
                                        {{ $profile->display_name ?? $profile->name }}
                                        @if ($profile->is_active)
                                            <span class="badge badge-success badge-sm ml-1">actif</span>
                                        @else
                                            <span class="badge badge-warning badge-sm ml-1">inactif</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-base-content/60">
                                        <code>{{ $profile->name }}</code>
                                        • {{ $profile->applications_count ?? $profile->applications->count() }} app(s)
                                    </div>
                                </div>
                                @can('assign-wpkg-workstationGroup', $group)
                                    <button type="button" class="btn btn-ghost btn-xs btn-square text-error"
                                        wire:click="detachWpkgProfile({{ $profile->id }})"
                                        wire:confirm="Retirer ce profil du parc ?">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- Applications directes parc --}}
        <div class="card bg-base-100 shadow-sm border border-base-300">
            <div class="card-body">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="card-title text-base">
                        <i class="fa-solid fa-cube text-primary"></i>
                        Applications directes
                        <span class="badge badge-ghost">{{ $attachedApps->count() }}</span>
                    </h3>
                    @can('assign-wpkg-workstationGroup', $group)
                        <button type="button" class="btn btn-primary btn-sm gap-2"
                            wire:click="openAttachWpkgAppModal">
                            <i class="fa-solid fa-plus"></i>
                            Ajouter
                        </button>
                    @endcan
                </div>

                @if ($attachedApps->isEmpty())
                    <div class="text-center py-6 text-base-content/60 text-sm">
                        Aucune application directement rattachée à ce parc.
                    </div>
                @else
                    <ul class="divide-y divide-base-200">
                        @foreach ($attachedApps as $app)
                            <li wire:key="grp-attached-app-{{ $app->id }}"
                                class="flex items-center justify-between py-2">
                                <div>
                                    <div class="font-medium">{{ $app->name }}</div>
                                    <div class="text-xs text-base-content/60">
                                        <code>{{ $app->app_id }}</code>
                                        @if ($app->category)
                                            • {{ $app->category }}
                                        @endif
                                    </div>
                                </div>
                                @can('assign-wpkg-workstationGroup', $group)
                                    <button type="button" class="btn btn-ghost btn-xs btn-square text-error"
                                        wire:click="detachWpkgApplication({{ $app->id }})"
                                        wire:confirm="Retirer cette application du parc ?">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
