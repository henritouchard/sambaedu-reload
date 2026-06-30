<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\Filesystem\NetworkShareLetterCollisionException;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\NetworkShareValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 34.2 — Détail d'un lecteur réseau géré : édition des champs + assignation
 * par maille (pivot SQL pur) + suppression. Validation prédictive (collision de
 * lettre, WG-montage-seul) calquée sur 30.5.
 */
new #[Title('Lecteur réseau - Instance SE4FS')] class extends Component {
    use WithToasts;

    public int $id;
    public ?NetworkShare $share = null;

    // Édition des champs.
    public string $name = '';
    public string $directoryName = '';
    public string $label = '';
    public string $letter = '';

    // Formulaire d'ajout d'assignation.
    public string $assignType = User::class;
    public string $assignSearch = '';
    public ?int $assignTargetId = null;
    public string $assignAccess = 'ro';

    public function mount(string $id): void
    {
        abort_unless(Gate::allows('view-networkshare'), 403);
        $this->id = (int) $id;
        $this->loadShare();
    }

    public function loadShare(): void
    {
        $share = NetworkShare::find($this->id);
        if ($share === null) {
            abort(404);
        }
        $this->share = $share;
        $this->name = (string) $share->name;
        $this->directoryName = (string) $share->directory_name;
        $this->label = (string) ($share->label ?? '');
        $this->letter = (string) ($share->letter ?? '');
    }

    // --- Assignations actuelles --------------------------------------------

    #[Computed]
    public function assignments(): array
    {
        $rows = NetworkShareAssignable::where('network_share_id', $this->id)
            ->orderBy('assignable_type')
            ->get();

        return $rows->map(function (NetworkShareAssignable $a): array {
            [$label, $typeLabel, $icon, $mountOnly] = $this->describeAssignable($a->assignable_type, (int) $a->assignable_id);

            return [
                'id' => $a->id,
                'type' => $a->assignable_type,
                'assignable_id' => $a->assignable_id,
                'access' => $a->access,
                'label' => $label,
                'type_label' => $typeLabel,
                'icon' => $icon,
                'mount_only' => $mountOnly,
            ];
        })->all();
    }

    /**
     * @return array{0:string,1:string,2:string,3:bool}  [label, typeLabel, icon, mountOnly]
     */
    private function describeAssignable(string $type, int $id): array
    {
        return match ($type) {
            User::class => [
                (string) (User::find($id)?->login ?? "#{$id}"),
                'Utilisateur', 'fa-user', false,
            ],
            UserGroup::class => [
                (string) (UserGroup::find($id)?->getDisplayNameOrNameAttribute() ?? "#{$id}"),
                "Groupe d'utilisateurs", 'fa-users', false,
            ],
            WorkstationGroup::class => (function () use ($id): array {
                $wg = WorkstationGroup::find($id);

                return [
                    (string) ($wg?->display_name ?: ($wg?->name ?? "#{$id}")),
                    'Parc (montage seul)', 'fa-layer-group', true,
                ];
            })(),
            default => ["#{$id}", 'Inconnu', 'fa-question', false],
        };
    }

    /** Avertissements prédictifs (WG-montage-seul). */
    #[Computed]
    public function warnings(): array
    {
        if ($this->share === null) {
            return [];
        }
        return app(NetworkShareValidator::class)->warnings($this->share);
    }

    // --- Pickers SQL (zéro CN AD) ------------------------------------------

    #[Computed]
    public function candidates(): array
    {
        $assignedIds = collect($this->assignments)
            ->where('type', $this->assignType)
            ->pluck('assignable_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $needle = '%' . strtolower(trim($this->assignSearch)) . '%';

        return match ($this->assignType) {
            User::class => User::query()
                ->when($this->assignSearch !== '', fn ($q) => $q->whereRaw('LOWER(login) LIKE ?', [$needle]))
                ->whereNotIn('id', $assignedIds)
                ->orderBy('login')
                ->limit(50)
                ->get(['id', 'login'])
                ->map(fn (User $u): array => ['id' => $u->id, 'label' => $u->login])
                ->all(),
            UserGroup::class => UserGroup::query()
                ->when($this->assignSearch !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$needle]))
                ->whereNotIn('id', $assignedIds)
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'display_name'])
                ->map(fn (UserGroup $g): array => ['id' => $g->id, 'label' => $g->display_name ?: $g->name])
                ->all(),
            WorkstationGroup::class => WorkstationGroup::query()
                ->when($this->assignSearch !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$needle]))
                ->whereNotIn('id', $assignedIds)
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'display_name'])
                ->map(fn (WorkstationGroup $w): array => ['id' => $w->id, 'label' => $w->display_name ?: $w->name])
                ->all(),
            default => [],
        };
    }

    public function updatedAssignType(): void
    {
        $this->assignTargetId = null;
        $this->assignSearch = '';
    }

    // --- Édition des champs -------------------------------------------------

    public function saveDetails(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $validator = app(NetworkShareValidator::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'directoryName' => [
                'required', 'string', 'max:255',
                'regex:' . NetworkShareService::DIRECTORY_NAME_PATTERN,
                'unique:network_shares,directory_name,' . $this->id,
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'letter' => [
                'nullable', 'string', 'max:8',
                function (string $attr, $value, $fail) use ($validator): void {
                    if ($validator->isReservedLetter($value)) {
                        $fail('Cette lettre est réservée par le système (A-D, H, I, K, L).');
                    }
                },
            ],
        ], [
            'directoryName.regex' => 'Le nom de répertoire ne peut contenir que des lettres, chiffres, '
                . '« . », « _ », « - » (sans espace) et ne peut pas commencer par « . ».',
            'directoryName.unique' => 'Ce nom de répertoire est déjà utilisé.',
        ]);

        $share = $this->share;
        $newLetter = $this->normalizedLetter($validated['letter'] ?? null);

        // Validation prédictive AVANT écriture (collision de lettre).
        $share->letter = $newLetter;
        try {
            $validator->assertNoLetterCollision($share);
        } catch (NetworkShareLetterCollisionException $e) {
            $share->letter = $share->getOriginal('letter'); // revert en mémoire
            $this->toastError($e->getMessage());
            return;
        }

        $share->name = $validated['name'];
        $share->directory_name = $validated['directoryName'];
        $share->label = $validated['label'] !== '' ? $validated['label'] : null;
        $share->save();

        $this->reprovision('Répertoire mis à jour');
        $this->loadShare();
    }

    // --- Assignations -------------------------------------------------------

    public function addAssignment(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);

        $validator = app(NetworkShareValidator::class);
        if (! $validator->isAllowedAssignableType($this->assignType)) {
            $this->toastError("Type d'assignation non autorisé.");
            return;
        }
        if ($this->assignTargetId === null) {
            $this->toastWarning('Sélectionnez une cible à assigner.');
            return;
        }
        if (! in_array($this->assignAccess, ['ro', 'rw'], true)) {
            $this->assignAccess = 'ro';
        }

        // Vérifie l'existence réelle de la cible (intégrité — pas de FK polymorphe).
        if (! $this->targetExists($this->assignType, $this->assignTargetId)) {
            $this->toastError('Cible introuvable.');
            return;
        }

        // L'ajout d'une cible ÉLARGIT l'audience : ce répertoire peut entrer en
        // collision de lettre avec un AUTRE répertoire de même lettre explicite
        // dont l'audience recouvre désormais la cible ajoutée (piège #3, finding
        // review #1 — `saveDetails`/`createShare` validaient déjà, `addAssignment`
        // était le vecteur non couvert). On valide DANS la transaction : si
        // collision, l'assignation est rollback (aucune écriture partielle).
        try {
            DB::transaction(function (): void {
                NetworkShareAssignable::updateOrCreate(
                    [
                        'network_share_id' => $this->id,
                        'assignable_type' => $this->assignType,
                        'assignable_id' => $this->assignTargetId,
                    ],
                    ['access' => $this->assignAccess],
                );

                app(NetworkShareValidator::class)->assertNoLetterCollision($this->share);
            });
        } catch (NetworkShareLetterCollisionException $e) {
            $this->toastError($e->getMessage());
            return;
        }

        $this->assignTargetId = null;
        $this->assignSearch = '';
        unset($this->assignments, $this->candidates, $this->warnings);

        $this->reprovision('Assignation ajoutée');
        $this->surfaceWarnings();
    }

    public function changeAccess(int $assignmentId, string $access): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        if (! in_array($access, ['ro', 'rw'], true)) {
            return;
        }
        $row = NetworkShareAssignable::where('network_share_id', $this->id)->find($assignmentId);
        if ($row === null) {
            return;
        }
        $row->access = $access;
        $row->save();
        unset($this->assignments);
        $this->reprovision('Accès mis à jour');
    }

    public function removeAssignment(int $assignmentId): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        $row = NetworkShareAssignable::where('network_share_id', $this->id)->find($assignmentId);
        if ($row === null) {
            return;
        }
        $row->delete();
        unset($this->assignments, $this->candidates, $this->warnings);
        $this->reprovision('Assignation retirée');
        $this->surfaceWarnings();
    }

    // --- Suppression --------------------------------------------------------

    public function deleteShare()
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        $name = $this->share?->name ?? '';
        // La suppression cascade le pivot (onDelete cascade, 34.1). Le dossier FS
        // sous /var/sambaedu/Partages N'EST PAS supprimé (archivage = 34.x).
        NetworkShare::where('id', $this->id)->delete();

        session()->flash('toast', [
            'status' => 'success',
            'title' => 'Suppression réussie',
            'message' => "Le répertoire « {$name} » a été supprimé (le dossier serveur est conservé).",
        ]);

        return redirect()->route('app.shares');
    }

    // --- Helpers ------------------------------------------------------------

    private function reprovision(string $okPrefix): void
    {
        $share = $this->share?->fresh();
        if ($share === null) {
            return;
        }
        $ok = app(NetworkShareService::class)->provision($share);
        if ($ok) {
            $this->toastSuccess($okPrefix . ' et provisionné.');
        } else {
            $this->toastWarning($okPrefix . ", mais le provisioning a échoué. Consultez les journaux serveur.");
        }
    }

    private function surfaceWarnings(): void
    {
        foreach ($this->warnings() as $warning) {
            $this->toastWarning($warning);
        }
    }

    private function targetExists(string $type, int $id): bool
    {
        return match ($type) {
            User::class => User::whereKey($id)->exists(),
            UserGroup::class => UserGroup::whereKey($id)->exists(),
            WorkstationGroup::class => WorkstationGroup::whereKey($id)->exists(),
            default => false,
        };
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
        return strtoupper($trimmed[0]) . ':';
    }

    public function typeOptions(): array
    {
        return [
            User::class => 'Utilisateur',
            UserGroup::class => "Groupe d'utilisateurs",
            WorkstationGroup::class => 'Parc (montage seul)',
        ];
    }
}; ?>

