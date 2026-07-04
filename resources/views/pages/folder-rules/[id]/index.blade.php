<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\FsAclAuthoringException;
use App\Models\FolderAccessRule;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Services\Agent\FolderAccessRuleService;
use App\Services\Agent\FolderAccessRuleValidator;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 36.4 — Détail d'une règle d'accès : édition des champs + activation/
 * désactivation + assignation de parcs (picker RESTREINT aux parcs autorisés,
 * piège #9) + suppression (refusée si active — D3). Calque `shares/[id]`.
 */
new #[Title('Règle d\'accès - Instance SE4FS')] class extends Component {
    use WithToasts;

    public int $id;
    public ?FolderAccessRule $rule = null;

    // Édition des champs métier.
    public bool $editing = false;
    public string $label = '';
    public string $path = '';
    public string $groupSearch = '';
    public ?int $userGroupId = null;
    public string $sens = 'deny';
    public string $niveau = 'list_folder';
    public string $portee = 'folder_only';
    public bool $denyAcknowledged = false;

    // Assignation de parcs.
    public ?int $assignParcId = null;

    public const SENS_LABELS = [
        'deny' => 'Interdire',
        'allow' => 'Autoriser',
    ];
    public const NIVEAU_LABELS = [
        'list_folder' => 'Parcourir',
        'read' => 'Lire',
        'write' => 'Écrire',
        'modify' => 'Modifier',
    ];
    public const PORTEE_LABELS = [
        'folder_only' => 'Ce dossier seul',
        'folder_subfolders_files' => 'Dossier et contenu',
    ];

    public function mount(string $id): void
    {
        abort_unless(Gate::allows('view-folderrule'), 403);
        $this->id = (int) $id;
        $this->loadRule();
    }

    public function loadRule(): void
    {
        $rule = FolderAccessRule::with('userGroup')->find($this->id);
        if ($rule === null) {
            abort(404);
        }
        $this->rule = $rule;
        $this->label = (string) $rule->label;
        $this->path = (string) $rule->path;
        $this->userGroupId = (int) $rule->user_group_id;
        $this->sens = (string) $rule->ace_type;
        $this->niveau = (string) $rule->rights;
        $this->portee = (string) $rule->applies_to;
        $this->denyAcknowledged = false;
    }

    // --- Parcs assignés + candidats (SCOPÉS, piège #9) ----------------------

    #[Computed]
    public function assignedParcs(): array
    {
        if ($this->rule === null) {
            return [];
        }

        return $this->rule->workstationGroups()->get()
            ->map(fn (WorkstationGroup $w): array => [
                'id' => $w->id,
                'label' => (string) ($w->display_name ?: $w->name),
                'is_physical' => (bool) $w->is_physical,
            ])->all();
    }

    /**
     * Parcs proposables = parcs NON encore assignés ET que l'acteur peut gérer
     * (délégation scopée — piège #9). L'admin global voit tout ; un délégué ne
     * voit que ses parcs.
     *
     * @return array<int, array{id:int,label:string}>
     */
    #[Computed]
    public function parcCandidates(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            return [];
        }

        $assignedIds = collect($this->assignedParcs())->pluck('id')->map(fn ($v) => (int) $v)->all();
        $permissions = app(PermissionService::class);

        return WorkstationGroup::query()
            ->whereNotIn('id', $assignedIds)
            ->orderBy('name')
            ->get(['id', 'name', 'display_name', 'is_physical'])
            ->filter(fn (WorkstationGroup $w): bool => $permissions->canOnWorkstationGroup($user, 'folderrule.manage', $w))
            ->map(fn (WorkstationGroup $w): array => ['id' => $w->id, 'label' => (string) ($w->display_name ?: $w->name)])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,label:string}>
     */
    public function groupCandidates(): array
    {
        $needle = '%' . strtolower(trim($this->groupSearch)) . '%';

        return UserGroup::query()
            ->when($this->groupSearch !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(display_name) LIKE ?', [$needle]))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'display_name'])
            ->map(fn (UserGroup $g): array => ['id' => $g->id, 'label' => (string) ($g->display_name ?: $g->name)])
            ->all();
    }

    // --- Édition des champs -------------------------------------------------

    public function editRule(): void
    {
        abort_unless(Gate::allows('manage-folderrule'), 403);
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetErrorBag();
        $this->loadRule();
    }

    public function saveRule(): void
    {
        abort_unless(Gate::allows('manage-folderrule'), 403);

        $validated = $this->validate([
            'label' => ['required', 'string', 'max:255'],
            'path' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z]:\\\\/'],
            'userGroupId' => ['required', 'integer', 'exists:user_groups,id'],
            'sens' => ['required', 'in:deny,allow'],
            'niveau' => ['required', 'in:list_folder,read,write,modify'],
            'portee' => ['required', 'in:folder_only,folder_subfolders_files'],
        ], [
            'path.regex' => 'Le chemin doit être un chemin Windows absolu (ex. D:\\Ressources).',
            'userGroupId.required' => 'Choisissez un groupe.',
        ]);

        if ($this->sens === 'deny' && ! $this->denyAcknowledged) {
            $this->addError('denyAcknowledged', 'Vous devez confirmer les implications de cette règle « Interdire ».');
            return;
        }

        try {
            $this->rule = app(FolderAccessRuleService::class)->update($this->rule, [
                'path' => $validated['path'],
                'user_group_id' => (int) $validated['userGroupId'],
                'ace_type' => $validated['sens'],
                'rights' => $validated['niveau'],
                'applies_to' => $validated['portee'],
                'label' => $validated['label'],
            ], $this->currentUser());
        } catch (FsAclAuthoringException $e) {
            foreach ($e->violations as $violation) {
                $this->toastError($violation);
            }
            return;
        }

        $this->surfacePredictiveWarnings($this->rule);
        $this->editing = false;
        $this->loadRule();
        $this->toastSuccess('Règle mise à jour.');
    }

    // --- Cycle de vie -------------------------------------------------------

    public function toggleActive(): void
    {
        abort_unless(Gate::allows('manage-folderrule'), 403);
        $active = ! (bool) $this->rule->is_active;
        $this->rule = app(FolderAccessRuleService::class)->setActive($this->rule, $active, $this->currentUser());
        $this->loadRule();

        if ($active) {
            $this->toastSuccess('Règle activée — les ACE seront posées au prochain cycle des postes concernés.');
        } else {
            $this->toastWarning('Règle désactivée — les ACE seront RETIRÉES au prochain cycle (retrait honnête), pas simplement oubliées.');
        }
    }

    public function deleteRule()
    {
        abort_unless(Gate::allows('manage-folderrule'), 403);
        try {
            app(FolderAccessRuleService::class)->delete($this->rule, $this->currentUser());
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());
            return;
        }

        session()->flash('toast', [
            'status' => 'success',
            'title' => 'Règle supprimée',
            'message' => 'La règle a été supprimée.',
        ]);

        return redirect()->route('app.folder-rules');
    }

    // --- Assignation de parcs -----------------------------------------------

    public function attachParc(): void
    {
        abort_unless(Gate::allows('manage-folderrule'), 403);
        if ($this->assignParcId === null) {
            $this->toastWarning('Sélectionnez un parc à assigner.');
            return;
        }
        $group = WorkstationGroup::find($this->assignParcId);
        if ($group === null) {
            $this->toastError('Parc introuvable.');
            return;
        }

        try {
            app(FolderAccessRuleService::class)->attachParc($this->rule, $group, $this->currentUser());
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());
            return;
        }

        $this->assignParcId = null;
        unset($this->assignedParcs, $this->parcCandidates);
        $this->toastSuccess('Parc assigné.');
    }

    public function detachParc(int $wgId): void
    {
        abort_unless(Gate::allows('manage-folderrule'), 403);
        $group = WorkstationGroup::find($wgId);
        if ($group === null) {
            return;
        }

        try {
            app(FolderAccessRuleService::class)->detachParc($this->rule, $group, $this->currentUser());
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());
            return;
        }

        unset($this->assignedParcs, $this->parcCandidates);
        $this->toastSuccess('Parc retiré.');
    }

    // --- Helpers ------------------------------------------------------------

    private function surfacePredictiveWarnings(FolderAccessRule $rule): void
    {
        $validator = app(FolderAccessRuleValidator::class);
        foreach ($validator->capabilityOverlaps($rule->path, $rule->trusteeName(), $rule->ace_type) as $capabilityKey) {
            $this->toastWarning(
                "Cette règle recouvre la capacité « {$capabilityKey} » — en cas de conflit, la maille la plus spécifique / la plus récente gagne."
            );
        }
        if ($validator->missingAdDn((int) $rule->user_group_id)) {
            $this->toastWarning("Groupe sans correspondance AD connue — la règle pourrait ne pas s'appliquer.");
        }
    }

    public function label(string $type, string $value): string
    {
        return match ($type) {
            'sens' => self::SENS_LABELS[$value] ?? $value,
            'niveau' => self::NIVEAU_LABELS[$value] ?? $value,
            'portee' => self::PORTEE_LABELS[$value] ?? $value,
            default => $value,
        };
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();
        return $user instanceof User ? $user : null;
    }
}; ?>

