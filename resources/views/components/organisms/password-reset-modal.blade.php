<?php

use App\Components\Traits\WithToasts;
use App\Services\BulkResetListingService;
use App\Services\UserService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modale Livewire SFC — Réinitialisation bulk des mots de passe (story 2.6).
 *
 * Déclenchée via les events :
 *   - `open-password-reset-modal` avec `{ users: [...logins], groups: [...ids] }`
 *
 * Flux :
 *   1. Validation permission Gate `user.password.init` (defense in depth)
 *   2. Appel {@see UserService::bulkResetPasswords()} (transaction atomique,
 *      validation préalable AD, rollback SQL si échec)
 *   3. Stockage du listing chiffré dans le cache Redis via
 *      {@see BulkResetListingService::storeListing()} — TTL 20 min, token signé
 *   4. Dispatch event `password-reset-done` + toast + ouverture nouvelle
 *      fenêtre vers l'URL signée du format demandé (PDF/CSV).
 *
 * Sécurité : le mot de passe clair n'est jamais affiché côté UI. Il n'apparaît
 * que dans l'export téléchargé puis est purgé du cache serveur après TTL.
 */
new class extends Component {
    use WithToasts;

    public bool $isOpen = false;
    public bool $isProcessing = false;

    /** @var array<int,string> Logins sélectionnés directement */
    public array $targetLogins = [];

    /** @var array<int,int> IDs de groupes sélectionnés */
    public array $targetGroupIds = [];

    /** Option legacy `change` : si true → pwdLastSet=0 (forcer changement) */
    public bool $forceChangeAtNextLogin = true;

    /** Option legacy `force` : si false → ne réinitialise que les non activés (pwdLastSet==0) */
    public bool $onlyNonActivated = false;

    /** Format demandé : 'pdf' ou 'csv' */
    public string $exportFormat = 'pdf';

    private UserService $userService;
    private BulkResetListingService $listingService;

    public function boot(UserService $userService, BulkResetListingService $listingService): void
    {
        $this->userService = $userService;
        $this->listingService = $listingService;
    }

    #[On('open-password-reset-modal')]
    public function open(array $users = [], array $groups = []): void
    {
        if (!Gate::allows('user.password.init')) {
            $this->toastAccessDenied('Vous n\'avez pas la permission de réinitialiser des mots de passe.');
            return;
        }

        $this->targetLogins = array_values(array_filter(array_map('strval', $users)));
        $this->targetGroupIds = array_values(array_filter(array_map('intval', $groups)));
        $this->forceChangeAtNextLogin = true;
        $this->onlyNonActivated = false;
        $this->exportFormat = 'pdf';
        $this->isProcessing = false;
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->isProcessing = false;
    }

    public function targetCount(): int
    {
        return count($this->targetLogins) + count($this->targetGroupIds);
    }

    public function performReset(): void
    {
        if (!Gate::allows('user.password.init')) {
            $this->toastAccessDenied();
            $this->isOpen = false;
            return;
        }

        if ($this->targetCount() === 0) {
            $this->toastError('Aucun utilisateur ou groupe sélectionné.');
            return;
        }

        $this->isProcessing = true;

        try {
            $result = $this->userService->bulkResetPasswords(
                selection: [
                    'userIds' => $this->targetLogins,
                    'groupIds' => $this->targetGroupIds,
                ],
                force: !$this->onlyNonActivated,
                forceChangeAtNextLogin: $this->forceChangeAtNextLogin,
            );
        } catch (\Throwable $e) {
            Log::error('password-reset-modal: exception pendant bulkResetPasswords', [
                'error' => $e->getMessage(),
            ]);
            $this->toastError('Erreur inattendue : ' . $e->getMessage());
            $this->isProcessing = false;
            return;
        }

        if (!($result['success'] ?? false)) {
            $partialFailures = $result['partial_failures'] ?? [];
            $successfulResults = array_values(array_filter(
                $result['results'] ?? [],
                static fn(array $r): bool => ($r['success'] ?? false) === true
            ));

            if (!empty($partialFailures) && !empty($successfulResults)) {
                // Option A : listing partiel stocké — l'admin peut récupérer les mdp des users réussis
                $operatorId = (int) (auth()->id() ?? 0);
                $token = $this->listingService->storeListing($operatorId, $successfulResults, [
                    'force_change' => $this->forceChangeAtNextLogin,
                    'bulk_operation_id' => $result['bulk_operation_id'],
                ]);
                $pdfUrl = $this->listingService->buildSignedUrl($token, 'pdf');
                $csvUrl = $this->listingService->buildSignedUrl($token, 'csv');

                $this->dispatch('password-reset-done', token: $token);
                $this->toastWarningWithActions(
                    count($successfulResults) . ' réinitialisation(s) réussies, ' . count($partialFailures) . ' échouée(s) (' . implode(', ', $partialFailures) . '). Téléchargez maintenant — expire dans 20 min.',
                    [
                        ['label' => 'Télécharger PDF', 'url' => $pdfUrl],
                        ['label' => 'Télécharger CSV', 'url' => $csvUrl],
                    ],
                    sticky: true
                );
            } else {
                $this->toastError($result['message'] ?? 'Erreur lors de la réinitialisation.');
            }
            $this->isProcessing = false;
            return;
        }

        // Stocker le listing chiffré en cache, retourne un token signé
        $operatorId = (int) (auth()->id() ?? 0);
        $token = $this->listingService->storeListing($operatorId, $result['results'], [
            'force_change' => $this->forceChangeAtNextLogin,
            'bulk_operation_id' => $result['bulk_operation_id'],
        ]);

        $pdfUrl = $this->listingService->buildSignedUrl($token, 'pdf');
        $csvUrl = $this->listingService->buildSignedUrl($token, 'csv');

        $downloadUrl = $this->exportFormat === 'csv' ? $csvUrl : $pdfUrl;

        $successMessage = count($result['results']) . ' mot(s) de passe réinitialisé(s). Téléchargez le fichier maintenant — expire dans 20 min.';
        $this->toastSuccessWithActions($successMessage, [
            ['label' => 'Télécharger PDF', 'url' => $pdfUrl],
            ['label' => 'Télécharger CSV', 'url' => $csvUrl],
        ], sticky: true);

        $this->dispatch('password-reset-done', token: $token);
        $this->dispatch('download-file', url: $downloadUrl);
        $this->isOpen = false;
        $this->isProcessing = false;
    }
};
?>

