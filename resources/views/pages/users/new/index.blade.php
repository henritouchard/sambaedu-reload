<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Services\UserService;
use App\Config\SambaEduConfig;
use App\Repositories\ClassRepository;
use App\Repositories\FunctionRepository;
use Livewire\Attributes\Validate;
use App\Components\Traits\WithToasts;

new #[Title('Nouvel utilisateur - Instance SE4FS')] class extends Component {
    use WithToasts;
    // Données du formulaire
    #[Validate('required|min:2|max:50')]
    public string $nom = '';

    #[Validate('required|min:2|max:50')]
    public string $prenom = '';

    #[Validate('nullable|max:50')]
    public string $login = '';

    #[Validate('required|in:Eleves,Profs,Administratifs')]
    public string $categorie = 'Eleves';

    #[Validate('nullable')]
    public string $fonction = '';

    #[Validate('nullable|array')]
    public array $classes = [];

    #[Validate('nullable|regex:/^[0-9]{8}$/')]
    public string $naissance = '';

    #[Validate('nullable|min:8|max:13')]
    public string $password = '';

    // Données de référence
    public string $etabName = '';
    public string $etabCode = '';
    public array $fonctions = [];
    public array $availableClasses = [];
    public array $passwordPolicy = [];

    // État du composant
    public bool $isSubmitting = false;
    public ?string $createdLogin = null;
    public ?string $createdPassword = null;

    // Recherche de classes
    public string $classSearch = '';

    private UserService $userService;
    private SambaEduConfig $config;
    private ClassRepository $classRepository;
    private FunctionRepository $functionRepository;

    public function boot(UserService $userService, SambaEduConfig $config, ClassRepository $classRepository, FunctionRepository $functionRepository)
    {
        $this->userService = $userService;
        $this->config = $config;
        $this->classRepository = $classRepository;
        $this->functionRepository = $functionRepository;
    }

    public function mount()
    {
        // Charger les données de référence depuis EstablishmentConfig
        $establishment = $this->config->establishment();
        $this->etabCode = $establishment->currentCode;
        $this->etabName = $establishment->name;

        // Charger les fonctions disponibles
        $this->loadFonctions();

        // Charger les classes disponibles
        $this->loadClasses();

        // Politique de mot de passe
        $this->passwordPolicy = [
            'min_length' => 8,
            'description' => 'Date de naissance (JJMMAAAA) ou mot de passe aléatoire si non renseigné',
        ];
    }

    public function loadFonctions()
    {
        try {
            // Mapper la catégorie du formulaire vers celle du repository
            $repoCat = match ($this->categorie) {
                'Administratifs' => 'Administratifs',
                'Profs' => 'Pedagogiques',
                default => 'all',
            };
            $fonctionsData = $this->functionRepository->getAll($repoCat);
            $this->fonctions = array_map(fn($f) => $f['cn'] ?? $f, $fonctionsData);

            // Fonctions par défaut si la liste est vide
            if (empty($this->fonctions)) {
                $this->fonctions = match ($this->categorie) {
                    'Administratifs' => ['Direction', 'Secrétariat', 'Gestionnaire', 'Medical', 'VieScol', 'Agent', 'AED', 'Tech'],
                    'Profs' => ['Documentaliste', 'AESH'],
                    default => [],
                };
            }
        } catch (\Exception $e) {
            $this->fonctions = match ($this->categorie) {
                'Administratifs' => ['Direction', 'Secrétariat', 'Gestionnaire', 'Medical', 'VieScol', 'Agent', 'AED', 'Tech'],
                'Profs' => ['Documentaliste', 'AESH'],
                default => [],
            };
        }
    }

    public function loadClasses()
    {
        try {
            $classesData = $this->classRepository->getAll($this->etabCode ?: '0');
            $this->availableClasses = array_map(fn($c) => $c['cn'] ?? $c, $classesData);
        } catch (\Exception $e) {
            $this->availableClasses = [];
        }
    }

    public function updatedCategorie()
    {
        // Réinitialiser les champs conditionnels
        if ($this->categorie === 'Eleves') {
            $this->classes = array_slice($this->classes, 0, 1);
        }
        if ($this->categorie !== 'Administratifs' && $this->categorie !== 'Profs') {
            $this->fonction = '';
        }
        $this->classSearch = '';

        // Recharger les fonctions filtrées par catégorie
        $this->fonction = '';
        $this->loadFonctions();
    }

    /**
     * Retourne les classes filtrées par la recherche
     */
    public function getFilteredClassesProperty(): array
    {
        if (empty($this->classSearch)) {
            return $this->availableClasses;
        }

        return array_values(
            array_filter($this->availableClasses, function ($classe) {
                return stripos($classe, $this->classSearch) !== false;
            }),
        );
    }

    /**
     * Sélectionne une classe pour un élève
     */
    public function selectClass(string $classe)
    {
        $this->classes = [$classe];
    }

    /**
     * Toggle une classe pour un prof
     */
    public function toggleClass(string $classe)
    {
        if (in_array($classe, $this->classes)) {
            $this->classes = array_values(array_diff($this->classes, [$classe]));
        } else {
            $this->classes[] = $classe;
        }
    }

    /**
     * Retire une classe de la sélection
     */
    public function removeClass(string $classe)
    {
        $this->classes = array_values(array_diff($this->classes, [$classe]));
    }

    public function createUser()
    {
        $this->validate();

        // Validation conditionnelle
        if ($this->categorie === 'Administratifs' && empty($this->fonction)) {
            $this->addError('fonction', 'La fonction est obligatoire pour les administratifs.');
            return;
        }

        if ($this->categorie === 'Eleves' && empty($this->classes)) {
            $this->addError('classes', 'La classe est obligatoire pour les élèves.');
            return;
        }

        $this->isSubmitting = true;

        try {
            $userData = [
                'nom' => $this->nom,
                'prenom' => $this->prenom,
                'login' => $this->login ?: '',
                'categorie' => $this->categorie,
                'fonction' => $this->fonction ?: '',
                'classes' => $this->classes,
                'naissance' => $this->naissance ?: '',
                'password' => $this->password ?: '',
                'new_etab' => 0, // Établissement courant
            ];

            $result = $this->userService->createUser($userData);

            if ($result['success'] ?? false) {
                $login = $result['user']['cn'] ?? null;
                $password = $result['user']['password'] ?? null;

                if ($login) {
                    session()->flash('created_password', $password);
                    $this->redirect(route('app.user.show', $login), navigate: true);
                    return;
                }

                $this->toastSuccess($result['message'] ?? 'Utilisateur créé avec succès.');
            } else {
                $this->toastError($result['message'] ?? 'Erreur lors de la création.');
            }
        } catch (\Exception $e) {
            $this->addError('form', 'Erreur lors de la création : ' . $e->getMessage());
        } finally {
            $this->isSubmitting = false;
        }
    }

    public function resetForm()
    {
        $this->reset(['nom', 'prenom', 'login', 'categorie', 'fonction', 'classes', 'naissance', 'password', 'createdLogin', 'createdPassword']);
        $this->categorie = 'Eleves';
    }
};

