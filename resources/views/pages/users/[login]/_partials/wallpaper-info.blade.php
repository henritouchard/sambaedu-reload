<!-- Fond d'écran personnel (story 4.7 AC 11) — conditionnel config -->
@if (config('wallpapers.allow_per_user', true))
    @can('wallpaper.manage')
        @if ($user && $user->id)
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="card-title text-base">
                            <i class="fa-solid fa-image text-primary"></i>
                            Fond d'écran personnel
                        </h3>
                        <span class="badge badge-info badge-outline">{{ $user->login }}</span>
                    </div>
                    <p class="text-sm text-base-content/60 mb-4">
                        Affiché en priorité sur tous les postes où cet utilisateur se connecte
                        (écrase la configuration du groupe et de la salle).
                    </p>

                    <div class="max-w-xl">
                        <livewire:components.molecules.wallpaper-card
                            type="wallpaper"
                            :ownerType="App\Models\User::class"
                            :ownerId="$user->id"
                            title="Fond d'écran de l'utilisateur"
                            description="Surcharge tous les autres niveaux sauf /home/perso."
                            :key="'wallpaper-user-' . $user->id" />
                    </div>
                </div>
            </div>
        @endif
    @endcan
@endif
