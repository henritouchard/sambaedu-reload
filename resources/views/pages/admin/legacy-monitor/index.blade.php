<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use App\Components\Traits\WithToasts;
use App\Models\LegacyCatchallLog;

new #[Title('Legacy Monitor - Instance SE4FS')] class extends Component {
    use WithToasts, WithPagination;

    #[Url]
    public string $filterPath = '';
    #[Url]
    public string $filterMethod = '';
    #[Url]
    public string $filterIp = '';
    // Story 38.2 — filtre sur l'origine de la réponse (tombstone natif vs proxy
    // catchall). Critère GO 38.6 : zéro hit `source='catchall'` sur les routes
    // clients, hits `tombstone` en décroissance.
    #[Url]
    public string $filterSource = '';
    public int $perPage = 50;

    public function updatingFilterPath(): void { $this->resetPage(); }
    public function updatingFilterMethod(): void { $this->resetPage(); }
    public function updatingFilterIp(): void { $this->resetPage(); }
    public function updatingFilterSource(): void { $this->resetPage(); }

    public function getLogs()
    {
        $perPage = max(1, min(200, $this->perPage));

        return LegacyCatchallLog::query()
            ->when($this->filterPath, fn($q) => $q->where('path', 'like', '%' . addcslashes($this->filterPath, '%_\\') . '%'))
            ->when($this->filterMethod, fn($q) => $q->where('method', $this->filterMethod))
            ->when($this->filterIp, fn($q) => $q->where('ip', 'like', '%' . addcslashes($this->filterIp, '%_\\') . '%'))
            ->when($this->filterSource, fn($q) => $q->where('source', $this->filterSource))
            ->selectRaw('source, method, path, COUNT(*) as frequency, MAX(created_at) as last_seen, MAX(ip) as last_ip')
            ->groupBy('source', 'method', 'path')
            ->orderByDesc('frequency')
            ->orderBy('path')
            ->paginate($perPage);
    }
};
?>

<x-organisms.page title="Legacy Monitor" description="Appels catchall en temps réel — identifiez les routes legacy encore actives">
    <x-slot:actions>
        <button wire:click="$refresh" class="btn btn-outline btn-primary btn-sm">
            <i class="fas fa-refresh"></i>
            Actualiser
        </button>
    </x-slot:actions>

    {{-- Filtres --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <input
            type="text"
            class="input input-bordered w-full max-w-xs"
            wire:model.live.300ms="filterPath"
            placeholder="Filtrer par path..."
        />
        <input
            type="text"
            class="input input-bordered w-full max-w-xs"
            wire:model.live.300ms="filterIp"
            placeholder="Filtrer par IP..."
        />
        <select class="select select-bordered" wire:model.live="filterMethod">
            <option value="">Toutes les méthodes</option>
            <option value="GET">GET</option>
            <option value="POST">POST</option>
            <option value="PUT">PUT</option>
            <option value="DELETE">DELETE</option>
            <option value="PATCH">PATCH</option>
        </select>
        <select class="select select-bordered" wire:model.live="filterSource">
            <option value="">Toutes les origines</option>
            <option value="catchall">Catchall (proxy legacy)</option>
            <option value="tombstone">Tombstone (natif SE5)</option>
        </select>
    </div>

    {{-- Tableau avec polling automatique toutes les 5 secondes --}}
    <div wire:poll.5s>
        @php $logs = $this->getLogs(); @endphp

        @if ($logs->isEmpty())
            <div class="hero min-h-[200px]">
                <div class="hero-content text-center">
                    <div>
                        <i class="fas fa-check-circle text-4xl text-success mb-4"></i>
                        <p class="text-lg">Aucun appel catchall enregistré</p>
                    </div>
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Origine</th>
                            <th>Méthode</th>
                            <th>Path</th>
                            <th>Dernière IP</th>
                            <th>Dernière occurrence</th>
                            <th>Fréquence</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td>
                                    @php
                                        $sourceBadge = match($log->source) {
                                            'tombstone' => 'badge-success',
                                            'catchall'  => 'badge-error',
                                            default     => 'badge-neutral',
                                        };
                                    @endphp
                                    <span class="badge {{ $sourceBadge }}">{{ $log->source ?? 'catchall' }}</span>
                                </td>
                                <td>
                                    @php
                                        $badgeClass = match($log->method) {
                                            'GET'    => 'badge-info',
                                            'POST'   => 'badge-warning',
                                            'DELETE' => 'badge-error',
                                            default  => 'badge-neutral',
                                        };
                                    @endphp
                                    <span class="badge {{ $badgeClass }}">{{ $log->method }}</span>
                                </td>
                                <td class="font-mono text-sm">{{ $log->path }}</td>
                                <td class="font-mono text-sm">{{ $log->last_ip ?? '—' }}</td>
                                <td>{{ $log->last_seen ? \Carbon\Carbon::parse($log->last_seen)->diffForHumans() : '—' }}</td>
                                <td>
                                    <span class="badge badge-ghost">{{ $log->frequency }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</x-organisms.page>
