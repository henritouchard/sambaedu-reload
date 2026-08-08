<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Nextcloud;

use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudFailure;
use App\Services\Nextcloud\NextcloudResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Story 61.3 — LE CANAL DES DOSSIERS D'ÉQUIPE ET DES GROUPES, à surface FERMÉE.
 *
 * Deux familles d'appels, et une seule règle de lecture.
 *
 *  - **Les dossiers d'équipe** — création, carte des groupes et de leurs
 *    permissions, activation des permissions avancées, plafond. Corps en
 *    formulaire, jamais en JSON (mesuré) ; en-tête d'API posé ; authentification
 *    basique ; aucune protection anti-CSRF sur ces routes.
 *  - **Les groupes de l'instance** — création (le statut « existe déjà » est une
 *    idempotence, pas une erreur) et appartenance. Ce backend est le PREMIER
 *    écrivain légitime de ce canal dans le dépôt : la garde d'architecture qui
 *    l'interdisait ailleurs n'a pas été retirée, elle a été RE-PÉRIMÉTRÉE.
 *
 * ---------------------------------------------------------------------------
 * **LE CODE HTTP MENT, ET C'EST MESURÉ.** Une opération d'administration refusée
 * peut rendre un `200` avec un refus dans le CORPS, et ne rien faire. Toute
 * réponse passe donc par {@see interpret()}, qui lit l'enveloppe applicative quand
 * elle est là et le code de transport sinon. Croire le code de transport, ici,
 * c'est rapporter « appliqué » sur un geste qui n'a pas eu lieu.
 *
 * **Ce que ce client N'A PAS.** Aucune méthode de partage : les octrois de ce
 * backend passent par les dossiers d'équipe et leurs règles, jamais par le
 * mécanisme de partage — dont le sondage d'ouverture d'epic a mesuré qu'il ment
 * (retrait accepté sans effet). Aucune méthode de quota d'UTILISATEUR non plus :
 * budgéter une personne est l'affaire du provisionnement des comptes, pas d'une
 * recette de partage (frontière D8, épinglée par test des deux côtés).
 *
 * **{@see deleteFolder()} n'est appelée par AUCUN chemin de production**, et un
 * test l'épingle. Elle existe pour la seule obligation du test d'intégration :
 * laisser l'instance dans l'état où il l'a trouvée. Détruire un dossier d'équipe
 * depuis une réconciliation contredirait la doctrine (aucune suppression implicite,
 * D9) — la révocation retire les octrois, elle ne détruit pas les données.
 */
final class NextcloudTeamFolderClient
{
    private const TIMEOUT_SECONDS = 20;

    /** Racine des routes de dossiers d'équipe. */
    private const FOLDERS_PATH = 'index.php/apps/groupfolders/folders';

    /** Statut applicatif « l'objet existe déjà » — mesuré au spike 60.0. */
    private const ALREADY_EXISTS = 102;

    /** @var list<int> statuts applicatifs de succès (les deux versions du protocole) */
    private const SUCCESS = [100, 200];

    public function __construct(private readonly NextcloudConnectionConfig $config)
    {
    }

    // =========================================================================
    // Dossiers d'équipe
    // =========================================================================

    /**
     * L'inventaire des dossiers d'équipe, tel que l'instance le REND.
     *
     * @return NextcloudResult `data['folders']` = liste brute normalisée
     */
    public function listFolders(): NextcloudResult
    {
        $result = $this->call('GET', self::FOLDERS_PATH, [], 'lecture des dossiers d\'équipe');

        if ($result->isFailure()) {
            return $result;
        }

        // Selon la version, l'instance rend une liste ou une table indexée par
        // identifiant. On normalise plutôt que d'en présumer une.
        $folders = array_values(array_filter($result->data, 'is_array'));

        return NextcloudResult::ok(['folders' => $folders], $result->httpStatus, $result->ocsStatusCode);
    }

    /**
     * Crée un dossier d'équipe au point de montage demandé.
     *
     * **La reconnaissance se fait sur la valeur RELUE, jamais sur l'envoyée** : le
     * point de montage revient parfois augmenté d'une barre oblique de tête. C'est
     * l'appelant qui adopte, avec {@see folderIdFor()}.
     */
    public function createFolder(string $mountPoint): NextcloudResult
    {
        return $this->call(
            'POST',
            self::FOLDERS_PATH,
            ['mountpoint' => $mountPoint],
            sprintf('création du dossier d\'équipe « %s »', $mountPoint),
        );
    }

    /** Ajoute un groupe à la carte d'un dossier d'équipe (permissions par défaut). */
    public function addGroup(int $folderId, string $groupId): NextcloudResult
    {
        return $this->call(
            'POST',
            self::FOLDERS_PATH . '/' . $folderId . '/groups',
            ['group' => $groupId],
            sprintf('ajout du groupe « %s » au dossier d\'équipe', $groupId),
        );
    }

