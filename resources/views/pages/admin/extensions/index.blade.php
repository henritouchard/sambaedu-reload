<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\ExtensionLifecycleException;
use App\Exceptions\ExtensionOperationException;
use App\Models\ExtensionInstallRun;
use App\Services\Extensions\ExtensionCatalogService;
use App\Services\Extensions\ExtensionLifecycleService;
use App\Services\Extensions\ExtensionOperationRunner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 54.1 (AC1) / 54.2 (AC1-AC3) — /admin/extensions : BIBLIOTHÈQUE des
 * extensions.
 *
 * Le registre est MULTI-SOURCES dès le socle (AR7) : chaque carte porte la
 * source d'origine. En 54.1 une seule source existe (« Embarquée (SambaEdu) »,
 * manifests du dépôt) ; les sources distantes relèvent de l'Epic 56.
 *
 * Story 54.2 ajoute le geste : « Intégrer » (direct, en un clic) et
 * « Désinstaller » (confirmé par la modale réutilisable) pour le type `link`
 * uniquement — aucun bouton pour un type `app` (rien à proposer avant
 * l'Epic 56). Les deux transitions et leur journal d'audit sont délégués à
 * {@see ExtensionLifecycleService} ; l'id d'action transite par le client
 * (`wire:click`) — le service REVALIDE tout côté serveur (existence, type,
 * état), jamais de confiance aveugle dans un paramètre client.
 *
 * **Story 56.1 — la provenance est impossible à ignorer (FR4, UX-DR4)** :
 * chaque carte porte un badge « Officielle » ou « Tierce » (icône + libellé,
 * jamais une couleur seule), et intégrer une extension d'une source TIERCE
 * passe par une modale d'avertissement nommant l'hôte réel du dépôt. Le
 * un-clic reste réservé aux extensions officielles. Cet avertissement est une
 * garde d'ATTENTION, pas une garde de sécurité : le service d'intégration est
 * inchangé et ne connaît pas la notion de source tierce.
 *
 * NFR15 — 3 couches strictes : toute la donnée vient des services
 * ({@see ExtensionCatalogService} lecture, {@see ExtensionLifecycleService}
 * écriture), aucun Eloquent dans le composant.
 *
 * **Story 56.3 — le cycle `app` s'ouvre, EN TÂCHE DE FOND (FR6/FR11, AR1)** :
 * « Intégrer », « Mettre à jour » et « Désinstaller » apparaissent aussi pour
 * le type `app`, derrière une modale de confirmation unique qui récapitule la
 * PROVENANCE et les SCOPES DEMANDÉS. Ces boutons ne lancent rien eux-mêmes :
 * ils ouvrent un run ({@see ExtensionOperationRunner}) et un Job exécute le
 * MÊME moteur que `ext:install` / `ext:update` / `ext:remove`. La page ne fait
 * ensuite que LIRE l'état du run, en `wire:poll` **conditionnel** — aucun trafic
 * au repos.
 *
 * Le cycle `link` est INCHANGÉ, verbatim : un clic reste synchrone et instantané
 * (rien à installer), et ses modales ne bougent pas.
 *
 * Concurrence : le verrou du moteur étant GLOBAL, tous les boutons d'opération
 * `app` de la page sont désactivés dès qu'un run est actif — la page REFLÈTE ce
 * verrou, elle ne le remplace ni ne le contourne. Un run resté actif après la
 * mort d'un worker cesse de bloquer l'UI (staleness).
 *
 * Sécurité (3 couches) : middlewares du groupe `admin` + `can:server.admin` sur
 * la route + garde `Gate::allows('server.admin')` DANS `mount()` ET DANS
 * CHAQUE méthode d'action (defense-in-depth — la garde de `mount()` ne suffit
 * pas, une ability révoquée après le montage doit rester bloquée).
 */
