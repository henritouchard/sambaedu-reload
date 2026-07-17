<?php

use App\Components\Traits\WithToasts;
use App\Models\DhcpReservation;
use App\Models\DhcpSubnet;
use App\Models\Workstation;
use App\Services\Network\DhcpService;
use App\Services\Network\DhcpSubnetService;
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
    private const TABS = ['reservations', 'leases', 'subnets'];
    public string $search = '';
    public bool $modalOpen = false;
    public bool $editing = false;
    public ?int $editingId = null;

    // === Sous-réseaux (VLAN) — Story 8.3 ===
    public bool $subnetModalOpen = false;
    public bool $subnetEditing = false;
    public ?int $subnetEditingId = null;
    public ?int $vlan_id = null;
    public string $network = '';
    public string $gateway = '';
    /** @var array<int,array{begin:string,end:string}> */
    public array $ranges = [['begin' => '', 'end' => '']];
    public ?string $extra_option = null;
    public ?string $subnetDescription = null;

    public bool $subnetDeleteOpen = false;
    public ?int $subnetDeleteId = null;
    public ?string $subnetDeleteLabel = null;

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

    // ====================================================================
    // Sous-réseaux (VLAN) — Story 8.3
    // ====================================================================

    private function resetSubnetForm(): void
    {
        $this->subnetEditing = false;
        $this->subnetEditingId = null;
        $this->vlan_id = null;
        $this->network = '';
        $this->gateway = '';
        $this->ranges = [['begin' => '', 'end' => '']];
        $this->extra_option = null;
        $this->subnetDescription = null;
        $this->resetErrorBag();
    }

    public function openCreateSubnetModal(): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }
        $this->resetSubnetForm();
        $this->subnetModalOpen = true;
    }

    public function openEditSubnetModal(int $id): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }
        $subnet = DhcpSubnet::findOrFail($id);
        $this->subnetEditing = true;
        $this->subnetEditingId = $subnet->id;
        $this->vlan_id = $subnet->vlan_id;
        $this->network = $subnet->network;
        $this->gateway = $subnet->gateway;
        $ranges = is_array($subnet->ranges) ? array_values($subnet->ranges) : [];
        $this->ranges = $ranges !== [] ? $ranges : [['begin' => '', 'end' => '']];
        $this->extra_option = $subnet->extra_option;
        $this->subnetDescription = $subnet->description;
        $this->resetErrorBag();
        $this->subnetModalOpen = true;
    }

    public function closeSubnet(): void
    {
        $this->subnetModalOpen = false;
        $this->subnetDeleteOpen = false;
    }

    public function addRange(): void
    {
        $this->ranges[] = ['begin' => '', 'end' => ''];
    }

    public function removeRange(int $index): void
    {
        if (isset($this->ranges[$index])) {
            unset($this->ranges[$index]);
            $this->ranges = array_values($this->ranges);
        }
        if ($this->ranges === []) {
            $this->ranges = [['begin' => '', 'end' => '']];
        }
    }

    public function saveSubnet(): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }

        $service = app(DhcpSubnetService::class);
        try {
            $attrs = [
                'vlan_id' => (int) $this->vlan_id,
                'network' => $this->network,
                'gateway' => $this->gateway,
                'ranges' => $this->ranges,
                'extra_option' => $this->extra_option,
                'description' => $this->subnetDescription,
            ];

            if ($this->subnetEditing && $this->subnetEditingId !== null) {
                $subnet = DhcpSubnet::findOrFail($this->subnetEditingId);
                $service->updateSubnet($subnet, $attrs);
                $this->toastSuccess('Sous-réseau modifié et service DHCP rechargé.');
            } else {
                $service->createSubnet($attrs);
                $this->toastSuccess('Sous-réseau créé et service DHCP rechargé.');
            }

            $this->subnetModalOpen = false;
        } catch (DhcpValidationException $e) {
            $this->addError('subnetForm', $e->getMessage());
            $this->toastError($e->getMessage());
        } catch (DhcpCommandException $e) {
            // AC5 — Mode dégradé : SQL + fichier conservés, seul le reload a échoué.
            Log::channel('network')->error('DhcpSubnetService: reload échoué', [
                'context' => 'page dhcp/index saveSubnet',
                'error' => $e->getMessage(),
            ]);
            $this->toastWarning(
                "Sous-réseau enregistré. Le service DHCP n'a pas pu être rechargé — relancer le service manuellement. (cause : " . $e->firstStderrLine() . ')',
                'Avertissement reload DHCP',
            );
            $this->subnetModalOpen = false;
        } catch (\Throwable $e) {
            Log::channel('network')->error('DhcpSubnetService: exception saveSubnet', ['error' => $e->getMessage()]);
            $this->toastError('Erreur inattendue : ' . $e->getMessage());
        }
    }

    public function confirmDeleteSubnet(int $id): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }
        $subnet = DhcpSubnet::findOrFail($id);
        $this->subnetDeleteId = $subnet->id;
        $this->subnetDeleteLabel = 'VLAN ' . $subnet->vlan_id . ' (' . $subnet->network . ')';
        $this->subnetDeleteOpen = true;
    }

    public function deleteSubnetConfirmed(): void
    {
        if (Gate::denies('manage-dhcp')) {
            $this->toastAccessDenied();
            return;
        }
        if ($this->subnetDeleteId === null) {
            return;
        }

        $service = app(DhcpSubnetService::class);
        try {
            $subnet = DhcpSubnet::findOrFail($this->subnetDeleteId);
            $service->deleteSubnet($subnet);
            $this->toastSuccess('Sous-réseau supprimé et service DHCP rechargé.');
        } catch (DhcpCommandException $e) {
            $this->toastWarning(
                'Sous-réseau supprimé. Reload service échoué — à relancer manuellement.',
                'Avertissement reload DHCP',
            );
        } catch (\Throwable $e) {
            $this->toastError('Erreur suppression : ' . $e->getMessage());
        } finally {
            $this->subnetDeleteOpen = false;
            $this->subnetDeleteId = null;
            $this->subnetDeleteLabel = null;
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

        // Sous-réseaux gérés (VLAN) + sous-réseau par défaut (lecture seule).
        $subnetService = app(DhcpSubnetService::class);
        $subnets = DhcpSubnet::query()->orderBy('vlan_id')->get();
        $defaultSubnet = $subnetService->defaultSubnet();

        return [
            'reservations' => $reservations,
            'leases' => $leases,
            'workstationSuggestions' => $workstationSuggestions,
            'subnets' => $subnets,
            'defaultSubnet' => $defaultSubnet,
        ];
    }
};
?>

