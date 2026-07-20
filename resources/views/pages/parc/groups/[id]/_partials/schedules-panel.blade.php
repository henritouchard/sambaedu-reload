@php
    $schedules = $this->schedules;
    $schedulesCount = $schedules->count();
    $activeSchedulesCount = $schedules->where('enabled', true)->count();
    $completedOneShotCount = $schedules->filter(fn($s) => $s->isOneShot() && $s->isCompleted())->count();

    $timezoneLabels = \App\Models\WorkstationGroupSchedule::timezoneLabels();
    $daysLabels = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];

    $formatDaysBadge = function (?array $days) use ($daysLabels) {
        if (!$days) {
            return '—';
        }
        sort($days);
        // Détection pattern Lun-Ven
        if ($days === [1, 2, 3, 4, 5]) {
            return 'Lun–Ven';
        }
        if ($days === [6, 7]) {
            return 'Week-end';
        }
        if ($days === [1, 2, 3, 4, 5, 6, 7]) {
            return 'Tous les jours';
        }
        return implode(' ', array_map(fn($d) => $daysLabels[$d] ?? $d, $days));
    };

    $actionLabels = [
        'wake' => ['label' => 'Allumage', 'icon' => 'fa-power-off', 'class' => 'badge-success'],
        'shutdown' => ['label' => 'Extinction', 'icon' => 'fa-stop', 'class' => 'badge-warning'],
    ];
@endphp

