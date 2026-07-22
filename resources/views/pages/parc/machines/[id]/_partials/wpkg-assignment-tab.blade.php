{{--
    Story 15.4 / Décision A — Onglet Applications WPKG du poste.
    Réutilise les méthodes Livewire et computed properties définies dans le
    composant parent `pages/parc/machines/[id]/index.blade.php` :
    - $this->wpkgAttachedProfiles  ['direct' => …, 'inherited' => …]
    - $this->wpkgAttachedApplications  ['direct' => …, 'inherited' => …]
    - méthodes attachWpkg*, detachWpkg*.
    Les options `.ini` ont leur propre onglet de premier niveau « Paramètres »
    (voir la branche @elseif ($tab === 'settings') du composant parent).
--}}
@php
    $profiles = $this->wpkgAttachedProfiles;
    $apps = $this->wpkgAttachedApplications;
    $directProfiles = $profiles['direct'];
    $inheritedProfiles = $profiles['inherited'];
    $directApps = $apps['direct'];
    $inheritedApps = $apps['inherited'];
    // Story 29.1 — Périmètre d'autorisation WPKG = salle physique du poste.
    // Résolu UNE fois ici (l'accessor physicalRoom requête sinon à chaque @can,
    // N+1) puis passé au Gate scopé. null (poste nomade) → fallback global.
    $wpkgScope = $this->workstation?->physicalRoom;
@endphp

<div class="space-y-4">
    {{-- Assignations WPKG (profils applicatifs + applications directes/héritées) --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Profils applicatifs --}}
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="card-title text-base">
                            <i class="fa-solid fa-layer-group text-primary"></i>
                            Profils applicatifs
                            <span class="badge badge-ghost">{{ $directProfiles->count() + $inheritedProfiles->count() }}</span>
                        </h3>
                        @can('assign-wpkg-workstationGroup', $wpkgScope)
                            <button type="button" class="btn btn-primary btn-sm gap-2"
                                wire:click="openAttachWpkgProfileModal">
                                <i class="fa-solid fa-plus"></i>
                                Ajouter directement
                            </button>
                        @endcan
                    </div>

                    @if ($directProfiles->isEmpty() && $inheritedProfiles->isEmpty())
                        <div class="text-center py-6 text-base-content/60 text-sm">
                            Aucun profil applicatif assigné.
                        </div>
                    @else
                        <ul class="divide-y divide-base-200">
                            @foreach ($directProfiles as $profile)
                                <li wire:key="ws-direct-profile-{{ $profile->id }}"
                                    class="flex items-center justify-between py-2">
                                    <div>
                                        <div class="font-medium">
                                            {{ $profile->name }}
                                            <span class="badge badge-success badge-sm ml-2">direct</span>
                                        </div>
                                        <div class="text-xs text-base-content/60">
                                            {{ $profile->applications->count() ?? 0 }} app(s)
                                        </div>
                                    </div>
                                    @can('assign-wpkg-workstationGroup', $wpkgScope)
                                        <button type="button" class="btn btn-ghost btn-xs btn-square text-error"
                                            wire:click="detachWpkgProfile({{ $profile->id }})"
                                            wire:confirm="Retirer ce profil du poste ?">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    @endcan
                                </li>
                            @endforeach
                            @foreach ($inheritedProfiles as $profile)
                                @php $sourceGroup = $profile->_inheritedFromGroup ?? null; @endphp
                                <li wire:key="ws-inherit-profile-{{ $profile->id }}"
                                    class="flex items-center justify-between py-2">
                                    <div>
                                        <div class="font-medium">
                                            {{ $profile->name }}
                                            <span class="badge badge-info badge-sm ml-2">
                                                hérité @if ($sourceGroup) (via
                                                    <a href="{{ route('app.parc.groups.show', $sourceGroup->id) }}"
                                                        class="link">{{ $sourceGroup->name }}</a>)
                                                @endif
                                            </span>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- Applications directes --}}
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="card-title text-base">
                            <i class="fa-solid fa-cube text-primary"></i>
                            Applications
                            <span class="badge badge-ghost">{{ $directApps->count() + $inheritedApps->count() }}</span>
                        </h3>
                        @can('assign-wpkg-workstationGroup', $wpkgScope)
                            <button type="button" class="btn btn-primary btn-sm gap-2"
                                wire:click="openAttachWpkgAppModal">
                                <i class="fa-solid fa-plus"></i>
                                Ajouter directement
                            </button>
                        @endcan
                    </div>

                    @if ($directApps->isEmpty() && $inheritedApps->isEmpty())
                        <div class="text-center py-6 text-base-content/60 text-sm">
                            Aucune application directement assignée à ce poste.
                        </div>
                    @else
                        <ul class="divide-y divide-base-200">
                            @foreach ($directApps as $app)
                                <li wire:key="ws-direct-app-{{ $app->id }}"
                                    class="flex items-center justify-between py-2">
                                    <div>
                                        <div class="font-medium">
                                            {{ $app->name }}
                                            <span class="badge badge-success badge-sm ml-2">direct</span>
                                        </div>
                                        <div class="text-xs text-base-content/60">
                                            <code>{{ $app->app_id }}</code>
                                            @if ($app->category)
                                                • {{ $app->category }}
                                            @endif
                                        </div>
                                    </div>
                                    @can('assign-wpkg-workstationGroup', $wpkgScope)
                                        <button type="button" class="btn btn-ghost btn-xs btn-square text-error"
                                            wire:click="detachWpkgApplication({{ $app->id }})"
                                            wire:confirm="Retirer cette application du poste ?">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    @endcan
                                </li>
                            @endforeach
                            @foreach ($inheritedApps as $app)
                                @php $sourceGroup = $app->_inheritedFromGroup ?? null; @endphp
                                <li wire:key="ws-inherit-app-{{ $app->id }}"
                                    class="flex items-center justify-between py-2">
                                    <div>
                                        <div class="font-medium">
                                            {{ $app->name }}
                                            <span class="badge badge-info badge-sm ml-2">
                                                hérité @if ($sourceGroup) (via
                                                    <a href="{{ route('app.parc.groups.show', $sourceGroup->id) }}"
                                                        class="link">{{ $sourceGroup->name }}</a>)
                                                @endif
                                            </span>
                                        </div>
                                        <div class="text-xs text-base-content/60">
                                            <code>{{ $app->app_id }}</code>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
    </div>
</div>
