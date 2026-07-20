<?php

use App\Components\Traits\WithToasts;
use App\Gpo\Services\WineImageAlreadyQueuedException;
use App\Gpo\Services\WineImageQueuer;
use App\Gpo\Services\WinePrefixScanner;
use App\Services\ShortcutsService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — UI admin native Wine (`/admin/settings/gpo/wine`).
 *
 * Story 16.3c — Volet 1 (AC1.1 → AC1.7). Story 16.9 — déplacement
 * sous `/admin/settings/gpo/wine` (groupe admin).
 *
 * Remplace le legacy `gpo/wine.php` (79 lignes) :
 *  - Form de sélection du conteneur Wine (scan FS `/var/sambaedu/unattended/install/wine`)
 *  - Action "Générer l'image"  → dispatch `GenerateWineImageJob` (queue Laravel,
 *                                 remplacement propre du `batch_command` legacy)
 *  - Action "Générer les raccourcis" → `ShortcutsService::importWineShortcuts`
 *  - Permission `server.admin` (Spatie + middleware via routes/web.php)
 *  - Channel logs `gpo` (catalogue Epic 16)
 *  - Audit F7 corrigé : whitelist regex + Process::run mode array
 *  - Bug legacy `wine.php:52` (`if ($application = $select_application)` = assignment)
 *    NON reproduit — l'attribut `selected` est posé sur l'option strictement égale.
 */
new #[Title('Wine — Gestion des images partagées | SE4FS')] class extends Component {
    use WithToasts;

    /** @var list<string> Préfixes Wine scannés (sans le préfixe `wine-`). */
    public array $prefixes = [];

    /**
     * Valeur sélectionnée dans le `<select>` (= suffixe `<X>` de `wine-<X>`).
     * Chaîne vide = conteneur par défaut `.wine`.
     */
    public string $selectedApplication = '';

    public function mount(WinePrefixScanner $scanner): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $this->prefixes = $scanner->list();
    }

    /**
     * Ouvre la modale de confirmation pour l'action "Générer l'image"
     * (AC1.5 — UX critique : ~10 min d'exécution + lock idempotence).
     */
    public function confirmGenerateImage(): void
    {
        $this->dispatch('open-confirm-modal',
            title: "Générer l'image Wine",
            message: "La génération de l'image Wine pour le conteneur « "
                . ($this->selectedApplication !== '' ? $this->selectedApplication : 'par défaut')
                . " » peut prendre ~10 minutes. La progression est consultable dans les logs `storage/logs/gpo/*.log`. Confirmer ?",
            confirmText: 'Lancer la génération',
            cancelText: 'Annuler',
            variant: 'warning',
            method: 'generateImage',
            params: [],
            wireId: $this->getId(),
        );
    }

    /**
     * Action "Générer l'image" (AC1.3 / AC5.2).
     */
    public function generateImage(WineImageQueuer $queuer): void
    {
        // Validation regex + whitelist (défense en profondeur ; le queuer
        // refait la validation).
        if (preg_match(WineImageQueuer::APPLICATION_REGEX, $this->selectedApplication) !== 1) {
            $this->toastError(
                "Conteneur Wine invalide. Caractères autorisés : lettres, chiffres, point, tiret, underscore.",
            );
            return;
        }

        try {
            $operationId = $queuer->dispatch($this->selectedApplication);

            $this->toastInfo(
                "L'image Wine est en cours de génération (≈ 10 min). "
                . "Surveillez les logs `storage/logs/gpo/*.log` (operation_id `{$operationId}`).",
                'Image en queue',
            );
        } catch (WineImageAlreadyQueuedException $e) {
            $this->toastWarning(
                "Une génération est déjà en cours pour ce conteneur. "
                . "Réessayez à la fin du Job actuel ou consultez les logs.",
                'Génération déjà en cours',
            );
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage(), 'Validation échouée');
        } catch (\Throwable $e) {
            $this->toastError(
                "Erreur inattendue lors du dispatch du Job. Consultez les logs.",
                'Erreur',
            );
        }
    }

    /**
     * Action "Générer les raccourcis" (AC1.4).
     */
    public function generateShortcuts(ShortcutsService $service): void
    {
        if (preg_match(WineImageQueuer::APPLICATION_REGEX, $this->selectedApplication) !== 1) {
            $this->toastError(
                "Conteneur Wine invalide. Caractères autorisés : lettres, chiffres, point, tiret, underscore.",
            );
            return;
        }

        try {
            $addedCount = $service->importWineShortcuts($this->selectedApplication);

            $this->toastSuccess(
                "{$addedCount} raccourci(s) Wine ajouté(s) à `shortcuts.json`.",
                'Raccourcis Wine importés',
            );
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage(), 'Validation échouée');
        } catch (\Throwable $e) {
            // Iso `generateImage()` (review #5 16.3c) : pas d'exposition du
            // détail interne (paths FS `.tmp.<pid>`, etc.) côté UI. Détail
            // dans les logs `gpo`.
            Log::channel('gpo')->error(
                '[gpo.wine.shortcuts.import] failure',
                [
                    'application' => $this->selectedApplication,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );
            $this->toastError(
                "Erreur inattendue lors de la génération des raccourcis. Consultez les logs.",
                'Erreur',
            );
        }
    }
}
?>

