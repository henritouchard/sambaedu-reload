<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\ActiveCloud;
use App\Enums\ApplicationStatus;
use App\Enums\CloudAccessPath;
use App\Models\Application;
use App\Models\Workstation;
use App\Services\FilePolicyService;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Story 63.5 — LE CLIENT DE SYNCHRONISATION EST UNE APPLICATION DU CATALOGUE,
 * DÉSIGNÉE, JAMAIS UN PAQUET DEVINÉ.
 *
 * ---------------------------------------------------------------------------
 * **« UN TUYAU, DEUX OUTILS » — ce service n'installe RIEN, et ne désinstalle
 * RIEN.** Il répond à une seule question : *quelle application du catalogue
 * doit entrer dans l'ensemble cible des applications d'un poste parce que
 * l'instance a choisi d'atteindre son cloud par le client de synchronisation ?*
 * L'installation reste le fait de WPKG, moteur déclaratif non absorbé ; l'agent
 * n'unifie que le TRANSPORT (le déclenchement). Franchir cette frontière —
 * ouvrir ici un second moteur de paquets, ou un handler d'installation — serait
 * une régression d'architecture, et le début de l'Epic 59 (plan d'installation
 * multiformat), qui n'est pas au périmètre.
 *
 * **SE5 NE CONNAÎT AUCUN `app_id` DE CLIENT.** Le catalogue d'applications est
 * sous autorité amont : un dépôt imposé désinstalle en cascade tout ce qui n'y
 * figure pas, et une entrée ajoutée localement disparaît à la synchro suivante.
 * Un `app_id` codé en dur serait donc faux sur la moitié des instances et effacé
 * sur l'autre. L'administrateur DÉSIGNE, par produit, l'application qui est le
 * client ({@see FilePolicyService} clés `nextcloud_client_app_id` /
 * `opencloud_client_app_id`). Sans désignation, la position « par le client de
 * synchronisation » est ABSENTE de l'écran, avec son motif.
 * ---------------------------------------------------------------------------
 *
 * **LA GARDE EST REJOUÉE CÔTÉ SERVICE.** Doctrine littérale de
 * {@see \App\Services\Filesystem\Backend\FileBackendSelection} (cité en FQCN :
 * ce service n'en dépend ni à la compilation ni à l'exécution) — *« une garde
 * qui ne vit que dans la liste affichée protège l'étourderie, pas la requête
 * forgée »*. {@see self::assertAvailable()} lève AVANT toute écriture.
 *
 * **LA VALIDATION DU `<remove>` EST PRÉDICTIVE, PAS UNE PREUVE.** Elle constate
 * qu'une désinstallation est **décrite** dans la recette WPKG persistée
 * (`applications.xml`) — pas qu'elle est **complète**. Un `<remove>` peut
 * parfaitement laisser derrière lui un profil `%APPDATA%`, une tâche au logon,
 * une clé `Run` ou un service. Ce que la recette nettoie réellement ne se
 * constate que sur un poste, et c'est l'objet du runbook QA de la story. La
 * garde vaut néanmoins d'exister : désigner un paquet sans aucune
 * désinstallation, c'est promettre une convergence qu'on ne peut pas tenir.
 * Précédent maison assumé : la validation prédictive des associations natives —
 * *le serveur prédit l'échec*.
 *
 * **LA LECTURE DE LA RECETTE EST LOCALE.** `DOMDocument` sur la colonne
 * persistée, jamais un appel réseau, jamais une relecture du dépôt amont
 * (précédent maison : {@see \App\Services\AppStore\PackagesXmlService} parcourt
 * déjà ces recettes en DOM). Aucun cache, aucune mémoïsation : le patron du
 * dépôt pour un réglage global est la RELECTURE, et `Cache::lock()` est de toute
 * façon inutilisable sous APCu.
 *
 * ---------------------------------------------------------------------------
 * ⚠️ **LA BORNE {@see self::MIN_AGENT_VERSION}, ET CE QUI CASSE EN DESSOUS.**
 *
 * La convergence du RETRAIT n'est pas un vœu : elle est acquise dans l'agent
 * depuis le 2026-06-19. `ApplicationsHandler.Test()` a DEUX conditions — (1)
 * `désiré ⊆ installé`, lu dans `wpkg.xml` ; (2) `desired set == profil DÉPOSÉ`,
 * comparé au `profiles.xml` réellement posé sur le poste. C'est la seconde qui
 * fait tout : quand un `app_id` QUITTE l'ensemble cible, `Test` échoue, `Apply`
 * redépose un `profiles.xml` amaigri et relance `wpkg-client.vbs /synchronize`,
 * et WPKG désinstalle nativement via le `<remove>` de la recette.
 *
 * Cette seconde condition est arrivée en agent **2.2.17**. Un poste qui exécute
 * un binaire ANTÉRIEUR installe le client et **ne le retire jamais** — sans
 * aucun signal : ni statut, ni erreur, ni ligne de rapport. Ce n'est pas du
 * bruit, c'est un mensonge tranquille, et c'est la seule raison pour laquelle
 * l'écran AVERTIT ({@see self::agentVersionCensus()}). Il avertit et n'interdit
 * rien : interdire figerait la décision d'un établissement sur l'état de mise à
 * jour de son parc.
 *
 * ⚠️ La documentation du contrat a longtemps affirmé le contraire (*« il ne la
 * désinstalle pas de lui-même »*). Elle était périmée ; elle est corrigée par
 * cette story. **Le code de l'agent fait autorité.**
 * ---------------------------------------------------------------------------
 */
