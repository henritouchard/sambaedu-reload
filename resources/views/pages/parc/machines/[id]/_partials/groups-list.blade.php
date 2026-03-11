<!-- Groupes de la machine -->
<div class="lg:col-span-2 space-y-6">

    <!-- Section Salle Physique (unique) -->
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <div class="flex items-center justify-between mb-4">
                <h3 class="card-title text-base">
                    <i class="fa-solid fa-door-open text-warning"></i>
                    Salle Physique
                    @if ($workstation->physicalRoom)
                        <span class="badge badge-warning badge-sm">Assignée</span>
                    @endif
                </h3>
            </div>

            <p class="text-sm text-base-content/60 mb-4">
                Une machine ne peut appartenir qu'à une seule salle physique à la fois.
            </p>

            @if ($workstation->physicalRoom)
                <!-- Salle actuelle -->
                <div class="card bg-warning/10 border border-warning/30">
                    <div class="card-body p-4">
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-lg bg-warning/20 flex items-center justify-center">
                                    <i class="fa-solid fa-door-open text-warning"></i>
                                </div>
                                <div>
                                    <a href="{{ route('app.parc.groups.show', $workstation->physicalRoom->id) }}"
                                        class="font-medium hover:text-warning">
                                        {{ $workstation->physicalRoom->name }}
                                    </a>
                                    <div class="text-xs text-base-content/60">
                                        Salle physique • {{ $workstation->physicalRoom->getAdStatusLabel() }}
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm btn-square text-error"
                                wire:click="removeFromPhysicalRoom"
                                wire:confirm="Retirer ce poste de la salle physique ?">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        @if ($workstation->physicalRoom->description)
                            <p class="text-sm text-base-content/60 mt-2">
                                {{ Str::limit($workstation->physicalRoom->description, 100) }}
                            </p>
                        @endif
                    </div>
                </div>

                <!-- Formulaire de changement de salle -->
                <div class="mt-4">
                    <div class="divider text-xs">Changer de salle</div>
                    <button type="button"
                        wire:click="$dispatch('open-workstation-group-selector', { drawerId: 'change-physical-room', groups: {{ $availablePhysicalRooms->filter(fn($r) => $r->id !== $workstation->physical_room_id)->values()->toJson() }} })"
                        class="btn btn-warning btn-sm gap-2">
                        <i class="fa-solid fa-arrows-rotate"></i>
                        Changer de salle
                    </button>
                </div>
            @else
                <!-- Aucune salle assignée -->
                <button type="button"
                    wire:click="$dispatch('open-workstation-group-selector', { drawerId: 'assign-physical-room', groups: {{ $availablePhysicalRooms->toJson() }} })"
                    class="btn btn-warning btn-sm gap-2">
                    <i class="fa-solid fa-plus"></i>
                    Assigner une salle
                </button>

                @if ($availablePhysicalRooms->isEmpty())
                    <div class="alert alert-info mt-4">
                        <i class="fa-solid fa-info-circle"></i>
                        <span>Créez d'abord une salle physique dans la gestion des groupes.</span>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <!-- Section Groupes Logiques (multiples) -->
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <div class="flex items-center justify-between mb-4">
                <h3 class="card-title text-base">
                    <i class="fa-solid fa-layer-group text-primary"></i>
                    Groupes Logiques
                    <span class="badge badge-ghost">{{ $workstation->groups->count() }}</span>
                </h3>
            </div>

            <p class="text-sm text-base-content/60 mb-4">
                Une machine peut appartenir à plusieurs groupes logiques simultanément.
            </p>

            <!-- Sélecteur de groupes logiques (multiple) -->
            @if ($availableLogicalGroups->isNotEmpty())
                <div class="mb-6">
                    <button type="button"
                        wire:click="$dispatch('open-workstation-group-selector', { drawerId: 'add-logical-groups', groups: {{ $availableLogicalGroups->toJson() }} })"
                        class="btn btn-primary btn-sm gap-2">
                        <i class="fa-solid fa-plus"></i>
                        Ajouter aux groupes
                    </button>
                </div>
            @endif

            @if ($workstation->groups->isEmpty())
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <div class="text-4xl mb-4 opacity-20">
                        <i class="fa-solid fa-folder-open"></i>
                    </div>
                    <h4 class="text-base font-semibold mb-2">Aucun groupe logique</h4>
                    <p class="text-base-content/60 text-sm max-w-sm">
                        Ce poste n'appartient à aucun groupe logique. Utilisez le sélecteur ci-dessus pour l'ajouter.
                    </p>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach ($workstation->groups as $group)
                        <div class="card bg-base-200/50 border border-base-300">
                            <div class="card-body p-4">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                                            <i class="fa-solid fa-layer-group text-primary"></i>
                                        </div>
                                        <div>
                                            <a href="{{ route('app.parc.groups.show', $group->id) }}"
                                                class="font-medium hover:text-primary">
                                                {{ $group->name }}
                                            </a>
                                            <div class="text-xs text-base-content/60">
                                                Groupe logique • {{ $group->getAdStatusLabel() }}
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-ghost btn-sm btn-square text-error"
                                        wire:click="removeFromLogicalGroup({{ $group->id }})"
                                        wire:confirm="Retirer ce poste du groupe logique ?">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>
                                @if ($group->description)
                                    <p class="text-sm text-base-content/60 mt-2">
                                        {{ Str::limit($group->description, 80) }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($availableLogicalGroups->isEmpty() && $workstation->groups->isNotEmpty())
                <div class="alert alert-success mt-4">
                    <i class="fa-solid fa-check-circle"></i>
                    <span>Cette machine appartient à tous les groupes logiques disponibles.</span>
                </div>
            @endif
        </div>
    </div>
</div>
