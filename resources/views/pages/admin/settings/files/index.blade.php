<?php

use App\Components\Traits\WithToasts;
use App\Enums\FilePolicyMode;
use App\Services\FilePolicyService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /admin/settings/files — « Gestion des fichiers ».
 *
 * Déclare le DÉFAUT d'instance de la politique de gestion des fichiers (décision
 * Henri 2026-07-17) : comment les utilisateurs accèdent à leurs fichiers, et donc
 * si l'agent monte les lecteurs réseau. Trois modes ({@see FilePolicyMode}) :
 * partages réseau / Nextcloud Desktop / web uniquement. La surcharge PAR PARC se
 * fait dans le formulaire d'édition d'un parc.
 *
 * Le mode gouverne le {@see \App\Services\Agent\Providers\DrivesStateProvider}
 * (gating des lecteurs). La config Nextcloud (URLs) est saisie ici mais consommée
 * par le provisioning du client Desktop (chantier ultérieur).
 *
 * Sécurité : `can:server.admin` sur la route + double garde mount()/save().
 */
new #[Title('Gestion des fichiers')] class extends Component {
    use WithToasts;

    public string $mode = '';
    public string $nextcloudServerUrl = '';
    public string $nextcloudWebUrl = '';

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $config = FilePolicyService::globalConfig();
        $this->mode = $config['mode'];
        $this->nextcloudServerUrl = $config['nextcloud']['server_url'];
        $this->nextcloudWebUrl = $config['nextcloud']['web_url'];
    }

    /** @return array<int, array{value:string, label:string}> */
    public function getModesProperty(): array
    {
        return array_map(
            fn (FilePolicyMode $m): array => ['value' => $m->value, 'label' => $m->label()],
            FilePolicyMode::cases(),
        );
    }

    public function save(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $mode = FilePolicyMode::tryFrom($this->mode);
        if ($mode === null) {
            $this->toastError('Mode de gestion des fichiers invalide.');

            return;
        }

        try {
            $this->validate([
                'nextcloudServerUrl' => ['nullable', 'string', 'max:255'],
                'nextcloudWebUrl' => ['nullable', 'string', 'max:255'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toastError('Configuration Nextcloud invalide.');
            throw $e;
        }

        try {
            FilePolicyService::setGlobal($mode, $this->nextcloudServerUrl, $this->nextcloudWebUrl);
            $this->toastSuccess('Politique de gestion des fichiers enregistrée.');
        } catch (\Throwable $e) {
            Log::error('FilePolicySettings: échec save', ['error' => $e->getMessage()]);
            $this->toastError('Impossible d\'enregistrer la politique. Consultez les logs.');
        }
    }
};
?>

<x-organisms.page title="Gestion des fichiers"
    icon="fa-solid fa-folder-tree"
    description="Politique d'accès aux fichiers des utilisateurs (défaut de l'établissement).">

    <div class="flex flex-col gap-6 pt-4 max-w-3xl">

        <div class="card bg-base-100 shadow-sm border border-base-300">
            <div class="card-body">
                <h3 class="card-title text-lg mb-1">
                    <i class="fa-solid fa-sliders mr-2"></i>
                    Politique par défaut de l'établissement
                </h3>
                <p class="text-sm opacity-70 mb-4">
                    Détermine comment les utilisateurs accèdent à leurs fichiers. Un parc peut
                    surcharger ce défaut depuis sa fiche d'édition. En dehors du mode
                    « Partages réseau », l'agent ne monte <strong>aucun lecteur</strong>
                    (y compris le home K:) — rien ne doit être déposé sur un lecteur.
                </p>

                <form wire:submit.prevent="save" class="space-y-5">
                    <div class="grid grid-cols-1 gap-3">
                        @foreach ($this->modes as $m)
                            <label class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ $mode === $m['value'] ? 'ring-2 ring-primary' : '' }}">
                                <div class="card-body p-4 flex-row items-center gap-3">
                                    <input type="radio" wire:model.live="mode" value="{{ $m['value'] }}"
                                        class="radio radio-primary" />
                                    <span class="font-medium">{{ $m['label'] }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>

                    {{-- Config Nextcloud — utile hors « Partages réseau » (consommée par le
                         provisioning du client Desktop, à venir). --}}
                    <div class="{{ $mode === FilePolicyMode::Partages->value ? 'opacity-50' : '' }}">
                        <div class="divider text-xs opacity-60">Nextcloud</div>
                        <div class="grid grid-cols-1 gap-4">
                            <div class="form-control w-full">
                                <label class="label py-2">
                                    <span class="label-text font-medium">URL du serveur Nextcloud</span>
                                </label>
                                <input type="text" wire:model="nextcloudServerUrl"
                                    placeholder="https://cloud.etablissement.fr"
                                    class="input input-bordered w-full @error('nextcloudServerUrl') input-error @enderror" />
                                @error('nextcloudServerUrl')
                                    <span class="text-xs text-error mt-1">{{ $message }}</span>
                                @enderror
                            </div>
                            <div class="form-control w-full">
                                <label class="label py-2">
                                    <span class="label-text font-medium">URL d'accès web (optionnel)</span>
                                </label>
                                <input type="text" wire:model="nextcloudWebUrl"
                                    placeholder="https://cloud.etablissement.fr/apps/files"
                                    class="input input-bordered w-full @error('nextcloudWebUrl') input-error @enderror" />
                                @error('nextcloudWebUrl')
                                    <span class="text-xs text-error mt-1">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary"
                            wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading wire:target="save" class="loading loading-spinner loading-xs"></span>
                            <i wire:loading.remove wire:target="save" class="fa-solid fa-save"></i>
                            Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Gestion des partages réseau — sous-section de la politique fichiers. --}}
        @can('view-networkshare')
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body flex-row items-center justify-between gap-4">
                    <div>
                        <h3 class="card-title text-lg mb-1">
                            <i class="fa-solid fa-network-wired mr-2"></i>
                            Partages réseau gérés
                        </h3>
                        <p class="text-sm opacity-70">
                            Répertoires réseau (lettres de lecteur) montés en mode « Partages réseau ».
                        </p>
                    </div>
                    <a href="{{ route('admin.shares') }}" class="btn btn-outline btn-sm shrink-0" wire:navigate>
                        <i class="fa-solid fa-arrow-right"></i>
                        Gérer les partages
                    </a>
                </div>
            </div>
        @endcan

    </div>
</x-organisms.page>
