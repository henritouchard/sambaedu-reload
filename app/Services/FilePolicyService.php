<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CloudAccessPath;
use App\Models\SystemSetting;

/**
 * Politique de gestion des fichiers — réglage GLOBAL d'instance UNIQUEMENT
 * (décision Henri 2026-07-17). QUATRE CAPACITÉS INDÉPENDANTES (pas un mode
 * exclusif, PAS d'override par parc) :
 *  - `home`      : monter le home perso (K:).
 *  - `shares`    : monter les partages serveur (classes H: + répertoires gérés).
 *  - `nextcloud` : « Accès Nextcloud » — l'instance monte les partages SMB
 *                  existants en stockage externe et SE5 provisionne ce montage et
 *                  les comptes (story 61.1).
 *  - `opencloud` : « Accès OpenCloud » — une instance OpenCloud devient une
 *                  AUTORITÉ D'ÉCRITURE possible pour un répertoire géré (le plan
 *                  y devient un espace de projet et des octrois par nœud).
 *
 * **La quatrième est un INTERRUPTEUR INDÉPENDANT, et rien de plus.** Elle ne
 * remplace ni ne renomme la troisième : OpenCloud est une ALTERNATIVE à
 * Nextcloud et au serveur de fichiers historique, pas leur successeur déclaré.
 * Aucun réglage `nextcloud_*` n'est réutilisé — `nextcloud_smb_host` n'aurait
 * d'ailleurs aucun sens ici, ce produit ne montant aucun partage SMB. Elle naît
 * ÉTEINTE : une instance déployée ne devient pas une autorité parce qu'elle
 * existe.
 *
 * « Web uniquement » n'est PAS une option : c'est l'état nul (tout à `false`) —
 * l'utilisateur passe par le navigateur, rien n'est monté ni provisionné.
 *
 * **`nextcloud_desktop_shortcut` n'est PAS une cinquième capacité** : rien n'est
 * monté ni provisionné, un raccourci vers le portail web est simplement posé sur
 * le Bureau. Il vit ici parce qu'il n'a de sens qu'adossé à la capacité Nextcloud
 * et à son URL, et parce que le réglage est GLOBAL comme les quatre autres. Il est
 * lu par le {@see \App\Services\Agent\Providers\ShortcutsStateProvider}, jamais par
 * {@see self::capabilities()} — un consommateur de capacités n'a rien à en faire.
 *
 * Persisté dans `SystemSetting` clé `files.policy` (JSON), édité sur
 * `/admin/settings/files`. Défaut `home✓ shares✓ nextcloud✗` (comportement
 * historique : K:/H: montés). Consommé par le
 * {@see \App\Services\Agent\Providers\DrivesStateProvider} (`home`→K:,
 * `shares`→H:+répertoires gérés), résolu PAR CAPACITÉ indépendamment.
 *
 * ---------------------------------------------------------------------------
 * **Story 61.1 — les réglages de connexion NON SECRETS vivent ici.** L'URL, le
 * compte admin, le nom du serveur SMB à monter et la vérification TLS sont du
 * réglage : ils vont dans ce JSON, lisible et diffable. **L'app password admin
 * n'y est PAS** — il vit chiffré dans `service_credentials` sous le nom
 * {@see \App\Services\Nextcloud\NextcloudConnectionConfig::CREDENTIAL_NAME}.
 * `files.policy` est stocké en clair ; y mettre un secret le rendrait lisible à
 * quiconque lit la table des réglages, et à tout export de configuration.
 *
 * **Recadrage du 2026-08-08 — IL N'Y A PLUS DE « MODE ».** La story 61.2 avait
 * ajouté ici une clé `nextcloud_mode` (instance administrée / compte porteur
 * délégué) et l'identifiant du compte porteur. La mesure contre une instance réelle
 * a montré qu'un compte ordinaire ne peut créer ni Team folder, ni groupe, ni
 * partage de groupe : le mode délégué ne pouvait pas tenir la clôture, qui est la
 * raison d'être du plan de fichiers. SE5 EXIGE donc un compte administrateur, les
 * deux clés ont été retirées, et le réglage ne décrit plus qu'UNE connexion — celle
 * de 61.1. Un payload persisté qui les porte encore les voit simplement ignorées.
 * ---------------------------------------------------------------------------
 */
