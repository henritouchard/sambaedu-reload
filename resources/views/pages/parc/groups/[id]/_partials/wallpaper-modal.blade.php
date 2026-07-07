@if ($group->is_physical)
    @can('wallpaper.manage')
        {{-- Sélecteurs de fond d'écran en modale large (refonte UX 2026-06).
             Chaque picker gère sa propre modale, ouverte par l'event
             `open-wp-picker` dispatché par les boutons « Bureau » / « Verr. ». --}}
        <livewire:components::molecules.wallpaper-library-picker
            type="wallpaper"
            :ownerType="App\Models\WorkstationGroup::class"
            :ownerId="$group->id"
            title="Fond d'écran — {{ $group->display_name_or_name }}"
            :key="'wp-picker-wallpaper-' . $group->id" />

        <livewire:components::molecules.wallpaper-library-picker
            type="lockscreen"
            :ownerType="App\Models\WorkstationGroup::class"
            :ownerId="$group->id"
            title="Écran de verrouillage — {{ $group->display_name_or_name }}"
            :key="'wp-picker-lockscreen-' . $group->id" />
    @endcan
@endif
