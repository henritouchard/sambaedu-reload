<?php

use Livewire\Component;
use App\Services\UserService;
use App\Repositories\FunctionRepository;
use App\Constants\Ldap\FunctionGroups;
use App\Components\Traits\WithToasts;
use App\Types\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

new class extends Component {
    use WithToasts;

    #[Locked]
    public string $login = '';
    public string $categorie = '';
    public string $fonction = '';
    public string $originalCategorie = '';
    public string $originalFonction = '';

    public bool $editMode = false;
    public bool $canEdit = false;
    public ?User $user = null;
    public array $fonctions = [];

    private UserService $userService;
    private FunctionRepository $functionRepository;
    private \App\Repositories\EstablishmentRepository $establishmentRepository;

    public function boot(UserService $userService, FunctionRepository $functionRepository, \App\Repositories\EstablishmentRepository $establishmentRepository)
    {
        $this->userService = $userService;
        $this->functionRepository = $functionRepository;
        $this->establishmentRepository = $establishmentRepository;
    }

    public function mount(User $user)
    {
        $this->user = $user;
        $this->login = $user->login ?? '';
        $this->loadCurrentRole();
        $this->canEdit = Gate::allows('update-user') && $this->login !== 'Administrator';
    }

    private function loadCurrentRole(): void
    {
        if (!$this->user) {
            return;
        }

        // Déterminer la catégorie depuis le rôle SQL ou le DN
        $this->categorie = match (strtolower($this->user->role ?? '')) {
            'eleve', 'eleves' => 'Eleves',
            'prof', 'profs' => 'Profs',
            'admin', 'administratif', 'administratifs' => 'Administratifs',
            default => 'Eleves',
        };

        // Extraire la fonction depuis le DN (sous-OU de la catégorie)
        $this->fonction = $this->extractFonctionFromDn($this->user->dn ?? '');

        $this->originalCategorie = $this->categorie;
        $this->originalFonction = $this->fonction;

        $this->loadFonctions();
    }

    private function extractFonctionFromDn(string $dn): string
    {
        if (empty($dn)) {
            return '';
        }

        // Le DN a la forme : CN=login,OU=Fonction,OU=Categorie,OU=Utilisateurs,...
        // On cherche la première OU après le CN qui n'est pas une catégorie principale
        $parts = ldap_explode_dn($dn, 1);
        if ($parts === false) {
            return '';
        }
        unset($parts['count']);

        $categories = ['Eleves', 'Profs', 'Administratifs'];
        // parts[0] = login, parts[1] = éventuellement la fonction, parts[2] = catégorie...
        // Note: ldap_explode_dn($dn, 1) strip les préfixes (OU=, CN=), donc parts[] contient les valeurs nues
        if (isset($parts[1]) && !in_array($parts[1], $categories)) {
            if (FunctionGroups::isFunctionGroup($parts[1])) {
                return $parts[1];
            }
        }

        return '';
    }

    public function loadFonctions(): void
    {
        try {
            $repoCat = match ($this->categorie) {
                'Administratifs' => 'Administratifs',
                'Profs' => 'Pedagogiques',
                default => 'all',
            };
            $fonctionsData = $this->functionRepository->getAll($repoCat);
            $this->fonctions = array_map(fn($f) => $f['cn'] ?? $f, $fonctionsData);

            if (empty($this->fonctions)) {
                $this->fonctions = FunctionGroups::forCategory($this->categorie);
            }
        } catch (\Exception $e) {
            $this->fonctions = FunctionGroups::forCategory($this->categorie);
        }
    }

    public function updatedCategorie(): void
    {
        $this->fonction = '';
        $this->loadFonctions();
    }

    public function toggleEditMode(): void
    {
        if (!$this->canEdit) {
            return;
        }

        $this->editMode = !$this->editMode;

        if (!$this->editMode) {
            // Annuler : restaurer les valeurs d'origine
            $this->categorie = $this->originalCategorie;
            $this->fonction = $this->originalFonction;
            $this->loadFonctions();
        }
    }

    public function save(): void
    {
        if (!Gate::allows('update-user')) {
            $this->toastError('Vous n\'avez pas les droits pour modifier cet utilisateur.');
            return;
        }

        // Validation
        $this->validate([
            'categorie' => 'required|in:Eleves,Profs,Administratifs',
            'fonction' => $this->categorie === 'Administratifs' ? 'required|string' : 'nullable|string',
        ], [
            'fonction.required' => 'La fonction est obligatoire pour les Administratifs.',
        ]);

        // Vérifier s'il y a un changement
        if ($this->categorie === $this->originalCategorie && $this->fonction === $this->originalFonction) {
            $this->toastInfo('Aucun changement détecté.');
            $this->editMode = false;
            return;
        }

        try {
            $etab = $this->establishmentRepository->getDefaultUai();

            $result = $this->userService->changeUserRole(
                $this->login,
                $this->categorie,
                $this->fonction,
                $etab
            );

            if ($result['success']) {
                $this->originalCategorie = $this->categorie;
                $this->originalFonction = $this->fonction;
                $this->editMode = false;

                $this->toastSuccess($result['message']);

                // Rafraîchir la page pour refléter le nouveau rôle dans le header
                $this->dispatch('user-updated');
                $this->redirect(route('app.user.show', $this->login), navigate: true);
            } else {
                $this->toastError($result['message']);
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors du changement de rôle', [
                'login' => $this->login,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Une erreur est survenue lors du changement de rôle.');
        }
    }

    public function hasChanged(): bool
    {
        return $this->categorie !== $this->originalCategorie || $this->fonction !== $this->originalFonction;
    }
};

?>

<div
    class="bg-gradient-to-br from-info/10 via-secondary/5 to-accent/10 rounded-3xl border border-base-300 shadow-xl backdrop-blur-sm overflow-hidden">
    <div class="p-8">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-4">
                <div
                    class="w-12 h-12 bg-gradient-to-br from-info to-info/80 rounded-2xl flex items-center justify-center shadow-lg ring-4 ring-info/20">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                        </path>
                    </svg>
                </div>
                <div>
                    <h2 class="text-2xl font-black text-base-content">Catégorie et fonction</h2>
                    <p class="text-sm text-base-content/60 font-medium">Rôle dans l'établissement</p>
                </div>
            </div>

            @if ($canEdit)
                @if ($editMode)
                    <div class="flex gap-3">
                        <button type="button" wire:click="save"
                            wire:confirm="Changer le rôle déplacera l'utilisateur dans l'annuaire AD et modifiera ses groupes. Confirmer ?"
                            class="btn btn-success btn-sm gap-2 hover:scale-105 transition-transform duration-300 shadow-lg">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 13l4 4L19 7"></path>
                            </svg>
                            Appliquer
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
                    <!-- Catégorie -->
                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Catégorie <span
                                    class="text-error">*</span></span>
                        </label>
                        <select wire:model.live="categorie" class="select select-bordered w-full">
                            <option value="Eleves">Élève</option>
                            <option value="Profs">Professeur</option>
                            <option value="Administratifs">Administratif</option>
                        </select>
                        @error('categorie')
                            <span class="text-error text-sm">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Fonction (conditionnelle) -->
                    @if ($categorie === 'Administratifs' || $categorie === 'Profs')
                        <div>
                            <label class="label">
                                <span class="label-text font-medium text-base-content/70">
                                    Fonction
                                    @if ($categorie === 'Administratifs')
                                        <span class="text-error">*</span>
                                    @endif
                                </span>
                            </label>
                            <select wire:model="fonction"
                                class="select select-bordered w-full @error('fonction') select-error @enderror">
                                <option value="">Sélectionnez une fonction</option>
                                @foreach ($fonctions as $f)
                                    <option value="{{ $f }}">{{ $f }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-base-content/50 mt-1">
                                {{ $categorie === 'Administratifs' ? 'Obligatoire pour les administratifs' : 'Optionnel pour les professeurs' }}
                            </p>
                            @error('fonction')
                                <span class="text-error text-sm">{{ $message }}</span>
                            @enderror
                        </div>
                    @endif
                </div>

                @if ($this->hasChanged())
                    <div class="alert alert-warning shadow-lg">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z">
                            </path>
                        </svg>
                        <span>Ce changement déplacera l'utilisateur dans l'annuaire AD et modifiera ses groupes d'appartenance.</span>
                    </div>
                @endif
            </form>
        @else
            <!-- MODE AFFICHAGE -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="label">
                        <span class="label-text font-medium text-base-content/70">Catégorie</span>
                    </label>
                    <div class="text-base-content font-medium">
                        @php
                            $categorieLabel = match ($categorie) {
                                'Eleves' => 'Élève',
                                'Profs' => 'Professeur',
                                'Administratifs' => 'Administratif',
                                default => $categorie,
                            };
                        @endphp
                        {{ $categorieLabel }}
                    </div>
                </div>

                <div>
                    <label class="label">
                        <span class="label-text font-medium text-base-content/70">Fonction</span>
                    </label>
                    <div class="text-base-content font-medium">
                        {{ $fonction ?: '-' }}
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
