<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\ExtensionLifecycleException;
use App\Services\Extensions\ExtensionCatalogService;
use App\Services\Extensions\ExtensionLifecycleService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 54.1 (AC1) / 54.2 (AC1-AC2) — /admin/extensions/{id} : FICHE d'une
 * extension.
 *
 * Tout ce qui est affiché — version, description, scopes DEMANDÉS,
 * dépendances, URL d'entrée, visibilité — provient du **manifest**, seul
 * contrat public du système d'extensions (FR5).
 *
 * Les listes vides sont rendues PROPREMENT (« Aucun scope demandé », « Aucune
 * dépendance ») : jamais une section cassée.
 *
 * ⚠️ Les `scopes` sont une INFORMATION admin (FR3) : ils ne sont ni accordés ni
 * consommés en 54.1 (Epics 55/56). Les rôles de visibilité sont STOCKÉS ici,
 * RÉSOLUS par le lanceur en Story 54.3.
 *
 * Story 54.2 ajoute « Intégrer » / « Désinstaller » dans `<x-slot:actions>`
 * pour le type `link` uniquement (patron `app-profiles/index.blade.php:319`),
 * avec la même modale de confirmation que la bibliothèque. `$id` est
 * `#[Locked]` — les actions s'appuient dessus, JAMAIS sur un id client.
 *
 * Sécurité : `can:server.admin` sur la route + garde `Gate::allows()` DANS
 * `mount()` ET DANS CHAQUE méthode d'action (defense-in-depth). Identifiant
 * inconnu ⇒ 404.
 */
new #[Title('Extension')] class extends Component {
    use WithToasts;

    /** Identifiant serveur-autoritatif (jamais re-piloté depuis le client). */
    #[Locked]
    public int $id = 0;

    /** @var array<string, mixed> */
    public array $extension = [];

    /** Modale de confirmation de désinstallation. */
    public bool $isUninstallOpen = false;

    /** Modale d'avertissement « source non officielle » (Story 56.1, AC2). */
    public bool $isThirdPartyWarningOpen = false;

    public function mount(string $id): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->id = (int) $id;

        $this->loadExtension();
    }

    // ── AC1 — Intégrer (direct, un clic) ────────────────────────────────

    public function integrate(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        try {
            $result = app(ExtensionLifecycleService::class)->integrate($this->id, auth()->user());
        } catch (ExtensionLifecycleException $e) {
            $this->toastError($e->getMessage());
            $this->refreshAfterAction();

            return;
        }

        if (! $result['changed']) {
            // Le no-op signale un écran périmé (second admin, onglet dupliqué) :
            // rafraîchir, sinon le toast et la fiche se contredisent (review #2).
            $this->toastInfo('Cette extension est déjà intégrée.');
            $this->refreshAfterAction();

            return;
        }

        $this->loadExtension();
        $this->toastSuccess('Extension intégrée.');
    }

    // ── Story 56.1 AC2 — Intégrer une extension TIERCE (avertissement) ──

    /**
     * Ouvre l'avertissement de provenance. L'officialité est relue depuis la
     * FICHE CHARGÉE PAR LE SERVICE (`$this->extension`), rechargée au montage
     * et après chaque acte — jamais depuis un paramètre client. Une extension
     * officielle atteinte par ce chemin (écran périmé) intègre directement :
     * l'avertissement est une garde d'attention, pas une étape obligatoire.
     */
    public function askIntegrate(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if ((bool) ($this->extension['source_is_official'] ?? false)) {
            $this->integrate();

            return;
        }

        $this->isThirdPartyWarningOpen = true;
    }

    public function confirmIntegrate(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        // `$this->id` est `#[Locked]` : contrairement à la bibliothèque, il n'y
        // a pas de cible à préserver — fermer la modale est sans effet sur
        // l'action, et un double-clic retombe sur le no-op propre du service.
        $this->isThirdPartyWarningOpen = false;

        $this->integrate();
    }

    public function closeThirdPartyWarning(): void
    {
        $this->isThirdPartyWarningOpen = false;
    }

    // ── AC2 — Désinstaller (confirmation par modale) ────────────────────

    public function askUninstall(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->isUninstallOpen = true;
    }

    public function confirmUninstall(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->closeUninstall();

        try {
            $result = app(ExtensionLifecycleService::class)->uninstall($this->id, auth()->user());
        } catch (ExtensionLifecycleException $e) {
            $this->toastError($e->getMessage());
            $this->refreshAfterAction();

            return;
        }

        if (! $result['changed']) {
            $this->toastInfo('Cette extension est déjà disponible.');
            $this->refreshAfterAction();

            return;
        }

        $this->loadExtension();
        $this->toastSuccess('Extension désinstallée.');
    }

    public function closeUninstall(): void
    {
        $this->isUninstallOpen = false;
    }

    /**
     * Recharge la fiche depuis le catalogue (après transition, ou au montage).
     *
     * `abort(404)` est le bon geste AU MONTAGE (l'URL ne désigne rien). En plein
     * re-render Livewire il serait brutal — voir {@see self::refreshAfterAction()}.
     */
    private function loadExtension(): void
    {
        $extension = app(ExtensionCatalogService::class)->find($this->id);
        if ($extension === null) {
            abort(404);
        }

        $this->extension = $extension;
    }

    /**
     * Rafraîchit la fiche après un no-op ou un refus (review #2).
     *
     * Ces deux chemins ne surviennent que lorsque l'écran est PÉRIMÉ. Si
     * l'extension existe encore, on remet la fiche en phase avec la base. Si
     * elle a disparu entre-temps (prune concurrent), la fiche n'a plus d'objet :
     * on ramène l'admin à la bibliothèque plutôt que de le laisser devant un
     * écran qui ment, ou de lui servir un 404 en plein re-render.
     */
    private function refreshAfterAction(): void
    {
        $extension = app(ExtensionCatalogService::class)->find($this->id);

        if ($extension === null) {
            $this->redirect(route('admin.extensions'), navigate: true);

            return;
        }

        $this->extension = $extension;
    }
};
?>

