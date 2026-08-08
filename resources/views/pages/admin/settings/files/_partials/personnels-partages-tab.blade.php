<?php

use App\Components\Traits\WithToasts;
use App\Jobs\ProvisionNextcloudJob;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudProvisioningService;
use App\Services\ServiceCredentials;
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
 *  - `nextcloud` : « Accès Nextcloud » — l'instance monte les partages SMB en
 *                  stockage externe et SE5 provisionne montages et comptes (61.1).
 *
 * On peut activer/désactiver chacune séparément. Tout désactivé = « web
 * uniquement » (rien monté). Gouverne le
 * {@see \App\Services\Agent\Providers\DrivesStateProvider}. Pas d'override par
 * parc. Composant enfant (nested) — double garde `server.admin`.
 *
 * **Enregistrement automatique** : chaque bascule persiste immédiatement (pas de
 * bouton « Enregistrer »). Les champs texte persistent à la sortie du champ
 * (`wire:model.blur`). `save()` reste public — c'est le point d'entrée unique,
 * appelé par le hook `updated()`.
 *
 * ---------------------------------------------------------------------------
 * **LE SECRET NE TRANSITE JAMAIS EN RETOUR (story 61.1).** L'app password admin
 * est un champ d'ÉCRITURE SEULE : il n'est jamais préchargé depuis le stock, et
 * la propriété est VIDÉE dès qu'elle est persistée — sans quoi elle repartirait
 * dans l'instantané Livewire du rendu suivant, c'est-à-dire dans le HTML de la
 * page. L'écran ne montre que le FAIT qu'un secret est enregistré, jamais sa
 * valeur. Un test l'épingle sur le HTML rendu.
 * ---------------------------------------------------------------------------
 *
 * **La capacité « Accès Nextcloud » ne dépend PAS de `home`.** Le montage
 * « Documents » (le home de l'utilisateur, vu par le web) est créé même quand le
 * lecteur K: est désactivé : `home` gouverne ce que l'AGENT monte sur le poste,
 * pas le chemin d'accès web. Les conditionner l'un à l'autre réintroduirait le
 * mode exclusif explicitement refusé le 2026-07-17.
 */
new class extends Component {
    use WithToasts;

    public bool $home = true;
    public bool $shares = true;
    public bool $nextcloud = false;
    public string $nextcloudServerUrl = '';
    public string $nextcloudAdminUser = '';
    public string $nextcloudSmbHost = '';
    public bool $nextcloudVerifyTls = true;

    /**
     * Champ d'écriture seule. **Toujours vide au rendu** — voir le docblock de
     * classe : une propriété Livewire non vidée repart dans le HTML.
     */
    public string $nextcloudAdminPassword = '';

    /** Un secret est-il enregistré ? Le FAIT, jamais la valeur. */
    public bool $hasAdminSecret = false;

    /** Dernier diagnostic de connexion (tableau plat, {@see \App\Services\Nextcloud\NextcloudConnectionProbe}). */
    public ?array $probeResult = null;

    /** Dernier rapport de provisionnement, en tableau (patron `network-share-status`). */
    public ?array $lastReport = null;

    /**
     * Exécution EN COURS, ou trace d'une exécution interrompue. Le rapport n'est
     * mis en cache qu'à la fin : sans ce marqueur, un traitement tué par la file
     * ne laisserait rien à voir et l'écran afficherait le rapport de la fois
     * d'avant comme s'il était le dernier mot.
     *
     * @var array{started_at: string, dry_run: bool}|null
     */
    public ?array $runningSince = null;

    /**
     * Défaut EFFECTIF du serveur SMB quand le champ est laissé vide : le serveur
     * de fichiers déjà connu de l'instance, celui que l'agent substitue au jeton
     * `<se4fs>` dans les UNC des lecteurs. Affiché en indication de saisie —
     * jamais recopié dans la valeur, pour qu'il continue de suivre la config.
     */
    public string $smbHostFallback = '';

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
        $this->nextcloudAdminUser = $config['nextcloud_admin_user'];
        $this->nextcloudSmbHost = $config['nextcloud_smb_host'];
        $this->nextcloudVerifyTls = $config['nextcloud_verify_tls'];

        $this->smbHostFallback = trim((string) config('sambaedu.se4fs_name', ''));
        $this->hasAdminSecret = app(ServiceCredentials::class)->has(NextcloudConnectionConfig::CREDENTIAL_NAME);
        $this->lastReport = app(NextcloudProvisioningService::class)->lastReport();
        $this->runningSince = app(NextcloudProvisioningService::class)->runningSince();
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
                'nextcloudServerUrl' => ['nullable', 'string', 'max:255', 'regex:/^$|^https?:\/\/\S+$/'],
                'nextcloudAdminUser' => ['nullable', 'string', 'max:255'],
                'nextcloudSmbHost' => ['nullable', 'string', 'max:255'],
            ], [
                'nextcloudServerUrl.regex' => 'L\'URL doit commencer par http:// ou https://.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toastError('Configuration Nextcloud invalide.');
            throw $e;
        }

        try {
            FilePolicyService::setGlobal(
                $this->home,
                $this->shares,
                $this->nextcloud,
                $this->nextcloudServerUrl,
                $this->nextcloudAdminUser,
                $this->nextcloudSmbHost,
                $this->nextcloudVerifyTls,
            );
        } catch (\Throwable $e) {
            Log::error('FilePolicySettings: échec save', ['error' => $e->getMessage()]);
            $this->toastError('Impossible d\'enregistrer la politique. Consultez les logs.');
        }
    }

    /**
     * Enregistre l'app password admin puis **vide immédiatement la propriété**.
     * Le secret ne fait qu'un aller : navigateur → serveur → stock chiffré.
     */
    public function saveAdminPassword(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $secret = trim($this->nextcloudAdminPassword);
        $this->nextcloudAdminPassword = '';

        if ($secret === '') {
            return;
        }

        try {
            app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, $secret);
            $this->hasAdminSecret = true;
            $this->toastSuccess('App password admin enregistré (chiffré).');
        } catch (\Throwable $e) {
            // Le message d'erreur ne cite JAMAIS le secret, ni sa longueur.
            Log::error('FilePolicySettings: échec enregistrement du secret Nextcloud', ['error' => $e->getMessage()]);
            $this->toastError('Impossible d\'enregistrer l\'app password. Consultez les logs.');
        }
    }

    /** Retire le secret enregistré (app password révoqué côté instance). */
    public function forgetAdminPassword(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        app(ServiceCredentials::class)->forget(NextcloudConnectionConfig::CREDENTIAL_NAME);
        $this->hasAdminSecret = false;
        $this->probeResult = null;
        $this->toastSuccess('App password admin retiré.');
    }

    /**
     * « Tester la connexion » — trois diagnostics distincts (instance injoignable /
     * privilège insuffisant / app « Stockage externe » absente), jamais un
     * « ça ne marche pas » qui ferait chercher au mauvais endroit.
     */
    public function testConnection(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $probe = app(NextcloudProvisioningService::class)->probe();
        $this->probeResult = $probe->toArray();

        $probe->isOk()
            ? $this->toastSuccess('Connexion Nextcloud établie.')
            : $this->toastError($probe->message);
    }

    /**
     * « Provisionner » — enfile le MÊME service que `nextcloud:provision`. Le
     * bouton n'est pas un second chemin d'exécution.
     */
    public function provision(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        ProvisionNextcloudJob::dispatch(auth()->user()?->login);
        $this->toastSuccess('Provisionnement Nextcloud enfilé. Le rapport apparaîtra ici une fois terminé.');
    }

    /** Recharge le dernier rapport (le traitement s'exécute hors requête). */
    public function refreshReport(): void
    {
        $this->lastReport = app(NextcloudProvisioningService::class)->lastReport();
        $this->runningSince = app(NextcloudProvisioningService::class)->runningSince();
    }

    /**
     * Enregistrement automatique : toute propriété éditable persiste dès sa
     * modification. Silencieux en cas de succès (un toast par bascule serait du
     * bruit) — l'indicateur inline en tête de formulaire fait le retour.
     */
    public function updated(string $property): void
    {
        if ($property === 'nextcloudAdminPassword') {
            $this->saveAdminPassword();

            return;
        }

        if (in_array($property, [
            'home', 'shares', 'nextcloud',
            'nextcloudServerUrl', 'nextcloudAdminUser', 'nextcloudSmbHost', 'nextcloudVerifyTls',
        ], true)) {
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

            {{-- Accès Nextcloud — libellé au SUJET neutre, l'état est porté par la
                 valeur du réglage (convention des capacités). --}}
            <label class="card bg-base-100 border cursor-pointer transition-all hover:shadow-md
                {{ $nextcloud ? 'border-primary/50 shadow-sm' : 'border-base-300' }}">
                <div class="card-body p-5 gap-2">
                    <div class="flex items-start justify-between gap-3">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0
                            {{ $nextcloud ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/40' }}">
                            <i class="fa-solid fa-cloud text-lg"></i>
                        </div>
                        <input type="checkbox" wire:model.live="nextcloud" class="toggle toggle-primary"
                            aria-label="Accès Nextcloud" />
                    </div>
                    <span class="font-medium">Accès Nextcloud</span>
                    <p class="text-xs text-base-content/60">
                        Monte les partages du serveur de fichiers dans Nextcloud et provisionne les comptes.
                    </p>
                </div>
            </label>
        </div>

        {{-- Config Nextcloud — révélée UNIQUEMENT quand la capacité est active. --}}
        @if ($nextcloud)
            <div class="rounded-xl border border-primary/30 bg-primary/5 p-5 flex flex-col gap-4">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-cloud text-primary"></i>
                    <span class="text-sm font-semibold">Connexion à l'instance Nextcloud</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex flex-col w-full">
                        <label class="label w-full" for="nextcloud-server-url">
                            <span class="label-text font-medium">URL du serveur Nextcloud <span class="text-error">*</span></span>
                        </label>
                        <input type="text" id="nextcloud-server-url" wire:model.blur="nextcloudServerUrl"
                            placeholder="https://cloud.etablissement.fr"
                            class="input input-bordered w-full @error('nextcloudServerUrl') input-error @enderror" />
                        @error('nextcloudServerUrl')
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="flex flex-col w-full">
                        <label class="label w-full" for="nextcloud-admin-user">
                            <span class="label-text font-medium">Compte administrateur de l'instance <span class="text-error">*</span></span>
                        </label>
                        <input type="text" id="nextcloud-admin-user" wire:model.blur="nextcloudAdminUser"
                            placeholder="admin"
                            class="input input-bordered w-full @error('nextcloudAdminUser') input-error @enderror" />
                        @error('nextcloudAdminUser')
                            <span class="text-xs text-error mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="flex flex-col w-full">
                        <label class="label w-full" for="nextcloud-admin-password">
                            <span class="label-text font-medium">App password admin <span class="text-error">*</span></span>
                        </label>
                        <div class="flex gap-2 items-center">
                            <input type="password" id="nextcloud-admin-password" autocomplete="new-password"
                                wire:model.blur="nextcloudAdminPassword"
                                placeholder="{{ $hasAdminSecret ? 'Enregistré — saisir pour remplacer' : 'Généré dans Nextcloud › Sécurité' }}"
                                class="input input-bordered w-full" />
                            @if ($hasAdminSecret)
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="forgetAdminPassword"
                                    aria-label="Retirer l'app password enregistré">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            @endif
                        </div>
                        <span class="text-xs mt-1 {{ $hasAdminSecret ? 'text-success' : 'text-warning' }}">
                            <i class="fa-solid {{ $hasAdminSecret ? 'fa-lock' : 'fa-triangle-exclamation' }}"></i>
                            {{ $hasAdminSecret ? 'Un app password est enregistré (chiffré).' : 'Aucun app password enregistré.' }}
                        </span>
                    </div>

                    <div class="flex flex-col w-full">
                        <label class="label w-full" for="nextcloud-smb-host">
                            <span class="label-text font-medium">Serveur de fichiers SMB à monter</span>
                        </label>
                        <input type="text" id="nextcloud-smb-host" wire:model.blur="nextcloudSmbHost"
                            placeholder="{{ $smbHostFallback !== '' ? $smbHostFallback : 'nom du serveur de fichiers' }}"
                            class="input input-bordered w-full" />
                    </div>
                </div>

                <label class="label cursor-pointer justify-start gap-3 w-full">
                    <input type="checkbox" wire:model.live="nextcloudVerifyTls" class="checkbox checkbox-sm checkbox-primary" />
                    <span class="label-text">Vérifier le certificat TLS de l'instance</span>
                </label>

                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline" wire:click="testConnection"
                        wire:loading.attr="disabled" wire:target="testConnection">
                        <span wire:loading wire:target="testConnection" class="loading loading-spinner loading-xs"></span>
                        <i wire:loading.remove wire:target="testConnection" class="fa-solid fa-plug"></i>
                        Tester la connexion
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="provision"
                        wire:loading.attr="disabled" wire:target="provision">
                        <i class="fa-solid fa-rocket"></i>
                        Provisionner l'accès Nextcloud
                    </button>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="refreshReport">
                        <i class="fa-solid fa-rotate"></i>
                        Rafraîchir le rapport
                    </button>
                </div>

                @if ($probeResult)
                    <div class="rounded-lg border p-3 text-xs
                        {{ $probeResult['ok'] ? 'border-success/40 bg-success/10' : 'border-error/40 bg-error/10' }}">
                        <p class="font-medium">
                            <i class="fa-solid {{ $probeResult['ok'] ? 'fa-circle-check text-success' : 'fa-circle-xmark text-error' }}"></i>
                            Diagnostic de connexion
                        </p>
                        <p class="mt-1 text-base-content/80">{{ $probeResult['message'] }}</p>
                    </div>
                @endif

                @if ($runningSince)
                    <div class="rounded-lg border border-info/40 bg-info/10 p-3 text-xs">
                        <p class="font-medium">
                            <i class="fa-solid fa-hourglass-half text-info"></i>
                            Provisionnement en cours depuis {{ $runningSince['started_at'] }}
                            @if ($runningSince['dry_run'])
                                <span class="badge badge-ghost badge-sm ml-1">simulation</span>
                            @endif
                        </p>
                        <p class="mt-1 text-base-content/80">
                            Le rapport ci-dessous est celui de l'exécution précédente. Si ce message persiste
                            longtemps après la fin attendue, l'exécution a été interrompue : relancez-la.
                        </p>
                    </div>
                @endif

                @if ($lastReport)
                    <div class="rounded-lg border border-base-300 bg-base-100 p-3 flex flex-col gap-3">
                        <p class="text-xs font-semibold uppercase tracking-wider text-base-content/60">
                            Dernier provisionnement
                            @if ($lastReport['dry_run'] ?? false)
                                <span class="badge badge-ghost badge-sm ml-1">simulation</span>
                            @endif
                            @if ($lastReport['started_at'] ?? null)
                                <span class="font-normal normal-case tracking-normal ml-1">— {{ $lastReport['started_at'] }}</span>
                            @endif
                        </p>

                        @if ($lastReport['refusal'] ?? null)
                            <p class="text-xs text-error">{{ $lastReport['refusal'] }}</p>
                        @endif

                        @if (! empty($lastReport['mounts']))
                            <div class="overflow-x-auto">
                                <table class="table table-xs">
                                    <thead>
                                        <tr><th>Montage</th><th>État</th><th>Détail</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($lastReport['mounts'] as $mount)
                                            <tr>
                                                <td class="font-medium">{{ $mount['name'] }}</td>
                                                <td>{{ $mount['label'] }}</td>
                                                <td class="text-base-content/60">{{ $mount['detail'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if (! empty($lastReport['users']))
                            <div class="flex flex-wrap gap-2 text-xs">
                                <span class="badge badge-ghost">Créés : {{ $lastReport['users']['crees'] }}</span>
                                <span class="badge badge-ghost">Adoptés : {{ $lastReport['users']['adoptes'] }}</span>
                                <span class="badge badge-warning badge-outline">Introuvables : {{ $lastReport['users']['introuvables'] }}</span>
                                <span class="badge badge-error badge-outline">Échecs : {{ $lastReport['users']['echecs'] }}</span>
                                <span class="badge badge-ghost">Hors périmètre : {{ $lastReport['users']['exclus'] }}</span>
                            </div>
                        @endif

                        @if (! empty($lastReport['user_issues']))
                            <div class="overflow-x-auto">
                                <table class="table table-xs">
                                    <thead>
                                        <tr><th>Compte</th><th>État</th><th>Marche à suivre</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($lastReport['user_issues'] as $issue)
                                            <tr>
                                                <td class="font-mono">{{ $issue['login'] }}</td>
                                                <td>{{ $issue['issue'] }}</td>
                                                <td class="text-base-content/60">{{ $issue['detail'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
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
                                « Partages » et « Documents » dans Nextcloud
                                @if (trim($nextcloudServerUrl) === '')
                                    <span class="block text-warning mt-0.5">URL du serveur non renseignée</span>
                                @else
                                    <span class="block text-base-content/50 mt-0.5 break-all">{{ $nextcloudServerUrl }}</span>
                                @endif
                            </span>
                        </li>
                    @endif
                </ul>

                @if ($nextcloud && ! $home)
                    {{-- Le cas qui surprend, dit avant qu'il ne surprenne. --}}
                    <p class="text-xs text-base-content/60">
                        <i class="fa-solid fa-circle-info"></i>
                        Le lecteur K: est désactivé, mais le dossier « Documents » reste accessible dans
                        Nextcloud : l'accès web ne dépend pas du montage sur le poste.
                    </p>
                @endif
            @endif
        </div>
    </aside>
</div>
