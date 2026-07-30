<?php

use Livewire\Component;
use Livewire\Attributes\Locked;
use App\Services\UserService;
use App\Repositories\FunctionRepository;
use App\Repositories\EstablishmentRepository;
use App\Constants\Ldap\FunctionGroups;
use App\Components\Traits\WithToasts;
use App\Types\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Card « Profil » : identité (état civil, contact) et rattachement
 * (catégorie / fonction) d'un utilisateur dans une seule card et un seul mode
 * édition. Fusionne les anciennes cards « Informations personnelles » et
 * « Catégorie et fonction » — deux formulaires qui décrivaient la même chose.
 *
 * Les deux moitiés restent deux écritures distinctes côté annuaire : l'identité
 * est un simple update d'attributs, le changement de catégorie/fonction DÉPLACE
 * l'objet dans l'AD et réécrit ses groupes. Le save n'appelle donc
 * `changeUserRole()` que si la catégorie ou la fonction a bougé.
 */
new class extends Component {
    use WithToasts;

    #[Locked]
    public string $login = '';

    // Identité
    public string $prenom = '';
    public string $nom = '';
    public string $email = '';
    public string $phone = '';
    public string $description = '';

    // Rattachement
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
    private EstablishmentRepository $establishmentRepository;

    public function boot(UserService $userService, FunctionRepository $functionRepository, EstablishmentRepository $establishmentRepository)
    {
        $this->userService = $userService;
        $this->functionRepository = $functionRepository;
        $this->establishmentRepository = $establishmentRepository;
    }

    public function mount(User $user)
    {
        $this->user = $user;
        $this->login = $user->login ?? '';
        $this->loadUserData();
        $this->loadCurrentRole();
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
            // Annuler : restaurer l'état affiché avant l'ouverture du formulaire
            $this->loadUserData();
            $this->categorie = $this->originalCategorie;
            $this->fonction = $this->originalFonction;
            $this->loadFonctions();
            $this->resetValidation();
        }
    }

    /** Le rattachement a bougé : le save devra déplacer l'objet dans l'AD. */
    public function roleHasChanged(): bool
    {
        return $this->categorie !== $this->originalCategorie || $this->fonction !== $this->originalFonction;
    }

    public function save(): void
    {
        if (!Gate::allows('update-user')) {
            $this->toastError('Vous n\'avez pas les droits pour modifier cet utilisateur.');
            return;
        }

        $this->validate([
            'prenom' => 'required|string|max:64',
            'nom' => 'required|string|max:64',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'categorie' => 'required|in:Eleves,Profs,Administratifs',
            'fonction' => $this->categorie === 'Administratifs' ? 'required|string' : 'nullable|string',
        ], [
            'fonction.required' => 'La fonction est obligatoire pour les Administratifs.',
        ]);

        try {
            $result = $this->userService->updatePersonalInfo($this->login, [
                'prenom' => $this->prenom,
                'nom' => $this->nom,
                'email' => $this->email,
                'phone' => $this->phone,
                'description' => $this->description,
            ]);

            if (!$result['success']) {
                $this->toastError($result['message']);
                return;
            }

            // Rattachement inchangé : on s'arrête là, pas de déplacement AD.
            if (!$this->roleHasChanged()) {
                $this->toastSuccess($result['message']);
                $this->closeAndRefresh();
                return;
            }

            $roleResult = $this->userService->changeUserRole(
                $this->login,
                $this->categorie,
                $this->fonction,
                $this->establishmentRepository->getDefaultUai()
            );

            if (!$roleResult['success']) {
                // L'identité est déjà enregistrée : on le dit, pour ne pas laisser
                // croire que rien n'a été écrit.
                $this->toastError($roleResult['message'] . ' Les informations personnelles ont bien été enregistrées.');
                $this->closeAndRefresh();
                return;
            }

            $this->originalCategorie = $this->categorie;
            $this->originalFonction = $this->fonction;
            $this->toastSuccess($roleResult['message']);
            $this->closeAndRefresh();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du profil utilisateur', [
                'login' => $this->login,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Une erreur est survenue lors de la mise à jour.');
        }
    }

    /**
     * Sort du mode édition en rechargeant la page : l'en-tête (nom affiché,
     * badges de catégorie, établissement) et les groupes sont rendus par le
     * parent et mentiraient sinon.
     */
    private function closeAndRefresh(): void
    {
        $this->editMode = false;
        $this->dispatch('user-updated');
        $this->redirect(route('app.user.show', $this->login), navigate: true);
    }
};

?>