<div>
    <div class="dialog z-[70]" x-data="{ open: @entangle('isOpen') }" x-show="open" x-cloak>
        <div class="fixed inset-0 bg-black/50 z-[70]" x-show="open" @click="$wire.close()"></div>
        <div class="fixed inset-0 z-[71] flex items-center justify-center p-4" x-show="open">
            <div class="bg-base-100 rounded-lg shadow-xl w-full max-w-xl max-h-[90vh] overflow-y-auto">
                {{-- Header --}}
                <div class="p-4 border-b border-base-300 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">
                            Réinitialiser {{ $this->targetCount() }} mot(s) de passe
                        </h3>
                        <p class="text-xs text-base-content/60 mt-1">
                            {{ count($targetLogins) }} utilisateur(s) direct(s)
                            @if (count($targetGroupIds) > 0)
                                + {{ count($targetGroupIds) }} groupe(s) (membres directs uniquement)
                            @endif
                        </p>
                    </div>
                    <button type="button" class="btn btn-sm btn-circle btn-ghost" wire:click="close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                {{-- Body --}}
                <div class="p-4 space-y-4">
                    {{-- Liste compacte des logins --}}
                    @if (count($targetLogins) > 0)
                        <div class="bg-base-200 rounded p-3">
                            <div class="text-xs font-semibold text-base-content/70 mb-1">
                                Utilisateurs ciblés :
                            </div>
                            <div class="text-sm font-mono">
                                @foreach (array_slice($targetLogins, 0, 10) as $login)
                                    <span class="badge badge-outline badge-sm m-0.5">{{ $login }}</span>
                                @endforeach
                                @if (count($targetLogins) > 10)
                                    <span class="text-xs text-base-content/60">
                                        ... et {{ count($targetLogins) - 10 }} autre(s)
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Option : force (non activés seulement) --}}
                    <label class="flex gap-3 cursor-pointer">
                        <input type="checkbox" wire:model="onlyNonActivated" class="checkbox checkbox-sm" />
                        <div class="flex-1">
                            <div class="font-medium text-sm">Réinitialiser uniquement les non activés</div>
                            <div class="text-xs text-base-content/60">
                                Filtre sur <code>pwdLastSet == 0</code> — utile en rentrée scolaire
                            </div>
                        </div>
                    </label>

                    {{-- Option : change (pwdLastSet 0 vs -1) --}}
                    <div class="space-y-2">
                        <label class="flex gap-3 cursor-pointer">
                            <input type="radio" wire:model.live="forceChangeAtNextLogin" value="1"
                                class="radio radio-sm radio-primary" />
                            <div class="flex-1">
                                <div class="font-medium text-sm">Forcer changement à la prochaine connexion
                                    (recommandé)</div>
                                <div class="text-xs text-base-content/60">
                                    <code>pwdLastSet = 0</code>
                                </div>
                            </div>
                        </label>
                        <label class="flex gap-3 cursor-pointer">
                            <input type="radio" wire:model.live="forceChangeAtNextLogin" value="0"
                                class="radio radio-sm radio-error" />
                            <div class="flex-1">
                                <div class="font-medium text-sm text-error">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    Mot de passe définitif (DANGER)
                                </div>
                                <div class="text-xs text-error/80">
                                    <code>pwdLastSet = -1</code> — l'utilisateur conserve le nouveau mot de passe
                                    sans changement obligatoire. À utiliser uniquement en cas d'incident spécifique.
                                </div>
                            </div>
                        </label>
                    </div>

                    {{-- Format export --}}
                    <div>
                        <div class="text-xs font-semibold text-base-content/70 mb-1">Format d'export :</div>
                        <div class="flex gap-4">
                            <label class="flex gap-2 cursor-pointer">
                                <input type="radio" wire:model="exportFormat" value="pdf" class="radio radio-sm" />
                                <span class="text-sm">PDF (cartouches imprimables)</span>
                            </label>
                            <label class="flex gap-2 cursor-pointer">
                                <input type="radio" wire:model="exportFormat" value="csv" class="radio radio-sm" />
                                <span class="text-sm">CSV (tableur)</span>
                            </label>
                        </div>
                    </div>

                    {{-- Note rollback AD --}}
                    <div class="alert alert-info text-xs">
                        <i class="fa-solid fa-info-circle"></i>
                        <span>
                            L'AD ne permet pas de rollback natif. Si une écriture échoue en cours de lot,
                            les utilisateurs déjà modifiés conservent leur nouveau mot de passe —
                            un rapport d'échec sera affiché.
                        </span>
                    </div>
                </div>

                {{-- Footer --}}
                <div class="p-4 border-t border-base-300 flex justify-between items-center">
                    <button type="button" class="btn btn-ghost" wire:click="close" @disabled($isProcessing)>
                        Annuler
                    </button>
                    <button type="button" class="btn btn-primary" wire:click="performReset"
                        wire:loading.attr="disabled" wire:target="performReset" @disabled($isProcessing)>
                        <span wire:loading wire:target="performReset"
                            class="loading loading-spinner loading-sm"></span>
                        <i wire:loading.remove wire:target="performReset" class="fa-solid fa-key"></i>
                        Réinitialiser et télécharger
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
