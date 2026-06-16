<?php

use App\Components\Traits\WithToasts;
use App\Models\OverlaySignal;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Overlay\OverlayService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — « Infos à transmettre » (messages overlay).
 *
 * Producteur du canal posté de l'overlay (cf. spike
 * spike-wallpaper-overlay-tools-2026-06-09.md) : poste un OverlaySignal ciblé
 * (broadcast / salle / poste / user) via OverlayService::postSignal(), liste
 * les signaux actifs et permet de les retirer.
 */
new #[Title('Infos à transmettre — overlay')] class extends Component {
    use WithToasts;

    // Formulaire
    public string $title = '';
    public string $text = '';
    public string $severity = 'info';
    public string $targetType = 'broadcast'; // broadcast | salle | workstation | user
    public ?int $targetSalleId = null;
    public string $targetWorkstationUuid = '';
    public string $targetUserLogin = '';
    public ?int $expiresInHours = 24; // null = pas d'expiration

    /** @var array<int,array{id:int,name:string}> */
    public array $salles = [];

    /** @var array<int,array{uuid:string,name:string}> */
    public array $postes = [];

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('wallpaper.manage'),
            403,
            'Permission wallpaper.manage requise.',
        );

        $this->salles = WorkstationGroup::query()
            ->where('is_physical', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (WorkstationGroup $g): array => ['id' => (int) $g->id, 'name' => (string) $g->name])
            ->all();

        $this->postes = Workstation::query()
            ->whereNotNull('uuid')
            ->orderBy('name')
            ->get(['name', 'uuid'])
            ->map(fn (Workstation $w): array => ['uuid' => (string) $w->uuid, 'name' => (string) $w->name])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:2000'],
            'severity' => ['required', 'in:info,warning,critical'],
            'targetType' => ['required', 'in:broadcast,salle,workstation,user'],
            'targetSalleId' => ['nullable', 'required_if:targetType,salle', 'integer'],
            'targetWorkstationUuid' => ['nullable', 'required_if:targetType,workstation', 'string'],
            'targetUserLogin' => ['nullable', 'required_if:targetType,user', 'string', 'max:255'],
            'expiresInHours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ];
    }

    public function save(OverlayService $overlay): void
    {
        $this->validate();

        $expiresAt = $this->expiresInHours !== null
            ? Carbon::now()->addHours($this->expiresInHours)
            : null;

        // Story 27.8 : le mécanisme strict/default est SUPPRIMÉ — le signal créé
        // ne porte plus de `mode` (STRICT inconditionnel, la cible fait loi).
        $overlay->postSignal(
            kind: 'notice',
            severity: $this->severity,
            title: $this->title,
            text: $this->text,
            workstationUuid: $this->targetType === 'workstation' ? $this->targetWorkstationUuid : null,
            workstationGroupId: $this->targetType === 'salle' ? $this->targetSalleId : null,
            userLogin: $this->targetType === 'user' ? $this->targetUserLogin : null,
            expiresAt: $expiresAt,
        );

        $this->reset(['title', 'text', 'targetSalleId', 'targetWorkstationUuid', 'targetUserLogin']);
        $this->toastSuccess('Message overlay publié.');
    }

    public function remove(int $id): void
    {
        OverlaySignal::query()->whereKey($id)->delete();
        $this->toastSuccess('Message retiré.');
    }

    /**
     * Signaux postés récents (actifs ou non), avec cible humanisée.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function signals(): array
    {
        $salleNames = collect($this->salles)->keyBy('id');

        return OverlaySignal::query()
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(function (OverlaySignal $s) use ($salleNames): array {
                $target = match (true) {
                    $s->workstation_group_id !== null => 'Salle : ' . ($salleNames[$s->workstation_group_id]['name'] ?? '#' . $s->workstation_group_id),
                    $s->workstation_uuid !== null => 'Poste : ' . $s->workstation_uuid,
                    $s->user_login !== null => 'Utilisateur : ' . $s->user_login,
                    default => 'Tous les postes (broadcast)',
                };

                $expired = $s->expires_at !== null && $s->expires_at->isPast();

                return [
                    'id' => (int) $s->id,
                    'severity' => (string) $s->severity,
                    'title' => (string) $s->title,
                    'target' => $target,
                    'expires' => $s->expires_at?->format('d/m/Y H:i') ?? '—',
                    'expired' => $expired,
                ];
            })
            ->all();
    }
};
?>

<x-organisms.page
    title="Infos à transmettre"
    :scrollable="true"
    description="Publiez un message affiché en surcouche du fond d'écran des postes (overlay), ciblé et avec expiration.">

    <div class="space-y-6">
        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">Canal overlay</p>
                <p class="text-sm opacity-80">
                    Le message est récupéré par les postes à leur prochain relevé (poll) et
                    reste affiché jusqu'à son expiration. La cible « Salle » s'applique à tous
                    les postes de la salle ; « broadcast » à tout le parc.
                </p>
            </div>
        </div>

        {{-- Formulaire de création --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <h2 class="card-title text-base">Nouveau message</h2>

                <form wire:submit="save" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-control">
                            <label class="label"><span class="label-text">Titre</span></label>
                            <input type="text" wire:model="title" class="input input-bordered" maxlength="255" />
                            @error('title') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div class="form-control">
                            <label class="label"><span class="label-text">Sévérité</span></label>
                            <select wire:model="severity" class="select select-bordered">
                                <option value="info">Information</option>
                                <option value="warning">Avertissement</option>
                                <option value="critical">Critique</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-control">
                        <label class="label"><span class="label-text">Message</span></label>
                        <textarea wire:model="text" class="textarea textarea-bordered" rows="3" maxlength="2000"></textarea>
                        @error('text') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="form-control">
                            <label class="label"><span class="label-text">Cible</span></label>
                            <select wire:model.live="targetType" class="select select-bordered">
                                <option value="broadcast">Tous les postes</option>
                                <option value="salle">Salle</option>
                                <option value="workstation">Poste</option>
                                <option value="user">Utilisateur</option>
                            </select>
                        </div>

                        @if ($targetType === 'salle')
                            <div class="form-control">
                                <label class="label"><span class="label-text">Salle</span></label>
                                <select wire:model="targetSalleId" class="select select-bordered">
                                    <option value="">— choisir —</option>
                                    @foreach ($salles as $salle)
                                        <option value="{{ $salle['id'] }}">{{ $salle['name'] }}</option>
                                    @endforeach
                                </select>
                                @error('targetSalleId') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                            </div>
                        @elseif ($targetType === 'workstation')
                            <div class="form-control">
                                <label class="label"><span class="label-text">Poste</span></label>
                                <select wire:model="targetWorkstationUuid" class="select select-bordered">
                                    <option value="">— choisir —</option>
                                    @foreach ($postes as $poste)
                                        <option value="{{ $poste['uuid'] }}">{{ $poste['name'] }}</option>
                                    @endforeach
                                </select>
                                @error('targetWorkstationUuid') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                            </div>
                        @elseif ($targetType === 'user')
                            <div class="form-control">
                                <label class="label"><span class="label-text">Login utilisateur</span></label>
                                <input type="text" wire:model="targetUserLogin" class="input input-bordered" />
                                @error('targetUserLogin') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        <div class="form-control">
                            <label class="label"><span class="label-text">Expire dans (heures)</span></label>
                            <input type="number" wire:model="expiresInHours" class="input input-bordered" min="1" max="8760" placeholder="vide = jamais" />
                            @error('expiresInHours') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-bullhorn"></i> Publier
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Liste des messages --}}
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <h2 class="card-title text-base">Messages récents</h2>

                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Sévérité</th>
                                <th>Titre</th>
                                <th>Cible</th>
                                <th>Expire</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->signals as $signal)
                                <tr class="{{ $signal['expired'] ? 'opacity-50' : '' }}">
                                    <td>
                                        <span class="badge badge-sm
                                            @class([
                                                'badge-info' => $signal['severity'] === 'info',
                                                'badge-warning' => $signal['severity'] === 'warning',
                                                'badge-error' => $signal['severity'] === 'critical',
                                            ])">{{ $signal['severity'] }}</span>
                                    </td>
                                    <td>{{ $signal['title'] }}</td>
                                    <td class="text-sm opacity-80">{{ $signal['target'] }}</td>
                                    <td class="text-sm">{{ $signal['expires'] }} {{ $signal['expired'] ? '(expiré)' : '' }}</td>
                                    <td class="text-right">
                                        <button class="btn btn-ghost btn-xs text-error"
                                            wire:click="remove({{ $signal['id'] }})"
                                            wire:confirm="Retirer ce message ?">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center opacity-60 py-6">Aucun message.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-organisms.page>
