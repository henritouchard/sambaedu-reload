<?php

use App\Models\MachineBootLog;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Activité - Tableau de bord')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $type = 'all';

    public int $perPage = 25;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function getActivities(): LengthAwarePaginator
    {
        $perPage = max(10, min(100, $this->perPage));
        $page = Paginator::resolveCurrentPage();
        $needle = trim($this->search);

        $items = collect();

        if ($this->type === 'all' || $this->type === 'machine') {
            $logs = MachineBootLog::query()
                ->with('workstation')
                ->when($needle, fn($q) => $q->where('machine_name', 'like', '%' . addcslashes($needle, '%_\\') . '%'))
                ->latest()
                ->get()
                ->map(fn(MachineBootLog $log) => (object) [
                    'kind'      => 'machine',
                    'initials'  => strtoupper(substr($log->machine_name, 0, 2)),
                    'name'      => $log->machine_name,
                    'action'    => match ($log->action) {
                        'wake'     => 'a été démarré',
                        'shutdown' => 'a été éteint',
                        'reboot'   => 'a redémarré',
                        default    => (string) $log->action,
                    },
                    'raw_action'  => $log->action,
                    'actor'       => $log->initiated_by,
                    'success'     => $log->success,
                    'color'       => $log->action === 'wake' ? 'success' : 'warning',
                    'timestamp'   => $log->created_at,
                ]);

            $items = $items->concat($logs);
        }

        if ($this->type === 'all' || $this->type === 'user') {
            $users = User::query()
                ->where('is_active', true)
                ->whereNotNull('firstname')
                ->when($needle, function ($q) use ($needle) {
                    $like = '%' . addcslashes($needle, '%_\\') . '%';
                    $q->where(function ($inner) use ($like) {
                        $inner->where('firstname', 'like', $like)
                            ->orWhere('lastname', 'like', $like)
                            ->orWhere('login', 'like', $like);
                    });
                })
                ->orderByDesc('updated_at')
                ->get()
                ->map(function (User $user): object {
                    $initials = strtoupper(
                        substr($user->firstname ?? '', 0, 1) . substr($user->lastname ?? '', 0, 1),
                    );

                    return (object) [
                        'kind'       => 'user',
                        'initials'   => $initials ?: '??',
                        'name'       => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')),
                        'action'     => 'compte synchronisé',
                        'raw_action' => 'sync',
                        'actor'      => null,
                        'success'    => null,
                        'color'      => 'primary',
                        'timestamp'  => $user->updated_at,
                    ];
                });

            $items = $items->concat($users);
        }

        /** @var Collection $sorted */
        $sorted = $items
            ->filter(fn(object $item): bool => $item->timestamp !== null)
            ->sortByDesc(fn(object $item) => $item->timestamp->getTimestamp())
            ->values();

        $total = $sorted->count();
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }
};
?>

<x-organisms.page title="Activité Récente" description="Historique complet des postes et des comptes utilisateurs">
    <x-slot:actions>
        <a href="{{ route('app.dashboard') }}" class="btn btn-outline btn-sm">
            <i class="fa-solid fa-arrow-left"></i>
            Retour dashboard
        </a>
    </x-slot:actions>

    {{-- Filtres --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <input type="text" class="input input-bordered w-full max-w-xs" wire:model.live.300ms="search"
            placeholder="Rechercher (machine, utilisateur)..." />
        <select class="select select-bordered" wire:model.live="type">
            <option value="all">Tous les types</option>
            <option value="machine">Postes</option>
            <option value="user">Utilisateurs</option>
        </select>
    </div>

    @php $activities = $this->getActivities(); @endphp

    @if ($activities->isEmpty())
        <div class="hero min-h-[200px]">
            <div class="hero-content text-center">
                <div>
                    <i class="fa-solid fa-clock-rotate-left text-4xl text-base-content/30 mb-4"></i>
                    <p class="text-lg">Aucune activité enregistrée</p>
                </div>
            </div>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="table table-zebra w-full">
                <thead>
                    <tr>
                        <th></th>
                        <th>Nom</th>
                        <th>Type</th>
                        <th>Action</th>
                        <th>Initiateur</th>
                        <th>Statut</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($activities as $activity)
                        <tr>
                            <td>
                                <x-atoms.avatar-placeholder :initials="$activity->initials" :color="$activity->color" size="w-8" />
                            </td>
                            <td class="font-medium">{{ $activity->name }}</td>
                            <td>
                                <span class="badge badge-ghost badge-sm">
                                    {{ $activity->kind === 'machine' ? 'Poste' : 'Utilisateur' }}
                                </span>
                            </td>
                            <td class="text-sm">{{ $activity->action }}</td>
                            <td class="text-sm">{{ $activity->actor ?? '—' }}</td>
                            <td>
                                @if ($activity->success === true)
                                    <span class="badge badge-success badge-sm">OK</span>
                                @elseif ($activity->success === false)
                                    <span class="badge badge-error badge-sm">Échec</span>
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td class="text-sm">
                                <span title="{{ $activity->timestamp?->toDateTimeString() }}">
                                    {{ $activity->timestamp?->diffForHumans() }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $activities->links() }}
        </div>
    @endif
</x-organisms.page>
