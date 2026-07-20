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
 *
 * **Enregistrement automatique** : chaque bascule persiste immédiatement (pas de
 * bouton « Enregistrer »). L'URL Nextcloud persiste à la sortie du champ
 * (`wire:model.blur`). `save()` reste public — c'est le point d'entrée unique,
 * appelé par le hook `updated()`.
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

    /**
     * Persiste la politique. Appelé par `updated()` à chaque bascule — pas de
     * bouton d'enregistrement.
     */
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
        } catch (\Throwable $e) {
            Log::error('FilePolicySettings: échec save', ['error' => $e->getMessage()]);
            $this->toastError('Impossible d\'enregistrer la politique. Consultez les logs.');
        }
    }

    /**
     * Enregistrement automatique : toute propriété éditable persiste dès sa
     * modification. Silencieux en cas de succès (un toast par bascule serait du
     * bruit) — l'indicateur inline en tête de formulaire fait le retour.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['home', 'shares', 'nextcloud', 'nextcloudServerUrl'], true)) {
            $this->save();
        }
    }
};
?>

<div class="flex flex-col gap-6">

    {{-- Les trois capacités, côte à côte (réglages indépendants et symétriques
         ⇒ lecture horizontale), puis le récapitulatif en pleine largeur. --}}
    <div class="flex flex-col gap-5">

        <div class="flex items-start justify-between gap-4">
            <p class="text-sm text-base-content/70">
                Choisissez, indépendamment, ce que l'agent met à disposition sur les postes. Tout
                désactivé = accès <strong>web uniquement</strong> (aucun lecteur monté).
                Chaque modification est enregistrée immédiatement.
            </p>
            <span class="text-xs text-base-content/50 flex items-center gap-2 shrink-0 pt-0.5"
                wire:loading.class.remove="text-base-content/50" wire:loading.class="text-primary"
                wire:target="home,shares,nextcloud,nextcloudServerUrl">
                <span wire:loading wire:target="home,shares,nextcloud,nextcloudServerUrl"
                    class="loading loading-spinner loading-xs"></span>
                <i wire:loading.remove wire:target="home,shares,nextcloud,nextcloudServerUrl"
                    class="fa-solid fa-check text-success"></i>
                <span wire:loading.remove wire:target="home,shares,nextcloud,nextcloudServerUrl">Enregistré</span>
                <span wire:loading wire:target="home,shares,nextcloud,nextcloudServerUrl">Enregistrement…</span>
            </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            {{-- Home perso K: --}}
            <label class="card bg-base-100 border cursor-pointer transition-all hover:shadow-md
                {{ $home ? 'border-primary/50 shadow-sm' : 'border-base-300' }}">
                <div class="card-body p-5 gap-2">
                    <div class="flex items-start justify-between gap-3">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0
                            {{ $home ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/40' }}">
                            <i class="fa-solid fa-house text-lg"></i>
                        </div>
                        <input type="checkbox" wire:model.live="home" class="toggle toggle-primary"
                            aria-label="Activer le répertoire personnel" />
                    </div>
                    <span class="font-medium">Répertoire personnel (K:)</span>
                    <p class="text-xs text-base-content/60">Monte le home de l'utilisateur (« Mes documents »).</p>
                </div>
            </label>

            {{-- Partages serveur H: --}}
            <label class="card bg-base-100 border cursor-pointer transition-all hover:shadow-md
                {{ $shares ? 'border-primary/50 shadow-sm' : 'border-base-300' }}">
                <div class="card-body p-5 gap-2">
                    <div class="flex items-start justify-between gap-3">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0
                            {{ $shares ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/40' }}">
                            <i class="fa-solid fa-network-wired text-lg"></i>
                        </div>
                        <input type="checkbox" wire:model.live="shares" class="toggle toggle-primary"
                            aria-label="Activer les partages réseau" />
                    </div>
                    <span class="font-medium">Partages réseau (H:)</span>
                    <p class="text-xs text-base-content/60">Monte les classes (H:) et les répertoires réseau gérés.</p>
                </div>
            </label>

            {{-- Nextcloud natif --}}
            <label class="card bg-base-100 border cursor-pointer transition-all hover:shadow-md
                {{ $nextcloud ? 'border-primary/50 shadow-sm' : 'border-base-300' }}">
                <div class="card-body p-5 gap-2">
                    <div class="flex items-start justify-between gap-3">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0
                            {{ $nextcloud ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/40' }}">
                            <i class="fa-solid fa-cloud text-lg"></i>
                        </div>
                        <input disabled title="Nextcloud natif n'est pas encore disponible" type="checkbox" wire:model.live="nextcloud" class="toggle toggle-primary"
                            aria-label="Activer Nextcloud natif" />
                    </div>
                    <span class="font-medium">Nextcloud natif</span>
                    <p class="text-xs text-base-content/60">Provisionne le client Nextcloud Desktop sur les postes (à venir).</p>
                </div>
            </label>
        </div>

        {{-- Config Nextcloud — révélée UNIQUEMENT quand la capacité est active
             (avant : grisée en opacity-50 mais toujours saisissable). --}}
        @if ($nextcloud)
            <div class="rounded-xl border border-primary/30 bg-primary/5 p-5 flex flex-col gap-1">
                <div class="flex items-center gap-2 mb-1">
                    <i class="fa-solid fa-cloud text-primary"></i>
                    <span class="text-sm font-semibold">Configuration Nextcloud</span>
                </div>
                <label class="label" for="nextcloud-server-url">
                    <span class="label-text font-medium">URL du serveur Nextcloud</span>
                </label>
                <input type="text" id="nextcloud-server-url" wire:model.blur="nextcloudServerUrl"
                    placeholder="https://cloud.etablissement.fr"
                    class="input input-bordered w-full @error('nextcloudServerUrl') input-error @enderror" />
                @error('nextcloudServerUrl')
                    <span class="text-xs text-error mt-1">{{ $message }}</span>
                @enderror
            </div>
        @endif
    </div>

    {{-- Récapitulatif : traduction de la config en ce que l'utilisateur verra
         réellement sur son poste. Réactif (toggles en wire:model.live). --}}
    <aside class="card bg-base-100 border border-base-300">
        <div class="card-body p-5 gap-3">
            <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-base-content/60">
                <i class="fa-solid fa-desktop"></i>
                Effet sur le poste
            </h3>

            @if (! $home && ! $shares && ! $nextcloud)
                <div class="rounded-lg border border-warning/40 bg-warning/10 p-3 flex gap-3">
                    <i class="fa-solid fa-globe text-warning mt-0.5"></i>
                    <div class="text-xs">
                        <p class="font-medium">Web uniquement</p>
                        <p class="text-base-content/60">Aucun lecteur monté, aucun client provisionné.</p>
                    </div>
                </div>
            @else
                <p class="text-xs text-base-content/60">Au prochain logon, <strong>tout utilisateur</strong> voit :</p>

                {{-- En pleine largeur, on reprend la grille à 3 colonnes des cartes
                     ci-dessus : chaque effet se lit sous la capacité qui le produit. --}}
                <ul class="grid grid-cols-1 md:grid-cols-3 gap-2 items-start">
                    @if ($home)
                        <li class="flex items-center gap-3 rounded-lg bg-base-200 px-3 py-2">
                            <span class="badge badge-primary badge-sm font-mono shrink-0">K:</span>
                            <span class="text-xs">Répertoire personnel</span>
                        </li>
                    @endif

                    @if ($shares)
                        <li class="flex items-center gap-3 rounded-lg bg-base-200 px-3 py-2">
                            <span class="badge badge-primary badge-sm font-mono shrink-0">H:</span>
                            <span class="text-xs">Classes</span>
                        </li>
                        {{-- Volontairement PAS d'énumération des répertoires réseau
                             gérés : ils dépendent des assignations de chaque
                             utilisateur, alors que ce panneau décrit un réglage
                             GLOBAL. Seule la structure intrinsèque SE5 (K: home,
                             H: classes) est universelle. --}}
                    @endif

                    @if ($nextcloud)
                        <li class="flex items-start gap-3 rounded-lg bg-base-200 px-3 py-2">
                            <i class="fa-solid fa-cloud text-base-content/40 mt-0.5 shrink-0"></i>
                            <span class="text-xs">
                                Le client Nextcloud Desktop
                                @if (trim($nextcloudServerUrl) === '')
                                    <span class="block text-warning mt-0.5">URL du serveur non renseignée</span>
                                @else
                                    <span class="block text-base-content/50 mt-0.5 break-all">{{ $nextcloudServerUrl }}</span>
                                @endif
                            </span>
                        </li>
                    @endif
                </ul>
            @endif
        </div>
    </aside>
</div>
