<?php

use App\Components\Traits\WithToasts;
use App\Services\FilePolicyService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Onglet « Personnels et partagés » de /admin/settings/files.
 *
 * Déclare le réglage GLOBAL d'instance de la politique de gestion des fichiers
 * (décision Henri 2026-07-17) : TROIS CAPACITÉS INDÉPENDANTES —
 *  - `home`      : montage du home perso K: ;
 *  - `shares`    : montage des partages serveur (classes H: + répertoires gérés) ;
 *  - `nextcloud` : provisionnement du client Nextcloud Desktop (à venir).
 *
 * On peut activer/désactiver chacune séparément. Tout désactivé = « web
 * uniquement » (rien monté). Gouverne le
 * {@see \App\Services\Agent\Providers\DrivesStateProvider}. Pas d'override par
 * parc. Composant enfant (nested) — double garde `server.admin`.
 */
new class extends Component {
    use WithToasts;

    public bool $home = true;
    public bool $shares = true;
    public bool $nextcloud = false;
    public string $nextcloudServerUrl = '';

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $config = FilePolicyService::globalConfig();
        $this->home = $config['home'];
        $this->shares = $config['shares'];
        $this->nextcloud = $config['nextcloud'];
        $this->nextcloudServerUrl = $config['nextcloud_server_url'];
    }

    public function save(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        try {
            $this->validate([
                'nextcloudServerUrl' => ['nullable', 'string', 'max:255'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toastError('Configuration Nextcloud invalide.');
            throw $e;
        }

        try {
            FilePolicyService::setGlobal($this->home, $this->shares, $this->nextcloud, $this->nextcloudServerUrl);
            $this->toastSuccess('Politique de gestion des fichiers enregistrée.');
        } catch (\Throwable $e) {
            Log::error('FilePolicySettings: échec save', ['error' => $e->getMessage()]);
            $this->toastError('Impossible d\'enregistrer la politique. Consultez les logs.');
        }
    }
};
?>

<div class="max-w-3xl">
    <div class="card bg-base-100 shadow-sm border border-base-300">
        <div class="card-body">
            <h3 class="card-title text-lg mb-1">
                <i class="fa-solid fa-sliders mr-2"></i>
                Politique par défaut de l'établissement
            </h3>
            <p class="text-sm opacity-70 mb-4">
                Choisissez, indépendamment, ce que l'agent met à disposition sur les postes. Tout
                désactivé = accès <strong>web uniquement</strong> (aucun lecteur monté).
            </p>

            <form wire:submit.prevent="save" class="space-y-5">
                <div class="grid grid-cols-1 gap-3">
                    {{-- Home perso K: --}}
                    <label class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ $home ? 'ring-2 ring-primary' : '' }}">
                        <div class="card-body p-4 flex-row items-center gap-3">
                            <input type="checkbox" wire:model.live="home" class="checkbox checkbox-primary" />
                            <div class="min-w-0 flex-1">
                                <span class="font-medium">Répertoire personnel (K:)</span>
                                <p class="text-xs opacity-70">Monte le home de l'utilisateur (« Mes documents »).</p>
                            </div>
                        </div>
                    </label>

                    {{-- Partages serveur H: --}}
                    <label class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ $shares ? 'ring-2 ring-primary' : '' }}">
                        <div class="card-body p-4 flex-row items-center gap-3">
                            <input type="checkbox" wire:model.live="shares" class="checkbox checkbox-primary" />
                            <div class="min-w-0 flex-1">
                                <span class="font-medium">Partages réseau (H:)</span>
                                <p class="text-xs opacity-70">Monte les classes (H:) et les répertoires réseau gérés.</p>
                            </div>
                        </div>
                    </label>

                    {{-- Nextcloud natif --}}
                    <label class="card bg-base-200 cursor-pointer hover:bg-base-300 transition-colors {{ $nextcloud ? 'ring-2 ring-primary' : '' }}">
                        <div class="card-body p-4 flex-row items-center gap-3">
                            <input type="checkbox" wire:model.live="nextcloud" class="checkbox checkbox-primary" />
                            <div class="min-w-0 flex-1">
                                <span class="font-medium">Nextcloud natif (client Desktop)</span>
                                <p class="text-xs opacity-70">Provisionne le client Nextcloud Desktop sur les postes (à venir).</p>
                            </div>
                        </div>
                    </label>
                </div>

                {{-- Config Nextcloud — utile quand « Nextcloud natif » est actif (consommée
                     par le provisioning du client Desktop, à venir). --}}
                <div class="{{ $nextcloud ? '' : 'opacity-50' }}">
                    <div class="divider text-xs opacity-60">Nextcloud</div>
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
</div>
