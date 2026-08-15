<?php

use App\Components\Traits\WithToasts;
use App\Services\FilePolicyService;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionVerifier;
use App\Services\ServiceCredentials;
use App\Services\Shortcuts\PortalShortcutIcon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * LA PAGE DE CONNEXION À L'INSTANCE OPENCLOUD — bloc 1 de l'onglet
 * « Emplacements et cloud » de /admin/settings/files.
 *
 * ---------------------------------------------------------------------------
 * **STORY 63.3 — CE BLOC A DÉMÉNAGÉ, IL N'A PAS ÉTÉ RÉÉCRIT.** Il était
 * l'onglet « OpenCloud » ; il est maintenant révélé par la position « OpenCloud »
 * du choix de cloud, et par elle seule. Le comportement, les libellés, les
 * gardes et les tests sont conservés à l'identique.
 *
 * **L'INTERRUPTEUR DE CAPACITÉ A DISPARU D'ICI, ET C'EST LE POINT.** « OpenCloud
 * est-il actif ? » n'est plus une case indépendante : c'est une des trois
 * positions du CLOUD ACTIF de l'instance, décidée au-dessus, et le miroir
 * ({@see \App\Services\Filesystem\FileLocationPolicyMirror}) écrit la capacité
 * `opencloud` de `files.policy` à partir d'elle. Ce composant lit donc la
 * capacité persistée et la REPASSE inchangée à chaque enregistrement — il
 * n'écrit que des réglages de connexion.
 *
 * Le bloc de connexion est SYMÉTRIQUE de celui de l'autre produit,
 * volontairement : adresse, compte d'administration, secret en écriture seule,
 * vérification TLS cochée par défaut, sonde qui n'écrit rien et diagnostic
 * persisté. Un exploitant qui connaît l'un sait lire l'autre.
 *
 * ---------------------------------------------------------------------------
 * **LE SECRET NE TRANSITE JAMAIS EN RETOUR.** Le mot de passe d'administration
 * est un champ d'ÉCRITURE SEULE : il n'est jamais préchargé depuis le stock, et
 * la propriété est VIDÉE dès qu'elle est persistée — sans quoi elle repartirait
 * dans l'instantané du rendu suivant, c'est-à-dire dans le HTML de la page.
 * L'écran ne montre que le FAIT qu'un secret est enregistré, jamais sa valeur.
 *
 * **LE DÉPLOIEMENT N'EST PAS ICI, ET C'EST DÉLIBÉRÉ.** Monter l'instance est une
 * opération d'exploitation sur le serveur, elle appartient à la commande
 * d'administration. Cet écran DÉCLARE une connexion — vers l'instance qu'on vient
 * de déployer, ou vers une instance hébergée ailleurs, ce qui doit rester
 * possible. Un bouton « déployer » ici ferait croire que la seule instance
 * légitime est locale.
 * ---------------------------------------------------------------------------
 *
 * Composant enfant (nested) — double garde `server.admin`, et **racine stable** :
 * aucune condition au premier niveau du gabarit, sous peine d'erreur au
 * re-rendu du parent.
 */
