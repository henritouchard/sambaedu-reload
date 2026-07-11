<?php

use App\Components\Traits\WithToasts;
use App\Models\DhcpReservation;
use App\Models\Workstation;
use App\Services\Network\DhcpService;
use App\Services\Network\Exceptions\DhcpCommandException;
use App\Services\Network\Exceptions\DhcpValidationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Réservations DHCP — SE4FS')] class extends Component {
    use WithPagination, WithToasts;

    // === État UI ===
    #[Url(keep: true)]
    public string $tab = 'reservations';

    /** Onglets valides (allow-list du switch). */
    private const TABS = ['reservations', 'leases'];
    public string $search = '';
    public bool $modalOpen = false;
    public bool $editing = false;
    public ?int $editingId = null;

    // === Form fields ===
    public string $name = '';
    public string $mac = '';
    public string $ip = '';
    public ?string $description = null;
    public ?int $workstation_id = null;

    // === State system ===
    public array $serviceStatus = ['active' => false, 'details' => 'inconnu'];
    public bool $leasesAvailable = true;

    // === Delete confirm ===
    public bool $deleteOpen = false;
    public ?int $deleteId = null;
    public ?string $deleteLabel = null;

    public function mount(): void
    {
        if (Gate::denies('viewAny-dhcp')) {
            abort(403);
        }

        $this->refreshStatus();
    }

    public function rendering(): void
    {
        $this->refreshStatus();
    }

    private function refreshStatus(): void
    {
        try {
            $this->serviceStatus = app(DhcpService::class)->serviceStatus();
        } catch (\Throwable $e) {
            $this->serviceStatus = ['active' => false, 'details' => $e->getMessage()];
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'reservations';
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }
        $this->editing = false;
        $this->editingId = null;
        $this->name = '';
        $this->mac = '';
        $this->ip = '';
        $this->description = null;
        $this->workstation_id = null;
        $this->resetErrorBag();
        $this->modalOpen = true;
    }

    public function openEditModal(int $id): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }
        $reservation = DhcpReservation::findOrFail($id);
        $this->editing = true;
        $this->editingId = $reservation->id;
        $this->name = $reservation->name;
        $this->mac = $reservation->mac;
        $this->ip = $reservation->ip;
        $this->description = $reservation->description;
        $this->workstation_id = $reservation->workstation_id;
        $this->resetErrorBag();
        $this->modalOpen = true;
    }

    public function close(): void
    {
        $this->modalOpen = false;
        $this->deleteOpen = false;
    }

    public function preFillFromLease(string $mac, string $ip, ?string $hostname): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }
        $this->editing = false;
        $this->editingId = null;
        $this->name = $hostname !== null && $hostname !== '' ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $hostname) : 'host_' . str_replace(['.', ':'], '_', $ip);
        $this->mac = $mac;
        $this->ip = $ip;
        $this->description = null;
        $this->workstation_id = null;
        $this->resetErrorBag();
        $this->modalOpen = true;
    }

    /**
     * Pré-remplit la modale depuis l'index d'un bail dans la collection courante
     * (recalculée côté serveur). Évite l'injection HTML dans `wire:click` quand
     * le `hostname` du bail contient un caractère spécial (apostrophe, etc.).
     *
     * Cf. review code 8.1 #2.
     */
    public function preFillFromLeaseByIndex(int $idx): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }

        try {
            $leases = app(DhcpService::class)->listActiveLeases()->take(200)->values();
        } catch (\Throwable $e) {
            $this->toastError('Lecture des baux DHCP indisponible.');
            return;
        }

        if (!isset($leases[$idx])) {
            $this->toastError('Bail introuvable (la liste a changé, rafraîchissez la page).');
            return;
        }

        $lease = $leases[$idx];
        $this->preFillFromLease(
            (string) $lease['mac'],
            (string) $lease['ip'],
            $lease['hostname'] ?? null,
        );
    }

    public function save(): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }

        $service = app(DhcpService::class);
        try {
            $attrs = [
                'name' => $this->name,
                'mac' => $this->mac,
                'ip' => $this->ip,
                'description' => $this->description,
                'workstation_id' => $this->workstation_id,
            ];

            if ($this->editing && $this->editingId !== null) {
                $reservation = DhcpReservation::findOrFail($this->editingId);
                $service->updateReservation($reservation, $attrs);
                $this->toastSuccess('Réservation modifiée et service DHCP rechargé.');
            } else {
                $service->createReservation($attrs);
                $this->toastSuccess('Réservation créée et service DHCP rechargé.');
            }

            $this->modalOpen = false;
        } catch (DhcpValidationException $e) {
            $this->addError('form', $e->getMessage());
            $this->toastError($e->getMessage());
        } catch (DhcpCommandException $e) {
            // AC6 — Mode dégradé : la mutation DB est faite (export file +
            // reload échoués). La réservation est persistée mais le reload
            // a planté.
            Log::channel('network')->error('DhcpService: reload échoué', [
                'context' => 'page dhcp/index save',
                'error' => $e->getMessage(),
            ]);
            $this->toastWarning(
                "Réservation enregistrée. Le service DHCP n'a pas pu être rechargé — relancer le service manuellement. (cause : " . $e->firstStderrLine() . ")",
                'Avertissement reload DHCP',
            );
            $this->modalOpen = false;
        } catch (\Throwable $e) {
            Log::channel('network')->error('DhcpService: exception save', ['error' => $e->getMessage()]);
            $this->toastError('Erreur inattendue : ' . $e->getMessage());
        }
    }

    public function confirmDelete(int $id): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }
        $reservation = DhcpReservation::findOrFail($id);
        $this->deleteId = $reservation->id;
        $this->deleteLabel = $reservation->name . ' (' . $reservation->ip . ')';
        $this->deleteOpen = true;
    }

    public function deleteConfirmed(): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }
        if ($this->deleteId === null) {
            return;
        }

        $service = app(DhcpService::class);
        try {
            $reservation = DhcpReservation::findOrFail($this->deleteId);
            $service->deleteReservation($reservation);
            $this->toastSuccess('Réservation supprimée et service DHCP rechargé.');
        } catch (DhcpCommandException $e) {
            $this->toastWarning(
                "Réservation supprimée. Reload service échoué — à relancer manuellement.",
                'Avertissement reload DHCP',
            );
        } catch (\Throwable $e) {
            $this->toastError('Erreur suppression : ' . $e->getMessage());
        } finally {
            $this->deleteOpen = false;
            $this->deleteId = null;
            $this->deleteLabel = null;
        }
    }

    public function with(): array
    {
        $service = app(DhcpService::class);

        $query = DhcpReservation::query()
            ->with('workstation:id,name')
            ->orderBy('name');
        if ($this->search !== '') {
            $like = '%' . $this->search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('mac', 'like', $like)
                    ->orWhere('ip', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        /** @var LengthAwarePaginator $reservations */
        $reservations = $query->paginate(25);

        // Baux actifs (best-effort)
        $leases = collect();
        try {
            $leases = $service->listActiveLeases()->take(200);
            $this->leasesAvailable = true;
        } catch (\Throwable $e) {
            $this->leasesAvailable = false;
        }

        // Selector workstation (top 50 matchant le `name`).
        $workstationSuggestions = collect();
        if ($this->modalOpen && $this->name !== '') {
            $workstationSuggestions = Workstation::query()
                ->where('name', 'like', $this->name . '%')
                ->orderBy('name')
                ->limit(20)
                ->get(['id', 'name', 'ip', 'mac']);
        }

        return [
            'reservations' => $reservations,
            'leases' => $leases,
            'workstationSuggestions' => $workstationSuggestions,
        ];
    }
};
?>

<x-organisms.page title="Réservations DHCP" :scrollable="true"
    description="Gestion native des réservations et baux DHCP (FR20 + FR22)">

    <x-slot:actions>
        <button type="button" wire:click="openCreateModal" class="btn btn-primary"
            @cannot('manage-dhcp') disabled @endcannot>
            <i class="fa-solid fa-plus"></i>
            Nouvelle réservation
        </button>
    </x-slot:actions>

    <div class="space-y-4">
        @include('pages.network.dhcp._partials.service-status-banner')

        {{-- Onglets --}}
        @php
            $dhcpTabs = [
                'reservations' => ['label' => 'Réservations ('.$reservations->total().')', 'icon' => 'fa-solid fa-bookmark'],
                'leases' => ['label' => 'Baux actifs'.($leasesAvailable ? ' ('.$leases->count().')' : ''), 'icon' => 'fa-solid fa-network-wired'],
            ];
        @endphp
        <x-molecules.tabs :tabs="$dhcpTabs" :active="$tab" class="bg-base-200 w-fit" />

        {{-- Contenu des onglets --}}
        @if ($tab === 'leases')
            @include('pages.network.dhcp._partials.leases-table', ['leases' => $leases, 'leasesAvailable' => $leasesAvailable])
        @else
            {{-- Recherche (réservations uniquement) --}}
            <div class="form-control">
                <input type="text" wire:model.live.debounce.300ms="search"
                    placeholder="Rechercher par nom, MAC, IP, description…" class="input input-bordered w-full max-w-md" />
            </div>
            @include('pages.network.dhcp._partials.reservations-table', ['reservations' => $reservations])
        @endif
    </div>

    {{-- Modale création / édition --}}
    <x-molecules.modal wire:model="modalOpen"
        :title="$editing ? 'Modifier la réservation' : 'Nouvelle réservation DHCP'"
        size="max-w-2xl"
        height="h-auto"
        closeMethod="close">
        <form wire:submit.prevent="save" class="space-y-4">
            <div class="form-control">
                <label class="label"><span class="label-text">Nom *</span></label>
                <input type="text" wire:model.live="name" class="input input-bordered" required maxlength="63" />
                <p class="text-xs text-base-content/50 mt-1">1..63 caractères alphanumériques, `_` ou `-`.</p>
            </div>
            <div class="form-control">
                <label class="label"><span class="label-text">MAC *</span></label>
                <input type="text" wire:model="mac" class="input input-bordered" required
                    placeholder="aa:bb:cc:dd:ee:ff" maxlength="17" />
                <p class="text-xs text-base-content/50 mt-1">Format `xx:xx:xx:xx:xx:xx`. Séparateurs `-`, `.`, ou aucun également acceptés.</p>
            </div>
            <div class="form-control">
                <label class="label"><span class="label-text">IP *</span></label>
                <input type="text" wire:model="ip" class="input input-bordered" required
                    placeholder="10.0.0.50" maxlength="45" />
            </div>
            <div class="form-control">
                <label class="label"><span class="label-text">Description</span></label>
                <input type="text" wire:model="description" class="input input-bordered" maxlength="255" />
            </div>

            @if ($workstationSuggestions->isNotEmpty() && !$editing)
                <div class="form-control">
                    <label class="label"><span class="label-text">Machine du parc à lier (optionnel)</span></label>
                    <select wire:model="workstation_id" class="select select-bordered">
                        <option value="">— Aucune machine liée —</option>
                        @foreach ($workstationSuggestions as $ws)
                            <option value="{{ $ws->id }}">{{ $ws->name }} ({{ $ws->ip ?? 'IP inconnue' }})</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @error('form')
                <div class="alert alert-error py-2"><i class="fa-solid fa-triangle-exclamation"></i>{{ $message }}</div>
            @enderror
        </form>

        <x-slot:footer>
            <button type="button" wire:click="close" class="btn btn-ghost" wire:loading.attr="disabled" wire:target="save">Annuler</button>
            <button type="button" wire:click="save" class="btn btn-primary"
                wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="loading loading-spinner loading-sm"></span>
                {{ $editing ? 'Enregistrer' : 'Créer' }}
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- Modale confirmation suppression --}}
    <x-molecules.modal wire:model="deleteOpen" title="Supprimer la réservation" size="max-w-md" height="h-auto"
        closeMethod="close">
        <p>Voulez-vous vraiment supprimer la réservation <strong>{{ $deleteLabel }}</strong> ?</p>
        <p class="text-sm text-base-content/60 mt-2">
            Le service DHCP sera rechargé pour prendre en compte la suppression.
        </p>
        <x-slot:footer>
            <button type="button" wire:click="close" class="btn btn-ghost" wire:loading.attr="disabled" wire:target="deleteConfirmed">Annuler</button>
            <button type="button" wire:click="deleteConfirmed" class="btn btn-error"
                wire:loading.attr="disabled" wire:target="deleteConfirmed">
                <span wire:loading wire:target="deleteConfirmed" class="loading loading-spinner loading-sm"></span>
                <i wire:loading.remove wire:target="deleteConfirmed" class="fa-solid fa-trash"></i> Supprimer
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</x-organisms.page>
