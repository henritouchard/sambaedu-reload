<?php

use App\Components\Traits\WithToasts;
use App\Services\BulkResetListingService;
use App\Services\UserService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modale Livewire SFC — Réinitialisation des mots de passe (story 2.6).
 *
 * Déclenchée via `open-password-reset-modal` avec `{ users: [...logins], groups: [...ids] }`.
 *
 * Deux modes de restitution :
 *   - `display` (mono-user uniquement) : flash le mdp en session et redirige
 *     vers la fiche user qui l'affiche via user-header.blade.php.
 *   - `export` : stocke un listing chiffré en cache (TTL 20 min), construit
 *     une URL signée PDF/CSV, et déclenche un auto-download côté navigateur
 *     via l'event `download-file` (listener Alpine dans ce composant).
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

    /** Mode de restitution : 'display' (affichage écran sur fiche user) ou 'export' (PDF/CSV) */
    public string $deliveryMode = 'display';

    /** Format demandé : 'pdf' ou 'csv' (uniquement si deliveryMode === 'export') */
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
        // Affichage écran réservé aux sélections mono-user ; en multi on force l'export.
        $isSingleDirect = count($this->targetLogins) === 1 && count($this->targetGroupIds) === 0;
        $this->deliveryMode = $isSingleDirect ? 'display' : 'export';
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

        $isDisplayMode = $this->deliveryMode === 'display' && count($this->targetLogins) === 1 && count($this->targetGroupIds) === 0;

        if (!($result['success'] ?? false)) {
            $partialFailures = $result['partial_failures'] ?? [];
            $successfulResults = array_values(array_filter($result['results'] ?? [], static fn(array $r): bool => ($r['success'] ?? false) === true));

            if (!empty($partialFailures) && !empty($successfulResults)) {
                // Partiel : on force l'export (affichage écran non pertinent si multi-users).
                $operatorId = (int) (auth()->id() ?? 0);
                $token = $this->listingService->storeListing($operatorId, $successfulResults, [
                    'force_change' => $this->forceChangeAtNextLogin,
                    'bulk_operation_id' => $result['bulk_operation_id'],
                ]);
                $downloadUrl = $this->listingService->buildSignedUrl($token, $this->exportFormat === 'csv' ? 'csv' : 'pdf');

                $this->dispatch('password-reset-done', token: $token);
                $this->dispatch('download-file', url: $downloadUrl);
                $this->toastWarning(count($successfulResults) . ' réinitialisation(s) réussies, ' . count($partialFailures) . ' échouée(s) (' . implode(', ', $partialFailures) . '). Téléchargement démarré — lien valide 20 min.');
            } else {
                $this->toastError($result['message'] ?? 'Erreur lors de la réinitialisation.');
            }
            $this->isProcessing = false;
            return;
        }

        // Mode "affichage écran" (mono-user) : flash le mdp en session et redirige vers la fiche user,
        // qui affichera la cartouche password via user-header.blade.php.
        if ($isDisplayMode) {
            $entry = $result['results'][0] ?? null;
            $newPassword = $entry['new_password'] ?? null;
            $login = $entry['login'] ?? ($this->targetLogins[0] ?? null);

            if ($newPassword === null || $login === null) {
                $this->toastError('Réinitialisation effectuée mais impossible de récupérer le mot de passe généré.');
                $this->isOpen = false;
                $this->isProcessing = false;
                return;
            }

            session()->flash('created_password', $newPassword);
            $this->dispatch('password-reset-done');
            $this->isOpen = false;
            $this->isProcessing = false;
            $this->redirect(route('app.user.show', $login), navigate: true);
            return;
        }

        // Mode "export" : listing chiffré en cache + URL signée + auto-download côté navigateur.
        $operatorId = (int) (auth()->id() ?? 0);
        $token = $this->listingService->storeListing($operatorId, $result['results'], [
            'force_change' => $this->forceChangeAtNextLogin,
            'bulk_operation_id' => $result['bulk_operation_id'],
        ]);

        $downloadUrl = $this->listingService->buildSignedUrl($token, $this->exportFormat === 'csv' ? 'csv' : 'pdf');

        $successMessage = count($result['results']) . ' mot(s) de passe réinitialisé(s). Le téléchargement démarre — lien valide 20 min.';
        $this->toastSuccess($successMessage);

        $this->dispatch('password-reset-done', token: $token);
        $this->dispatch('download-file', url: $downloadUrl);
        $this->isOpen = false;
        $this->isProcessing = false;
    }
};
?>

