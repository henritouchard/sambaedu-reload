<?php

use App\Components\Traits\WithToasts;
use App\Exceptions\ExtensionSourceException;
use App\Services\Extensions\ExtensionSourceService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story 56.1 (AC1/AC3/AC5) — /admin/extensions/sources : les SOURCES du
 * catalogue d'extensions.
 *
 * D'où SE5 tire ses extensions : la source embarquée du dépôt (toujours là,
 * jamais désactivable) et les dépôts tiers ajoutés par l'admin. Un dépôt tiers
 * est un site statique publiant `index.json`, sa signature détachée et sa clé
 * publique ; SE5 **pinne** cette clé à l'ajout et vérifie la signature du
 * catalogue AVANT d'en décoder quoi que ce soit.
 *
 * NFR15 — 3 couches strictes : toute la donnée et tous les actes passent par
 * {@see ExtensionSourceService}. Aucun Eloquent, aucun `Http::` dans ce
 * composant : il ne sait ni ce qu'est une signature, ni comment on parle à un
 * dépôt.
 *
 * Sécurité (3 couches) : middlewares du groupe `admin` + `can:server.admin` sur
 * la route + garde `Gate::allows('server.admin')` DANS `mount()` ET DANS CHAQUE
 * action (defense-in-depth : une ability révoquée après le montage doit rester
 * bloquée).
 *
 * ⚠️ Racine Blade unique et STABLE (`<x-organisms.page>`) : un `@if` au premier
 * niveau ferait tomber le re-render Livewire du parent (piège maison).
 */
