<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\ExtensionLifecycleException;
use App\Services\Extensions\ExtensionCatalogService;
use App\Services\Extensions\ExtensionLifecycleService;
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
 * NFR15 — 3 couches strictes : toute la donnée vient des services
 * ({@see ExtensionCatalogService} lecture, {@see ExtensionLifecycleService}
 * écriture), aucun Eloquent dans le composant.
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

    public function mount(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->loadExtensions();
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
};
?>

<x-organisms.page title="Extensions" icon="fa-solid fa-puzzle-piece"
    description="Bibliothèque des extensions disponibles pour cette instance : ce que vous pouvez intégrer, d'où ça vient et ce que ça demande.">

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

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" data-testid="extensions-grid">
                @foreach ($extensions as $extension)
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
                            class="card-body gap-3 @if ($extension['type'] === 'link') pb-0 @endif">
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
                                @if ($extension['version'] !== '')
                                    <span class="text-base-content/40 font-mono">v{{ $extension['version'] }}</span>
                                @endif
                            </div>
                        </a>

                        @if ($extension['type'] === 'link')
                            <div class="card-actions justify-end px-6 pb-4 pt-2">
                                @if ($extension['status'] === 'available')
                                    <button type="button" class="btn btn-primary btn-sm"
                                        wire:click="integrate({{ $extension['id'] }})"
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
    </div>
</x-organisms.page>
