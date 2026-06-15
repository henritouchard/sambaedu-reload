<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\WithFileUploads;
use App\Models\Shortcut;
use App\Services\ShortcutsService;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use App\Components\Traits\WithToasts;
use Livewire\Attributes\Validate;

new #[Title('Détail du raccourci - Instance SE4FS')] class extends Component {
    use WithFileUploads, WithToasts;

    // Propriétés du raccourci
    public string $key = '';
    public ?Shortcut $shortcutModel = null;
    public bool $isGlobal = false;
    public bool $editing = false;
    public array $filters = [];

    // Assignations
    public array $assignedWorkstationGroups = [];
    public array $assignedWorkstations = [];
    public array $assignedAdUsers = [];
    public array $assignedAdUserGroups = [];

    // Champs du formulaire
    public string $name = '';
    public string $place = 'desktop';
    public string $windows_link = '';
    public string $windows_args = '';
    public string $windows_path = '';
    public string $windows_icon = '';
    public string $linux_link = '';
    public string $linux_args = '';
    public string $linux_path = '';
    public string $linux_startupwmclass = '';

    // Mode d'application desired-state (Story 27.1, FR26) : strict|default.
    public string $mode = 'strict';

    // Upload d'icône
    #[Validate('image|max:2048')] // 2MB Max
    public $icon_file = null;

    public function mount(string $id)
    {
        $this->key = $id;
        $this->filters = [
            'place' => [
                'desktop' => 'Bureau',
                'startup' => 'Démarrage automatique',
                'taskbar' => 'Barre des tâches (seulement Linux)',
            ],
        ];
        $this->loadShortcut();
    }

    public function loadShortcut()
    {
        try {
            $this->shortcutModel = Shortcut::findByKey($this->key);

            if (!$this->shortcutModel) {
                $this->toast('error', 'Erreur', 'Raccourci non trouvé');
                return $this->redirect(route('app.shortcuts'));
            }

            $this->isGlobal = $this->shortcutModel->is_global;

            // Remplir les champs du formulaire
            $this->name = $this->shortcutModel->name ?? '';
            $this->place = $this->shortcutModel->place ?? 'desktop';
            $this->windows_link = $this->shortcutModel->windows_link ?? '';
            $this->windows_args = $this->shortcutModel->windows_args ?? '';
            $this->windows_path = $this->shortcutModel->windows_path ?? '';
            $this->windows_icon = $this->shortcutModel->windows_icon ?? '';
            $this->linux_link = $this->shortcutModel->linux_link ?? '';
            $this->linux_args = $this->shortcutModel->linux_args ?? '';
            $this->linux_path = $this->shortcutModel->linux_path ?? '';
            $this->linux_startupwmclass = $this->shortcutModel->linux_startupwmclass ?? '';
            // Mode desired-state : null en base = défaut strict (la cible fait loi).
            $this->mode = $this->shortcutModel->mode?->value ?? 'strict';
            $this->loadAssignments();
        } catch (\Exception $e) {
            Log::error('ShortcutPage loadShortcut error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors du chargement du raccourci');
            return $this->redirect(route('app.shortcuts'));
        }
    }

    public function save()
    {
        if (Gate::denies('update-shortcut')) {
            $this->toast('error', 'Accès refusé', 'Vous n\'avez pas les droits pour modifier ce raccourci');
            return;
        }

        if ($this->isGlobal) {
            $this->toast('error', 'Erreur', 'Ce raccourci est géré par le ControlHub et ne peut pas être modifié ici');
            return;
        }

        $this->validate([
            'name' => 'required|string|max:255',
            'place' => 'required|in:desktop,startup,taskbar',
            'windows_link' => 'nullable|string|max:500',
            'windows_args' => 'nullable|string|max:500',
            'windows_path' => 'nullable|string|max:500',
            'linux_link' => 'nullable|string|max:500',
            'linux_args' => 'nullable|string|max:500',
            'linux_path' => 'nullable|string|max:500',
            'linux_startupwmclass' => 'nullable|string|max:255',
            'icon_file' => 'nullable|image|max:2048',
            'mode' => 'required|in:strict,default',
        ]);

        try {
            $this->shortcutModel->update([
                'name' => $this->name,
                'place' => $this->place,
                'windows_link' => $this->windows_link,
                'windows_args' => $this->windows_args,
                'windows_path' => $this->windows_path,
                'linux_link' => $this->linux_link,
                'linux_args' => $this->linux_args,
                'linux_path' => $this->linux_path,
                'linux_startupwmclass' => $this->linux_startupwmclass,
                'mode' => $this->mode,
            ]);

            // Gérer l'icône si uploadée
            if ($this->icon_file) {
                $shortcutsService = app(ShortcutsService::class);
                $iconPath = $shortcutsService->handleIconUpload($this->icon_file, $this->name);
                if ($iconPath) {
                    $this->shortcutModel->update([
                        'windows_icon' => $iconPath,
                        'icon_path' => $iconPath,
                    ]);
                }
            }

            $this->toast('success', 'Modification réussie', 'Raccourci modifié avec succès');
            $this->icon_file = null;
            $this->editing = false;
            $this->loadShortcut();

        } catch (\Exception $e) {
            Log::error('ShortcutPage save error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la modification du raccourci');
        }
    }

    public function startEdit(): void
    {
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->icon_file = null;
        $this->loadShortcut();
    }

    public function delete()
    {
        if (Gate::denies('delete-shortcut')) {
            $this->toast('error', 'Accès refusé', 'Vous n\'avez pas les droits pour supprimer ce raccourci');
            return;
        }

        if ($this->isGlobal) {
            $this->toast('error', 'Erreur', 'Ce raccourci est géré par le ControlHub et ne peut pas être supprimé ici');
            return;
        }

        try {
            $this->shortcutModel->delete();
            $this->toast('success', 'Suppression réussie', 'Raccourci supprimé avec succès');
            return $this->redirect(route('app.shortcuts'));
        } catch (\Exception $e) {
            Log::error('ShortcutPage delete error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors de la suppression du raccourci');
        }
    }

    public function getShortcutIconUrl(): string
    {
        $iconPath = '/etc/sambaedu/applications/shortcuts/' . $this->name . '.png';
        if (file_exists($iconPath)) {
            return route('shortcuts.icon', ['name' => $this->name]);
        }
        return asset('elements/images/system-run.png');
    }

    public function isUrlShortcut(): bool
    {
        return $this->shortcutModel?->isUrlShortcut() ?? false;
    }

    public function loadAssignments(): void
    {
        if (!$this->shortcutModel) {
            $this->assignedWorkstationGroups = [];
            $this->assignedWorkstations = [];
            $this->assignedAdUsers = [];
            $this->assignedAdUserGroups = [];
            return;
        }

        $this->assignedWorkstationGroups = $this->shortcutModel
            ->workstationGroups()->orderBy('name')->get()->all();

        $this->assignedWorkstations = $this->shortcutModel
            ->workstations()->orderBy('name')->get()->all();

        $this->assignedAdUsers = $this->shortcutModel->ad_users ?? [];
        $this->assignedAdUserGroups = $this->shortcutModel->ad_user_groups ?? [];
    }

    public function openAssignmentModal(): void
    {
        $this->dispatch('open-shortcut-assignment-modal',
            assignedWgIds: collect($this->assignedWorkstationGroups)->pluck('id')->toArray(),
            assignedWsIds: collect($this->assignedWorkstations)->pluck('id')->toArray(),
            assignedUsers: $this->assignedAdUsers,
            assignedUserGroups: $this->assignedAdUserGroups,
        );
    }

    #[On('shortcut-assignments-confirmed')]
    public function onAssignmentsConfirmed(
        array $workstationGroupIds = [],
        array $workstationIds = [],
        array $adUsers = [],
        array $adUserGroups = []
    ): void {
        if (!$this->shortcutModel) {
            return;
        }

        try {
            $count = 0;

            if (!empty($workstationGroupIds)) {
                $this->shortcutModel->workstationGroups()->syncWithoutDetaching($workstationGroupIds);
                $count += count($workstationGroupIds);
            }

            if (!empty($workstationIds)) {
                $this->shortcutModel->workstations()->syncWithoutDetaching($workstationIds);
                $count += count($workstationIds);
            }

            if (!empty($adUsers)) {
                $current = $this->shortcutModel->ad_users ?? [];
                $merged = array_values(array_unique(array_merge($current, $adUsers)));
                $this->shortcutModel->update(['ad_users' => $merged]);
                $count += count($adUsers);
            }

            if (!empty($adUserGroups)) {
                $current = $this->shortcutModel->ad_user_groups ?? [];
                $merged = array_values(array_unique(array_merge($current, $adUserGroups)));
                $this->shortcutModel->update(['ad_user_groups' => $merged]);
                $count += count($adUserGroups);
            }

            $this->shortcutModel->refresh();
            $this->loadAssignments();
            $this->toast('success', 'Assignations ajoutées', "{$count} cible(s) assignée(s) au raccourci");
        } catch (\Exception $e) {
            Log::error('ShortcutPage onAssignmentsConfirmed error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', "Erreur lors de l'assignation");
        }
    }

    public function detachWorkstationGroup(int $groupId): void
    {
        if (!$this->shortcutModel) return;
        try {
            $this->shortcutModel->workstationGroups()->detach($groupId);
            $this->loadAssignments();
            $this->toast('success', 'Retiré', 'Groupe de postes retiré');
        } catch (\Exception $e) {
            Log::error('detachWorkstationGroup error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors du retrait');
        }
    }

    public function detachWorkstation(int $wsId): void
    {
        if (!$this->shortcutModel) return;
        try {
            $this->shortcutModel->workstations()->detach($wsId);
            $this->loadAssignments();
            $this->toast('success', 'Retiré', 'Poste retiré');
        } catch (\Exception $e) {
            Log::error('detachWorkstation error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors du retrait');
        }
    }

    public function detachAdUserGroup(string $cn): void
    {
        if (!$this->shortcutModel) return;
        try {
            $current = $this->shortcutModel->ad_user_groups ?? [];
            $this->shortcutModel->update([
                'ad_user_groups' => array_values(array_diff($current, [$cn])),
            ]);
            $this->shortcutModel->refresh();
            $this->loadAssignments();
            $this->toast('success', 'Retiré', "Groupe AD « {$cn} » retiré");
        } catch (\Exception $e) {
            Log::error('detachAdUserGroup error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors du retrait');
        }
    }

    public function detachAdUser(string $cn): void
    {
        if (!$this->shortcutModel) return;
        try {
            $current = $this->shortcutModel->ad_users ?? [];
            $this->shortcutModel->update([
                'ad_users' => array_values(array_diff($current, [$cn])),
            ]);
            $this->shortcutModel->refresh();
            $this->loadAssignments();
            $this->toast('success', 'Retiré', "Utilisateur « {$cn} » retiré");
        } catch (\Exception $e) {
            Log::error('detachAdUser error: ' . $e->getMessage());
            $this->toast('error', 'Erreur', 'Erreur lors du retrait');
        }
    }
};
?>