?>

<x-organisms.page :backUrl="route('app.users')" title="Nouvel utilisateur" backText="Retour à la liste">
    <x-slot:actions>
        <button type="button" wire:click="resetForm" class="btn btn-ghost gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                </path>
            </svg>
            Réinitialiser
        </button>
    </x-slot:actions>

    <!-- Message de succès avec informations de création -->
    @if ($createdLogin)
        <div class="alert alert-success mb-6 shadow-lg">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div class="flex-1">
                <h3 class="font-bold">Utilisateur créé avec succès !</h3>
                <div class="text-sm mt-2 space-y-1">
                    <p><strong>Login :</strong> <span
                            class="font-mono bg-base-200 px-2 py-0.5 rounded">{{ $createdLogin }}</span></p>
                    @if ($createdPassword)
                        <p><strong>Mot de passe :</strong> <span
                                class="font-mono bg-base-200 px-2 py-0.5 rounded">{{ $createdPassword }}</span></p>
                    @endif
                </div>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('app.user.show', $createdLogin) }}" class="btn btn-sm btn-primary">
                    Voir la fiche
                </a>
                <button wire:click="resetForm" class="btn btn-sm btn-ghost">
                    Créer un autre
                </button>
            </div>
        </div>
    @endif

    <!-- Erreurs globales -->
    @if ($errors->has('form'))
        <div class="alert alert-error mb-6">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span>{{ $errors->first('form') }}</span>
        </div>
    @endif

    <form wire:submit="createUser" class="space-y-6">
        <!-- Informations générales -->
        @include('pages.users.new._partials.general-info')

        <!-- Classes (conditionnelle) -->
        @include('pages.users.new._partials.classes-selection')

        <!-- Authentification -->
        @include('pages.users.new._partials.authentication')

        <!-- Actions -->
        <div class="flex justify-end gap-3 pt-4">
            <a href="{{ route('app.users') }}" class="btn btn-ghost gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                    </path>
                </svg>
                Annuler
            </a>
            <button type="submit" class="btn btn-primary gap-2" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="createUser">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4">
                        </path>
                    </svg>
                </span>
                <span wire:loading wire:target="createUser" class="loading loading-spinner loading-sm"></span>
                Créer l'utilisateur
            </button>
        </div>
    </form>
</x-organisms.page>