<x-organisms.page :title="$extension['name']" icon="fa-solid fa-puzzle-piece"
    :back="route('admin.extensions')" back-text="Retour à la bibliothèque">

    @if ($extension['type'] === 'link')
        <x-slot:actions>
            @if ($extension['status'] === 'available')
                {{-- Officielle : un clic (54.2 inchangé). Tierce : avertissement d'abord. --}}
                <button type="button" class="btn btn-primary"
                    wire:click="{{ $extension['source_is_official'] ? 'integrate' : 'askIntegrate' }}"
                    data-testid="integrate-action">
                    <i class="fa-solid fa-plug"></i> Intégrer
                </button>
            @elseif ($extension['status'] === 'integrated')
                <button type="button" class="btn btn-ghost" wire:click="askUninstall" data-testid="uninstall-action">
                    <i class="fa-solid fa-trash-can"></i> Désinstaller
                </button>
            @endif
        </x-slot:actions>
    @endif

    <div class="flex flex-col gap-6">

        {{-- ============ Provenance non officielle (56.1 AC2, FR4/UX-DR4) ============ --}}
        @unless ($extension['source_is_official'])
            <div class="alert alert-warning shadow-sm" data-testid="third-party-alert">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <p class="font-medium">
                        Source non officielle : {{ $extension['source_host'] !== '' ? $extension['source_host'] : 'dépôt inconnu' }}
                        — vous installez sous votre responsabilité.
                    </p>
                    <p class="text-sm opacity-80">
                        SE5 a vérifié que le catalogue de cette source est signé par la clé enregistrée pour elle.
                        Cela authentifie le dépôt, pas le contenu : ni SambaEdu ni votre académie n'ont audité
                        cette extension.
                    </p>
                </div>
            </div>
        @endunless

        {{-- ===================== Identité ===================== --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <div class="flex items-start gap-4">
                    <div class="hidden sm:flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <i class="{{ $extension['icon'] !== '' ? $extension['icon'] : 'fa-solid fa-puzzle-piece' }} text-xl"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="card-title text-lg flex items-center gap-2 flex-wrap">
                            <span class="truncate">{{ $extension['name'] }}</span>
                            <span class="badge badge-sm badge-outline gap-1">
                                <i class="{{ $extension['type_icon'] }} text-[10px]"></i>
                                {{ $extension['type_label'] }}
                            </span>
                            <span class="badge badge-sm {{ $extension['status_badge'] }}"
                                data-testid="extension-status">{{ $extension['status_label'] }}</span>
                        </h2>

                        <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-code-branch w-4 text-center opacity-50"></i>
                                <span>Version</span>
                                <span class="font-mono">{{ $extension['version'] !== '' ? $extension['version'] : '—' }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-building w-4 text-center opacity-50"></i>
                                <span>Éditeur</span>
                                <span>{{ $extension['publisher'] !== '' ? $extension['publisher'] : 'Non renseigné' }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-box-archive w-4 text-center opacity-50"></i>
                                <span>Source</span>
                                <span>{{ $extension['source_name'] }}</span>
                                <span class="badge badge-ghost badge-xs">{{ $extension['source_kind_label'] }}</span>
                                @if ($extension['source_is_official'])
                                    <span class="badge badge-xs badge-success gap-1" title="Source officielle SambaEdu">
                                        <i class="fa-solid fa-certificate text-[9px]"></i> Officielle
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-fingerprint w-4 text-center opacity-50"></i>
                                <span>Identifiant</span>
                                <span class="font-mono text-xs">{{ $extension['key'] }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70 sm:col-span-2">
                                <i class="fa-solid fa-arrow-up-right-from-square w-4 text-center opacity-50"></i>
                                <span>Cible</span>
                                <span class="font-mono text-xs break-all"
                                    data-testid="extension-entry-url">{{ $extension['entry_url'] !== '' ? $extension['entry_url'] : '—' }}</span>
                            </div>
                        </dl>

                        @if ($extension['description'] !== '')
                            <p class="mt-4 text-sm text-base-content/80 whitespace-pre-line">{{ $extension['description'] }}</p>
                        @else
                            <p class="mt-4 text-sm text-base-content/50 italic">Aucune description fournie par le manifest.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== Ce que l'extension demande ===================== --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- Scopes demandés (information admin — jamais consommés en 54.1). --}}
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body">
                    <h3 class="card-title text-base">
                        <i class="fa-solid fa-shield-halved text-primary"></i> Autorisations demandées
                        <span class="badge badge-neutral badge-sm">{{ count($extension['scopes']) }}</span>
                    </h3>
                    <p class="text-xs text-base-content/50">
                        Ce que l'extension déclare vouloir consulter. Rien n'est accordé aujourd'hui :
                        c'est une information de transparence.
                    </p>
                    @if (count($extension['scopes']) === 0)
                        <p class="text-sm text-base-content/50 py-3" data-testid="no-scopes">Aucun scope demandé.</p>
                    @else
                        <ul class="flex flex-wrap gap-2 pt-2" data-testid="scopes-list">
                            @foreach ($extension['scopes'] as $scope)
                                <li wire:key="scope-{{ $loop->index }}">
                                    <span class="badge badge-outline font-mono text-xs">{{ $scope }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- Dépendances déclarées. --}}
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body">
                    <h3 class="card-title text-base">
                        <i class="fa-solid fa-diagram-project text-primary"></i> Dépendances
                        <span class="badge badge-neutral badge-sm">{{ count($extension['dependencies']) }}</span>
                    </h3>
                    <p class="text-xs text-base-content/50">
                        Les autres extensions dont celle-ci a besoin pour fonctionner.
                    </p>
                    @if (count($extension['dependencies']) === 0)
                        <p class="text-sm text-base-content/50 py-3" data-testid="no-dependencies">Aucune dépendance.</p>
                    @else
                        <ul class="flex flex-wrap gap-2 pt-2" data-testid="dependencies-list">
                            @foreach ($extension['dependencies'] as $dependency)
                                <li wire:key="dependency-{{ $loop->index }}">
                                    <span class="badge badge-outline font-mono text-xs">{{ $dependency }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- ===================== Public visé ===================== --}}
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body">
                <h3 class="card-title text-base">
                    <i class="fa-solid fa-users text-primary"></i> Public visé
                </h3>
                <p class="text-xs text-base-content/50">
                    Rôles métier auxquels la tuile est destinée. L'autorisation réelle reste du ressort de
                    l'extension elle-même.
                </p>
                @if (count($extension['visibility_roles']) === 0)
                    <p class="text-sm text-base-content/50 py-3">Aucun rôle déclaré.</p>
                @else
                    <ul class="flex flex-wrap gap-2 pt-2" data-testid="visibility-roles">
                        @foreach ($extension['visibility_roles'] as $role)
                            <li wire:key="role-{{ $loop->index }}">
                                <span class="badge badge-ghost">{{ $role }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    {{-- ===================== Modale : confirmer la désinstallation (AC2) ===================== --}}
    <x-molecules.modal wire:model="isUninstallOpen" size="max-w-lg" height="h-auto"
        close-method="closeUninstall" title="Désinstaller l'extension" icon="fa-trash-can text-error">

        <x-molecules.modal.section>
            <p class="text-sm">
                <strong>{{ $extension['name'] }}</strong> redevient disponible dans la bibliothèque.
            </p>
            <p class="text-sm text-base-content/60 mt-1">
                Une extension lien n'installe aucun composant : il n'y a rien à nettoyer, la
                désinstallation est un simple retour à l'état disponible — vous pourrez la
                réintégrer en un clic.
            </p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeUninstall">Annuler</button>
            <button type="button" class="btn btn-error" wire:click="confirmUninstall"
                data-testid="confirm-uninstall">
                <i class="fa-solid fa-trash-can"></i> Désinstaller
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- ============ Modale : avertissement de source tierce (56.1 AC2) ============ --}}
    <x-molecules.modal wire:model="isThirdPartyWarningOpen" size="max-w-lg" height="h-auto"
        close-method="closeThirdPartyWarning" title="Source non officielle"
        icon="fa-triangle-exclamation text-warning">

        <x-molecules.modal.section>
            <p class="text-sm" data-testid="third-party-warning-text">
                <strong>Source non officielle : {{ $extension['source_host'] !== '' ? $extension['source_host'] : 'dépôt inconnu' }}</strong>
                — vous installez sous votre responsabilité.
            </p>
            <p class="text-sm text-base-content/60 mt-2">
                <strong>{{ $extension['name'] }}</strong> provient d'un dépôt tiers. SE5 a vérifié la signature de
                son catalogue, mais cela n'engage que le dépôt : le contenu de l'extension n'a été audité ni par
                SambaEdu ni par votre académie.
            </p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeThirdPartyWarning">Annuler</button>
            <button type="button" class="btn btn-warning" wire:click="confirmIntegrate"
                data-testid="confirm-third-party-integrate">
                <i class="fa-solid fa-plug"></i> Intégrer quand même
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</x-organisms.page>
