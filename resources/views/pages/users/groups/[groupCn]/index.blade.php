<?php

use App\Components\Traits\WithToasts;
use App\Config\SambaEduConfig;
use App\Repositories\GroupRepository;
use App\Services\UserService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Détail du groupe - Instance SE4FS')] class extends Component
{
    use WithToasts;

    private GroupRepository $groupRepository;

    private UserService $userService;

    private SambaEduConfig $config;

    // Données du groupe
    public ?array $group = null;

    public array $members = [];

    public bool $dataLoaded = false;

    public string $groupCn = '';

    // Édition
    public bool $isEditing = false;

    public string $editGroupName = '';

    public string $editDescription = '';

    // Modal ajout de membres
    public bool $showAddMemberModal = false;

    public string $memberSearchTerm = '';

    public array $searchResults = [];

    public array $selectedMembers = [];

    // Modal confirmation suppression
    public bool $showDeleteModal = false;

    public bool $showRemoveMemberModal = false;

    public string $memberToRemove = '';

    public string $memberToRemoveName = '';

    public function boot(GroupRepository $groupRepository, UserService $userService, SambaEduConfig $config)
    {
        $this->groupRepository = $groupRepository;
        $this->userService = $userService;
        $this->config = $config;
    }

    public function mount(string $groupCn)
    {
        $this->groupCn = urldecode($groupCn);
        $this->loadGroupData();
    }

    public function loadGroupData()
    {
        try {
            $this->group = $this->groupRepository->getGroupByCn($this->groupCn);

            if (! $this->group) {
                $this->toast('error', 'Erreur', 'Groupe non trouvé');

                return;
            }

            $this->members = $this->groupRepository->getGroupMembers($this->groupCn)->toArray();
            $this->editDescription = $this->group['description'] ?? '';
            $this->dataLoaded = true;

            Log::debug('[GroupShow] Groupe chargé', [
                'cn' => $this->groupCn,
                'memberCount' => count($this->members),
            ]);
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur chargement groupe: '.$e->getMessage());
            $this->toast('error', 'Erreur', 'Impossible de charger le groupe');
        }
    }

    /**
     * Extrait la catégorie d'un groupe à partir de son CN
     */
    public function getGroupCategory(): string
    {
        $cn = $this->groupCn;
        if (str_starts_with($cn, 'Classe_')) {
            return 'Classe';
        }
        if (str_starts_with($cn, 'Equipe_')) {
            return 'Équipe';
        }
        if (str_starts_with($cn, 'Cours_')) {
            return 'Cours';
        }
        if (str_starts_with($cn, 'Matiere_')) {
            return 'Matière';
        }
        if (str_starts_with($cn, 'Projet_')) {
            return 'Projet';
        }
        if (str_starts_with($cn, 'PP_')) {
            return 'PP';
        }

        return 'Autre';
    }

    public function getCategoryBadgeClass(): string
    {
        return match ($this->getGroupCategory()) {
            'Classe' => 'badge-primary',
            'Équipe' => 'badge-secondary',
            'Cours' => 'badge-accent',
            'Matière' => 'badge-info',
            'Projet' => 'badge-warning',
            'PP' => 'badge-error',
            default => 'badge-ghost',
        };
    }

    // Édition de la description
    public function startEditing()
    {
        $this->isEditing = true;
        $this->editGroupName = $this->group['cn'] ?? '';
        $this->editDescription = $this->group['description'] ?? '';
    }

    public function cancelEditing()
    {
        $this->isEditing = false;
        $this->editDescription = $this->group['description'] ?? '';
    }

    public function saveDescription()
    {
        try {
            if ($this->groupRepository->updateGroupDescription($this->groupCn, $this->editDescription)) {
                $this->group['description'] = $this->editDescription;
                $this->isEditing = false;
                $this->toast('success', 'Succès', 'Description mise à jour');
            } else {
                $this->toast('error', 'Erreur', 'Impossible de mettre à jour la description');
            }
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur mise à jour description: '.$e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la mise à jour');
        }
    }

    // Gestion des membres
    public function openAddMemberModal()
    {
        $this->showAddMemberModal = true;
        $this->memberSearchTerm = '';
        $this->searchResults = [];
        $this->selectedMembers = [];
    }

    public function closeAddMemberModal()
    {
        $this->showAddMemberModal = false;
        $this->memberSearchTerm = '';
        $this->searchResults = [];
        $this->selectedMembers = [];
    }

    public function searchMembers()
    {
        if (strlen($this->memberSearchTerm) < 2) {
            $this->searchResults = [];

            return;
        }

        try {
            $results = $this->userService->searchUsers($this->memberSearchTerm, 'all', 'all', 20, 1, [], $this->memberSearchTerm, '');

            // Filtrer les utilisateurs déjà membres
            $currentMemberCns = array_column($this->members, 'cn');

            $this->searchResults = collect($results->items ?? [])
                ->filter(fn ($user) => ! in_array($user->login, $currentMemberCns))
                ->map(
                    fn ($user) => [
                        'cn' => $user->login,
                        'dn' => $user->dn ?? '',
                        'displayName' => $user->fullname ?? $user->login,
                        'mail' => $user->email ?? '',
                    ],
                )
                ->take(10)
                ->toArray();
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur recherche membres: '.$e->getMessage());
            $this->searchResults = [];
        }
    }

    public function toggleMemberSelection(string $cn)
    {
        if (in_array($cn, $this->selectedMembers)) {
            $this->selectedMembers = array_values(array_filter($this->selectedMembers, fn ($m) => $m !== $cn));
        } else {
            $this->selectedMembers[] = $cn;
        }
    }

    public function addSelectedMembers()
    {
        if (empty($this->selectedMembers)) {
            $this->toast('warning', 'Attention', 'Aucun utilisateur sélectionné');

            return;
        }

        $added = 0;
        $errors = 0;

        foreach ($this->selectedMembers as $memberCn) {
            $member = collect($this->searchResults)->firstWhere('cn', $memberCn);
            if ($member && ! empty($member['dn'])) {
                if ($this->groupRepository->addMember($this->groupCn, $member['dn'])) {
                    $added++;
                } else {
                    $errors++;
                }
            }
        }

        if ($added > 0) {
            $this->toast('success', 'Succès', "{$added} membre(s) ajouté(s)");
            $this->loadGroupData();
        }
        if ($errors > 0) {
            $this->toast('error', 'Erreur', "{$errors} erreur(s) lors de l'ajout");
        }

        $this->closeAddMemberModal();
    }

    public function confirmRemoveMember(string $memberDn, string $memberName)
    {
        $this->memberToRemove = $memberDn;
        $this->memberToRemoveName = $memberName;
        $this->showRemoveMemberModal = true;
    }

    public function cancelRemoveMember()
    {
        $this->showRemoveMemberModal = false;
        $this->memberToRemove = '';
        $this->memberToRemoveName = '';
    }

    public function removeMember()
    {
        try {
            if ($this->groupRepository->removeMember($this->groupCn, $this->memberToRemove)) {
                $this->toast('success', 'Succès', 'Membre retiré du groupe');
                $this->loadGroupData();
            } else {
                $this->toast('error', 'Erreur', 'Impossible de retirer le membre');
            }
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur suppression membre: '.$e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la suppression');
        }

        $this->cancelRemoveMember();
    }

    public function removeMemberByDn(string $memberDn)
    {
        try {
            if ($this->groupRepository->removeMember($this->groupCn, $memberDn)) {
                $this->toast('success', 'Succès', 'Membre retiré du groupe');
                $this->loadGroupData();
            } else {
                $this->toast('error', 'Erreur', 'Impossible de retirer le membre');
            }
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur suppression membre: '.$e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la suppression');
        }
    }

    // Suppression du groupe
    public function confirmDeleteGroup()
    {
        $this->showDeleteModal = true;
    }

    public function cancelDeleteGroup()
    {
        $this->showDeleteModal = false;
    }

    public function deleteGroup()
    {
        try {
            if ($this->groupRepository->deleteGroup($this->groupCn)) {
                $this->toast('success', 'Succès', 'Groupe supprimé');
                $this->redirect(route('app.users', ['tab' => 'groups']));
            } else {
                $this->toast('error', 'Erreur', 'Impossible de supprimer le groupe');
            }
        } catch (\Exception $e) {
            Log::error('[GroupShow] Erreur suppression groupe: '.$e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la suppression');
        }

        $this->showDeleteModal = false;
    }

    public function refresh()
    {
        $this->dataLoaded = false;
        $this->loadGroupData();
        $this->toast('success', 'Actualisé', 'Données rechargées');
    }
};
?>