<x-organisms.page title="Wine — Gestion des images partagées"
    description="Gérez la génération de l'image Wine partagée et l'import des raccourcis Wine pour les postes Linux.">

    <x-slot:actions>
        <a href="{{ route('admin.gpo.index') }}" class="btn btn-outline btn-sm">
            <i class="fa-solid fa-arrow-left"></i>
            Retour aux GPOs
        </a>
    </x-slot:actions>

    <div id="gpo-wine" class="space-y-6">

        {{-- Bandeau d'information --}}
        <div class="alert alert-info shadow-sm">
            <i class="fa-solid fa-circle-info"></i>
            <div class="text-sm space-y-1">
                <p class="font-medium">Activation du support Wine sur les postes Linux</p>
                <ol class="list-decimal ml-4 space-y-0.5 opacity-90">
                    <li>Ajouter l'application <strong>WPKG Wine</strong> aux parcs de clients Linux concernés.</li>
                    <li>Sur un poste cible, se connecter en <code>se4install</code> et installer les applications Windows souhaitées avec Wine.</li>
                    <li>Par défaut le préfixe <code>.wine</code> est utilisé ; vous pouvez définir des préfixes spécifiques via <code>WINEPREFIX=/home/se4install/.wine-&lt;application&gt;</code>.</li>
                    <li>Vérifier que les raccourcis sur le bureau sont fonctionnels — ils seront récupérés par l'action « Générer les raccourcis ».</li>
                    <li>Se déconnecter de la session <code>se4install</code> et générer l'image partagée avec le bouton ci-dessous (à refaire en cas de mise à jour de Wine).</li>
                </ol>
            </div>
        </div>

        {{-- Form principal --}}
        <div class="card bg-base-100 shadow-sm border border-base-300">
            <div class="card-body space-y-4">
                <h2 class="card-title text-lg">
                    <i class="fa-solid fa-wine-glass text-primary"></i>
                    Conteneur Wine
                </h2>
                <p class="text-sm text-base-content/70">
                    Sélectionnez le conteneur Wine à utiliser. Le conteneur par défaut <code>.wine</code> est toujours disponible.
                    Si plusieurs conteneurs sont générés, les conteneurs <code>.wine-&lt;application&gt;</code> sont automatiquement
                    montés et utilisés sur les postes où l'application WPKG correspondante est activée.
                </p>

                <div class="form-control max-w-xl">
                    <label class="label">
                        <span class="label-text font-medium">Conteneur</span>
                    </label>
                    <select wire:model.live="selectedApplication"
                        name="application"
                        class="select select-bordered"
                        data-testid="wine-prefix-select">
                        {{-- AC1.6 — `selected` sur option strictement égale, pas d'assignment.
                             @legacy-bug fixed: assignment instead of comparison wine.php:52 --}}
                        <option value="" @selected($selectedApplication === '')>
                            Conteneur par défaut (.wine)
                        </option>
                        @foreach ($prefixes as $prefix)
                            <option value="{{ $prefix }}" @selected($selectedApplication === $prefix)>
                                .wine-{{ $prefix }}
                            </option>
                        @endforeach
                    </select>
                    @if (count($prefixes) === 0)
                        <label class="label">
                            <span class="label-text-alt text-base-content/50">
                                Aucun conteneur Wine alternatif détecté dans
                                <code>/var/sambaedu/unattended/install/wine</code>.
                            </span>
                        </label>
                    @endif
                </div>

                <div class="divider my-2"></div>

                {{-- Actions --}}
                <div class="flex flex-wrap gap-3">
                    <button type="button"
                        class="btn btn-primary"
                        wire:click="confirmGenerateImage"
                        wire:loading.attr="disabled"
                        data-testid="wine-generate-image">
                        <span wire:loading.remove wire:target="generateImage,confirmGenerateImage">
                            <i class="fa-solid fa-cog"></i>
                            Générer l'image
                        </span>
                        <span wire:loading wire:target="generateImage,confirmGenerateImage">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            Mise en queue…
                        </span>
                    </button>

                    <button type="button"
                        class="btn btn-secondary"
                        wire:click="generateShortcuts"
                        wire:loading.attr="disabled"
                        data-testid="wine-generate-shortcuts">
                        <span wire:loading.remove wire:target="generateShortcuts">
                            <i class="fa-solid fa-link"></i>
                            Générer les raccourcis
                        </span>
                        <span wire:loading wire:target="generateShortcuts">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            Import en cours…
                        </span>
                    </button>
                </div>

                <p class="text-xs text-base-content/60 mt-1">
                    <i class="fa-solid fa-clock"></i>
                    La génération de l'image peut prendre <strong>~10 minutes</strong>. Vous serez notifié immédiatement
                    de la mise en queue ; consultez ensuite <code>storage/logs/gpo/*.log</code> pour le suivi.
                </p>
            </div>
        </div>
    </div>

    {{-- Modale de confirmation réutilisable (cf. CLAUDE.md). --}}
    <x-molecules.confirm-modal />
</x-organisms.page>
