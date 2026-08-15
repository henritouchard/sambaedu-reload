<?php

use App\Components\Traits\WithToasts;
use App\Jobs\ProvisionNextcloudJob;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudConnectionVerifier;
use App\Services\Nextcloud\NextcloudIdentityLinker;
use App\Services\Nextcloud\NextcloudProvisioningService;
use App\Services\ServiceCredentials;
use App\Services\Shortcuts\PortalShortcutIcon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * LA PAGE DE CONNEXION À L'INSTANCE NEXTCLOUD — bloc 1 de l'onglet
 * « Emplacements et cloud » de /admin/settings/files.
 *
 * ---------------------------------------------------------------------------
 * **STORY 63.3 — CE BLOC A DÉMÉNAGÉ, IL N'A PAS ÉTÉ RÉÉCRIT.** Il était
 * l'onglet « Personnels et partagés » ; il est maintenant révélé par la position
 * « Nextcloud » du choix de cloud, et par elle seule. La sonde-garde
 * fail-closed, le diagnostic persisté, la re-sonde après changement de secret,
 * le rapport de provisionnement et la modale de rattachement d'identité sont
 * conservés à l'identique.
 *
 * **LES TROIS INTERRUPTEURS ONT DISPARU D'ICI, ET C'EST LE POINT.** `home`,
 * `shares` et « Accès Nextcloud » ne sont plus des cases : les deux premiers
 * sont dérivés des EMPLACEMENTS (où vit l'espace personnel, où vit l'espace
 * partagé) et le troisième du CLOUD ACTIF, tous trois décidés au-dessus et
 * projetés sur `files.policy` par
 * {@see \App\Services\Filesystem\FileLocationPolicyMirror}. Ce composant lit
 * donc les quatre booléens persistés et les REPASSE inchangés à chaque
 * enregistrement — il n'écrit que des réglages de connexion.
 * ---------------------------------------------------------------------------
 *
 * Composant enfant (nested) — double garde `server.admin`, et **racine stable**.
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
 * ---------------------------------------------------------------------------
 * **STORY 61.2 (recadrée le 2026-08-08) — LA CONNEXION EST FAIL-CLOSED.**
 * L'écran ne se contente pas d'enregistrer une configuration : il VÉRIFIE que le
 * compte saisi peut administrer l'instance, **avant** de la persister. Une
 * configuration que la sonde refuse n'est pas enregistrée du tout, la précédente
 * reste en vigueur, et le motif exact est affiché — jamais « accepté puis
 * silencieusement dégradé ».
 *
 * **Il n'y a plus de « mode ».** La story 61.2 avait livré un choix entre instance
 * administrée et compte porteur délégué. Mesuré contre une instance réelle, un
 * compte ordinaire ne peut créer ni Team folder, ni groupe, ni partage de groupe :
 * sans Team folder, pas de clôture — donc pas de cloisonnement, qui est le problème
 * que le plan de fichiers existe pour résoudre. SE5 EXIGE un compte administrateur,
 * et la question du fail-closed se réduit à celle-là.
 *
 * **La sonde-garde ne parle à l'instance QUE quand ce qui DÉFINIT LA CONNEXION
 * change** — l'URL de l'instance, l'identifiant admin, la vérification TLS (revue
 * 61.2 #1 : ne comparer que l'identifiant laissait passer un changement d'URL, donc
 * une cible jamais vérifiée). Le point de sauvegarde est global à l'onglet : sonder
 * à chaque enregistrement ferait d'une panne d'instance un verrou sur le répertoire
 * personnel, les partages ou l'hôte SMB — des réglages qui ne la concernent pas.
 *
 * **Le cas du SECRET est différent, et il est traité à part (revue 61.2 #3)** :
 * l'enregistrement d'un app password n'est JAMAIS annulé par une sonde. Refuser de
 * STOCKER un secret que l'instance ne confirme pas rendrait une instance
 * injoignable définitivement inconfigurable — or l'app password est ÉMIS par
 * l'instance et seulement conservé par SE5. Le secret est donc enregistré, PUIS
 * l'écran re-sonde : vert si la configuration tient encore, « NON VÉRIFIÉE depuis le
 * dernier changement de secret » sinon — état persisté, donc il survit au
 * rechargement. Le fail-closed porte sur la CONFIGURATION DE CONNEXION ; l'honnêteté,
 * elle, porte sur tout.
 * ---------------------------------------------------------------------------
 */
new class extends Component {
    use WithToasts;

    /**
     * ⚠️ **LA CAPACITÉ N'EST PLUS UNE PROPRIÉTÉ, ET C'EST UNE CORRECTION DE
     * SÉCURITÉ** (revue 63.3).
     *
     * Elle l'était, lue au seul `mount()`. Or ce composant est monté au CLIC sur
     * la position « Nextcloud » du choix de cloud, c'est-à-dire **avant** que le
     * miroir n'allume la capacité — et il gardait cet instantané `false` pour
     * toute la session de page. Conséquences, invisibles en test parce que
     * `Livewire::test()` re-monte les enfants à chaque rendu du parent alors
     * qu'un navigateur ne le fait pas :
     *  - {@see self::guardConnectionChange()} rendait `true` **sans jamais
     *    sonder** — URL et compte administrateur persistés sans la moindre
     *    vérification, c'est-à-dire le fail-closed de la story 61.2
     *    court-circuité sur le parcours de PREMIÈRE configuration, le seul qui
     *    compte ;
     *  - {@see self::reprobeAfterSecretChange()} effaçait le diagnostic au lieu
     *    d'en produire un.
     *
     * Elle est donc **relue à chaque écriture** dans `files.policy`, comme ce
     * composant le fait déjà pour tous les autres réglages qu'il repasse. Une
     * capacité dérivée d'une décision prise ailleurs ne se met pas en cache dans
     * l'écran qui la subit.
     */
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

    /** Modale de rattachement d'identité (AC7) — ouverte depuis un « introuvable ». */
    public bool $showLinkModal = false;

    public string $linkLogin = '';

    public string $linkNextcloudId = '';

    /**
     * Dernier diagnostic de connexion (tableau plat, {@see \App\Services\Nextcloud\NextcloudConnectionProbe}).
     *
     * **Il est PERSISTÉ** ({@see NextcloudConnectionVerifier::rememberDiagnostic()})
     * et relu au montage : un état de vérification qui disparaîtrait au rechargement
     * de la page laisserait une configuration déclarée passer pour vérifiée dès le
     * prochain affichage, ce qui est exactement le mensonge que la revue a fait
     * fermer.
     */
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
        $this->nextcloudServerUrl = $config['nextcloud_server_url'];
        $this->nextcloudAdminUser = $config['nextcloud_admin_user'];
        $this->nextcloudSmbHost = $config['nextcloud_smb_host'];
        $this->nextcloudVerifyTls = $config['nextcloud_verify_tls'];

        $this->smbHostFallback = trim((string) config('sambaedu.se4fs_name', ''));
        $this->hasAdminSecret = app(ServiceCredentials::class)->has(NextcloudConnectionConfig::CREDENTIAL_NAME);
        $this->lastReport = app(NextcloudProvisioningService::class)->lastReport();
        $this->runningSince = app(NextcloudProvisioningService::class)->runningSince();
        $this->probeResult = app(NextcloudConnectionVerifier::class)->lastDiagnostic();
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

        if (! $this->guardConnectionChange()) {
            // Refusé : RIEN n'est persisté, et la configuration précédente reste en
            // vigueur.
            return;
        }

        // CE COMPOSANT N'ÉCRIT QUE DES RÉGLAGES DE CONNEXION. Il ne NOMME que
        // les quatre siens : les quatre booléens de capacité — dérivés des
        // emplacements et du cloud actif par le miroir — et tout le reste sont
        // relus et repassés par `patchGlobal()`. Une page de connexion qui
        // écrirait une capacité ouvrirait un second chemin de décision, celui-là
        // même que cette story ferme ; ici, elle n'en a structurellement plus le
        // moyen.
        try {
            FilePolicyService::patchGlobal([
                'nextcloud_server_url' => $this->nextcloudServerUrl,
                'nextcloud_admin_user' => $this->nextcloudAdminUser,
                'nextcloud_smb_host' => $this->nextcloudSmbHost,
                'nextcloud_verify_tls' => $this->nextcloudVerifyTls,
            ]);
        } catch (\Throwable $e) {
            Log::error('FilePolicySettings: échec save', ['error' => $e->getMessage()]);
            $this->toastError('Impossible d\'enregistrer la politique. Consultez les logs.');

            return;
        }

        $this->publishPortalIcon();

        // L'ÉCRAN PARENT RECALCULE CE QU'IL PROPOSE. Sans cet événement, une
        // connexion qu'on vient de compléter n'apparaissait jamais comme
        // posable dans le bloc des emplacements, dont le motif continuait
        // d'annoncer « la connexion est incomplète : complétez-la ci-dessus ».
        $this->dispatch('cloud-connexion-enregistree');
    }

    /**
     * Publie l'icône du raccourci-portail — **ici et pas dans le provider**.
     *
     * Le provider d'état est compilé pour chaque couple (poste, utilisateur) : il
     * ne fait QUE des lectures de colonnes, jamais un hash de fichier. La mise à
     * disposition de l'icône content-addressed est donc rattachée au geste
     * d'administration, qui a lieu une fois.
     *
     * Republier à CHAQUE enregistrement (et pas seulement à la première
     * activation) est délibéré : une icône source mise à jour par une nouvelle
     * version de SE5 serait sinon publiée une fois pour toutes et jamais
     * rafraîchie. L'opération est idempotente et ne coûte qu'une empreinte.
     *
     * **Story 63.2 — plus AUCUNE condition.** La publication était gardée par la
     * capacité Nextcloud et par la case « poser le raccourci » ; la case a
     * disparu (le raccourci suit le cloud actif) et le produit ne décide plus de
     * rien ici. Publier une icône n'active rien et ne se voit nulle part tant
     * qu'aucun raccourci ne la réclame : la garder derrière une condition
     * ferait seulement rater le cas où le cloud est déclaré depuis l'autre
     * onglet.
     *
     * NON BLOQUANT : un échec de publication laisse le raccourci sans icône, il
     * ne l'empêche jamais d'être posé.
     */
    private function publishPortalIcon(): void
    {
        app(PortalShortcutIcon::class)->publish();
    }

    /**
     * AC2 — LA SONDE-GARDE : la configuration enregistrée est une configuration que
     * le compte peut honorer.
     *
     * Rend `false` quand elle est REFUSÉE — l'appelant n'écrit alors rien du tout.
     *
     * **Elle ne s'exécute que quand ce qui DÉFINIT la connexion change** — l'URL de
     * l'instance, l'identifiant admin, ou la vérification TLS — **et** que cette
     * connexion a déjà de quoi être sondée (URL + secret enregistré) : sinon on
     * refuserait la saisie d'une configuration en cours de constitution, ce qui
     * interdirait de la constituer.
     *
     * ---------------------------------------------------------------------------
     * **CORRECTION DE REVUE (61.2 #1) — L'URL ET LE TLS SONT DE LA CONNEXION, PAS
     * DU DÉCOR.** La première rédaction ne comparait que l'IDENTIFIANT du compte.
     * Changer la seule URL — déménagement d'hébergeur, ou simple faute de frappe —
     * traversait donc la garde sans le moindre appel : `setGlobal()` persistait une
     * nouvelle cible avec un compte qui n'avait JAMAIS été vérifié capable de
     * l'administrer là-bas. C'est précisément ce que l'AC2 interdit. Le drapeau TLS
     * relève du même raisonnement : il décide de ce qui est joignable.
     *
     * **Recadrage du 2026-08-08** : la garde portait aussi sur le MODE visé. Les
     * modes ont disparu ; la question qu'elle pose est désormais unique — « ce
     * compte est-il administrateur de l'instance ? » — et {@see NextcloudConnectionProbe}
     * y répond déjà.
     * ---------------------------------------------------------------------------
     *
     * Une sauvegarde qui ne touche que l'hôte SMB ne parle donc JAMAIS à
     * l'instance ; capacité éteinte, aucun appel non plus — il n'y a alors aucune
     * instance à configurer.
     */
    private function guardConnectionChange(): bool
    {
        // ⚠️ LA CAPACITÉ EST RELUE ICI, JAMAIS MISE EN CACHE AU MONTAGE : ce
        // composant est monté AVANT que le cloud actif ne l'allume, et un
        // instantané `false` ferait rendre `true` à cette garde sans le moindre
        // appel — le fail-closed annulé sur le parcours de première
        // configuration.
        $persisted = FilePolicyService::globalConfig();

        if (! $persisted['nextcloud']) {
            return true;
        }

        // Tout ce qui définit LA CONNEXION : la cible, le compte, et ce qui décide
        // de la joignabilité.
        $connectionChanged = trim($this->nextcloudAdminUser) !== trim((string) $persisted['nextcloud_admin_user'])
            || trim($this->nextcloudServerUrl) !== trim((string) $persisted['nextcloud_server_url'])
            || $this->nextcloudVerifyTls !== (bool) $persisted['nextcloud_verify_tls'];

        if (! $connectionChanged || ! $this->isConnectionProbeable()) {
            return true;
        }

        $probe = app(NextcloudConnectionVerifier::class)->verify(
            $this->nextcloudServerUrl,
            $this->nextcloudVerifyTls,
            $this->nextcloudAdminUser,
        );

        $this->rememberDiagnostic($probe->toArray());

        if ($probe->isOk()) {
            return true;
        }

        // TOUT ce qui définit la connexion reprend sa valeur persistée : l'écran ne
        // doit pas afficher une configuration que la base ne porte pas — la règle
        // valait déjà pour l'identifiant, elle vaut pour l'URL et le TLS au même
        // titre.
        $this->nextcloudAdminUser = (string) $persisted['nextcloud_admin_user'];
        $this->nextcloudServerUrl = (string) $persisted['nextcloud_server_url'];
        $this->nextcloudVerifyTls = (bool) $persisted['nextcloud_verify_tls'];

        $this->toastError('Configuration Nextcloud refusée : ' . $probe->message);

        Log::warning('nextcloud.connection.selection_refused', [
            'failure' => $probe->failure?->value,
        ]);

        return false;
    }

    /** La connexion a-t-elle de quoi être sondée ? (URL + secret admin enregistré) */
    private function isConnectionProbeable(): bool
    {
        return trim($this->nextcloudServerUrl) !== '' && $this->hasAdminSecret;
    }

    /**
     * Le diagnostic affiché ET PERSISTÉ, en un seul geste.
     *
     * Passer par ici plutôt que d'écrire `$this->probeResult` directement est ce qui
     * garantit qu'un état de vérification survit au rechargement de la page : une
     * propriété Livewire seule disparaît au prochain montage, et l'écran
     * repartirait vierge — donc muet — sur une configuration qui n'est plus
     * vérifiée.
     *
     * @param  array<string, mixed>|null  $diagnostic
     */
    private function rememberDiagnostic(?array $diagnostic): void
    {
        $this->probeResult = $diagnostic;
        app(NextcloudConnectionVerifier::class)->rememberDiagnostic($diagnostic);
    }

    /**
     * CORRECTION DE REVUE (61.2 #3) — REMPLACER UN SECRET NE LAISSE PLUS UN MODE
     * DÉCLARÉ « VÉRIFIÉ » QU'IL N'EST PLUS.
     *
     * ---------------------------------------------------------------------------
     * **NON BLOQUANTE, ET C'EST LE CŒUR DE LA DÉCISION.** L'enregistrement du
     * secret n'est JAMAIS annulé par le résultat de cette sonde. Refuser de STOCKER
     * un app password que l'instance ne confirme pas rendrait une instance
     * momentanément injoignable impossible à reconfigurer — un verrou dont on ne
     * sortirait que par la base de données. Le secret est émis par l'instance et
     * seulement conservé par SE5 : le conserver n'affirme rien.
     *
     * Ce qui change, c'est l'HONNÊTETÉ de l'écran. Après un changement de secret :
     *  - sonde verte ⇒ le diagnostic vert s'affiche, comme après « Tester la
     *    connexion » ;
     *  - sonde en échec (instance injoignable comprise) ⇒ le secret **reste
     *    enregistré**, et l'écran affiche « configuration NON VÉRIFIÉE depuis le
     *    dernier changement de secret », avec le motif.
     *
     * Un « non vérifié » affiché n'est pas un échec : c'est le seul état honnête.
     * Il est persisté avec le diagnostic, donc il survit au rechargement.
     * ---------------------------------------------------------------------------
     */
    private function reprobeAfterSecretChange(string $stored): void
    {
        // Même règle qu'à la garde : la capacité est RELUE, jamais celle du
        // montage — sinon un secret rangé juste après le choix du cloud
        // n'aboutirait qu'à effacer le diagnostic, sans jamais en produire un.
        if (! FilePolicyService::capabilities()['nextcloud']) {
            // Aucune instance à qui une position s'impose : le diagnostic précédent
            // ne vaut plus rien, et il n'y a rien à vérifier.
            $this->rememberDiagnostic(null);
            $this->toastSuccess($stored . '.');

            return;
        }

        $probe = app(NextcloudConnectionVerifier::class)->verify(
            $this->nextcloudServerUrl,
            $this->nextcloudVerifyTls,
            $this->nextcloudAdminUser,
        );

        $diagnostic = $probe->toArray();

        if ($probe->isOk()) {
            $this->rememberDiagnostic($diagnostic);
            $this->toastSuccess($stored . ', et la connexion est vérifiée.');

            return;
        }

        // Le secret EST enregistré. Ce qui ne l'est pas, c'est la confirmation que
        // la configuration tient encore — et l'écran le dit plutôt que de se taire.
        $diagnostic['unverified_since_secret_change'] = true;
        $this->rememberDiagnostic($diagnostic);

        $this->toastWarning(sprintf(
            '%s. La connexion reste NON VÉRIFIÉE depuis ce changement : %s',
            $stored,
            $probe->message,
        ));
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
            // Le diagnostic précédent portait sur d'AUTRES identifiants : le garder
            // affiché le ferait passer pour un verdict sur ceux-ci. On ne se
            // contente pas de l'effacer — on RE-SONDE, sans jamais annuler
            // l'enregistrement.
            $this->reprobeAfterSecretChange('App password admin enregistré (chiffré)');

            // Un secret rangé peut COMPLÉTER la connexion : l'écran parent doit
            // recalculer les positions qu'il propose.
            $this->dispatch('cloud-connexion-enregistree');
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
        $this->rememberDiagnostic(null);

        // Retirer un secret DÉGRADE la connexion — c'est le geste par lequel on
        // départage deux clouds configurés. Le parent en tient compte.
        $this->dispatch('cloud-connexion-enregistree');

        $this->toastSuccess('App password admin retiré.');
    }

    /**
     * « Tester la connexion », avec les valeurs de l'écran. Trois diagnostics, qui
     * se corrigent à trois endroits différents : instance injoignable / privilège
     * insuffisant (le compte n'est pas administrateur) / app « Stockage externe »
     * absente.
     */
    public function testConnection(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $probe = app(NextcloudConnectionVerifier::class)->verify(
            $this->nextcloudServerUrl,
            $this->nextcloudVerifyTls,
            $this->nextcloudAdminUser,
        );

        $this->rememberDiagnostic($probe->toArray());

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

    // =========================================================================
    // AC7 — le rattachement explicite d'identité
    // =========================================================================

    /**
     * Ouvre la modale de rattachement depuis un compte « introuvable » du dernier
     * rapport. Le champ est **pré-rempli avec le candidat nommé par le rapport**
     * quand il en existe un : ce sont exactement les identifiants que l'instance a
     * proposés et que SE5 a refusé d'adopter tout seul (revue 61.1, correction #2).
     */
    public function openLinkModal(string $login, ?string $candidate = null): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $this->linkLogin = $login;
        $this->linkNextcloudId = trim((string) $candidate);
        $this->showLinkModal = true;
    }

    public function closeLinkModal(): void
    {
        $this->showLinkModal = false;
        $this->linkLogin = '';
        $this->linkNextcloudId = '';
    }

    /**
     * Écrit le rattachement — **après vérification à distance**. Une identité que
     * l'instance ne confirme pas n'est jamais écrite : la modale se ferme sur un
     * succès seulement, et le refus reste affiché avec sa cause.
     */
    public function linkIdentity(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $result = app(NextcloudIdentityLinker::class)->link($this->linkLogin, $this->linkNextcloudId);

        if ($result->isFailure()) {
            $this->toastError($result->message);

            return;
        }

        $this->toastSuccess($result->message);
        $this->closeLinkModal();
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
            'nextcloudServerUrl', 'nextcloudAdminUser', 'nextcloudSmbHost', 'nextcloudVerifyTls',
        ], true)) {
            $this->save();
        }
    }
};
?>