new #[Title('Extensions')] class extends Component {
    use WithToasts;

    /**
     * Les extensions du registre, déjà mises en forme par le service.
     *
     * @var list<array<string, mixed>>
     */
    public array $extensions = [];

    /** Modale de confirmation de désinstallation. */
    public bool $isUninstallOpen = false;

    /** Cible de la désinstallation en cours (id CLIENT — revalidé par le service). */
    public int $uninstallTargetId = 0;

    /** Nom affiché dans la modale (affichage uniquement, jamais utilisé pour l'action). */
    public string $uninstallTargetName = '';

    /** Modale d'avertissement « source non officielle » (Story 56.1, AC2). */
    public bool $isThirdPartyWarningOpen = false;

    /** Cible de l'intégration en attente de confirmation (id CLIENT — revalidé par le service). */
    public int $integrateTargetId = 0;

    public string $integrateTargetName = '';

    /** Hôte du dépôt d'origine, résolu CÔTÉ SERVEUR (jamais depuis le snapshot client). */
    public string $integrateTargetHost = '';

    // ── Story 56.3 — cycle `app` en tâche de fond ───────────────────────

    /**
     * Dernier run PAR extension, déjà mis en forme par l'orchestrateur.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $runs = [];

    /**
     * Le run actif de l'instance (le verrou du moteur étant global, il n'y en a
     * qu'un), ou `null`.
     *
     * @var array<string, mixed>|null
     */
    public ?array $activeRun = null;

    /** Modale unique de confirmation des opérations `app` (3 usages). */
    public bool $isAppOperationOpen = false;

    /** `install` | `update` | `remove` — résolu et revalidé côté serveur. */
    public string $appOperation = '';

    /** Cible de l'opération (id CLIENT — le service REVALIDE tout). */
    public int $appTargetId = 0;

    /**
     * Fiche de la cible, chargée PAR LE SERVICE au moment d'ouvrir la modale
     * (provenance, scopes, versions) : jamais le snapshot client.
     *
     * @var array<string, mixed>
     */
    public array $appTarget = [];

    /**
     * Run suivi par CET onglet, pour ne toaster la fin qu'UNE fois. Un run
     * lancé par un autre admin n'est pas « suivi » : son avancement s'affiche,
     * mais le toast appartient à celui qui a cliqué.
     */
    public int $trackedRunId = 0;

    public string $trackedRunStatus = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->loadExtensions();
        $this->loadRuns();
    }

    /** Nombre d'extensions déjà intégrées (indicateur d'en-tête). */
    public function integratedCount(): int
    {
        return count(array_filter(
            $this->extensions,
            static fn (array $extension): bool => ($extension['status'] ?? '') === 'integrated',
        ));
    }

    // ── AC1 — Intégrer (direct, un clic) ────────────────────────────────

    public function integrate(int $extensionId): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        try {
            $result = app(ExtensionLifecycleService::class)->integrate($extensionId, auth()->user());
        } catch (ExtensionLifecycleException $e) {
            // ⚠️ Recharger AUSSI sur le chemin d'erreur (review #2) : le refus le
            // plus probable est « introuvable », c'est-à-dire un écran périmé.
            $this->loadExtensions();
            $this->toastError($e->getMessage());

            return;
        }

        if (! $result['changed']) {
            // Le no-op n'arrive QUE quand l'écran est périmé (second admin, onglet
            // dupliqué, clic rejoué). Dire « déjà intégrée » sans rafraîchir la
            // carte laisserait le message et l'écran se contredire.
            $this->loadExtensions();
            $this->toastInfo('Cette extension est déjà intégrée.');

            return;
        }

        $this->loadExtensions();
        $this->toastSuccess('Extension intégrée.');
    }

    // ── Story 56.1 AC2 — Intégrer une extension TIERCE (avertissement) ──

    /**
     * Ouvre l'avertissement « source non officielle » avant l'intégration.
     *
     * La cible et l'hôte sont résolus CÔTÉ SERVEUR (même raison qu'en 54.2,
     * review #6) : `$this->extensions` est réhydraté depuis le snapshot client
     * à chaque requête, et faire confirmer un avertissement sous un hôte que le
     * client a fourni viderait l'avertissement de son sens.
     */
    public function askIntegrate(int $extensionId): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $target = app(ExtensionCatalogService::class)->find($extensionId);

        if ($target === null) {
            $this->loadExtensions();
            $this->toastError('Extension introuvable — la bibliothèque a été rechargée.');

            return;
        }

        // Extension officielle atteinte par ce chemin (écran périmé, source
        // devenue officielle) : rien à avertir, on intègre directement.
        if ((bool) $target['source_is_official']) {
            $this->integrate($extensionId);

            return;
        }

        $this->integrateTargetId = $extensionId;
        $this->integrateTargetName = (string) $target['name'];
        $this->integrateTargetHost = (string) $target['source_host'];
        $this->isThirdPartyWarningOpen = true;
    }

    public function confirmIntegrate(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $extensionId = $this->integrateTargetId;

        // ⚠️ Même piège qu'en 54.2 (review #1) : on ferme VISUELLEMENT sans
        // remettre la cible à zéro, sinon un double-clic rejoue l'appel avec
        // `integrateTargetId = 0`.
        $this->isThirdPartyWarningOpen = false;

        if ($extensionId === 0) {
            return;
        }

        $this->integrate($extensionId);
    }

    public function closeThirdPartyWarning(): void
    {
        $this->isThirdPartyWarningOpen = false;
        $this->integrateTargetId = 0;
        $this->integrateTargetName = '';
        $this->integrateTargetHost = '';
    }

    // ── AC2 — Désinstaller (confirmation par modale) ────────────────────

    public function askUninstall(int $extensionId): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        // ⚠️ Cible résolue CÔTÉ SERVEUR (review #6). `$this->extensions` est
        // réhydraté depuis le snapshot client à chaque requête : y puiser le nom
        // ferait confirmer une désinstallation sous un libellé non vérifié — et
        // afficherait un nom périmé si une synchro a renommé l'extension depuis
        // le chargement de la page.
        $target = app(ExtensionCatalogService::class)->find($extensionId);

        if ($target === null) {
            $this->loadExtensions();
            $this->toastError('Extension introuvable — la bibliothèque a été rechargée.');

            return;
        }

        $this->uninstallTargetId = $extensionId;
        $this->uninstallTargetName = (string) $target['name'];
        $this->isUninstallOpen = true;
    }

    public function confirmUninstall(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $extensionId = $this->uninstallTargetId;

        // ⚠️ NE PAS appeler closeUninstall() ici (review #1) : il remet la cible
        // à 0, et le bouton de confirmation reste cliquable tant que la première
        // réponse n'est pas revenue. Un double-clic rejouait donc la seconde
        // invocation avec `uninstallTargetId = 0` → « Extension #0 introuvable »
        // au lieu du no-op propre exigé par l'AC3. On ferme visuellement, on
        // garde la cible ; la remise à zéro reste au chemin « Annuler ».
        $this->isUninstallOpen = false;

        if ($extensionId === 0) {
            // Confirmation sans cible (modale jamais ouverte, ou déjà soldée) :
            // rien à faire, silencieusement.
            return;
        }

        try {
            $result = app(ExtensionLifecycleService::class)->uninstall($extensionId, auth()->user());
        } catch (ExtensionLifecycleException $e) {
            $this->loadExtensions();
            $this->toastError($e->getMessage());

            return;
        }

        if (! $result['changed']) {
            $this->loadExtensions();
            $this->toastInfo('Cette extension est déjà disponible.');

            return;
        }

        $this->loadExtensions();
        $this->toastSuccess('Extension désinstallée.');
    }

    public function closeUninstall(): void
    {
        $this->isUninstallOpen = false;
        $this->uninstallTargetId = 0;
        $this->uninstallTargetName = '';
    }

    // ══════════════════════════════════════════════════════════════════════
    // Story 56.3 — opérations `app` (installation, mise à jour, retrait)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Ouvre la modale de confirmation d'une opération `app`.
     *
     * ⚠️ La cible est RÉSOLUE CÔTÉ SERVEUR (patron 54.2 review #6, reconduit
     * en 56.1) : `$this->extensions` est réhydraté depuis le snapshot client à
     * chaque requête, et faire confirmer une installation sous une provenance
     * et des scopes fournis par le client viderait la confirmation de son sens.
     *
     * ⚠️ Le nom de méthode évite `update` : c'est un hook Livewire réservé
     * (`updating`/`updated`), comme `upload`.
     */
    public function askAppOperation(int $extensionId, string $operation): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if (! in_array($operation, ExtensionInstallRun::OPERATIONS, true)) {
            $this->toastError('Opération non reconnue.');

            return;
        }

        $target = app(ExtensionCatalogService::class)->find($extensionId);

        if ($target === null || ($target['type'] ?? '') !== 'app') {
            $this->refreshAll();
            $this->toastError('Extension introuvable — la bibliothèque a été rechargée.');

            return;
        }

        $this->appOperation = $operation;
        $this->appTargetId = $extensionId;
        $this->appTarget = $target;
        $this->isAppOperationOpen = true;
    }

    /**
     * Confirme l'opération : crée le run et met le Job en file.
     *
     * ⚠️ Comme en 54.2 (review #1), la modale est fermée VISUELLEMENT sans
     * remettre la cible à zéro : le bouton reste cliquable tant que la première
     * réponse n'est pas revenue, et un double-clic doit retomber sur le refus
     * propre de l'orchestrateur (« déjà en cours »), pas sur « extension #0 ».
     */
    public function confirmAppOperation(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $extensionId = $this->appTargetId;
        $operation = $this->appOperation;

        $this->isAppOperationOpen = false;

        if ($extensionId === 0 || $operation === '') {
            return;
        }

        try {
            $run = app(ExtensionOperationRunner::class)->start($operation, $extensionId, auth()->user());
        } catch (ExtensionOperationException $e) {
            // Refus métier (opération déjà en cours, écran périmé) : jamais une
            // 500, toujours un écran remis en phase avec la base.
            $this->refreshAll();
            $this->toastError($e->getMessage());

            return;
        }

        $this->trackedRunId = (int) $run->id;
        $this->trackedRunStatus = (string) $run->status;

        $this->refreshAll();
        $this->toastInfo(($this->runs[$extensionId]['operation_label'] ?? 'Opération').' en cours — suivez la progression sur la carte.');
    }

    public function closeAppOperation(): void
    {
        $this->isAppOperationOpen = false;
        $this->appOperation = '';
        $this->appTargetId = 0;
        $this->appTarget = [];
    }

    /**
     * Rafraîchissement piloté par le `wire:poll` de la carte en cours.
     *
     * Il n'est rendu QUE s'il y a quelque chose à suivre : au repos, la page
     * n'émet aucune requête. C'est aussi ici que se détecte la FIN d'un run
     * suivi — par comparaison avec le statut du rendu précédent, conservé
     * côté serveur — pour ne toaster qu'une seule fois.
     */
    public function pollRuns(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->refreshAll();

        if ($this->trackedRunId === 0) {
            return;
        }

        $tracked = null;
        foreach ($this->runs as $run) {
            if (($run['id'] ?? 0) === $this->trackedRunId) {
                $tracked = $run;
                break;
            }
        }

        if ($tracked === null) {
            $this->trackedRunId = 0;
            $this->trackedRunStatus = '';

            return;
        }

        $status = (string) $tracked['status'];

        if ($status === $this->trackedRunStatus) {
            return;
        }

        $this->trackedRunStatus = $status;

        if ($status === ExtensionInstallRun::STATUS_SUCCESS) {
            $this->trackedRunId = 0;

            // Review 56.3 #3 — un no-op propre (l'état demandé était déjà en
            // place) est un succès, mais pas un acte : dire « terminée »
            // laisserait croire à cet admin que son clic a fait le travail que
            // quelqu'un d'autre avait déjà fait. Patron du no-op de 54.2.
            if (($tracked['changed'] ?? true) === false) {
                $this->toastInfo('Rien à faire : l\'extension était déjà dans l\'état demandé.');

                return;
            }

            $this->toastSuccess($tracked['operation_label'].' terminée.');

            return;
        }

        if ($status === ExtensionInstallRun::STATUS_FAILED) {
            $this->trackedRunId = 0;
            $this->toastError($tracked['operation_label'].' en échec : '.$tracked['error_label']);
        }
    }

    /** Recharge la bibliothèque depuis le catalogue (après transition, ou au montage). */
    private function loadExtensions(): void
    {
        try {
            $this->extensions = app(ExtensionCatalogService::class)->library();
        } catch (\Throwable $e) {
            // Une bibliothèque illisible ne doit pas rendre une 500 : on affiche
            // l'état vide et on le dit.
            report($e);
            $this->extensions = [];
            $this->toastError("Impossible de charger la bibliothèque d'extensions. Consultez les journaux serveur.");
        }
    }

    /**
     * Recharge l'état des runs. Lecture UNIQUE et défensive (une table de runs
     * illisible ne doit pas casser la bibliothèque), centralisée dans
     * l'orchestrateur : le composant ne connaît aucun modèle.
     */
    private function loadRuns(): void
    {
        $state = app(ExtensionOperationRunner::class)->runsForLibrarySafely();

        $this->runs = $state['by_extension'];
        $this->activeRun = $state['active'];
    }

    /** Catalogue + runs, dans cet ordre : le second commente le premier. */
    private function refreshAll(): void
    {
        $this->loadExtensions();
        $this->loadRuns();
    }
};
?>

