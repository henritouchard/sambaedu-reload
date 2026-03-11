<?php

declare(strict_types=1);

use App\Services\WorkerMonitoringService;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Workers - Dashboard')] class extends Component {
    /**
     * @return array<int,object>
     */
    public function workers(): array
    {
        /** @var WorkerMonitoringService $service */
        $service = app(WorkerMonitoringService::class);

        return $service->getWorkers();
    }

    public function getStartCommand(string $role): string
    {
        /** @var WorkerMonitoringService $service */
        $service = app(WorkerMonitoringService::class);

        return $service->getStartInstructions($role);
    }
};
?>

<x-organisms.page title="Workers" description="Suivi détaillé des workers, actions et logs">
    <x-slot:actions>
        <a href="{{ route('app.dashboard') }}" class="btn btn-outline btn-sm">Retour dashboard</a>
    </x-slot:actions>

    @php
        $workers = $this->workers();
        $syncWorkers = array_values(
            array_filter($workers, static fn(object $worker): bool => $worker->role === 'sync'),
        );
        $generalWorkers = array_values(
            array_filter($workers, static fn(object $worker): bool => $worker->role !== 'sync'),
        );
    @endphp

    <div class="space-y-4">
        <div class="alert alert-warning">
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <div class="flex-1">
                <h3 class="font-bold">Gestion des workers (SSH uniquement)</h3>
                <div class="text-sm mt-2 space-y-1">
                    <p><strong>Démarrer worker SYNC :</strong> <code
                            class="bg-base-300 px-2 py-1 rounded">{{ $this->getStartCommand('sync') }}</code></p>
                    <p><strong>Démarrer worker GÉNÉRAL :</strong> <code
                            class="bg-base-300 px-2 py-1 rounded">{{ $this->getStartCommand('general') }}</code></p>
                    <p class="text-xs opacity-70 mt-2">Remplacer <code>start</code> par <code>stop</code> ou
                        <code>restart</code> selon besoin.
                    </p>
                </div>
            </div>
        </div>

        <div class="alert alert-info py-2">
            <span>{{ count($syncWorkers) }} worker(s) sync · {{ count($generalWorkers) }} worker(s) généraux
                actifs</span>
        </div>

        @foreach (['sync' => $syncWorkers, 'general' => $generalWorkers] as $role => $roleWorkers)
            <div class="space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-base-content/70">
                    {{ $role === 'sync' ? 'Workers Sync (longues tâches)' : 'Workers Généraux' }}
                </h3>

                @forelse ($roleWorkers as $worker)
                    <a href="{{ route('app.workers.show', ['pid' => $worker->pid]) }}"
                        class="card bg-base-100 shadow-sm border border-base-200 hover:border-primary transition-colors">
                        <div class="card-body gap-3">
                            <div class="flex items-center justify-between">
                                <h2 class="card-title text-base">Worker #{{ $worker->pid }}</h2>
                                <span
                                    class="badge {{ $worker->role === 'sync' ? 'badge-warning' : 'badge-info' }}">{{ $worker->queues_label }}</span>
                            </div>

                            <div class="text-sm text-base-content/70 grid md:grid-cols-4 gap-2">
                                <div>Type: <span class="font-semibold">{{ $worker->role }}</span></div>
                                <div>Uptime: {{ $worker->uptime_label }}</div>
                                <div>Pending: {{ $worker->pending_jobs }}</div>
                                <div>Failed: {{ $worker->failed_jobs }}</div>
                            </div>

                            <div class="text-xs text-base-content/60">Cliquez pour voir les tâches (done/running/queued)
                                et logs.</div>
                        </div>
                    </a>
                @empty
                    <div class="alert alert-info">
                        <span>Aucun worker dans cette catégorie.</span>
                    </div>
                @endforelse
            </div>
        @endforeach
    </div>
</x-organisms.page>
