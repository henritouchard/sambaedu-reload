<?php

declare(strict_types=1);

use App\Services\WorkerMonitoringService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Détail Worker')] class extends Component {
    public string $selectedWorkerId = '';

    // Onglet actif — toujours reflété dans l'URL (?tab=), deep-link supporté.
    #[Url(as: 'tab', keep: true)]
    public string $selectedTab = 'running';

    /** Onglets valides (allow-list du switch). */
    private const TABS = ['running', 'queued', 'done'];

    public string $selectedTaskId = '';

    public function mount(int $pid): void
    {
        $this->selectedWorkerId = 'worker-' . $pid;
    }

    public function selectTab(string $tab): void
    {
        $this->selectedTab = in_array($tab, self::TABS, true) ? $tab : 'running';
        $this->selectedTaskId = '';
    }

    public function selectedWorker(): ?object
    {
        if ($this->selectedWorkerId === '') {
            return null;
        }

        /** @var WorkerMonitoringService $service */
        $service = app(WorkerMonitoringService::class);

        return $service->findWorkerById($this->selectedWorkerId);
    }

    /**
     * @return array<int,object>
     */
    public function tasks(): array
    {
        if ($this->selectedWorkerId === '') {
            return [];
        }

        /** @var WorkerMonitoringService $service */
        $service = app(WorkerMonitoringService::class);

        $tasks = $service->getWorkerTasks($this->selectedWorkerId, $this->selectedTab);
        if ($this->selectedTaskId === '' && count($tasks) > 0) {
            $this->selectedTaskId = (string) $tasks[0]->id;
        }

        return $tasks;
    }

    public function selectTask(string $taskId): void
    {
        $this->selectedTaskId = $taskId;
    }

    public function selectedTaskLogs(): object
    {
        if ($this->selectedWorkerId === '' || $this->selectedTaskId === '') {
            return (object) ['title' => 'Aucune tâche sélectionnée', 'entries' => []];
        }

        /** @var WorkerMonitoringService $service */
        $service = app(WorkerMonitoringService::class);

        return $service->getTaskLogs($this->selectedWorkerId, $this->selectedTab, $this->selectedTaskId);
    }
};
?>

<x-organisms.page title="Détail Worker" description="Suivi des actions et logs d'un worker">
    <x-slot:actions>
        <a href="{{ route('app.workers.index') }}" class="btn btn-outline btn-sm">Retour liste workers</a>
    </x-slot:actions>

    @php
        $worker = $this->selectedWorker();
        $tasks = $this->tasks();
        $taskLogs = $this->selectedTaskLogs();
        $tabs = [
            'running' => 'Running',
            'queued' => 'Queued',
            'done' => 'Done',
        ];
    @endphp

    @if ($worker === null)
        <div class="alert alert-warning">
            <span>Worker introuvable ou terminé.</span>
        </div>
    @else
        <div class="space-y-4">
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body py-4">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="badge badge-outline">Worker #{{ $worker->pid }}</span>
                        <span
                            class="badge {{ $worker->role === 'sync' ? 'badge-warning' : 'badge-info' }}">{{ strtoupper($worker->role) }}</span>
                        <span class="badge badge-ghost">Queues: {{ $worker->queues_label }}</span>
                        <span class="badge badge-ghost">Uptime: {{ $worker->uptime_label }}</span>
                        <span class="badge badge-ghost">{{ strtoupper($selectedTab) }}: {{ count($tasks) }}</span>
                        <span class="badge badge-ghost">Pending: {{ $worker->pending_jobs }}</span>
                        <span class="badge badge-ghost">Failed: {{ $worker->failed_jobs }}</span>
                    </div>
                </div>
            </div>

            @php
                $workerTabs = collect($tabs)->map(fn (string $label): array => ['label' => $label])->all();
            @endphp
            <x-molecules.tabs :tabs="$workerTabs" :active="$selectedTab" action="selectTab" />

            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body p-0 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="table table-zebra w-full">
                            <thead>
                                <tr>
                                    <th>Tâche</th>
                                    <th>Queue</th>
                                    <th>Horodatage</th>
                                    <th class="w-32 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($tasks as $task)
                                    <tr class="{{ $selectedTaskId === $task->id ? 'bg-primary/10' : '' }}">
                                        <td class="font-medium">{{ $task->title }}</td>
                                        <td>{{ $task->queue }}</td>
                                        <td class="text-sm text-base-content/70">{{ $task->timestamp }}</td>
                                        <td class="text-right">
                                            <button type="button"
                                                class="btn btn-xs {{ $selectedTaskId === $task->id ? 'btn-primary' : 'btn-ghost' }}"
                                                wire:click="selectTask('{{ $task->id }}')">
                                                Voir logs
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4">
                                            <div class="alert alert-info my-2">
                                                <span>Aucune tâche {{ $selectedTab }}.</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h2 class="card-title text-xl">{{ $taskLogs->title }}</h2>
                    <p class="text-sm text-base-content/70 mb-4">
                        Worker #{{ $worker->pid }} · {{ strtoupper($selectedTab) }} · Queues
                        {{ $worker->queues_label }}
                    </p>

                    <div class="space-y-2 max-h-[36rem] overflow-auto">
                        @if (count($taskLogs->entries) === 0)
                            <div class="alert alert-info">
                                <span>Aucune donnée de log disponible pour cette tâche.</span>
                            </div>
                        @endif

                        @foreach ($taskLogs->entries as $entry)
                            <div class="rounded-lg border border-base-200 p-3 bg-base-50">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="badge badge-outline">{{ strtoupper($entry->level) }}</span>
                                    <span class="text-xs text-base-content/60">{{ $entry->time }}</span>
                                </div>
                                <pre class="text-xs whitespace-pre-wrap text-base-content/80">{{ $entry->message }}</pre>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-organisms.page>
