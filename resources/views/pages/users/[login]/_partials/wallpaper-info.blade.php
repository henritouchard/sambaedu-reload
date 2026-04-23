<!-- Fond d'écran personnel (story 4.7 AC 11) — conditionnel config -->
@if (config('wallpapers.allow_per_user', true))
    @can('wallpaper.manage')
        @php $userModel = $user ? \App\Models\User::where('login', $user->login)->first() : null; @endphp
        @if ($userModel)
            {{-- Affiché en priorité sur tous les postes où cet utilisateur se connecte
                        (écrase la configuration du groupe et de la salle). --}}
            <div class="max-w-xl">
                <livewire:components::molecules.wallpaper-card type="wallpaper" :ownerType="App\Models\User::class" :ownerId="$userModel->id"
                    title="Fond d'écran de l'utilisateur" description="Surcharge tous les autres niveaux sauf /home/perso."
                    :key="'wallpaper-user-' . $userModel->id" />
            </div>
        @endif
    @endcan
@endif