final class FilePolicyService
{
    /** Clé SystemSetting du réglage d'instance. */
    public const SETTING_KEY = 'files.policy';

    /**
     * Config globale par défaut : home & partages actifs (historique), accès
     * Nextcloud désactivé, connexion vide.
     *
     * `nextcloud_verify_tls` vaut **`true`** : le chemin legacy désactivait la
     * vérification du certificat en dur dans le code (`getNextcloudAppPassword`),
     * ce qui rendait la faiblesse invisible à l'exploitant. Ici, l'assouplissement
     * est un choix visible, coché sur l'écran et persisté.
     *
     * @return array{home: bool, shares: bool, nextcloud: bool, nextcloud_server_url: string, nextcloud_admin_user: string, nextcloud_smb_host: string, nextcloud_verify_tls: bool, nextcloud_desktop_shortcut: bool, opencloud: bool, opencloud_server_url: string, opencloud_admin_user: string, opencloud_verify_tls: bool, cloud_access_path: string, nextcloud_client_app_id: ?string, opencloud_client_app_id: ?string}
     */
    public static function defaults(): array
    {
        return [
            'home' => true,
            'shares' => true,
            'nextcloud' => false,
            'nextcloud_server_url' => '',
            'nextcloud_admin_user' => '',
            // Vide = « le serveur de fichiers connu de l'instance ». Le défaut
            // effectif est DÉRIVÉ (`sambaedu.se4fs_name`) au moment du
            // provisionnement, jamais recopié ici : recopier figerait une valeur
            // qui doit suivre la configuration de l'instance.
            'nextcloud_smb_host' => '',
            'nextcloud_verify_tls' => true,

            // Poser sur le Bureau un raccourci vers le PORTAIL WEB de l'instance.
            // Ce n'est PAS une capacité (rien n'est monté, rien n'est provisionné)
            // mais une PRÉSENTATION : le seul chemin d'accès d'un répertoire servi
            // par Nextcloud est le web, et un chemin d'accès que l'utilisateur ne
            // voit pas n'existe pas pour lui. Naît ÉTEINT — activer « Accès
            // Nextcloud » ne décide pas à la place de l'exploitant de ce qui
            // apparaît sur les bureaux de l'établissement.
            'nextcloud_desktop_shortcut' => false,

            // --- Accès OpenCloud : STRICTEMENT ADDITIF ------------------------
            // Aucune clé ci-dessus n'est réutilisée, renommée ni supprimée : un
            // payload persisté avant l'arrivée de ces clés se relit à
            // l'identique et signifie exactement ce qu'il signifiait
            // (capacité éteinte, connexion vide).
            'opencloud' => false,
            'opencloud_server_url' => '',
            'opencloud_admin_user' => '',
            // Même doctrine que pour l'autre produit : la vérification du
            // certificat est VRAIE par défaut, et son assouplissement est un
            // choix visible à l'écran, jamais un défaut caché dans le code.
            'opencloud_verify_tls' => true,

            // --- Story 63.3 : le chemin d'accès au cloud, STRICTEMENT ADDITIF --
            // Une clé de plus, en queue, avec un défaut qui reproduit exactement
            // le comportement d'avant son arrivée : un payload persisté qui ne la
            // porte pas se relit à l'identique et signifie ce qu'il signifiait.
            //
            // Ce n'est PAS une capacité : rien n'est monté, rien n'est
            // provisionné. C'est la réponse à « par où l'utilisateur atteint ses
            // fichiers quand ils vivent au cloud », et elle n'a de sens
            // qu'adossée à un cloud actif. Elle n'a pas encore de lecteur sur le
            // poste — l'écran le DIT, plutôt que de laisser croire à un effet.
            'cloud_access_path' => CloudAccessPath::Web->value,

            // --- Story 63.5 : QUELLE application du catalogue EST le client ---
            // Deux clés, UNE PAR PRODUIT, strictement additives, défaut `null`.
            //
            // **Jamais une clé unique « le client du cloud actif ».** Une
            // instance qui bascule de Nextcloud à OpenCloud (ou l'inverse)
            // continuerait alors de désigner le paquet de l'ancien produit, et
            // SE5 poserait silencieusement le mauvais logiciel sur tout le parc.
            // Deux clés rendent cette confusion irreprésentable — symétriquement
            // à `nextcloud_server_url` / `opencloud_server_url`.
            //
            // La valeur est un `app_id` (`applications.app_id`, l'identifiant de
            // paquet WPKG), JAMAIS une PK d'`Application` : la PK est un détail
            // de base, l'`app_id` est l'identité que le contrat agent transporte.
            //
            // **SE5 ne code aucun `app_id` en dur** : le catalogue est sous
            // autorité amont (un dépôt imposé désinstalle en cascade ce qui n'y
            // figure pas), donc l'identifiant du paquet client varie d'une
            // instance à l'autre. L'administrateur DÉSIGNE ; le serveur ne
            // devine pas.
            'nextcloud_client_app_id' => null,
            'opencloud_client_app_id' => null,
        ];
    }

