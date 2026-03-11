<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkerMonitoringService
{
    private const SYNC_QUEUE = 'sync';
    private const GENERAL_QUEUES = ['default', 'high', 'low'];
    private const SYSTEMD_SERVICE_GENERAL = 'laravel-queue-general';
    private const SYSTEMD_SERVICE_SYNC = 'laravel-queue-sync';

    /**
     * @return array<int,object>
     */
    public function getWorkers(): array
    {
        $rows = $this->readQueueWorkerProcesses();

        $workers = [];
        foreach ($rows as $row) {
            $queues = $this->extractQueueNames($row->command);
            $pendingJobs = $this->countPendingJobs($queues);
            $failedJobs = $this->countFailedJobs($queues);
            $role = $this->detectWorkerRole($queues);

            $workers[] = (object) [
                'id' => 'worker-' . $row->pid,
                'pid' => $row->pid,
                'queue' => $queues[0] ?? 'default',
                'queues' => $queues,
                'queues_label' => implode(', ', $queues),
                'role' => $role,
                'uptime_seconds' => $row->uptime_seconds,
                'uptime_label' => $this->formatDuration($row->uptime_seconds),
                'command' => $row->command,
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'actions' => [
                    (object) ['id' => 'runtime', 'label' => 'Runtime', 'description' => 'État du process worker'],
                    (object) ['id' => 'pending-jobs', 'label' => 'Jobs en attente', 'description' => "{$pendingJobs} job(s) en file sur {$this->formatQueueList($queues)}"],
                    (object) ['id' => 'failed-jobs', 'label' => 'Jobs échoués', 'description' => "{$failedJobs} job(s) en échec sur {$this->formatQueueList($queues)}"],
                    (object) ['id' => 'system-logs', 'label' => 'Logs système', 'description' => 'Entrées récentes liées aux queues'],
                ],
            ];
        }

        return $workers;
    }


    public function findWorkerById(string $workerId): ?object
    {
        foreach ($this->getWorkers() as $worker) {
            if ($worker->id === $workerId) {
                return $worker;
            }
        }

        return null;
    }

    public function getWorkerActionDetails(string $workerId, string $actionId): object
    {
        $worker = $this->findWorkerById($workerId);

        if ($worker === null) {
            return (object) [
                'title' => 'Worker introuvable',
                'entries' => [],
            ];
        }

        return match ($actionId) {
            'pending-jobs' => $this->buildPendingJobsDetails($worker),
            'failed-jobs' => $this->buildFailedJobsDetails($worker),
            'system-logs' => $this->buildSystemLogsDetails($worker),
            default => $this->buildRuntimeDetails($worker),
        };
    }

    /**
     * Retourne les instructions pour démarrer manuellement un worker
     */
    public function getStartInstructions(string $role): string
    {
        $serviceName = match ($role) {
            'sync' => self::SYSTEMD_SERVICE_SYNC,
            'general' => self::SYSTEMD_SERVICE_GENERAL,
            default => null,
        };

        if ($serviceName === null) {
            return 'Service inconnu';
        }

        return "sudo systemctl start {$serviceName}";
    }


    /**
     * @return array<int,object>
     */
    public function getWorkerTasks(string $workerId, string $status): array
    {
        $worker = $this->findWorkerById($workerId);
        if ($worker === null) {
            return [];
        }

        return match ($status) {
            'queued' => $this->getQueuedTasks($worker),
            'running' => $this->getRunningTasks($worker),
            'done' => $this->getDoneTasks($worker),
            default => [],
        };
    }

    public function getTaskLogs(string $workerId, string $status, string $taskId): object
    {
        $tasks = $this->getWorkerTasks($workerId, $status);

        foreach ($tasks as $task) {
            if ($task->id === $taskId) {
                return (object) [
                    'title' => $task->title,
                    'entries' => $task->logs,
                ];
            }
        }

        return (object) [
            'title' => 'Tâche introuvable',
            'entries' => [],
        ];
    }

    /**
     * @return array<int,object>
     */
    private function readQueueWorkerProcesses(): array
    {
        $output = shell_exec("ps -eo pid,etimes,command | grep 'artisan queue:work' | grep -v grep");
        $lines = array_filter(array_map('trim', explode("\n", (string) ($output ?? ''))));

        $workers = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line, 3);
            if (!is_array($parts) || count($parts) < 3) {
                continue;
            }

            $workers[] = (object) [
                'pid' => (int) $parts[0],
                'uptime_seconds' => (int) $parts[1],
                'command' => (string) $parts[2],
            ];
        }

        return $workers;
    }

    /**
     * @return array<int,string>
     */
    private function extractQueueNames(string $command): array
    {
        if (preg_match('/--queue(?:=|\s+)([^\s]+)/', $command, $matches) === 1) {
            $rawQueues = explode(',', trim((string) $matches[1]));

            $queues = array_values(array_filter(array_map(
                static fn(string $queue): string => trim($queue),
                $rawQueues,
            ), static fn(string $queue): bool => $queue !== ''));

            if (count($queues) > 0) {
                return $queues;
            }
        }

        return ['default'];
    }

    /**
     * @param array<int,string> $queues
     */
    private function countPendingJobs(array $queues): int
    {
        if (!Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs');
        if (Schema::hasColumn('jobs', 'queue') && count($queues) > 0) {
            $query->whereIn('queue', $queues);
        }

        return (int) $query->count();
    }

    /**
     * @param array<int,string> $queues
     */
    private function countFailedJobs(array $queues): int
    {
        if (!Schema::hasTable('failed_jobs')) {
            return 0;
        }

        $query = DB::table('failed_jobs');
        if (Schema::hasColumn('failed_jobs', 'queue') && count($queues) > 0) {
            $query->whereIn('queue', $queues);
        }

        return (int) $query->count();
    }

    private function buildRuntimeDetails(object $worker): object
    {
        return (object) [
            'title' => 'Runtime worker',
            'entries' => [
                (object) ['time' => now()->toDateTimeString(), 'level' => 'info', 'message' => "Worker PID {$worker->pid} actif depuis {$worker->uptime_label}"],
                (object) ['time' => now()->toDateTimeString(), 'level' => 'info', 'message' => "Queues écoutées: {$worker->queues_label}"],
                (object) ['time' => now()->toDateTimeString(), 'level' => 'info', 'message' => "Type de worker: {$worker->role}"],
                (object) ['time' => now()->toDateTimeString(), 'level' => 'debug', 'message' => "Commande: {$worker->command}"],
            ],
        ];
    }

    private function buildPendingJobsDetails(object $worker): object
    {
        if (!Schema::hasTable('jobs')) {
            return (object) ['title' => 'Jobs en attente', 'entries' => []];
        }

        $query = DB::table('jobs')->orderByDesc('id')->limit(25);
        if (Schema::hasColumn('jobs', 'queue') && is_array($worker->queues) && count($worker->queues) > 0) {
            $query->whereIn('queue', $worker->queues);
        }

        $rows = $query->get();

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = (object) [
                'time' => isset($row->created_at) ? (string) $row->created_at : 'n/a',
                'level' => 'info',
                'message' => sprintf(
                    'Job #%s | queue=%s | attempts=%s',
                    (string) ($row->id ?? 'n/a'),
                    (string) ($row->queue ?? $worker->queue),
                    (string) ($row->attempts ?? 0),
                ),
            ];
        }

        return (object) ['title' => 'Jobs en attente', 'entries' => $entries];
    }

    private function buildFailedJobsDetails(object $worker): object
    {
        if (!Schema::hasTable('failed_jobs')) {
            return (object) ['title' => 'Jobs échoués', 'entries' => []];
        }

        $query = DB::table('failed_jobs')->orderByDesc('id')->limit(25);
        if (Schema::hasColumn('failed_jobs', 'queue') && is_array($worker->queues) && count($worker->queues) > 0) {
            $query->whereIn('queue', $worker->queues);
        }

        $rows = $query->get();

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = (object) [
                'time' => isset($row->failed_at) ? (string) $row->failed_at : 'n/a',
                'level' => 'error',
                'message' => sprintf(
                    'Job #%s | queue=%s | exception=%s',
                    (string) ($row->id ?? 'n/a'),
                    (string) ($row->queue ?? $worker->queue),
                    mb_substr((string) ($row->exception ?? ''), 0, 140),
                ),
            ];
        }

        return (object) ['title' => 'Jobs échoués', 'entries' => $entries];
    }

    private function buildSystemLogsDetails(object $worker): object
    {
        $serviceName = $this->detectSystemdServiceFromQueues($worker->queues);
        
        if ($serviceName === null) {
            return (object) ['title' => 'Logs système', 'entries' => []];
        }

        $output = shell_exec('sudo journalctl -u ' . escapeshellarg($serviceName) . ' -n 50 --no-pager 2>&1');
        
        if ($output === null || trim($output) === '') {
            return (object) ['title' => 'Logs système', 'entries' => []];
        }

        $lines = explode("\n", $output);
        $entries = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\w+\s+\d+\s+\d+:\d+:\d+)\s+/', $line, $matches)) {
                $time = $matches[1];
                $message = substr($line, strlen($matches[0]));
            } else {
                $time = now()->toDateTimeString();
                $message = $line;
            }

            $level = 'log';
            if (stripos($message, 'error') !== false || stripos($message, 'failed') !== false) {
                $level = 'error';
            } elseif (stripos($message, 'warning') !== false) {
                $level = 'warning';
            } elseif (stripos($message, 'processing') !== false || stripos($message, 'processed') !== false) {
                $level = 'info';
            }

            $entries[] = (object) [
                'time' => $time,
                'level' => $level,
                'message' => $message,
            ];
        }

        return (object) ['title' => 'Logs système (journalctl)', 'entries' => $entries];
    }

    private function detectSystemdServiceFromQueues(array $queues): ?string
    {
        $role = $this->detectWorkerRole($queues);
        
        return match ($role) {
            'sync' => self::SYSTEMD_SERVICE_SYNC,
            'general' => self::SYSTEMD_SERVICE_GENERAL,
            default => null,
        };
    }

    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02dh %02dm %02ds', $hours, $minutes, $remainingSeconds);
    }

    /**
     * @return array<int,string>
     */
    private function configuredGeneralQueues(): array
    {
        $configured = config('sambaedu.workers.general_queues', self::GENERAL_QUEUES);

        if (!is_array($configured)) {
            return self::GENERAL_QUEUES;
        }

        $queues = array_values(array_filter(array_map(
            static fn(mixed $queue): string => trim((string) $queue),
            $configured,
        ), static fn(string $queue): bool => $queue !== '' && $queue !== self::SYNC_QUEUE));

        return count($queues) > 0 ? $queues : self::GENERAL_QUEUES;
    }

    /**
     * @param array<int,string> $queues
     */
    private function detectWorkerRole(array $queues): string
    {
        foreach ($queues as $queue) {
            $normalizedQueue = mb_strtolower(trim($queue));
            if ($normalizedQueue === 'sync' || str_contains($normalizedQueue, 'sync')) {
                return 'sync';
            }
        }

        return 'general';
    }

    /**
     * @param array<int,string> $queues
     */
    private function formatQueueList(array $queues): string
    {
        return implode(', ', $queues);
    }

    /**
     * @return array<int,object>
     */
    private function getQueuedTasks(object $worker): array
    {
        if (!Schema::hasTable('jobs')) {
            return [];
        }

        $query = DB::table('jobs')->orderByDesc('id')->limit(100);
        if (Schema::hasColumn('jobs', 'queue')) {
            $query->whereIn('queue', $worker->queues);
        }
        if (Schema::hasColumn('jobs', 'reserved_at')) {
            $query->whereNull('reserved_at');
        }

        $rows = $query->get();

        $tasks = [];
        foreach ($rows as $row) {
            $payloadData = $this->decodePayload((string) ($row->payload ?? ''));
            $taskLabel = $this->resolveTaskLabel($payloadData);
            $taskId = 'queued-' . (string) ($row->id ?? uniqid('job-', true));

            $tasks[] = (object) [
                'id' => $taskId,
                'title' => $taskLabel,
                'status' => 'queued',
                'queue' => (string) ($row->queue ?? $worker->queue),
                'timestamp' => isset($row->created_at) ? (string) $row->created_at : 'n/a',
                'logs' => [
                    (object) [
                        'time' => isset($row->created_at) ? (string) $row->created_at : now()->toDateTimeString(),
                        'level' => 'info',
                        'message' => 'Tâche en attente d\'exécution',
                    ],
                ],
            ];
        }

        return $tasks;
    }

    /**
     * @return array<int,object>
     */
    private function getRunningTasks(object $worker): array
    {
        if (!Schema::hasTable('queue_task_runs')) {
            return [];
        }

        $rows = DB::table('queue_task_runs')
            ->whereIn('queue', $worker->queues)
            ->where('status', 'running')
            ->orderByDesc('started_at')
            ->limit(100)
            ->get();

        return $this->mapTrackedRowsToTasks($rows, 'running');
    }

    /**
     * @return array<int,object>
     */
    private function getDoneTasks(object $worker): array
    {
        if (!Schema::hasTable('queue_task_runs')) {
            return [];
        }

        $rows = DB::table('queue_task_runs')
            ->whereIn('queue', $worker->queues)
            ->where('status', 'done')
            ->orderByDesc('finished_at')
            ->limit(100)
            ->get();

        return $this->mapTrackedRowsToTasks($rows, 'done');
    }

    /**
     * @param \Illuminate\Support\Collection<int,object> $rows
     * @return array<int,object>
     */
    private function mapTrackedRowsToTasks(\Illuminate\Support\Collection $rows, string $status): array
    {
        $tasks = [];
        foreach ($rows as $row) {
            $logs = $this->extractTrackedLogs((string) ($row->log_lines ?? ''));

            if (count($logs) === 0) {
                $logs[] = (object) [
                    'time' => (string) ($row->updated_at ?? now()->toDateTimeString()),
                    'level' => 'info',
                    'message' => $status === 'done' ? 'Tâche terminée' : 'Tâche en cours',
                ];
            }

            $tasks[] = (object) [
                'id' => 'tracked-' . (string) ($row->task_uuid ?? $row->id),
                'title' => (string) ($row->job_name ?? 'Job'),
                'status' => $status,
                'queue' => (string) ($row->queue ?? 'default'),
                'timestamp' => (string) ($row->started_at ?? $row->created_at ?? 'n/a'),
                'logs' => $logs,
            ];
        }

        return $tasks;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodePayload(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $payloadData
     */
    private function resolveTaskLabel(array $payloadData): string
    {
        $displayName = $payloadData['displayName'] ?? null;
        if (is_string($displayName) && trim($displayName) !== '') {
            return trim($displayName);
        }

        $job = $payloadData['job'] ?? null;
        if (is_string($job) && trim($job) !== '') {
            return trim($job);
        }

        return 'Job en attente';
    }

    /**
     * @return array<int,object>
     */
    private function extractTrackedLogs(string $logLines): array
    {
        if ($logLines === '') {
            return [];
        }

        $logs = [];
        $lines = array_filter(array_map('trim', explode("\n", $logLines)));

        foreach ($lines as $line) {
            $logs[] = (object) [
                'time' => now()->toDateTimeString(),
                'level' => 'log',
                'message' => $line,
            ];
        }

        return $logs;
    }
}
