<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\ErrorLog;

new #[Title('Error Logger - Instance SE4FS')] class extends Component {
    use WithPagination;

    #[Url]
    public string $sourceFilter = '';

    #[Url]
    public string $messageFilter = '';

    public ?int $selectedErrorId = null;

    public function updatingSourceFilter(): void { $this->resetPage(); }
    public function updatingMessageFilter(): void { $this->resetPage(); }

    public function selectError(int $id): void
    {
        $this->selectedErrorId = ($this->selectedErrorId === $id) ? null : $id;
    }

    public function clearSelection(): void
    {
        $this->selectedErrorId = null;
    }

    #[Computed]
    public function selectedError(): ?ErrorLog
    {
        return $this->selectedErrorId ? ErrorLog::find($this->selectedErrorId) : null;
    }

    #[Computed]
    public function errors()
    {
        return ErrorLog::query()
            ->when($this->sourceFilter, fn($q) => $q->where('source', $this->sourceFilter))
            ->when($this->messageFilter, fn($q) => $q->where('message', 'like', '%' . $this->messageFilter . '%'))
            ->orderByDesc('created_at')
            ->paginate(50);
    }
};
?>

<x-organisms.page title="Error Logger" description="Erreurs capturées (legacy PHP & exceptions Laravel) — diagnostic unifié">
    <x-slot:actions>
        <button wire:click="$refresh" class="btn btn-outline btn-primary btn-sm">
            <i class="fas fa-refresh"></i>
            Actualiser
        </button>
    </x-slot:actions>

    {{-- Erreur sélectionnée (épinglée au-dessus) --}}
    @if ($this->selectedError)
        @php
            $sel = $this->selectedError;
            $selBadge = match($sel->source) {
                'legacy'  => 'badge-warning',
                'laravel' => 'badge-error',
                default   => 'badge-neutral',
            };
        @endphp
        @php
            // Extraire les champs structurés (format "Clé: valeur" par ligne)
            $lines = explode("\n", $sel->message);
            $fields = [];
            $remainder = [];
            foreach ($lines as $line) {
                if (preg_match('/^(Route|Module|Fichier|Erreur)\s*:\s*(.+)$/i', $line, $m)) {
                    $fields[strtolower($m[1])] = trim($m[2]);
                } else {
                    $remainder[] = $line;
                }
            }
            $remainderText = trim(implode("\n", $remainder));
        @endphp
        <div class="alert alert-info mb-6 flex flex-col items-start gap-2">
            <div class="flex w-full items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="badge {{ $selBadge }}">{{ $sel->source }}</span>
                    <span class="text-sm opacity-70">{{ $sel->created_at?->format('d/m/Y H:i:s') }}</span>
                </div>
                <button wire:click="clearSelection" class="btn btn-ghost btn-xs">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            @if (!empty($fields))
                <div class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm w-full">
                    @foreach (['route' => 'Route', 'erreur' => 'Erreur', 'fichier' => 'Fichier', 'module' => 'Module'] as $key => $label)
                        @if (!empty($fields[$key]))
                            <span class="font-semibold opacity-70">{{ $label }}</span>
                            <span class="font-mono break-all">{{ $fields[$key] }}</span>
                        @endif
                    @endforeach
                </div>
                @if (!empty($remainderText))
                    <pre class="font-mono text-sm whitespace-pre-wrap break-all w-full mt-2">{{ $remainderText }}</pre>
                @endif
            @else
                <pre class="font-mono text-sm whitespace-pre-wrap break-all w-full">{{ $sel->message }}</pre>
            @endif
        </div>
    @endif

    {{-- Filtres --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <select class="select select-bordered" wire:model.live="sourceFilter">
            <option value="">Toutes les sources</option>
            <option value="legacy">Legacy</option>
            <option value="laravel">Laravel</option>
        </select>

        <input
            type="text"
            class="input input-bordered flex-1 min-w-[200px]"
            placeholder="Filtrer par message…"
            wire:model.live.debounce.300ms="messageFilter"
        />
    </div>

    {{-- Tableau avec polling automatique toutes les 10 secondes --}}
    <div wire:poll.10s.visible>
        @if ($this->errors->total() === 0)
            <div class="hero min-h-[200px]">
                <div class="hero-content text-center">
                    <div>
                        <i class="fas fa-check-circle text-4xl text-success mb-4"></i>
                        <p class="text-lg">Aucune erreur enregistrée</p>
                    </div>
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Date/Heure</th>
                            <th>Source</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->errors as $error)
                            <tr
                                wire:click="selectError({{ $error->id }})"
                                class="cursor-pointer hover:bg-base-200 {{ $this->selectedErrorId === $error->id ? 'bg-info/10' : '' }}"
                            >
                                <td class="whitespace-nowrap">{{ $error->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td>
                                    @php
                                        $badgeClass = match($error->source) {
                                            'legacy'  => 'badge-warning',
                                            'laravel' => 'badge-error',
                                            default   => 'badge-neutral',
                                        };
                                    @endphp
                                    <span class="badge {{ $badgeClass }}">{{ $error->source }}</span>
                                </td>
                                <td class="font-mono text-sm max-w-lg truncate">{{ $error->message }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->errors->links() }}
            </div>
        @endif
    </div>
</x-organisms.page>