@if ($schedulesCount > 0)
<div class="card bg-base-100 shadow-sm border border-base-300 mb-6">
    <div class="card-body">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="font-semibold text-lg flex items-center gap-2">
                    <i class="fa-solid fa-calendar-day text-primary"></i>
                    Actions Programmées
                    <span class="badge badge-neutral badge-sm">{{ $schedulesCount }}</span>
                </h3>
                <p class="text-xs text-base-content/60 mt-1">
                    {{ $activeSchedulesCount }} active{{ $activeSchedulesCount > 1 ? 's' : '' }}
                    @if ($completedOneShotCount > 0)
                        · {{ $completedOneShotCount }} one-shot terminé{{ $completedOneShotCount > 1 ? 's' : '' }}
                    @endif
                </p>
            </div>

            @can('computer.control')
                <button type="button"
                    wire:click="openScheduleModal"
                    class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-plus"></i>
                    Ajouter une programmation
                </button>
            @endcan
        </div>

        <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr class="text-xs uppercase">
                            <th>Type</th>
                            <th>Action</th>
                            <th>Déclenchement</th>
                            <th>Statut</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($schedules as $schedule)
                            @php
                                $actionMeta = $actionLabels[$schedule->action] ?? ['label' => $schedule->action, 'icon' => 'fa-circle', 'class' => 'badge-neutral'];
                                $isCompletedOneShot = $schedule->isOneShot() && $schedule->isCompleted();
                                $rowClass = !$schedule->enabled ? 'opacity-50' : '';
                            @endphp
                            <tr class="{{ $rowClass }}" wire:key="schedule-row-{{ $schedule->id }}">
                                <td>
                                    @if ($isCompletedOneShot)
                                        <span class="badge badge-ghost gap-1" title="One-shot terminé">
                                            <i class="fa-solid fa-check text-xs"></i>
                                            Terminé
                                        </span>
                                    @elseif ($schedule->isOneShot())
                                        <span class="badge badge-accent gap-1" title="Date unique — s'exécute une seule fois">
                                            <i class="fa-solid fa-calendar-day text-xs"></i>
                                            Date unique
                                        </span>
                                    @else
                                        <span class="badge badge-info gap-1" title="Programmation récurrente">
                                            <i class="fa-solid fa-repeat text-xs"></i>
                                            Récurrent
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $actionMeta['class'] }} gap-1">
                                        <i class="fa-solid {{ $actionMeta['icon'] }} text-xs"></i>
                                        {{ $actionMeta['label'] }}
                                    </span>
                                </td>
                                <td class="text-sm">
                                    @if ($schedule->isOneShot())
                                        @if ($isCompletedOneShot)
                                            Exécuté le {{ $schedule->completed_at?->translatedFormat('d M Y à H:i') }}
                                        @else
                                            {{ $schedule->run_at?->translatedFormat('d M Y à H:i') }}
                                        @endif
                                    @else
                                        <div class="flex flex-col">
                                            <span class="font-medium">
                                                {{ $formatDaysBadge($schedule->days_of_week) }}
                                            </span>
                                            <span class="text-xs text-base-content/60">
                                                {{ $schedule->time_of_day?->format('H:i') }}
                                                @if ($schedule->timezone && $schedule->timezone !== config('app.timezone'))
                                                    · {{ $schedule->timezone }}
                                                @endif
                                            </span>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if ($isCompletedOneShot)
                                        <span class="text-xs text-base-content/50">—</span>
                                    @else
                                        @can('computer.control')
                                            <input type="checkbox"
                                                class="toggle toggle-primary toggle-sm"
                                                @checked($schedule->enabled)
                                                wire:click="toggleSchedule({{ $schedule->id }})"
                                                wire:key="schedule-toggle-{{ $schedule->id }}"
                                                aria-label="Activer ou désactiver" />
                                        @else
                                            <span class="text-xs">
                                                {{ $schedule->enabled ? 'Activée' : 'Désactivée' }}
                                            </span>
                                        @endcan
                                    @endif
                                </td>
                                <td class="text-right">
                                    <div class="flex gap-1 justify-end">
                                        <a href="{{ route('app.parc.groups.schedules.runs', ['id' => $group->id, 'scheduleId' => $schedule->id]) }}"
                                            class="btn btn-ghost btn-xs"
                                            title="Historique d'exécution">
                                            <i class="fa-solid fa-clock-rotate-left"></i>
                                        </a>
                                        @can('computer.control')
                                            @if ($schedule->isEditable())
                                                <button type="button"
                                                    wire:click="openScheduleModal({{ $schedule->id }})"
                                                    class="btn btn-ghost btn-xs"
                                                    title="Modifier">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                            @endif
                                            @if ($schedule->isOneShot())
                                                <button type="button"
                                                    wire:click="cloneOneShot({{ $schedule->id }})"
                                                    class="btn btn-ghost btn-xs"
                                                    title="Dupliquer en nouveau one-shot">
                                                    <i class="fa-solid fa-copy"></i>
                                                </button>
                                            @endif
                                            <button type="button"
                                                wire:click="deleteSchedule({{ $schedule->id }})"
                                                wire:confirm="Supprimer cette programmation ?"
                                                class="btn btn-ghost btn-xs text-error"
                                                title="Supprimer">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
        </div>
    </div>
</div>
@endif

