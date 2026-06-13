<?php

use App\Components\Traits\WithToasts;
use App\Models\AgentEnrollmentRequest;
use App\Services\Agent\Enrollment\EnrollmentCampaign;
use App\Services\Agent\Enrollment\EnrollmentService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Story 25.3 — Surface d'approbation des enrôlements porte 2 (AC5).
 *
 * Liste paginée des demandes `pending` d'enrôlement des postes migrés (faisceau
 * de preuves affiché + rapprochement DB le cas échéant + badge auto/manuel/
 * conflit), avec « Approuver » (un-clic — arme la demande, le token naît au
 * prochain check-in du poste, il ne transite JAMAIS par l'UI) et « Rejeter »
 * (modale réutilisable affichant les preuves pour une décision éclairée).
 *
 * Le mode campagne (auto-approbation bornée, anti-usurpation jamais débrayé) est
 * réglable ici : un bandeau montre l'état et permet d'activer/désactiver. Les
 * actions passent par {@see EnrollmentService} (logs `agent.enroll.*`), jamais
 * par une écriture directe.
 */
return new class extends Component {
    use WithPagination;
    use WithToasts;

    /** Modale de rejet (réutilisable) — pattern `isOpen` + preuves affichées. */
    public bool $isRejectOpen = false;

    public ?int $rejectId = null;

    public array $rejectEvidence = [];

    /** Durée (en jours) de la campagne lors de l'activation depuis l'UI. */
    public int $campaignDays = 7;

    #[Computed]
    public function requests()
    {
        return AgentEnrollmentRequest::query()
            ->pending()
            ->with('matchedWorkstation')
            ->orderByDesc('last_seen_at')
            ->paginate(20);
    }

    #[Computed]
    public function campaignActive(): bool
    {
        return app(EnrollmentCampaign::class)->isActive();
    }

    #[Computed]
    public function campaignUntil(): ?string
    {
        return app(EnrollmentCampaign::class)->until()?->format('d/m/Y H:i');
    }

    /**
     * Approbation un-clic : arme la demande (le token naîtra au prochain
     * check-in du poste). Aucun token n'est manipulé ici.
     */
    public function approve(int $id): void
    {
        // (review #4) Double protection iso-pattern projet : le middleware route
        // protège la page, le Gate protège l'action adressable via /livewire/update.
        Gate::authorize('computer.install');

        $request = AgentEnrollmentRequest::query()->pending()->with('matchedWorkstation')->find($id);
        if ($request === null) {
            $this->toastError('Demande introuvable ou déjà résolue.');

            return;
        }

        // (review #3) Une demande sans rapprochement (« inconnu ») ne peut pas
        // être armée : l'étape 2 du redeem exige un poste cible, sinon le poste
        // resterait 403 indéfiniment et la demande, sortie du scope pending,
        // deviendrait invisible. Le choix d'un poste cible est l'extension 25.5.
        if ($request->matched_workstation_id === null) {
            $this->toastError('Poste non rapproché : impossible d\'approuver sans cible (rapprochement requis).');

            return;
        }

        app(EnrollmentService::class)->approveManually($request, auth()->id());
        unset($this->requests);
        $this->toastSuccess('Poste approuvé — il recevra son jeton à son prochain check-in.');
    }

    public function openReject(int $id): void
    {
        Gate::authorize('computer.install');

        $request = AgentEnrollmentRequest::query()->pending()->find($id);
        if ($request === null) {
            $this->toastError('Demande introuvable ou déjà résolue.');

            return;
        }

        $this->rejectId = $id;
        $this->rejectEvidence = [
            'mac' => $request->mac ?? '—',
            'hostname' => $request->hostname ?? '—',
            'uuid' => $request->uuid ?? '—',
            'matched' => $request->matchedWorkstation?->name,
        ];
        $this->isRejectOpen = true;
    }

    public function close(): void
    {
        $this->isRejectOpen = false;
        $this->rejectId = null;
        $this->rejectEvidence = [];
    }

    public function confirmReject(): void
    {
        Gate::authorize('computer.install');

        $request = $this->rejectId !== null
            ? AgentEnrollmentRequest::query()->pending()->find($this->rejectId)
            : null;

        if ($request === null) {
            $this->close();
            $this->toastError('Demande introuvable ou déjà résolue.');

            return;
        }

        app(EnrollmentService::class)->rejectManually($request, auth()->id());
        $this->close();
        unset($this->requests);
        $this->toastSuccess('Demande rejetée — le poste reste hors du système.');
    }

    public function enableCampaign(): void
    {
        Gate::authorize('computer.install');

        // (review #7) Borne haute : une campagne plafonne à 365 jours (hygiène
        // d'input — l'anti-usurpation ne dépend pas de la durée, mais une saisie
        // erronée ne doit pas laisser la campagne active « indéfiniment »).
        $days = min(max(1, $this->campaignDays), 365);
        app(EnrollmentCampaign::class)->enableUntil(now()->addDays($days));
        unset($this->campaignActive, $this->campaignUntil);
        $this->toastSuccess("Mode campagne activé pour {$days} jour(s).");
    }

    public function disableCampaign(): void
    {
        Gate::authorize('computer.install');

        app(EnrollmentCampaign::class)->disable();
        unset($this->campaignActive, $this->campaignUntil);
        $this->toastSuccess('Mode campagne désactivé — retour à l\'approbation manuelle.');
    }

    /**
     * Badge de la demande : conflit (poste déjà enrôlé — ne devrait pas être
     * pending, garde-fou d'affichage), rapproché (manuel), ou inconnu.
     */
    public function badge(AgentEnrollmentRequest $request): array
    {
        $ws = $request->matchedWorkstation;

        if ($ws !== null && $ws->isAgentEnrolled()) {
            return ['label' => 'conflit', 'class' => 'badge-error'];
        }

        if ($ws !== null) {
            return ['label' => 'rapproché', 'class' => 'badge-info'];
        }

        return ['label' => 'inconnu', 'class' => 'badge-warning'];
    }
};
?>

<div class="flex flex-col gap-4">
    {{-- Bandeau mode campagne (auto-approbation bornée) --}}
    <div class="alert {{ $this->campaignActive ? 'alert-warning' : 'alert-info' }} flex-wrap gap-3">
        <i class="fa-solid {{ $this->campaignActive ? 'fa-bolt' : 'fa-hand' }}"></i>
        <div class="flex-1 min-w-0">
            @if ($this->campaignActive)
                <span class="font-semibold">Mode campagne actif</span>
                — auto-approbation des postes connus concordants jusqu'au
                {{ $this->campaignUntil }}. L'anti-usurpation reste actif (postes
                divergents/inconnus toujours en approbation manuelle).
            @else
                <span class="font-semibold">Approbation manuelle</span>
                — chaque poste migré doit être approuvé un par un.
            @endif
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @if ($this->campaignActive)
                <button type="button" class="btn btn-sm btn-ghost" wire:click="disableCampaign">
                    Désactiver
                </button>
            @else
                <input type="number" min="1" max="365" class="input input-sm input-bordered w-20"
                    wire:model="campaignDays" aria-label="Durée en jours">
                <button type="button" class="btn btn-sm btn-warning" wire:click="enableCampaign">
                    Activer la campagne
                </button>
            @endif
        </div>
    </div>

    {{-- Liste des demandes pending --}}
    <div class="overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Hostname</th>
                    <th>MAC</th>
                    <th>UUID</th>
                    <th>Rapprochement</th>
                    <th>Vu</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->requests as $request)
                    @php($b = $this->badge($request))
                    <tr wire:key="enroll-{{ $request->id }}">
                        <td class="font-mono">{{ $request->hostname ?? '—' }}</td>
                        <td class="font-mono text-sm">{{ $request->mac ?? '—' }}</td>
                        <td class="font-mono text-xs text-base-content/60">{{ $request->uuid ?? '—' }}</td>
                        <td>
                            <span class="badge {{ $b['class'] }}">{{ $b['label'] }}</span>
                            @if ($request->matchedWorkstation)
                                <span class="text-sm ml-1">{{ $request->matchedWorkstation->name }}</span>
                            @endif
                        </td>
                        <td class="text-sm text-base-content/60">
                            {{ $request->last_seen_at?->diffForHumans() ?? '—' }}
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @if ($request->matched_workstation_id !== null)
                                <button type="button" class="btn btn-sm btn-success"
                                    wire:click="approve({{ $request->id }})"
                                    wire:confirm="Approuver l'enrôlement de ce poste ?">
                                    <i class="fa-solid fa-check"></i> Approuver
                                </button>
                            @else
                                <button type="button" class="btn btn-sm btn-success" disabled
                                    title="Poste non rapproché : un rapprochement avec une fiche connue est requis pour approuver (sélection de cible à venir en 25.5).">
                                    <i class="fa-solid fa-check"></i> Approuver
                                </button>
                            @endif
                            <button type="button" class="btn btn-sm btn-ghost text-error"
                                wire:click="openReject({{ $request->id }})">
                                <i class="fa-solid fa-xmark"></i> Rejeter
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-base-content/50 py-8">
                            Aucune demande d'enrôlement en attente.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-4">
            {{ $this->requests->links() }}
        </div>
    </div>

    {{-- Modale de rejet (réutilisable) — preuves affichées pour décision éclairée --}}
    <x-molecules.modal wire:model="isRejectOpen" title="Rejeter la demande d'enrôlement"
        icon="fa-triangle-exclamation text-error" size="max-w-xl" height="h-auto">
        <x-molecules.modal.section title="Preuves présentées par le poste">
            <dl class="grid grid-cols-3 gap-2 text-sm">
                <dt class="font-semibold">Hostname</dt>
                <dd class="col-span-2 font-mono">{{ $rejectEvidence['hostname'] ?? '—' }}</dd>
                <dt class="font-semibold">MAC</dt>
                <dd class="col-span-2 font-mono">{{ $rejectEvidence['mac'] ?? '—' }}</dd>
                <dt class="font-semibold">UUID</dt>
                <dd class="col-span-2 font-mono text-xs">{{ $rejectEvidence['uuid'] ?? '—' }}</dd>
                <dt class="font-semibold">Poste rapproché</dt>
                <dd class="col-span-2">{{ $rejectEvidence['matched'] ?? 'aucun' }}</dd>
            </dl>
            <p class="text-sm text-base-content/60 mt-3">
                Le poste restera hors du système. Un nouvel appel de sa part ne ré-ouvrira
                pas la demande automatiquement.
            </p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">Annuler</button>
            <button type="button" class="btn btn-error" wire:click="confirmReject">
                <i class="fa-solid fa-xmark"></i> Rejeter
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</div>