<x-organisms.page title="Extensions" icon="fa-solid fa-puzzle-piece"
    description="Bibliothèque des extensions disponibles pour cette instance : ce que vous pouvez intégrer, d'où ça vient et ce que ça demande.">

    <x-slot:actions>
        {{-- Story 56.5 — le journal d'audit FR36, enfin consultable. --}}
        <a href="{{ route('admin.extensions.journal') }}" class="btn btn-ghost" wire:navigate
            data-testid="open-journal">
            <i class="fa-solid fa-clipboard-list"></i> Journal
        </a>
        <a href="{{ route('admin.extensions.sources') }}" class="btn btn-ghost" wire:navigate
            data-testid="manage-sources">
            <i class="fa-solid fa-box-archive"></i> Gérer les sources
        </a>
    </x-slot:actions>

    <div class="flex flex-col gap-6 pt-2">

        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">À quoi sert cette page</p>
                <p class="text-sm opacity-80">
                    Chaque extension est décrite par un <strong>manifest</strong> fourni par sa source.
                    Ouvrez une fiche pour voir sa version, sa description, les
                    <strong>autorisations qu'elle demande</strong> et ses dépendances.
                    L'intégration au lanceur se fera depuis cette bibliothèque.
                </p>
            </div>
        </div>

        @if (count($extensions) === 0)
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body items-center text-center py-16">
                    <i class="fa-solid fa-puzzle-piece text-4xl opacity-30 mb-3"></i>
                    <h3 class="text-lg font-semibold mb-1">Aucune extension</h3>
                    <p class="text-base-content/60 max-w-lg">
                        Aucune extension n'est disponible sur cette instance. Les extensions embarquées
                        sont chargées au déploiement du serveur.
                    </p>
                </div>
            </div>
        @else
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <p class="text-sm text-base-content/60">
                    {{ count($extensions) }} extension(s) au catalogue —
                    {{ $this->integratedCount() }} intégrée(s).
                </p>
            </div>

            {{--
                Story 56.3 — Bandeau d'opération en cours. Il porte le
                `wire:poll` de la page : RENDU SEULEMENT s'il y a quelque chose
                à suivre (patron `iso-windows`), donc zéro trafic au repos.
                Le verrou du moteur étant global, ce bandeau vaut pour toute la
                bibliothèque, pas pour une carte.
            --}}
            @if ($activeRun !== null)
                <div class="alert alert-info shadow-sm" wire:poll.3s="pollRuns" data-testid="active-run-banner">
                    <span class="loading loading-spinner loading-sm"></span>
                    <div>
                        <p class="font-medium">
                            {{ $activeRun['operation_label'] }} en cours —
                            <span class="opacity-80">{{ $activeRun['current_step_label'] !== '' ? $activeRun['current_step_label'] : 'démarrage…' }}</span>
                        </p>
                        <p class="text-sm opacity-80">
                            Les opérations d'extensions s'exécutent une par une sur le serveur : les autres boutons
                            restent indisponibles jusqu'à la fin.
                            @if ($activeRun['requested_by_login'] !== '')
                                Demandée par <strong>{{ $activeRun['requested_by_login'] }}</strong>.
                            @endif
                        </p>
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" data-testid="extensions-grid">
                @foreach ($extensions as $extension)
                    @php
                        $run = $runs[$extension['id']] ?? null;
                        $isRunning = $run !== null && $run['is_active'];
                        $busy = $activeRun !== null;
                        $canInstall = $extension['type'] === 'app'
                            && $extension['status'] === 'available'
                            && ($extension['installable'] ?? false);
                        $canOperate = $extension['type'] === 'app' && $extension['status'] === 'integrated';
                        $hasFooter = $extension['type'] === 'link'
                            || $isRunning
                            || $canInstall
                            || $canOperate
                            || ($run !== null && $run['is_failed']);
                    @endphp
                    {{--
                        ⚠️ Story 54.2 : la carte n'est PLUS un `<a>` entier — un
                        `wire:click` imbriqué dans un `<a>` déclencherait la
                        navigation (et serait du HTML invalide). Racine `<div>`,
                        zone haute cliquable en `<a>`, pied `card-actions` HORS
                        du lien.
                    --}}
                    <div wire:key="extension-{{ $extension['id'] }}"
                        data-testid="extension-card-{{ $extension['id'] }}"
                        class="card bg-base-100 border border-base-300 shadow-sm transition-all hover:shadow-md hover:border-primary/40">
                        {{--
                            `pb-0` UNIQUEMENT quand un pied `card-actions` suit et
                            rattrape le padding (review #7) : sinon une carte sans
                            boutons — type `app`, ou tout état futur sans action —
                            colle ses badges au bord inférieur.
                        --}}
                        <a href="{{ route('admin.extensions.show', ['id' => $extension['id']]) }}"
                            class="card-body gap-3 @if ($hasFooter) pb-0 @endif">
                            <div class="flex items-start gap-3">
                                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <i class="{{ $extension['icon'] !== '' ? $extension['icon'] : 'fa-solid fa-puzzle-piece' }} text-lg"></i>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h2 class="card-title text-base truncate">{{ $extension['name'] }}</h2>
                                    <p class="text-xs text-base-content/60 truncate">
                                        {{ $extension['publisher'] !== '' ? $extension['publisher'] : 'Éditeur non renseigné' }}
                                    </p>
                                </div>
                                <span class="badge badge-sm {{ $extension['status_badge'] }} shrink-0">
                                    {{ $extension['status_label'] }}
                                </span>
                            </div>

                            @if ($extension['description'] !== '')
                                <p class="text-sm text-base-content/70 line-clamp-2">{{ $extension['description'] }}</p>
                            @endif

                            <div class="flex items-center gap-2 flex-wrap text-xs">
                                <span class="badge badge-sm badge-outline gap-1">
                                    <i class="{{ $extension['type_icon'] }} text-[10px]"></i>
                                    {{ $extension['type_label'] }}
                                </span>
                                <span class="badge badge-sm badge-ghost gap-1">
                                    <i class="fa-solid fa-box-archive text-[10px]"></i>
                                    {{ $extension['source_name'] }}
                                </span>

                                {{--
                                    Story 56.5 (FR35) — backend observé
                                    injoignable (état PERSISTÉ, jamais mesuré
                                    ici). Même règle unique que la tuile du
                                    lanceur : la bibliothèque et la navbar ne
                                    peuvent pas se contredire. Un état périmé ou
                                    jamais mesuré n'affiche RIEN.
                                --}}
                                @if ($extension['unavailable'] ?? false)
                                    <span class="badge badge-sm badge-error gap-1"
                                        data-testid="unavailable-badge-{{ $extension['id'] }}"
                                        title="Le backend de cette extension ne répondait pas à la dernière mesure">
                                        <i class="fa-solid fa-circle-exclamation text-[10px]"></i> Indisponible
                                    </span>
                                @endif

                                {{--
                                    Story 56.1 (FR4/UX-DR4) — la provenance ne
                                    doit jamais être ambiguë : icône + libellé,
                                    jamais une couleur seule.
                                --}}
                                @if ($extension['source_is_official'])
                                    <span class="badge badge-sm badge-success gap-1"
                                        data-testid="official-badge-{{ $extension['id'] }}"
                                        title="Source officielle SambaEdu">
                                        <i class="fa-solid fa-certificate text-[10px]"></i> Officielle
                                    </span>
                                @else
                                    <span class="badge badge-sm badge-warning gap-1"
                                        data-testid="third-party-badge-{{ $extension['id'] }}"
                                        title="Source tierce — hors périmètre SambaEdu">
                                        <i class="fa-solid fa-triangle-exclamation text-[10px]"></i> Tierce
                                    </span>
                                @endif

                                {{-- État de la source, discret : une intégrée survit à
                                     la désactivation de sa source, mais l'admin doit
                                     le SAVOIR (c'est lui qui décide de désinstaller). --}}
                                @unless ($extension['source_enabled'])
                                    <span class="badge badge-sm badge-neutral gap-1"
                                        data-testid="source-disabled-badge-{{ $extension['id'] }}">
                                        <i class="fa-solid fa-pause text-[10px]"></i> Source désactivée
                                    </span>
                                @elseif ($extension['source_sync_status'] !== 'ok')
                                    <span class="badge badge-sm {{ $extension['source_sync_badge'] }} gap-1"
                                        data-testid="source-sync-badge-{{ $extension['id'] }}">
                                        <i class="fa-solid fa-circle-exclamation text-[10px]"></i>
                                        {{ $extension['source_sync_label'] }}
                                    </span>
                                @endunless

                                @if ($extension['version'] !== '')
                                    <span class="text-base-content/40 font-mono">v{{ $extension['version'] }}</span>
                                @endif

                                {{-- Story 56.3 — l'écart entre ce qui TOURNE et
                                     ce que la source PUBLIE, dit une seule fois. --}}
                                @if ($extension['update_available'] ?? false)
                                    <span class="badge badge-sm badge-info gap-1"
                                        data-testid="update-badge-{{ $extension['id'] }}"
                                        title="Version installée : {{ $extension['installed_version'] }}">
                                        <i class="fa-solid fa-arrow-up text-[10px]"></i> Mise à jour disponible
                                    </span>
                                @elseif ($extension['type'] === 'app' && $extension['status'] === 'integrated' && ($extension['installed_version'] ?? '') !== '')
                                    <span class="text-base-content/40 font-mono text-[11px]"
                                        data-testid="installed-version-{{ $extension['id'] }}">
                                        installée : {{ $extension['installed_version'] }}
                                    </span>
                                @endif
                            </div>
                        </a>

                        {{-- ===== Story 56.3 — actions et progression du type `app` ===== --}}
                        @if ($extension['type'] === 'app' && $hasFooter)
                            <div class="card-actions justify-end px-6 pb-4 pt-2 flex-col items-stretch gap-2">
                                @if ($isRunning)
                                    <div class="flex items-center gap-2 text-sm"
                                        data-testid="run-progress-{{ $extension['id'] }}">
                                        <span class="loading loading-spinner loading-xs"></span>
                                        <span class="font-medium">{{ $run['operation_label'] }}</span>
                                        <span class="text-base-content/60 truncate">
                                            {{ $run['current_step_label'] !== '' ? $run['current_step_label'] : 'démarrage…' }}
                                        </span>
                                    </div>
                                @else
                                    <div class="flex justify-end gap-2 flex-wrap">
                                        @if ($canInstall)
                                            <button type="button" class="btn btn-primary btn-sm"
                                                @disabled($busy)
                                                wire:click="askAppOperation({{ $extension['id'] }}, 'install')"
                                                data-testid="app-install-{{ $extension['id'] }}">
                                                <i class="fa-solid fa-plug"></i> Intégrer
                                            </button>
                                        @elseif ($canOperate)
                                            @if (($extension['update_available'] ?? false) && ($extension['installable'] ?? false))
                                                <button type="button" class="btn btn-info btn-sm"
                                                    @disabled($busy)
                                                    wire:click="askAppOperation({{ $extension['id'] }}, 'update')"
                                                    data-testid="app-update-{{ $extension['id'] }}">
                                                    <i class="fa-solid fa-arrow-up"></i> Mettre à jour
                                                </button>
                                            @endif
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                @disabled($busy)
                                                wire:click="askAppOperation({{ $extension['id'] }}, 'remove')"
                                                data-testid="app-remove-{{ $extension['id'] }}">
                                                <i class="fa-solid fa-trash-can"></i> Désinstaller
                                            </button>
                                        @endif
                                    </div>
                                @endif

                                {{-- Raison du DERNIER échec, discrète, jusqu'à ce
                                     qu'un nouveau run la remplace. --}}
                                @if ($run !== null && $run['is_failed'])
                                    <p class="text-xs text-error text-right"
                                        data-testid="run-error-{{ $extension['id'] }}">
                                        {{ $run['operation_label'] }} en échec : {{ $run['error_label'] }}
                                    </p>
                                @elseif ($run !== null && $run['is_stale'])
                                    <p class="text-xs text-warning text-right"
                                        data-testid="run-stale-{{ $extension['id'] }}">
                                        {{ $run['operation_label'] }} interrompue — relancez l'opération.
                                    </p>
                                @endif
                            </div>
                        @endif

                        @if ($extension['type'] === 'link')
                            <div class="card-actions justify-end px-6 pb-4 pt-2">
                                @if ($extension['status'] === 'available')
                                    {{-- Officielle : un clic (comportement 54.2 inchangé).
                                         Tierce : avertissement de provenance d'abord. --}}
                                    <button type="button" class="btn btn-primary btn-sm"
                                        wire:click="{{ $extension['source_is_official'] ? 'integrate' : 'askIntegrate' }}({{ $extension['id'] }})"
                                        data-testid="integrate-{{ $extension['id'] }}">
                                        <i class="fa-solid fa-plug"></i> Intégrer
                                    </button>
                                @elseif ($extension['status'] === 'integrated')
                                    <button type="button" class="btn btn-ghost btn-sm"
                                        wire:click="askUninstall({{ $extension['id'] }})"
                                        data-testid="uninstall-{{ $extension['id'] }}">
                                        <i class="fa-solid fa-trash-can"></i> Désinstaller
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ===================== Modale : confirmer la désinstallation (AC2) ===================== --}}
        <x-molecules.modal wire:model="isUninstallOpen" size="max-w-lg" height="h-auto"
            close-method="closeUninstall" title="Désinstaller l'extension" icon="fa-trash-can text-error">

            <x-molecules.modal.section>
                <p class="text-sm">
                    <strong>{{ $uninstallTargetName }}</strong> redevient disponible dans la bibliothèque.
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
                    <strong>Source non officielle : {{ $integrateTargetHost !== '' ? $integrateTargetHost : 'dépôt inconnu' }}</strong>
                    — vous installez sous votre responsabilité.
                </p>
                <p class="text-sm text-base-content/60 mt-2">
                    <strong>{{ $integrateTargetName }}</strong> provient d'un dépôt tiers. SE5 a vérifié que son
                    catalogue est bien signé par la clé enregistrée pour cette source, mais cela n'engage que le
                    dépôt : ni SambaEdu ni votre académie n'ont audité ce que fait cette extension.
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

        {{-- ===== Story 56.3 — confirmation des opérations `app` (3 usages) ===== --}}
        @include('pages.admin.extensions._partials.app-operation-modal')
    </div>
</x-organisms.page>
