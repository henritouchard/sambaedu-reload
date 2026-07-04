<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\FsAclAuthoringException;
use App\Models\FolderAccessRule;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Agent\FolderAccessRuleService;
use App\Services\Agent\FolderAccessRuleValidator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Story 36.4 — Page liste des règles d'accès aux dossiers + modale de création.
 *
 * SFC Volt (calque `pages/shares/index.blade.php`). Pivot SQL pur (zéro CN AD au
 * chemin SQL). Gardée par la policy dédiée `folderrule.*` (D6) : la route impose
 * `can:folderrule.view`, les mutations vérifient `manage-folderrule`. Le
 * formulaire n'expose QUE des champs MÉTIER (D8) — les enums techniques sont
 * mappés depuis des mots métier.
 */
new #[Title('Règles d\'accès aux dossiers - Instance SE4FS')] class extends Component {
    use WithToasts;

    #[Url]
    public string $search = '';
    #[Url]
    public int $perPage = 20;
    #[Url]
    public int $currentPage = 1;

    public array $allowedPerPage = [10, 20, 50, 100];

    /** @var array<int, array<string, mixed>> */
    public array $rules = [];
    public int $totalRules = 0;
    public ?array $pagination = null;

    // --- Modale de création (champs 100 % métier, D8) -----------------------
    public bool $isCreateOpen = false;
    public string $label = '';
    public string $path = '';
    public string $groupSearch = '';
    public ?int $userGroupId = null;
    public string $sens = 'deny';        // deny | allow
    public string $niveau = 'list_folder'; // list_folder | read | write | modify
    public string $portee = 'folder_only'; // folder_only | folder_subfolders_files
    public bool $denyAcknowledged = false;

    /** Mots métier → enums (source unique pour l'UI + la validation). */
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

    public function mount(): void
    {
        abort_unless(Gate::allows('view-folderrule'), 403);
        $this->loadRules();
    }

    public function updatedSearch(): void
    {
        $this->currentPage = 1;
        $this->loadRules();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, $this->allowedPerPage, true)) {
            $this->perPage = 20;
        }
        $this->currentPage = 1;
        $this->loadRules();
    }

    public function loadRules(): void
    {
        try {
            $query = FolderAccessRule::query()->with('userGroup');

            if ($this->search !== '') {
                $needle = '%' . strtolower($this->search) . '%';
                $query->where(function ($q) use ($needle): void {
                    $q->whereRaw('LOWER(label) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(path) LIKE ?', [$needle]);
                });
            }

            $this->totalRules = $query->count();

            $lastPage = max(1, (int) ceil($this->totalRules / $this->perPage));
            $this->currentPage = min(max(1, $this->currentPage), $lastPage);
            $offset = ($this->currentPage - 1) * $this->perPage;

            $rows = $query
                ->withCount('assignments')
                ->orderBy('label')
                ->skip($offset)
                ->take($this->perPage)
                ->get();

            $this->rules = $rows->map(fn (FolderAccessRule $r): array => [
                'id' => $r->id,
                'label' => $r->label,
                'path' => $r->path,
                'group' => $r->userGroup?->getDisplayNameOrNameAttribute() ?? ('#' . $r->user_group_id),
                'sens' => self::SENS_LABELS[$r->ace_type] ?? $r->ace_type,
                'niveau' => self::NIVEAU_LABELS[$r->rights] ?? $r->rights,
                'portee' => self::PORTEE_LABELS[$r->applies_to] ?? $r->applies_to,
                'is_active' => (bool) $r->is_active,
                'parcs_count' => $r->assignments_count,
            ])->all();

            $this->pagination = [
                'current_page' => $this->currentPage,
                'per_page' => $this->perPage,
                'total' => $this->totalRules,
                'last_page' => $lastPage,
                'from' => $this->totalRules > 0 ? $offset + 1 : 0,
                'to' => min($offset + $this->perPage, $this->totalRules),
                'has_more_pages' => $this->currentPage < $lastPage,
            ];
        } catch (\Throwable $e) {
            Log::error('FolderRulesPage loadRules error: ' . $e->getMessage());
            $this->rules = [];
            $this->pagination = null;
            $this->totalRules = 0;
        }
    }

    public function goToPage($page): void
    {
        $this->currentPage = max(1, min((int) $page, $this->pagination['last_page'] ?? 1));
        $this->loadRules();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->currentPage = 1;
        $this->loadRules();
    }

    // --- Picker de groupe (SQL pur, zéro CN AD) -----------------------------

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

    // --- Création -----------------------------------------------------------

    public function openCreate(): void
    {
        abort_unless(Gate::allows('manage-folderrule'), 403);
        $this->resetCreateForm();
        $this->isCreateOpen = true;
    }

    public function close(): void
    {
        $this->isCreateOpen = false;
        $this->resetCreateForm();
    }

    private function resetCreateForm(): void
    {
        $this->label = '';
        $this->path = '';
        $this->groupSearch = '';
        $this->userGroupId = null;
        $this->sens = 'deny';
        $this->niveau = 'list_folder';
        $this->portee = 'folder_only';
        $this->denyAcknowledged = false;
        $this->resetErrorBag();
    }

    protected function createRules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            // Chemin Windows absolu — regex MIROIR du guard (`^[A-Za-z]:\`).
            'path' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z]:\\\\/'],
            'userGroupId' => ['required', 'integer', 'exists:user_groups,id'],
            'sens' => ['required', 'in:deny,allow'],
            'niveau' => ['required', 'in:list_folder,read,write,modify'],
            'portee' => ['required', 'in:folder_only,folder_subfolders_files'],
        ];
    }

    protected function createMessages(): array
    {
        return [
            'label.required' => 'Le libellé est requis.',
            'path.required' => 'Le chemin est requis.',
            'path.regex' => 'Le chemin doit être un chemin Windows absolu (ex. D:\\Ressources).',
            'userGroupId.required' => 'Choisissez un groupe.',
            'userGroupId.exists' => 'Groupe introuvable.',
        ];
    }

    public function createRule()
    {
        abort_unless(Gate::allows('manage-folderrule'), 403);

        $validated = $this->validate($this->createRules(), $this->createMessages());

        // Confirmation d'implications OBLIGATOIRE pour un `deny` (patron warning
        // capacités : l'acquittement est bloquant côté formulaire — D4/piège #10).
        if ($this->sens === 'deny' && ! $this->denyAcknowledged) {
            $this->addError('denyAcknowledged', 'Vous devez confirmer les implications de cette règle « Interdire ».');
            return;
        }

        try {
            $rule = app(FolderAccessRuleService::class)->create([
                'path' => $validated['path'],
                'user_group_id' => (int) $validated['userGroupId'],
                'ace_type' => $validated['sens'],
                'rights' => $validated['niveau'],
                'applies_to' => $validated['portee'],
                'label' => $validated['label'],
                'is_active' => true,
            ], $this->currentUser());
        } catch (FsAclAuthoringException $e) {
            // Violations du guard → messages FR explicites (racines protégées,
            // principals système, 8.3, enums…).
            foreach ($e->violations as $violation) {
                $this->toastError($violation);
            }
            return;
        }

        // Avertissements NON bloquants (la règle est déjà créée).
        $this->surfacePredictiveWarnings($rule);

        $this->toastSuccess("La règle « {$rule->label} » a été créée. Assignez-lui des parcs pour l'appliquer.");
        $this->isCreateOpen = false;
        $this->resetCreateForm();

        // Redirige vers la page d'édition (assignation des parcs cibles).
        return $this->redirect(route('app.folder-rules.show', $rule->id), navigate: true);
    }

    private function surfacePredictiveWarnings(FolderAccessRule $rule): void
    {
        $validator = app(FolderAccessRuleValidator::class);

        // Recouvrement d'une capacité catalogue ACTIVE (D5) — non bloquant.
        $overlaps = $validator->capabilityOverlaps($rule->path, $rule->trusteeName(), $rule->ace_type);
        foreach ($overlaps as $capabilityKey) {
            $this->toastWarning(
                "Cette règle recouvre la capacité « {$capabilityKey} » — en cas de conflit, la maille la plus spécifique / la plus récente gagne."
            );
        }

        // Groupe sans correspondance AD connue (D9/piège #4) — non bloquant.
        if ($validator->missingAdDn((int) $rule->user_group_id)) {
            $this->toastWarning(
                "Groupe sans correspondance AD connue — la règle pourrait ne pas s'appliquer (résolution du nom impossible sur le poste)."
            );
        }
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();
        return $user instanceof User ? $user : null;
    }
}; ?>