{{-- Modale de création / édition (AC1, AC7, AC18) --}}
@if ($scheduleModalOpen)
    <div class="modal modal-open" wire:key="schedule-modal">
        <div class="modal-box max-w-2xl">
            <h3 class="font-bold text-lg mb-4">
                <i class="fa-regular fa-calendar-plus text-primary mr-2"></i>
                {{ $editingScheduleId ? 'Modifier la programmation' : 'Nouvelle programmation' }}
            </h3>

            <form wire:submit.prevent="saveSchedule" class="space-y-4">
                {{-- Toggle mode récurrent / one-shot (D7) --}}
                <div class="flex gap-2 p-1 bg-base-200 rounded-lg" role="group" aria-label="Type de programmation">
                    <button type="button"
                        wire:click="toggleFormMode('recurring')"
                        class="flex-1 btn btn-sm {{ $formMode === 'recurring' ? 'btn-primary' : 'btn-ghost' }}">
                        <i class="fa-solid fa-repeat"></i>
                        Récurrent
                    </button>
                    <button type="button"
                        wire:click="toggleFormMode('one_shot')"
                        class="flex-1 btn btn-sm {{ $formMode === 'one_shot' ? 'btn-primary' : 'btn-ghost' }}">
                        <i class="fa-regular fa-calendar-day"></i>
                        Date unique
                    </button>
                </div>

                {{-- Action (commun aux 2 modes) --}}
                <div>
                    <label class="label">
                        <span class="label-text font-medium">Action</span>
                    </label>
                    <div class="flex gap-2">
                        <label class="flex items-center gap-2 cursor-pointer flex-1 p-3 rounded-lg border border-base-300 hover:border-primary transition-colors {{ $formAction === 'wake' ? 'border-primary bg-primary/5' : '' }}">
                            <input type="radio" wire:model="formAction" value="wake" class="radio radio-primary radio-sm" />
                            <i class="fa-solid fa-power-off text-success"></i>
                            <span class="text-sm font-medium">Allumage</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer flex-1 p-3 rounded-lg border border-base-300 hover:border-primary transition-colors {{ $formAction === 'shutdown' ? 'border-primary bg-primary/5' : '' }}">
                            <input type="radio" wire:model="formAction" value="shutdown" class="radio radio-primary radio-sm" />
                            <i class="fa-solid fa-stop text-warning"></i>
                            <span class="text-sm font-medium">Extinction</span>
                        </label>
                    </div>
                </div>

                {{-- Section récurrent --}}
                @if ($formMode === 'recurring')
                    <div>
                        <label class="label">
                            <span class="label-text font-medium">Jours de la semaine</span>
                        </label>
                        <div class="flex gap-1 flex-wrap">
                            @foreach ([1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'] as $day => $label)
                                <button type="button"
                                    wire:click="toggleDay({{ $day }})"
                                    class="px-4 py-2 rounded-lg border transition-colors text-sm font-medium
                                        {{ in_array($day, $formDaysOfWeek) ? 'bg-primary text-primary-content border-primary' : 'border-base-300 hover:border-primary' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                        @error('formDaysOfWeek')
                            <p class="text-xs text-error mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Heure</span>
                            </label>
                            <input type="text"
                                wire:model="formTimeOfDay"
                                placeholder="HH:MM"
                                maxlength="5"
                                class="input input-bordered w-full" />
                            @error('formTimeOfDay')
                                <p class="text-xs text-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Fuseau horaire</span>
                            </label>
                            <select wire:model="formTimezone" class="select select-bordered w-full">
                                @foreach ($timezoneLabels as $tz => $label)
                                    <option value="{{ $tz }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('formTimezone')
                                <p class="text-xs text-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif

                {{-- Section one-shot --}}
                @if ($formMode === 'one_shot')
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Date</span>
                            </label>
                            <input type="text"
                                wire:model="formRunAtDate"
                                placeholder="JJ/MM/AAAA"
                                maxlength="10"
                                class="input input-bordered w-full" />
                            @error('formRunAtDate')
                                <p class="text-xs text-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="label">
                                <span class="label-text font-medium">Heure</span>
                            </label>
                            <input type="text"
                                wire:model="formRunAtTime"
                                placeholder="HH:MM"
                                maxlength="5"
                                class="input input-bordered w-full" />
                            @error('formRunAtTime')
                                <p class="text-xs text-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <p class="text-xs text-base-content/60 mt-1">
                        <i class="fa-solid fa-circle-info"></i>
                        L'action sera exécutée une seule fois à cette date/heure, puis la programmation sera automatiquement désactivée.
                    </p>
                @endif

                {{-- Toggle enabled --}}
                <div class="flex items-center justify-between pt-2 border-t border-base-300">
                    <label class="label cursor-pointer gap-3">
                        <input type="checkbox"
                            wire:model="formEnabled"
                            class="toggle toggle-primary toggle-sm" />
                        <span class="label-text font-medium">Programmation activée</span>
                    </label>
                </div>

                <div class="modal-action">
                    <button type="button"
                        wire:click="closeScheduleModal"
                        class="btn btn-ghost">
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-save"></i>
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
        <div class="modal-backdrop" wire:click="closeScheduleModal"></div>
    </div>
@endif
