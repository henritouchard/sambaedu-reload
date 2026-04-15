<?php

use App\Services\UserGroupService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Groupe utilisateur')] class extends Component {
    private UserGroupService $userGroupService;

    public int $groupId;
    public string $name = '';
    public string $displayName = '';
    public string $type = 'custom';
    public array $selectedUserIds = [];
    public bool $editing = false;

    public function boot(UserGroupService $userGroupService): void
    {
        $this->userGroupService = $userGroupService;
    }

    public function mount(int $id): void
    {
        $group = $this->userGroupService->getById($id);

        if ($group === null) {
            abort(404, 'Groupe introuvable');
        }

        $this->groupId = $group->id;
        $this->name = $group->name;
        $this->displayName = $group->display_name ?? '';
        $this->type = $group->type;
        $this->selectedUserIds = $group->users->pluck('id')->map(fn(mixed $v): int => (int) $v)->values()->all();
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->userGroupService->getById($this->groupId)
            ?->users
            ?->map(function ($user): array {
                $label = $user->fullname ?: trim((string) (($user->firstname ?? '') . ' ' . ($user->lastname ?? '')));
                if ($label === '') {
                    $label = $user->login;
                }

                return [
                    'id' => $user->id,
                    'login' => $user->login,
                    'label' => $label,
                ];
            }) ?? collect();
    }

    #[Computed]
    public function availableUsers(): Collection
    {
        $memberIds = $this->userGroupService->getById($this->groupId)
            ?->users
            ?->pluck('id')
            ->map(fn(mixed $v): int => (int) $v)
            ->all() ?? [];

        return $this->userGroupService->getAssignableUsers()
            ->reject(fn($user): bool => in_array((int) $user->id, $memberIds, true))
            ->map(function ($user): array {
                $label = $user->fullname ?: trim((string) (($user->firstname ?? '') . ' ' . ($user->lastname ?? '')));
                if ($label === '') {
                    $label = $user->login;
                }

                return [
                    'value' => $user->id,
                    'label' => $label,
                    'hint' => $user->login,
                    'disabled' => false,
                ];
            })
            ->values();
    }

    public function removeMember(int $userId): void
    {
        $this->selectedUserIds = array_values(array_filter(
            $this->selectedUserIds,
            fn(int $id): bool => $id !== $userId,
        ));

        $this->userGroupService->updateGroup($this->groupId, [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'type' => $this->type,
            'user_ids' => $this->selectedUserIds,
        ]);

        unset($this->members);

        session()->flash('toast', [
            'type' => 'success',
            'title' => 'Membre retiré',
            'message' => 'Le membre a été retiré du groupe.',
        ]);
    }

    public function toggleUser(int $userId): void
    {
        if (in_array($userId, $this->selectedUserIds, true)) {
            $this->selectedUserIds = array_values(array_filter(
                $this->selectedUserIds,
                fn(int $id): bool => $id !== $userId,
            ));
        } else {
            $this->selectedUserIds[] = $userId;
        }
    }

    public function startEditing(): void
    {
        $this->editing = true;
    }

    public function cancelEditing(): void
    {
        $group = $this->userGroupService->getById($this->groupId);

        if ($group !== null) {
            $this->name = $group->name;
            $this->displayName = $group->display_name ?? '';
            $this->type = $group->type;
            $this->selectedUserIds = $group->users->pluck('id')->map(fn(mixed $v): int => (int) $v)->values()->all();
        }

        $this->resetValidation();
        $this->editing = false;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'displayName' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'selectedUserIds' => ['array'],
            'selectedUserIds.*' => ['integer', 'exists:users,id'],
        ]);

        $this->userGroupService->updateGroup($this->groupId, [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'type' => $this->type,
            'user_ids' => $this->selectedUserIds,
        ]);

        session()->flash('toast', [
            'type' => 'success',
            'title' => 'Groupe mis à jour',
            'message' => 'Les modifications ont été enregistrées.',
        ]);

        $this->editing = false;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'classe' => 'Classe',
            'cours' => 'Cours',
            'matiere' => 'Matière',
            'matiere_classe' => 'Matière / Classe',
            'projet' => 'Projet',
            'equipe' => 'Équipe',
            'custom' => 'Custom',
            'other_group' => 'Autre',
            default => ucfirst($this->type),
        };
    }

    public function typeBadgeClass(): string
    {
        return match ($this->type) {
            'classe' => 'badge-info',
            'cours' => 'badge-success',
            'matiere', 'matiere_classe' => 'badge-warning',
            'projet' => 'badge-secondary',
            'equipe' => 'badge-accent',
            default => 'badge-ghost',
        };
    }
};
?>

<x-organisms.page :title="$editing ? 'Modifier le groupe' : ($displayName ?: $name)" :scrollable="false"
    :description="$editing ? 'Éditez les informations et les membres du groupe' : null"
    :backUrl="!$editing ? route('app.users') : null" backText="Retour">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            @if ($editing)
                <button type="button" class="btn btn-ghost" wire:click="cancelEditing">
                    <i class="fa-solid fa-xmark"></i>
                    Annuler
                </button>
            @else
                <div class="dropdown dropdown-left">
                    <label tabindex="0" class="btn btn-primary gap-2 min-w-32">
                        <i class="fa-solid fa-pen-to-square"></i>
                        Actions
                        <i class="fa-solid fa-chevron-down text-xs"></i>
                    </label>
                    <ul tabindex="0"
                        class="dropdown-content menu p-2 shadow-lg bg-base-100 rounded-box w-64 border border-base-200">
                        <li>
                            <button type="button" class="flex items-center gap-3 w-full" wire:click="startEditing"
                                @click="document.activeElement.blur()">
                                <i class="fa-solid fa-pen-to-square w-4"></i>
                                <div class="flex flex-col items-start">
                                    <span class="font-medium">Modifier le groupe</span>
                                    <span class="text-xs opacity-70">Nom, type et membres</span>
                                </div>
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
        </div>
    </x-slot:actions>

    @if ($editing)
        @include('pages.users.groups.[id]._partials.edit-form')
    @else
        @include('pages.users.groups.[id]._partials.group-header')
        @include('pages.users.groups.[id]._partials.members-list')
    @endif
</x-organisms.page>
