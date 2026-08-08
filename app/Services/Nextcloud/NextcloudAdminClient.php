<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Story 61.1 — LE SEUL POINT DE SORTIE HTTP VERS NEXTCLOUD (code nouveau).
 *
 * ---------------------------------------------------------------------------
 * **NIVEAU DE PRIVILÈGE REQUIS : ADMINISTRATEUR DE L'INSTANCE.** Ce n'est pas
 * une précaution, c'est une contrainte de l'API : les montages globaux
 * (`files_external`) et la gestion des comptes (`cloud/users`) sont refusés à un
 * compte ordinaire. Le cadrage 61.2 le dit d'avance, la sonde le vérifie, et un
 * 401/403 nomme l'opération refusée plutôt que de dégrader en silence.
 * ---------------------------------------------------------------------------
 *
 * **Pourquoi une classe et pas des appels dispersés.** Le chemin existant
 * (`UserService::configureUserCloud`) parle à Nextcloud en `curl` nu : aucun test
 * sur l'hôte ne peut l'observer, ses échecs sont muets, et sa désactivation de la
 * vérification TLS est enfouie dans le code. Tout le code NOUVEAU passe donc par
 * ici, sur le client HTTP du framework — **falsifiable par `Http::fake()`**, donc
 * testable sans réseau, sur sqlite, à chaque exécution de la suite. Le chemin
 * `curl` legacy n'est PAS réécrit par cette story (legs nommé) ; il n'est pas non
 * plus étendu.
 *
 * **Deux familles d'appels, et l'une des deux est le pari de la story.**
 *  - **OCS** (`ocs/v1.php`, `ocs/v2.php`) : comptes, résolution d'identité, mise à
 *    jour de mot de passe. Doublement éprouvée — spike 60.0 et production SE4.
 *  - **L'endpoint d'administration des montages globaux**
 *    (`index.php/apps/files_external/globalstorages`) : `files_external` n'expose
 *    PAS d'API OCS d'écriture. Sa lecture OCS existe (`api/v1/mounts`, montages de
 *    l'utilisateur courant), pas son écriture. **MESURÉ le 2026-08-08 sur
 *    `nc-spike` (Nextcloud 34.0.2)** : l'authentification basic admin passe,
 *    aucune protection anti-CSRF ne s'y oppose — `GET` → `200`, `POST` → `201`,
 *    `DELETE` → `204`, corps d'écriture en `application/x-www-form-urlencoded`.
 *    La règle d'arrêt de la story ne s'est donc pas déclenchée ; la branche `412`
 *    de la sonde reste en place parce qu'une AUTRE instance, autrement
 *    configurée, pourrait la produire — et il vaut mieux la nommer que la
 *    découvrir.
 *
 * **L'en-tête `OCS-APIRequest: true` est posé sur TOUS les appels**, y compris
 * ceux de la seconde famille : c'est l'en-tête que le protocole demande pour les
 * requêtes d'API, et Nextcloud s'en sert pour ne pas exiger d'état de session.
 *
 * **Idempotence normalisée.** Le statuscode OCS `102` (« existe déjà »), mesuré au
 * spike 60.0, est traduit en « déjà conforme » ({@see NextcloudResult::conforming()}),
 * jamais en exception : rejouer le provisionnement est une opération normale.
 *
 * **Ce que ce client N'A PAS, et c'est un invariant testé (AC4).** Aucune méthode
 * de partage OCS, aucune méthode de groupe Nextcloud, aucune méthode de quota.
 * SE5 n'écrit AUCUN droit côté Nextcloud : la seule instance qui tranche un accès
 * est Samba/POSIX, avec les identifiants de l'utilisateur de session. Ajouter une
 * de ces méthodes ne serait pas une extension, ce serait un second plan de
 * permissions sur une zone qui en a déjà un.
 *
 * **Le secret ne sort jamais.** Il n'entre dans aucun message, aucun journal,
 * aucune exception : les messages sont construits à partir de l'OPÉRATION et de la
 * CAUSE. Un test l'épingle.
 */
final class NextcloudAdminClient
{
    /**
     * Délai d'attente par appel. Volontairement court : ces appels sont dans le
     * chemin d'un écran (« Tester la connexion ») ou dans une boucle par
     * utilisateur. Une instance qui met une minute à répondre est en panne, et le
     * dire tout de suite vaut mieux que de faire attendre.
     */
    private const TIMEOUT_SECONDS = 15;

    /** Chemin de l'endpoint d'administration des montages globaux. */
    private const GLOBAL_STORAGES_PATH = 'index.php/apps/files_external/globalstorages';

    /** Statuscode OCS « l'objet existe déjà » — mesuré au spike 60.0. */
    private const OCS_ALREADY_EXISTS = 102;

    /**
     * Statuscodes OCS de succès : `100` pour OCS v1, `200` pour OCS v2. Les deux
     * versions sont employées ici, chacune là où le précédent legacy l'employait.
     *
     * @var list<int>
     */
    private const OCS_SUCCESS = [100, 200];

    public function __construct(private readonly NextcloudConnectionConfig $config)
    {
    }

    // =========================================================================
    // AC1 / AC9 — la sonde de connexion
    // =========================================================================

    /**
     * Trois diagnostics distincts (AC1) : instance injoignable, privilège
     * insuffisant, app « Stockage externe » absente.
     *
     * Deux appels, dans cet ordre, parce que chacun isole UNE cause :
     *  1. les capacités OCS — l'instance répond-elle, et le compte est-il accepté ?
     *  2. la liste des montages globaux — le compte est-il ADMIN, et l'app est-elle
     *     là ? Un 404 sur cette route signifie que la route n'existe pas, donc que
     *     l'app est désactivée ; un 403 signifie que le compte n'est pas admin.
     */
    public function probe(): NextcloudConnectionProbe
    {
        try {
            $capabilities = $this->ocsRequest('GET', 'ocs/v2.php/cloud/capabilities');
        } catch (ConnectionException $e) {
            return NextcloudConnectionProbe::unreachable(sprintf(
                'Instance Nextcloud injoignable à l\'adresse %s (%s).',
                $this->config->baseUrl,
                $this->shortReason($e),
            ));
        }

        if ($capabilities->status() === 401) {
            return NextcloudConnectionProbe::notAdministrator(
                sprintf(
                    'Le compte « %s » a été refusé par l\'instance (identifiants invalides ou compte désactivé).',
                    $this->config->adminUser,
                ),
                401,
            );
        }

        if ($capabilities->status() >= 500) {
            return NextcloudConnectionProbe::rejected(
                sprintf('L\'instance a répondu une erreur serveur (HTTP %d).', $capabilities->status()),
                $capabilities->status(),
            );
        }

        try {
            $storages = $this->adminRequest('GET', self::GLOBAL_STORAGES_PATH);
        } catch (ConnectionException $e) {
            return NextcloudConnectionProbe::unreachable(sprintf(
                'Instance Nextcloud injoignable à l\'adresse %s (%s).',
                $this->config->baseUrl,
                $this->shortReason($e),
            ));
        }

        $status = $storages->status();

        if ($status === 404) {
            return NextcloudConnectionProbe::appMissing(
                'L\'app « Stockage externe » (files_external) n\'est pas active sur l\'instance : '
                . 'activez-la (occ app:enable files_external) avant de provisionner.',
                404,
            );
        }

        if ($status === 401 || $status === 403) {
            return NextcloudConnectionProbe::notAdministrator(
                sprintf(
                    'Le compte « %s » n\'est pas administrateur de l\'instance : les montages globaux et '
                    . 'la gestion des comptes exigent ce niveau de privilège.',
                    $this->config->adminUser,
                ),
                $status,
            );
        }

        if ($status === 412) {
            // Protection anti-CSRF sur une route `index.php`. C'est EXACTEMENT le
            // scénario de la règle d'arrêt de l'AC10 : on le nomme, avec son code,
            // et on n'essaie rien d'autre.
            return NextcloudConnectionProbe::rejected(
                'L\'instance a refusé la requête d\'administration des montages (HTTP 412, protection '
                . 'anti-CSRF sur les routes d\'administration). Le canal d\'écriture des montages n\'est '
                . 'pas franchissable en authentification par app password sur cette instance.',
                412,
            );
        }

        if (! $storages->successful()) {
            return NextcloudConnectionProbe::rejected(
                sprintf('L\'instance a refusé la lecture des montages globaux (HTTP %d).', $status),
                $status,
            );
        }

        return NextcloudConnectionProbe::ok();
    }

    // =========================================================================
    // AC3 — les montages external storage
    // =========================================================================

    /**
     * Liste les montages globaux déclarés sur l'instance.
     *
     * @return NextcloudResult `data['storages']` = liste brute des entrées.
     */
    public function listGlobalStorages(): NextcloudResult
    {
        try {
            $response = $this->adminRequest('GET', self::GLOBAL_STORAGES_PATH);
        } catch (ConnectionException $e) {
            return $this->unreachable('lecture des montages globaux', $e);
        }

        if (! $response->successful()) {
            return $this->httpFailure('lecture des montages globaux', $response);
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            return NextcloudResult::failed(
                NextcloudFailure::Illisible,
                'Lecture des montages globaux : l\'instance n\'a pas répondu une liste exploitable.',
                $response->status(),
            );
        }

        // L'instance rend soit une liste, soit un objet indexé par identifiant
        // selon la version. On normalise en liste plutôt que d'en présumer une.
        $storages = array_values(array_filter($decoded, 'is_array'));

        return NextcloudResult::ok(['storages' => $storages], $response->status());
    }

    /** Crée un montage global. */
    public function createGlobalStorage(ExternalStorageDefinition $definition): NextcloudResult
    {
        $operation = sprintf('création du montage « %s »', $definition->label());

        try {
            $response = $this->adminRequest('POST', self::GLOBAL_STORAGES_PATH, $definition->toPayload());
        } catch (ConnectionException $e) {
            return $this->unreachable($operation, $e);
        }

        if (! $response->successful()) {
            return $this->storageWriteFailure($operation, $response);
        }

        $body = is_array($response->json()) ? $response->json() : [];

        return NextcloudResult::ok($body, $response->status());
    }

    /** Met à jour un montage global existant (reconnu par sa signature canonique). */
    public function updateGlobalStorage(int|string $id, ExternalStorageDefinition $definition): NextcloudResult
    {
        $operation = sprintf('mise à jour du montage « %s »', $definition->label());
        $payload = $definition->toPayload() + ['id' => $id];

        try {
            $response = $this->adminRequest('PUT', self::GLOBAL_STORAGES_PATH . '/' . $id, $payload);
        } catch (ConnectionException $e) {
            return $this->unreachable($operation, $e);
        }

        if (! $response->successful()) {
            return $this->storageWriteFailure($operation, $response);
        }

        $body = is_array($response->json()) ? $response->json() : [];

        return NextcloudResult::ok($body, $response->status());
    }

    /**
     * Supprime un montage global.
     *
     * ⚠️ **Le provisionnement de SE5 n'appelle JAMAIS cette méthode**, et un test
     * l'épingle. Elle existe pour l'unique obligation du test d'intégration :
     * laisser l'instance de sondage dans l'état où il l'a trouvée. Supprimer un
     * montage depuis le provisionnement contredirait la doctrine drift STRICT —
     * SE5 ne gouverne que ce qu'il a déclaré, et ne retire rien qu'il n'ait posé
     * dans le geste courant.
     */
    public function deleteGlobalStorage(int|string $id): NextcloudResult
    {
        $operation = sprintf('suppression du montage #%s', (string) $id);

        try {
            $response = $this->adminRequest('DELETE', self::GLOBAL_STORAGES_PATH . '/' . $id);
        } catch (ConnectionException $e) {
            return $this->unreachable($operation, $e);
        }

        return $response->successful()
            ? NextcloudResult::ok([], $response->status())
            : $this->httpFailure($operation, $response);
    }

    // =========================================================================
    // AC5 / AC6 / AC7 — les comptes
    // =========================================================================

    /**
     * Crée un compte Nextcloud. Le statuscode OCS `102` (« l'utilisateur existe
     * déjà ») est rendu comme **déjà conforme** — c'est l'adoption, pas une erreur.
     *
     * L'identifiant du compte EST le login SE5 : c'est nous qui l'envoyons, donc
     * c'est nous qui le connaissons — première étape de la résolution d'identité
     * (AC6), et la seule qui ne coûte aucun appel supplémentaire.
     */
    public function createUser(string $login, string $password): NextcloudResult
    {
        return $this->ocsCall(
            'POST',
            'ocs/v1.php/cloud/users',
            sprintf('création du compte Nextcloud « %s »', $login),
            ['userid' => $login, 'password' => $password],
            sprintf('Le compte Nextcloud « %s » existe déjà : adopté en l\'état.', $login),
        );
    }

    /** Sonde directe d'un compte par son identifiant. */
    public function getUser(string $login): NextcloudResult
    {
        return $this->ocsCall(
            'GET',
            'ocs/v1.php/cloud/users/' . rawurlencode($login),
            sprintf('lecture du compte Nextcloud « %s »', $login),
        );
    }

    /**
     * Met à jour le mot de passe d'un compte Nextcloud (patron legacy
     * `cloud.inc.php:894` — mise à jour clé/valeur en `PUT`).
     *
     * Sans elle, sur une instance SANS synchro LDAP, le premier changement de mot
     * de passe SE5 casse le montage : le mécanisme « identifiants de session »
     * exige que Nextcloud accepte les identifiants AD. C'est le pont iso-legacy ;
     * le successeur propre est l'OIDC, hors périmètre et nommé.
     */
    public function setUserPassword(string $nextcloudUserId, string $newPassword): NextcloudResult
    {
        return $this->ocsCall(
            'PUT',
            'ocs/v2.php/cloud/users/' . rawurlencode($nextcloudUserId),
            sprintf('mise à jour du mot de passe du compte Nextcloud « %s »', $nextcloudUserId),
            ['key' => 'password', 'value' => $newPassword],
        );
    }

    /**
     * Résolution d'identité par autocomplétion (précédent SE4 `cloud.inc.php:989`).
     *
     * **L'absence est SILENCIEUSE côté API** — mesure du spike 60.0 : un login
     * inconnu rend zéro résultat, pas une erreur. Elle ne doit donc jamais rester
     * silencieuse côté SE5 : l'appelant compte l'introuvable.
     *
     * @return NextcloudResult `data['matches']` = liste `[['id' => …, 'source' => …], …]`
     */
    public function autocompleteUser(string $search): NextcloudResult
    {
        $result = $this->ocsCall(
            'GET',
            'ocs/v2.php/core/autocomplete/get',
            sprintf('résolution de l\'identité Nextcloud de « %s »', $search),
            ['search' => $search, 'itemType' => ' ', 'itemId' => ' ', 'shareTypes' => [0]],
        );

        if ($result->isFailure()) {
            return $result;
        }

        $matches = [];
        foreach ($result->data as $entry) {
            if (is_array($entry) && ($entry['source'] ?? null) === 'users' && is_string($entry['id'] ?? null)) {
                $matches[] = ['id' => $entry['id'], 'source' => 'users'];
            }
        }

        return NextcloudResult::ok(['matches' => $matches], $result->httpStatus, $result->ocsStatusCode);
    }

    // =========================================================================
    // Interne
    // =========================================================================

    /**
     * Un appel OCS, de bout en bout : émission, traduction des échecs de
     * transport, traduction des sémantiques OCS.
     *
     * @param  array<string, mixed>  $payload
     */
    private function ocsCall(
        string $method,
        string $path,
        string $operation,
        array $payload = [],
        ?string $conformingMessage = null,
    ): NextcloudResult {
        try {
            $response = $this->ocsRequest($method, $path, $payload);
        } catch (ConnectionException $e) {
            return $this->unreachable($operation, $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return $this->privilegeFailure($operation, $response->status());
        }

        $body = $response->json();
        $meta = is_array($body) ? ($body['ocs']['meta'] ?? null) : null;

        if (! is_array($meta) || ! isset($meta['statuscode'])) {
            if (! $response->successful()) {
                return $this->httpFailure($operation, $response);
            }

            return NextcloudResult::failed(
                NextcloudFailure::Illisible,
                sprintf('%s : réponse OCS illisible (enveloppe absente).', ucfirst($operation)),
                $response->status(),
            );
        }

        $ocsCode = (int) $meta['statuscode'];
        $ocsMessage = is_string($meta['message'] ?? null) ? $meta['message'] : '';
        $data = is_array($body['ocs']['data'] ?? null) ? $body['ocs']['data'] : [];

        if ($ocsCode === self::OCS_ALREADY_EXISTS) {
            return NextcloudResult::conforming(
                $conformingMessage ?? sprintf('%s : déjà conforme.', ucfirst($operation)),
                $data,
                $response->status(),
                $ocsCode,
            );
        }

        if (in_array($ocsCode, self::OCS_SUCCESS, true)) {
            return NextcloudResult::ok($data, $response->status(), $ocsCode);
        }

        if ($ocsCode === 401 || $ocsCode === 403 || $ocsCode === 997) {
            return $this->privilegeFailure($operation, $response->status(), $ocsCode);
        }

        if ($ocsCode === 404 || $ocsCode === 998) {
            return NextcloudResult::failed(
                NextcloudFailure::Absent,
                sprintf('%s : la cible n\'existe pas côté Nextcloud%s.', ucfirst($operation), $this->suffix($ocsMessage)),
                $response->status(),
                $ocsCode,
            );
        }

        return NextcloudResult::failed(
            NextcloudFailure::Refus,
            sprintf('%s : refusée par l\'instance%s.', ucfirst($operation), $this->suffix($ocsMessage)),
            $response->status(),
            $ocsCode,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ocsRequest(string $method, string $path, array $payload = []): Response
    {
        $request = $this->pending();
        $method = strtoupper($method);

        // `format=json` : sans lui l'instance répond du XML, que le legacy parsait
        // à la main. On demande du JSON, que le client HTTP décode seul.
        //
        // La chaîne de requête est construite ICI, jamais passée en second
        // argument du verbe : le client HTTP ÉCRASE la chaîne existante quand on
        // lui passe un tableau de requête — `format=json` disparaîtrait en
        // silence, et l'instance répondrait du XML que ce code ne sait pas lire.
        $query = ['format' => 'json'] + ($method === 'GET' ? $payload : []);
        $url = $this->config->url($path) . '?' . http_build_query($query);

        return match ($method) {
            'GET' => $request->get($url),
            'POST' => $request->asForm()->post($url, $payload),
            'PUT' => $request->asForm()->put($url, $payload),
            default => $request->send($method, $url, ['form_params' => $payload]),
        };
    }

    /**
     * Appel à l'endpoint d'ADMINISTRATION (route `index.php`).
     *
     * **Corps en `application/x-www-form-urlencoded`**, mesuré le 2026-08-08 sur
     * l'instance de sondage : c'est la forme que l'endpoint accepte
     * (`backendOptions[host]=…`). La réponse, elle, est du JSON.
     *
     * @param  array<string, mixed>  $payload
     */
    private function adminRequest(string $method, string $path, array $payload = []): Response
    {
        $request = $this->pending();
        $method = strtoupper($method);
        $url = $this->config->url($path);

        return match ($method) {
            'GET' => $request->get($url),
            'DELETE' => $request->delete($url),
            'POST' => $request->asForm()->post($url, $payload),
            'PUT' => $request->asForm()->put($url, $payload),
            default => $request->send($method, $url, ['form_params' => $payload]),
        };
    }

    /**
     * La requête de base, commune à TOUS les appels : auth basic admin, en-tête
     * OCS, vérification TLS conforme au réglage, délai borné.
     *
     * **Aucun retry.** Un refus de privilège rejoué reste un refus, et un retry
     * silencieux masquerait la cause qu'on veut nommer (AC9). Le client HTTP du
     * framework ne lève pas non plus sur 4xx/5xx par défaut : les codes sont
     * TRADUITS ici, en résultats typés, pas convertis en exceptions.
     */
    private function pending(): PendingRequest
    {
        return Http::withBasicAuth($this->config->adminUser, $this->config->adminPassword())
            ->withHeaders([
                // Exigé par le protocole OCS ; sert aussi à Nextcloud pour ne pas
                // réclamer d'état de session sur les routes d'API.
                'OCS-APIRequest' => 'true',
                'Accept' => 'application/json',
            ])
            ->withOptions(['verify' => $this->config->verifyTls])
            ->timeout(self::TIMEOUT_SECONDS);
    }

    private function unreachable(string $operation, ConnectionException $e): NextcloudResult
    {
        return NextcloudResult::failed(
            NextcloudFailure::Injoignable,
            sprintf('%s : instance injoignable (%s).', ucfirst($operation), $this->shortReason($e)),
        );
    }

    private function privilegeFailure(string $operation, ?int $httpStatus, ?int $ocsCode = null): NextcloudResult
    {
        return NextcloudResult::failed(
            NextcloudFailure::Privilege,
            sprintf(
                '%s : refusée — le compte « %s » n\'a pas le privilège requis (administrateur de l\'instance).',
                ucfirst($operation),
                $this->config->adminUser,
            ),
            $httpStatus,
            $ocsCode,
        );
    }

    /**
     * Échec d'une ÉCRITURE de montage — avec le quatrième diagnostic (AC1/AC9).
     *
     * **Mesuré sur `nc-spike` le 2026-08-08** : quand l'hôte de l'instance n'a ni
     * le binaire `smbclient` ni l'extension `php-smbclient`, l'endpoint répond
     * `422 {"message":"Invalid storage backend \"smb\""}`. Le rendre en « refus
     * générique » enverrait chercher du côté du compte ou de l'app, qui sont tous
     * deux corrects — la correction est un paquet à installer sur l'instance,
     * **suivi d'un redémarrage du service** (la détection des backends est mise en
     * cache : le paquet seul ne suffit pas, ce qui en fait le piège le plus
     * coûteux du lot).
     */
    private function storageWriteFailure(string $operation, Response $response): NextcloudResult
    {
        $body = $response->json();
        $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : '';

        if ($response->status() === 422 && str_contains(mb_strtolower($message), 'invalid storage backend')) {
            return NextcloudResult::failed(
                NextcloudFailure::BackendIndisponible,
                sprintf(
                    '%s : le backend SMB n\'est pas disponible sur l\'instance. Installez « php-smbclient » '
                    . 'ou le binaire « smbclient » sur l\'hôte Nextcloud, PUIS redémarrez le service — la '
                    . 'détection des backends est mise en cache, l\'installation seule ne suffit pas.',
                    ucfirst($operation),
                ),
                422,
            );
        }

        return $this->httpFailure($operation, $response);
    }

    private function httpFailure(string $operation, Response $response): NextcloudResult
    {
        $status = $response->status();

        $failure = match (true) {
            $status === 401, $status === 403 => NextcloudFailure::Privilege,
            $status === 404 => NextcloudFailure::Absent,
            default => NextcloudFailure::Refus,
        };

        if ($failure === NextcloudFailure::Privilege) {
            return $this->privilegeFailure($operation, $status);
        }

        return NextcloudResult::failed(
            $failure,
            sprintf('%s : refusée par l\'instance (HTTP %d).', ucfirst($operation), $status),
            $status,
        );
    }

    /**
     * Raison COURTE d'un échec de transport. On ne recopie pas le message complet
     * du client HTTP : il contient l'URL complète, parfois avec les identifiants
     * quand quelqu'un les a mis dans l'URL. On garde la classe et rien d'autre.
     */
    private function shortReason(ConnectionException $e): string
    {
        $message = $e->getMessage();

        return $message === ''
            ? 'échec de connexion'
            : (string) preg_replace('/\s+/', ' ', mb_substr($message, 0, 120));
    }

    private function suffix(string $message): string
    {
        return $message === '' ? '' : ' — ' . $message;
    }
}
