<?php

use App\Components\Traits\WithToasts;
use App\Models\AppProfile;
use App\Services\AppProfile\AppProfileService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;
    use WithToasts;

    private AppProfileService $appProfileService;

    #[Url]
    public string $profileSearch = '';

    #[Url]
    public ?bool $activeOnly = null;

    public array $selectedProfiles = [];

    #[Url]
    public int $profilesPerPage = 20;

    public array $allowedPerPage = [10, 20, 50, 100];

    // Modal création profil
    public bool $showCreateModal = false;

    public string $newProfileName = '';

    public string $newProfileDisplayName = '';

    public string $newProfileDescription = '';

    public function boot(AppProfileService $appProfileService): void
    {
        $this->appProfileService = $appProfileService;
    }

    #[Computed]
    public function profiles()
    {
        try {
            return $this->appProfileService->listProfiles(
                perPage: $this->profilesPerPage,
                search: $this->profileSearch ?: null,
                activeOnly: $this->activeOnly,
            );
        } catch (\Exception $e) {
            Log::error('[ProfilesTab] Erreur chargement profils: '.$e->getMessage());

            return collect();
        }
    }

    public function resetProfileFilters(): void
    {
        $this->profileSearch = '';
        $this->activeOnly = null;
        $this->selectedProfiles = [];
        $this->resetPage();
    }

    public function updatedProfilesPerPage(): void
    {
        $this->resetPage();
    }

    #[On('reset-pagination')]
    public function onResetPagination(): void
    {
        $this->resetPage();
    }

    #[On('open-create-profile-modal')]
    public function openCreateModal(): void
    {
        $this->newProfileName = '';
        $this->newProfileDisplayName = '';
        $this->newProfileDescription = '';
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
    }

    public function createProfile(): void
    {
        $this->validate([
            'newProfileName' => 'required|string|max:100|unique:app_profiles,name',
            'newProfileDisplayName' => 'nullable|string|max:255',
            'newProfileDescription' => 'nullable|string',
        ]);

        try {
            $profile = $this->appProfileService->createProfile([
                'name' => $this->newProfileName,
                'display_name' => $this->newProfileDisplayName ?: null,
                'description' => $this->newProfileDescription ?: null,
                'is_active' => true,
            ]);

            $this->toastSuccess("Profil '{$profile->name}' créé avec succès");
            $this->closeCreateModal();
        } catch (\Exception $e) {
            Log::error('[ProfilesTab] Erreur création profil: '.$e->getMessage());
            $this->toastError('Erreur lors de la création du profil');
        }
    }

    public function deleteProfile(int $profileId): void
    {
        try {
            $profile = AppProfile::find($profileId);
            if (! $profile) {
                $this->toastError('Profil non trouvé');

                return;
            }

            $name = $profile->name;
            $this->appProfileService->deleteProfile($profileId);
            $this->toastSuccess("Profil '{$name}' supprimé avec succès");
        } catch (\Exception $e) {
            Log::error('[ProfilesTab] Erreur suppression profil: '.$e->getMessage());
            $this->toastError($e->getMessage());
        }
    }

    public function toggleProfileActive(int $profileId): void
    {
        try {
            $profile = AppProfile::find($profileId);
            if (! $profile) {
                $this->toastError('Profil non trouvé');

                return;
            }

            $this->appProfileService->updateProfile($profileId, [
                'is_active' => ! $profile->is_active,
            ]);

            $status = ! $profile->is_active ? 'activé' : 'désactivé';
            $this->toastSuccess("Profil '{$profile->name}' {$status}");
        } catch (\Exception $e) {
            Log::error('[ProfilesTab] Erreur toggle profil: '.$e->getMessage());
            $this->toastError('Erreur lors de la modification du profil');
        }
    }
};
?>