<x-organisms.page title="Règle d'accès aux dossiers" :scrollable="true">
    <x-slot:actions>
        <a href="{{ route('app.folder-rules') }}" class="btn btn-ghost btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Retour
        </a>
        @can('manage-folderrule')
            @if (! $rule->is_active)
                <button type="button" class="btn btn-error btn-sm" wire:click="deleteRule"
                    wire:confirm="Supprimer définitivement cette règle inactive ?">
                    <i class="fa-regular fa-trash-can"></i> Supprimer
                </button>
            @endif
        @endcan
    </x-slot:actions>

    {{-- ===================== Header : identité de la règle ===================== --}}
    <div class="card bg-base-100 shadow mb-6">
        <div class="card-body">
            @if ($editing)
                <h2 class="card-title text-base"><i class="fa-solid fa-pen text-primary"></i> Modifier la règle</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 mt-1">
                    <div class="form-control sm:col-span-2">
                        <label class="label"><span class="label-text font-medium">Libellé <span class="text-error">*</span></span></label>
                        <input type="text" wire:model="label" class="input input-bordered" />
                        @error('label') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-control sm:col-span-2">
                        <label class="label">
                            <span class="label-text font-medium">Chemin du dossier <span class="text-error">*</span>
                                <span class="tooltip align-middle" data-tip="Chemin Windows absolu, ex. D:\Ressources">
                                    <i class="fa-solid fa-circle-info text-base-content/40 ml-0.5"></i>
                                </span>
                            </span>
                        </label>
                        <input type="text" wire:model="path" class="input input-bordered font-mono" />
                        @error('path') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Sens <span class="text-error">*</span></span></label>
                        <select wire:model.live="sens" class="select select-bordered">
                            @foreach (self::SENS_LABELS as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Niveau <span class="text-error">*</span></span></label>
                        <select wire:model="niveau" class="select select-bordered">
                            @foreach (self::NIVEAU_LABELS as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
                        </select>
                    </div>
                    <div class="form-control sm:col-span-2">
                        <label class="label"><span class="label-text font-medium">Portée <span class="text-error">*</span></span></label>
                        <select wire:model="portee" class="select select-bordered">
                            @foreach (self::PORTEE_LABELS as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
                        </select>
                    </div>
                </div>

                <div class="form-control mt-2">
                    <label class="label"><span class="label-text font-medium">Groupe concerné <span class="text-error">*</span></span></label>
                    <label class="input input-bordered flex items-center gap-2">
                        <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                        <input type="search" wire:model.live.debounce.300ms="groupSearch" class="grow" placeholder="Rechercher un groupe..." />
                    </label>
                    <div class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-base-300 divide-y divide-base-200">
                        @foreach ($this->groupCandidates() as $cand)
                            <button type="button" wire:key="grp-{{ $cand['id'] }}"
                                class="w-full text-left px-3 py-2 hover:bg-base-200 flex items-center gap-2 {{ $userGroupId === $cand['id'] ? 'bg-primary/10' : '' }}"
                                wire:click="$set('userGroupId', {{ $cand['id'] }})">
                                <i class="fa-solid fa-users opacity-50 w-4 text-center"></i>
                                <span class="text-sm truncate grow">{{ $cand['label'] }}</span>
                                @if ($userGroupId === $cand['id'])<i class="fa-solid fa-check text-primary"></i>@endif
                            </button>
                        @endforeach
                    </div>
                    @error('userGroupId') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                @if ($sens === 'deny')
                    <div class="alert alert-warning text-sm mt-2">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>{{ \App\Services\Agent\FolderAccessRuleService::DENY_WARNING }}</span>
                    </div>
                    <label class="label cursor-pointer justify-start gap-2">
                        <input type="checkbox" wire:model="denyAcknowledged" class="checkbox checkbox-warning checkbox-sm" />
                        <span class="label-text">J'ai compris les implications.</span>
                    </label>
                    @error('denyAcknowledged') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                @endif

                <div class="card-actions justify-end mt-3 gap-2">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelEdit">Annuler</button>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="saveRule">
                        <i class="fa-solid fa-floppy-disk"></i> Enregistrer
                    </button>
                </div>
            @else
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="card-title text-lg flex items-center gap-2 flex-wrap">
                            <span class="truncate">{{ $rule->label }}</span>
                            <span class="badge badge-sm {{ $rule->ace_type === 'deny' ? 'badge-error' : 'badge-success' }}">{{ $this->label('sens', $rule->ace_type) }}</span>
                            @if ($rule->is_active)
                                <span class="badge badge-sm badge-success">Active</span>
                            @else
                                <span class="badge badge-sm badge-ghost">Désactivée</span>
                            @endif
                        </h2>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        @can('manage-folderrule')
                            <button type="button" class="btn btn-ghost btn-sm {{ $rule->is_active ? 'text-warning' : 'text-success' }}" wire:click="toggleActive">
                                <i class="fa-solid {{ $rule->is_active ? 'fa-toggle-off' : 'fa-toggle-on' }}"></i>
                                {{ $rule->is_active ? 'Désactiver' : 'Activer' }}
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="editRule"><i class="fa-solid fa-pen"></i> Modifier</button>
                        @endcan
                    </div>
                </div>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex items-center gap-2 text-base-content/70">
                        <i class="fa-solid fa-folder w-4 text-center opacity-50"></i>
                        <span class="font-mono">{{ $rule->path }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-base-content/70">
                        <i class="fa-solid fa-users w-4 text-center opacity-50"></i>
                        <span>{{ $rule->userGroup?->getDisplayNameOrNameAttribute() ?? ('#' . $rule->user_group_id) }}</span>
                        <span class="text-base-content/40 text-xs">(groupe concerné)</span>
                    </div>
                    <div class="flex items-center gap-2 text-base-content/70">
                        <i class="fa-solid fa-shield-halved w-4 text-center opacity-50"></i>
                        <span>{{ $this->label('niveau', $rule->rights) }} — {{ $this->label('portee', $rule->applies_to) }}</span>
                    </div>
                </dl>
            @endif
        </div>
    </div>

    {{-- ===================== Parcs cibles ===================== --}}
    <div class="card bg-base-100 shadow mb-6">
        <div class="card-body">
            <div class="flex items-center justify-between gap-2">
                <h2 class="card-title text-base">
                    <i class="fa-solid fa-layer-group text-primary"></i> Parcs cibles
                    <span class="badge badge-neutral badge-sm">{{ count($this->assignedParcs()) }}</span>
                </h2>
            </div>
            <p class="text-xs text-base-content/50 mb-2">La règle ne s'applique qu'aux postes des parcs assignés (portée machine).</p>

            @can('manage-folderrule')
                <div class="flex items-end gap-2 mb-3">
                    <div class="form-control flex-1">
                        <label class="label py-1"><span class="label-text text-sm">Ajouter un parc</span></label>
                        <select wire:model="assignParcId" class="select select-bordered select-sm">
                            <option value="">— choisir un parc —</option>
                            @foreach ($this->parcCandidates() as $cand)
                                <option value="{{ $cand['id'] }}">{{ $cand['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="attachParc"><i class="fa-solid fa-plus"></i> Assigner</button>
                </div>
            @endcan

            @if (count($this->assignedParcs()) === 0)
                <div class="text-sm text-base-content/50 text-center py-6">
                    <i class="fa-regular fa-folder-open text-2xl mb-2 block opacity-40"></i>
                    Aucun parc assigné — la règle n'est appliquée à aucun poste.
                </div>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->assignedParcs() as $parc)
                        <span wire:key="parc-{{ $parc['id'] }}" class="badge badge-lg gap-2 {{ $parc['is_physical'] ? 'badge-info' : 'badge-warning' }}">
                            <i class="fa-solid {{ $parc['is_physical'] ? 'fa-door-open' : 'fa-layer-group' }} text-xs"></i>
                            {{ $parc['label'] }}
                            @can('manage-folderrule')
                                <button type="button" class="hover:text-error" wire:click="detachParc({{ $parc['id'] }})"
                                    wire:confirm="Retirer ce parc de la règle ?">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            @endcan
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-organisms.page>
