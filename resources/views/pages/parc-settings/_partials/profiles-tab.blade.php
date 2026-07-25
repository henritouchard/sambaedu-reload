<?php

use App\Components\Traits\WithToasts;
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

    public array $selectedProfiles = [];

    #[Url]
    public int $profilesPerPage = 20;

    public array $allowedPerPage = [10, 20, 50, 100];

    // Modal création profil
    public bool $showCreateModal = false;

    public string $newProfileName = '';

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
            );
        } catch (\Exception $e) {
            Log::error('[ProfilesTab] Erreur chargement profils: '.$e->getMessage());

            return collect();
        }
    }

    public function resetProfileFilters(): void
    {
        $this->profileSearch = '';
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
            'newProfileDescription' => 'nullable|string',
        ]);

        try {
            $profile = $this->appProfileService->createProfile([
                'name' => $this->newProfileName,
                'description' => $this->newProfileDescription ?: null,
            ]);

            $this->toastSuccess("Profil '{$profile->name}' créé avec succès");
            $this->closeCreateModal();
        } catch (\Exception $e) {
            Log::error('[ProfilesTab] Erreur création profil: '.$e->getMessage());
            $this->toastError('Erreur lors de la création du profil');
        }
    }

    /**
     * Suppression groupée DÉFINITIVE. Le profil disparaît du catalogue et de
     * tous les parcs/postes auxquels il était rattaché : les applications qu'il
     * portait ne sont plus déployées. C'est le geste que l'ancien drapeau
     * « inactif » laissait croire qu'il faisait, sans jamais le faire.
     */
    public function deleteProfiles(): void
    {
        $ids = array_map('intval', $this->selectedProfiles);

        if ($ids === []) {
            return;
        }

        $deleted = 0;

        foreach ($ids as $profileId) {
            try {
                if ($this->appProfileService->deleteProfile($profileId)) {
                    $deleted++;
                }
            } catch (\Exception $e) {
                Log::error('[ProfilesTab] Erreur suppression profil: '.$e->getMessage(), [
                    'profile_id' => $profileId,
                ]);
            }
        }

        $this->selectedProfiles = [];

        if ($deleted === 0) {
            $this->toastError('Aucun profil supprimé');

            return;
        }

        $this->toastSuccess($deleted.' profil(s) supprimé(s) définitivement');
    }
};
?>

<div class="flex flex-col gap-3 flex-1 min-h-0">
    {{-- Story 38.7 — le badge de synchronisation AD des profils applicatifs a été
         retiré : OU=Parcs est en lecture seule, un profil n'a plus de représentation
         AD, la comparaison SQL ↔ AD n'a plus d'objet. --}}

    <!-- Filtres -->
    <x-molecules.filter-bar reset="resetProfileFilters">
        <div class="flex-1 min-w-[200px]">
            <x-atoms.search-input model="profileSearch" placeholder="Nom, description..." />
        </div>
    </x-molecules.filter-bar>

    <!-- Tableau des profils -->
    <div class="card bg-base-100 shadow-sm border border-base-300 flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="card-body p-0 flex flex-col flex-1 min-h-0">
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th class="w-12">
                                {{-- Sans binding, cette case ne sélectionnait rien : composant canonique. --}}
                                <x-molecules.select-all-checkbox class="checkbox-sm"
                                    :ids="$this->profiles->pluck('id')" model="selectedProfiles" />
                            </th>
                            <th>Nom</th>
                            <th>Description</th>
                            <th class="text-center">Applications</th>
                            <th class="text-center">Groupes</th>
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
                                    <span class="font-medium">{{ $profile->name }}</span>
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-8 text-base-content/60">
                                    <i class="fa-solid fa-cubes text-4xl mb-2 opacity-30"></i>
                                    <p>Aucun profil applicatif trouvé</p>
                                    @if ($profileSearch)
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
                    <button type="button" class="btn btn-error btn-sm" wire:click="deleteProfiles"
                        wire:confirm="Supprimer définitivement ces profils ? Les applications qu'ils portent ne seront plus déployées sur les parcs et postes rattachés.">
                        <i class="fa-solid fa-trash"></i>
                        Supprimer définitivement
                    </button>
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
                            <span class="label-text">Nom *</span>
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
