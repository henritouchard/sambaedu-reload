<!-- Cibles assignées au raccourci -->
<div class="card bg-base-100 shadow-sm border border-base-300">
    <div class="card-body">
        <div class="flex items-center justify-between mb-4">
            <h3 class="card-title flex gap-4">
                <i class="fa-solid fa-bullseye"></i>
                Cibles assignées
            </h3>
            @if (!$isGlobal)
                <button type="button" class="btn btn-sm btn-primary"
                    wire:click="openAssignmentModal">
                    <i class="fa-solid fa-plus"></i>
                    Gérer les assignations
                </button>
            @endif
        </div>

        @if ($isGlobal)
            <div class="alert alert-warning">
                <i class="fa-solid fa-lock"></i>
                <span>Ce raccourci est géré par le ControlHub. Les assignations ne peuvent pas être modifiées ici.</span>
            </div>
        @endif

        @php
            $hasAny = count($assignedWorkstationGroups) > 0
                || count($assignedWorkstations) > 0
                || count($assignedUserGroups) > 0
                || count($assignedUsers) > 0;
        @endphp

        @if ($hasAny)
            <div class="space-y-4">

                {{-- Groupes de postes --}}
                @if (count($assignedWorkstationGroups) > 0)
                    <div>
                        <h4 class="text-sm font-semibold text-base-content/70 mb-2">
                            <i class="fa-solid fa-layer-group mr-1"></i> Groupes de postes
                        </h4>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($assignedWorkstationGroups as $group)
                                <div class="badge gap-1 {{ $group->is_physical ? 'badge-warning' : 'badge-primary' }}">
                                    <i class="fa-solid {{ $group->is_physical ? 'fa-door-open' : 'fa-layer-group' }} text-xs"></i>
                                    {{ $group->display_name ?? $group->name }}
                                    @if (!$isGlobal)
                                        <button type="button" class="ml-1 hover:text-error"
                                            wire:click="detachWorkstationGroup({{ $group->id }})"
                                            wire:confirm="Retirer « {{ $group->display_name ?? $group->name }} » ?">
                                            <i class="fa-solid fa-xmark text-xs"></i>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Postes individuels --}}
                @if (count($assignedWorkstations) > 0)
                    <div>
                        <h4 class="text-sm font-semibold text-base-content/70 mb-2">
                            <i class="fa-solid fa-computer mr-1"></i> Postes de travail
                        </h4>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($assignedWorkstations as $ws)
                                <div class="badge badge-info gap-1">
                                    <i class="fa-solid fa-computer text-xs"></i>
                                    {{ $ws->name }}
                                    @if (!$isGlobal)
                                        <button type="button" class="ml-1 hover:text-error"
                                            wire:click="detachWorkstation({{ $ws->id }})"
                                            wire:confirm="Retirer le poste « {{ $ws->name }} » ?">
                                            <i class="fa-solid fa-xmark text-xs"></i>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Groupes d'utilisateurs --}}
                @if (count($assignedUserGroups) > 0)
                    <div>
                        <h4 class="text-sm font-semibold text-base-content/70 mb-2">
                            <i class="fa-solid fa-users mr-1"></i> Groupes d'utilisateurs
                        </h4>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($assignedUserGroups as $group)
                                @php $label = $group->display_name ?: $group->name; @endphp
                                <div class="badge badge-secondary gap-1">
                                    <i class="fa-solid fa-users text-xs"></i>
                                    {{ $label }}
                                    @if (!$isGlobal)
                                        <button type="button" class="ml-1 hover:text-error"
                                            wire:click="detachUserGroup({{ $group->id }})"
                                            wire:confirm="Retirer le groupe « {{ $label }} » ?">
                                            <i class="fa-solid fa-xmark text-xs"></i>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Utilisateurs --}}
                @if (count($assignedUsers) > 0)
                    <div>
                        <h4 class="text-sm font-semibold text-base-content/70 mb-2">
                            <i class="fa-solid fa-user mr-1"></i> Utilisateurs
                        </h4>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($assignedUsers as $user)
                                <div class="badge badge-accent gap-1">
                                    <i class="fa-solid fa-user text-xs"></i>
                                    {{ $user->login }}
                                    @if (!$isGlobal)
                                        <button type="button" class="ml-1 hover:text-error"
                                            wire:click="detachUser({{ $user->id }})"
                                            wire:confirm="Retirer l'utilisateur « {{ $user->login }} » ?">
                                            <i class="fa-solid fa-xmark text-xs"></i>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @else
            <div class="text-center py-8 text-base-content/50">
                <i class="fa-solid fa-bullseye text-3xl mb-2"></i>
                <p>Aucune cible assignée</p>
                <p class="text-sm">Ce raccourci n'est assigné à aucun groupe, poste ou utilisateur.</p>
            </div>
        @endif
    </div>
</div>

<!-- Modal d'assignation -->
@if (!$isGlobal)
    <livewire:organisms.shortcut-assignment-modal />
@endif