    /** Fixe les permissions d'un groupe sur un dossier d'équipe. */
    public function setGroupPermissions(int $folderId, string $groupId, int $permissions): NextcloudResult
    {
        return $this->call(
            'POST',
            self::FOLDERS_PATH . '/' . $folderId . '/groups/' . rawurlencode($groupId),
            ['permissions' => $permissions],
            sprintf('permissions du groupe « %s » sur le dossier d\'équipe', $groupId),
        );
    }

    /** Retire un groupe de la carte d'un dossier d'équipe (révocation, sans destruction). */
    public function removeGroup(int $folderId, string $groupId): NextcloudResult
    {
        return $this->call(
            'DELETE',
            self::FOLDERS_PATH . '/' . $folderId . '/groups/' . rawurlencode($groupId),
            [],
            sprintf('retrait du groupe « %s » du dossier d\'équipe', $groupId),
        );
    }

    /**
     * L'INTERRUPTEUR des permissions avancées.
     *
     * Ce n'est PAS la pose d'une règle — c'est la seule chose que cette route sache
     * faire, et lui passer quoi que ce soit d'autre rend une erreur de requête.
     * Sans cet interrupteur, les règles posées par l'autre canal n'ont AUCUN EFFET :
     * elles sont acceptées et ignorées. Le prérequis est écrit au runbook.
     */
    public function enableAdvancedPermissions(int $folderId): NextcloudResult
    {
        return $this->call(
            'POST',
            self::FOLDERS_PATH . '/' . $folderId . '/acl',
            ['acl' => 1],
            'activation des permissions avancées du dossier d\'équipe',
        );
    }

    /** Fixe le plafond d'un dossier d'équipe, en octets. */
    public function setQuota(int $folderId, int $bytes): NextcloudResult
    {
        return $this->call(
            'POST',
            self::FOLDERS_PATH . '/' . $folderId . '/quota',
            ['quota' => $bytes],
            'plafond du dossier d\'équipe',
        );
    }

    /**
     * ⚠️ **AUCUN CHEMIN DE PRODUCTION N'APPELLE CECI** (D9, épinglé par test).
     * Réservée au nettoyage du test d'intégration.
     */
    public function deleteFolder(int $folderId): NextcloudResult
    {
        return $this->call(
            'DELETE',
            self::FOLDERS_PATH . '/' . $folderId,
            [],
            'suppression du dossier d\'équipe',
        );
    }

    // =========================================================================
    // Groupes de l'instance
    // =========================================================================

    /** Crée un groupe. « Existe déjà » est une idempotence : le résultat est conforme. */
    public function ensureGroup(string $groupId): NextcloudResult
    {
        return $this->call(
            'POST',
            'ocs/v1.php/cloud/groups',
            ['groupid' => $groupId],
            sprintf('création du groupe « %s »', $groupId),
            sprintf('Le groupe « %s » existe déjà : adopté en l\'état.', $groupId),
        );
    }

    /**
     * Les identifiants de comptes membres d'un groupe.
     *
     * @return NextcloudResult `data['members']` = `list<string>`
     */
    public function groupMembers(string $groupId): NextcloudResult
    {
        $result = $this->call(
            'GET',
            'ocs/v1.php/cloud/groups/' . rawurlencode($groupId),
            [],
            sprintf('lecture des membres du groupe « %s »', $groupId),
        );

        if ($result->isFailure()) {
            return $result;
        }

        $users = $result->data['users'] ?? $result->data;
        $members = [];
        foreach (is_array($users) ? $users : [] as $user) {
            if (is_string($user) && $user !== '') {
                $members[] = $user;
            }
        }
        sort($members, SORT_STRING);

        return NextcloudResult::ok(['members' => $members], $result->httpStatus, $result->ocsStatusCode);
    }

    public function addUserToGroup(string $nextcloudUserId, string $groupId): NextcloudResult
    {
        return $this->call(
            'POST',
            'ocs/v1.php/cloud/users/' . rawurlencode($nextcloudUserId) . '/groups',
            ['groupid' => $groupId],
            sprintf('ajout d\'un compte au groupe « %s »', $groupId),
            sprintf('Le compte est déjà membre du groupe « %s ».', $groupId),
        );
    }

    public function removeUserFromGroup(string $nextcloudUserId, string $groupId): NextcloudResult
    {
        return $this->call(
            'DELETE',
            'ocs/v1.php/cloud/users/' . rawurlencode($nextcloudUserId) . '/groups',
            ['groupid' => $groupId],
            sprintf('retrait d\'un compte du groupe « %s »', $groupId),
        );
    }

    // =========================================================================
    // Interne
    // =========================================================================

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(
        string $method,
        string $path,
        array $payload,
        string $operation,
        ?string $conformingMessage = null,
    ): NextcloudResult {
        try {
            $response = $this->send($method, $path, $payload);
        } catch (ConnectionException $e) {
            return NextcloudResult::failed(
                NextcloudFailure::Injoignable,
                sprintf('%s : instance injoignable (%s).', ucfirst($operation), $this->shortReason($e)),
            );
        }

        return $this->interpret($response, $operation, $conformingMessage);
    }