<div
    class="bg-gradient-to-br from-primary/10 via-secondary/5 to-accent/10 rounded-3xl border border-base-300 shadow-xl backdrop-blur-sm overflow-hidden">
    <div class="p-6">
        <div class="flex items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-4">
                <div
                    class="w-10 h-10 bg-gradient-to-br from-primary to-primary/80 rounded-xl flex items-center justify-center shadow-lg ring-4 ring-primary/20">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-base-content">Profil</h2>
                    <p class="text-sm text-base-content/60">Identité et rôle dans l'établissement</p>
                </div>
            </div>

            @if ($canEdit)
                @if ($editMode)
                    <div class="flex gap-2">
                        {{-- Le déplacement AD n'est confirmé que s'il va réellement
                             avoir lieu : `categorie` et `fonction` sont bindés en
                             .live, donc le bouton est re-rendu à chaque changement. --}}
                        <button type="button" wire:click="save"
                            @if ($this->roleHasChanged()) wire:confirm="Changer la catégorie ou la fonction déplacera l'utilisateur dans l'annuaire AD et modifiera ses groupes. Confirmer ?" @endif
                            class="btn btn-success btn-sm gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 13l4 4L19 7"></path>
                            </svg>
                            Enregistrer
                        </button>
                        <button type="button" wire:click="toggleEditMode" class="btn btn-ghost btn-sm">
                            Annuler
                        </button>
                    </div>
                @else
                    <button type="button" wire:click="toggleEditMode" class="btn btn-ghost btn-sm gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Prénom <span
                                    class="text-error">*</span></span>
                        </label>
                        <input type="text" wire:model="prenom"
                            class="input input-bordered w-full @error('prenom') input-error @enderror" required>
                        @error('prenom')
                            <span class="text-error text-sm">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Nom <span
                                    class="text-error">*</span></span>
                        </label>
                        <input type="text" wire:model="nom"
                            class="input input-bordered w-full @error('nom') input-error @enderror" required>
                        @error('nom')
                            <span class="text-error text-sm">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Email</span>
                        </label>
                        <input type="email" wire:model="email"
                            class="input input-bordered w-full @error('email') input-error @enderror">
                        @error('email')
                            <span class="text-error text-sm">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Téléphone</span>
                        </label>
                        <input type="text" wire:model="phone"
                            class="input input-bordered w-full @error('phone') input-error @enderror"
                            placeholder="+33 1 23 45 67 89">
                        @error('phone')
                            <span class="text-error text-sm">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="md:col-span-2">
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Description</span>
                        </label>
                        <textarea wire:model="description" rows="2"
                            class="textarea textarea-bordered w-full @error('description') textarea-error @enderror"></textarea>
                        @error('description')
                            <span class="text-error text-sm">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                {{-- Le rattachement est séparé de l'identité : c'est la moitié du
                     formulaire qui déplace l'objet dans l'annuaire. --}}
                <div class="divider text-xs text-base-content/50">Rattachement</div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
                    <div>
                        <label class="label">
                            <span class="label-text font-medium text-base-content/70">Catégorie <span
                                    class="text-error">*</span></span>
                        </label>
                        <select wire:model.live="categorie"
                            class="select select-bordered w-full @error('categorie') select-error @enderror">
                            <option value="Eleves">Élève</option>
                            <option value="Profs">Professeur</option>
                            <option value="Administratifs">Administratif</option>
                        </select>
                        @error('categorie')
                            <span class="text-error text-sm">{{ $message }}</span>
                        @enderror
                    </div>

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
                            <select wire:model.live="fonction"
                                class="select select-bordered w-full @error('fonction') select-error @enderror">
                                <option value="">Sélectionnez une fonction</option>
                                @foreach ($fonctions as $f)
                                    <option value="{{ $f }}">{{ $f }}</option>
                                @endforeach
                            </select>
                            @error('fonction')
                                <span class="text-error text-sm">{{ $message }}</span>
                            @enderror
                        </div>
                    @endif
                </div>

                @if ($this->roleHasChanged())
                    <div class="alert alert-warning shadow-lg">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z">
                            </path>
                        </svg>
                        <span>Ce changement déplacera l'utilisateur dans l'annuaire AD et modifiera ses groupes
                            d'appartenance.</span>
                    </div>
                @endif
            </form>
        @else
            <!-- MODE AFFICHAGE -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-5">
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-base-content/50 mb-1">Prénom</div>
                    <div class="text-base-content font-medium">{{ $prenom ?: '-' }}</div>
                </div>

                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-base-content/50 mb-1">Nom</div>
                    <div class="text-base-content font-medium">{{ $nom ?: '-' }}</div>
                </div>

                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-base-content/50 mb-1">Email</div>
                    <div class="text-base-content">
                        @if (!empty($email))
                            <a href="mailto:{{ $email }}" class="link link-primary font-medium">{{ $email }}</a>
                        @else
                            <span class="text-base-content/50">-</span>
                        @endif
                    </div>
                </div>

                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-base-content/50 mb-1">Téléphone</div>
                    <div class="text-base-content">{{ $phone ?: '-' }}</div>
                </div>

                @php
                    $categorieLabel = match ($categorie) {
                        'Eleves' => 'Élève',
                        'Profs' => 'Professeur',
                        'Administratifs' => 'Administratif',
                        default => $categorie,
                    };
                @endphp
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-base-content/50 mb-1">Catégorie</div>
                    <div class="text-base-content font-medium">{{ $categorieLabel }}</div>
                </div>

                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-base-content/50 mb-1">Fonction</div>
                    <div class="text-base-content font-medium">{{ $fonction ?: '-' }}</div>
                </div>

                @if ($description !== '')
                    <div class="md:col-span-2">
                        <div class="text-xs font-medium uppercase tracking-wide text-base-content/50 mb-1">Description
                        </div>
                        <div class="text-base-content">{{ $description }}</div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
