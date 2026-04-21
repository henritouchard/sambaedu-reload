<!-- Onglet Fonds d'écran (story 4.7 AC 9) — conditionnel is_physical -->
@if ($group->is_physical)
    @can('wallpaper.manage')
        <div class="card bg-base-100 shadow-sm mt-6">
            <div class="card-body">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="card-title text-base">
                        <i class="fa-solid fa-image text-primary"></i>
                        Fonds d'écran de la salle
                    </h3>
                    <span class="badge badge-info badge-outline">Salle : {{ $group->name }}</span>
                </div>
                <p class="text-sm text-base-content/60 mb-4">
                    Les fonds d'écran définis ici ont la priorité sur ceux de l'établissement.
                </p>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <livewire:components::molecules.wallpaper-card
                        type="wallpaper"
                        :ownerType="App\Models\WorkstationGroup::class"
                        :ownerId="$group->id"
                        title="Fond d'écran"
                        description="Affiché sur les postes de cette salle au login utilisateur."
                        :key="'wallpaper-salle-' . $group->id" />

                    <livewire:components::molecules.wallpaper-card
                        type="lockscreen"
                        :ownerType="App\Models\WorkstationGroup::class"
                        :ownerId="$group->id"
                        title="Écran de verrouillage"
                        description="Affiché sur les postes de cette salle au boot et au verrouillage."
                        :key="'lockscreen-salle-' . $group->id" />
                </div>
            </div>
        </div>
    @endcan
@endif
