<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\ExtensionLifecycleException;
use App\Exceptions\ExtensionOperationException;
use App\Models\ExtensionInstallRun;
use App\Services\Extensions\ExtensionCatalogService;
use App\Services\Extensions\ExtensionHealthService;
use App\Services\Extensions\ExtensionLifecycleService;
use App\Services\Extensions\ExtensionOperationRunner;
use App\Services\Extensions\ExtensionScopeService;
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
 * ⚠️ Les `scopes` du manifest sont une INFORMATION admin (FR3) : ce que
 * l'extension DEMANDE. **Story 56.4** ajoute le second volet — ce qui lui est
 * réellement ACCORDÉ (`granted_scopes`, l'état du client OIDC actif), avec un
 * bouton de révocation par scope. Les deux ne doivent jamais être confondus :
 * l'écart entre les deux listes est précisément l'information utile.
 * Les rôles de visibilité sont STOCKÉS ici, RÉSOLUS par le lanceur en 54.3.
 *
 * Story 54.2 ajoute « Intégrer » / « Désinstaller » dans `<x-slot:actions>`
 * pour le type `link` uniquement (patron `app-profiles/index.blade.php:319`),
 * avec la même modale de confirmation que la bibliothèque. `$id` est
 * `#[Locked]` — les actions s'appuient dessus, JAMAIS sur un id client.
 *
 * **Story 56.3 — le cycle `app` (FR6/FR11)** : « Intégrer », « Mettre à jour »
 * et « Désinstaller » apparaissent aussi pour le type `app`, derrière LA MÊME
 * modale de confirmation que la bibliothèque (un seul fichier,
 * `_partials/app-operation-modal`), et s'exécutent en tâche de fond via
 * {@see ExtensionOperationRunner}. La fiche affiche l'état du run : étapes
 * accomplies, étape courante, raison d'échec — et la ligne « Version installée
 * / catalogue » quand les deux divergent. Le `wire:poll` n'est rendu que
 * pendant un run actif.
 *
 * Le cycle `link` est INCHANGÉ, verbatim.
 *
 * **Story 56.5 — carte « Santé » (FR34)** : pour une `app` réellement installée
 * seulement (rien à sonder ailleurs). Elle affiche l'état PERSISTÉ par
 * `ext:health:check` — joignabilité, fraîcheur de la mesure, versions, dernier
 * incident — et un bouton « Sonder maintenant » qui, lui, mesure en direct et
 * persiste ({@see ExtensionHealthService}, écrivain unique). Le rendu de la fiche
 * ne sonde JAMAIS. Un lien mène au journal d'audit, pré-filtré sur l'extension.
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

    // ── Story 56.3 — cycle `app` en tâche de fond ───────────────────────

    /** Modale unique de confirmation des opérations `app` (3 usages). */
    public bool $isAppOperationOpen = false;

    /** `install` | `update` | `remove`. */
    public string $appOperation = '';

    /**
     * Cible de la modale : c'est la FICHE elle-même (`$this->id` est
     * `#[Locked]`), rechargée par le service — aucune cible client à mémoriser.
     *
     * @var array<string, mixed>
     */
    public array $appTarget = [];

    /**
     * Dernier run de CETTE extension, mis en forme par l'orchestrateur.
     *
     * @var array<string, mixed>|null
     */
    public ?array $run = null;

    /**
     * Un run actif AILLEURS gèle aussi les boutons de cette fiche : le verrou
     * du moteur est global, la page le reflète.
     *
     * @var array<string, mixed>|null
     */
    public ?array $activeRun = null;

    /** Run suivi par cet onglet (toast de fin émis une seule fois). */
    public int $trackedRunId = 0;

    public string $trackedRunStatus = '';

    // ── Story 56.4 — révocation d'un scope accordé ──────────────────────

    /** Modale de confirmation de la révocation d'un scope. */
    public bool $isRevokeScopeOpen = false;

    /**
     * Scope visé par la modale — mémorisé CÔTÉ SERVEUR entre l'ouverture et la
     * confirmation (patron `appOperation`). Revalidé par le service de toute
     * façon : l'entrée client n'est jamais crue sur parole.
     */
    public string $scopeToRevoke = '';

    public function mount(string $id): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->id = (int) $id;

        $this->loadExtension();
        $this->loadRun();
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

    // ══════════════════════════════════════════════════════════════════════
    // Story 56.3 — opérations `app` (installation, mise à jour, retrait)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Ouvre la modale de confirmation. La cible est la fiche courante,
     * rechargée PAR LE SERVICE : `$this->id` est `#[Locked]`, et le contenu de
     * la modale (provenance, scopes, versions) ne vient jamais du snapshot
     * client.
     */
    public function askAppOperation(string $operation): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if (! in_array($operation, ExtensionInstallRun::OPERATIONS, true)) {
            $this->toastError('Opération non reconnue.');

            return;
        }

        $target = app(ExtensionCatalogService::class)->find($this->id);

        if ($target === null) {
            $this->redirect(route('admin.extensions'), navigate: true);

            return;
        }

        if (($target['type'] ?? '') !== 'app') {
            $this->extension = $target;
            $this->toastError('Cette extension est un lien : elle n\'installe aucun composant système.');

            return;
        }

        $this->extension = $target;
        $this->appTarget = $target;
        $this->appOperation = $operation;
        $this->isAppOperationOpen = true;
    }

    public function confirmAppOperation(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $operation = $this->appOperation;

        // `$this->id` étant `#[Locked]`, il n'y a pas de cible à préserver :
        // fermer la modale est sans effet sur l'action, et un double-clic
        // retombe sur le refus propre de l'orchestrateur.
        $this->isAppOperationOpen = false;

        if ($operation === '') {
            return;
        }

        try {
            $run = app(ExtensionOperationRunner::class)->start($operation, $this->id, auth()->user());
        } catch (ExtensionOperationException $e) {
            $this->toastError($e->getMessage());
            $this->refreshAfterAction();
            $this->loadRun();

            return;
        }

        $this->trackedRunId = (int) $run->id;
        $this->trackedRunStatus = (string) $run->status;

        $this->loadRun();
        $this->toastInfo(($this->run['operation_label'] ?? 'Opération').' en cours — suivez la progression ci-dessous.');
    }

    public function closeAppOperation(): void
    {
        $this->isAppOperationOpen = false;
        $this->appOperation = '';
        $this->appTarget = [];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Story 56.4 — révoquer un scope accordé (FR23)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Ouvre la confirmation. Le scope est mémorisé côté serveur, et vérifié
     * contre les scopes RÉELLEMENT ACCORDÉS tels que le service vient de les
     * lire — pas contre le snapshot de la page, qui peut être périmé.
     */
    public function askRevokeScope(string $scope): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $target = app(ExtensionCatalogService::class)->find($this->id);

        if ($target === null) {
            $this->redirect(route('admin.extensions'), navigate: true);

            return;
        }

        $this->extension = $target;

        if (! in_array($scope, (array) ($this->extension['granted_scopes'] ?? []), true)) {
            // Écran périmé (autre admin, autre onglet) : rien à confirmer.
            $this->toastInfo('Ce scope n\'est plus accordé à cette extension.');

            return;
        }

        $this->scopeToRevoke = $scope;
        $this->isRevokeScopeOpen = true;
    }

    public function confirmRevokeScope(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $scope = $this->scopeToRevoke;

        $this->closeRevokeScope();

        if ($scope === '') {
            return;
        }

        try {
            $result = app(ExtensionScopeService::class)->revokeScope($this->id, $scope, auth()->user());
        } catch (ExtensionLifecycleException $e) {
            $this->toastError($e->getMessage());
            $this->refreshAfterAction();

            return;
        }

        if (! $result['changed']) {
            // No-op ou refus : dans les deux cas l'écran doit repartir de
            // l'état réel — un toast qui contredirait la page serait pire que
            // pas de toast (patron review 54.2 #2).
            $this->toastInfo(match ($result['status']) {
                ExtensionScopeService::STATUS_UNSUPPORTED => 'Ce scope n\'est pas révocable.',
                ExtensionScopeService::STATUS_NO_CLIENT => 'Cette extension n\'a plus de client OIDC actif : il n\'y a rien à révoquer.',
                default => 'Ce scope n\'était déjà plus accordé.',
            });
            $this->refreshAfterAction();

            return;
        }

        $this->loadExtension();
        $this->toastSuccess('Autorisation « '.$scope.' » révoquée.');
    }

    /**
     * ⚠️ Garde présente ICI aussi, contrairement aux autres `close*()` de cette
     * page : les leurs ne font que fermer une modale, celle-ci efface EN PLUS
     * la cible mémorisée côté serveur. Aucun état d'une révocation ne doit être
     * pilotable par quelqu'un qui n'a pas le droit de révoquer.
     */
    public function closeRevokeScope(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->isRevokeScopeOpen = false;
        $this->scopeToRevoke = '';
    }

    /**
     * Rafraîchissement piloté par le `wire:poll` du panneau d'état — rendu
     * SEULEMENT quand il y a une opération à suivre.
     */
    public function pollRun(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->refreshAfterAction();
        $this->loadRun();

        if ($this->trackedRunId === 0 || $this->run === null || (int) $this->run['id'] !== $this->trackedRunId) {
            return;
        }

        $status = (string) $this->run['status'];

        if ($status === $this->trackedRunStatus) {
            return;
        }

        $this->trackedRunStatus = $status;

        if ($status === ExtensionInstallRun::STATUS_SUCCESS) {
            $this->trackedRunId = 0;

            // Même règle que la bibliothèque (review 56.3 #3) : un no-op propre
            // s'annonce en « info », jamais en « terminée ».
            if (($this->run['changed'] ?? true) === false) {
                $this->toastInfo('Rien à faire : l\'extension était déjà dans l\'état demandé.');

                return;
            }

            $this->toastSuccess($this->run['operation_label'].' terminée.');

            return;
        }

        if ($status === ExtensionInstallRun::STATUS_FAILED) {
            $this->trackedRunId = 0;
            $this->toastError($this->run['operation_label'].' en échec : '.$this->run['error_label']);
        }
    }

    // ── Story 56.5 — sonder la santé À LA DEMANDE ───────────────────────

    /**
     * Sonde le backend MAINTENANT et persiste le résultat.
     *
     * C'est le SEUL chemin de sonde à la demande — jamais le rendu (NFR9 : la
     * fiche, comme la navbar, LIT l'état persisté). Ce bouton existe parce qu'un
     * admin qui vient de redémarrer un service ne doit pas attendre 5 minutes
     * pour le constater.
     *
     * Contrairement au check doctor (read-only strict), cette sonde PERSISTE :
     * c'est tout son intérêt, et l'admin qui clique demande explicitement une
     * mesure. Le service reste l'écrivain unique des colonnes `health_*`.
     */
    public function probeNow(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        try {
            $result = app(ExtensionHealthService::class)->checkById($this->id);
        } catch (\Throwable $e) {
            // Une sonde qui explose (cache, DB) ne doit pas casser la fiche.
            report($e);
            $this->toastError('La sonde n\'a pas pu être exécutée.');

            return;
        }

        if ($result === null) {
            // Écran périmé : l'extension a été désinstallée entre-temps (ou
            // n'avait rien à sonder). On remet la fiche en phase plutôt que
            // d'afficher un verdict inventé.
            $this->refreshAfterAction();
            $this->toastInfo('Cette extension n\'a pas de backend à sonder.');

            return;
        }

        $this->loadExtension();

        if ($result['reachable']) {
            $this->toastSuccess('Le backend répond.');

            return;
        }

        $this->toastError('Le backend ne répond pas : '.$result['category']);
    }

    /** Lecture UNIQUE des runs, centralisée dans l'orchestrateur. */
    private function loadRun(): void
    {
        $runner = app(ExtensionOperationRunner::class);

        try {
            $this->run = $runner->latestRunFor($this->id);
            // Le run actif est celui que le serveur reconnaît comme tel — la
            // même méthode que celle qui refuse une seconde opération.
            $this->activeRun = $runner->activeRunRow();
        } catch (\Throwable $e) {
            // Une table de runs illisible ne doit pas casser la fiche.
            report($e);
            $this->run = null;
            $this->activeRun = null;
        }
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

    @php
        // Story 56.3 — le verrou du moteur est GLOBAL : une opération en cours
        // ailleurs gèle aussi les boutons de cette fiche.
        $busy = $activeRun !== null;
        $isRunning = $run !== null && $run['is_active'];
        $canInstall = $extension['type'] === 'app'
            && $extension['status'] === 'available'
            && ($extension['installable'] ?? false);
        $canOperate = $extension['type'] === 'app' && $extension['status'] === 'integrated';
        // Le slot n'est défini que s'il a quelque chose à porter : une `app`
        // disponible mais non installable (bloc `install` absent) n'a AUCUNE
        // action, et un en-tête d'actions vide serait un artefact visuel.
        $hasActions = $extension['type'] === 'link'
            || (! $isRunning && ($canInstall || $canOperate));
    @endphp

    @if ($hasActions)
        <x-slot:actions>
            @if ($extension['type'] === 'link')
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
            @elseif (! $isRunning)
                {{-- Story 56.3 — cycle `app` : les mêmes gestes, en tâche de fond. --}}
                @if ($canInstall)
                    <button type="button" class="btn btn-primary" @disabled($busy)
                        wire:click="askAppOperation('install')" data-testid="app-install-action">
                        <i class="fa-solid fa-plug"></i> Intégrer
                    </button>
                @elseif ($canOperate)
                    @if (($extension['update_available'] ?? false) && ($extension['installable'] ?? false))
                        <button type="button" class="btn btn-info" @disabled($busy)
                            wire:click="askAppOperation('update')" data-testid="app-update-action">
                            <i class="fa-solid fa-arrow-up"></i> Mettre à jour
                        </button>
                    @endif
                    <button type="button" class="btn btn-ghost" @disabled($busy)
                        wire:click="askAppOperation('remove')" data-testid="app-remove-action">
                        <i class="fa-solid fa-trash-can"></i> Désinstaller
                    </button>
                @endif
            @endif
        </x-slot:actions>
    @endif

    <div class="flex flex-col gap-6">

        {{--
            Story 56.3 — Une opération en cours sur une AUTRE extension gèle
            aussi les boutons de cette fiche (le verrou du moteur est global).
            Ce bandeau porte alors le `wire:poll` : sans lui, la fiche resterait
            gelée jusqu'à un rechargement manuel après la fin de l'opération.
        --}}
        @if ($activeRun !== null && (int) $activeRun['extension_id'] !== $id)
            <div class="alert alert-info shadow-sm" wire:poll.3s="pollRun" data-testid="foreign-run-banner">
                <span class="loading loading-spinner loading-sm"></span>
                <div>
                    <p class="font-medium">
                        {{ $activeRun['operation_label'] }} en cours sur une autre extension.
                    </p>
                    <p class="text-sm opacity-80">
                        Les opérations d'extensions s'exécutent une par une sur le serveur : les actions de cette
                        fiche redeviendront disponibles à la fin.
                        @if ($activeRun['requested_by_login'] !== '')
                            Demandée par <strong>{{ $activeRun['requested_by_login'] }}</strong>.
                        @endif
                    </p>
                </div>
            </div>
        @endif

        {{--
            ===== Story 56.3 — État de l'opération en cours ou de la dernière =====
            Le `wire:poll` est porté par ce panneau et n'est RENDU que tant qu'il
            y a une opération vivante à suivre : au repos, la fiche n'émet
            aucune requête (patron `iso-windows`).
        --}}
        @if ($run !== null)
            <div class="card bg-base-100 border {{ $run['is_active'] ? 'border-info' : 'border-base-300' }}"
                @if ($run['is_active']) wire:poll.3s="pollRun" @endif
                data-testid="run-panel">
                <div class="card-body gap-3">
                    <h3 class="card-title text-base">
                        @if ($run['is_active'])
                            <span class="loading loading-spinner loading-sm text-info"></span>
                        @else
                            <i class="fa-solid fa-clock-rotate-left text-primary"></i>
                        @endif
                        {{ $run['operation_label'] }}
                        <span class="badge badge-sm {{ $run['status_badge'] }}"
                            data-testid="run-status">{{ $run['status_label'] }}</span>
                    </h3>

                    <p class="text-xs text-base-content/50">
                        @if ($run['requested_by_login'] !== '')
                            Demandée par <strong>{{ $run['requested_by_login'] }}</strong>.
                        @endif
                        @if ($run['finished_at'] !== null)
                            Terminée le {{ $run['finished_at'] }}.
                        @endif
                    </p>

                    @if (count($run['steps']) > 0)
                        <ul class="text-sm flex flex-col gap-1" data-testid="run-steps">
                            @foreach ($run['steps'] as $step)
                                <li wire:key="run-step-{{ $run['id'] }}-{{ $step['key'] }}"
                                    class="flex items-center gap-2 text-base-content/70">
                                    <i class="fa-solid fa-check text-success text-xs"></i> {{ $step['label'] }}
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($run['is_active'] && $run['current_step_label'] !== '')
                        <p class="text-sm text-info" data-testid="run-current-step">
                            <span class="loading loading-dots loading-xs align-middle"></span>
                            {{ $run['current_step_label'] }}
                        </p>
                    @endif

                    @if ($run['is_stale'])
                        <p class="text-sm text-warning" data-testid="run-stale">
                            Cette opération ne donne plus signe de vie (worker arrêté ou délai dépassé).
                            Vous pouvez la relancer.
                        </p>
                    @elseif ($run['is_failed'])
                        <p class="text-sm text-error" data-testid="run-error">{{ $run['error_label'] }}</p>
                    @endif
                </div>
            </div>
        @endif

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
                            <div class="flex items-center gap-2 text-base-content/70 flex-wrap">
                                <i class="fa-solid fa-code-branch w-4 text-center opacity-50"></i>
                                <span>Version</span>
                                <span class="font-mono">{{ $extension['version'] !== '' ? $extension['version'] : '—' }}</span>

                                {{--
                                    Story 56.3 — `version` (ce que la source
                                    PUBLIE) et `installed_version` (ce qui
                                    TOURNE) sont deux faits distincts : on ne
                                    les affiche ensemble que lorsqu'ils
                                    divergent, c'est-à-dire quand ça veut dire
                                    quelque chose.
                                --}}
                                @if (($extension['update_available'] ?? false))
                                    <span class="badge badge-sm badge-info gap-1" data-testid="update-badge">
                                        <i class="fa-solid fa-arrow-up text-[10px]"></i> Mise à jour disponible
                                    </span>
                                    <span class="text-xs text-base-content/50" data-testid="installed-version">
                                        Version installée : <span class="font-mono">{{ $extension['installed_version'] }}</span>
                                        (catalogue : <span class="font-mono">{{ $extension['version'] }}</span>)
                                    </span>
                                @elseif ($extension['type'] === 'app' && ($extension['installed_version'] ?? '') !== '')
                                    <span class="text-xs text-base-content/50" data-testid="installed-version">
                                        Version installée : <span class="font-mono">{{ $extension['installed_version'] }}</span>
                                    </span>
                                @endif
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

        {{--
            Story 56.5 — accès au journal d'audit, PRÉ-FILTRÉ sur cette
            extension. Hors du `<x-slot:actions>` volontairement : ce slot n'est
            rendu que s'il y a un acte à proposer (56.3), alors qu'un journal se
            consulte toujours — y compris pour une extension qui n'a plus aucune
            action possible.
        --}}
        <div class="flex justify-end">
            <a href="{{ route('admin.extensions.journal', ['ext' => $extension['key']]) }}"
                class="btn btn-ghost btn-sm" wire:navigate data-testid="extension-journal-link">
                <i class="fa-solid fa-clipboard-list"></i> Journal de cette extension
            </a>
        </div>

        {{-- ===================== Santé (56.5, FR34) ===================== --}}
        @if ($extension['health_monitored'] ?? false)
            @php
                // Trois états, JAMAIS deux : « ok », « indisponible », et
                // « inconnu ou périmé » — qui n'est pas une panne (un scheduler
                // arrêté n'arrête pas une extension) et ne doit donc pas se
                // peindre comme telle.
                $healthStale = (bool) ($extension['health_stale'] ?? true);
                $healthStatus = (string) ($extension['health_status'] ?? '');
                $healthBadge = match (true) {
                    $healthStale || $healthStatus === '' => ['badge-ghost', 'fa-circle-question', 'Inconnu ou périmé'],
                    $healthStatus === 'unreachable' => ['badge-error', 'fa-circle-exclamation', 'Indisponible'],
                    default => ['badge-success', 'fa-circle-check', 'Joignable'],
                };
            @endphp
            <div class="card bg-base-100 shadow" data-testid="health-card">
                <div class="card-body">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <h3 class="card-title text-base flex items-center gap-2">
                            <i class="fa-solid fa-heart-pulse opacity-60"></i> Santé
                            <span class="badge badge-sm {{ $healthBadge[0] }} gap-1" data-testid="health-badge">
                                <i class="fa-solid {{ $healthBadge[1] }} text-[10px]"></i> {{ $healthBadge[2] }}
                            </span>
                        </h3>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="probeNow"
                            data-testid="health-probe-now">
                            <i class="fa-solid fa-stethoscope"></i> Sonder maintenant
                        </button>
                    </div>

                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                        <div class="flex items-center gap-2 text-base-content/70 flex-wrap">
                            <i class="fa-solid fa-clock w-4 text-center opacity-50"></i>
                            <span>Dernière mesure</span>
                            <span data-testid="health-checked-at">
                                @if (($extension['health_checked_at'] ?? '') !== '')
                                    {{ $extension['health_checked_at'] }}
                                    <span class="text-xs text-base-content/50">({{ $extension['health_checked_human'] }})</span>
                                @else
                                    Jamais sondée
                                @endif
                            </span>
                        </div>

                        <div class="flex items-center gap-2 text-base-content/70 flex-wrap">
                            <i class="fa-solid fa-code-branch w-4 text-center opacity-50"></i>
                            <span>Version</span>
                            <span class="font-mono" data-testid="health-installed-version">
                                {{ ($extension['installed_version'] ?? '') !== '' ? $extension['installed_version'] : '—' }}
                            </span>
                            {{-- Badge « mise à jour disponible » RÉUTILISÉ de 56.3 : la
                                 règle n'est pas recalculée ici (review 56.1 #3). --}}
                            @if (($extension['update_available'] ?? false))
                                <span class="badge badge-sm badge-info gap-1" data-testid="health-update-badge">
                                    <i class="fa-solid fa-arrow-up text-[10px]"></i>
                                    catalogue : {{ $extension['version'] }}
                                </span>
                            @endif
                        </div>

                        <div class="flex items-start gap-2 text-base-content/70 sm:col-span-2">
                            <i class="fa-solid fa-triangle-exclamation w-4 text-center opacity-50 mt-0.5"></i>
                            <span>Dernier incident</span>
                            <span data-testid="health-last-incident">
                                @if (($extension['health_last_incident_at'] ?? '') !== '')
                                    {{ $extension['health_last_incident_at'] }}
                                    @if (($extension['health_last_incident_detail'] ?? '') !== '')
                                        — {{ $extension['health_last_incident_detail'] }}
                                    @endif
                                @else
                                    Aucun incident enregistré
                                @endif
                            </span>
                        </div>
                    </dl>

                    @if ($healthStale)
                        <p class="text-xs text-base-content/50 mt-2" data-testid="health-stale-note">
                            L'état affiché n'est plus à jour : la sonde planifiée (toutes les 5 minutes) n'a pas
                            tourné récemment. Vérifiez le planificateur de tâches, ou sondez maintenant.
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{-- ===================== Ce que l'extension demande ===================== --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{--
                Autorisations — DEUX volets distincts (Story 56.4, FR23) :

                  • DEMANDÉS  : ce que le manifest déclare. Information, jamais
                    un droit — une extension peut demander ce qu'elle veut.
                  • ACCORDÉS  : l'état RÉEL du client OIDC actif. C'est ce qui
                    est servi, et c'est ce qui se révoque.

                Le volet « accordés » n'est rendu que si l'extension a un client
                OIDC actif (`granted_scopes !== null`) : une `link` ou une `app`
                non installée n'a rien accordé — et « rien » n'est pas « une
                liste vide ».
            --}}
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body">
                    <h3 class="card-title text-base">
                        <i class="fa-solid fa-shield-halved text-primary"></i> Autorisations
                    </h3>

                    {{-- ── Demandés (manifest) ───────────────────────────── --}}
                    <div class="pt-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium">Demandées par le manifest</span>
                            <span class="badge badge-neutral badge-sm">{{ count($extension['scopes']) }}</span>
                        </div>
                        <p class="text-xs text-base-content/50 mt-1">
                            Ce que l'extension déclare vouloir consulter — une information de transparence,
                            pas un droit.
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

                    {{-- ── Accordés (client OIDC actif) ──────────────────── --}}
                    @if (($extension['granted_scopes'] ?? null) !== null)
                        <div class="divider my-2"></div>
                        <div data-testid="granted-scopes-block">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium">Réellement accordées</span>
                                <span class="badge badge-success badge-sm">{{ count($extension['granted_scopes']) }}</span>
                            </div>
                            <p class="text-xs text-base-content/50 mt-1">
                                Ce que l'extension reçoit aujourd'hui. Révoquer une autorisation prend effet
                                immédiatement, y compris pour les sessions en cours ; le geste est
                                irréversible (ré-accorder = désinstaller puis réinstaller).
                            </p>
                            @if (count($extension['granted_scopes']) === 0)
                                <p class="text-sm text-base-content/50 py-3" data-testid="no-granted-scopes">
                                    Aucun scope accordé : l'extension ne reçoit que l'identifiant de l'utilisateur connecté.
                                </p>
                            @else
                                <ul class="flex flex-wrap gap-2 pt-2" data-testid="granted-scopes-list">
                                    @foreach ($extension['granted_scopes'] as $granted)
                                        <li wire:key="granted-scope-{{ $granted }}">
                                            <span class="badge badge-success badge-outline gap-2 font-mono text-xs">
                                                {{ $granted }}
                                                <button type="button" class="text-error hover:opacity-70"
                                                    title="Révoquer « {{ $granted }} »"
                                                    wire:click="askRevokeScope('{{ $granted }}')"
                                                    data-testid="revoke-scope-{{ $granted }}">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
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

    {{-- ========== Modale : révoquer une autorisation (56.4, FR23) ========== --}}
    <x-molecules.modal wire:model="isRevokeScopeOpen" size="max-w-lg" height="h-auto"
        close-method="closeRevokeScope" title="Révoquer une autorisation"
        icon="fa-shield-halved text-error">

        <x-molecules.modal.section>
            <p class="text-sm" data-testid="revoke-scope-text">
                Révoquer <span class="badge badge-outline font-mono text-xs">{{ $scopeToRevoke }}</span>
                pour <strong>{{ $extension['name'] }}</strong> ?
            </p>
            <p class="text-sm text-base-content/60 mt-2">
                L'extension ne recevra plus ces données — <strong>y compris pour ses jetons en cours</strong> :
                l'effet est immédiat, sans attendre la prochaine connexion.
            </p>
            <p class="text-sm text-base-content/60 mt-2">
                Ses utilisateurs continuent de s'y connecter normalement : c'est une donnée qui est
                retirée, pas l'accès. <strong>Le geste est irréversible</strong> — pour ré-accorder cette
                autorisation, il faudra désinstaller puis réinstaller l'extension.
            </p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeRevokeScope">Annuler</button>
            <button type="button" class="btn btn-error" wire:click="confirmRevokeScope"
                data-testid="confirm-revoke-scope">
                <i class="fa-solid fa-ban"></i> Révoquer
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- ===== Story 56.3 — confirmation des opérations `app` (3 usages) ===== --}}
    @include('pages.admin.extensions._partials.app-operation-modal')
</x-organisms.page>
