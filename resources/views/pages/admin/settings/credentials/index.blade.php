<?php

use App\Components\Traits\WithToasts;
use App\Services\ServiceCredentialTotpManager;
use App\Services\ServiceCredentials;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /admin/settings/credentials — Compte de service se4install + TOTP 6 h.
 *
 * Active/désactive la rotation TOTP du mot de passe AD de `se4install` (compte
 * de déploiement). Source de vérité = table chiffrée `service_credentials` ;
 * la boucle `sambaedu:totp:reconcile` réaligne l'AD à chaque fenêtre de 6 h.
 * Voir le contrat anti-désync dans ServiceCredentials / ServiceCredentialTotpReconciler.
 *
 * Sécurité : middleware can:server.admin sur la route + double guard mount().
 */
new #[Title('Compte se4install')] class extends Component {
    use WithToasts;

    /** Compte de service géré (scope actuel : se4install seul). */
    public const ACCOUNT = 'se4install';

    public bool $totpActive = false;

    public ?int $appliedWindow = null;

    public bool $showDeactivateModal = false;

    /** Mot de passe effectif révélé à la demande (null = masqué). */
    public ?string $revealed = null;

    public function mount(): void
    {
        if (!Gate::allows('server.admin')) {
            abort(403);
        }

        $this->loadState();
    }

    private function loadState(): void
    {
        $creds = app(ServiceCredentials::class);
        $this->totpActive = $creds->totpSecret(self::ACCOUNT) !== null;
        $this->appliedWindow = $creds->appliedCounter(self::ACCOUNT);
        $this->revealed = null;
    }

    public function activate(ServiceCredentialTotpManager $manager): void
    {
        if (!Gate::allows('server.admin')) {
            $this->toastAccessDenied();
            return;
        }

        if ($manager->activate(self::ACCOUNT)) {
            $this->toastSuccess('TOTP activé : le mot de passe AD de se4install tourne désormais toutes les 6 h.');
        } else {
            $this->toastError('Échec de l\'activation : écriture AD impossible. Aucune modification appliquée.');
        }

        $this->loadState();
    }

    public function confirmDeactivate(): void
    {
        $this->showDeactivateModal = true;
    }

    public function closeDeactivateModal(): void
    {
        $this->showDeactivateModal = false;
    }

    public function deactivate(ServiceCredentialTotpManager $manager): void
    {
        if (!Gate::allows('server.admin')) {
            $this->toastAccessDenied();
            return;
        }

        if ($manager->deactivate(self::ACCOUNT)) {
            $this->toastSuccess('TOTP désactivé : le mot de passe AD est revenu à la base seule.');
        } else {
            $this->toastError('Échec de la désactivation : écriture AD impossible. TOTP conservé.');
        }

        $this->showDeactivateModal = false;
        $this->loadState();
    }

    public function reveal(ServiceCredentials $creds): void
    {
        $this->revealed = $creds->effectivePassword(self::ACCOUNT);
    }

    public function hide(): void
    {
        $this->revealed = null;
    }
};
?>

<x-organisms.page title="Compte se4install"
    icon="fa-solid fa-key"
    description="Compte de service de déploiement : rotation TOTP 6 h du mot de passe AD"
    back="{{ route('admin.settings') }}">

    <div class="max-w-2xl flex flex-col gap-6 pt-4">

        {{-- Statut --}}
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div class="flex items-center justify-between">
                    <h2 class="card-title text-base">
                        <i class="fa-solid fa-rotate text-primary"></i>
                        Rotation TOTP du mot de passe AD
                    </h2>
                    @if ($totpActive)
                        <span class="badge badge-success gap-1">
                            <i class="fa-solid fa-circle-check"></i> Active
                        </span>
                    @else
                        <span class="badge badge-ghost gap-1">
                            <i class="fa-solid fa-circle-pause"></i> Inactive
                        </span>
                    @endif
                </div>

                <p class="text-sm text-base-content/70">
                    Quand elle est active, le mot de passe AD de <code>se4install</code> vaut
                    <code>base + code TOTP</code> et est réaligné automatiquement à chaque
                    fenêtre de 6 h par la tâche <code>sambaedu:totp:reconcile</code>. Les scripts
                    d'imaging utilisent le mot de passe effectif courant.
                </p>

                @if ($totpActive && $appliedWindow !== null)
                    <p class="text-xs text-base-content/50">
                        Fenêtre appliquée à l'AD : <span class="font-mono">#{{ $appliedWindow }}</span>
                    </p>
                @endif

                <div class="card-actions justify-end items-center gap-2">
                    @if ($totpActive)
                        @if ($revealed === null)
                            <button type="button" class="btn btn-ghost btn-sm"
                                wire:click="reveal" wire:loading.attr="disabled" wire:target="reveal">
                                <i class="fa-solid fa-eye"></i> Afficher le mot de passe effectif
                            </button>
                        @else
                            <code class="text-xs bg-base-200 px-2 py-1 rounded select-all">{{ $revealed }}</code>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="hide">
                                <i class="fa-solid fa-eye-slash"></i> Masquer
                            </button>
                        @endif

                        <button type="button" class="btn btn-error btn-sm"
                            wire:click="confirmDeactivate">
                            <i class="fa-solid fa-circle-stop"></i> Désactiver
                        </button>
                    @else
                        <button type="button" class="btn btn-primary btn-sm"
                            wire:click="activate"
                            wire:loading.attr="disabled" wire:target="activate">
                            <span wire:loading wire:target="activate" class="loading loading-spinner loading-xs"></span>
                            <i wire:loading.remove wire:target="activate" class="fa-solid fa-play"></i>
                            Activer le TOTP
                        </button>
                    @endif
                </div>
            </div>
        </div>

        <div class="alert alert-warning text-sm">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>
                L'activation <strong>change immédiatement</strong> le mot de passe AD de
                <code>se4install</code>. Les scripts d'install lisent le mot de passe effectif,
                mais vérifiez qu'aucune installation n'est en cours au moment du basculement.
            </span>
        </div>
    </div>

    {{-- Modale de confirmation de désactivation --}}
    <x-molecules.modal wire:model="showDeactivateModal" closeMethod="closeDeactivateModal"
        title="Désactiver le TOTP de se4install" icon="fa-circle-stop text-error"
        size="max-w-lg" height="h-auto">

        <x-molecules.modal.section title="Confirmation" icon="fa-triangle-exclamation text-warning" dense>
            <p class="text-sm text-base-content/70">
                Le mot de passe AD de <code>se4install</code> sera remis sur la <strong>base seule</strong>
                (sans code TOTP) et la rotation 6 h sera arrêtée. Le secret TOTP sera effacé.
            </p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeDeactivateModal">Annuler</button>
            <button type="button" class="btn btn-error"
                wire:click="deactivate"
                wire:loading.attr="disabled" wire:target="deactivate">
                <span wire:loading wire:target="deactivate" class="loading loading-spinner loading-xs"></span>
                <i wire:loading.remove wire:target="deactivate" class="fa-solid fa-circle-stop"></i>
                Désactiver
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</x-organisms.page>
