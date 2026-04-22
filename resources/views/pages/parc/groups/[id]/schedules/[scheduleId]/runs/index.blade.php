<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\WithPagination;
use App\Models\WorkstationGroup;
use App\Models\WorkstationGroupSchedule;
use App\Models\WorkstationGroupScheduleRun;
use App\Models\MachinePowerActionTask;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Log;

new #[Title("Historique d'exécution — SE4FS")] class extends Component {
    use WithToasts;
    use WithPagination;

    public int $id;
    public int $scheduleId;
    public ?WorkstationGroup $group = null;
    public ?WorkstationGroupSchedule $schedule = null;

    public function mount(string|int $id, string|int $scheduleId): void
    {
        $this->id = (int) $id;
        $this->scheduleId = (int) $scheduleId;

        $this->group = WorkstationGroup::find($this->id);
        $this->schedule = WorkstationGroupSchedule::with('workstationGroup')->find($this->scheduleId);

        if (!$this->group || !$this->schedule || $this->schedule->workstation_group_id !== $this->id) {
            Log::warning('[ScheduleRunsPage] schedule/group mismatch', [
                'group_id' => $this->id,
                'schedule_id' => $this->scheduleId,
            ]);
            $this->toastError('Programmation introuvable pour ce groupe.');
            $this->redirect(route('app.parc.groups.show', ['id' => $this->id]));
        }
    }

    public function getRunsProperty()
    {
        return WorkstationGroupScheduleRun::where('schedule_id', $this->scheduleId)
            ->orderByDesc('ran_at')
            ->paginate(20);
    }

    public function getTasksByIdProperty(): array
    {
        $taskIds = [];
        foreach ($this->runs as $run) {
            $ids = $run->summary['task_ids'] ?? [];
            if (is_array($ids)) {
                $taskIds = array_merge($taskIds, $ids);
            }
        }
        if (empty($taskIds)) {
            return [];
        }

        return MachinePowerActionTask::whereIn('id', array_values(array_unique($taskIds)))
            ->get()
            ->keyBy('id')
            ->all();
    }
};

?>

