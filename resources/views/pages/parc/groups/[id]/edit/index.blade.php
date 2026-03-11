<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Services\Parc\WorkstationGroupService;
use App\Models\WorkstationGroup;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

new #[Title('Modifier le Groupe - SE4FS')] class extends Component {
    use WithToasts;

    private WorkstationGroupService $parcService;

    public string|int $id;
    public ?WorkstationGroup $group = null;

    // Données du formulaire
    public string $name = '';
    public string $description = '';
    public ?int $parent_id = null;
    public bool $is_physical = false;

    // Données pour les sélecteurs
    public Collection $availableParents;

    public function boot(WorkstationGroupService $parcService): void
    {
        $this->parcService = $parcService;
    }

    public function mount(string|int $id): void
    {
        $this->id = (int) $id;
        $this->availableParents = collect();
        $this->loadGroup();
        $this->loadParents();
    }

    public function loadGroup(): void
    {
        try {
            $this->group = $this->parcService->getGroup($this->id);

            if (!$this->group) {
                session()->flash('toast', [
                    'type' => 'error',
                    'title' => 'Erreur',
                    'message' => 'Groupe non trouvé',
                ]);
                $this->redirect(route('app.parc.index'));
                return;
            }

            // Remplir le formulaire
            $this->name = $this->group->name;
            $this->description = $this->group->description ?? '';
            $this->parent_id = $this->group->parent_id;
            $this->is_physical = (bool) $this->group->is_physical;
        } catch (\Exception $e) {
            Log::error('[GroupEdit] Erreur chargement: ' . $e->getMessage());
            $this->toastError('Erreur lors du chargement du groupe');
        }
    }

    public function loadParents(): void
    {
        try {
            $this->availableParents = WorkstationGroup::where('id', '!=', $this->id)->orderBy('name')->get();
        } catch (\Exception $e) {
            Log::error('[GroupEdit] Erreur chargement parents: ' . $e->getMessage());
            $this->availableParents = collect();
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'parent_id' => 'nullable|integer|exists:workstation_groups,id',
            'is_physical' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom du groupe est requis.',
            'name.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'description.max' => 'La description ne peut pas dépasser 500 caractères.',
            'parent_id.exists' => 'Le groupe parent sélectionné n\'existe pas.',
        ];
    }

    public function save(): void
    {
        $validated = $this->validate();

        if ($validated['parent_id'] == $this->id) {
            $this->toastError('Un groupe ne peut pas être son propre parent');
            return;
        }

        try {
            $this->parcService->updateGroup($this->id, [
                'name' => $validated['name'],
                'description' => $validated['description'] ?: null,
                'parent_id' => $validated['parent_id'] ?: null,
                'is_physical' => $validated['is_physical'],
            ]);

            session()->flash('toast', [
                'type' => 'success',
                'title' => 'Groupe modifié',
                'message' => "Le groupe \"{$validated['name']}\" a été modifié avec succès.",
            ]);

            $this->redirect(route('app.parc.groups.show', $this->id));
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[GroupEdit] Erreur modification: ' . $e->getMessage());
            $this->toastError('Une erreur est survenue lors de la modification du groupe.');
        }
    }
};
?>

<x-organisms.page title="Modifier {{ $group?->name ?? 'Groupe' }}" :scrollable="true"
    description="Modifier les informations du groupe">

    <x-slot:actions>
        <a href="{{ route('app.parc.groups.show', $id) }}" class="btn btn-ghost">
            <i class="fa-solid fa-arrow-left"></i>
            Retour
        </a>
    </x-slot:actions>

    @if ($group)
        <div class="max-w-2xl mx-auto">
            @include('pages.parc.groups.[id].edit._partials.form')
        </div>
    @else
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body flex flex-col items-center justify-center py-16">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <h3 class="text-xl font-semibold mb-3">Groupe non trouvé</h3>
                <a href="{{ route('app.parc.index') }}" class="btn btn-primary">
                    <i class="fa-solid fa-arrow-left"></i>
                    Retour à la liste
                </a>
            </div>
        </div>
    @endif
</x-organisms.page>
