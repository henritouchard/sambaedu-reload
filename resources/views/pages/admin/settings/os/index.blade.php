<?php

use App\Components\Traits\WithToasts;
use App\SystemStatus\Distro;
use App\SystemStatus\DistroInstallTracker;
use App\SystemStatus\DistroInventoryService;
use App\SystemStatus\Jobs\RunDistroInstallScriptJob;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /admin/settings/os — Sources d'installation OS (distros iPXE).
 *
 * Grille d'une card par distro installable via le boot iPXE (whitelist
 * {@see Distro}) : inventaire filesystem read-only ({@see DistroInventoryService},
 * chargé au mount) + provisioning async des distros Linux
 * ({@see RunDistroInstallScriptJob} — whitelist enum) avec suivi `wire:poll`
 * pendant un install. Les distros Windows (non installables par script) renvoient
 * vers la page de gestion des ISO dédiée (`admin/ipxe/iso-windows`).
 *
 * Extrait de « État du système » (les distros ne sont pas un diagnostic
 * environnement mais des sources d'installation — décision Henri 2026-07-18).
 *
 * Sécurité : middleware can:server.admin sur la route + double guard mount()/
 * actions (defense in depth).
 */
new #[Title('OS installables')] class extends Component {
    use WithToasts;

    /**
     * Inventaire distros LINUX uniquement : [['key','label','available',
     * 'missing','installable','install_state'], ...]. Les versions Windows
     * sont regroupées dans une card unique {@see $windows} (gestion dédiée
     * page ISO).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $distros = [];

    /**
     * Synthèse Windows (Win10 + Win11 regroupés) : `available` = au moins une
     * version déployée ; `versions` = libellés des versions disponibles.
     *
     * @var array{available: bool, versions: array<int, string>}
     */
    public array $windows = ['available' => false, 'versions' => []];

    /** Au moins un install en cours → active le wire:poll. */
    public bool $installRunning = false;

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->loadDistros();
    }

    /**
     * Defense in depth : les actions Livewire repassent par le guard — le
     * middleware route couvre le canal `livewire/update`, mais on ne dépend
     * pas que de lui.
     */
    private function ensureAdmin(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }
    }

    /**
     * Dispatch l'install async d'une distro manquante.
     */
    public function installDistro(string $key): void
    {
        $this->ensureAdmin();

        $distro = Distro::tryFrom($key);
        if ($distro === null || $distro->installScriptPath() === null) {
            $this->toastError('Distro inconnue ou non installable depuis cette page.');

            return;
        }

        // Revalider côté serveur que la distro est bien manquante (le bouton
        // est masqué quand disponible, mais l'action Livewire reste appelable
        // directement — relancer un script sur des assets servis en prod peut
        // les écraser pendant un boot).
        foreach (app(DistroInventoryService::class)->list() as $item) {
            if ($item['distro'] === $distro && $item['available']) {
                $this->toastWarning(sprintf('%s est déjà disponible — rien à installer.', $distro->label()));

                return;
            }
        }

        // Lock atomique partagé (pattern WineImageQueuer) AVANT le push — deux
        // requêtes concurrentes ne peuvent pas dispatcher deux scripts
        // parallèles sur le même répertoire OS. Le job relâche le lock en fin
        // d'exécution (handle/failed).
        $tracker = app(DistroInstallTracker::class);
        if (! $tracker->tryAcquireLock($distro)) {
            $this->toastWarning(sprintf('Installation de %s déjà en cours.', $distro->label()));

            return;
        }

        // `running` posé AVANT le dispatch : le job peut patienter en queue,
        // l'UI doit refléter l'intention immédiatement.
        $tracker->start($distro);
        RunDistroInstallScriptJob::dispatch($distro);

        $this->loadDistros();
        $this->toastInfo(
            sprintf('Installation de %s lancée en arrière-plan — suivi sur cette page.', $distro->label()),
            'Provisioning démarré',
        );
    }

    /**
     * Cible du wire:poll pendant un install : rafraîchit l'inventaire + les
     * états, et notifie la fin.
     */
    public function refreshInstallStates(): void
    {
        $this->ensureAdmin();
        $this->loadDistros();

        // L'idempotence des toasts repose sur le flag `notified` persisté dans
        // l'état (pas sur un diff de propriété Livewire volatile) — un
        // rechargement de page ou un poll doublé n'émet pas deux fois la notif.
        $tracker = app(DistroInstallTracker::class);
        foreach ($this->distros as $distro) {
            $state = $distro['install_state'];
            if ($state === null || ($state['notified'] ?? false) === true) {
                continue;
            }
            if ($state['status'] === 'done') {
                $tracker->markNotified(Distro::from($distro['key']));
                $this->toastSuccess(sprintf('%s est désormais disponible à l\'installation.', $distro['label']));
            } elseif ($state['status'] === 'failed') {
                $tracker->markNotified(Distro::from($distro['key']));
                $this->toastError(
                    sprintf('Installation de %s échouée : %s', $distro['label'], $state['detail'] ?? 'détail indisponible'),
                );
            }
        }
    }

    private function loadDistros(): void
    {
        $tracker = app(DistroInstallTracker::class);

        // Windows (Win10 + Win11) est regroupé dans une card unique : sa gestion
        // (versions, ISO, pilotes) vit sur la page dédiée. Les autres distros
        // (Linux) restent des cards individuelles avec install direct.
        $windowsCases = [Distro::Win10, Distro::Win11];
        $windowsVersions = [];
        $linux = [];

        foreach (app(DistroInventoryService::class)->list() as $item) {
            if (in_array($item['distro'], $windowsCases, true)) {
                if ($item['available']) {
                    $windowsVersions[] = $item['distro']->label();
                }

                continue;
            }

            $linux[] = [
                'key' => $item['distro']->value,
                'label' => $item['distro']->label(),
                'available' => $item['available'],
                'missing' => $item['missing'],
                'installable' => $item['installable'],
                'install_state' => $tracker->stateFor($item['distro']),
            ];
        }

        $this->distros = $linux;
        $this->windows = ['available' => $windowsVersions !== [], 'versions' => $windowsVersions];
        $this->installRunning = $tracker->anyRunning();
    }
};
?>