<div class="flex flex-col gap-3 flex-1 min-h-0">
    {{-- Vérification synchronisation AD/SQL --}}
    <div class="flex-shrink-0">
        <livewire:components::molecules.app-profile-sync-status />
    </div>

    <!-- Filtres -->
    <div class="flex-shrink-0 border-b border-base-300 pb-3">
        <div class="flex flex-wrap gap-x-3 gap-y-2 items-end">
            <!-- Recherche -->
            <div class="form-control flex-1 min-w-[200px]">
                <label class="label py-0">
                    <span class="label-text text-xs">Rechercher</span>
                </label>
                <input type="text" wire:model.live.debounce.300ms="profileSearch"
                    class="input input-bordered input-sm" placeholder="Nom, description..." />
            </div>

            <!-- Filtre actif -->
            <div class="form-control min-w-[150px]">
                <label class="label py-0">
                    <span class="label-text text-xs">Statut</span>
                </label>
                <select wire:model.live="activeOnly" class="select select-bordered select-sm">
                    <option value="">Tous</option>
                    <option value="1">Actifs uniquement</option>
                    <option value="0">Inactifs uniquement</option>
                </select>
            </div>

            <!-- Bouton reset -->
            <button type="button" class="btn btn-ghost btn-sm" wire:click="resetProfileFilters"
                title="Réinitialiser les filtres">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
        </div>
    </div>

    <!-- Tableau des profils -->
    <div class="card bg-base-100 shadow-sm border border-base-300 flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="card-body p-0 flex flex-col flex-1 min-h-0">
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <input type="checkbox" class="checkbox checkbox-sm" />
                            </th>
                            <th>Nom</th>
                            <th>Description</th>
                            <th class="text-center">Applications</th>
                            <th class="text-center">Groupes</th>
                            <th class="text-center">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->profiles as $profile)
                            <tr wire:key="profile-{{ $profile->id }}" class="hover cursor-pointer"
                                onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.parc-settings.profiles.show', ['id' => $profile->id, 'from' => route('app.parc-settings.index', ['tab' => 'profiles'], false)]) }}'">
                                <td class="checkbox-cell p-0">
                                    <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                        <input type="checkbox" class="checkbox checkbox-sm"
                                            wire:model.live="selectedProfiles" value="{{ $profile->id }}" />
                                    </label>
                                </td>
                                <td>
                                    <div class="flex flex-col">
                                        <span class="font-medium">
                                            {{ $profile->display_name ?? $profile->name }}
                                        </span>
                                        @if ($profile->display_name)
                                            <span class="text-xs text-base-content/60">{{ $profile->name }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span class="text-sm text-base-content/70 line-clamp-2">
                                        {{ $profile->description ?? '-' }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-ghost">
                                        {{ $profile->applications_count ?? 0 }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-ghost">
                                        {{ $profile->workstation_groups_count ?? 0 }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    @if ($profile->is_active)
                                        <span class="badge badge-success badge-sm">Actif</span>
                                    @else
                                        <span class="badge badge-warning badge-sm">Inactif</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-8 text-base-content/60">
                                    <i class="fa-solid fa-cubes text-4xl mb-2 opacity-30"></i>
                                    <p>Aucun profil applicatif trouvé</p>
                                    @if ($profileSearch || $activeOnly !== null)
                                        <button type="button" class="btn btn-ghost btn-sm mt-2"
                                            wire:click="resetProfileFilters">
                                            Réinitialiser les filtres
                                        </button>
                                    @else
                                        <button type="button" class="btn btn-primary btn-sm mt-2"
                                            wire:click="openCreateModal">
                                            <i class="fa-solid fa-plus mr-1"></i>
                                            Créer un profil
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if ($this->profiles instanceof \Illuminate\Pagination\LengthAwarePaginator)
                <x-molecules.pagination :paginator="$this->profiles" :allowedPerPage="$allowedPerPage" perPageModel="profilesPerPage"
                    itemLabel="profil" itemLabelPlural="profils" />
            @endif
        </div>
    </div>

    <!-- Actions groupées -->
    @if (count($selectedProfiles) > 0)
        <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
            <div class="card bg-base-100 shadow-xl border border-base-300">
                <div class="card-body py-3 px-4 flex-row items-center gap-4">
                    <span class="text-sm font-medium">
                        {{ count($selectedProfiles) }} profil(s) sélectionné(s)
                    </span>
                    <div class="divider divider-horizontal m-0"></div>
                    <div class="dropdown dropdown-top">
                        <label tabindex="0" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-cog"></i>
                            Actions
                            <i class="fa-solid fa-chevron-up ml-1"></i>
                        </label>
                        <ul tabindex="0"
                            class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-56 border border-base-300 mb-2">
                            <li>
                                <button type="button" wire:click="activateProfiles">
                                    <i class="fa-solid fa-toggle-on text-success"></i>
                                    Activer
                                </button>
                            </li>
                            <li>
                                <button type="button" wire:click="deactivateProfiles">
                                    <i class="fa-solid fa-toggle-off text-warning"></i>
                                    Désactiver
                                </button>
                            </li>
                            <li>
                                <button type="button" wire:click="duplicateProfiles">
                                    <i class="fa-solid fa-copy"></i>
                                    Dupliquer
                                </button>
                            </li>
                            <div class="divider my-1"></div>
                            <li>
                                <button type="button" class="text-error" wire:click="deleteProfiles"
                                    wire:confirm="Êtes-vous sûr de vouloir supprimer ces profils ?">
                                    <i class="fa-solid fa-trash"></i>
                                    Supprimer
                                </button>
                            </li>
                        </ul>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('selectedProfiles', [])">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Modal création profil -->
    @if ($showCreateModal)
        <div class="modal modal-open">
            <div class="modal-box">
                <h3 class="font-bold text-lg mb-4">Nouveau Profil Applicatif</h3>
                <form wire:submit="createProfile">
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text">Nom technique *</span>
                        </label>
                        <input type="text" wire:model="newProfileName" class="input input-bordered"
                            placeholder="ex: salle-info-101" required />
                        @error('newProfileName')
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $message }}</span>
                            </label>
                        @enderror
                    </div>
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text">Nom d'affichage</span>
                        </label>
                        <input type="text" wire:model="newProfileDisplayName" class="input input-bordered"
                            placeholder="ex: Salle Informatique 101" />
                    </div>
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text">Description</span>
                        </label>
                        <textarea wire:model="newProfileDescription" class="textarea textarea-bordered" rows="3"
                            placeholder="Description du profil..."></textarea>
                    </div>
                    <div class="modal-action">
                        <button type="button" class="btn" wire:click="closeCreateModal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-check mr-2"></i>
                            Créer
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-backdrop" wire:click="closeCreateModal"></div>
        </div>
    @endif
</div>