new class extends Component {
    use WithToasts;

    /**
     * ⚠️ **LA CAPACITÉ N'EST PAS UNE PROPRIÉTÉ DE CE COMPOSANT** (correction de
     * revue). Elle l'a été, avec un docblock affirmant que « les gardes de ce
     * composant la consultent » — or il n'y en a aucune ici, et
     * {@see self::save()} repasse de toute façon la valeur persistée. Une
     * propriété que personne ne lit, documentée comme portante, est un piège
     * pour la prochaine lecture.
     *
     * Le principe vaut au-delà de l'inutilité : cette capacité est DÉRIVÉE du
     * cloud actif, décidé au-dessus, et ce composant est monté au clic sur la
     * position « OpenCloud » — donc AVANT que le miroir ne l'allume. La mettre
     * en cache au montage figerait un `false` pour toute la session de page.
     * Si une garde naît ici, elle relira la valeur persistée à ce moment-là.
     */
    public string $serverUrl = '';

    public string $adminUser = '';

    /** ÉCRITURE SEULE : jamais préchargé, vidé dès qu'il est persisté. */
    public string $adminPassword = '';

    public bool $verifyTls = true;

    /** Un secret est-il enregistré ? Le FAIT, jamais la valeur. */
    public bool $hasAdminSecret = false;

    /** Dernier diagnostic de connexion, persisté (tableau plat). */
    public ?array $probeResult = null;

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $config = FilePolicyService::globalConfig();

        $this->serverUrl = (string) $config['opencloud_server_url'];
        $this->adminUser = (string) $config['opencloud_admin_user'];
        $this->verifyTls = (bool) $config['opencloud_verify_tls'];

        $this->refreshSecretState();
        $this->probeResult = app(OpenCloudConnectionVerifier::class)->lastDiagnostic();
    }

    /** Chaque bascule persiste immédiatement : il n'y a pas de bouton d'enregistrement. */
    public function updated(string $property): void
    {
        if ($property === 'adminPassword') {
            $this->storeSecret();

            return;
        }

        $this->save();
    }

    public function save(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        // CE COMPOSANT N'ÉCRIT QUE SA CONNEXION. Il ne NOMME que ses trois
        // réglages ; les quatre booléens de capacité — dérivés des emplacements
        // par le miroir — et tous les réglages de l'autre produit sont relus et
        // repassés par `patchGlobal()`, seul endroit du dépôt qui connaisse
        // l'ordre des paramètres de `setGlobal()`.
        FilePolicyService::patchGlobal([
            'opencloud_server_url' => trim($this->serverUrl),
            'opencloud_admin_user' => trim($this->adminUser),
            'opencloud_verify_tls' => $this->verifyTls,
        ]);

        $this->publishPortalIcon();

        // L'écran parent recalcule les positions qu'il propose : une connexion
        // qu'on vient de compléter doit devenir posable sans recharger la page.
        $this->dispatch('cloud-connexion-enregistree');

        $this->toastSuccess('Réglages OpenCloud enregistrés.');
    }

    /**
     * Publie l'icône du raccourci vers le portail web — **le même appel que dans
     * l'onglet voisin, et pour la même raison** (Story 63.2).
     *
     * Le raccourci « Mes fichiers en ligne » suit le CLOUD ACTIF de l'instance :
     * il est donc posé aussi quand ce cloud est OpenCloud. Sans cet appel ici,
     * une instance qui n'aurait jamais enregistré l'onglet voisin poserait un
     * `.lnk` portant l'icône de `rundll32.exe` sur tous les bureaux.
     *
     * L'icône source est NEUTRE, sans marque : c'est la même pour les deux
     * produits, il n'y a rien à choisir ici.
     *
     * Idempotent, et NON BLOQUANT : un échec de publication laisse le raccourci
     * sans icône, jamais sans raccourci.
     */
    private function publishPortalIcon(): void
    {
        app(PortalShortcutIcon::class)->publish();
    }

    /**
     * Range le secret CHIFFRÉ, puis VIDE la propriété.
     *
     * Le diagnostic précédent portait sur d'AUTRES identifiants : le garder
     * afficherait un vert qui ne dit plus rien de la configuration courante. On
     * le marque « non vérifié depuis le changement de secret » plutôt que de
     * l'effacer — un « non vérifié » explicite vaut mieux qu'un silence.
     */
    public function storeSecret(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $secret = trim($this->adminPassword);
        $this->adminPassword = '';

        if ($secret === '') {
            return;
        }

        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, $secret);
        $this->refreshSecretState();

        $diagnostic = $this->probeResult;
        if (is_array($diagnostic)) {
            $diagnostic['unverified_since_secret_change'] = true;
            $this->rememberDiagnostic($diagnostic);
        }

        // Un secret rangé peut COMPLÉTER la connexion : le parent recalcule.
        $this->dispatch('cloud-connexion-enregistree');

        $this->toastSuccess('Mot de passe d\'administration enregistré (chiffré). Testez la connexion pour le vérifier.');
    }

    public function forgetAdminPassword(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        app(ServiceCredentials::class)->forget(OpenCloudConnectionConfig::CREDENTIAL_NAME);
        $this->refreshSecretState();
        $this->rememberDiagnostic(null);

        // Retirer un secret DÉGRADE la connexion — c'est le geste par lequel on
        // départage deux clouds configurés. Le parent en tient compte.
        $this->dispatch('cloud-connexion-enregistree');

        $this->toastSuccess('Mot de passe d\'administration retiré.');
    }

    /**
     * « Tester la connexion », avec les valeurs de l'ÉCRAN — pas les persistées.
     * Sonder l'état persisté ne dirait rien de la cible qu'on s'apprête à
     * enregistrer. **La sonde n'écrit rien sur l'instance.**
     */
    public function testConnection(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $probe = app(OpenCloudConnectionVerifier::class)
            ->verify(trim($this->serverUrl), $this->verifyTls, trim($this->adminUser));

        $this->rememberDiagnostic($probe->toArray());

        $probe->isOk()
            ? $this->toastSuccess($probe->message)
            : $this->toastError($probe->message);
    }

    private function rememberDiagnostic(?array $diagnostic): void
    {
        $this->probeResult = $diagnostic;
        app(OpenCloudConnectionVerifier::class)->rememberDiagnostic($diagnostic);
    }

    private function refreshSecretState(): void
    {
        $this->hasAdminSecret = app(ServiceCredentials::class)
            ->has(OpenCloudConnectionConfig::CREDENTIAL_NAME);
    }
};
?>