@php
    $placeLabels = [
        'desktop' => 'Bureau',
        'startup' => 'Démarrage auto',
        'taskbar' => 'Barre des tâches',
    ];
@endphp

<x-organisms.page :backUrl="route('app.shortcuts')" title="Modifier le raccourci" backText="Retour à la liste">
    <x-slot:actions>
        <div class="flex gap-2">
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost">
                    <i class="fa-solid fa-ellipsis-vertical"></i>
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-56 p-2 shadow-lg border border-base-200">
                    @if (!$isGlobal)
                        <li>
                            <button type="button" @click="$wire.openAssignmentModal(); document.activeElement.blur();">
                                <i class="fa-solid fa-bullseye"></i>
                                Gérer les assignations
                            </button>
                        </li>
                        <li class="border-t border-base-200 mt-1 pt-1">
                            <button type="button" class="text-error" wire:click="delete"
                                wire:confirm="Êtes-vous sûr de vouloir supprimer ce raccourci ?">
                                <i class="fa-regular fa-trash-can"></i>
                                Supprimer
                            </button>
                        </li>
                    @endif
                </ul>
            </div>
        </div>
    </x-slot:actions>

    <!-- Formulaire raccourci (vue/édition) -->
    <form wire:submit="save" class="space-y-6 mb-4">
        @include('pages.shortcuts.[id]._partials.shortcut-form')
    </form>

    <!-- Cibles assignées -->
    @include('pages.shortcuts.[id]._partials.assigned-groups')
</x-organisms.page>