<x-organisms.page title="OS installables"
    icon="fa-solid fa-compact-disc"
    description="Sources d'installation des systèmes d'exploitation déployés par le boot iPXE. Les actions de provisioning s'exécutent en arrière-plan."
    back="{{ route('admin.settings') }}">

    <div class="flex flex-col gap-6 pt-4"
        @if ($installRunning) wire:poll.5s="refreshInstallStates" @endif>

        {{-- Grille de cards (une par système) — pas d'encadré, le titre de page suffit. --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

            {{-- Card Windows unique (Win10 + Win11 regroupés) → page de gestion. --}}
            <a href="{{ route('admin.ipxe.iso-windows') }}"
                class="card bg-base-100 shadow-sm border border-base-300 hover:border-primary transition-colors"
                data-testid="os-card-windows">
                <div class="card-body p-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold">
                            <i class="fa-brands fa-windows text-primary mr-1"></i>Windows
                        </h3>
                        @if ($windows['available'])
                            <span class="badge badge-success badge-sm">{{ count($windows['versions']) }} déployée(s)</span>
                        @else
                            <span class="badge badge-ghost badge-sm">Aucune version</span>
                        @endif
                    </div>

                    <p class="text-xs text-base-content/60 mt-1">
                        @if ($windows['available'])
                            {{ implode(', ', $windows['versions']) }}
                        @else
                            Aucune source Windows déployée.
                        @endif
                    </p>
                </div>
            </a>

            @foreach ($distros as $distro)
                <div class="card bg-base-100 shadow-sm border border-base-300" data-testid="distro-{{ $distro['key'] }}">
                    <div class="card-body p-4">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold">{{ $distro['label'] }}</h3>
                            @if ($distro['available'])
                                <span class="badge badge-success badge-sm">Disponible</span>
                            @elseif (($distro['install_state']['status'] ?? null) === 'running')
                                <span class="badge badge-info badge-sm">
                                    <span class="loading loading-spinner loading-xs mr-1"></span>En cours…
                                </span>
                            @else
                                <span class="badge badge-ghost badge-sm">Manquante</span>
                            @endif
                        </div>

                        @if (! $distro['available'])
                            <p class="text-xs text-base-content/50 mt-1">
                                Manquant : {{ implode(', ', $distro['missing']) }}
                            </p>

                            @if (($distro['install_state']['status'] ?? null) === 'failed')
                                <p class="text-xs text-error mt-1" data-testid="distro-{{ $distro['key'] }}-failed">
                                    Dernier essai échoué : {{ $distro['install_state']['detail'] ?? 'détail indisponible' }}
                                </p>
                            @endif
                        @endif

                        <div class="card-actions justify-end mt-2">
                            @if ($distro['installable'])
                                @if (! $distro['available'])
                                    <button class="btn btn-sm btn-outline btn-secondary"
                                        wire:click="installDistro('{{ $distro['key'] }}')"
                                        @if (($distro['install_state']['status'] ?? null) === 'running') disabled @endif
                                        data-testid="install-{{ $distro['key'] }}">
                                        <i class="fa-solid fa-download mr-1"></i>
                                        {{ ($distro['install_state']['status'] ?? null) === 'failed' ? 'Réessayer' : 'Installer' }}
                                    </button>
                                @endif
                            @else
                                <a href="{{ route('admin.ipxe.iso-windows') }}"
                                    class="btn btn-sm btn-outline"
                                    data-testid="goto-iso-windows-{{ $distro['key'] }}">
                                    <i class="fa-brands fa-windows mr-1"></i>Gérer les ISO Windows
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

    </div>
</x-organisms.page>