<x-organisms.page title="Réservations DHCP" :scrollable="true"
    description="Gestion native des réservations et baux DHCP">

    <x-slot:actions>
        @if ($tab === 'subnets')
            <button type="button" wire:click="openCreateSubnetModal" class="btn btn-primary"
                @cannot('manage-dhcp') disabled @endcannot>
                <i class="fa-solid fa-plus"></i>
                Nouveau sous-réseau
            </button>
        @elseif ($tab !== 'leases')
            <button type="button" wire:click="openCreateModal" class="btn btn-primary"
                @cannot('manage-dhcp') disabled @endcannot>
                <i class="fa-solid fa-plus"></i>
                Nouvelle réservation
            </button>
        @endif
    </x-slot:actions>

    <div class="space-y-4">
        @include('pages.network.dhcp._partials.service-status-banner')

        {{-- Onglets --}}
        @php
            $dhcpTabs = [
                'reservations' => ['label' => 'Réservations ('.$reservations->total().')', 'icon' => 'fa-solid fa-bookmark'],
                'leases' => ['label' => 'Baux actifs'.($leasesAvailable ? ' ('.$leases->count().')' : ''), 'icon' => 'fa-solid fa-network-wired'],
                'subnets' => ['label' => 'Sous-réseaux ('.$subnets->count().')', 'icon' => 'fa-solid fa-sitemap'],
            ];
        @endphp
        <x-molecules.tabs :tabs="$dhcpTabs" :active="$tab" />

        {{-- Contenu des onglets --}}
        @if ($tab === 'subnets')
            @include('pages.network.dhcp._partials.subnets-table', ['subnets' => $subnets, 'defaultSubnet' => $defaultSubnet])
        @elseif ($tab === 'leases')
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

    {{-- Modale création / édition sous-réseau (VLAN) — Story 8.3 --}}
    <x-molecules.modal wire:model="subnetModalOpen"
        :title="$subnetEditing ? 'Modifier le sous-réseau (VLAN)' : 'Nouveau sous-réseau (VLAN)'"
        size="max-w-2xl"
        height="h-auto"
        closeMethod="closeSubnet">
        <form wire:submit.prevent="saveSubnet" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1">
                    <label class="label justify-between">
                        <span class="label-text">N° de VLAN *</span>
                        <span class="tooltip tooltip-left" data-tip="Entier de 1 à 999, unique. Identifie le sous-réseau dans le fichier de configuration généré.">
                            <i class="fa-solid fa-circle-info text-base-content/40"></i>
                        </span>
                    </label>
                    <input type="number" min="1" max="999" wire:model="vlan_id" class="input input-bordered w-full" required
                        placeholder="20" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="label justify-between">
                        <span class="label-text">Réseau (CIDR) *</span>
                        <span class="tooltip tooltip-left" data-tip="Notation CIDR complète, ex. 192.168.20.0/24. Le masque en est dérivé ; l'adresse est ramenée à sa base réseau.">
                            <i class="fa-solid fa-circle-info text-base-content/40"></i>
                        </span>
                    </label>
                    <input type="text" wire:model="network" class="input input-bordered w-full" required
                        placeholder="192.168.20.0/24" maxlength="45" />
                </div>
            </div>

            <div class="flex flex-col gap-1">
                <label class="label justify-between">
                    <span class="label-text">Passerelle *</span>
                    <span class="tooltip tooltip-left" data-tip="Adresse IPv4 de la passerelle du VLAN. Doit appartenir au réseau déclaré.">
                        <i class="fa-solid fa-circle-info text-base-content/40"></i>
                    </span>
                </label>
                <input type="text" wire:model="gateway" class="input input-bordered w-full" required
                    placeholder="192.168.20.254" maxlength="45" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="label justify-between">
                    <span class="label-text">Plages dynamiques *</span>
                    <span class="tooltip tooltip-left" data-tip="Au moins une plage. Début et fin inclus dans le réseau, début ≤ fin. Ne doit recouvrir aucune IP déjà réservée.">
                        <i class="fa-solid fa-circle-info text-base-content/40"></i>
                    </span>
                </label>
                <div class="space-y-2">
                    @foreach ($ranges as $i => $range)
                        <div class="flex items-center gap-2" wire:key="range-{{ $i }}">
                            <input type="text" wire:model="ranges.{{ $i }}.begin" class="input input-bordered input-sm flex-1"
                                placeholder="Début (192.168.20.10)" maxlength="45" />
                            <span class="text-base-content/40">→</span>
                            <input type="text" wire:model="ranges.{{ $i }}.end" class="input input-bordered input-sm flex-1"
                                placeholder="Fin (192.168.20.200)" maxlength="45" />
                            <button type="button" wire:click="removeRange({{ $i }})" class="btn btn-ghost btn-sm text-error"
                                title="Retirer la plage">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                    @endforeach
                </div>
                <button type="button" wire:click="addRange" class="btn btn-ghost btn-sm mt-2 w-fit">
                    <i class="fa-solid fa-plus"></i> Ajouter une plage
                </button>
            </div>

            <div class="flex flex-col gap-1">
                <label class="label justify-between">
                    <span class="label-text">Fichier d'option supplémentaire</span>
                    <span class="tooltip tooltip-left" data-tip="Optionnel. Chemin absolu d'un fichier d'options DHCP à inclure, ex. /etc/dhcp/vlan20.conf. Sans espace ni caractère spécial (‘ ; $ ` { } | &).">
                        <i class="fa-solid fa-circle-info text-base-content/40"></i>
                    </span>
                </label>
                <input type="text" wire:model="extra_option" class="input input-bordered w-full" maxlength="255"
                    placeholder="/etc/dhcp/vlan20-extra.conf" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="label"><span class="label-text">Description</span></label>
                <input type="text" wire:model="subnetDescription" class="input input-bordered w-full" maxlength="255" />
            </div>

            @error('subnetForm')
                <div class="alert alert-error py-2"><i class="fa-solid fa-triangle-exclamation"></i>{{ $message }}</div>
            @enderror
        </form>

        <x-slot:footer>
            <button type="button" wire:click="closeSubnet" class="btn btn-ghost" wire:loading.attr="disabled" wire:target="saveSubnet">Annuler</button>
            <button type="button" wire:click="saveSubnet" class="btn btn-primary"
                wire:loading.attr="disabled" wire:target="saveSubnet">
                <span wire:loading wire:target="saveSubnet" class="loading loading-spinner loading-sm"></span>
                {{ $subnetEditing ? 'Enregistrer' : 'Créer' }}
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- Modale confirmation suppression sous-réseau --}}
    <x-molecules.modal wire:model="subnetDeleteOpen" title="Supprimer le sous-réseau" size="max-w-md" height="h-auto"
        closeMethod="closeSubnet">
        <p>Voulez-vous vraiment supprimer le sous-réseau <strong>{{ $subnetDeleteLabel }}</strong> ?</p>
        <p class="text-sm text-base-content/60 mt-2">
            Les clés <code>dhcp_*_N</code> correspondantes seront retirées et le service DHCP sera rechargé.
        </p>
        <x-slot:footer>
            <button type="button" wire:click="closeSubnet" class="btn btn-ghost" wire:loading.attr="disabled" wire:target="deleteSubnetConfirmed">Annuler</button>
            <button type="button" wire:click="deleteSubnetConfirmed" class="btn btn-error"
                wire:loading.attr="disabled" wire:target="deleteSubnetConfirmed">
                <span wire:loading wire:target="deleteSubnetConfirmed" class="loading loading-spinner loading-sm"></span>
                <i wire:loading.remove wire:target="deleteSubnetConfirmed" class="fa-solid fa-trash"></i> Supprimer
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</x-organisms.page>