final class CloudSyncClient
{
    /**
     * La version d'agent à partir de laquelle le RETRAIT converge (condition 2
     * du `Test` du handler `applications`). En dessous : installation correcte,
     * retrait silencieusement impossible.
     */
    public const MIN_AGENT_VERSION = '2.2.17';

    /** Aucun cloud actif : il n'y a littéralement rien à joindre. */
    public const REFUSAL_NO_ACTIVE_CLOUD = 'Aucun cloud n\'est configuré : le client de synchronisation '
        .'n\'a rien à joindre.';

    /** `%s` = le libellé du produit ({@see ActiveCloud::label()}). */
    public const REFUSAL_NO_DESIGNATION = 'Aucune application du catalogue n\'est désignée comme client %s. '
        .'Désignez-la ci-dessous avant de choisir ce mode d\'accès.';

    /** `%s` = l'`app_id` désigné — inconnu du catalogue, ou en statut ≠ installée. */
    public const REFUSAL_NOT_INSTALLED = 'L\'application désignée (%s) n\'est pas installée dans le '
        .'catalogue : elle ne peut pas être posée sur les postes.';

    /** `%s` = le nom d'affichage de l'application. */
    public const REFUSAL_NO_REMOVE = 'La recette de « %s » ne décrit aucune désinstallation : la retirer '
        .'des postes laisserait le logiciel en place. Choisissez une application dont le paquet porte une '
        .'désinstallation.';

    /**
     * `%s` = le nom d'affichage. Motif PROPRE à la recette illisible : un `xml`
     * vide, absent ou non analysable ne prouve pas la présence d'un `<remove>`,
     * et le tolérer par défaut reviendrait à décider que l'absence de preuve est
     * une preuve.
     */
    public const REFUSAL_UNREADABLE_RECIPE = 'La recette de « %s » ne se relit pas (contenu absent ou XML '
        .'invalide) : rien n\'y prouve qu\'une désinstallation est décrite. Réimportez le paquet, ou '
        .'désignez une autre application.';

    /** `%d` = le nombre de postes, `%s` = {@see self::MIN_AGENT_VERSION}. */
    public const WARNING_BELOW_MIN_VERSION = '%d poste(s) exécutent une version d\'agent antérieure à %s : '
        .'sur ceux-là, le client s\'installera mais ne sera pas retiré si vous changez d\'avis.';

    /** `%d` = le nombre de postes qui n'ont jamais rapporté de version. */
    public const NOTICE_UNKNOWN_VERSION = '%d poste(s) n\'ont jamais rapporté leur version.';

    /**
     * La clé de désignation du produit, ou `null` pour {@see ActiveCloud::Aucun}
     * — qui n'a pas de client parce qu'il n'a pas de cloud.
     */
    public function policyKeyFor(ActiveCloud $cloud): ?string
    {
        return match ($cloud) {
            ActiveCloud::Aucun => null,
            ActiveCloud::Nextcloud => 'nextcloud_client_app_id',
            ActiveCloud::OpenCloud => 'opencloud_client_app_id',
        };
    }

    /**
     * L'`app_id` DÉSIGNÉ pour ce produit — le réglage brut, sans aucune garde.
     * Ce n'est pas ce qu'il faut unionner ({@see self::appIdFor()}) : une
     * désignation peut parfaitement pointer une application archivée ou une
     * recette sans désinstallation.
     */
    public function designatedAppId(ActiveCloud $cloud): ?string
    {
        $key = $this->policyKeyFor($cloud);

        if ($key === null) {
            return null;
        }

        $appId = FilePolicyService::globalConfig()[$key] ?? null;

        return is_string($appId) && $appId !== '' ? $appId : null;
    }

