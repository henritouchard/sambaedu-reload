<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use App\Components\Traits\WithToasts;
use App\Enums\SambaPermission;
use App\Models\User as EloquentUser;
use App\Models\WorkstationGroup;
use App\Services\PermissionService;
use App\Services\UserService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

new class extends Component {
    use WithToasts;

    public bool $isOpen = false;

    /** Logins AD des users cibles. */
    public array $selectedUsers = [];

    public ?int $selectedWorkstationGroupId = null;
    public ?string $selectedDelegationPermission = null;

    /** null = Auto ; sinon force l'action. Valeurs : grant/revoke/negate/lift_negative. */
    public ?string $forcedAction = null;
    /** Toggle UI pour afficher le date-time picker. Décoché → expiresAt null. */
    public bool $hasExpiration = false;
    /** ISO date ou datetime (appliqué aux actions `grant` et `negate`). */
    public ?string $delegationExpiresAt = null;

    public string $workstationGroupSearch = '';

    public array $availableWorkstationGroups = [];
    public array $delegatablePermissions = [];

    public bool $processing = false;

    public function mount(): void
    {
        $this->loadAvailableData();
    }

    private function loadAvailableData(): void
    {
        $this->availableWorkstationGroups = WorkstationGroup::physical()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(
                fn(WorkstationGroup $wg) => [
                    'id' => $wg->id,
                    'name' => $wg->name,
                    'display_name' => $wg->display_name ?? $wg->name,
                ],
            )
            ->toArray();

        $this->delegatablePermissions = collect(SambaPermission::cases())
            ->filter(fn(SambaPermission $p) => in_array($p->category(), ['computer', 'wpkg']))
            ->values()
            ->map(
                fn(SambaPermission $p) => [
                    'name' => $p->value,
                    'label' => $p->label(),
                    'requires_gpo' => $p->requiresGpoSync(),
                ],
            )
            ->toArray();
    }

    /**
     * Ouvre la modale. Story 7.2 — accepte optionnellement un triplet
     * (workstationGroupId, permission, expiresAt) pour pré-remplir la modale
     * en mode "édition" d'une délégation existante (clic ligne dans le tableau
     * Délégations actives de /app/rights-management).
     */
    #[On('open-delegation-modal')]
    public function open(
        array $users = [],
        ?int $workstationGroupId = null,
        ?string $permission = null,
        ?string $expiresAt = null,
    ): void
    {
        abort_unless(Gate::allows('user.assign.right'), 403);

        $this->selectedUsers = $users;
        $this->isOpen = true;
        $this->resetForm();
        $this->loadAvailableData();

        if ($workstationGroupId !== null) {
            $this->selectedWorkstationGroupId = $workstationGroupId;
        }
        if ($permission !== null) {
            $this->selectedDelegationPermission = $permission;
        }
        if ($expiresAt !== null) {
            $this->hasExpiration = true;
            $this->delegationExpiresAt = $expiresAt;
        }
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->selectedWorkstationGroupId = null;
        $this->selectedDelegationPermission = null;
        $this->forcedAction = null;
        $this->hasExpiration = false;
        $this->delegationExpiresAt = null;
        $this->workstationGroupSearch = '';
        $this->processing = false;
    }

    /** Toggle décoché → on vide la date pour ne pas persister de valeur fantôme. */
    public function updatedHasExpiration(bool $value): void
    {
        if (!$value) {
            $this->delegationExpiresAt = null;
        }
    }

    #[Computed]
    public function filteredWorkstationGroups(): array
    {
        if (empty($this->workstationGroupSearch)) {
            return $this->availableWorkstationGroups;
        }
        $search = strtolower($this->workstationGroupSearch);
        return array_values(
            array_filter($this->availableWorkstationGroups, static function (array $wg) use ($search): bool {
                $name = strtolower((string) ($wg['name'] ?? ''));
                $display = strtolower((string) ($wg['display_name'] ?? ''));
                return str_contains($name, $search) || str_contains($display, $search);
            }),
        );
    }

    /**
     * Résumés d'état par user. Tant que salle/permission ne sont pas toutes
     * deux choisies, retourne un stub avec labels "—" pour afficher la liste
     * des users immédiatement (pré-sélection visible avant action).
     *
     * @return array<string, array{login:string,source:string,state:string,action_suggested:string,source_label:string,state_label:string,action_label:string,exists_in_sql:bool}>
     */
    #[Computed]
    public function userSummaries(): array
    {
        $group = null;
        if ($this->selectedWorkstationGroupId !== null) {
            $found = WorkstationGroup::find($this->selectedWorkstationGroupId);
            if ($found && $found->is_physical) {
                $group = $found;
            }
        }

        $canCompute = $group !== null && $this->selectedDelegationPermission !== null;
        $service = $canCompute ? app(PermissionService::class) : null;
        $summaries = [];

        foreach ($this->selectedUsers as $login) {
            $user = EloquentUser::where('login', $login)->first();
            $existsInSql = $user !== null;

            if (!$canCompute) {
                $summaries[$login] = [
                    'login' => $login,
                    'source' => 'pending',
                    'state' => 'pending',
                    'action_suggested' => 'pending',
                    'source_label' => '—',
                    'state_label' => '—',
                    'action_label' => '—',
                    'exists_in_sql' => $existsInSql,
                ];
                continue;
            }

            if (!$user) {
                $summaries[$login] = [
                    'login' => $login,
                    'source' => 'none',
                    'state' => 'none',
                    'action_suggested' => 'grant',
                    'source_label' => 'Aucun droit',
                    'state_label' => 'Aucun',
                    'action_label' => 'Accorder',
                    'exists_in_sql' => false,
                ];
                continue;
            }

            $summary = $service->getEffectiveAccessSummary($user, $this->selectedDelegationPermission, $group);
            $summaries[$login] = array_merge($summary, [
                'login' => $login,
                'source_label' => $this->sourceLabel($summary['source']),
                'state_label' => $this->stateLabel($summary['state']),
                'action_label' => $this->actionLabel($summary['action_suggested']),
                'exists_in_sql' => true,
            ]);
        }

        return $summaries;
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'delegation_negative' => 'Exclusion scopée',
            'delegation_positive' => 'Délégation sur cette salle',
            'global' => 'Permission globale directe',
            'role' => 'Permission via rôle',
            default => 'Aucun droit',
        };
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'granted' => 'Autorisé',
            'denied' => 'Bloqué',
            default => 'Aucun',
        };
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'grant' => 'Accorder',
            'revoke' => 'Révoquer',
            'negate' => 'Exclure',
            'lift_negative' => "Lever l'exclusion",
            default => '—',
        };
    }

    public function applyDelegationActions(): void
    {
        abort_unless(Gate::allows('user.assign.right'), 403);

        if (empty($this->selectedUsers)) {
            $this->toastError('Aucun utilisateur sélectionné.');
            return;
        }
        if ($this->selectedWorkstationGroupId === null) {
            $this->toastError('Veuillez sélectionner une salle.');
            return;
        }
        if ($this->selectedDelegationPermission === null) {
            $this->toastError('Veuillez sélectionner une permission.');
            return;
        }

        $this->processing = true;
        $service = app(PermissionService::class);
        $group = WorkstationGroup::find($this->selectedWorkstationGroupId);

        if (!$group || !$group->is_physical) {
            $this->toastError('Salle invalide ou non physique.');
            $this->processing = false;
            return;
        }

        // Résolution du granter (utilisateur connecté, compat legacy AuthUser).
        $granter = null;
        $authUser = auth()->user();
        if ($authUser) {
            if ($authUser instanceof EloquentUser) {
                $granter = $authUser;
            } else {
                $granter = EloquentUser::where('login', $authUser->getAuthIdentifier())->first();
            }
        }

        $expiresAt = null;
        if ($this->delegationExpiresAt) {
            try {
                $expiresAt = new \DateTimeImmutable($this->delegationExpiresAt);
            } catch (\Exception $e) {
                $this->toastError("Date d'expiration invalide.");
                $this->processing = false;
                return;
            }
        }

        $permName = $this->selectedDelegationPermission;
        $forced = $this->forcedAction;
        $counts = ['grant' => 0, 'revoke' => 0, 'negate' => 0, 'lift_negative' => 0, 'noop' => 0];
        $errors = 0;
        $anyAuditFailed = false;

        foreach ($this->selectedUsers as $login) {
            try {
                $user = $this->ensureEloquentUser($login);
                if (!$user) {
                    $errors++;
                    continue;
                }

                $action = $forced ?? $service->getEffectiveAccessSummary($user, $permName, $group)['action_suggested'];

                switch ($action) {
                    case 'grant':
                        $service->grantDelegation($user, $permName, $group, $granter, $expiresAt);
                        $counts['grant']++;
                        break;
                    case 'revoke':
                        $deleted = $service->revokeDelegation($user, $permName, $group, $granter);
                        $counts[$deleted ? 'revoke' : 'noop']++;
                        break;
                    case 'negate':
                        $service->negateDelegation($user, $permName, $group, $granter, $expiresAt);
                        $counts['negate']++;
                        break;
                    case 'lift_negative':
                        $deleted = $service->revokeNegativeDelegation($user, $permName, $group, $granter);
                        $counts[$deleted ? 'lift_negative' : 'noop']++;
                        break;
                    default:
                        $counts['noop']++;
                }

                if ($service->lastAuditFailed) {
                    $anyAuditFailed = true;
                }
            } catch (\Exception $e) {
                Log::error("[DelegationModal] Erreur pour {$login}: " . $e->getMessage());
                $errors++;
            }
        }

        $parts = [];
        if ($counts['grant'] > 0) {
            $parts[] = "{$counts['grant']} accordée(s)";
        }
        if ($counts['revoke'] > 0) {
            $parts[] = "{$counts['revoke']} révoquée(s)";
        }
        if ($counts['negate'] > 0) {
            $parts[] = "{$counts['negate']} exclusion(s) créée(s)";
        }
        if ($counts['lift_negative'] > 0) {
            $parts[] = "{$counts['lift_negative']} exclusion(s) levée(s)";
        }
        if ($counts['noop'] > 0) {
            $parts[] = "{$counts['noop']} sans effet";
        }

        $summary = empty($parts) ? "Aucune modification appliquée sur '{$group->name}'." : "Sur '{$group->name}' ({$permName}) : " . implode(', ', $parts) . '.';

        if ($errors > 0) {
            $summary .= " ({$errors} erreur(s))";
            $this->toastWarning($summary);
        } else {
            $this->toastSuccess($summary);
        }

        if ($anyAuditFailed) {
            $this->toastWarning("Action(s) appliquée(s) mais la traçabilité n'a pas été enregistrée pour une ou plusieurs opérations. Contactez l'administrateur.");
        }

        // Story 7.2 — notifier les pages parentes (rights-management) pour
        // qu'elles puissent rafraîchir leur tableau de délégations actives.
        $this->dispatch('delegations-changed');

        $this->processing = false;
    }

    /**
     * Assure qu'un EloquentUser existe pour un login AD.
     * Crée le user minimal seulement si le login existe vraiment dans l'annuaire.
     */
    private function ensureEloquentUser(string $login): ?EloquentUser
    {
        $user = EloquentUser::where('login', $login)->first();
        if ($user) {
            return $user;
        }

        try {
            $adUser = app(UserService::class)->getByLogin($login);
        } catch (\Throwable $e) {
            Log::warning("[DelegationModal] Échec lookup AD pour {$login}: " . $e->getMessage());
            $adUser = null;
        }

        if (!$adUser) {
            $this->toastWarning("Utilisateur {$login} introuvable dans l'annuaire.");
            return null;
        }

        return EloquentUser::create([
            'login' => $login,
            'role' => 'autre',
            'is_active' => true,
        ]);
    }
};
?>