    /**
     * Config globale persistée, fusionnée avec les défauts (tolère un JSON partiel
     * ou un ancien payload `{mode:...}` : les clés inconnues sont ignorées, on
     * retombe proprement sur les défauts).
     *
     * @return array{home: bool, shares: bool, nextcloud: bool, nextcloud_server_url: string, nextcloud_admin_user: string, nextcloud_smb_host: string, nextcloud_verify_tls: bool, nextcloud_desktop_shortcut: bool, opencloud: bool, opencloud_server_url: string, opencloud_admin_user: string, opencloud_verify_tls: bool, cloud_access_path: string, nextcloud_client_app_id: ?string, opencloud_client_app_id: ?string}
     */
    public static function globalConfig(): array
    {
        $stored = SystemSetting::get(self::SETTING_KEY);
        $defaults = self::defaults();
        if (! is_array($stored)) {
            return $defaults;
        }

        return [
            'home' => array_key_exists('home', $stored) ? (bool) $stored['home'] : $defaults['home'],
            'shares' => array_key_exists('shares', $stored) ? (bool) $stored['shares'] : $defaults['shares'],
            'nextcloud' => array_key_exists('nextcloud', $stored) ? (bool) $stored['nextcloud'] : $defaults['nextcloud'],
            'nextcloud_server_url' => is_string($stored['nextcloud_server_url'] ?? null)
                ? $stored['nextcloud_server_url']
                : $defaults['nextcloud_server_url'],
            'nextcloud_admin_user' => is_string($stored['nextcloud_admin_user'] ?? null)
                ? $stored['nextcloud_admin_user']
                : $defaults['nextcloud_admin_user'],
            'nextcloud_smb_host' => is_string($stored['nextcloud_smb_host'] ?? null)
                ? $stored['nextcloud_smb_host']
                : $defaults['nextcloud_smb_host'],
            'nextcloud_verify_tls' => array_key_exists('nextcloud_verify_tls', $stored)
                ? (bool) $stored['nextcloud_verify_tls']
                : $defaults['nextcloud_verify_tls'],
            'nextcloud_desktop_shortcut' => array_key_exists('nextcloud_desktop_shortcut', $stored)
                ? (bool) $stored['nextcloud_desktop_shortcut']
                : $defaults['nextcloud_desktop_shortcut'],

            'opencloud' => array_key_exists('opencloud', $stored)
                ? (bool) $stored['opencloud']
                : $defaults['opencloud'],
            'opencloud_server_url' => is_string($stored['opencloud_server_url'] ?? null)
                ? $stored['opencloud_server_url']
                : $defaults['opencloud_server_url'],
            'opencloud_admin_user' => is_string($stored['opencloud_admin_user'] ?? null)
                ? $stored['opencloud_admin_user']
                : $defaults['opencloud_admin_user'],
            'opencloud_verify_tls' => array_key_exists('opencloud_verify_tls', $stored)
                ? (bool) $stored['opencloud_verify_tls']
                : $defaults['opencloud_verify_tls'],

            // Vocabulaire FERMÉ : une valeur hors vocabulaire retombe sur le
            // défaut plutôt que d'être rendue telle quelle — c'est un réglage de
            // présentation, pas une décision d'emplacement, et rien ici ne se
            // prête à un refus qui bloquerait l'écran entier.
            'cloud_access_path' => CloudAccessPath::isKnown($stored['cloud_access_path'] ?? null)
                ? (string) $stored['cloud_access_path']
                : $defaults['cloud_access_path'],

            // Story 63.5 — une chaîne vide, un blanc ou une valeur non textuelle
            // ne sont PAS une désignation : ils se relisent en `null`, comme
            // l'absence de clé. Un `''` persisté qui se relirait en `''` ferait
            // chercher une `Application` d'`app_id` vide.
            'nextcloud_client_app_id' => self::readAppId($stored, 'nextcloud_client_app_id'),
            'opencloud_client_app_id' => self::readAppId($stored, 'opencloud_client_app_id'),
        ];
    }

