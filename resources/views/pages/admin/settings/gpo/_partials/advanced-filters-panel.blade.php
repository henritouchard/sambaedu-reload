{{--
    Panneau filtres avancés GPO — Story 16.14 AC2.1-2.3.

    Composant Blade partial inclus dans la SFC index.blade.php.
    Les propriétés référencées ($filterType, $filterOu, etc.) sont des
    propriétés Livewire de la SFC parente.

    Variables attendues depuis le parent :
      - $advancedFiltersCount (int) : nombre de filtres actifs
      - $ouSuggestions (array) : suggestions auto-complete OU
--}}
<div class="collapse collapse-arrow border border-base-300 rounded-lg bg-base-100 shadow-sm"
    data-testid="advanced-filters-panel">

    {{-- Toggle state préservé via checkbox (DaisyUI pattern) --}}
    <input type="checkbox" id="advanced-filters-toggle" class="peer" />

    <div class="collapse-title text-sm font-medium flex items-center gap-2 px-4 py-3 hover:bg-base-200/50 transition-colors">
        <i class="fa-solid fa-sliders text-base-content/60 text-xs"></i>
        <span>Filtres avancés</span>
        @if ($advancedFiltersCount > 0)
            <span class="badge badge-primary badge-sm ml-1" data-testid="active-filters-count">
                {{ $advancedFiltersCount }} actif{{ $advancedFiltersCount > 1 ? 's' : '' }}
            </span>
        @endif
        <div class="flex-1"></div>
        @if ($advancedFiltersCount > 0)
            <button type="button"
                class="btn btn-ghost btn-xs text-base-content/50 hover:text-base-content"
                wire:click.stop="resetAdvancedFilters"
                data-testid="reset-advanced-filters">
                <i class="fa-solid fa-rotate-left text-xs"></i>
                Réinitialiser
            </button>
            <button type="button"
                class="btn btn-ghost btn-xs text-error/70 hover:text-error"
                wire:click.stop="resetAllFilters"
                data-testid="reset-all-filters">
                <i class="fa-solid fa-xmark text-xs"></i>
                Tout effacer
            </button>
        @endif
    </div>

    <div class="collapse-content">
        <div class="pt-1 pb-3 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">

                {{-- Filtre Type (Machine / User / Script logon) --}}
                <div class="form-control">
                    <label class="label py-1">
                        <span class="label-text text-xs font-medium">Type de GPO</span>
                    </label>
                    <select wire:model.live="filterType"
                        class="select select-bordered select-sm w-full"
                        data-testid="filter-type">
                        <option value="">Tous les types</option>
                        <option value="machine">Machine</option>
                        <option value="user">Utilisateur</option>
                        <option value="logon">Script logon</option>
                        <option value="other">Autre</option>
                    </select>
                </div>

                {{-- Story 16.14 Q1 — Filtre Statut santé MULTI-valeur (AC2.2). --}}
                <div class="form-control" data-testid="filter-health-statuses-group">
                    <label class="label py-1">
                        <span class="label-text text-xs font-medium">Statut santé</span>
                    </label>
                    <div class="flex flex-wrap gap-3 items-center pt-1">
                        <label class="label cursor-pointer justify-start gap-2 py-0">
                            <input type="checkbox"
                                wire:model.live="filterHealthStatuses"
                                value="healthy"
                                class="checkbox checkbox-sm checkbox-success"
                                data-testid="filter-health-healthy" />
                            <span class="label-text text-xs">Saine</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-2 py-0">
                            <input type="checkbox"
                                wire:model.live="filterHealthStatuses"
                                value="orphaned"
                                class="checkbox checkbox-sm checkbox-warning"
                                data-testid="filter-health-orphaned" />
                            <span class="label-text text-xs">Orpheline</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-2 py-0">
                            <input type="checkbox"
                                wire:model.live="filterHealthStatuses"
                                value="conflicting"
                                class="checkbox checkbox-sm checkbox-error"
                                data-testid="filter-health-conflicting" />
                            <span class="label-text text-xs">Conflit</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-2 py-0">
                            <input type="checkbox"
                                wire:model.live="filterHealthStatuses"
                                value="stale"
                                class="checkbox checkbox-sm checkbox-ghost"
                                data-testid="filter-health-stale" />
                            <span class="label-text text-xs">Obsolète</span>
                        </label>
                    </div>
                </div>

                {{-- Filtre Sections natives uniquement --}}
                <div class="form-control">
                    <label class="label py-1">
                        <span class="label-text text-xs font-medium">Sections natives</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-3 mt-1">
                        <input type="checkbox"
                            wire:model.live="filterNativeOnly"
                            class="checkbox checkbox-sm checkbox-primary"
                            data-testid="filter-native-only" />
                        <span class="label-text text-sm">Avec sections natives uniquement</span>
                    </label>
                </div>

                {{-- Filtre Version range --}}
                <div class="form-control">
                    <label class="label py-1">
                        <span class="label-text text-xs font-medium">Version (majeure) — plage</span>
                    </label>
                    <div class="flex gap-2 items-center">
                        <input type="number"
                            wire:model.live.debounce.300ms="filterVersionMin"
                            class="input input-bordered input-sm w-full"
                            placeholder="Min"
                            min="0"
                            data-testid="filter-version-min" />
                        <span class="text-base-content/40 text-xs">—</span>
                        <input type="number"
                            wire:model.live.debounce.300ms="filterVersionMax"
                            class="input input-bordered input-sm w-full"
                            placeholder="Max"
                            min="0"
                            data-testid="filter-version-max" />
                    </div>
                </div>

                {{-- Filtre OU liée avec auto-complete --}}
                <div class="form-control md:col-span-2">
                    <label class="label py-1">
                        <span class="label-text text-xs font-medium">OU liée (auto-complete)</span>
                    </label>
                    <div class="relative">
                        <input type="text"
                            wire:model.live.debounce.300ms="filterOuSearch"
                            class="input input-bordered input-sm w-full"
                            placeholder="Rechercher une OU par DN (ex: OU=Salles,DC=...)"
                            data-testid="filter-ou-search" />
                        @if (!empty($ouSuggestions))
                            <ul class="absolute z-20 mt-1 w-full bg-base-100 border border-base-300 rounded-lg shadow-lg max-h-48 overflow-y-auto"
                                data-testid="ou-suggestions">
                                @foreach ($ouSuggestions as $suggestion)
                                    <li>
                                        <button type="button"
                                            class="w-full text-left px-3 py-2 text-xs hover:bg-base-200 transition-colors font-mono truncate"
                                            wire:click="selectFilterOu(@js($suggestion))"
                                            title="{{ $suggestion }}">
                                            {{ $suggestion }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if (!empty($filterOu))
                            <div class="mt-1 flex items-center gap-2">
                                <span class="badge badge-outline badge-sm font-mono text-xs truncate max-w-xs">
                                    {{ $filterOu }}
                                </span>
                                <button type="button"
                                    wire:click="clearFilterOu"
                                    class="btn btn-ghost btn-xs">
                                    <i class="fa-solid fa-xmark text-xs"></i>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
