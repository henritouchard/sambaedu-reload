<?php

use App\Components\Traits\WithToasts;
use App\Models\ExtensionAuditLog;
use App\Services\Extensions\ExtensionAuditJournalService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Story 56.5 (AC5/AC6, FR36) — /admin/extensions/journal : le JOURNAL D'AUDIT
 * du système d'extensions, en lecture seule.
 *
 * Qui a intégré quoi, quand, et ce qui a échoué : intégrations et
 * désinstallations (54.2), actes de source (56.1), installations et retraits
 * (56.2), mises à jour (56.3), révocations d'autorisation (56.4). Tout était
 * écrit depuis 54.2 ; cette page est la première à le LIRE.
 *
 * **Page dédiée plutôt qu'un onglet** (décision n° 6 de la story) : la
 * bibliothèque est déjà dense (cartes, modales, runs), et le journal mélange
 * extensions ET sources — il est transverse au domaine, pas rattaché à une
 * fiche. La fiche y LIE, pré-filtrée (`?ext=<clé>`).
 *
 * **Rendu TOLÉRANT** : une action inconnue du mapping s'affiche telle quelle avec
 * un badge neutre. `action` est un string libre par construction, et cette page
 * verra un jour des actions écrites par une story future.
 *
 * **Ce qui n'apparaît JAMAIS ici** : URL de source, secret, `client_id`,
 * `installed_sha256`. La page ne rend que les colonnes du journal — jamais une
 * relation au-delà des dénormalisations — et tout est échappé par `{{ }}`.
 *
 * NFR15 (3 couches) : aucune requête, aucun Eloquent dans ce composant. Tout
 * passe par {@see ExtensionAuditJournalService} (lecture) et par les statiques du
 * modèle pour le MARQUEUR d'échec d'écriture — un signal d'exploitation, pas une
 * donnée du journal.
 *
 * Sécurité (3 couches) : middlewares du groupe `admin` + `can:server.admin` sur
 * la route + `Gate::allows('server.admin')` DANS `mount()` ET DANS CHAQUE action.
 *
 * ⚠️ Racine Blade unique et STABLE (`<x-organisms.page>`) : aucun `@if` de
 * premier niveau (piège maison du re-render Livewire).
 */