    /**
     * Story 63.5 — un `app_id` persisté, ou `null`. Trim, puis « vide = absent ».
     *
     * @param  array<mixed>  $stored
     */
    private static function readAppId(array $stored, string $key): ?string
    {
        $raw = $stored[$key] ?? null;

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    /**
     * Les quatre capacités effectives (sans les URL) — consommées par le gating
     * des lecteurs et par la posabilité d'une autorité d'écriture.
     *
     * @return array{home: bool, shares: bool, nextcloud: bool, opencloud: bool}
     */
    public static function capabilities(): array
    {
        $config = self::globalConfig();

        return [
            'home' => $config['home'],
            'shares' => $config['shares'],
            'nextcloud' => $config['nextcloud'],
            'opencloud' => $config['opencloud'],
        ];
    }

    /**
     * Persiste la config globale (upsert SystemSetting). Normalise l'URL.
     *
     * **Tous les paramètres nullables, laissés à `null`, CONSERVENT la valeur
     * persistée.** Ce n'est pas une commodité : les appelants antérieurs aux stories
     * 61.1/61.2 ne les connaissent pas, et un défaut « chaîne vide » leur ferait
     * effacer la configuration de connexion à chaque bascule de capacité — une perte
     * silencieuse dont personne ne verrait la cause.
     *
     * **Les quatre paramètres OpenCloud sont AJOUTÉS EN QUEUE, tous nullables**, et
     * pour exactement la même raison : les deux appelants existants les ignorent, et
     * un appel qui ne les nomme pas ne doit rien effacer. `nextcloudDesktopShortcut`
     * suit la même règle et la même place — en queue, nullable : un appelant qui ne
     * le nomme pas ne fait pas disparaître un raccourci déjà posé sur les bureaux.
     *
     * **Story 63.5 — les deux désignations de client, EN QUEUE et nullables**, et
     * avec une nuance qui n'existait pour aucun paramètre précédent : leur valeur
     * persistée peut LÉGITIMEMENT être `null` (aucune application désignée). Un
     * `null` qui signifierait à la fois « conserve » et « efface » rendrait le
     * retrait d'une désignation impossible. La convention est donc :
     *  - paramètre ABSENT / `null` ⇒ la désignation persistée est CONSERVÉE ;
     *  - chaîne VIDE ⇒ la désignation est EFFACÉE (relue en `null`).
     * {@see self::patchGlobal()} — seul endroit du dépôt à connaître cet ordre —
     * passe toujours une chaîne, donc l'effacement y est atteignable.
     */
    public static function setGlobal(
        bool $home,
        bool $shares,
        bool $nextcloud,
        string $nextcloudServerUrl = '',
        ?string $nextcloudAdminUser = null,
        ?string $nextcloudSmbHost = null,
        ?bool $nextcloudVerifyTls = null,
        ?bool $opencloud = null,
        ?string $opencloudServerUrl = null,
        ?string $opencloudAdminUser = null,
        ?bool $opencloudVerifyTls = null,
        ?bool $nextcloudDesktopShortcut = null,
        ?string $cloudAccessPath = null,
        ?string $nextcloudClientAppId = null,
        ?string $opencloudClientAppId = null,
    ): void {
        $current = self::globalConfig();

        SystemSetting::set(self::SETTING_KEY, [
            'home' => $home,
            'shares' => $shares,
            'nextcloud' => $nextcloud,
            'nextcloud_server_url' => trim($nextcloudServerUrl),
            'nextcloud_admin_user' => trim($nextcloudAdminUser ?? $current['nextcloud_admin_user']),
            'nextcloud_smb_host' => trim($nextcloudSmbHost ?? $current['nextcloud_smb_host']),
            'nextcloud_verify_tls' => $nextcloudVerifyTls ?? $current['nextcloud_verify_tls'],
            'nextcloud_desktop_shortcut' => $nextcloudDesktopShortcut ?? $current['nextcloud_desktop_shortcut'],

            'opencloud' => $opencloud ?? $current['opencloud'],
            'opencloud_server_url' => trim($opencloudServerUrl ?? $current['opencloud_server_url']),
            'opencloud_admin_user' => trim($opencloudAdminUser ?? $current['opencloud_admin_user']),
            'opencloud_verify_tls' => $opencloudVerifyTls ?? $current['opencloud_verify_tls'],

            // Story 63.3 — même règle, même place : EN QUEUE et NULLABLE. Un
            // appelant qui ne le nomme pas ne fait pas retomber le chemin
            // d'accès sur le navigateur à l'insu de l'exploitant. Une valeur
            // hors vocabulaire est ramenée au persisté plutôt qu'écrite.
            'cloud_access_path' => CloudAccessPath::isKnown($cloudAccessPath)
                ? (string) $cloudAccessPath
                : $current['cloud_access_path'],

            // Story 63.5 — `null` conserve, chaîne vide efface (cf. docblock).
            'nextcloud_client_app_id' => self::normalizeAppId($nextcloudClientAppId ?? $current['nextcloud_client_app_id']),
            'opencloud_client_app_id' => self::normalizeAppId($opencloudClientAppId ?? $current['opencloud_client_app_id']),
        ]);
    }

    /** Story 63.5 — un `app_id` trimé, ou `null` quand il ne reste rien. */
    private static function normalizeAppId(?string $appId): ?string
    {
        $appId = trim((string) $appId);

        return $appId === '' ? null : $appId;
    }

    /**
     * Story 63.3 (correction de revue) — **LE SEUL ENDROIT DU DÉPÔT QUI CONNAÎT
     * L'ORDRE DES PARAMÈTRES DE {@see self::setGlobal()}.**
     *
     * Écrit la config globale en ne nommant QUE ce qui change, tout le reste
     * étant relu et repassé explicitement. Les appelants énuméraient chacun les
     * treize paramètres positionnels dans le bon ordre — un écran, un miroir, une
     * page de connexion — si bien que le jour où la signature bouge, l'un des
     * sites serait oublié : exactement la classe de défaut que cette story ferme.
     *
     * **Rien n'est conservé par omission.** `setGlobal()` a dix paramètres
     * nullables qui conservent le persisté, mais son quatrième
     * (`$nextcloudServerUrl`) est un `string` de défaut `''` TOUJOURS écrit : un
     * appelant qui l'oublie efface l'adresse de l'instance et éteint la chaîne
     * cloud entière. En repassant les treize valeurs relues, cette méthode rend ce
     * piège inatteignable — et c'est la raison pour laquelle elle est le passage
     * obligé plutôt qu'une commodité.
     *
     * Une clé inconnue est ignorée ; une clé absente n'est jamais effacée.
     *
     * @param  array<string, mixed>  $changes  les clés de {@see self::defaults()} à modifier
     */
    public static function patchGlobal(array $changes): void
    {
        $config = array_replace(self::globalConfig(), array_intersect_key($changes, self::defaults()));

        self::setGlobal(
            (bool) $config['home'],
            (bool) $config['shares'],
            (bool) $config['nextcloud'],
            (string) $config['nextcloud_server_url'],
            (string) $config['nextcloud_admin_user'],
            (string) $config['nextcloud_smb_host'],
            (bool) $config['nextcloud_verify_tls'],
            (bool) $config['opencloud'],
            (string) $config['opencloud_server_url'],
            (string) $config['opencloud_admin_user'],
            (bool) $config['opencloud_verify_tls'],
            (bool) $config['nextcloud_desktop_shortcut'],
            (string) $config['cloud_access_path'],
            // Story 63.5 — TOUJOURS une chaîne (jamais `null`) : c'est ce qui rend
            // l'effacement d'une désignation atteignable depuis cette méthode,
            // alors que `null` y signifierait « conserve ».
            (string) ($config['nextcloud_client_app_id'] ?? ''),
            (string) ($config['opencloud_client_app_id'] ?? ''),
        );
    }
}