new #[Title('Sources d\'extensions')] class extends Component {
    use WithToasts;

    /**
     * Les sources du registre, déjà mises en forme par le service.
     *
     * @var list<array<string, mixed>>
     */
    public array $sources = [];

    // ── Modale « Ajouter une source » ───────────────────────────────────

    public bool $isAddOpen = false;

    public string $newName = '';

    public string $newUrl = '';

    public string $newPublicKey = '';

    // ── Modale « Retirer la source » ────────────────────────────────────

    public bool $isRemoveOpen = false;

    /** Cible du retrait (id CLIENT — revalidé par le service). */
    public int $removeTargetId = 0;

    public string $removeTargetName = '';

    public int $removeTargetIntegrated = 0;

    public function mount(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->loadSources();
    }

    // ── AC1 — Ajouter une source ────────────────────────────────────────

    public function openAdd(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->resetAddForm();
        $this->isAddOpen = true;
    }

    public function closeAdd(): void
    {
        $this->isAddOpen = false;
        $this->resetAddForm();
    }

    public function addSource(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        try {
            $result = app(ExtensionSourceService::class)->add(
                $this->newName,
                $this->newUrl,
                $this->newPublicKey,
                auth()->user(),
            );
        } catch (ExtensionSourceException $e) {
            // La modale RESTE ouverte : l'admin corrige sa saisie sans tout
            // ressaisir (URL en http sans clé, clé mal collée, doublon…).
            $this->toastError($e->getMessage());

            return;
        }

        $this->closeAdd();
        $this->loadSources();

        // Le statut de la PREMIÈRE synchro fait le message : une source dont le
        // catalogue est refusé existe bel et bien, elle est simplement inutile
        // tant que la clé ou le dépôt n'est pas corrigé. Le taire donnerait
        // l'illusion d'un ajout réussi.
        match ($result['status']) {
            'ok' => $this->toastSuccess(
                'Source ajoutée — '.$result['loaded'].' extension(s) au catalogue.'
            ),
            'unreachable' => $this->toastWarning(
                'Source ajoutée, mais le dépôt est injoignable pour l\'instant. Réessayez avec « Actualiser ».'
            ),
            default => $this->toastError(
                'Source ajoutée, mais son catalogue a été REFUSÉ : '.$result['error']
                .' — aucune extension n\'est proposée.'
            ),
        };
    }

    // ── AC5/AC7 — Actualiser ────────────────────────────────────────────

    public function refreshSource(int $sourceId): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        try {
            $result = app(ExtensionSourceService::class)->refresh($sourceId, auth()->user());
        } catch (ExtensionSourceException $e) {
            $this->loadSources();
            $this->toastError($e->getMessage());

            return;
        }

        $this->loadSources();

        match ($result['status']) {
            'ok' => $this->toastSuccess('Catalogue vérifié — '.$result['loaded'].' extension(s).'),
            'unreachable' => $this->toastWarning('Dépôt injoignable — le dernier catalogue vérifié reste en place.'),
            default => $this->toastError('Catalogue refusé : '.$result['error']),
        };
    }

    // ── AC3 — Activer / Désactiver ──────────────────────────────────────

    public function toggleSource(int $sourceId, bool $enable): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $service = app(ExtensionSourceService::class);

        try {
            $result = $enable
                ? $service->enable($sourceId, auth()->user())
                : $service->disable($sourceId, auth()->user());
        } catch (ExtensionSourceException $e) {
            $this->loadSources();
            $this->toastError($e->getMessage());

            return;
        }

        $this->loadSources();

        if (! $result['changed']) {
            // Écran périmé (second admin, onglet dupliqué) : on rafraîchit,
            // sinon le message et la liste se contrediraient.
            $this->toastInfo($enable ? 'Cette source est déjà active.' : 'Cette source est déjà désactivée.');

            return;
        }

        $this->toastSuccess($enable
            ? 'Source réactivée — ses extensions redeviennent proposables.'
            : 'Source désactivée — ses extensions non intégrées disparaissent de la bibliothèque.');
    }

    // ── AC3 — Retirer (confirmation par modale) ─────────────────────────

    public function askRemove(int $sourceId): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        // ⚠️ Cible résolue CÔTÉ SERVEUR : `$this->sources` est réhydraté depuis
        // le snapshot client à chaque requête ; y puiser le libellé ferait
        // confirmer un retrait sous un nom non vérifié.
        $target = $this->findSourceRow($sourceId);

        if ($target === null) {
            $this->loadSources();
            $this->toastError('Source introuvable — la liste a été rechargée.');

            return;
        }

        $this->removeTargetId = $sourceId;
        $this->removeTargetName = (string) $target['name'];
        $this->removeTargetIntegrated = (int) $target['integrated_count'];
        $this->isRemoveOpen = true;
    }

    public function confirmRemove(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $sourceId = $this->removeTargetId;

        // ⚠️ NE PAS appeler closeRemove() ici (piège review 54.2 #1) : il remet
        // la cible à 0, et le bouton reste cliquable tant que la première
        // réponse n'est pas revenue. Un double-clic rejouerait l'appel avec
        // `removeTargetId = 0` → « Source #0 introuvable » au lieu d'un no-op
        // propre. On ferme visuellement, on GARDE la cible ; la remise à zéro
        // reste au chemin « Annuler ».
        $this->isRemoveOpen = false;

        if ($sourceId === 0) {
            return;
        }

        try {
            $result = app(ExtensionSourceService::class)->remove($sourceId, auth()->user());
        } catch (ExtensionSourceException $e) {
            $this->loadSources();
            $this->toastError($e->getMessage());

            return;
        }

        $this->loadSources();
        $this->toastSuccess('Source « '.$result['name'].' » retirée.');
    }

    public function closeRemove(): void
    {
        $this->isRemoveOpen = false;
        $this->removeTargetId = 0;
        $this->removeTargetName = '';
        $this->removeTargetIntegrated = 0;
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function findSourceRow(int $sourceId): ?array
    {
        foreach (app(ExtensionSourceService::class)->list() as $row) {
            if ((int) $row['id'] === $sourceId) {
                return $row;
            }
        }

        return null;
    }

    private function resetAddForm(): void
    {
        $this->newName = '';
        $this->newUrl = '';
        $this->newPublicKey = '';
    }

    private function loadSources(): void
    {
        try {
            $this->sources = app(ExtensionSourceService::class)->list();
        } catch (\Throwable $e) {
            // Une liste illisible ne doit pas rendre une 500 : on affiche l'état
            // vide et on le dit (patron 54.2 / correctif review 54.3).
            report($e);
            $this->sources = [];
            $this->toastError('Impossible de charger les sources. Consultez les journaux serveur.');
        }
    }
};
?>