    /**
     * **LA SEULE LECTURE DE RÉPONSE DE CE CLIENT.**
     *
     * L'enveloppe applicative fait foi quand elle est là — c'est elle qui porte le
     * refus mesuré derrière un `200`. Le code de transport ne sert que lorsque la
     * réponse n'en a pas.
     */
    private function interpret(Response $response, string $operation, ?string $conformingMessage): NextcloudResult
    {
        $body = $response->json();
        $meta = is_array($body) ? ($body['ocs']['meta'] ?? null) : null;

        if (is_array($meta) && isset($meta['statuscode'])) {
            $code = (int) $meta['statuscode'];
            $message = is_string($meta['message'] ?? null) ? $meta['message'] : '';
            $data = is_array($body['ocs']['data'] ?? null) ? $body['ocs']['data'] : [];

            if ($code === self::ALREADY_EXISTS) {
                return NextcloudResult::conforming(
                    $conformingMessage ?? sprintf('%s : déjà conforme.', ucfirst($operation)),
                    $data,
                    $response->status(),
                    $code,
                );
            }

            if (in_array($code, self::SUCCESS, true)) {
                return NextcloudResult::ok($data, $response->status(), $code);
            }

            if ($code === 401 || $code === 403 || $code === 997) {
                return NextcloudResult::failed(
                    NextcloudFailure::Privilege,
                    sprintf(
                        '%s : refusée — le compte « %s » n\'a pas le privilège requis (administrateur de '
                        . 'l\'instance)%s.',
                        ucfirst($operation),
                        $this->config->adminUser,
                        $message === '' ? '' : ' — ' . $message,
                    ),
                    $response->status(),
                    $code,
                );
            }

            if ($code === 404 || $code === 998) {
                return NextcloudResult::failed(
                    NextcloudFailure::Absent,
                    sprintf(
                        '%s : la cible n\'existe pas côté instance%s.',
                        ucfirst($operation),
                        $message === '' ? '' : ' — ' . $message,
                    ),
                    $response->status(),
                    $code,
                );
            }

            return NextcloudResult::failed(
                NextcloudFailure::Refus,
                sprintf(
                    '%s : refusée par l\'instance%s.',
                    ucfirst($operation),
                    $message === '' ? '' : ' — ' . $message,
                ),
                $response->status(),
                $code,
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return NextcloudResult::failed(
                NextcloudFailure::Privilege,
                sprintf(
                    '%s : refusée — le compte « %s » n\'a pas le privilège requis (administrateur de l\'instance).',
                    ucfirst($operation),
                    $this->config->adminUser,
                ),
                $response->status(),
            );
        }

        if (! $response->successful()) {
            return NextcloudResult::failed(
                $response->status() === 404 ? NextcloudFailure::Absent : NextcloudFailure::Refus,
                sprintf('%s : refusée par l\'instance (HTTP %d).', ucfirst($operation), $response->status()),
                $response->status(),
            );
        }

        // Réponse JSON nue (les routes de dossiers d'équipe en rendent) : la donnée
        // est le corps lui-même.
        return NextcloudResult::ok(is_array($body) ? $body : [], $response->status());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $method, string $path, array $payload): Response
    {
        $request = $this->pending();
        $method = strtoupper($method);

        // `format=json` posé dans la chaîne de requête, jamais en second argument
        // du verbe : le client HTTP écraserait la chaîne existante et l'instance
        // répondrait du XML que ce code ne sait pas lire (piège 61.1).
        $query = ['format' => 'json'] + ($method === 'GET' ? $payload : []);
        $url = $this->config->url($path) . '?' . http_build_query($query);

        return match ($method) {
            'GET' => $request->get($url),
            // Corps en FORMULAIRE, mesuré. Jamais de JSON, jamais de booléen dans
            // un corps de formulaire (piège mesuré en 61.1).
            'POST' => $request->asForm()->post($url, $payload),
            'PUT' => $request->asForm()->put($url, $payload),
            default => $request->send($method, $url, $payload === [] ? [] : ['form_params' => $payload]),
        };
    }

    private function pending(): PendingRequest
    {
        return Http::withBasicAuth($this->config->adminUser, $this->config->adminPassword())
            ->withHeaders([
                'OCS-APIRequest' => 'true',
                'Accept' => 'application/json',
            ])
            ->withOptions(['verify' => $this->config->verifyTls])
            ->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * Le point de montage RELU d'une entrée d'inventaire, normalisé.
     *
     * L'instance rend parfois une barre oblique de tête que personne n'a écrite —
     * deuxième occurrence du piège « le relu n'est pas l'envoyé » dans cet epic. On
     * normalise ICI, à la source, pour que personne n'ait à s'en souvenir.
     *
     * @param  array<string, mixed>  $folder
     */
    public static function mountPointOf(array $folder): string
    {
        $raw = $folder['mount_point'] ?? $folder['mountpoint'] ?? '';

        return is_string($raw) ? trim(trim($raw), '/') : '';
    }

    private function shortReason(ConnectionException $e): string
    {
        $message = trim($e->getMessage());

        return $message === ''
            ? 'échec de connexion'
            : (string) preg_replace('/\s+/', ' ', mb_substr($message, 0, 120));
    }
}