new #[Title('Journal des extensions')] class extends Component {
    use WithPagination;
    use WithToasts;

    /** Filtre par action (`''` = toutes). Pré-filtrable par query param. */
    #[Url]
    public string $action = '';

    /** Filtre par clé d'extension (`''` = toutes) — lien « Journal de cette extension ». */
    #[Url]
    public string $ext = '';

    /**
     * Marqueur « une écriture d'audit a été perdue » (legs review 56.3 #4).
     *
     * `null` = cas normal. Chargé au montage et après acquittement : c'est un
     * signal d'exploitation, il n'a pas besoin d'être temps réel.
     *
     * @var array{first_at: string, last_at: string, count: int}|null
     */
    public ?array $writeFailure = null;

    /** Modale de confirmation de l'acquittement. */
    public bool $isAcknowledgeOpen = false;

    public function mount(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->loadWriteFailure();
    }

    // ── Filtres ─────────────────────────────────────────────────────────

    public function updatingAction(): void
    {
        $this->resetPage();
    }

    public function updatingExt(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->action = '';
        $this->ext = '';
        $this->resetPage();
    }

    // ── AC6 — acquittement du signal d'échec d'écriture ─────────────────

    public function askAcknowledge(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->isAcknowledgeOpen = true;
    }

    public function closeAcknowledge(): void
    {
        $this->isAcknowledgeOpen = false;
    }

    /**
     * Efface le marqueur.
     *
     * ⚠️ N'écrit AUCUNE ligne d'audit — décision assumée (n° 5 de la story) : le
     * marqueur est un signal d'exploitation, pas une donnée de conformité.
     * L'auditer créerait une boucle (que faire si l'audit de l'acquittement
     * échoue ?).
     */
    public function confirmAcknowledge(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        ExtensionAuditLog::acknowledgeWriteFailure();

        $this->isAcknowledgeOpen = false;
        $this->loadWriteFailure();

        $this->toastSuccess('Signal acquitté. Il réapparaîtra si une écriture d\'audit échoue de nouveau.');
    }

    // ── Lecture ─────────────────────────────────────────────────────────

    /**
     * La page courante du journal (tableaux plats — NFR15).
     *
     * Toute défaillance de lecture (table absente pendant la fenêtre
     * `update.sh`) rend une page VIDE plutôt qu'une 500 : patron 54.2 « une
     * bibliothèque illisible ne doit pas rendre une 500 », étendu ici.
     */
    #[Computed]
    public function rows()
    {
        try {
            return app(ExtensionAuditJournalService::class)->page($this->action, $this->ext);
        } catch (\Throwable $e) {
            report($e);

            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, ExtensionAuditJournalService::DEFAULT_PER_PAGE);
        }
    }

    /** @return array<string, string> */
    #[Computed]
    public function knownActions(): array
    {
        return app(ExtensionAuditJournalService::class)->knownActions();
    }

    /** @return list<string> */
    #[Computed]
    public function extensionKeys(): array
    {
        try {
            return app(ExtensionAuditJournalService::class)->extensionKeys();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function loadWriteFailure(): void
    {
        $this->writeFailure = ExtensionAuditLog::writeFailureMarker();
    }
};
?>

<x-organisms.page title="Journal des extensions" icon="fa-solid fa-clipboard-list"
    :back="route('admin.extensions')" back-text="Retour à la bibliothèque"
    description="Qui a intégré, installé, mis à jour, retiré ou révoqué quoi — et ce qui a échoué. Journal en lecture seule, ajout uniquement.">

    <div class="flex flex-col gap-6 pt-2">

        {{--
            Legs review 56.3 #4 — bandeau d'avertissement. Rendu conditionnel
            INTERNE (jamais au premier niveau du composant) : le journal peut
            être incomplet, et un journal de conformité qui ment sans le dire
            serait pire qu'un journal absent.
        --}}
        @if ($writeFailure !== null)
            <div class="alert alert-error shadow-sm" data-testid="audit-write-failure-banner">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div class="flex-1">
                    <p class="font-medium">
                        Ce journal est peut-être INCOMPLET :
                        {{ $writeFailure['count'] }} écriture(s) n'ont pas pu être enregistrées.
                    </p>
                    <p class="text-sm opacity-80">
                        Première occurrence : {{ $writeFailure['first_at'] }} — dernière : {{ $writeFailure['last_at'] }}.
                        Le détail de ce qui a été perdu se trouve dans les logs Laravel
                        (« Trace d'échec NON ÉCRITE »). Vérifiez la santé de la base de données avant d'acquitter.
                    </p>
                </div>
                <button type="button" class="btn btn-sm btn-ghost" wire:click="askAcknowledge"
                    data-testid="acknowledge-audit-failure">
                    <i class="fa-solid fa-check"></i> Acquitter
                </button>
            </div>
        @endif

        {{-- ===================== Filtres ===================== --}}
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex flex-col w-full sm:w-64">
                        <label class="label" for="journal-action-filter">
                            <span class="label-text">Action</span>
                        </label>
                        <select id="journal-action-filter" class="select select-bordered w-full"
                            wire:model.live="action" data-testid="journal-filter-action">
                            <option value="">Toutes les actions</option>
                            @foreach ($this->knownActions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex flex-col w-full sm:w-64">
                        <label class="label" for="journal-ext-filter">
                            <span class="label-text">Extension</span>
                        </label>
                        <select id="journal-ext-filter" class="select select-bordered w-full"
                            wire:model.live="ext" data-testid="journal-filter-extension">
                            <option value="">Toutes les extensions</option>
                            @foreach ($this->extensionKeys as $key)
                                <option value="{{ $key }}">{{ $key }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="button" class="btn btn-ghost" wire:click="resetFilters"
                        data-testid="journal-reset-filters">
                        <i class="fa-solid fa-filter-circle-xmark"></i> Réinitialiser
                    </button>
                </div>

                <p class="text-sm text-base-content/60" data-testid="journal-count">
                    {{ $this->rows->total() }} entrée(s) au journal
                    @if ($action !== '' || $ext !== '')
                        (filtre actif)
                    @endif
                </p>
            </div>
        </div>

        {{-- ===================== Tableau ===================== --}}
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body">
                @if ($this->rows->total() === 0)
                    <div class="flex flex-col items-center text-center py-12" data-testid="journal-empty">
                        <i class="fa-solid fa-clipboard-list text-4xl opacity-30 mb-3"></i>
                        <h3 class="text-lg font-semibold mb-1">Aucune entrée</h3>
                        <p class="text-base-content/60 max-w-lg">
                            Aucun acte n'a encore été consigné, ou aucun ne correspond au filtre.
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-zebra w-full" data-testid="journal-table">
                            <thead>
                                <tr>
                                    <th>Horodatage</th>
                                    <th>Action</th>
                                    <th>Cible</th>
                                    <th>Acteur</th>
                                    <th>Détails</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->rows as $row)
                                    <tr data-testid="journal-row-{{ $row['id'] }}">
                                        <td class="whitespace-nowrap text-sm">{{ $row['at'] }}</td>
                                        <td>
                                            <span class="badge badge-sm {{ $row['action_badge'] }}"
                                                data-testid="journal-action-{{ $row['id'] }}">{{ $row['action_label'] }}</span>
                                        </td>
                                        <td class="text-sm">
                                            <span>{{ $row['target_label'] }}</span>
                                            @if ($row['target_kind'] === 'source')
                                                <span class="badge badge-ghost badge-xs">source</span>
                                            @endif
                                        </td>
                                        <td class="text-sm">
                                            <span class="font-mono text-xs">{{ $row['actor'] }}</span>
                                            @if ($row['is_system'])
                                                <span class="badge badge-ghost badge-xs" title="Tâche planifiée">planifié</span>
                                            @endif
                                        </td>
                                        <td class="text-sm max-w-md break-words">
                                            {{ $row['details'] !== '' ? $row['details'] : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        {{ $this->rows->links() }}
                    </div>
                @endif
            </div>
        </div>

        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <p class="font-medium">Ce que ce journal contient — et ne contiendra jamais</p>
                <p class="text-sm opacity-80">
                    Il consigne les <strong>actes</strong> et les <strong>tentatives en échec</strong> du cycle de
                    vie des extensions et de leurs sources. Il ne porte jamais d'URL de dépôt, de secret ni
                    d'empreinte de paquet : les détails sont des catégories courtes, le reste reste dans les
                    journaux serveur. La supervision de la <em>disponibilité</em> n'y figure pas non plus —
                    c'est de la mesure, pas un acte : elle se lit sur la fiche de chaque extension et dans
                    l'état du système.
                </p>
            </div>
        </div>
    </div>

    {{-- ===== Modale : acquitter le signal d'échec d'écriture (AC6) ===== --}}
    <x-molecules.modal wire:model="isAcknowledgeOpen" size="max-w-lg" height="h-auto"
        close-method="closeAcknowledge" title="Acquitter le signal" icon="fa-check text-warning">

        <x-molecules.modal.section>
            <p class="text-sm">
                Acquitter fait disparaître l'avertissement. <strong>Les lignes perdues ne sont pas
                    récupérables</strong> : ce geste dit seulement « j'ai vu, j'ai traité ».
            </p>
            <p class="text-sm text-base-content/60 mt-2">
                Avant d'acquitter, vérifiez dans les journaux serveur ce qui a été perdu et que la base de
                données va bien. Le signal réapparaîtra si une écriture échoue de nouveau.
            </p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeAcknowledge">Annuler</button>
            <button type="button" class="btn btn-warning" wire:click="confirmAcknowledge"
                data-testid="confirm-acknowledge-audit-failure">
                <i class="fa-solid fa-check"></i> Acquitter
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</x-organisms.page>
