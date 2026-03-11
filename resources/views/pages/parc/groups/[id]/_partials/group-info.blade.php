<!-- Informations du groupe -->
<div class="lg:col-span-1 space-y-6">
    <!-- Carte d'information -->
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-16 h-16 rounded-xl bg-primary/10 flex items-center justify-center">
                    <i class="fa-solid fa-layer-group text-primary text-2xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold">{{ $group->name }}</h2>
                    @if ($group->is_physical)
                        <span class="badge badge-success gap-1">
                            <i class="fa-solid fa-door-open text-xs"></i>
                            Salle physique
                        </span>
                    @else
                        <span class="badge badge-info gap-1">
                            <i class="fa-solid fa-layer-group text-xs"></i>
                            Groupe logique
                        </span>
                    @endif
                </div>
            </div>

            @if ($group->description)
                <p class="text-base-content/70 mb-4">{{ $group->description }}</p>
            @endif

            <div class="divider my-2"></div>

            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-base-content/60">UUID</span>
                    <span class="font-mono text-xs">{{ Str::limit($group->uuid, 12) }}...</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-base-content/60">Type</span>
                    @if ($group->is_physical)
                        <span class="badge badge-success badge-sm gap-1">
                            <i class="fa-solid fa-door-open text-xs"></i>
                            Salle physique
                        </span>
                    @else
                        <span class="badge badge-info badge-sm gap-1">
                            <i class="fa-solid fa-layer-group text-xs"></i>
                            Groupe logique
                        </span>
                    @endif
                </div>
                <div class="flex justify-between">
                    <span class="text-base-content/60">Sync AD</span>
                    @if ($group->isSyncedWithAd())
                        <span class="badge badge-success badge-sm">Synchronisé</span>
                    @else
                        <span class="badge badge-warning badge-sm">En attente</span>
                    @endif
                </div>
                @if ($group->parent)
                    <div class="flex justify-between">
                        <span class="text-base-content/60">Parent</span>
                        <a href="{{ route('app.parc.groups.show', $group->parent->id) }}" class="link link-primary">
                            {{ $group->parent->name }}
                        </a>
                    </div>
                @endif
                <div class="flex justify-between">
                    <span class="text-base-content/60">Créé le</span>
                    <span>{{ $group->created_at->format('d/m/Y H:i') }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistiques -->
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <h3 class="card-title text-base mb-4">
                <i class="fa-solid fa-chart-pie text-primary"></i>
                Statistiques
            </h3>
            <div class="stats stats-vertical shadow-none">
                <div class="stat px-0">
                    <div class="stat-title">Machines</div>
                    <div class="stat-value text-primary">{{ $group->workstations()->count() }}</div>
                </div>
                <div class="stat px-0">
                    <div class="stat-title">Sous-groupes</div>
                    <div class="stat-value">{{ $group->children()->count() }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sous-groupes -->
    @if ($group->children->count() > 0)
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h3 class="card-title text-base mb-4">
                    <i class="fa-solid fa-folder-tree text-primary"></i>
                    Sous-groupes
                </h3>
                <ul class="space-y-2">
                    @foreach ($group->children as $child)
                        <li>
                            <a href="{{ route('app.parc.groups.show', $child->id) }}"
                                class="flex items-center gap-2 p-2 rounded-lg hover:bg-base-200 transition-colors">
                                <i class="fa-solid fa-layer-group text-base-content/50"></i>
                                <span>{{ $child->name }}</span>
                                <span class="badge badge-ghost badge-sm ml-auto">
                                    {{ $child->workstations()->count() }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
