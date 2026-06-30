<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\Filesystem\NetworkShareLetterCollisionException;
use App\Models\NetworkShare;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\NetworkShareValidator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Story 34.2 — Page liste des lecteurs réseau gérés + modale de création.
 *
 * SFC Volt (iso `pages/shortcuts/index.blade.php`). Pivot SQL pur (zéro CN AD).
 * Gardée par la policy dédiée `networkshare.*` (Q5) : la route impose
 * `can:networkshare.view`, les actions de gestion vérifient `manage-networkshare`.
 */
new #[Title('Lecteurs réseau gérés - Instance SE4FS')] class extends Component {
    use WithToasts;

    #[Url]
    public string $search = '';
    #[Url]
    public int $perPage = 20;
    #[Url]
    public int $currentPage = 1;

    public array $allowedPerPage = [10, 20, 50, 100];

    /** @var array<int, array<string, mixed>> */
    public array $shares = [];
    public int $totalShares = 0;
    public ?array $pagination = null;

    // --- Modale de création -------------------------------------------------
    public bool $isCreateOpen = false;
    public string $name = '';
    public string $directoryName = '';
    public string $label = '';
    public string $letter = '';

    public function mount(): void
    {
        // Defense-in-depth + cohérence avec la page détail (mount `view`) : ferme
        // l'éventuel vecteur d'hydratation directe du composant (finding review #5).
        abort_unless(Gate::allows('view-networkshare'), 403);
        $this->loadShares();
    }

    public function updatedSearch(): void
    {
        $this->currentPage = 1;
        $this->loadShares();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, $this->allowedPerPage, true)) {
            $this->perPage = 20;
        }
        $this->currentPage = 1;
        $this->loadShares();
    }

    public function loadShares(): void
    {
        try {
            $query = NetworkShare::query();

            if ($this->search !== '') {
                $needle = '%' . strtolower($this->search) . '%';
                $query->where(function ($q) use ($needle): void {
                    $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(directory_name) LIKE ?', [$needle]);
                });
            }

            $this->totalShares = $query->count();

            $lastPage = max(1, (int) ceil($this->totalShares / $this->perPage));
            $this->currentPage = min(max(1, $this->currentPage), $lastPage);
            $offset = ($this->currentPage - 1) * $this->perPage;

            $rows = $query
                ->withCount(['users', 'userGroups', 'workstationGroups'])
                ->withCount(['assignments as rw_count' => fn ($q) => $q->where('access', 'rw')])
                ->orderBy('name')
                ->skip($offset)
                ->take($this->perPage)
                ->get();

            $this->shares = $rows->map(fn (NetworkShare $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'directory_name' => $s->directory_name,
                'letter' => $s->letter,
                'users_count' => $s->users_count,
                'user_groups_count' => $s->user_groups_count,
                'workstation_groups_count' => $s->workstation_groups_count,
                'total_assignments' => $s->users_count + $s->user_groups_count + $s->workstation_groups_count,
                'rw_count' => $s->rw_count,
            ])->all();

            $this->pagination = [
                'current_page' => $this->currentPage,
                'per_page' => $this->perPage,
                'total' => $this->totalShares,
                'last_page' => $lastPage,
                'from' => $this->totalShares > 0 ? $offset + 1 : 0,
                'to' => min($offset + $this->perPage, $this->totalShares),
                'has_more_pages' => $this->currentPage < $lastPage,
            ];
        } catch (\Throwable $e) {
            Log::error('SharesPage loadShares error: ' . $e->getMessage());
            $this->shares = [];
            $this->pagination = null;
            $this->totalShares = 0;
        }
    }

    public function goToPage($page): void
    {
        $this->currentPage = max(1, min((int) $page, $this->pagination['last_page'] ?? 1));
        $this->loadShares();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->currentPage = 1;
        $this->loadShares();
    }

    // --- Création -----------------------------------------------------------

    public function openCreate(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $this->resetCreateForm();
        // Q2 — pré-remplir la prochaine lettre sûre libre (encourager l'explicite,
        // modifiable / effaçable → retombe sur l'auto-assignation du provider).
        $this->letter = app(NetworkShareValidator::class)->suggestNextFreeLetter() ?? '';
        $this->isCreateOpen = true;
    }

    public function close(): void
    {
        $this->isCreateOpen = false;
        $this->resetCreateForm();
    }

    private function resetCreateForm(): void
    {
        $this->name = '';
        $this->directoryName = '';
        $this->label = '';
        $this->letter = '';
        $this->resetErrorBag();
    }

    protected function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'directoryName' => [
                'required',
                'string',
                'max:255',
                'regex:' . NetworkShareService::DIRECTORY_NAME_PATTERN,
                'unique:network_shares,directory_name',
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'letter' => [
                'nullable',
                'string',
                'max:8',
                function (string $attribute, $value, $fail): void {
                    if (app(NetworkShareValidator::class)->isReservedLetter($value)) {
                        $fail('Cette lettre est réservée par le système (A-D, H, I, K, L). '
                            . 'Choisissez une autre lettre ou laissez le champ vide (attribution automatique).');
                    }
                },
            ],
        ];
    }

    protected function createMessages(): array
    {
        return [
            'name.required' => 'Le nom est requis.',
            'directoryName.required' => 'Le nom de répertoire est requis.',
            'directoryName.regex' => 'Le nom de répertoire ne peut contenir que des lettres, chiffres, '
                . '« . », « _ » et « - » (sans espace), et ne peut pas commencer par « . ».',
            'directoryName.unique' => 'Ce nom de répertoire est déjà utilisé.',
        ];
    }

    public function createShare(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $validated = $this->validate($this->createRules(), $this->createMessages());

        $share = new NetworkShare([
            'name' => $validated['name'],
            'directory_name' => $validated['directoryName'],
            'label' => $validated['label'] !== '' ? $validated['label'] : null,
            'letter' => $this->normalizedLetter($validated['letter'] ?? null),
            'created_by_user_id' => $this->currentUserId(),
        ]);

        // Validation prédictive (defense-in-depth) — un répertoire neuf n'a pas
        // encore d'audience, donc pas de collision possible ici, mais on garde
        // l'appel pour homogénéité avec le chemin d'édition.
        try {
            app(NetworkShareValidator::class)->assertNoLetterCollision($share);
        } catch (NetworkShareLetterCollisionException $e) {
            $this->toastError($e->getMessage());
            return;
        }

        $share->save();

        $ok = app(NetworkShareService::class)->provision($share);
        if ($ok) {
            $this->toastSuccess("Le répertoire « {$share->name} » a été créé et provisionné.");
        } else {
            $this->toastWarning("Le répertoire « {$share->name} » a été créé, mais son provisioning a échoué. "
                . 'Consultez les journaux serveur.');
        }

        $this->isCreateOpen = false;
        $this->resetCreateForm();
        $this->loadShares();
    }

    private function normalizedLetter(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $bare = strtoupper($trimmed[0]);

        return $bare . ':';
    }

    private function currentUserId(): ?int
    {
        $user = auth()->user();
        return ($user instanceof \App\Models\User) ? $user->id : null;
    }
}; ?>