<div class="flex flex-col gap-6">

    {{-- Le bloc de connexion, et lui seul : les trois interrupteurs de capacité
         ont quitté cet écran — ils sont dérivés des emplacements et du cloud
         actif, décidés au-dessus. --}}
    <div class="flex flex-col gap-5">

        <div class="flex items-start justify-between gap-4">
            <p class="text-sm text-base-content/70">
                Déclarez ici l'instance Nextcloud de l'établissement. La capacité « Accès Nextcloud »
                suit le <strong>cloud actif</strong> choisi ci-dessus : elle ne se règle plus ici.
                Chaque modification est enregistrée immédiatement.
            </p>
            <span class="text-xs text-base-content/50 flex items-center gap-2 shrink-0 pt-0.5"
                wire:loading.class.remove="text-base-content/50" wire:loading.class="text-primary"
                wire:target="nextcloudServerUrl,nextcloudAdminUser,nextcloudSmbHost,nextcloudVerifyTls">
                <span wire:loading wire:target="nextcloudServerUrl,nextcloudAdminUser,nextcloudSmbHost,nextcloudVerifyTls"
                    class="loading loading-spinner loading-xs"></span>
                <i wire:loading.remove wire:target="nextcloudServerUrl,nextcloudAdminUser,nextcloudSmbHost,nextcloudVerifyTls"
                    class="fa-solid fa-check text-success"></i>
                <span wire:loading.remove wire:target="nextcloudServerUrl,nextcloudAdminUser,nextcloudSmbHost,nextcloudVerifyTls">Enregistré</span>
                <span wire:loading wire:target="nextcloudServerUrl,nextcloudAdminUser,nextcloudSmbHost,nextcloudVerifyTls">Enregistrement…</span>
            </span>
        </div>

        {{-- Config Nextcloud — le bloc est monté par la position « Nextcloud » du
             choix de cloud ; il n'y a donc plus rien à conditionner ici. --}}
        <div>
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
                        <span class="text-xs text-base-content/60 mt-1">
                            Le compte doit être <strong>administrateur</strong> de l'instance : les dossiers
                            d'équipe, les groupes et les quotas sont des opérations d'administration, et sans
                            eux le cloisonnement des partages n'est pas tenable. Une configuration qui ne le
                            permet pas est refusée, avec son motif.
                        </span>
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

                @if ($probeResult && ($probeResult['unverified_since_secret_change'] ?? false))
                    {{-- Correction de revue 61.2 #3 — L'ÉTAT HONNÊTE APRÈS UN
                         CHANGEMENT DE SECRET. Le secret EST enregistré (jamais
                         annulé par la sonde : une instance injoignable resterait
                         sinon inconfigurable), mais la position déclarée n'est plus
                         confirmée, et l'écran le DIT. Cet état est persisté : il
                         survit au rechargement de la page. --}}
                    <div class="rounded-lg border border-warning/40 bg-warning/10 p-3 text-xs">
                        <p class="font-medium">
                            <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                            Connexion déclarée, <strong>non vérifiée</strong> depuis le dernier
                            changement de secret
                        </p>
                        <p class="mt-1 text-base-content/80">{{ $probeResult['message'] }}</p>
                        <p class="mt-1 text-base-content/60">
                            L'app password est bien enregistré (chiffré) : un secret n'est jamais refusé au stockage,
                            sans quoi une instance momentanément injoignable deviendrait impossible à reconfigurer.
                            Corrigez la cause ci-dessus, puis relancez « Tester la connexion ».
                        </p>
                    </div>
                @elseif ($probeResult)
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
                                {{-- Correction de revue 61.3 #1 — le plafond NON écrit se voit. Le
                                     `?? 0` n'est pas de la coquetterie : un rapport mis en cache
                                     AVANT cette correction ne porte pas la clé, et l'écran doit
                                     continuer de s'afficher. --}}
                                @if (($lastReport['users']['quotas_indetermines'] ?? 0) > 0)
                                    <span class="badge badge-warning badge-outline">
                                        Plafonds non écrits (profil indéterminable) :
                                        {{ $lastReport['users']['quotas_indetermines'] }}
                                    </span>
                                @endif
                            </div>
                            @if (($lastReport['users']['quotas_indetermines'] ?? 0) > 0)
                                <p class="text-xs text-base-content/60">
                                    Le profil de quota (élève / enseignant / administrateur) se résout par
                                    l'annuaire. Pour ces comptes, l'annuaire n'a pas répondu : aucun plafond
                                    n'a été écrit — SE5 ne devine pas un profil, un plafond faux s'appliquerait
                                    sans que rien ne le signale.
                                    @if (! empty($lastReport['quota_unresolved']))
                                        Exemples : {{ implode(', ', array_slice($lastReport['quota_unresolved'], 0, 10)) }}.
                                    @endif
                                </p>
                            @endif
                        @endif

                        @if (! empty($lastReport['user_issues']))
                            <div class="overflow-x-auto">
                                <table class="table table-xs">
                                    <thead>
                                        <tr><th>Compte</th><th>État</th><th>Marche à suivre</th><th></th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($lastReport['user_issues'] as $issue)
                                            <tr>
                                                <td class="font-mono">{{ $issue['login'] }}</td>
                                                <td>{{ $issue['issue'] }}</td>
                                                <td class="text-base-content/60">{{ $issue['detail'] }}</td>
                                                <td class="text-right">
                                                    {{-- AC7 — le rattachement EXPLICITE : pré-rempli du
                                                         candidat que l'instance a proposé et que SE5 a
                                                         refusé d'adopter tout seul.

                                                         Correction de revue 61.2 #4 — `@js` et JAMAIS une
                                                         interpolation nue entre apostrophes : Blade échappe
                                                         en entités HTML, mais le navigateur les DÉCODE avant
                                                         que Livewire n'évalue l'expression. Or `candidates`
                                                         vient de l'autocomplétion Nextcloud — une source
                                                         que SE5 ne contrôle pas. `@js` produit un littéral
                                                         JavaScript correctement échappé. --}}
                                                    <button type="button" class="btn btn-xs btn-outline"
                                                        wire:click="openLinkModal(@js($issue['login']), @js($issue['candidates'][0] ?? ''))">
                                                        <i class="fa-solid fa-link"></i>
                                                        Rattacher
                                                    </button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- « Effet sur le poste » a QUITTÉ ce bloc (Story 63.3) : il est porté par
         les deux cartes d'emplacement, seul endroit où il est vrai. Ce qui reste
         ici est ce que cet écran-là ne dit pas — ce que l'instance publie. --}}
    <div class="flex items-start gap-3 rounded-lg bg-base-200 px-3 py-2">
        <i class="fa-solid fa-cloud text-base-content/40 mt-0.5 shrink-0"></i>
        <span class="text-xs">
            « Partages » et « Documents » dans Nextcloud
            @if (trim($nextcloudServerUrl) === '')
                <span class="block text-warning mt-0.5">URL du serveur non renseignée</span>
            @else
                <span class="block text-base-content/50 mt-0.5 break-all">{{ $nextcloudServerUrl }}</span>
            @endif
        </span>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────
         AC7 — LE RATTACHEMENT EXPLICITE D'IDENTITÉ.
         Modale réutilisable. Le geste est VÉRIFIÉ à distance avant d'écrire :
         une identité que l'instance ne confirme pas n'est jamais enregistrée —
         c'est la règle qui empêche un futur changement de mot de passe d'aller
         sur le compte de quelqu'un d'autre.
    ───────────────────────────────────────────────────────────────────────── --}}
    <x-molecules.modal wire:model="showLinkModal" closeMethod="closeLinkModal"
        title="Rattacher une identité Nextcloud" icon="fa-link text-primary"
        size="max-w-xl" height="h-auto">

        <x-molecules.modal.section title="Identité" icon="fa-user text-primary" dense>
            <p class="text-sm text-base-content/70">
                Utilisateur SE5 : <code>{{ $linkLogin }}</code>
            </p>

            <div class="flex flex-col w-full mt-3">
                <label class="label w-full" for="link-nextcloud-id">
                    <span class="label-text font-medium">Identifiant Nextcloud <span class="text-error">*</span></span>
                </label>
                <input type="text" id="link-nextcloud-id" wire:model="linkNextcloudId"
                    class="input input-bordered w-full" />
            </div>

            <p class="text-xs text-base-content/60 mt-2">
                L'identifiant est vérifié auprès de l'instance avant d'être enregistré. S'il n'y existe
                pas exactement, rien n'est écrit.
            </p>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeLinkModal">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="linkIdentity"
                wire:loading.attr="disabled" wire:target="linkIdentity">
                <span wire:loading wire:target="linkIdentity" class="loading loading-spinner loading-xs"></span>
                <i wire:loading.remove wire:target="linkIdentity" class="fa-solid fa-link"></i>
                Rattacher
            </button>
        </x-slot:footer>
    </x-molecules.modal>
</div>