@php
    $selectedGroupLabel = null;
    if ($selectedWorkstationGroupId !== null) {
        foreach ($availableWorkstationGroups as $wg) {
            if ((int) $wg['id'] === (int) $selectedWorkstationGroupId) {
                $selectedGroupLabel = $wg['display_name'];
                break;
            }
        }
    }
    $selectedPermMeta = null;
    if ($selectedDelegationPermission !== null) {
        foreach ($delegatablePermissions as $perm) {
            if ($perm['name'] === $selectedDelegationPermission) {
                $selectedPermMeta = $perm;
                break;
            }
        }
    }

    $summaries = $this->userSummaries;
    $countsByAction = [
        'grant' => 0,
        'revoke' => 0,
        'negate' => 0,
        'lift_negative' => 0,
        'pending' => 0,
        'noop' => 0,
    ];
    foreach ($summaries as $s) {
        $key = $s['action_suggested'] ?? 'noop';
        if (!isset($countsByAction[$key])) {
            $countsByAction[$key] = 0;
        }
        $countsByAction[$key]++;
    }
    $hasPending = $countsByAction['pending'] > 0;

    $forcedActionOptions = [
        '' => 'Auto',
        'grant' => 'Accorder',
        'revoke' => 'Révoquer',
        'negate' => 'Exclure',
        'lift_negative' => "Lever l'exclusion",
    ];
    $currentForcedLabel = $forcedActionOptions[$forcedAction ?? ''] ?? 'Auto';

    $forcedLabel = match ($forcedAction) {
        'grant' => 'Accorder',
        'revoke' => 'Révoquer',
        'negate' => 'Exclure',
        'lift_negative' => "Lever l'exclusion",
        default => null,
    };
    $btnLabel = $forcedLabel !== null ? "Appliquer : {$forcedLabel}" : "Appliquer l'action suggérée";
