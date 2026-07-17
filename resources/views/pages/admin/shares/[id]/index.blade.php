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
    public string $description = '';
    public string $letter = '';

    // Formulaire d'ajout d'assignation.
    public string $assignType = User::class;
    public string $assignSearch = '';
    public ?int $assignTargetId = null;
    public string $assignAccess = 'ro';

    // Modale d'ajout d'assignation (recherche dynamique de la cible).
    public bool $isAssignOpen = false;

    // Édition de l'identité : header en lecture seule par défaut, formulaire à la demande.
    public bool $editingDetails = false;

    // Audit de dérive ACL (désiré SQL vs effectif disque). Rafraîchi sur mount +
    // après chaque (re)provisioning — PAS un computed live (éviterait un getfacl
    // à chaque frappe). `null` = non calculé.
    public ?array $drift = null;

    public function mount(string $id): void
    {
        abort_unless(Gate::allows('view-networkshare'), 403);
        $this->id = (int) $id;
        $this->loadShare();
        $this->refreshDrift();
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
        $this->description = (string) ($share->description ?? '');
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

    public function editDetails(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        $this->editingDetails = true;
    }

    public function cancelEditDetails(): void
    {
        $this->editingDetails = false;
        $this->resetErrorBag();
        $this->loadShare(); // restaure les valeurs d'origine si modifiées sans enregistrer
    }

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
            'description' => ['nullable', 'string', 'max:1000'],
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
        $share->description = ($validated['description'] ?? '') !== '' ? $validated['description'] : null;
        $share->save();

        $this->editingDetails = false;
        $this->reprovision('Répertoire mis à jour');
        $this->loadShare();
    }

    // --- Assignations -------------------------------------------------------

    public function openAssign(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        $this->assignType = User::class;
        $this->assignSearch = '';
        $this->assignTargetId = null;
        $this->assignAccess = NetworkShareAssignable::ACCESS_RO;
        unset($this->candidates);
        $this->isAssignOpen = true;
    }

    public function closeAssign(): void
    {
        $this->isAssignOpen = false;
        $this->assignSearch = '';
        $this->assignTargetId = null;
        unset($this->candidates);
    }

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
        $this->isAssignOpen = false;
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
        $share = $this->share;
        $name = $share?->name ?? '';

        // Déprovisionne AVANT de perdre la ligne SQL : révoque les ACL POSIX et
        // sort le dossier de l'espace exposé par le share SMB [partages] (sinon
        // un dossier « supprimé » reste atteignable en UNC avec ses grants). Le
        // contenu est archivé (mv en poubelle), pas détruit.
        $deprovisioned = $share !== null && app(NetworkShareService::class)->deprovision($share);

        // La suppression cascade le pivot (onDelete cascade, 34.1).
        NetworkShare::where('id', $this->id)->delete();

        session()->flash('toast', [
            'status' => $deprovisioned ? 'success' : 'warning',
            'title' => 'Suppression réussie',
            'message' => $deprovisioned
                ? "Le répertoire « {$name} » a été supprimé : accès révoqués et dossier archivé."
                : "Le répertoire « {$name} » a été supprimé, mais la révocation des accès serveur a échoué. Consultez les journaux.",
        ]);

        return redirect()->route('admin.shares');
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
        $this->refreshDrift();
    }

    /**
     * Recalcule l'état de dérive ACL (désiré SQL vs effectif disque). Appelé sur
     * mount et après chaque provisioning. Read-only (getfacl).
     */
    private function refreshDrift(): void
    {
        $share = $this->share?->fresh();
        $this->drift = $share === null ? null : app(NetworkShareService::class)->computeDrift($share);
    }

    /**
     * Reconvergence manuelle depuis l'UI (analogue SE5 du « ré-appliquer » du
     * legacy visuacls.php, mais idempotent : wipe + ré-application canonique).
     */
    public function resync(): void
    {
        abort_unless(Gate::allows('manage-networkshare'), 403);
        $this->reprovision('Lecteur resynchronisé');
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
        <a href="{{ route('admin.shares') }}" class="btn btn-ghost btn-sm">
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

    {{-- ===================== Header : identité du lecteur ===================== --}}
    <div class="card bg-base-100 shadow mb-6">
        <div class="card-body">
            <div class="flex items-start gap-4">
                <div class="hidden sm:flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <i class="fa-solid fa-hard-drive text-xl"></i>
                </div>
                <div class="flex-1 min-w-0">
                    @if ($editingDetails)
                        {{-- Mode édition : formulaire --}}
                        <h2 class="card-title text-base">
                            <i class="fa-solid fa-pen text-primary"></i> Modifier le lecteur
                        </h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 mt-1">
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text font-medium">Nom <span class="text-error">*</span></span>
                                </label>
                                <input type="text" wire:model="name" class="input input-bordered" />
                                @error('name') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                            </div>
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text font-medium">
                                        Nom de répertoire (FS) <span class="text-error">*</span>
                                        <span class="tooltip align-middle" data-tip="Lettres, chiffres, « . » « _ » « - » — sans espace, ne commence pas par « . ».">
                                            <i class="fa-solid fa-circle-info text-base-content/40 ml-0.5"></i>
                                        </span>
                                    </span>
                                </label>
                                <input type="text" wire:model="directoryName" class="input input-bordered font-mono" />
                                @error('directoryName') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                            </div>
                            <div class="form-control">
                                <label class="label"><span class="label-text font-medium">Libellé du lecteur</span></label>
                                <input type="text" wire:model="label" class="input input-bordered" placeholder="(par défaut : le nom)" />
                            </div>
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text font-medium">
                                        Lettre
                                        <span class="tooltip align-middle" data-tip="Laisser vide pour une attribution automatique (pool M..Z).">
                                            <i class="fa-solid fa-circle-info text-base-content/40 ml-0.5"></i>
                                        </span>
                                    </span>
                                </label>
                                <input type="text" wire:model="letter" class="input input-bordered" maxlength="8" placeholder="P:" />
                                @error('letter') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="form-control mt-1">
                            <label class="label"><span class="label-text font-medium">Description</span></label>
                            <textarea wire:model="description" rows="2" class="textarea textarea-bordered"
                                placeholder="À quoi sert ce lecteur ?"></textarea>
                            @error('description') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
                        </div>
                        <div class="card-actions justify-end mt-3 gap-2">
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelEditDetails">Annuler</button>
                            <button type="button" class="btn btn-primary btn-sm" wire:click="saveDetails">
                                <i class="fa-solid fa-floppy-disk"></i> Enregistrer
                            </button>
                        </div>
                    @else
                        {{-- Mode lecture seule (défaut) --}}
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <h2 class="card-title text-lg flex items-center gap-2 flex-wrap">
                                    <span class="truncate">{{ $name }}</span>
                                    @if ($letter !== '')
                                        <span class="badge badge-neutral badge-sm font-mono">{{ $letter }}</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">Lettre auto</span>
                                    @endif
                                </h2>
                            </div>
                            @can('manage-networkshare')
                                <button type="button" class="btn btn-ghost btn-sm shrink-0" wire:click="editDetails">
                                    <i class="fa-solid fa-pen"></i> Modifier
                                </button>
                            @endcan
                        </div>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-folder w-4 text-center opacity-50"></i>
                                <span class="font-mono">{{ $directoryName }}</span>
                                <span class="text-base-content/40 text-xs">(nom de répertoire serveur)</span>
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-tag w-4 text-center opacity-50"></i>
                                <span>{{ $label !== '' ? $label : $name }}</span>
                                <span class="text-base-content/40 text-xs">(libellé affiché côté poste)</span>
                            </div>
                            @if ($description !== '')
                                <div class="flex items-start gap-2 text-base-content/70">
                                    <i class="fa-solid fa-align-left w-4 text-center opacity-50 mt-0.5"></i>
                                    <span class="whitespace-pre-line">{{ $description }}</span>
                                </div>
                            @endif
                        </dl>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Conformité ACL (audit de dérive) ===================== --}}
    @if ($drift !== null)
        @php
            $driftMeta = match ($drift['status']) {
                'conforme' => ['alert-success', 'fa-circle-check', 'ACL disque conformes au paramétrage.'],
                'drifted' => ['alert-warning', 'fa-triangle-exclamation', 'Dérive détectée : le disque ne correspond plus au paramétrage.'],
                'absent' => ['alert-info', 'fa-folder-plus', 'Répertoire pas encore provisionné sur le serveur.'],
                default => ['alert-error', 'fa-circle-xmark', 'Impossible de lire les ACL du serveur (voir journaux).'],
            };
        @endphp
        <div class="alert {{ $driftMeta[0] }} mb-6 flex items-start justify-between gap-3">
            <div class="flex items-start gap-2 min-w-0">
                <i class="fa-solid {{ $driftMeta[1] }} mt-0.5"></i>
                <div class="min-w-0">
                    <div class="text-sm font-medium">Conformité ACL — {{ $driftMeta[2] }}</div>
                    @if ($drift['status'] === 'drifted')
                        <div class="text-xs mt-1 space-y-0.5">
                            @if (count($drift['missing']) > 0)
                                <div><span class="font-semibold">Manquant sur disque :</span>
                                    <span class="font-mono">{{ implode(', ', array_slice($drift['missing'], 0, 6)) }}</span>
                                    @if (count($drift['missing']) > 6) … @endif
                                </div>
                            @endif
                            @if (count($drift['unexpected']) > 0)
                                <div><span class="font-semibold">En trop sur disque :</span>
                                    <span class="font-mono">{{ implode(', ', array_slice($drift['unexpected'], 0, 6)) }}</span>
                                    @if (count($drift['unexpected']) > 6) … @endif
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
            @can('manage-networkshare')
                @if (in_array($drift['status'], ['drifted', 'absent', 'error'], true))
                    <button type="button" class="btn btn-sm shrink-0" wire:click="resync"
                        wire:loading.attr="disabled" wire:target="resync">
                        <span wire:loading.remove wire:target="resync"><i class="fa-solid fa-rotate"></i> Resynchroniser</span>
                        <span wire:loading wire:target="resync"><span class="loading loading-spinner loading-xs"></span> …</span>
                    </button>
                @endif
            @endcan
        </div>
    @endif

    {{-- ===================== Assignations : tableau + bouton « Ajouter » ===================== --}}
            <div class="flex items-center justify-between gap-2">
                <h2 class="card-title text-base">
                    <i class="fa-solid fa-share-nodes text-primary"></i> Assignations
                    <span class="badge badge-neutral badge-sm">{{ count($this->assignments()) }}</span>
                </h2>
                @can('manage-networkshare')
                    <button type="button" class="btn btn-primary btn-sm" wire:click="openAssign">
                        <i class="fa-solid fa-plus"></i> Ajouter
                    </button>
                @endcan
            </div>

            @if (count($this->assignments()) === 0)
                <div class="text-sm text-base-content/50 text-center py-10">
                    <i class="fa-regular fa-folder-open text-2xl mb-2 block opacity-40"></i>
                    Aucune assignation. Cliquez sur « Ajouter » pour donner accès à un utilisateur, un groupe ou un parc.
                </div>
            @else
                <div class="card bg-base-100 shadow-sm overflow-hidden border border-base-300 mt-3">
                    <div class="px-4 py-3 border-b border-base-300 text-sm text-base-content/70">
                        {{ count($this->assignments()) }} assignation(s) sur ce lecteur
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>Cible</th>
                                    <th>Type</th>
                                    <th>Accès</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->assignments() as $a)
                                    <tr wire:key="assign-{{ $a['id'] }}" class="hover:bg-sky-50">
                                        <td class="font-medium">
                                            <i class="fa-solid {{ $a['icon'] }} mr-1.5 opacity-60"></i>{{ $a['label'] }}
                                        </td>
                                        <td>
                                            <span class="badge badge-ghost badge-sm gap-1">
                                                <i class="fa-solid {{ $a['icon'] }} text-[10px]"></i>
                                                {{ $a['type_label'] }}
                                            </span>
                                        </td>
                                        <td>
                                            @if ($a['mount_only'])
                                                <span class="badge badge-ghost badge-sm" title="Parc = aucune ACL POSIX">—</span>
                                            @else
                                                @can('manage-networkshare')
                                                    <select class="select select-bordered select-xs"
                                                        wire:change="changeAccess({{ $a['id'] }}, $event.target.value)">
                                                        @foreach (\App\Models\NetworkShareAssignable::ACCESS_LABELS as $val => $label)
                                                            <option value="{{ $val }}" @selected($a['access'] === $val)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <span class="badge badge-sm {{ $a['access'] === 'rw' ? 'badge-success' : 'badge-info' }}">{{ \App\Models\NetworkShareAssignable::accessLabel($a['access']) }}</span>
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
                    </div>
                </div>
            @endif

    {{-- ===================== Modale : ajouter une assignation (recherche dynamique) ===================== --}}
    @can('manage-networkshare')
        <x-molecules.modal wire:model="isAssignOpen" size="max-w-2xl" height="h-auto"
            close-method="closeAssign"
            title="Ajouter une assignation" icon="fa-user-plus text-primary">

            <x-molecules.modal.section title="Type de cible" icon="fa-filter text-primary" dense>
                <select wire:model.live="assignType" class="select select-bordered w-full">
                    @foreach ($this->typeOptions() as $value => $lbl)
                        <option value="{{ $value }}">{{ $lbl }}</option>
                    @endforeach
                </select>
            </x-molecules.modal.section>

            @php
                $typeIcon = match ($assignType) {
                    \App\Models\User::class => 'fa-user',
                    \App\Models\UserGroup::class => 'fa-users',
                    \App\Models\WorkstationGroup::class => 'fa-layer-group',
                    default => 'fa-question',
                };
            @endphp
            <x-molecules.modal.section title="Rechercher la cible" icon="fa-magnifying-glass text-primary" dense>
                <label class="input input-bordered flex items-center gap-2">
                    <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                    <input type="search" wire:model.live.debounce.300ms="assignSearch" class="grow"
                        placeholder="Nom d'utilisateur, groupe, parc..." />
                    <span wire:loading wire:target="assignSearch" class="loading loading-spinner loading-xs"></span>
                </label>

                <div class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-base-300 divide-y divide-base-200">
                    @forelse ($this->candidates() as $cand)
                        <button type="button" wire:key="cand-{{ $cand['id'] }}"
                            class="w-full text-left px-3 py-2 hover:bg-base-200 flex items-center gap-2 transition-colors {{ $assignTargetId === $cand['id'] ? 'bg-primary/10' : '' }}"
                            wire:click="$set('assignTargetId', {{ $cand['id'] }})">
                            <i class="fa-solid {{ $typeIcon }} opacity-50 shrink-0 w-4 text-center"></i>
                            <span class="text-sm truncate grow">{{ $cand['label'] }}</span>
                            @if ($assignTargetId === $cand['id'])
                                <i class="fa-solid fa-check text-primary shrink-0"></i>
                            @endif
                        </button>
                    @empty
                        <div class="px-3 py-6 text-center text-sm text-base-content/50">
                            {{ trim($assignSearch) === '' ? 'Aucune cible disponible.' : 'Aucun résultat pour « ' . $assignSearch . ' ».' }}
                        </div>
                    @endforelse
                </div>
                <p class="text-xs text-base-content/40 mt-1">50 premiers résultats — affinez la recherche si besoin.</p>
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Niveau d'accès" icon="fa-shield-halved text-primary" dense>
                @if ($assignType === \App\Models\WorkstationGroup::class)
                    <div class="alert alert-info py-2">
                        <i class="fa-solid fa-circle-info"></i>
                        <span class="text-sm">Un parc est un <strong>montage seul</strong> (aucune ACL POSIX) : le niveau d'accès ne s'applique pas.</span>
                    </div>
                @else
                    @php
                        $accessMeta = [
                            \App\Models\NetworkShareAssignable::ACCESS_RO => ['icon' => 'fa-eye', 'desc' => 'Consultation seule.'],
                            \App\Models\NetworkShareAssignable::ACCESS_RW => ['icon' => 'fa-pen', 'desc' => 'Lecture et écriture des fichiers.'],
                        ];
                    @endphp
                    <div class="grid grid-cols-2 gap-3">
                        @foreach (\App\Models\NetworkShareAssignable::ACCESS_LABELS as $val => $label)
                            @php($meta = $accessMeta[$val])
                            <button type="button" wire:click="$set('assignAccess', '{{ $val }}')"
                                class="flex items-start gap-3 rounded-lg border-2 p-3 text-left transition-all {{ $assignAccess === $val ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-base-300 hover:border-base-content/30 hover:bg-base-200/50' }}">
                                <i class="fa-solid {{ $meta['icon'] }} text-lg mt-0.5 {{ $assignAccess === $val ? 'text-primary' : 'text-base-content/40' }}"></i>
                                <div class="min-w-0">
                                    <div class="font-medium text-sm flex items-center gap-1.5">
                                        {{ $label }}
                                        @if ($assignAccess === $val)
                                            <i class="fa-solid fa-circle-check text-primary text-xs"></i>
                                        @endif
                                    </div>
                                    <div class="text-xs text-base-content/60">{{ $meta['desc'] }}</div>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </x-molecules.modal.section>

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="closeAssign">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="addAssignment"
                    wire:loading.attr="disabled" wire:target="addAssignment" @disabled($assignTargetId === null)>
                    <span wire:loading.remove wire:target="addAssignment"><i class="fa-solid fa-plus"></i> Ajouter</span>
                    <span wire:loading wire:target="addAssignment"><span class="loading loading-spinner loading-xs"></span> Ajout...</span>
                </button>
            </x-slot:footer>
        </x-molecules.modal>
    @endcan
</x-organisms.page>