{{-- RACINE STABLE : un seul élément au premier niveau, sans condition — une
     condition ici casse le re-rendu du parent. --}}
<div class="flex flex-col gap-6">

    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info"></i>
        <div class="text-sm">
            <p>
                Un répertoire servi par OpenCloud n'a <strong>aucune lettre de lecteur réseau</strong> :
                il se consulte au navigateur et se synchronise par le client de bureau. Chaque dossier
                partagé apparaît chez son destinataire ; ce qui ne lui est pas partagé lui reste invisible.
            </p>
            <p class="mt-1 text-xs text-base-content/70">
                Le choix de l'autorité d'écriture se fait <strong>à la création</strong> d'un répertoire, et
                il ne se change pas ensuite : déplacer les données et retraduire les droits est une
                opération à part entière, qui n'existe pas encore.
            </p>
        </div>
    </div>

    {{-- La capacité n'a PLUS d'interrupteur ici : « OpenCloud est-il actif ? »
         est une position du choix de cloud, au-dessus. Ce bloc ne fait que
         déclarer la connexion à l'instance. --}}
    <div>
        <div class="rounded-xl border border-primary/30 bg-primary/5 p-5 flex flex-col gap-4">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-cloud-arrow-up text-primary"></i>
                <span class="text-sm font-semibold">Connexion à l'instance OpenCloud</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex flex-col w-full">
                    <label class="label w-full" for="opencloud-server-url">
                        <span class="label-text font-medium">
                            URL de l'instance <span class="text-error">*</span>
                        </span>
                    </label>
                    <input type="text" id="opencloud-server-url" wire:model.blur="serverUrl"
                        placeholder="https://fichiers.etablissement.fr"
                        class="input input-bordered w-full" />
                    <span class="text-xs text-base-content/60 mt-1">
                        L'adresse publique, celle que le frontal expose. Si l'instance a été montée sur ce
                        serveur, c'est celle qui a été fournie au déploiement.
                    </span>
                </div>

                <div class="flex flex-col w-full">
                    <label class="label w-full" for="opencloud-admin-user">
                        <span class="label-text font-medium">
                            Compte d'administration <span class="text-error">*</span>
                        </span>
                    </label>
                    <input type="text" id="opencloud-admin-user" wire:model.blur="adminUser"
                        placeholder="admin" class="input input-bordered w-full" />
                    <span class="text-xs text-base-content/60 mt-1">
                        Le compte doit être <strong>administrateur</strong> de l'instance : créer un espace de
                        projet, un groupe ou poser un octroi sont des opérations d'administration. Un compte
                        ordinaire est refusé, avec son motif.
                    </span>
                </div>

                <div class="flex flex-col w-full">
                    <label class="label w-full" for="opencloud-admin-password">
                        <span class="label-text font-medium">
                            Mot de passe d'administration <span class="text-error">*</span>
                        </span>
                    </label>
                    <div class="flex gap-2 items-center">
                        <input type="password" id="opencloud-admin-password" autocomplete="new-password"
                            wire:model.blur="adminPassword"
                            placeholder="{{ $hasAdminSecret ? 'Enregistré — saisir pour remplacer' : 'Mot de passe du compte d\'administration' }}"
                            class="input input-bordered w-full" />
                        @if ($hasAdminSecret)
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="forgetAdminPassword"
                                aria-label="Retirer le mot de passe enregistré">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        @endif
                    </div>
                    <span class="text-xs mt-1 {{ $hasAdminSecret ? 'text-success' : 'text-warning' }}">
                        <i class="fa-solid {{ $hasAdminSecret ? 'fa-lock' : 'fa-triangle-exclamation' }}"></i>
                        {{ $hasAdminSecret
                            ? 'Un mot de passe est enregistré (chiffré).'
                            : 'Aucun mot de passe enregistré.' }}
                    </span>
                </div>
            </div>

            <label class="label cursor-pointer justify-start gap-3 w-full">
                <input type="checkbox" wire:model.live="verifyTls" class="checkbox checkbox-sm checkbox-primary" />
                <span class="label-text">Vérifier le certificat TLS de l'instance</span>
            </label>

            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-outline" wire:click="testConnection"
                    wire:loading.attr="disabled" wire:target="testConnection">
                    <span wire:loading wire:target="testConnection" class="loading loading-spinner loading-xs"></span>
                    <i wire:loading.remove wire:target="testConnection" class="fa-solid fa-plug"></i>
                    Tester la connexion
                </button>
                <span class="text-xs text-base-content/60 self-center">
                    La sonde lit seulement : elle n'écrit rien sur l'instance.
                </span>
            </div>

            @if (is_array($probeResult))
                <div class="alert {{ ($probeResult['ok'] ?? false) ? 'alert-success' : 'alert-warning' }} text-sm">
                    <i class="fa-solid {{ ($probeResult['ok'] ?? false) ? 'fa-circle-check' : 'fa-triangle-exclamation' }}"></i>
                    <div>
                        <p>{{ $probeResult['message'] ?? '' }}</p>
                        @if ($probeResult['unverified_since_secret_change'] ?? false)
                            <p class="mt-1 text-xs">
                                Ce diagnostic est <strong>antérieur au dernier changement de mot de passe</strong> :
                                relancez « Tester la connexion » pour le confirmer.
                            </p>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div class="text-xs text-base-content/60">
        <p class="font-medium mb-1">Monter l'instance sur ce serveur</p>
        <p>
            Le déploiement est une opération d'exploitation : il se joue en ligne de commande, et il est
            rejouable sans risque.
        </p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-base-200 p-3">php artisan opencloud:deploy --url=https://fichiers.etablissement.fr</pre>
        <p class="mt-1">
            La commande ne supprime jamais de conteneur, de volume ni de donnée, et elle
            <strong>ne rend pas</strong> OpenCloud cloud actif de l'instance : c'est un geste explicite qui
            vous revient, au-dessus.
        </p>
    </div>

</div>