@endphp

<div>
    <x-molecules.modal wire:model="isOpen" size="max-w-4xl" height="h-[85vh]" title="Déléguer un droit sur une salle"
        icon="fa-building text-primary" noScroll>
        <x-slot:titleComplement>
            <span class="badge badge-neutral badge-sm align-middle">{{ count($selectedUsers) }}</span>
        </x-slot:titleComplement>

        {{-- Contexte : salle + permission --}}
        <x-molecules.modal.section title="Contexte de délégation" icon="fa-sliders text-primary" dense>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                {{-- Salle physique (dropdown DaisyUI avec recherche) --}}
                <div>
                    <label class="text-xs font-medium text-base-content/60 mb-1 block">Salle</label>
                    <details class="dropdown w-full" x-data @click.outside="$el.removeAttribute('open')">
                        <summary class="btn btn-sm btn-outline w-full justify-between font-normal">
                            <span class="truncate">{{ $selectedGroupLabel ?? 'Choisir une salle' }}</span>
                            <i class="fa-solid fa-chevron-down text-xs"></i>
                        </summary>
                        <div
                            class="dropdown-content bg-base-100 rounded-box z-20 mt-1 shadow border border-base-300 w-full p-1">
                            @if (count($availableWorkstationGroups) > 20)
                                <label class="input input-xs w-full mb-1">
                                    <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                                    <input type="text" wire:model.live.debounce.200ms="workstationGroupSearch"
                                        placeholder="Rechercher..." class="grow" />
                                </label>
                            @endif
                            <ul class="menu max-h-60 overflow-y-auto w-full p-0">
                                @forelse ($this->filteredWorkstationGroups as $wg)
                                    <li>
                                        <a class="text-sm py-1 {{ (int) $selectedWorkstationGroupId === (int) $wg['id'] ? 'active' : '' }}"
                                            wire:click="$set('selectedWorkstationGroupId', {{ $wg['id'] }})"
                                            onclick="this.closest('details').removeAttribute('open')">
                                            {{ $wg['display_name'] }}
                                        </a>
                                    </li>
                                @empty
                                    <li class="px-3 py-2 text-xs text-base-content/50">Aucun résultat</li>
                                @endforelse
                            </ul>
                        </div>
                    </details>
                </div>

                {{-- Permission (dropdown DaisyUI) --}}
                <div>
                    <label class="text-xs font-medium text-base-content/60 mb-1 block">Permission</label>
                    <details class="dropdown w-full" x-data @click.outside="$el.removeAttribute('open')">
                        <summary class="btn btn-sm btn-outline w-full justify-between font-normal">
                            <span
                                class="truncate">{{ $selectedPermMeta ? $selectedPermMeta['label'] . ($selectedPermMeta['requires_gpo'] ? ' — GPO' : '') : 'Choisir une permission' }}</span>
                            <i class="fa-solid fa-chevron-down text-xs"></i>
                        </summary>
                        <ul
                            class="menu dropdown-content bg-base-100 rounded-box z-20 mt-1 p-1 shadow border border-base-300 w-full max-h-60 overflow-y-auto">
                            @foreach ($delegatablePermissions as $perm)
                                <li>
                                    <a class="text-sm py-1 {{ $selectedDelegationPermission === $perm['name'] ? 'active' : '' }}"
                                        wire:click="$set('selectedDelegationPermission', '{{ $perm['name'] }}')"
                                        onclick="this.closest('details').removeAttribute('open')">
                                        <span>{{ $perm['label'] }}</span>
                                        @if ($perm['requires_gpo'])
                                            <span class="badge badge-warning badge-xs ml-auto">GPO</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </div>
            </div>

            @if ($selectedDelegationPermission === 'computer.elevate')
                <div class="alert alert-warning py-2 mt-3">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span class="text-sm"><strong>computer.elevate</strong> déclenche une synchronisation GPO.</span>
                </div>
            @endif
        </x-molecules.modal.section>

        {{-- État courant par user --}}
        <x-molecules.modal.section title="État courant par utilisateur" icon="fa-users text-primary" grow scrollable>
            <x-slot:titleComplement>
                <span class="badge badge-neutral badge-sm">{{ count($summaries) }}</span>
            </x-slot:titleComplement>
            @if (!$hasPending && count($summaries) > 0 && $forcedAction === null)
                <x-slot:headerAction>
                    @if ($countsByAction['grant'] > 0)
                        <span class="badge badge-primary badge-sm gap-1" title="Accords à créer">
                            <i class="fa-solid fa-plus"></i>
                            {{ $countsByAction['grant'] }}
                        </span>
                    @endif
                    @if ($countsByAction['revoke'] > 0)
                        <span class="badge badge-warning badge-sm gap-1" title="Révocations">
                            <i class="fa-solid fa-eraser"></i>
                            {{ $countsByAction['revoke'] }}
                        </span>
                    @endif
                    @if ($countsByAction['negate'] > 0)
                        <span class="badge badge-error badge-sm gap-1" title="Exclusions">
                            <i class="fa-solid fa-ban"></i>
                            {{ $countsByAction['negate'] }}
                        </span>
                    @endif
                    @if ($countsByAction['lift_negative'] > 0)
                        <span class="badge badge-info badge-sm gap-1" title="Exclusions levées">
                            <i class="fa-solid fa-rotate-left"></i>
                            {{ $countsByAction['lift_negative'] }}
                        </span>
                    @endif
                </x-slot:headerAction>
            @endif

            <div class="border border-base-300 rounded-lg bg-base-100 shadow-sm">
                @if (count($summaries) === 0)
                    <div class="p-8 text-center text-sm text-base-content/50">
                        <i class="fa-solid fa-user-slash text-3xl mb-2 opacity-20 block"></i>
                        Aucun utilisateur sélectionné.
                    </div>
                @else
                    <table class="table table-sm table-pin-rows">
                        <thead class="bg-base-200">
                            <tr class="text-xs uppercase">
                                <th>Utilisateur</th>
                                <th>Origine du droit</th>
                                <th>État</th>
                                <th>Action Auto</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($summaries as $sum)
                                @php
                                    $stateMeta = match ($sum['state']) {
                                        'granted' => ['class' => 'badge-success', 'icon' => 'fa-check'],
                                        'denied' => ['class' => 'badge-error', 'icon' => 'fa-ban'],
                                        'pending' => ['class' => 'badge-ghost', 'icon' => 'fa-hourglass-half'],
                                        default => ['class' => 'badge-ghost', 'icon' => 'fa-minus'],
                                    };
                                    $actionMeta = match ($sum['action_suggested']) {
                                        'grant' => ['class' => 'badge-primary', 'icon' => 'fa-plus'],
                                        'revoke' => ['class' => 'badge-warning', 'icon' => 'fa-eraser'],
                                        'negate' => ['class' => 'badge-error', 'icon' => 'fa-ban'],
                                        'lift_negative' => ['class' => 'badge-info', 'icon' => 'fa-rotate-left'],
                                        'pending' => ['class' => 'badge-ghost', 'icon' => 'fa-hourglass-half'],
                                        default => ['class' => 'badge-ghost', 'icon' => 'fa-minus'],
                                    };
                                    $sourceMeta = match ($sum['source']) {
                                        'delegation_negative' => ['icon' => 'fa-user-xmark', 'color' => 'text-error'],
                                        'delegation_positive' => ['icon' => 'fa-user-check', 'color' => 'text-success'],
                                        'global' => ['icon' => 'fa-shield-halved', 'color' => 'text-info'],
                                        'role' => ['icon' => 'fa-user-shield', 'color' => 'text-info'],
                                        'pending' => ['icon' => 'fa-hourglass-half', 'color' => 'text-base-content/30'],
                                        default => ['icon' => 'fa-user', 'color' => 'text-base-content/30'],
                                    };
                                @endphp
                                <tr wire:key="dmod-row-{{ $sum['login'] }}">
                                    <td class="font-mono text-xs">
                                        {{ $sum['login'] }}
                                        @if (!$sum['exists_in_sql'])
                                            <span class="badge badge-ghost badge-xs ml-1"
                                                title="Pas encore en base SER">AD</span>
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        <i
                                            class="fa-solid {{ $sourceMeta['icon'] }} {{ $sourceMeta['color'] }} mr-1.5"></i>
                                        {{ $sum['source_label'] }}
                                    </td>
                                    <td>
                                        <span class="badge {{ $stateMeta['class'] }} badge-sm gap-1">
                                            <i class="fa-solid {{ $stateMeta['icon'] }}"></i>
                                            {{ $sum['state_label'] }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge {{ $actionMeta['class'] }} badge-sm gap-1">
                                            <i class="fa-solid {{ $actionMeta['icon'] }}"></i>
                                            {{ $sum['action_label'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </x-molecules.modal.section>

        {{-- Action à appliquer --}}
        <x-molecules.modal.section title="Action à appliquer" icon="fa-wand-magic-sparkles text-primary" dense>
            <div class="flex gap-6 items-start flex-wrap">
                <div>
                    <div>
                        <label class="label">
                            <span class="label-text font-medium">Action</span>
                        </label>
                    </div>
                    <details class="dropdown dropdown-top max-w-[220px] mt-1" x-data
                        @click.outside="$el.removeAttribute('open')">
                        <summary class="btn btn-sm btn-outline w-full justify-between font-normal">
                            <span>{{ $currentForcedLabel }}</span>
                            <i class="fa-solid fa-chevron-up text-xs"></i>
                        </summary>
                        <ul
                            class="menu dropdown-content bg-base-100 rounded-box z-20 mb-1 p-1 shadow border border-base-300 w-48">
                            @foreach ($forcedActionOptions as $value => $label)
                                <li>
                                    <a class="text-sm py-1 {{ ($forcedAction ?? '') === $value ? 'active' : '' }}"
                                        wire:click="$set('forcedAction', {{ $value === '' ? 'null' : "'{$value}'" }})"
                                        onclick="this.closest('details').removeAttribute('open')">
                                        {{ $label }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </div>

                <div>
                    <label class="label">
                        <span class="label-text font-medium">Expiration</span>
                    </label>
                    <div class="max-w-[280px] mt-1">
                        <x-molecules.date-time-picker wire:model.live="delegationExpiresAt" :with-time="true" />
                    </div>
                </div>
            </div>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="applyDelegationActions"
                wire:loading.attr="disabled" @disabled($selectedWorkstationGroupId === null || $selectedDelegationPermission === null)>
                <span wire:loading.remove wire:target="applyDelegationActions">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    {{ $btnLabel }}
                </span>
                <span wire:loading wire:target="applyDelegationActions">
                    <span class="loading loading-spinner loading-xs"></span> Traitement...
                </span>
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</div>