@php
    $hasFilters = $search !== '';
@endphp

<x-organisms.page title="Règles d'accès aux dossiers" :scrollable="false"
    description="Interdisez ou autorisez un dossier à un groupe, par parc — sans attendre une capacité catalogue">

    <x-slot:actions>
        @can('manage-folderrule')
            <button type="button" class="btn highlight btn-primary" wire:click="openCreate">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Nouvelle règle
            </button>
        @endcan
    </x-slot:actions>

    <div class="flex flex-col h-full">
        <div class="space-y-3">
            <div class="flex-1 min-w-48">
                <x-atoms.searchInput wire:model.live.debounce.500ms="search" id="searchInput"
                    placeholder="Rechercher (par libellé, chemin...)" icon="fa-magnifying-glass" class="w-full" />
            </div>
        </div>

        @if (count($rules) > 0)
            <div class="flex justify-between items-center my-4">
                <span class="text-base-content/70">{{ $totalRules }} règle(s) trouvée(s)</span>
            </div>

            <x-organisms.data-table
                colgroup="<colgroup><col style='width: 20%'><col style='width: 18%'><col style='width: 14%'><col style='width: 9%'><col style='width: 9%'><col style='width: 14%'><col style='width: 8%'><col style='width: 8%'></colgroup>">
                <x-slot:header>
                    <th>Libellé</th>
                    <th>Chemin</th>
                    <th>Groupe</th>
                    <th>Sens</th>
                    <th>Niveau</th>
                    <th>Portée</th>
                    <th>Parcs</th>
                    <th>Statut</th>
                </x-slot:header>
                @foreach ($rules as $rule)
                    <tr class="hover:bg-sky-50 cursor-pointer"
                        onclick="window.location.href='{{ route('app.folder-rules.show', $rule['id']) }}'">
                        <td class="font-bold">{{ $rule['label'] }}</td>
                        <td><span class="font-mono text-sm">{{ $rule['path'] }}</span></td>
                        <td>{{ $rule['group'] }}</td>
                        <td>
                            <span class="badge badge-sm {{ $rule['sens'] === 'Interdire' ? 'badge-error' : 'badge-success' }}">
                                {{ $rule['sens'] }}
                            </span>
                        </td>
                        <td>{{ $rule['niveau'] }}</td>
                        <td class="text-sm text-base-content/70">{{ $rule['portee'] }}</td>
                        <td>
                            @if ($rule['parcs_count'] === 0)
                                <span class="text-sm text-base-content/40">Aucun</span>
                            @else
                                <span class="badge badge-sm badge-warning">
                                    <i class="fa-solid fa-layer-group text-xs mr-1"></i>{{ $rule['parcs_count'] }}
                                </span>
                            @endif
                        </td>
                        <td>
                            @if ($rule['is_active'])
                                <span class="badge badge-sm badge-success">Active</span>
                            @else
                                <span class="badge badge-sm badge-ghost">Désactivée</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-organisms.data-table>

            @if ($pagination)
                <x-molecules.pagination :currentPage="$pagination['current_page']" :lastPage="$pagination['last_page']" :total="$pagination['total']" :from="$pagination['from']"
                    :to="$pagination['to']" :perPage="$perPage" :allowedPerPage="$allowedPerPage" onPageChange="goToPage"
                    perPageModel="perPage" itemLabel="règle" itemLabelPlural="règles" />
            @endif
        @else
            <div class="card flex-1 flex flex-col justify-center items-center mt-8">
                <div class="card-body flex-col justify-center items-center flex-0 py-16">
                    <i class="fa-solid fa-folder-tree text-5xl opacity-30 mb-4"></i>
                    <h3 class="text-lg font-semibold mb-2">Aucune règle d'accès</h3>
                    <p class="text-base-content/60 text-base mb-6">
                        {{ $hasFilters ? 'Aucune règle ne correspond à la recherche.' : "Aucune règle d'accès aux dossiers n'est définie." }}
                    </p>
                    <div class="text-center">
                        @if ($hasFilters)
                            <button type="button" class="btn btn-outline" wire:click="resetFilters">Effacer la recherche</button>
                        @endif
                        @can('manage-folderrule')
                            <button type="button" class="btn highlight btn-primary ml-2" wire:click="openCreate">Nouvelle règle</button>
                        @endcan
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Modale de création (réutilisable x-molecules.modal) --}}
    <x-molecules.modal wire:model="isCreateOpen" size="max-w-2xl" height="h-auto"
        title="Nouvelle règle d'accès" icon="fa-folder-tree text-primary">
        <x-molecules.modal.section title="La règle" icon="fa-circle-info text-primary" dense>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control md:col-span-2">
                    <label class="label"><span class="label-text font-medium">Libellé <span class="text-error">*</span></span></label>
                    <input type="text" wire:model="label" class="input input-bordered" placeholder="Interdire le dossier Direction aux élèves" />
                    @error('label') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="form-control md:col-span-2">
                    <label class="label">
                        <span class="label-text font-medium">
                            Chemin du dossier <span class="text-error">*</span>
                            <span class="tooltip align-middle" data-tip="Chemin Windows absolu, ex. D:\Ressources">
                                <i class="fa-solid fa-circle-info text-base-content/40 ml-0.5"></i>
                            </span>
                        </span>
                    </label>
                    <input type="text" wire:model="path" class="input input-bordered font-mono" placeholder="D:\Ressources" />
                    @error('path') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Sens <span class="text-error">*</span></span></label>
                    <select wire:model.live="sens" class="select select-bordered">
                        @foreach (self::SENS_LABELS as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Niveau <span class="text-error">*</span></span></label>
                    <select wire:model="niveau" class="select select-bordered">
                        @foreach (self::NIVEAU_LABELS as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-control md:col-span-2">
                    <label class="label"><span class="label-text font-medium">Portée <span class="text-error">*</span></span></label>
                    <select wire:model="portee" class="select select-bordered">
                        @foreach (self::PORTEE_LABELS as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-molecules.modal.section>

        <x-molecules.modal.section title="Groupe concerné" icon="fa-users text-primary" dense>
            <label class="input input-bordered flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                <input type="search" wire:model.live.debounce.300ms="groupSearch" class="grow"
                    placeholder="Rechercher un groupe (classe, groupe d'utilisateurs)..." />
                <span wire:loading wire:target="groupSearch" class="loading loading-spinner loading-xs"></span>
            </label>
            <div class="mt-2 max-h-56 overflow-y-auto rounded-lg border border-base-300 divide-y divide-base-200">
                @forelse ($this->groupCandidates() as $cand)
                    <button type="button" wire:key="grp-{{ $cand['id'] }}"
                        class="w-full text-left px-3 py-2 hover:bg-base-200 flex items-center gap-2 transition-colors {{ $userGroupId === $cand['id'] ? 'bg-primary/10' : '' }}"
                        wire:click="$set('userGroupId', {{ $cand['id'] }})">
                        <i class="fa-solid fa-users opacity-50 shrink-0 w-4 text-center"></i>
                        <span class="text-sm truncate grow">{{ $cand['label'] }}</span>
                        @if ($userGroupId === $cand['id'])
                            <i class="fa-solid fa-check text-primary shrink-0"></i>
                        @endif
                    </button>
                @empty
                    <div class="px-3 py-6 text-center text-sm text-base-content/50">
                        {{ trim($groupSearch) === '' ? 'Tapez pour rechercher un groupe.' : 'Aucun résultat.' }}
                    </div>
                @endforelse
            </div>
            @error('userGroupId') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
        </x-molecules.modal.section>

        {{-- Confirmation d'implications pour un « Interdire » (patron warning capacités). --}}
        @if ($sens === 'deny')
            <x-molecules.modal.section title="Implications" icon="fa-triangle-exclamation text-warning" dense>
                <div class="alert alert-warning text-sm">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>{{ \App\Services\Agent\FolderAccessRuleService::DENY_WARNING }}</span>
                </div>
                <label class="label cursor-pointer justify-start gap-2 mt-2">
                    <input type="checkbox" wire:model="denyAcknowledged" class="checkbox checkbox-warning checkbox-sm" />
                    <span class="label-text">J'ai compris les implications de cette règle « Interdire ».</span>
                </label>
                @error('denyAcknowledged') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
            </x-molecules.modal.section>
        @endif

        <x-slot:footerNote>
            La règle sera créée puis vous serez redirigé vers sa page pour assigner les parcs cibles (elle ne s'applique qu'une fois un parc assigné).
        </x-slot:footerNote>
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="createRule" wire:loading.attr="disabled" wire:target="createRule">
                <span wire:loading.remove wire:target="createRule"><i class="fa-solid fa-plus"></i> Créer</span>
                <span wire:loading wire:target="createRule"><span class="loading loading-spinner loading-xs"></span> Création...</span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</x-organisms.page>
