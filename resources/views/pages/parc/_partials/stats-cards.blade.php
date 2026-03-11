<!-- Cartes de statistiques -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    @if (!$statsLoaded)
        @for ($i = 0; $i < 4; $i++)
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body py-4">
                    <div class="skeleton h-4 w-20 mb-2"></div>
                    <div class="skeleton h-8 w-16"></div>
                </div>
            </div>
        @endfor
    @else
        <!-- Machines actives -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-success/10 flex items-center justify-center">
                        <i class="fa-solid fa-computer text-success"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Postes actifs</div>
                        <div class="text-2xl font-bold">{{ $machineStats['active'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Postes sans groupe -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-warning/10 flex items-center justify-center">
                        <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Sans groupe</div>
                        <div class="text-2xl font-bold">{{ $machineStats['without_group'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Salles -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                        <i class="fa-solid fa-door-open text-primary"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Salles</div>
                        <div class="text-2xl font-bold">{{ $groupStats['rooms'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Parcs logiques -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-secondary/10 flex items-center justify-center">
                        <i class="fa-solid fa-layer-group text-secondary"></i>
                    </div>
                    <div>
                        <div class="text-sm text-base-content/60">Parcs logiques</div>
                        <div class="text-2xl font-bold">{{ $groupStats['parcs'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