@php
    $hasFilters = $search !== '';
@endphp

<x-organisms.page title="Lecteurs réseau gérés" :scrollable="false"
    description="Créez et assignez des répertoires réseau (lecteurs) par utilisateur, groupe ou parc">

    <x-slot:actions>
        @can('manage-networkshare')
            <button type="button" class="btn highlight btn-primary" wire:click="openCreate">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Nouveau répertoire
            </button>
        @endcan
    </x-slot:actions>

    <div class="flex flex-col h-full">
        <div class="space-y-3">
            <div class="flex-1 min-w-48">
                <x-atoms.searchInput wire:model.live.debounce.500ms="search" id="searchInput"
                    placeholder="Rechercher (par nom, répertoire...)" icon="fa-magnifying-glass" class="w-full" />
            </div>
        </div>

        @if (count($shares) > 0)
            <div class="flex justify-between items-center my-4">
                <span class="text-base-content/70">{{ $totalShares }} répertoire(s) trouvé(s)</span>
            </div>

            <x-organisms.data-table
                colgroup="<colgroup><col style='width: 28%'><col style='width: 22%'><col style='width: 10%'><col style='width: auto'><col style='width: 12%'></colgroup>">
                <x-slot:header>
                    <th>Nom</th>
                    <th>Répertoire</th>
                    <th>Lettre</th>
                    <th>Assignations</th>
                    <th>Accès</th>
                </x-slot:header>
                @foreach ($shares as $share)
                    <tr class="hover:bg-sky-50 cursor-pointer"
                        onclick="window.location.href='{{ route('app.shares.show', $share['id']) }}'">
                        <td class="font-bold">{{ $share['name'] }}</td>
                        <td><span class="font-mono text-sm">{{ $share['directory_name'] }}</span></td>
                        <td>
                            @if ($share['letter'])
                                <span class="badge badge-primary">{{ $share['letter'] }}</span>
                            @else
                                <span class="badge badge-ghost" title="Lettre attribuée automatiquement">auto</span>
                            @endif
                        </td>
                        <td>
                            @if ($share['total_assignments'] === 0)
                                <span class="text-sm text-base-content/40">Aucune</span>
                            @else
                                <div class="flex flex-wrap gap-1 text-xs">
                                    @if ($share['users_count'] > 0)
                                        <span class="badge badge-sm badge-accent">
                                            <i class="fa-solid fa-user text-xs mr-1"></i>{{ $share['users_count'] }}
                                        </span>
                                    @endif
                                    @if ($share['user_groups_count'] > 0)
                                        <span class="badge badge-sm badge-secondary">
                                            <i class="fa-solid fa-users text-xs mr-1"></i>{{ $share['user_groups_count'] }}
                                        </span>
                                    @endif
                                    @if ($share['workstation_groups_count'] > 0)
                                        <span class="badge badge-sm badge-warning" title="Parc — montage seul">
                                            <i class="fa-solid fa-layer-group text-xs mr-1"></i>{{ $share['workstation_groups_count'] }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td>
                            @if ($share['total_assignments'] === 0)
                                <span class="text-sm text-base-content/40">—</span>
                            @elseif ($share['rw_count'] > 0)
                                <span class="badge badge-sm badge-success">Lecture/écriture</span>
                            @else
                                <span class="badge badge-sm badge-info">Lecture seule</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-organisms.data-table>

            @if ($pagination)
                <x-molecules.pagination :currentPage="$pagination['current_page']" :lastPage="$pagination['last_page']" :total="$pagination['total']" :from="$pagination['from']"
                    :to="$pagination['to']" :perPage="$perPage" :allowedPerPage="$allowedPerPage" onPageChange="goToPage"
                    perPageModel="perPage" itemLabel="répertoire" itemLabelPlural="répertoires" />
            @endif
        @else
            <div class="card flex-1 flex flex-col justify-center items-center mt-8">
                <div class="card-body flex-col justify-center items-center flex-0 py-16">
                    <i class="fa-solid fa-network-wired text-5xl opacity-30 mb-4"></i>
                    <h3 class="text-lg font-semibold mb-2">Aucun lecteur réseau</h3>
                    <p class="text-base-content/60 text-base mb-6">
                        {{ $hasFilters ? 'Aucun répertoire ne correspond à la recherche.' : "Aucun répertoire réseau n'est défini." }}
                    </p>
                    <div class="text-center">
                        @if ($hasFilters)
                            <button type="button" class="btn btn-outline" wire:click="resetFilters">Effacer la recherche</button>
                        @endif
                        @can('manage-networkshare')
                            <button type="button" class="btn highlight btn-primary ml-2" wire:click="openCreate">Nouveau répertoire</button>
                        @endcan
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Modale de création (réutilisable x-molecules.modal) --}}
    <x-molecules.modal wire:model="isCreateOpen" size="max-w-2xl" height="h-auto"
        title="Nouveau répertoire réseau" icon="fa-network-wired text-primary">
        <x-molecules.modal.section title="Informations" icon="fa-circle-info text-primary" dense>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Nom <span class="text-error">*</span></span></label>
                    <input type="text" wire:model="name" class="input input-bordered" placeholder="Échange Direction" />
                    @error('name') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Nom de répertoire (FS) <span class="text-error">*</span></span></label>
                    <input type="text" wire:model="directoryName" class="input input-bordered font-mono" placeholder="echange_direction" />
                    @error('directoryName') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Libellé du lecteur</span></label>
                    <input type="text" wire:model="label" class="input input-bordered" placeholder="(par défaut : le nom)" />
                    @error('label') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="form-control">
                    <label class="label">
                        <span class="label-text font-medium">Lettre</span>
                        <span class="label-text-alt text-base-content/50">vide = auto</span>
                    </label>
                    <input type="text" wire:model="letter" class="input input-bordered" maxlength="8" placeholder="P:" />
                    @error('letter') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-molecules.modal.section>

        <x-slot:footerNote>
            Le répertoire sera créé puis provisionné (FS + ACL). Les assignations (utilisateurs, groupes, parcs) se gèrent ensuite sur la page du répertoire.
        </x-slot:footerNote>
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="createShare" wire:loading.attr="disabled" wire:target="createShare">
                <span wire:loading.remove wire:target="createShare"><i class="fa-solid fa-plus"></i> Créer</span>
                <span wire:loading wire:target="createShare"><span class="loading loading-spinner loading-xs"></span> Création...</span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</x-organisms.page>
