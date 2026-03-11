<?php

use Livewire\Component;
use App\Repositories\GroupRepository;
use App\Config\SambaEduConfig;
use Illuminate\Support\Facades\Log;
use App\Components\Traits\WithToasts;

new class extends Component {
    use WithToasts;

    private GroupRepository $groupRepository;
    private SambaEduConfig $config;

    protected $listeners = ['open-create-group-modal' => 'openCreateModal'];

    // Données des groupes
    public array $groups = [];
    public bool $groupsLoaded = false;

    // Filtres
    public string $searchTerm = '';
    public string $categoryFilter = '';

    // Modal création de groupe
    public bool $showCreateModal = false;
    public string $newGroupPrefix = '';
    public string $newGroupCategory = 'Classe';
    public string $newGroupName = '';
    public string $newGroupDescription = '';
    public array $createErrors = [];

    // Catégories disponibles (comme dans le legacy)
    public array $availableCategories = [
        'Classe' => 'Classe',
        'Cours' => 'Cours',
        'Matiere' => 'Matière',
        'Projet' => 'Projet',
        'Autre' => 'Autre',
    ];

    public function boot(GroupRepository $groupRepository, SambaEduConfig $config)
    {
        $this->groupRepository = $groupRepository;
        $this->config = $config;
    }

    public function mount()
    {
        $this->loadGroups();
    }

    public function loadGroups()
    {
        if ($this->groupsLoaded) {
            return;
        }

        try {
            $establishmentCode = session('etab', null);
            if (empty($establishmentCode) || $establishmentCode === '0') {
                $establishmentCode = $this->config->getCurrentEstablishmentCode();
            }
            if ($establishmentCode === '0') {
                $establishmentCode = null;
            }

            $rawGroups = $this->groupRepository->getGroupsWithMemberCount($establishmentCode);
            $this->groups = $rawGroups->toArray();
            $this->groupsLoaded = true;

            // Dispatcher le compteur au parent
            $this->dispatch('groups-count-updated', count: count($this->groups));

            Log::debug('[GroupsTab] Groupes chargés', [
                'count' => count($this->groups),
                'establishment' => $establishmentCode,
            ]);
        } catch (\Exception $e) {
            Log::error('[GroupsTab] Erreur chargement groupes: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Impossible de charger les groupes');
            $this->groups = [];
            $this->groupsLoaded = true;
        }
    }

    public function getFilteredGroupsProperty(): array
    {
        $groups = collect($this->groups);

        // Filtre par recherche
        if ($this->searchTerm) {
            $search = strtolower($this->searchTerm);
            $groups = $groups->filter(function ($group) use ($search) {
                return str_contains(strtolower($group['cn'] ?? ''), $search) || str_contains(strtolower($group['description'] ?? ''), $search);
            });
        }

        // Filtre par catégorie (utilise la catégorie fournie par le repository)
        if ($this->categoryFilter) {
            $categoryFilter = $this->categoryFilter;
            $groups = $groups->filter(function ($group) use ($categoryFilter) {
                $category = $group['category'] ?? 'Autre';
                return match ($categoryFilter) {
                    'Classe' => $category === 'Classe',
                    'Equipe' => $category === 'Équipe',
                    'Cours' => $category === 'Cours',
                    'Matiere' => $category === 'Matière',
                    'Projet' => $category === 'Projet',
                    'PP' => $category === 'PP',
                    'Droits' => $category === 'Droits',
                    'Autre' => $category === 'Autre',
                    default => true,
                };
            });
        }

        return $groups->values()->toArray();
    }

    public function openCreateModal()
    {
        $this->resetCreateForm();
        $this->showCreateModal = true;
    }

    public function closeCreateModal()
    {
        $this->showCreateModal = false;
        $this->resetCreateForm();
    }

    private function resetCreateForm()
    {
        $this->newGroupPrefix = '';
        $this->newGroupCategory = 'Classe';
        $this->newGroupName = '';
        $this->newGroupDescription = '';
        $this->createErrors = [];
    }

    public function createGroup()
    {
        $this->createErrors = [];

        // Validation (comme dans le legacy)
        if (empty($this->newGroupName)) {
            $this->createErrors['name'] = 'L\'intitulé du groupe est obligatoire.';
        } elseif (!$this->verifIntituleGrp($this->newGroupName)) {
            $this->createErrors['name'] = 'L\'intitulé ne doit pas commencer ou se terminer par Classe, Equipe ou Matiere.';
        }

        if (empty($this->newGroupDescription)) {
            $this->createErrors['description'] = 'La description est obligatoire.';
        } elseif (!$this->verifDescription($this->newGroupDescription)) {
            $this->createErrors['description'] = 'La description contient des caractères interdits.';
        }

        if (!empty($this->createErrors)) {
            return;
        }

        try {
            // Construire le nom du groupe (comme dans le legacy)
            $intitule = $this->enleveAccents($this->newGroupName);
            $prefix = !empty($this->newGroupPrefix) ? $this->newGroupPrefix . '_' : '';

            if ($this->newGroupCategory === 'Autre') {
                $categorie = '';
                $typeGroupe = 'other_group';
            } else {
                $categorie = $this->newGroupCategory . '_';
                $typeGroupe = strtolower($this->newGroupCategory);
            }

            $cn = $categorie . $prefix . $intitule;

            // Vérifier si le groupe existe déjà
            if ($this->groupRepository->groupExists($cn)) {
                $this->createErrors['name'] = "Le groupe '{$cn}' existe déjà.";
                return;
            }

            // Créer le groupe via le repository
            $result = $this->groupRepository->createGroup($intitule, $this->newGroupDescription, $typeGroupe, $this->newGroupPrefix);

            if ($result) {
                $this->toast('success', 'Succès', "Le groupe '{$cn}' a été créé avec succès.");
                $this->closeCreateModal();
                $this->refresh();
            } else {
                $this->createErrors['general'] = 'Échec de la création du groupe.';
            }
        } catch (\Exception $e) {
            Log::error('[GroupsTab] Erreur création groupe: ' . $e->getMessage());
            $this->createErrors['general'] = 'Erreur lors de la création du groupe: ' . $e->getMessage();
        }
    }

    /**
     * Validation de l'intitulé du groupe (comme dans le legacy)
     */
    private function verifIntituleGrp(string $intitule): bool
    {
        $forbidden = ['Classe', 'Equipe', 'Matiere', 'classe', 'equipe', 'matiere'];
        foreach ($forbidden as $word) {
            if (str_starts_with($intitule, $word) || str_ends_with($intitule, $word)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validation de la description (comme dans le legacy)
     */
    private function verifDescription(string $description): bool
    {
        // Caractères interdits dans la description
        return !preg_match('/[<>"\']/', $description);
    }

    /**
     * Supprime les accents (comme dans le legacy enleveaccents2)
     */
    private function enleveAccents(string $str): string
    {
        $str = str_replace(' ', '_', $str);
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        $str = preg_replace('/[^a-zA-Z0-9_-]/', '', $str);
        return $str;
    }

    public function refresh()
    {
        $this->groupsLoaded = false;
        $this->loadGroups();
        $this->toast('success', 'Actualisé', 'Liste des groupes rechargée');
    }

    public function clearFilters()
    {
        $this->searchTerm = '';
        $this->categoryFilter = '';
    }

    /**
     * Retourne la catégorie d'un groupe (utilise celle fournie par le repository)
     */
    public function getGroupCategory(array $group): string
    {
        return $group['category'] ?? 'Autre';
    }

    /**
     * Retourne la couleur du badge selon la catégorie
     */
    public function getCategoryBadgeClass(string $category): string
    {
        return match ($category) {
            'Classe' => 'badge-primary',
            'Équipe' => 'badge-secondary',
            'Cours' => 'badge-accent',
            'Matière' => 'badge-info',
            'Projet' => 'badge-warning',
            'PP' => 'badge-error',
            'Droits' => 'badge-neutral',
            'Délégation' => 'badge-neutral',
            default => 'badge-ghost',
        };
    }
};
?>

<div class="flex-1 min-h-0 flex flex-col gap-4">
    <!-- Filtres -->
    <div class="flex-shrink-0 card bg-base-100 shadow-sm">
        <div class="card-body py-4">
            <div class="flex flex-wrap gap-4 items-end">
                <div class="form-control flex-1 min-w-[200px]">
                    <label class="label py-1">
                        <span class="label-text font-semibold text-sm">Rechercher un groupe</span>
                    </label>
                    <input type="text" wire:model.live.debounce.300ms="searchTerm" placeholder="Nom ou description..."
                        class="input input-bordered input-sm w-full">
                </div>
                <div class="form-control min-w-[150px]">
                    <label class="label py-1">
                        <span class="label-text font-semibold text-sm">Catégorie</span>
                    </label>
                    <select wire:model.live="categoryFilter" class="select select-bordered select-sm w-full">
                        <option value="">Toutes</option>
                        <option value="Classe">Classes</option>
                        <option value="Equipe">Équipes</option>
                        <option value="Cours">Cours</option>
                        <option value="Matiere">Matières</option>
                        <option value="Projet">Projets</option>
                        <option value="PP">Profs principaux</option>
                        <option value="Droits">Droits</option>
                        <option value="Autre">Autres</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button wire:click="clearFilters" class="btn btn-ghost btn-sm" title="Effacer les filtres">
                        <i class="fa-solid fa-filter-circle-xmark"></i>
                    </button>
                    <button wire:click="refresh" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-rotate"></i>
                        Actualiser
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Liste des groupes -->
    @if (!$groupsLoaded)
        <div class="flex items-center justify-center py-16">
            <span class="loading loading-spinner loading-lg text-primary"></span>
            <span class="ml-4 text-lg">Chargement des groupes...</span>
        </div>
    @elseif (empty($this->filteredGroups))
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body text-center py-16">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-users-rectangle"></i>
                </div>
                @if ($searchTerm || $categoryFilter)
                    <h3 class="text-xl font-semibold mb-3">Aucun groupe trouvé</h3>
                    <p class="text-base-content/60">Essayez de modifier vos critères de recherche.</p>
                @else
                    <h3 class="text-xl font-semibold mb-3">Aucun groupe</h3>
                    <p class="text-base-content/60 mb-4">Commencez par créer un groupe d'utilisateurs.</p>
                    <button wire:click="openCreateModal" class="btn btn-primary">
                        <i class="fa-solid fa-plus mr-2"></i>
                        Créer un groupe
                    </button>
                @endif
            </div>
        </div>
    @else
        <div class="card bg-base-100 shadow-sm flex-1 min-h-0 flex flex-col overflow-hidden">
            <div class="card-body p-0 flex-1 min-h-0 flex flex-col">
                <div class="overflow-auto flex-1 min-h-0">
                    <table class="table table-zebra table-pin-rows">
                        <thead>
                            <tr>
                                <th>Nom du groupe</th>
                                <th>Catégorie</th>
                                <th>Description</th>
                                <th class="text-center">Membres</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->filteredGroups as $group)
                                @php
                                    $category = $this->getGroupCategory($group);
                                    $badgeClass = $this->getCategoryBadgeClass($category);
                                @endphp
                                <tr wire:key="group-{{ $group['cn'] }}" class="hover">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <div>
                                                <div class="font-bold">{{ $group['cn'] }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge {{ $badgeClass }} badge-sm">{{ $category }}</span>
                                    </td>
                                    <td class="max-w-xs truncate" title="{{ $group['description'] ?? '' }}">
                                        {{ $group['description'] ?? '-' }}
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-outline">{{ $group['memberCount'] ?? 0 }}</span>
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('app.users.groups.show', ['groupCn' => urlencode($group['cn'])]) }}"
                                            class="btn btn-ghost btn-sm">
                                            <i class="fa-solid fa-eye"></i>
                                            Voir
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="flex-shrink-0 text-sm text-base-content/60">
            {{ count($this->filteredGroups) }} groupe(s) affiché(s)
            @if ($searchTerm || $categoryFilter)
                sur {{ count($groups) }} au total
            @endif
        </div>
    @endif

    <!-- Modal Création de groupe -->
    <dialog id="create-group-modal" class="modal" @if ($showCreateModal) open @endif>
        <div class="modal-box max-w-lg">
            <button type="button" wire:click="closeCreateModal"
                class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <h3 class="font-bold text-lg mb-4">
                <i class="fa-solid fa-users-gear mr-2 text-primary"></i>
                Créer un nouveau groupe
            </h3>

            @if (!empty($createErrors['general']))
                <div class="alert alert-error mb-4">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span>{{ $createErrors['general'] }}</span>
                </div>
            @endif

            <form wire:submit="createGroup">
                <div class="space-y-4">
                    <!-- Préfixe (optionnel) -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text font-medium">Préfixe (optionnel)</span>
                        </label>
                        <input type="text" wire:model="newGroupPrefix" placeholder="Ex: LP, LT..."
                            class="input input-bordered w-full" maxlength="10">
                        <label class="label">
                            <span class="label-text-alt text-base-content/60">Exemple : LP, LT pour distinguer les
                                filières</span>
                        </label>
                    </div>

                    <!-- Catégorie -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text font-medium">Catégorie</span>
                        </label>
                        <select wire:model="newGroupCategory" class="select select-bordered w-full">
                            @foreach ($availableCategories as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label class="label">
                            <span class="label-text-alt text-base-content/60">
                                @if ($newGroupCategory === 'Classe')
                                    Crée automatiquement : Classe_X, Equipe_X et PP_X
                                @elseif ($newGroupCategory === 'Cours')
                                    Crée automatiquement : Cours_X et Equipe_X
                                @else
                                    Crée un groupe de type {{ $newGroupCategory }}
                                @endif
                            </span>
                        </label>
                    </div>

                    <!-- Intitulé -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text font-medium">Intitulé <span class="text-error">*</span></span>
                        </label>
                        <input type="text" wire:model="newGroupName" placeholder="Ex: 3A, Terminale_S1..."
                            class="input input-bordered w-full @error('name') input-error @enderror">
                        @if (!empty($createErrors['name']))
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $createErrors['name'] }}</span>
                            </label>
                        @endif
                    </div>

                    <!-- Description -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text font-medium">Description <span class="text-error">*</span></span>
                        </label>
                        <input type="text" wire:model="newGroupDescription" placeholder="Ex: Classe de 3ème A..."
                            class="input input-bordered w-full @error('description') input-error @enderror">
                        @if (!empty($createErrors['description']))
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $createErrors['description'] }}</span>
                            </label>
                        @endif
                    </div>

                    <!-- Aperçu du nom final -->
                    @if ($newGroupName)
                        <div class="alert alert-info">
                            <i class="fa-solid fa-info-circle"></i>
                            <div>
                                <p class="font-semibold">Aperçu du groupe :</p>
                                <p class="font-mono">
                                    @php
                                        $preview = $newGroupCategory !== 'Autre' ? $newGroupCategory . '_' : '';
                                        $preview .= $newGroupPrefix ? $newGroupPrefix . '_' : '';
                                        $preview .= $newGroupName;
                                    @endphp
                                    {{ $preview }}
                                </p>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="modal-action">
                    <button type="button" wire:click="closeCreateModal" class="btn">Annuler</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-plus mr-2"></i>
                        Créer le groupe
                    </button>
                </div>
            </form>
        </div>
        <div class="modal-backdrop" wire:click="closeCreateModal"></div>
    </dialog>
</div>