    /**
     * Le motif pour lequel la position « par le client de synchronisation »
     * n'est PAS tenable pour ce cloud, ou `null` si elle l'est.
     *
     * La phrase est destinée à l'administrateur : elle dit ce qui manque et où
     * le régler, jamais « indisponible ».
     *
     * ⚠️ **C'est une garde d'ÉCRITURE, et elle ne vit que là** : elle filtre le
     * sélecteur de l'écran et refuse la soumission. La compilation d'état, elle,
     * ne la rejoue PAS — {@see self::appIdFor()} dit pourquoi (un catalogue qui
     * passe en `Downloading` désinstallerait le parc).
     */
    public function refusalFor(ActiveCloud $cloud): ?string
    {
        if ($cloud === ActiveCloud::Aucun) {
            return self::REFUSAL_NO_ACTIVE_CLOUD;
        }

        $appId = $this->designatedAppId($cloud);

        if ($appId === null) {
            return sprintf(self::REFUSAL_NO_DESIGNATION, $cloud->label());
        }

        $application = $this->catalogEntry($appId);

        if ($application === null || $application->status !== ApplicationStatus::Installed) {
            // Une `Application` en statut `Available` (matérialisée par un ordre
            // amont, jamais installée sur le serveur) n'a pas de recette
            // exploitable : WPKG échouerait sur le poste et rendrait `error`. Un
            // échec bruyant, mais évitable côté serveur.
            return sprintf(self::REFUSAL_NOT_INSTALLED, $appId);
        }

        return $this->recipeRefusalFor($application);
    }

    /** `true` si la position est tenable pour ce cloud. */
    public function isAvailable(ActiveCloud $cloud): bool
    {
        return $this->refusalFor($cloud) === null;
    }