<x-organisms.page title="Sources d'extensions" icon="fa-solid fa-box-archive"
    :back="route('admin.extensions')" back-text="Retour à la bibliothèque"
    description="D'où SE5 tire ses extensions. Chaque dépôt tiers publie un catalogue signé : SE5 vérifie cette signature avant d'en lire quoi que ce soit.">

    <x-slot:actions>
        <button type="button" class="btn btn-primary" wire:click="openAdd" data-testid="open-add-source">
            <i class="fa-solid fa-plus"></i> Ajouter une source
        </button>
    </x-slot:actions>

    <div class="flex flex-col gap-6 pt-2">

        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-shield-halved"></i>
            <div>
                <p class="font-medium">Comment SE5 fait confiance à un dépôt</p>
                <p class="text-sm opacity-80">
                    La <strong>clé publique</strong> d'une source est enregistrée une seule fois, à son ajout —
                    collée par vous, ou lue sur le dépôt si l'adresse est en <code>https</code>. Elle n'est
                    <strong>jamais</strong> retéléchargée ensuite : si le dépôt change de clé, son catalogue est
                    refusé et vous devez retirer puis rajouter la source vous-même. Un dépôt en
                    <code>http</code> exige que vous colliez sa clé.
                </p>
            </div>
        </div>

        <div class="flex flex-col gap-4" data-testid="sources-list">
            @foreach ($sources as $source)
                <div wire:key="source-{{ $source['id'] }}" data-testid="source-card-{{ $source['id'] }}"
                    class="card bg-base-100 border border-base-300 shadow-sm">
                    <div class="card-body gap-4">

                        <div class="flex items-start justify-between gap-4 flex-wrap">
                            <div class="min-w-0 flex-1">
                                <h2 class="card-title text-base flex-wrap gap-2">
                                    <span class="truncate">{{ $source['name'] }}</span>

                                    @if ($source['is_official'])
                                        <span class="badge badge-sm badge-success gap-1" title="Source officielle SambaEdu">
                                            <i class="fa-solid fa-certificate text-[10px]"></i> Officielle
                                        </span>
                                    @else
                                        <span class="badge badge-sm badge-warning gap-1" title="Source tierce — hors périmètre SambaEdu">
                                            <i class="fa-solid fa-triangle-exclamation text-[10px]"></i> Tierce
                                        </span>
                                    @endif

                                    <span class="badge badge-sm badge-ghost">{{ $source['kind_label'] }}</span>

                                    @unless ($source['enabled'])
                                        <span class="badge badge-sm badge-neutral gap-1" data-testid="source-disabled-{{ $source['id'] }}">
                                            <i class="fa-solid fa-pause text-[10px]"></i> Désactivée
                                        </span>
                                    @endunless
                                </h2>

                                <p class="text-xs text-base-content/60 font-mono break-all mt-1">
                                    {{ $source['url'] !== '' ? $source['url'] : 'Manifests embarqués dans le dépôt SE5' }}
                                </p>
                            </div>

                            @if ($source['is_remote'])
                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="badge {{ $source['sync_badge'] }} gap-1"
                                        data-testid="source-sync-{{ $source['id'] }}">
                                        <i class="{{ $source['sync_icon'] }} text-[10px]"></i>
                                        {{ $source['sync_label'] }}
                                    </span>
                                </div>
                            @endif
                        </div>

                        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-2 text-sm">
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-puzzle-piece w-4 text-center opacity-50"></i>
                                <span>{{ $source['extensions_count'] }} extension(s)</span>
                            </div>
                            <div class="flex items-center gap-2 text-base-content/70">
                                <i class="fa-solid fa-plug w-4 text-center opacity-50"></i>
                                <span>{{ $source['integrated_count'] }} intégrée(s)</span>
                            </div>
                            @if ($source['is_remote'])
                                <div class="flex items-center gap-2 text-base-content/70">
                                    <i class="fa-solid fa-clock-rotate-left w-4 text-center opacity-50"></i>
                                    <span>{{ $source['last_synced_at'] !== '' ? $source['last_synced_at'] : 'Jamais synchronisée' }}</span>
                                </div>
                                <div class="flex items-center gap-2 text-base-content/70">
                                    <i class="fa-solid fa-key w-4 text-center opacity-50"></i>
                                    <span class="font-mono text-xs truncate"
                                        title="Clé publique Ed25519 pinnée">{{ $source['public_key_preview'] !== '' ? $source['public_key_preview'] : '—' }}</span>
                                </div>
                            @endif
                        </dl>

                        @if ($source['last_error'] !== '')
                            <div class="alert alert-warning py-2" data-testid="source-error-{{ $source['id'] }}">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span class="text-sm">{{ $source['last_error'] }}</span>
                            </div>
                        @endif

                        @if ($source['is_remote'])
                            <div class="card-actions justify-end">
                                <button type="button" class="btn btn-ghost btn-sm"
                                    wire:click="refreshSource({{ $source['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="refreshSource({{ $source['id'] }})"
                                    data-testid="refresh-source-{{ $source['id'] }}">
                                    <span wire:loading.remove wire:target="refreshSource({{ $source['id'] }})">
                                        <i class="fa-solid fa-rotate"></i>
                                    </span>
                                    <span class="loading loading-spinner loading-xs" wire:loading
                                        wire:target="refreshSource({{ $source['id'] }})"></span>
                                    Actualiser
                                </button>

                                @if ($source['enabled'])
                                    <button type="button" class="btn btn-ghost btn-sm"
                                        wire:click="toggleSource({{ $source['id'] }}, false)"
                                        data-testid="disable-source-{{ $source['id'] }}">
                                        <i class="fa-solid fa-pause"></i> Désactiver
                                    </button>
                                @else
                                    <button type="button" class="btn btn-ghost btn-sm"
                                        wire:click="toggleSource({{ $source['id'] }}, true)"
                                        data-testid="enable-source-{{ $source['id'] }}">
                                        <i class="fa-solid fa-play"></i> Réactiver
                                    </button>
                                @endif

                                <button type="button" class="btn btn-ghost btn-sm text-error"
                                    wire:click="askRemove({{ $source['id'] }})"
                                    data-testid="remove-source-{{ $source['id'] }}">
                                    <i class="fa-solid fa-trash-can"></i> Retirer
                                </button>
                            </div>
                        @else
                            {{-- Source embarquée : aucune action. Ses manifests font partie du déploiement SE5. --}}
                            <p class="text-xs text-base-content/50">
                                Source embarquée dans le déploiement SE5 : elle ne peut être ni désactivée ni retirée.
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ===================== Modale : ajouter une source (AC1) ===================== --}}
    <x-molecules.modal wire:model="isAddOpen" size="max-w-2xl" height="h-auto" close-method="closeAdd"
        title="Ajouter une source d'extensions" icon="fa-box-archive text-primary">

        <x-molecules.modal.section>
            <div class="flex flex-col gap-4 w-full">

                <div class="flex flex-col gap-1.5 w-full">
                    <label class="text-sm font-medium" for="new-source-name">
                        Nom <span class="text-error">*</span>
                    </label>
                    <input id="new-source-name" type="text" class="input input-bordered w-full"
                        wire:model="newName" placeholder="Extensions Académie de Grenoble"
                        data-testid="new-source-name" />
                </div>

                <div class="flex flex-col gap-1.5 w-full">
                    <label class="text-sm font-medium" for="new-source-url">
                        Adresse du dépôt <span class="text-error">*</span>
                    </label>
                    <input id="new-source-url" type="text" class="input input-bordered w-full font-mono text-sm"
                        wire:model="newUrl" placeholder="https://depot.example.org/extensions"
                        data-testid="new-source-url" />
                    <p class="text-xs text-base-content/60">
                        Adresse du dossier contenant <code>index.json</code>, <code>index.json.sig</code> et
                        <code>source.pub</code> — sans le nom de fichier.
                    </p>
                </div>

                <div class="flex flex-col gap-1.5 w-full">
                    <label class="text-sm font-medium" for="new-source-key">Clé publique</label>
                    <textarea id="new-source-key" rows="2"
                        class="textarea textarea-bordered w-full font-mono text-xs"
                        wire:model="newPublicKey" placeholder="Clé Ed25519 en base64 (44 caractères)"
                        data-testid="new-source-key"></textarea>
                    <p class="text-xs text-base-content/60">
                        Facultative si l'adresse est en <code>https</code> : SE5 lira alors
                        <code>source.pub</code> une seule fois. <strong>Obligatoire</strong> si l'adresse est en
                        <code>http</code>.
                    </p>
                </div>
            </div>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeAdd">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="addSource"
                wire:loading.attr="disabled" wire:target="addSource" data-testid="confirm-add-source">
                <span class="loading loading-spinner loading-xs" wire:loading wire:target="addSource"></span>
                Ajouter la source
            </button>
        </x-slot:footer>
    </x-molecules.modal>

    {{-- ===================== Modale : retirer une source (AC3) ===================== --}}
    <x-molecules.modal wire:model="isRemoveOpen" size="max-w-lg" height="h-auto" close-method="closeRemove"
        title="Retirer la source" icon="fa-trash-can text-error">

        <x-molecules.modal.section>
            <p class="text-sm">
                <strong>{{ $removeTargetName }}</strong> et les extensions qu'elle publie disparaissent du
                catalogue.
            </p>
            @if ($removeTargetIntegrated > 0)
                <p class="text-sm text-warning mt-2" data-testid="remove-blocked-hint">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    {{ $removeTargetIntegrated }} extension(s) de cette source sont encore intégrées : le retrait
                    sera refusé tant qu'elles ne seront pas désinstallées depuis la bibliothèque.
                </p>
            @else
                <p class="text-sm text-base-content/60 mt-1">
                    Aucune extension de cette source n'est intégrée : le retrait est sans effet sur ce qui est en
                    service. Vous pourrez rajouter la source plus tard (sa clé sera redemandée).
                </p>
            @endif
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeRemove">Annuler</button>
            <button type="button" class="btn btn-error" wire:click="confirmRemove" data-testid="confirm-remove-source">
                <i class="fa-solid fa-trash-can"></i> Retirer
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</x-organisms.page>
