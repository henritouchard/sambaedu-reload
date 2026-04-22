@if ($group->is_physical)
    @can('wallpaper.manage')
        @teleport('body')
            <dialog class="modal"
                x-data="{ open: @entangle('showWallpaperModal') }"
                :class="{ 'modal-open': open }"
                x-cloak>
                <div class="modal-box w-11/12 max-w-3xl">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-lg font-bold flex items-center gap-2">
                            <i class="fa-solid fa-image text-primary"></i>
                            Fonds d'écran — {{ $group->name }}
                        </h3>
                        <button type="button" class="btn btn-ghost btn-sm btn-circle" wire:click="closeWallpaperModal">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <p class="text-sm text-base-content/60 mb-6">
                        Les fonds d'écran définis ici ont la priorité sur ceux de l'établissement.
                    </p>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <livewire:components::molecules.wallpaper-card
                            type="wallpaper"
                            :ownerType="App\Models\WorkstationGroup::class"
                            :ownerId="$group->id"
                            title="Fond d'écran"
                            :key="'wallpaper-salle-' . $group->id" />

                        <livewire:components::molecules.wallpaper-card
                            type="lockscreen"
                            :ownerType="App\Models\WorkstationGroup::class"
                            :ownerId="$group->id"
                            title="Écran de verrouillage"
                            :key="'lockscreen-salle-' . $group->id" />
                    </div>

                    <div class="modal-action">
                        <button type="button" class="btn" wire:click="closeWallpaperModal">Fermer</button>
                    </div>
                </div>
                <form method="dialog" class="modal-backdrop">
                    <button wire:click="closeWallpaperModal">fermer</button>
                </form>
            </dialog>
        @endteleport
    @endcan
@endif
