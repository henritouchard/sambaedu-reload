<?php

use Livewire\Component;
use App\Services\UserService;
use App\Types\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Devrabiul\ToastMagic\Facades\ToastMagic;

new class extends Component {
    #[Locked]
    public string $login = '';
    public string $prenom = '';
    public string $nom = '';
    public string $email = '';
    public string $phone = '';
    public string $description = '';

    public bool $editMode = false;
    public bool $canEdit = false;
    public ?User $user = null;

    private UserService $userService;

    public function boot(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function mount(User $user)
    {
        $this->user = $user;
        $this->login = $user->login ?? '';
        $this->loadUserData();

        // Vérifier les permissions d'édition
        $this->canEdit = Gate::allows('update-user') && $this->login !== 'Administrator';
    }

    private function loadUserData(): void
    {
        if (!$this->user) {
            return;
        }

        $this->prenom = $this->extractValue($this->user->firstname);
        $this->nom = $this->extractValue($this->user->lastname);
        $this->email = $this->extractValue($this->user->email);
        $this->phone = $this->extractValue($this->user->phone);
        $this->description = $this->extractValue($this->user->description);
    }

    private function extractValue($value): string
    {
        if (is_array($value)) {
            return $value[0] ?? '';
        }
        return $value ?? '';
    }

    public function toggleEditMode(): void
    {
        if (!$this->canEdit) {
            return;
        }

        $this->editMode = !$this->editMode;

        // Recharger les données si on annule
        if (!$this->editMode) {
            $this->loadUserData();
        }
    }

    public function save(): void
    {
        if (!Gate::allows('update-user')) {
            ToastMagic::error('Vous n\'avez pas les droits pour modifier cet utilisateur.');
            return;
        }

        $this->validate([
            'prenom' => 'required|string|max:64',
            'nom' => 'required|string|max:64',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
        ]);

        try {
            $result = $this->userService->updatePersonalInfo($this->login, [
                'prenom' => $this->prenom,
                'nom' => $this->nom,
                'email' => $this->email,
                'phone' => $this->phone,
                'description' => $this->description,
            ]);

            if ($result['success']) {
                // Recharger les données depuis SQL
                $this->user = $this->userService->getByLoginFromSql($this->login);
                $this->loadUserData();

                $this->editMode = false;
                ToastMagic::success($result['message']);

                // Rafraîchir le composant parent (header)
                $this->dispatch('user-updated');
            } else {
                ToastMagic::error($result['message']);
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour des informations personnelles', [
                'login' => $this->login,
                'error' => $e->getMessage(),
            ]);
            ToastMagic::error('Une erreur est survenue lors de la mise à jour.');
        }
    }
};

?>

<div
    class="bg-gradient-to-br from-primary/10 via-secondary/5 to-accent/10 rounded-3xl border border-base-200/50 shadow-xl backdrop-blur-sm h-full overflow-hidden mt-6">
    <div class="p-8">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-4">
                <div
                    class="w-12 h-12 bg-gradient-to-br from-primary to-primary/80 rounded-2xl flex items-center justify-center shadow-lg ring-4 ring-primary/20 group-hover:scale-110 transition-transform duration-300">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                </div>
                <div>
                    <h2
                        class="text-2xl font-black text-base-content bg-gradient-to-r from-base-content to-base-content/80 bg-clip-text">
                        Informations personnelles</h2>
                    <p class="text-sm text-base-content/60 font-medium">Données de base de l'utilisateur</p>
                </div>
            </div>

            @if ($canEdit)
                @if ($editMode)
                    <div class="flex gap-3">
                        <button type="button" wire:click="save"
                            class="btn btn-success btn-sm gap-2 hover:scale-105 transition-transform duration-300 shadow-lg">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 13l4 4L19 7"></path>
                            </svg>
                            Sauvegarder
                        </button>
                        <button type="button" wire:click="toggleEditMode"
                            class="btn btn-ghost btn-sm hover:bg-base-200/50 transition-all duration-300">
                            Annuler
                        </button>
                    </div>
                @else
                    <button type="button" wire:click="toggleEditMode"
                        class="btn btn-ghost btn-sm gap-2 hover:bg-base-200/50 transition-all duration-300 group">
                        <svg class="w-4 h-4 group-hover:scale-110 transition-transform duration-300" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                            </path>
                        </svg>
                        Modifier
                    </button>
                @endif
            @endif
        </div>

        @if ($editMode)
            <!-- MODE ÉDITION -->
            <form wire:submit="save" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <div>
                            <label class="label">
                                <span class="label-text font-medium text-base-content/70">Prénom</span>
                            </label>
                            <input type="text" wire:model="prenom" class="input input-bordered w-full" required>
                            @error('prenom')
                                <span class="text-error text-sm">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text font-medium text-base-content/70">Nom</span>
                            </label>
                            <input type="text" wire:model="nom" class="input input-bordered w-full" required>
                            @error('nom')
                                <span class="text-error text-sm">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text font-medium text-base-content/70">Téléphone</span>
                            </label>
                            <input type="text" wire:model="phone" class="input input-bordered w-full"
                                placeholder="+33 1 23 45 67 89">
                            @error('phone')
                                <span class="text-error text-sm">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="label">
                                <span class="label-text font-medium text-base-content/70">Email</span>
                            </label>
                            <input type="email" wire:model="email" class="input input-bordered w-full">
                            @error('email')
                                <span class="text-error text-sm">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text font-medium text-base-content/70">Description</span>
                            </label>
                            <textarea wire:model="description" rows="3" class="textarea textarea-bordered w-full"></textarea>
                            @error('description')
                                <span class="text-error text-sm">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                </div>
            </form>
        @else
            <!-- MODE AFFICHAGE -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-4">
                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Prénom</span>
                        </label>
                        <div class="text-base-content font-medium">
                            {{ $prenom ?: '-' }}
                        </div>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Nom</span>
                        </label>
                        <div class="text-base-content font-medium">
                            {{ $nom ?: '-' }}
                        </div>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Téléphone</span>
                        </label>
                        <div class="text-base-content">
                            {{ $phone ?: '-' }}
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Email</span>
                        </label>
                        <div class="text-base-content">
                            @if (!empty($email))
                                <a href="mailto:{{ $email }}"
                                    class="link link-primary font-medium">{{ $email }}</a>
                            @else
                                <span class="text-base-content/50">-</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Description</span>
                        </label>
                        <div class="text-base-content">
                            {{ $description ?: '-' }}
                        </div>
                    </div>

                </div>
            </div>
        @endif
    </div>
</div>
