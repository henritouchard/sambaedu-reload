<?php

use App\Components\Traits\WithToasts;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\UserGroupService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Story 4.15 — Section « Professeur principal » Livewire de la fiche groupe
 * `/app/users/groups/[id]`.
 *
 * Pattern aligné sur `_partials/class-share-section.blade.php` (5.2) :
 *  - Component SFC anonyme via `new class extends Component`.
 *  - Double guard `update-group` (UI `@can` + serveur `Gate::authorize`) — la
 *    même permission qui régit l'édition du groupe (`user.modify`, D6).
 *  - Toasts `WithToasts` génériques (pas `$e->getMessage()` — leçon 5.1b #4).
 *  - Section affichée UNIQUEMENT si `$group->type === 'classe'` (D4). Un payload
 *    Livewire forgé avec un groupId non-classe est rejeté en `mount` via abort.
 *
 * Fonction : désigner le(s) professeur(s) principal(aux) d'une classe. Le toggle
 * n'est proposé que pour les membres `isProf()` (D5) — un élève PP n'a pas de
 * sens métier (l'écriture service reste robuste si forcé : intersection membres).
 * Plusieurs PP autorisés.
 *
 * Persistance : `UserGroupService::updateGroup($id, [... 'head_teacher_ids'])` —
 * un seul aller-retour qui (1) projette la 3ᵉ cible AD `PP_<base>` (orthogonale
 * à `Equipe_`/`Classe_`) AVANT le read-back `syncFromAd` (D2), (2) fait converger
 * le pivot `is_head_teacher` au read-back. L'état lu pour cocher les toggles vient
 * du pivot `UserGroup::users()->pivot->is_head_teacher` (withPivot 4.14, D8).
 */
new class extends Component {
    use WithToasts;

    #[Locked]
    public int $groupId = 0;

    #[Locked]
    public bool $isClasse = false;

    #[Locked]
    public string $className = '';

    /** @var array<int,int> IDs des membres profs cochés « professeur principal ». */
    public array $headTeacherIds = [];

    public bool $isLoading = false;

    private UserGroupService $userGroupService;

    /** Cache mémoïsé per-render du UserGroup. */
    private ?UserGroup $cachedGroup = null;

    public function boot(UserGroupService $userGroupService): void
    {
        $this->userGroupService = $userGroupService;
    }

    public function mount(int $groupId): void
    {
        $this->groupId = $groupId;
        $group = UserGroup::find($groupId);
        if ($group === null) {
            // Cohérence avec la page parente qui abort 404 en mount.
            abort(404);
        }
        // Anti-forge : un groupId non-classe est rejeté (pas seulement le @if
        // de la vue parente).
        $this->isClasse = ($group->type === 'classe');
        $this->className = (string) $group->name;
        if ($this->isClasse) {
            $this->refreshState($group);
        }
    }

    /**
     * Recharge la sélection PP depuis le pivot SQL (source côté SE5).
     */
    private function refreshState(?UserGroup $group = null): void
    {
        $group = $group ?? UserGroup::find($this->groupId);
        if ($group === null || $group->type !== 'classe') {
            return;
        }

        $this->headTeacherIds = $group->users
            ->filter(static fn(User $u): bool => (bool) ($u->pivot->is_head_teacher ?? false))
            ->map(static fn(User $u): int => (int) $u->id)
            ->values()
            ->all();
    }

    /**
     * Membres profs de la classe avec leur état PP courant. Le toggle PP n'est
     * proposé QUE pour les `isProf()` (D5).
     *
     * @return array<int,array{id:int,login:string,label:string,is_head_teacher:bool}>
     */
    public function profMembers(): array
    {
        $group = $this->groupModel();
        if ($group === null) {
            return [];
        }

        return $group->users
            ->filter(static fn(User $u): bool => $u->isProf())
            ->map(function (User $u): array {
                $label = $u->fullname ?: trim((string) (($u->firstname ?? '') . ' ' . ($u->lastname ?? '')));
                if ($label === '') {
                    $label = (string) $u->login;
                }

                return [
                    'id' => (int) $u->id,
                    'login' => (string) $u->login,
                    'label' => $label,
                    'is_head_teacher' => in_array((int) $u->id, $this->headTeacherIds, true),
                ];
            })
            ->values()
            ->all();
    }

    public function toggleHeadTeacher(int $userId): void
    {
        if (in_array($userId, $this->headTeacherIds, true)) {
            $this->headTeacherIds = array_values(array_filter(
                $this->headTeacherIds,
                static fn(int $id): bool => $id !== $userId
            ));
        } else {
            $this->headTeacherIds[] = $userId;
        }
    }

    public function save(): void
    {
        $group = $this->loadClasseOrFail();
        Gate::authorize('update-group', $group);

        $this->isLoading = true;
        try {
            // Ne retenir que les PP qui sont effectivement membres profs (D5 UI ;
            // le service ré-intersecte défensivement côté écriture AD).
            $allowed = collect($this->profMembers())->pluck('id')->map(static fn(mixed $v): int => (int) $v)->all();
            $ppIds = array_values(array_intersect(
                array_map('intval', $this->headTeacherIds),
                $allowed
            ));

            $this->userGroupService->updateGroup($this->groupId, [
                'name' => $group->name,
                'display_name' => $group->display_name ?? '',
                'type' => $group->type,
                'user_ids' => $group->users()->pluck('users.id')->map(static fn(mixed $id): int => (int) $id)->all(),
                'head_teacher_ids' => $ppIds,
            ]);

            $this->toastSuccess('Professeur(s) principal(aux) mis à jour.');
        } catch (\Throwable $e) {
            Log::error('UserGroupService: erreur UI head-teacher save', [
                'group_id' => $this->groupId,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Erreur lors de la mise à jour du professeur principal.');
        } finally {
            $this->isLoading = false;
            $this->cachedGroup = null;
            $this->refreshState();
        }
    }

    private function loadClasseOrFail(): UserGroup
    {
        $group = UserGroup::find($this->groupId);
        if ($group === null || $group->type !== 'classe') {
            abort(404);
        }
        return $group;
    }

    /**
     * Helper exposé pour `@can('update-group', $this->groupModel())` dans le
     * template. Mémoïsé per-instance.
     */
    public function groupModel(): ?UserGroup
    {
        return $this->cachedGroup ??= UserGroup::find($this->groupId);
    }
};
?>

@if (! $isClasse)
    {{-- Le component n'affiche rien si le UserGroup n'est pas de type classe. --}}
    <div></div>
@else
    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <div class="flex items-center justify-between mb-4">
                <h3 class="card-title text-lg">
                    <i class="fa-solid fa-chalkboard-user mr-2"></i>
                    Professeur principal
                </h3>
            </div>

            @can('view-group')
                @php($profs = $this->profMembers())
                @if (count($profs) === 0)
                    <div class="alert alert-info text-sm">
                        <i class="fa-solid fa-circle-info"></i>
                        Aucun enseignant membre de cette classe. Ajoutez un prof
                        aux membres pour pouvoir le désigner professeur principal.
                    </div>
                @else
                    <p class="text-sm opacity-70 mb-3">
                        Désignez le(s) professeur(s) principal(aux) de
                        <code>{{ $className }}</code>. Plusieurs choix possibles.
                        Seuls les enseignants membres sont proposés.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>Enseignant</th>
                                    <th>Login</th>
                                    <th class="text-center">Professeur principal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($profs as $prof)
                                    <tr>
                                        <td class="font-medium">{{ $prof['label'] }}</td>
                                        <td>
                                            <code
                                                class="text-sm bg-base-200 px-2 py-0.5 rounded font-mono">{{ $prof['login'] }}</code>
                                        </td>
                                        <td class="text-center">
                                            @can('update-group', $this->groupModel())
                                                <input type="checkbox"
                                                    class="toggle toggle-primary toggle-sm"
                                                    @checked($prof['is_head_teacher'])
                                                    wire:click="toggleHeadTeacher({{ $prof['id'] }})"
                                                    wire:loading.attr="disabled" wire:target="save" />
                                            @else
                                                @if ($prof['is_head_teacher'])
                                                    <span class="badge badge-success badge-sm">
                                                        <i class="fa-solid fa-check mr-1"></i>
                                                        PP
                                                    </span>
                                                @else
                                                    <span class="text-base-content/40">—</span>
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @can('update-group', $this->groupModel())
                        <div class="mt-4 flex items-center gap-2">
                            <button type="button" class="btn btn-primary btn-sm"
                                wire:click="save" wire:loading.attr="disabled"
                                wire:target="save">
                                <i class="fa-solid fa-floppy-disk"></i>
                                Enregistrer
                            </button>
                            <span wire:loading wire:target="save"
                                class="text-xs opacity-60 flex items-center gap-2">
                                <span class="loading loading-spinner loading-xs"></span>
                                Mise à jour…
                            </span>
                        </div>
                    @else
                        <p class="text-xs opacity-70 mt-3">
                            Vous n'avez pas la permission <code>user.modify</code>
                            requise pour modifier le professeur principal.
                        </p>
                    @endcan
                @endif
            @else
                <div class="alert alert-info text-sm">
                    <i class="fa-solid fa-lock"></i>
                    Accès restreint — la consultation des groupes requiert la
                    permission <code>user.read</code>.
                </div>
            @endcan
        </div>
    </div>
@endif