<div x-data
    @download-file.window="
        const url = $event.detail?.url ?? $event.detail?.[0]?.url;
        if (!url) return;
        const a = document.createElement('a');
        a.href = url;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
    ">
    <div class="dialog z-[70]" x-data="{ open: @entangle('isOpen') }" x-show="open" x-cloak>
        <div class="fixed inset-0 bg-black/50 z-[70]" x-show="open" @click="$wire.close()"></div>
        <div class="fixed inset-0 z-[71] flex items-center justify-center p-4" x-show="open">
            <div class="bg-base-100 rounded-lg shadow-xl w-full max-w-xl">
                {{-- Header --}}
                <div class="p-4 border-b border-base-300 flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold">
                            Réinitialiser {{ $this->targetCount() }} mot(s) de passe
                        </h3>
                        <p class="text-xs text-base-content/50 mt-0.5">
                            {{ count($targetLogins) }} utilisateur(s) direct(s)
                            @if (count($targetGroupIds) > 0)
                                + {{ count($targetGroupIds) }} groupe(s)
                            @endif
                        </p>
                    </div>
                    <button type="button" class="btn btn-sm btn-circle btn-ghost" wire:click="close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                {{-- Body --}}
                <div class="p-4 space-y-3">
                    <div class="text-xs mb-3 font-semibold text-base-content/50 uppercase tracking-wide">Utilisateurs
                    </div>
                    {{-- Liste compacte des logins --}}
                    @if (count($targetLogins) > 0)
                        <div class="bg-base-200 rounded px-2 mb-4 font-mono text-xs flex flex-wrap gap-1">
                            @foreach (array_slice($targetLogins, 0, 10) as $login)
                                <span class="badge badge-outline badge-xs">{{ $login }}</span>
                            @endforeach
                            @if (count($targetLogins) > 10)
                                <span class="text-base-content/50">... +{{ count($targetLogins) - 10 }}</span>
                            @endif
                        </div>
                    @endif


                    {{-- Option : force (non activés seulement) --}}
                    <div class="text-xs mb-3 font-semibold text-base-content/50 uppercase tracking-wide">Mode</div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" wire:model="onlyNonActivated" class="checkbox checkbox-sm" />
                        <span class="text-sm font-medium">Uniquement les non activés</span>
                        <div class="tooltip tooltip-right"
                            data-tip="Ne modifie le mot de passe que des utilisateurs qui n'ont jamais changé leur mot de passe.">
                            <i class="fa-solid fa-circle-info text-base-content/30"></i>
                        </div>
                    </label>

                    {{-- Option : change (pwdLastSet 0 vs -1) --}}
                    <div class="space-y-2">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" wire:model.live="forceChangeAtNextLogin" value="1"
                                class="radio radio-sm radio-primary" />
                            <span class="text-sm font-medium">Forcer changement à la prochaine connexion</span>
                            <div class="tooltip tooltip-right" data-tip="Recommandé">
                                <i class="fa-solid fa-circle-info text-base-content/30 "></i>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" wire:model.live="forceChangeAtNextLogin" value="0"
                                class="radio radio-sm radio-error" />
                            <span class="text-sm font-medium text-error">
                                Mot de passe définitif
                            </span>
                            <div class="tooltip tooltip-left"
                                data-tip="DANGER : l'utilisateur conserve le mot de passe sans changement obligatoire. Uniquement en cas d'incident spécifique.">
                                <i class="fa-solid fa-circle-info text-base-content/30"></i>
                            </div>
                        </label>
                    </div>

                    {{-- Mode de restitution --}}
                    @php
                        $isSingleDirect = count($targetLogins) === 1 && count($targetGroupIds) === 0;
                    @endphp
                    <div class="space-y-2">
                        <div class="text-xs mb-3 font-semibold text-base-content/50 uppercase tracking-wide">Restitution
                        </div>
                        <label
                            class="flex items-center gap-3 {{ $isSingleDirect ? 'cursor-pointer' : 'opacity-40 cursor-not-allowed' }}">
                            <input type="radio" wire:model.live="deliveryMode" value="display"
                                class="radio radio-sm radio-primary" @disabled(!$isSingleDirect) />
                            <span class="text-sm font-medium">Affichage sur la fiche</span>
                            <div class="tooltip tooltip-right"
                                data-tip="{{ $isSingleDirect ? 'Le mot de passe apparaît sur la fiche utilisateur (copier/masquer).' : 'Indisponible en sélection multiple.' }}">
                                <i class="fa-solid fa-circle-info text-base-content/30"></i>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" wire:model.live="deliveryMode" value="export"
                                class="radio radio-sm radio-primary" />
                            <span class="text-sm font-medium">Exporter dans un fichier</span>
                            <div class="tooltip tooltip-right" data-tip="Fichier signé, lien valide 20 min.">
                                <i class="fa-solid fa-circle-info text-base-content/30"></i>
                            </div>
                        </label>
                    </div>

                    {{-- Format export --}}
                    @if ($deliveryMode === 'export')
                        <div class="flex items-center gap-4 pl-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="exportFormat" value="pdf" class="radio radio-xs" />
                                <span class="text-sm">PDF</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="exportFormat" value="csv" class="radio radio-xs" />
                                <span class="text-sm">CSV</span>
                            </label>
                        </div>
                    @endif
                </div>

                 <p class="px-4 text-xs text-base-content/80"> En cas d'échec partiel, les utilisateurs déjà modifiés conservent leur nouveau mot de passe.</p>

                {{-- Footer --}}
                <div class="p-4 border-t border-base-300 flex justify-between items-center">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="close" @disabled($isProcessing)>
                        Annuler
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="performReset"
                        wire:loading.attr="disabled" wire:target="performReset" @disabled($isProcessing)>
                        <span wire:loading wire:target="performReset" class="loading loading-spinner loading-xs"></span>
                        <i wire:loading.remove wire:target="performReset" class="fa-solid fa-key"></i>
                        {{ $deliveryMode === 'export' ? 'Réinitialiser et télécharger' : 'Réinitialiser et afficher' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
