<!-- Formulaire de recherche -->
<div class="flex-shrink-0 card bg-base-100 shadow-sm mb-2 relative z-10 py-2">
    <div class="card-body py-2">
        <h3 class="card-title text-lg mb-2">
            <i class="fa-solid fa-magnifying-glass"></i>
            Rechercher des utilisateurs
        </h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2" @keydown.enter="$wire.performSearch()"
            x-data="{
                searchName: @entangle('searchName'),
                searchLogin: @entangle('searchLogin'),
                role: @entangle('role'),
                status: @entangle('status'),
                group: @entangle('group'),
                classes: @entangle('classes'),
                hasAnyCriteria() {
                    return this.searchName.trim() !== '' ||
                        this.searchLogin.trim() !== '' ||
                        this.role.length > 0 ||
                        this.status.length > 0 ||
                        this.group.length > 0 ||
                        this.classes.length > 0;
                }
            }">
            <!-- Recherche par nom -->
            <div class="form-control">
                <!-- Recherche optimisée: Recherche dans displayName et sn (attributs indexés) -->
                <label class="label">
                    <span class="label-text font-medium">Nom / Prénom</span>
                </label>
                <input type="text" wire:model="searchName" placeholder="Ex: Dupont, Marie..."
                    class="input input-bordered w-full" />
            </div>

            <!-- Recherche par login -->
            <div class="form-control">
                <!-- Recherche très rapide: Recherche dans cn et sAMAccountName (hautement indexés) -->
                <label class="label">
                    <span class="label-text font-medium">
                        <i title="recherche rapide" class="fa-solid fa-bolt-lightning text-warning"></i>
                        Login
                    </span>
                </label>
                <input type="text" wire:model="searchLogin" autocomplete="none" placeholder="Ex: jean.dupont..."
                    class="input input-bordered w-full" />
            </div>

            <!-- Rôle -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-medium">Rôle</span>
                </label>
                <div class="dropdown dropdown-bottom w-full" x-data="{ openRoles: false }"
                    :class="{ 'dropdown-open': openRoles }">
                    <label tabindex="0" class="btn btn-outline w-full justify-between"
                        @click="openRoles = !openRoles">
                        <span class="flex items-center gap-2">
                            <i class="fa-solid fa-user-tag"></i>
                            @if (empty($role))
                                Sélectionner des rôles...
                            @else
                                {{ count($role) }} rôle(s) sélectionné(s)
                            @endif
                        </span>
                        <i class="fa-solid fa-chevron-down text-xs" :class="{ 'rotate-180': openRoles }"></i>
                    </label>
                    <div tabindex="0"
                        class="dropdown-content z-[100] menu p-2 shadow-lg bg-base-100 rounded-box w-full border border-base-300"
                        @click.away="openRoles = false">
                        <li>
                            <label class="label cursor-pointer justify-start gap-3 p-2">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="role"
                                    value="eleves" />
                                <span class="label-text">Élèves</span>
                            </label>
                        </li>
                        <li>
                            <label class="label cursor-pointer justify-start gap-3 p-2">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="role"
                                    value="profs" />
                                <span class="label-text">Professeurs</span>
                            </label>
                        </li>
                        <li>
                            <label class="label cursor-pointer justify-start gap-3 p-2">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="role"
                                    value="administratifs" />
                                <span class="label-text">Administratifs</span>
                            </label>
                        </li>
                    </div>
                </div>
            </div>

            <!-- Statut -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-medium">Statut</span>
                </label>
                <div class="dropdown dropdown-bottom w-full" x-data="{ openStatus: false }"
                    :class="{ 'dropdown-open': openStatus }">
                    <label tabindex="0" class="btn btn-outline w-full justify-between"
                        @click="openStatus = !openStatus">
                        <span class="flex items-center gap-2">
                            <i class="fa-solid fa-toggle-on"></i>
                            @if (empty($status))
                                Sélectionner des statuts...
                            @else
                                {{ count($status) }} statut(s) sélectionné(s)
                            @endif
                        </span>
                        <i class="fa-solid fa-chevron-down text-xs" :class="{ 'rotate-180': openStatus }"></i>
                    </label>
                    <div tabindex="0"
                        class="dropdown-content z-[100] menu p-2 shadow-lg bg-base-100 rounded-box w-full border border-base-300"
                        @click.away="openStatus = false">
                        <li>
                            <label class="label cursor-pointer justify-start gap-3 p-2">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="status"
                                    value="active" />
                                <span class="label-text">Actifs</span>
                            </label>
                        </li>
                        <li>
                            <label class="label cursor-pointer justify-start gap-3 p-2">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="status"
                                    value="inactive" />
                                <span class="label-text">Inactifs</span>
                            </label>
                        </li>
                        <li>
                            <label class="label cursor-pointer justify-start gap-3 p-2">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="status"
                                    value="trash" />
                                <span class="label-text">Corbeille</span>
                            </label>
                        </li>
                    </div>
                </div>
            </div>

            <!-- Classes -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-medium">Classes</span>
                    <span class="label-text-alt text-xs">
                        @if ($classesLoaded)
                            {{ count($availableClasses) }} disponible(s)
                        @else
                            <span class="loading loading-spinner loading-xs"></span>
                        @endif
                    </span>
                </label>
                <div class="dropdown dropdown-bottom w-full" x-data="{ openClasses: false, searchClass: '' }"
                    :class="{ 'dropdown-open': openClasses }">
                    <label tabindex="0" class="btn btn-outline w-full justify-between"
                        @click="openClasses = !openClasses">
                        <span class="flex items-center gap-2">
                            <i class="fa-solid fa-chalkboard"></i>
                            @if (empty($classes))
                                Sélectionner des classes...
                            @else
                                {{ count($classes) }} classe(s) sélectionnée(s)
                            @endif
                        </span>
                        <i class="fa-solid fa-chevron-down text-xs" :class="{ 'rotate-180': openClasses }"></i>
                    </label>
                    <div tabindex="0"
                        class="dropdown-content z-[1] menu p-2 shadow-lg bg-base-100 rounded-box w-full max-h-60 overflow-y-auto border border-base-300"
                        @click.away="openClasses = false">
                        @if (!$classesLoaded)
                            <li class="disabled">
                                <span class="text-sm opacity-60">
                                    <span class="loading loading-spinner loading-xs"></span>
                                    Chargement...
                                </span>
                            </li>
                        @elseif(count($availableClasses) > 0)
                            <!-- Champ de recherche dans les classes -->
                            <div class="p-2 sticky top-0 bg-base-100 z-10">
                                <input type="text" x-model="searchClass" placeholder="Rechercher une classe..."
                                    class="input input-sm input-bordered w-full" @click.stop />
                            </div>
                            @foreach ($availableClasses as $class)
                                <li
                                    x-show="searchClass === '' || '{{ strtolower($class) }}'.includes(searchClass.toLowerCase())">
                                    <label class="label cursor-pointer justify-start gap-3 p-2">
                                        <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="classes"
                                            value="{{ $class }}" />
                                        <span class="label-text">{{ $class }}</span>
                                    </label>
                                </li>
                            @endforeach
                        @else
                            <li class="disabled">
                                <span class="text-sm opacity-60">
                                    Aucune classe disponible ({{ count($availableClasses) }} chargées)
                                </span>
                            </li>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Groupes -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text font-medium">Groupes</span>
                    <span class="label-text-alt text-xs">
                        @if ($groupsLoaded)
                            {{ count($availableGroups) }} disponible(s)
                        @else
                            <span class="loading loading-spinner loading-xs"></span>
                        @endif
                    </span>
                </label>
                <div class="dropdown dropdown-bottom w-full" x-data="{ openGroups: false, searchGroup: '' }"
                    :class="{ 'dropdown-open': openGroups }">
                    <label tabindex="0" class="btn btn-outline w-full justify-between"
                        @click="openGroups = !openGroups">
                        <span class="flex items-center gap-2">
                            <i class="fa-solid fa-users"></i>
                            @if (empty($group))
                                Sélectionner des groupes...
                            @else
                                {{ count($group) }} groupe(s) sélectionné(s)
                            @endif
                        </span>
                        <i class="fa-solid fa-chevron-down text-xs" :class="{ 'rotate-180': openGroups }"></i>
                    </label>
                    <div tabindex="0"
                        class="dropdown-content z-[100] menu p-2 shadow-lg bg-base-100 rounded-box w-full max-h-60 overflow-y-auto border border-base-300"
                        @click.away="openGroups = false">
                        @if (!$groupsLoaded)
                            <li class="disabled">
                                <span class="text-sm opacity-60">
                                    <span class="loading loading-spinner loading-xs"></span>
                                    Chargement...
                                </span>
                            </li>
                        @elseif(count($availableGroups) > 0)
                            <!-- Champ de recherche dans les groupes -->
                            <div class="p-2 sticky top-0 bg-base-100 z-10">
                                <input type="text" x-model="searchGroup" placeholder="Rechercher un groupe..."
                                    class="input input-sm input-bordered w-full" @click.stop />
                            </div>
                            @foreach ($availableGroups as $groupItem)
                                @php
                                    $groupCn = is_array($groupItem) ? $groupItem['cn'] ?? '' : $groupItem;
                                    $groupName = is_array($groupItem) ? $groupItem['name'] ?? $groupCn : $groupItem;
                                @endphp
                                <li
                                    x-show="searchGroup === '' || '{{ strtolower($groupName) }}'.includes(searchGroup.toLowerCase())">
                                    <label class="label cursor-pointer justify-start gap-3 p-2">
                                        <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="group"
                                            value="{{ $groupCn }}" />
                                        <span class="label-text">{{ $groupName }}</span>
                                    </label>
                                </li>
                            @endforeach
                        @else
                            <li class="disabled">
                                <span class="text-sm opacity-60">
                                    Aucun groupe disponible ({{ count($availableGroups) }} chargés)
                                </span>
                            </li>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Boutons d'action -->
            <div class="col-span-full flex justify-end mt-2 gap-2">
                <button type="button" class="btn btn-ghost" wire:click="resetFilters" wire:loading.attr="disabled"
                    :disabled="!hasAnyCriteria()">
                    <i class="fa-solid fa-eraser"></i>
                    Effacer
                </button>
                <button type="button" class="btn btn-primary" wire:click="performSearch"
                    :disabled="!hasAnyCriteria()">
                    <span wire:loading.remove wire:target="performSearch">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </span>
                    <span wire:loading wire:target="performSearch" class="loading loading-spinner loading-sm"></span>
                    Rechercher
                </button>
            </div>
        </div>
    </div>
</div>
