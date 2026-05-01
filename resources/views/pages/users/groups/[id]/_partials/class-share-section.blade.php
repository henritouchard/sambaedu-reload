<?php

use App\Components\Traits\WithToasts;
use App\Models\UserGroup;
use App\Services\Filesystem\ShareService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Story 5.2 — Section "Partage de classe" Livewire de la fiche groupe
 * `/app/users/groups/[id]`.
 *
 * Pattern aligné sur `_partials/group-quota-section.blade.php` 5.1c :
 *  - Component SFC anonyme via `new class extends Component`.
 *  - Double guard `manage-share` (UI `@can` + serveur `Gate::authorize`).
 *  - Toasts `WithToasts` génériques (pas `$e->getMessage()` — leçon 5.1b #4).
 *  - Section affichée UNIQUEMENT si `$group->type === 'classe'` — un payload
 *    Livewire forgé avec un groupId non-classe est rejeté en mount via abort.
 *
 * Sources d'info :
 *  - `ShareService::getStatus($group)` — état FS + sous-dirs + state échange.
 *  - Lecture cachée 60s pour éviter de spammer `getfacl` à chaque render.
 *
 * Actions :
 *  - `createShare()`     : (re-)applique les ACLs canoniques (idempotent).
 *  - `reapplyAcls()`     : alias `createShare()` — UX bouton plus clair quand
 *                          le partage existe déjà.
 *  - `toggleEchange()`   : switch ACL `_echange` ↔ ---.
 */
new class extends Component {
    use WithToasts;

    #[Locked]
    public int $groupId = 0;

    #[Locked]
    public bool $isClasse = false;

    #[Locked]
    public string $className = '';

    public bool $shareExists = false;

    public ?bool $echangeActive = null;

    public array $subdirs = [];

    public int $membersCount = 0;

    public ?string $resolvedPath = null;

    public bool $isLoading = false;

    private ShareService $shareService;

    /** Cache mémoïsé per-render du UserGroup (review 5.2 #6). */
    private ?UserGroup $cachedGroup = null;

    public function boot(ShareService $shareService): void
    {
        $this->shareService = $shareService;
    }

    public function mount(int $groupId): void
    {
        $this->groupId = $groupId;
        $group = UserGroup::find($groupId);
        if ($group === null) {
            // Cohérence avec la page parente qui abort 404 en mount.
            abort(404);
        }
        $this->isClasse = ($group->type === 'classe');
        $this->className = (string) $group->name;
        if ($this->isClasse) {
            $this->refreshState($group);
        }
    }

    /**
     * Recharge l'état FS depuis ShareService::getStatus avec un cache 60s
     * (clé per-group) pour éviter le spam de getfacl en re-render Livewire.
     */
    private function refreshState(?UserGroup $group = null): void
    {
        $group = $group ?? UserGroup::find($this->groupId);
        if ($group === null || $group->type !== 'classe') {
            return;
        }

        $cacheKey = 'share-status:' . $this->groupId;
        $status = Cache::remember($cacheKey, 60, fn () => $this->shareService->getStatus($group));

        $this->shareExists = (bool) ($status['exists'] ?? false);
        $this->resolvedPath = $status['path'] ?? null;
        $this->subdirs = $status['subdirs'] ?? [];
        $this->echangeActive = $status['echange_active'] ?? null;
        $this->membersCount = (int) ($status['members_count'] ?? 0);
    }

    private function bustCache(): void
    {
        Cache::forget('share-status:' . $this->groupId);
        // Review 5.2 #6 — invalide aussi la mémoïsation du UserGroup pour
        // refléter d'éventuels changements (rename, type, etc.) post-action.
        $this->cachedGroup = null;
    }

    // =========================================================================
    // ACTIONS — toutes commencent par `Gate::authorize('manage-share', $group)`.
    // =========================================================================

    public function createShare(): void
    {
        $group = $this->loadClasseOrFail();
        Gate::authorize('manage-share', $group);

        $this->isLoading = true;
        try {
            $ok = $this->shareService->createClassShare($group);
            if ($ok) {
                $this->toastSuccess('Partage de classe créé / ACLs réappliquées.');
            } else {
                $this->toastError('Création/réapplication partielle. Consultez les logs.');
            }
        } catch (\Throwable $e) {
            Log::error('ShareService: erreur UI createShare', [
                'group_id' => $this->groupId,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Erreur lors de la création du partage.');
        } finally {
            $this->isLoading = false;
            $this->bustCache();
            $this->refreshState();
        }
    }

    public function reapplyAcls(): void
    {
        // Alias UX : recreate idempotent.
        $this->createShare();
    }

    public function toggleEchange(): void
    {
        $group = $this->loadClasseOrFail();
        Gate::authorize('manage-share', $group);

        $this->isLoading = true;
        try {
            // Si l'état est inconnu, on assume "activer" par défaut (D6=A).
            $newState = ! ($this->echangeActive ?? false);
            $ok = $this->shareService->toggleEchange($group, active: $newState);
            if ($ok) {
                $msg = $newState
                    ? 'Dossier d\'échange activé (lecture/écriture pour les membres classe).'
                    : 'Dossier d\'échange désactivé (data préservée mais invisible aux membres).';
                $this->toastSuccess($msg);
            } else {
                $this->toastError('Toggle échec — consultez les logs.');
            }
        } catch (\Throwable $e) {
            Log::error('ShareService: erreur UI toggleEchange', [
                'group_id' => $this->groupId,
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Erreur lors du toggle du dossier d\'échange.');
        } finally {
            $this->isLoading = false;
            $this->bustCache();
            $this->refreshState();
        }
    }

    public function refresh(): void
    {
        // Review 5.2 #16 — cohérence pattern double-guard : on ajoute
        // `Gate::authorize('viewAny-share')` même si `refreshState()` exit
        // early sur non-classe et que la lecture FS est sans side-effect.
        // L'objectif est uniquement la cohérence : pas de méthode publique
        // Livewire sans Gate explicit, pour faciliter l'audit sécurité.
        Gate::authorize('viewAny-share');
        $this->bustCache();
        $this->refreshState();
        $this->toastInfo('État du partage actualisé.');
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
     * Helper exposé pour le check `@can('manage-share', $this->groupModel())`
     * dans le template Blade. Retourne le UserGroup chargé (ou null si
     * absent — laisse @can renvoyer false dans ce cas).
     *
     * Review 5.2 #6 — mémoïsation per-instance pour éviter 2 queries par
     * render (le template appelle groupModel() 2 fois).
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
                    <i class="fa-solid fa-folder-tree mr-2"></i>
                    Partage de classe
                </h3>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="refresh"
                    wire:loading.attr="disabled" wire:target="refresh">
                    <i class="fa-solid fa-rotate-right"></i>
                    Actualiser
                </button>
            </div>

            @can('share.view')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {{-- Colonne 1 : état FS --}}
                    <div class="flex flex-col gap-3">
                        <span class="font-medium">État du partage</span>
                        <div class="bg-base-200 rounded-lg py-3 px-4">
                            @if ($shareExists)
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="badge badge-success">
                                        <i class="fa-solid fa-check mr-1"></i>
                                        Partage créé
                                    </span>
                                    <span class="text-xs opacity-70">{{ $membersCount }} membre(s)</span>
                                </div>
                                @if ($resolvedPath)
                                    <code class="text-xs opacity-70 break-all">{{ $resolvedPath }}</code>
                                @endif
                                <div class="mt-3 space-y-1 text-sm">
                                    <div class="flex items-center gap-2">
                                        <i
                                            class="fa-solid fa-{{ $subdirs['_travail'] ?? false ? 'check text-success' : 'xmark text-base-content/40' }} w-4"></i>
                                        <span>_travail (lecture élève / écriture prof)</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i
                                            class="fa-solid fa-{{ $subdirs['_profs'] ?? false ? 'check text-success' : 'xmark text-base-content/40' }} w-4"></i>
                                        <span>_profs (privé enseignants)</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i
                                            class="fa-solid fa-{{ $subdirs['_echange'] ?? false ? 'check text-success' : 'xmark text-base-content/40' }} w-4"></i>
                                        <span>_echange
                                            @if ($echangeActive === true)
                                                <span class="badge badge-success badge-sm ml-1">activé</span>
                                            @elseif ($echangeActive === false)
                                                <span class="badge badge-ghost badge-sm ml-1">désactivé</span>
                                            @endif
                                        </span>
                                    </div>
                                </div>
                            @else
                                <span class="badge badge-warning">
                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                    Non créé
                                </span>
                                <p class="text-xs opacity-70 mt-2">
                                    Le partage <code>Classe_{{ $className }}</code> n'a pas encore
                                    été initialisé sur le filesystem.
                                    @can('manage-share', $this->groupModel())
                                        Cliquez sur le bouton ci-contre pour créer les
                                        dossiers et appliquer les ACLs canoniques.
                                    @endcan
                                </p>
                            @endif
                        </div>
                    </div>

                    {{-- Colonne 2 : actions --}}
                    <div class="flex flex-col gap-3">
                        <span class="font-medium">Actions</span>
                        <div class="bg-base-200 rounded-lg py-3 px-4 space-y-2">
                            @can('manage-share', $this->groupModel())
                                @if (! $shareExists)
                                    <button type="button" class="btn btn-primary btn-sm w-full"
                                        wire:click="createShare" wire:loading.attr="disabled"
                                        wire:target="createShare">
                                        <i class="fa-solid fa-folder-plus"></i>
                                        Créer le partage
                                    </button>
                                @else
                                    <button type="button" class="btn btn-outline btn-sm w-full"
                                        wire:click="reapplyAcls" wire:loading.attr="disabled"
                                        wire:target="reapplyAcls">
                                        <i class="fa-solid fa-arrows-rotate"></i>
                                        Réappliquer les ACLs
                                    </button>
                                    <button type="button" class="btn btn-outline btn-sm w-full"
                                        wire:click="toggleEchange" wire:loading.attr="disabled"
                                        wire:target="toggleEchange">
                                        <i class="fa-solid fa-arrows-left-right"></i>
                                        @if ($echangeActive === true)
                                            Désactiver le dossier d'échange
                                        @else
                                            Activer le dossier d'échange
                                        @endif
                                    </button>
                                @endif
                            @else
                                <p class="text-xs opacity-70">
                                    Vous n'avez pas la permission <code>share.manage</code>
                                    requise pour modifier le partage.
                                </p>
                            @endcan
                        </div>
                    </div>
                </div>
            @else
                <div class="alert alert-info text-sm">
                    <i class="fa-solid fa-lock"></i>
                    Accès restreint — la consultation des partages requiert la
                    permission <code>share.view</code>.
                </div>
            @endcan

            <div wire:loading wire:target="createShare,reapplyAcls,toggleEchange,refresh"
                class="text-xs opacity-60 mt-3 flex items-center gap-2">
                <span class="loading loading-spinner loading-xs"></span>
                Opération en cours…
            </div>
        </div>
    </div>
@endif