<x-organisms.page title="Lecteur réseau géré" :scrollable="true">
    <x-slot:actions>
        <a href="{{ route('app.shares') }}" class="btn btn-ghost btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Retour
        </a>
        @can('manage-networkshare')
            <button type="button" class="btn btn-error btn-sm" wire:click="deleteShare"
                wire:confirm="Supprimer ce répertoire ? Les assignations seront retirées (le dossier serveur est conservé).">
                <i class="fa-regular fa-trash-can"></i> Supprimer
            </button>
        @endcan
    </x-slot:actions>

    @if (count($this->warnings()) > 0)
        @foreach ($this->warnings() as $warning)
            <div class="alert alert-warning mb-4">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span class="text-sm">{{ $warning }}</span>
            </div>
        @endforeach
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Édition des champs --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h2 class="card-title text-base"><i class="fa-solid fa-circle-info text-primary"></i> Informations</h2>
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Nom</span></label>
                    <input type="text" wire:model="name" class="input input-bordered" @cannot('manage-networkshare') disabled @endcannot />
                    @error('name') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Nom de répertoire (FS)</span></label>
                    <input type="text" wire:model="directoryName" class="input input-bordered font-mono" @cannot('manage-networkshare') disabled @endcannot />
                    @error('directoryName') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text font-medium">Libellé du lecteur</span></label>
                    <input type="text" wire:model="label" class="input input-bordered" placeholder="(par défaut : le nom)" @cannot('manage-networkshare') disabled @endcannot />
                </div>
                <div class="form-control">
                    <label class="label">
                        <span class="label-text font-medium">Lettre</span>
                        <span class="label-text-alt text-base-content/50">vide = auto</span>
                    </label>
                    <input type="text" wire:model="letter" class="input input-bordered" maxlength="8" placeholder="P:" @cannot('manage-networkshare') disabled @endcannot />
                    @error('letter') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                @can('manage-networkshare')
                    <div class="card-actions justify-end mt-3">
                        <button type="button" class="btn btn-primary btn-sm" wire:click="saveDetails">
                            <i class="fa-solid fa-floppy-disk"></i> Enregistrer
                        </button>
                    </div>
                @endcan
            </div>
        </div>

        {{-- Assignations --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h2 class="card-title text-base">
                    <i class="fa-solid fa-share-nodes text-primary"></i> Assignations
                    <span class="badge badge-neutral badge-sm">{{ count($this->assignments()) }}</span>
                </h2>

                @can('manage-networkshare')
                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-end p-3 bg-base-200 rounded-lg">
                        <div class="form-control sm:col-span-4">
                            <label class="label py-1"><span class="label-text text-xs">Type</span></label>
                            <select wire:model.live="assignType" class="select select-bordered select-sm">
                                @foreach ($this->typeOptions() as $value => $lbl)
                                    <option value="{{ $value }}">{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control sm:col-span-5">
                            <label class="label py-1"><span class="label-text text-xs">Cible</span></label>
                            <input type="text" wire:model.live.debounce.300ms="assignSearch" class="input input-bordered input-sm mb-1" placeholder="Rechercher..." />
                            <select wire:model="assignTargetId" class="select select-bordered select-sm">
                                <option value="">— choisir —</option>
                                @foreach ($this->candidates() as $cand)
                                    <option value="{{ $cand['id'] }}">{{ $cand['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control sm:col-span-2">
                            <label class="label py-1"><span class="label-text text-xs">Accès</span></label>
                            <select wire:model="assignAccess" class="select select-bordered select-sm"
                                @if($assignType === \App\Models\WorkstationGroup::class) disabled title="Parc = montage seul" @endif>
                                <option value="ro">RO</option>
                                <option value="rw">RW</option>
                            </select>
                        </div>
                        <div class="sm:col-span-1">
                            <button type="button" class="btn btn-primary btn-sm w-full" wire:click="addAssignment" title="Ajouter">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                        </div>
                    </div>
                @endcan

                <div class="mt-3">
                    @if (count($this->assignments()) === 0)
                        <div class="text-sm text-base-content/50 text-center py-6">Aucune assignation.</div>
                    @else
                        <table class="table table-sm">
                            <thead>
                                <tr class="text-xs uppercase"><th>Cible</th><th>Type</th><th>Accès</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($this->assignments() as $a)
                                    <tr wire:key="assign-{{ $a['id'] }}">
                                        <td class="font-medium">
                                            <i class="fa-solid {{ $a['icon'] }} mr-1 opacity-60"></i>{{ $a['label'] }}
                                        </td>
                                        <td class="text-xs text-base-content/60">{{ $a['type_label'] }}</td>
                                        <td>
                                            @if ($a['mount_only'])
                                                <span class="badge badge-ghost badge-sm" title="Parc = aucune ACL POSIX">—</span>
                                            @else
                                                @can('manage-networkshare')
                                                    <select class="select select-bordered select-xs"
                                                        wire:change="changeAccess({{ $a['id'] }}, $event.target.value)">
                                                        <option value="ro" @selected($a['access'] === 'ro')>RO</option>
                                                        <option value="rw" @selected($a['access'] === 'rw')>RW</option>
                                                    </select>
                                                @else
                                                    <span class="badge badge-sm {{ $a['access'] === 'rw' ? 'badge-success' : 'badge-info' }}">{{ strtoupper($a['access']) }}</span>
                                                @endcan
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @can('manage-networkshare')
                                                <button type="button" class="btn btn-ghost btn-xs text-error"
                                                    wire:click="removeAssignment({{ $a['id'] }})"
                                                    wire:confirm="Retirer cette assignation ?">
                                                    <i class="fa-regular fa-trash-can"></i>
                                                </button>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-organisms.page>