<x-organisms.page title="Détails du groupe" :scrollable="true" :backUrl="route('app.users', ['tab' => 'groups'])" backText="'Retour aux groupes'">

    <x-slot:actions>

        <div class="dropdown dropdown-end">
            <button tabindex="0" class="btn btn-outline">
                <i class="fa-solid fa-ellipsis-vertical"></i>
                Actions
            </button>
            <ul tabindex="0" class="dropdown-content z-50 menu p-2 shadow bg-base-100 rounded-box w-52">
                <li>
                    <button wire:click="startEditing" class="flex items-center gap-2">
                        <i class="fa-solid fa-pen"></i>
                        Modifier le groupe
                    </button>
                </li>
                <li>
                    <button wire:click="openAddMemberModal" class="btn btn-primary">
                        <i class="fa-solid fa-user-plus"></i>
                        Ajouter des membres
                    </button>
                </li>
                <li>
                    <button wire:click="confirmDeleteGroup" class="flex items-center gap-2 text-error">
                        <i class="fa-solid fa-trash"></i>
                        Supprimer le groupe
                    </button>
                </li>
            </ul>
        </div>
    </x-slot:actions>

    @if (!$dataLoaded)
        <div class="flex items-center justify-center py-16">
            <span class="loading loading-spinner loading-lg text-primary"></span>
            <span class="ml-4 text-lg">Chargement du groupe...</span>
        </div>
    @elseif (!$group)
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span>Groupe non trouvé</span>
        </div>
    @else
        <!-- En-tête du groupe -->
        <div class="card bg-base-100 shadow-sm mb-6">
            <div class="card-body">
                @if ($isEditing)
                    <!-- Formulaire d'édition -->
                    <div class="flex items-center gap-4 mb-4">
                        <div class="avatar placeholder">
                            <div class="bg-primary text-primary-content rounded-full w-16">
                                <i class="fa-solid fa-users-rectangle text-2xl"></i>
                            </div>
                        </div>
                        <div class="flex-1">
                            <h1 class="text-2xl font-bold mb-2">Modifier le groupe</h1>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text font-medium">Nom du groupe</span>
                                    </label>
                                    <input type="text" wire:model="editGroupName" placeholder="Nouveau nom du groupe"
                                        class="input input-bordered">
                                </div>
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text font-medium">Description</span>
                                    </label>
                                    <input type="text" wire:model="editDescription"
                                        placeholder="Description du groupe" class="input input-bordered">
                                </div>
                            </div>
                            <div class="flex gap-2 mt-4">
                                <button wire:click="saveGroupChanges" class="btn btn-primary">
                                    <i class="fa-solid fa-check"></i>
                                    Sauvegarder
                                </button>
                                <button wire:click="cancelEditing" class="btn btn-ghost">
                                    <i class="fa-solid fa-xmark"></i>
                                    Annuler
                                </button>
                            </div>
                        </div>
                    </div>
                @else
                    <!-- Affichage normal -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div
                                class="bg-primary text-primary-content flex items-center justify-center rounded-full h-16 w-16">
                                <i class="fa-solid fa-users text-2xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold">{{ $group['cn'] }}</h1>
                                <div class="flex items-center gap-2 mt-1">
                                    <span
                                        class="badge {{ $this->getCategoryBadgeClass() }}">{{ $this->getGroupCategory() }}</span>
                                    <span class="text-base-content/60">{{ count($members) }}
                                        membre{{ count($members) > 1 ? 's' : '' }}</span>
                                </div>
                                <p class="text-base-content/70 mt-2">{{ $group['description'] ?: 'Aucune description' }}
                                </p>
                            </div>
                        </div>

                    </div>
                @endif
            </div>
        </div>

        <!-- Quotas du groupe -->
        @include('components.quotas.group-quota-management')

        <!-- Tableau des membres -->
        <div class="card bg-base-100 shadow-sm mt-6">
            <div class="card-body">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="card-title">
                        <i class="fa-solid fa-users mr-2"></i>
                        Membres du groupe
                        <span class="badge badge-primary">{{ count($members) }}</span>
                    </h2>
                    <button wire:click="openAddMemberModal" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-user-plus mr-2"></i>
                        Ajouter des membres
                    </button>
                </div>

                @if (empty($members))
                    <div class="text-center py-12">
                        <div class="text-6xl mb-4 opacity-20">
                            <i class="fa-solid fa-user-slash"></i>
                        </div>
                        <p class="text-lg text-base-content/60 mb-4">Aucun membre dans ce groupe</p>
                        <button wire:click="openAddMemberModal" class="btn btn-primary">
                            <i class="fa-solid fa-user-plus mr-2"></i>
                            Ajouter des membres
                        </button>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-zebra w-full">
                            <thead>
                                <tr>
                                    <th>Utilisateur</th>
                                    <th>Email</th>
                                    <th>Rôle</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($members as $member)
                                    <tr wire:key="member-{{ $member['cn'] }}">
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <div class="avatar placeholder">
                                                    <div class="bg-neutral text-neutral-content rounded-full w-10">
                                                        <span>{{ strtoupper(substr($member['displayName'] ?? $member['cn'], 0, 1)) }}</span>
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="font-bold">{{ $member['displayName'] ?? $member['cn'] }}
                                                    </div>
                                                    <div class="text-sm opacity-50">{{ $member['cn'] }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>{{ $member['mail'] ?? '-' }}</td>
                                        <td>
                                            <span class="badge badge-ghost">Membre</span>
                                        </td>
                                        <td class="text-right">
                                            <div class="flex justify-end gap-2">
                                                <a href="{{ route('app.user.show', ['login' => $member['cn']]) }}"
                                                    class="btn btn-ghost btn-sm" title="Voir l'utilisateur">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <x-atoms.confirm-button method="removeMemberByDn" :params="[$member['dn']]"
                                                    title="Retirer le membre" :message="'Voulez-vous retirer ' .
                                                        ($member['displayName'] ?? $member['cn']) .
                                                        ' du groupe ' .
                                                        $groupCn .
                                                        ' ?'" confirm-text="Retirer"
                                                    variant="warning" class="btn-ghost btn-sm text-error">
                                                    <i class="fa-solid fa-user-minus"></i>
                                                </x-atoms.confirm-button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <!-- Modal Ajout de membres -->
        @if ($showAddMemberModal)
            <div class="modal modal-open">
                <div class="modal-box max-w-2xl">
                    <button type="button" wire:click="closeAddMemberModal"
                        class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2">
                        <i class="fa-solid fa-xmark"></i>
                    </button>

                    <h3 class="font-bold text-lg mb-4">
                        <i class="fa-solid fa-user-plus mr-2 text-primary"></i>
                        Ajouter des membres au groupe
                    </h3>

                    <!-- Recherche -->
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text font-medium">Rechercher des utilisateurs</span>
                        </label>
                        <div class="flex gap-2">
                            <input type="text" wire:model="memberSearchTerm"
                                wire:keyup.debounce.300ms="searchMembers" placeholder="Nom, prénom ou login..."
                                class="input input-bordered flex-1">
                            <button wire:click="searchMembers" class="btn btn-primary">
                                <i class="fa-solid fa-search"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Résultats de recherche -->
                    @if (!empty($searchResults))
                        <div class="max-h-64 overflow-y-auto border rounded-lg">
                            <table class="table table-sm">
                                <tbody>
                                    @foreach ($searchResults as $user)
                                        <tr wire:key="search-{{ $user['cn'] }}"
                                            class="hover cursor-pointer {{ in_array($user['cn'], $selectedMembers) ? 'bg-primary/10' : '' }}"
                                            wire:click="toggleMemberSelection('{{ $user['cn'] }}')">
                                            <td class="w-8">
                                                <input type="checkbox" class="checkbox checkbox-primary checkbox-sm"
                                                    {{ in_array($user['cn'], $selectedMembers) ? 'checked' : '' }}>
                                            </td>
                                            <td>
                                                <div class="font-bold">{{ $user['displayName'] }}</div>
                                                <div class="text-sm opacity-50">{{ $user['cn'] }}</div>
                                            </td>
                                            <td class="text-sm opacity-50">{{ $user['mail'] ?? '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if (!empty($selectedMembers))
                            <div class="mt-4 p-3 bg-base-200 rounded-lg">
                                <span class="font-semibold">{{ count($selectedMembers) }} utilisateur(s)
                                    sélectionné(s)</span>
                            </div>
                        @endif
                    @elseif (strlen($memberSearchTerm) >= 2)
                        <div class="text-center py-4 text-base-content/60">
                            Aucun utilisateur trouvé
                        </div>
                    @endif

                    <div class="modal-action">
                        <button type="button" wire:click="closeAddMemberModal" class="btn">Annuler</button>
                        <button type="button" wire:click="addSelectedMembers" class="btn btn-primary"
                            {{ empty($selectedMembers) ? 'disabled' : '' }}>
                            <i class="fa-solid fa-plus mr-2"></i>
                            Ajouter ({{ count($selectedMembers) }})
                        </button>
                    </div>
                </div>
                <div class="modal-backdrop" wire:click="closeAddMemberModal"></div>
            </div>
        @endif

        <!-- Modal Confirmation suppression membre -->
        @if ($showRemoveMemberModal)
            <div class="modal modal-open">
                <div class="modal-box">
                    <h3 class="font-bold text-lg">
                        <i class="fa-solid fa-user-minus mr-2 text-warning"></i>
                        Retirer un membre
                    </h3>
                    <p class="py-4">
                        Êtes-vous sûr de vouloir retirer <strong>{{ $memberToRemoveName }}</strong> du groupe
                        <strong>{{ $groupCn }}</strong> ?
                    </p>
                    <div class="modal-action">
                        <button wire:click="cancelRemoveMember" class="btn">Annuler</button>
                        <button wire:click="removeMember" class="btn btn-warning">
                            <i class="fa-solid fa-user-minus mr-2"></i>
                            Retirer
                        </button>
                    </div>
                </div>
                <div class="modal-backdrop" wire:click="cancelRemoveMember"></div>
            </div>
        @endif

        <!-- Modal Confirmation suppression groupe -->
        @if ($showDeleteModal)
            <div class="modal modal-open">
                <div class="modal-box">
                    <h3 class="font-bold text-lg text-error">
                        <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                        Supprimer le groupe
                    </h3>
                    <p class="py-4">
                        Êtes-vous sûr de vouloir supprimer définitivement le groupe
                        <strong>{{ $groupCn }}</strong> ?
                    </p>
                    <div class="alert alert-warning mb-4">
                        <i class="fa-solid fa-exclamation-triangle"></i>
                        <span>Cette action est irréversible. Tous les membres seront retirés du groupe.</span>
                    </div>
                    <div class="modal-action">
                        <button wire:click="cancelDeleteGroup" class="btn">Annuler</button>
                        <button wire:click="deleteGroup" class="btn btn-error">
                            <i class="fa-solid fa-trash mr-2"></i>
                            Supprimer
                        </button>
                    </div>
                </div>
                <div class="modal-backdrop" wire:click="cancelDeleteGroup"></div>
            </div>
        @endif
    @endif
</x-organisms.page>
