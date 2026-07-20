<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Application;
use App\Policies\UserPolicy;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recherche globale du header (Ctrl+K).
 *
 * Cherche dans les entités métier SE5 réelles — postes, groupes de postes,
 * utilisateurs, groupes d'utilisateurs, applications — et renvoie des résultats
 * groupés par catégorie, chacun pointant vers sa page de détail.
 *
 * Périmètre / droits :
 *  - Postes & groupes de postes : scopés à la délégation de l'utilisateur via
 *    `WorkstationGroupService` (droit global `computer.view` → tout ; sinon
 *    uniquement les salles déléguées, exclusions retirées).
 *  - Utilisateurs & groupes d'utilisateurs : uniquement si `user.read`.
 *  - Applications : uniquement si `computer.install`.
 * Une catégorie sans droit n'est jamais interrogée (pas de fuite d'existence).
 */
new class extends Component {
    /** Terme de recherche (piloté en live depuis le champ de la modale). */
    public string $query = '';

    /** Nombre de résultats max par catégorie. */
    private const PER_CATEGORY = 5;

    /** Longueur minimale avant de déclencher une recherche. */
    private const MIN_CHARS = 2;

    /**
     * Résultats groupés par catégorie.
     *
     * @return array<int,array{category:string,icon:string,items:array<int,array{title:string,subtitle:string,url:string}>}>
     */
    #[Computed]
    public function results(): array
    {
        $q = trim($this->query);
        $user = auth()->user();

        // `scopeFor:` de WorkstationGroupService exige un App\Models\User strict —
        // même garde que `scopedUser()` des pages parc (auth peut renvoyer un autre type).
        if (! $user instanceof User || mb_strlen($q) < self::MIN_CHARS) {
            return [];
        }

        $groups = [];
        $service = app(WorkstationGroupService::class);

        // Postes — scopés délégation. Le search du repository couvre `name` (ILIKE).
        $machines = $service->listMachines(perPage: self::PER_CATEGORY, search: $q, scopeFor: $user);
        if ($machines->total() > 0) {
            $groups[] = [
                'category' => 'Postes',
                'icon' => 'fa-desktop',
                'items' => collect($machines->items())->map(fn ($m) => [
                    'title' => $m->name,
                    'subtitle' => trim((string) ($m->os ?? '') . ' ' . (string) ($m->ip ?? '')),
                    'url' => route('app.parc.machines.show', $m->id),
                ])->all(),
            ];
        }

        // Groupes de postes — scopés délégation (search : name + display_name).
        $workstationGroups = $service->listGroups(perPage: self::PER_CATEGORY, search: $q, scopeFor: $user);
        if ($workstationGroups->total() > 0) {
            $groups[] = [
                'category' => 'Groupes de postes',
                'icon' => 'fa-layer-group',
                'items' => collect($workstationGroups->items())->map(fn ($g) => [
                    'title' => $g->display_name ?: $g->name,
                    'subtitle' => (string) ($g->description ?? ''),
                    'url' => route('app.parc.groups.show', $g->id),
                ])->all(),
            ];
        }

        // Utilisateurs & groupes d'utilisateurs — gate `user.read`.
        if ($user->can('user.read')) {
            $usersQuery = User::query()
                ->where(fn ($sub) => $sub
                    ->where('login', 'ILIKE', "%{$q}%")
                    ->orWhere('fullname', 'ILIKE', "%{$q}%")
                    ->orWhere('email', 'ILIKE', "%{$q}%"));

            // RGPD — même scoping que la page /users (correction review 7.2 #3) :
            // un Prof/EleveAdmin scopé classe (user.read sans rôle global) ne doit
            // voir que les co-membres de ses propres classes. Sans ce filtre, la
            // recherche contournerait `UserPolicy::view()`.
            $this->scopeUsersToActorClasses($usersQuery, $user);

            $users = $usersQuery
                ->orderBy('fullname')
                ->limit(self::PER_CATEGORY)
                ->get();
            if ($users->isNotEmpty()) {
                $groups[] = [
                    'category' => 'Utilisateurs',
                    'icon' => 'fa-user',
                    'items' => $users->map(fn ($u) => [
                        'title' => $u->fullname ?: $u->login,
                        'subtitle' => (string) $u->login,
                        'url' => route('app.user.show', $u->login),
                    ])->all(),
                ];
            }

            $userGroups = UserGroup::query()
                ->where(fn ($sub) => $sub
                    ->where('name', 'ILIKE', "%{$q}%")
                    ->orWhere('display_name', 'ILIKE', "%{$q}%"))
                ->orderBy('display_name')
                ->limit(self::PER_CATEGORY)
                ->get();
            if ($userGroups->isNotEmpty()) {
                $groups[] = [
                    'category' => "Groupes d'utilisateurs",
                    'icon' => 'fa-users',
                    'items' => $userGroups->map(fn ($g) => [
                        'title' => $g->display_name ?: $g->name,
                        'subtitle' => (string) ($g->type ?? ''),
                        'url' => route('app.users.groups.edit', $g->id),
                    ])->all(),
                ];
            }
        }

        // Applications — gate `computer.install`.
        if ($user->can('computer.install')) {
            $apps = Application::query()
                ->where(fn ($sub) => $sub
                    ->where('name', 'ILIKE', "%{$q}%")
                    ->orWhere('description', 'ILIKE', "%{$q}%")
                    ->orWhere('category', 'ILIKE', "%{$q}%"))
                ->orderBy('name')
                ->limit(self::PER_CATEGORY)
                ->get();
            if ($apps->isNotEmpty()) {
                $groups[] = [
                    'category' => 'Applications',
                    'icon' => 'fa-box',
                    'items' => $apps->map(fn ($a) => [
                        'title' => $a->name,
                        'subtitle' => trim((string) ($a->version ?? '') . ' ' . (string) ($a->category ?? '')),
                        'url' => route('app.parc-settings.applications.show', $a->id),
                    ])->all(),
                ];
            }
        }

        return $groups;
    }

    /**
     * Restreint une requête utilisateurs au périmètre classe de l'acteur.
     *
     * Réplique la garde RGPD de `pages/users/index.blade.php` : un Prof ou
     * EleveAdmin scopé classe (droit `user.read` mais aucun rôle global) ne voit
     * que les co-membres de ses propres classes (`type='classe'`, noms nus
     * résolus par le helper partagé `User::classGroupNames()`). Sans classe
     * attachée → aucun résultat (`1 = 0`). Un rôle global n'est pas restreint.
     */
    private function scopeUsersToActorClasses(Builder $query, User $actor): void
    {
        if (! $actor->hasAnyRole(['prof', 'eleve-admin'])
            || $actor->hasAnyRole(UserPolicy::GLOBAL_USER_ROLES)) {
            return;
        }

        $classNames = $actor->classGroupNames();

        if ($classNames->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas(
            'userGroups',
            fn (Builder $q) => $q->where('type', 'classe')
                ->whereIn('name', $classNames->all()),
        );
    }

    /** Total des résultats toutes catégories confondues. */
    #[Computed]
    public function totalResults(): int
    {
        return collect($this->results)->sum(fn ($g) => count($g['items']));
    }

    /** Longueur minimale exposée au template. */
    #[Computed]
    public function minChars(): int
    {
        return self::MIN_CHARS;
    }
};
?>

<!-- Recherche globale (Ctrl+K) -->
<div x-data="{ searchOpen: false }"
    @keydown.window.ctrl.k.prevent="searchOpen = true"
    @keydown.window.meta.k.prevent="searchOpen = true">

    <!-- Déclencheur : bouton stylé « faux champ » (n'accepte pas la frappe) -->
    <button type="button" @click="searchOpen = true"
        class="bg-sky-100 p-2 rounded-full flex items-center gap-2 w-full max-w-xs text-left text-base-content/60 hover:bg-sky-200 transition-colors">
        <i class="fa-solid fa-magnifying-glass"></i>
        <span class="flex-1 text-sm">Rechercher...</span>
        <kbd class="hidden sm:inline-block text-xs px-1.5 py-0.5 rounded border border-base-300 bg-base-100/60">Ctrl K</kbd>
    </button>

    <!-- Modale -->
    <div x-show="searchOpen" x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0" class="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4"
        x-init="$watch('searchOpen', value => { if (value) $nextTick(() => $refs.searchInput?.focus()) })"
        style="display: none;">
        <div class="fixed inset-0 bg-black/50" @click="searchOpen = false; $wire.set('query', '')"></div>
        <div class="relative w-full max-w-2xl bg-base-100 rounded-lg shadow-xl border border-base-300" @click.stop>
            <div class="p-4">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-base-content/60"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <input type="text" placeholder="Rechercher un poste, un groupe, un utilisateur, une application..."
                        class="input input-bordered w-full pl-10 pr-4"
                        wire:model.live.debounce.300ms="query"
                        x-ref="searchInput"
                        @keydown.escape="searchOpen = false; $wire.set('query', '')" />
                    <span wire:loading wire:target="query"
                        class="absolute right-3 top-1/2 transform -translate-y-1/2 loading loading-spinner loading-sm text-primary"></span>
                </div>

                <!-- Résultats -->
                <div class="mt-4 max-h-[65vh] min-h-[16rem] overflow-y-auto">
                    @if ($this->totalResults > 0)
                        <div class="space-y-6">
                            @foreach ($this->results as $group)
                                <div>
                                    <div class="flex items-center gap-2 text-sm font-bold text-base-content bg-base-200 rounded-md px-3 py-2 mb-2 sticky top-0">
                                        <i class="fa-solid {{ $group['icon'] }} text-primary"></i>
                                        <span class="uppercase tracking-wide">{{ $group['category'] }}</span>
                                        <span class="ml-auto text-xs font-normal text-base-content/50">{{ count($group['items']) }}</span>
                                    </div>
                                    <div class="space-y-1 pl-8">
                                        @foreach ($group['items'] as $item)
                                            <a href="{{ $item['url'] }}" wire:navigate
                                                @click="searchOpen = false; $wire.set('query', '')"
                                                class="block p-2 hover:bg-base-200 rounded-md transition-colors">
                                                <div class="font-medium text-sm truncate">{{ $item['title'] }}</div>
                                                @if ($item['subtitle'] !== '')
                                                    <div class="text-xs text-base-content/60 truncate">{{ $item['subtitle'] }}</div>
                                                @endif
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif (mb_strlen(trim($query)) >= $this->minChars)
                        <div class="text-center py-8 text-base-content/60" wire:loading.remove wire:target="query">
                            <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <p>Aucun résultat pour « <span class="font-medium">{{ trim($query) }}</span> »</p>
                        </div>
                    @else
                        <div class="text-center py-8 text-base-content/60">
                            <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <p>Tapez pour rechercher...</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Pied -->
            <div class="px-4 py-3 bg-base-200/50 rounded-b-lg border-t border-base-300">
                <div class="flex items-center justify-between text-xs text-base-content/60">
                    <div>
                        @if ($this->totalResults > 0)
                            {{ $this->totalResults }} résultat(s)
                        @endif
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="text-xs">Ctrl+K pour ouvrir</span>
                        <button type="button" @click="searchOpen = false; $wire.set('query', '')"
                            class="text-xs hover:text-base-content transition-colors">
                            Fermer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