<x-organisms.page
    title="Historique d'exécution"
    :scrollable="true"
    description="Runs enregistrés pour cette programmation (rétention 30 jours)"
    backUrl="{{ route('app.parc.groups.show', ['id' => $id]) }}"
    backText="Retour au groupe">

    @if ($schedule && $group)
        @php
            $daysLabels = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];
            $actionLabels = [
                'wake' => ['label' => 'Allumage', 'icon' => 'fa-power-off', 'class' => 'badge-success'],
                'shutdown' => ['label' => 'Extinction', 'icon' => 'fa-stop', 'class' => 'badge-warning'],
            ];
            $action = $actionLabels[$schedule->action] ?? ['label' => $schedule->action, 'icon' => 'fa-circle', 'class' => 'badge-neutral'];
        @endphp

        {{-- Métadonnées du schedule --}}
        <div class="card bg-base-100 shadow-sm border border-base-200 mb-4">
            <div class="card-body py-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <div class="text-xs text-base-content/60 mb-1">Groupe</div>
                        <div class="font-semibold">
                            <i class="fa-solid fa-layer-group mr-1"></i>
                            {{ $group->name }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/60 mb-1">Action</div>
                        <span class="badge {{ $action['class'] }} gap-1">
                            <i class="fa-solid {{ $action['icon'] }} text-xs"></i>
                            {{ $action['label'] }}
                        </span>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/60 mb-1">Type</div>
                        @if ($schedule->isOneShot())
                            <span class="badge badge-accent gap-1">
                                <i class="fa-regular fa-calendar-day text-xs"></i>
                                Date unique
                            </span>
                        @else
                            <span class="badge badge-info gap-1">
                                <i class="fa-solid fa-repeat text-xs"></i>
                                Récurrent
                            </span>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs text-base-content/60 mb-1">Déclenchement</div>
                        <div class="text-sm">
                            @if ($schedule->isOneShot())
                                {{ $schedule->run_at?->translatedFormat('d M Y à H:i') }}
                            @else
                                @php
                                    $days = $schedule->days_of_week ?? [];
                                    sort($days);
                                @endphp
                                {{ implode(' ', array_map(fn ($d) => $daysLabels[$d] ?? $d, $days)) }}
                                · {{ $schedule->time_of_day?->format('H:i') }}
                                @if ($schedule->timezone)
                                    <span class="text-base-content/60">· {{ $schedule->timezone }}</span>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/60 mb-1">Statut</div>
                        @if ($schedule->isOneShot() && $schedule->isCompleted())
                            <span class="badge badge-ghost gap-1">
                                <i class="fa-solid fa-check text-xs"></i>
                                Exécuté
                            </span>
                        @elseif ($schedule->enabled)
                            <span class="badge badge-success">Activée</span>
                        @else
                            <span class="badge badge-ghost">Désactivée</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Liste des runs --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <h3 class="font-semibold text-lg mb-3 flex items-center gap-2">
                    <i class="fa-regular fa-clock-rotate-left text-primary"></i>
                    Historique
                    <span class="badge badge-neutral badge-sm">{{ $this->runs->total() }}</span>
                </h3>

                @if ($this->runs->isEmpty())
                    <div class="text-center py-12 text-base-content/50">
                        <i class="fa-regular fa-calendar-xmark text-3xl mb-2 block"></i>
                        <p class="text-sm">Aucun run enregistré pour cette programmation.</p>
                        <p class="text-xs mt-1">Les runs apparaîtront ici après la prochaine exécution (rétention 30 jours).</p>
                    </div>
                @else
                    @php
                        $tasksById = $this->tasksById;
                    @endphp
                    <div class="space-y-3">
                        @foreach ($this->runs as $run)
                            @php
                                $summary = $run->summary ?? [];
                                $successCount = (int) ($summary['success_count'] ?? 0);
                                $failedCount = (int) ($summary['failed_count'] ?? 0);
                                $skippedCount = (int) ($summary['skipped_count'] ?? 0);
                                $taskIds = (array) ($summary['task_ids'] ?? []);
                                $errors = (array) ($summary['errors'] ?? []);
                                $drift = $summary['drift_seconds'] ?? null;
                                $rowColor = $failedCount > 0 ? 'border-error/30' : ($successCount > 0 ? 'border-success/30' : 'border-base-300');
                            @endphp
                            <div class="collapse collapse-arrow bg-base-200/40 border {{ $rowColor }}" wire:key="run-{{ $run->id }}">
                                <input type="checkbox" />
                                <div class="collapse-title flex items-center justify-between flex-wrap gap-2 pr-12">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <span class="font-mono text-sm">
                                            {{ $run->ran_at->translatedFormat('d M Y H:i:s') }}
                                        </span>
                                        <span class="text-xs text-base-content/60">
                                            (créneau {{ \Carbon\Carbon::parse($run->ran_for_time)->format('H:i') }})
                                        </span>
                                        @if ($drift !== null && (int) $drift > 60)
                                            <span class="badge badge-warning badge-sm gap-1" title="Ce one-shot a été rattrapé après downtime">
                                                <i class="fa-solid fa-clock-rotate-left text-xs"></i>
                                                Rattrapé (+{{ (int) $drift }}s)
                                            </span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2">
                                        @if ($successCount > 0)
                                            <span class="badge badge-success badge-sm">{{ $successCount }} OK</span>
                                        @endif
                                        @if ($failedCount > 0)
                                            <span class="badge badge-error badge-sm">{{ $failedCount }} échec{{ $failedCount > 1 ? 's' : '' }}</span>
                                        @endif
                                        @if ($skippedCount > 0)
                                            <span class="badge badge-ghost badge-sm">{{ $skippedCount }} ignorée{{ $skippedCount > 1 ? 's' : '' }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="collapse-content">
                                    {{-- Erreurs machine --}}
                                    @if (!empty($errors))
                                        <div class="mt-2 mb-3">
                                            <p class="text-xs font-semibold text-error uppercase mb-1">Échecs</p>
                                            <ul class="text-sm space-y-1">
                                                @foreach ($errors as $err)
                                                    <li class="flex items-start gap-2">
                                                        <i class="fa-solid fa-circle-exclamation text-error mt-0.5"></i>
                                                        <span>
                                                            <span class="font-medium">{{ $err['machine_name'] ?? 'machine #' . ($err['machine_id'] ?? '?') }}</span>
                                                            <span class="text-base-content/70">— {{ $err['error_message'] ?? '' }}</span>
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    {{-- Tâches détaillées --}}
                                    @if (!empty($taskIds))
                                        <div class="mt-2">
                                            <p class="text-xs font-semibold uppercase mb-1 text-base-content/70">Tâches déclenchées</p>
                                            <div class="overflow-x-auto">
                                                <table class="table table-xs">
                                                    <thead>
                                                        <tr>
                                                            <th>Task #</th>
                                                            <th>Machine</th>
                                                            <th>Statut</th>
                                                            <th>Mise à jour</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($taskIds as $tid)
                                                            @php $task = $tasksById[$tid] ?? null; @endphp
                                                            <tr>
                                                                <td class="font-mono">#{{ $tid }}</td>
                                                                <td>
                                                                    @if ($task)
                                                                        {{ $task->workstation_id ? '#' . $task->workstation_id : '—' }}
                                                                    @else
                                                                        <span class="text-base-content/50">—</span>
                                                                    @endif
                                                                </td>
                                                                <td>
                                                                    @if ($task)
                                                                        <span class="badge badge-sm">{{ $task->status }}</span>
                                                                    @else
                                                                        <span class="text-xs text-base-content/50">supprimée</span>
                                                                    @endif
                                                                </td>
                                                                <td class="text-xs text-base-content/70">
                                                                    {{ $task?->updated_at?->diffForHumans() ?? '—' }}
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4">
                        {{ $this->runs->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-organisms.page>