    /**
     * Refuse AVANT toute écriture une position non tenable.
     *
     * @throws InvalidArgumentException
     */
    public function assertAvailable(ActiveCloud $cloud): void
    {
        $refusal = $this->refusalFor($cloud);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }
    }

    /**
     * L'`app_id` à UNIONNER à l'ensemble cible des applications d'un poste, ou
     * `null` — c'est LE point de contact avec la compilation d'état.
     *
     * ---------------------------------------------------------------------------
     * ⚠️ **LA COMPILATION NE REJOUE PAS LA GARDE DE SAISIE, ET C'EST DÉLIBÉRÉ.**
     *
     * Elle ne retient que le STRUCTUREL : une désignation existe pour le cloud
     * actif, et une ligne {@see Application} porte cet `app_id`. **Ni le statut,
     * ni la présence d'un `<remove>` ne sont contrôlés ici.** Le contrôle de
     * statut et la validation prédictive de la recette restent entiers dans
     * {@see self::refusalFor()} / {@see self::assertAvailable()} et dans le filtre
     * du sélecteur de l'écran — c'est-à-dire **à l'ÉCRITURE**, seul moment où un
     * administrateur décide quelque chose.
     *
     * **Le motif est le scénario `Downloading`.** Le catalogue bouge sans qu'aucun
     * administrateur ne décide : `AppStoreService::installApplication()` bascule le
     * statut à `Downloading` avant téléchargement et ne le rend à `Installed` qu'à
     * `finalizeInstallation()`, un échec le laisse à `Error`, et
     * `SyncApplicationJob::applyPayloadToApplication()` réécrit `xml` **et**
     * `app_id` depuis l'amont. Rejouer la garde de saisie ici ferait SORTIR
     * l'`app_id` de l'ensemble cible pendant la réinstallation ou la mise à jour de
     * l'application désignée — donc **WPKG désinstallerait le client sur tout le
     * parc**, pour le réinstaller à la passe suivante. Une garde qui protège un
     * formulaire n'a pas à pouvoir désinstaller un parc : la compilation projette
     * la **décision persistée**, la posabilité est une propriété de l'**écriture**.
     * ---------------------------------------------------------------------------
     *
     * Court-circuits, dans cet ordre, et pour un coût croissant :
     *  1. aucun cloud actif ⇒ `null`, ZÉRO requête ;
     *  2. chemin d'accès `web` (le DÉFAUT) ⇒ `null`, aucune requête sur
     *     `applications` — l'ensemble cible reste byte-identique à celui d'avant
     *     cette story, et le golden d'état ne bouge pas ;
     *  3. aucune désignation ⇒ `null`, toujours aucune requête sur `applications` ;
     *  4. désignation qui ne résout AUCUNE ligne de catalogue ⇒ `null` : il n'y
     *     aurait ni `name` à hydrater, ni `sourceId`, et le provider n'émettrait
     *     qu'un `Log::warning`.
     *
     * Rend l'`app_id` **du catalogue**, jamais la chaîne désignée : c'est celle-là
     * que le `whereIn('app_id', …)` du provider retrouvera, à la casse près.
     */
    public function appIdFor(ActiveCloud $cloud): ?string
    {
        if ($cloud === ActiveCloud::Aucun) {
            return null;
        }

        $config = FilePolicyService::globalConfig();

        if (($config['cloud_access_path'] ?? null) !== CloudAccessPath::ClientNatif->value) {
            return null;
        }

        $key = $this->policyKeyFor($cloud);
        $appId = $key === null ? null : ($config[$key] ?? null);

        if (! is_string($appId) || trim($appId) === '') {
            return null;
        }

        $application = $this->catalogEntry(trim($appId));

        return $application === null ? null : (string) $application->app_id;
    }

    /**
     * Le motif de refus PRÉDICTIF d'une recette, ou `null` si elle décrit une
     * désinstallation pour SON `package-id`.
     *
     * Un `<remove>` porté par un AUTRE `package-id` du même document ne compte
     * pas : c'est la désinstallation de ce paquet-ci qui est en jeu.
     *
     * L'appariement du `package-id` est INSENSIBLE À LA CASSE — le dépôt a déjà
     * tranché ainsi au même endroit ({@see \App\Wpkg\Deployment\Support\ApplicationXmlReader}
     * apparie en `LOWER(app_id)` : *« parité legacy lowercase, robustesse
     * collation »*). Une recette dont l'`id` ne diffère que par la casse décrit
     * bien la désinstallation de ce paquet.
     */
    public function recipeRefusalFor(Application $application): ?string
    {
        $name = (string) $application->name;
        $xml = $application->xml;

        if (! is_string($xml) || trim($xml) === '') {
            return sprintf(self::REFUSAL_UNREADABLE_RECIPE, $name);
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET : aucune résolution réseau. Et surtout PAS de
            // LIBXML_NOENT — substituer les entités ouvrirait un XXE sur une
            // colonne alimentée par un dépôt tiers.
            $parsed = $document->loadXML(trim($xml), LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($parsed === false) {
            return sprintf(self::REFUSAL_UNREADABLE_RECIPE, $name);
        }

        $appId = (string) $application->app_id;

        foreach ($document->getElementsByTagName('package') as $package) {
            if (! $package instanceof DOMElement || strcasecmp(trim($package->getAttribute('id')), $appId) !== 0) {
                continue;
            }

            if ($package->getElementsByTagName('remove')->length > 0) {
                return null;
            }
        }

        return sprintf(self::REFUSAL_NO_REMOVE, $name);
    }

    /**
     * Le recensement des versions d'agent du parc, en UNE lecture SQL.
     *
     * Les postes qui n'ont JAMAIS rapporté de version ne sont pas comptés comme
     * antérieurs : on ne sait pas ce qu'ils exécutent, et les compter d'un côté
     * ou de l'autre serait inventer. Ils sont comptés à part, et nommés.
     *
     * @return array{below: int, unknown: int}
     */
    public function agentVersionCensus(): array
    {
        $below = 0;
        $unknown = 0;

        $rows = Workstation::query()
            ->whereNull('archived_at')
            ->selectRaw('agent_reported_version as reported, count(*) as total')
            ->groupBy('agent_reported_version')
            ->get();

        foreach ($rows as $row) {
            $reported = is_string($row->reported) ? trim($row->reported) : '';
            $total = (int) $row->total;

            if ($reported === '') {
                $unknown += $total;

                continue;
            }

            // Le préfixe `v` se rencontre dans les tags de release ; il n'est pas
            // une version. `version_compare` ne le sait pas.
            if (version_compare(ltrim($reported, 'vV'), self::MIN_AGENT_VERSION, '<')) {
                $below += $total;
            }
        }

        return ['below' => $below, 'unknown' => $unknown];
    }

    /**
     * L'avertissement de version à afficher, ou `null` quand tout le parc est à
     * la borne (et qu'aucun poste ne s'est tu).
     *
     * Il INFORME et n'interdit rien.
     */
    public function agentVersionWarning(): ?string
    {
        ['below' => $below, 'unknown' => $unknown] = $this->agentVersionCensus();

        $phrases = [];

        if ($below > 0) {
            $phrases[] = sprintf(self::WARNING_BELOW_MIN_VERSION, $below, self::MIN_AGENT_VERSION);
        }

        if ($unknown > 0) {
            $phrases[] = sprintf(self::NOTICE_UNKNOWN_VERSION, $unknown);
        }

        return $phrases === [] ? null : implode(' ', $phrases);
    }

    /**
     * La ligne de catalogue d'un `app_id`, appariée SANS ÉGARD À LA CASSE (même
     * choix que {@see \App\Wpkg\Deployment\Support\ApplicationXmlReader}, qui
     * apparie en `LOWER(app_id)`). Plusieurs lignes partageant un même `app_id`
     * (improbable) : la PK la plus petite gagne — même déterminisme que
     * l'hydratation du provider.
     */
    private function catalogEntry(string $appId): ?Application
    {
        return Application::query()
            ->whereRaw('LOWER(app_id) = ?', [mb_strtolower($appId)])
            ->orderBy('id')
            ->first();
    }
}
