<?php

declare(strict_types=1);

namespace Tests\Feature\Filesystem\Backend\OpenCloud;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * UNE INSTANCE OPENCLOUD EN MÉMOIRE, QUI REJOUE LES CORPS **MESURÉS**.
 *
 * ---------------------------------------------------------------------------
 * **CE DOUBLE NE DIT RIEN QU'UNE INSTANCE RÉELLE N'AIT DIT.**
 *
 * C'est le legs le plus cher du chantier voisin : *un double bâti sur les
 * intentions du code se valide lui-même*. Chaque forme rendue ici — la structure
 * des corps, les codes, et surtout les sémantiques CONTRE-INTUITIVES — vient du
 * relevé du 2026-08-13 consigné dans le dossier de la story, et non de ce que le
 * backend espère recevoir :
 *
 *  - **deux créations d'espace du même nom rendent DEUX `201`** et produisent deux
 *    espaces distincts : il n'y a aucune idempotence native (M9a) ;
 *  - **un groupe, un compte ou un octroi déjà existant rend `409
 *    nameAlreadyExists`** — un code d'échec qui enveloppe un état CONFORME ;
 *  - **une invitation rejouée avec un AUTRE rôle rend `409` aussi**, sans rien
 *    changer : la modification passe obligatoirement par `PATCH` ;
 *  - **la création d'un dossier déjà présent rend `405`**, et `409` quand le parent
 *    manque ;
 *  - **le retrait d'un octroi absent rend `404 itemNotFound`** ;
 *  - **la relecture AJOUTE des champs** (`createdDateTime`, `invitation.invitedBy`,
 *    le `displayName` du principal) que l'égalité doit ignorer ;
 *  - **les octrois d'un descendant ne nomment PAS l'accès hérité de la racine** :
 *    leur liste est vide, et c'est ce qui rend la propagation invisible ;
 *  - **les deux versions d'API cohabitent** : les espaces en `v1.0`, les octrois
 *    d'items en `v1beta1`. Une route demandée sur la mauvaise version rend
 *    `404 page not found`, exactement comme l'instance réelle.
 * ---------------------------------------------------------------------------
 *
 * Il COMPTE aussi les écritures, parce que « second passage, zéro écriture » ne
 * se prouve pas autrement qu'en les comptant.
 */
final class FakeOpenCloudInstance
{
    public const STORAGE = '39cc9ee5-825f-43b7-99da-f40d57b41adc';

    public string $baseUrl = 'https://nuage.exemple.fr';

    /** @var array<string, array{id:string,name:string,description:string,quota:?int}> */
    public array $spaces = [];

    /** @var array<string, array<string, string>> espace => (chemin relatif => identifiant d'item) */
    public array $items = [];

    /** @var array<string, array<string, array{id:string,type:string,principal:string,roles:list<string>,actions:list<string>}>> */
    public array $permissions = [];

    /** @var array<string, array{id:string,displayName:string,members:list<string>}> */
    public array $groups = [];

    /** @var array<string, array{id:string,login:string}> */
    public array $users = [];

    /** Chaque écriture réellement émise, dans l'ordre : `MÉTHODE chemin`. @var list<string> */
    public array $writes = [];

    /** Le compte d'administration est-il authentifié ? (sonde) */
    public bool $authenticated = true;

    /** Le compte d'administration est-il administrateur ? (sonde) */
    public bool $administrator = true;

    /**
     * PANNES INJECTÉES — **et elles ne prétendent RIEN de l'API.**
     *
     * Tout le reste de ce double rejoue des corps MESURÉS. Ceci n'en est pas un :
     * c'est une panne de TRANSPORT (l'instance ne répond pas à une requête), et
     * elle n'existe que pour éprouver le comportement FAIL-CLOSED du backend
     * quand une lecture échoue — le cas où un `conforme` serait un mensonge et
     * où un fail-OPEN sur une révocation serait le pire des deux sens. Aucun
     * verdict de ce fichier ne s'appuie sur la FORME de cette réponse.
     *
     * @var list<array{method:string,needle:string,status:int}>
     */
    public array $breakdowns = [];

    private int $sequence = 0;

    // =========================================================================
    // Décor
    // =========================================================================

    public function withUser(string $login): string
    {
        $id = $this->uuid('user');
        $this->users[$id] = ['id' => $id, 'login' => $login];

        return $id;
    }

    public function withGroup(string $displayName): string
    {
        $id = $this->uuid('group');
        $this->groups[$id] = ['id' => $id, 'displayName' => $displayName, 'members' => []];

        return $id;
    }

    /** @return string identifiant de l'espace */
    public function withSpace(string $name, string $description = 'Répertoire géré par SambaEdu.'): string
    {
        $id = self::STORAGE . '$' . $this->uuid('space');
        $this->spaces[$id] = ['id' => $id, 'name' => $name, 'description' => $description, 'quota' => null];
        $this->items[$id] = [];
        $this->permissions[$id . '|root'] = [];

        return $id;
    }

    public function withFolder(string $spaceId, string $path): string
    {
        $itemId = $spaceId . '!' . $this->uuid('item');
        $this->items[$spaceId][$path] = $itemId;
        $this->permissions[$spaceId . '|' . $itemId] = [];

        return $itemId;
    }

    /** Pose un octroi comme le ferait un administrateur à la main. */
    public function withPermission(string $spaceId, ?string $itemId, string $type, string $principalId, string $roleId): string
    {
        $bucket = $spaceId . '|' . ($itemId ?? 'root');
        $permissionId = self::STORAGE . ':' . $this->uuid('perm') . ':' . $this->uuid('grant');
        $this->permissions[$bucket][$permissionId] = [
            'id' => $permissionId,
            'type' => $type,
            'principal' => $principalId,
            'roles' => [$roleId],
            'actions' => [],
        ];

        return $permissionId;
    }

    /** Fait échouer le TRANSPORT sur les requêtes dont le chemin porte cette aiguille. */
    public function breakOn(string $method, string $needle, int $status = 503): void
    {
        $this->breakdowns[] = ['method' => strtoupper($method), 'needle' => $needle, 'status' => $status];
    }

    /** Les écritures émises depuis le dernier appel — pour compter un second passage. */
    public function resetWrites(): void
    {
        $this->writes = [];
    }

    /** L'identifiant d'un groupe par son nom d'affichage, ou `null`. */
    public function groupIdOf(string $displayName): ?string
    {
        foreach ($this->groups as $id => $group) {
            if ($group['displayName'] === $displayName) {
                return $id;
            }
        }

        return null;
    }

    /** Les octrois relus sur un nœud, sous la forme `principal => rôle`. @return array<string,string> */
    public function grantsOn(string $spaceId, ?string $itemId): array
    {
        $out = [];
        foreach ($this->permissions[$spaceId . '|' . ($itemId ?? 'root')] ?? [] as $permission) {
            $out[$permission['principal']] = $permission['roles'][0] ?? '';
        }

        return $out;
    }

    // =========================================================================
    // Le routeur
    // =========================================================================

    public function install(): void
    {
        Http::fake(function (Request $request) {
            $method = strtoupper($request->method());
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $body = $request->data();

            if ($method !== 'GET') {
                $this->writes[] = $method . ' ' . $path;
            }

            return $this->route($method, $path, is_array($body) ? $body : []);
        });
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function route(string $method, string $path, array $body): mixed
    {
        if (! $this->authenticated) {
            return Http::response('', 401);
        }

        foreach ($this->breakdowns as $breakdown) {
            if ($breakdown['method'] === $method && str_contains($path, $breakdown['needle'])) {
                return Http::response('', $breakdown['status']);
            }
        }

        // --- La sonde -------------------------------------------------------
        if ($path === '/graph/v1.0/me') {
            return Http::response([
                'displayName' => 'Admin',
                'id' => 'b870d0d2-1077-4dcf-b961-cc43caa32a0f',
                'mail' => 'admin@example.org',
                'onPremisesSamAccountName' => 'admin',
                'userType' => 'Member',
            ], 200);
        }

        // --- Les dossiers, par le protocole d'édition distante ---------------
        if (str_starts_with($path, '/dav/spaces/')) {
            return $this->routeDav($method, substr($path, strlen('/dav/spaces/')));
        }


        // --- L'annuaire -----------------------------------------------------
        if (str_starts_with($path, '/graph/v1.0/groups')) {
            return $this->routeGroups($method, substr($path, strlen('/graph/v1.0/groups')), $body);
        }

        if ($path === '/graph/v1.0/users') {
            if (! $this->administrator) {
                return $this->error(403, 'accessDenied', 'insufficient permissions');
            }

            return Http::response(['value' => array_values(array_map(
                static fn (array $u): array => [
                    'accountEnabled' => true,
                    'displayName' => $u['login'],
                    'id' => $u['id'],
                    'onPremisesSamAccountName' => $u['login'],
                    'userType' => 'Member',
                ],
                $this->users,
            ))], 200);
        }

        // --- Les espaces (v1.0) et les octrois (v1beta1) ---------------------
        if (str_starts_with($path, '/graph/v1.0/drives')) {
            return $this->routeSpaces($method, substr($path, strlen('/graph/v1.0/drives')), $body);
        }

        if (str_starts_with($path, '/graph/v1beta1/drives')) {
            return $this->routePermissions($method, substr($path, strlen('/graph/v1beta1/drives')), $body);
        }

        // Exactement ce que l'instance rend sur une route inconnue — y compris
        // sur la BONNE route demandée à la MAUVAISE version d'API.
        return Http::response('404 page not found', 404);
    }

    /**
     * La création d'un dossier — le SEUL geste hors de l'API Graph, et le seul
     * dont les codes ne veulent pas dire ce qu'ils ont l'air de dire.
     */
    private function routeDav(string $method, string $rest): mixed
    {
        if ($method !== 'MKCOL') {
            return Http::response('', 405);
        }

        $rest = trim($rest, '/');
        $slash = strpos($rest, '/');
        if ($slash === false) {
            return Http::response('', 409);
        }

        $spaceId = urldecode(substr($rest, 0, $slash));
        $path = trim(urldecode(substr($rest, $slash + 1)), '/');

        if (! isset($this->spaces[$spaceId]) || $path === '') {
            return Http::response('', 409);
        }

        // MESURÉ : le dossier existe déjà ⇒ `405`, et ce `405` est un ÉTAT.
        if (isset($this->items[$spaceId][$path])) {
            return Http::response('', 405);
        }

        // MESURÉ : le parent manque ⇒ `409`, et c'est ce qui impose l'ordre de
        // création parents d'abord.
        $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
        if ($parent !== '' && ! isset($this->items[$spaceId][$parent])) {
            return Http::response('', 409);
        }

        $this->withFolder($spaceId, $path);

        return Http::response('', 201);
    }

    /** @param array<string,mixed> $body */
    private function routeGroups(string $method, string $rest, array $body): mixed
    {
        if (! $this->administrator) {
            return $this->error(403, 'accessDenied', 'insufficient permissions');
        }

        $rest = trim($rest, '/');

        if ($rest === '') {
            if ($method === 'GET') {
                return Http::response(['value' => array_values(array_map(
                    static fn (array $g): array => [
                        'displayName' => $g['displayName'],
                        'groupTypes' => [],
                        'id' => $g['id'],
                    ],
                    $this->groups,
                ))], 200);
            }

            $name = (string) ($body['displayName'] ?? '');
            if ($this->groupIdOf($name) !== null) {
                // MESURÉ : « existe déjà » se dit par un code d'ÉCHEC.
                return $this->error(409, 'nameAlreadyExists', 'group already exists');
            }
            $id = $this->withGroup($name);

            return Http::response(['displayName' => $name, 'groupTypes' => [], 'id' => $id], 201);
        }

        $segments = explode('/', $rest);
        $groupId = $segments[0];

        if (! isset($this->groups[$groupId])) {
            return $this->error(404, 'itemNotFound', 'group not found');
        }

        if (count($segments) === 1 && $method === 'GET') {
            return Http::response([
                'displayName' => $this->groups[$groupId]['displayName'],
                'groupTypes' => [],
                'id' => $groupId,
                'members' => array_values(array_map(
                    fn (string $uid): array => [
                        'displayName' => $this->users[$uid]['login'] ?? $uid,
                        'id' => $uid,
                        'onPremisesSamAccountName' => $this->users[$uid]['login'] ?? $uid,
                        'userType' => 'Member',
                    ],
                    $this->groups[$groupId]['members'],
                )),
            ], 200);
        }

        // .../members/$ref  et  .../members/{userId}/$ref
        if (($segments[1] ?? '') === 'members') {
            if ($method === 'POST') {
                $ref = (string) ($body['@odata.id'] ?? '');
                $userId = (string) substr($ref, (int) strrpos($ref, '/') + 1);
                if (in_array($userId, $this->groups[$groupId]['members'], true)) {
                    return $this->error(409, 'nameAlreadyExists', 'already member');
                }
                $this->groups[$groupId]['members'][] = $userId;
                sort($this->groups[$groupId]['members']);

                return Http::response('', 204);
            }

            if ($method === 'DELETE') {
                $userId = $segments[2] ?? '';
                if (! in_array($userId, $this->groups[$groupId]['members'], true)) {
                    return $this->error(404, 'itemNotFound', 'not a member');
                }
                $this->groups[$groupId]['members'] = array_values(array_diff(
                    $this->groups[$groupId]['members'],
                    [$userId],
                ));

                return Http::response('', 204);
            }
        }

        return Http::response('404 page not found', 404);
    }

    /** @param array<string,mixed> $body */
    private function routeSpaces(string $method, string $rest, array $body): mixed
    {
        if (! $this->administrator) {
            return $this->error(403, 'notAllowed', 'insufficient permissions to create a space.');
        }

        $rest = trim($rest, '/');

        if ($rest === '') {
            if ($method === 'GET') {
                return Http::response(['value' => array_values(array_map(
                    fn (array $s): array => $this->spaceBody($s),
                    $this->spaces,
                ))], 200);
            }

            // MESURÉ : AUCUNE idempotence — un homonyme crée un SECOND espace.
            $id = $this->withSpace(trim((string) ($body['name'] ?? '')), (string) ($body['description'] ?? ''));

            return Http::response($this->spaceBody($this->spaces[$id]), 201);
        }

        $segments = explode('/', $rest);
        $spaceId = urldecode($segments[0]);

        if (! isset($this->spaces[$spaceId])) {
            return $this->error(404, 'itemNotFound', 'no drive returned from storage');
        }

        if (count($segments) === 1) {
            if ($method === 'PATCH') {
                if (isset($body['quota']['total'])) {
                    $this->spaces[$spaceId]['quota'] = (int) $body['quota']['total'];
                }
                if (isset($body['name'])) {
                    // MESURÉ : le serveur ROGNE les bords, sans toucher au reste.
                    $this->spaces[$spaceId]['name'] = trim((string) $body['name']);
                }

                return Http::response($this->spaceBody($this->spaces[$spaceId]), 200);
            }

            return Http::response($this->spaceBody($this->spaces[$spaceId]), 200);
        }

        // .../items/{itemId}/children — pour la RACINE, l'identifiant d'item est
        // celui de l'ESPACE (forme mesurée).
        if (($segments[1] ?? '') === 'items' && ($segments[3] ?? '') === 'children') {
            if ($method !== 'GET') {
                // MESURÉ : l'API Graph NE SAIT PAS créer un dossier.
                return Http::response('', 405);
            }

            $itemId = urldecode($segments[2]);
            $parentPath = $itemId === $spaceId
                ? ''
                : (string) array_search($itemId, $this->items[$spaceId] ?? [], true);

            $children = [];
            foreach ($this->items[$spaceId] ?? [] as $path => $id) {
                $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
                if ($parent !== $parentPath) {
                    continue;
                }
                $children[] = [
                    'eTag' => '"' . md5($id) . '"',
                    'folder' => [],
                    'id' => $id,
                    'name' => basename($path),
                    'parentReference' => ['driveId' => $spaceId, 'id' => $itemId],
                    'size' => 0,
                ];
            }

            return Http::response(['value' => $children], 200);
        }

        return Http::response('404 page not found', 404);
    }

    /** @param array<string,mixed> $body */
    private function routePermissions(string $method, string $rest, array $body): mixed
    {
        $rest = trim($rest, '/');
        $segments = explode('/', $rest);
        $spaceId = urldecode($segments[0] ?? '');

        if (! isset($this->spaces[$spaceId])) {
            return $this->error(404, 'itemNotFound', 'no drive returned from storage');
        }

        // /root/... ou /items/{itemId}/...
        if (($segments[1] ?? '') === 'root') {
            $itemId = null;
            $tail = array_slice($segments, 2);
        } elseif (($segments[1] ?? '') === 'items') {
            $itemId = urldecode($segments[2] ?? '');
            $tail = array_slice($segments, 3);
            if (! in_array($itemId, $this->items[$spaceId] ?? [], true)) {
                return $this->error(404, 'itemNotFound', 'stat: error: not found: ');
            }
        } else {
            return Http::response('404 page not found', 404);
        }

        $bucket = $spaceId . '|' . ($itemId ?? 'root');
        $head = $tail[0] ?? '';

        if ($head === 'permissions' && count($tail) === 1 && $method === 'GET') {
            return Http::response([
                '@libre.graph.permissions.actions.allowedValues' => ['libre.graph/driveItem/basic/read'],
                '@libre.graph.permissions.roles.allowedValues' => [],
                'value' => array_values(array_map(
                    fn (array $p): array => $this->permissionBody($p),
                    $this->permissions[$bucket] ?? [],
                )),
            ], 200);
        }

        if ($head === 'invite' && $method === 'POST') {
            $recipient = $body['recipients'][0] ?? [];
            $principal = (string) ($recipient['objectId'] ?? '');
            $type = (string) ($recipient['@libre.graph.recipient.type'] ?? '');
            $roles = array_values((array) ($body['roles'] ?? []));

            if ($roles === []) {
                // MESURÉ : un octroi explicitement VIDE est REFUSÉ.
                return $this->error(400, 'invalidRequest', "Key: 'DriveItemInvite.Roles' … 'one_or_another' tag");
            }

            // MESURÉ : la famille de rôles dépend de la ressource.
            if (! $this->roleFits($roles[0], $itemId === null)) {
                return $this->error(400, 'invalidRequest', 'role not applicable to this resource');
            }

            foreach ($this->permissions[$bucket] ?? [] as $existing) {
                if ($existing['principal'] === $principal) {
                    // MESURÉ : rejouer une invitation — MÊME avec un autre rôle —
                    // rend `409` et ne change RIEN.
                    return $this->error(409, 'nameAlreadyExists', 'error creating share: error: already exists');
                }
            }

            $id = $this->withPermission($spaceId, $itemId, $type, $principal, (string) $roles[0]);

            return Http::response(['value' => [$this->permissionBody($this->permissions[$bucket][$id])]], 200);
        }

        if ($head === 'permissions' && count($tail) === 2) {
            $permissionId = urldecode($tail[1]);

            if (! isset($this->permissions[$bucket][$permissionId])) {
                return $this->error(404, 'itemNotFound', 'error: not found: opaque_id:"' . $permissionId . '"');
            }

            if ($method === 'PATCH') {
                $roles = array_values((array) ($body['roles'] ?? []));
                if ($roles === [] || ! $this->roleFits((string) $roles[0], $itemId === null)) {
                    return $this->error(400, 'invalidRequest', 'role not applicable to this resource');
                }
                $this->permissions[$bucket][$permissionId]['roles'] = [(string) $roles[0]];

                return Http::response($this->permissionBody($this->permissions[$bucket][$permissionId]), 200);
            }

            if ($method === 'DELETE') {
                unset($this->permissions[$bucket][$permissionId]);

                return Http::response('', 204);
            }
        }

        return Http::response('404 page not found', 404);
    }

    // =========================================================================
    // Corps de réponse — la FORME MESURÉE, champs ajoutés compris
    // =========================================================================

    /** @param array{id:string,name:string,description:string,quota:?int} $space */
    private function spaceBody(array $space): array
    {
        return [
            'description' => $space['description'],
            'driveAlias' => 'project/' . strtolower(str_replace([' ', '_'], '-', $space['name'])),
            'driveType' => 'project',
            'id' => $space['id'],
            'lastModifiedDateTime' => '2026-08-13T13:46:14.056Z',
            'name' => $space['name'],
            'owner' => ['user' => ['displayName' => '', 'id' => 'irrelevant']],
            'quota' => [
                'remaining' => $space['quota'] ?? 1000000000,
                'state' => 'normal',
                'total' => $space['quota'] ?? 1000000000,
                'used' => 0,
            ],
            'root' => ['eTag' => '"etag"', 'id' => $space['id']],
            'webUrl' => $this->baseUrl . '/f/' . $space['id'],
        ];
    }

    /**
     * La forme RELUE d'un octroi — avec les champs que le serveur AJOUTE et que
     * l'égalité doit ignorer.
     *
     * @param  array{id:string,type:string,principal:string,roles:list<string>,actions:list<string>}  $permission
     */
    private function permissionBody(array $permission): array
    {
        $body = [
            'createdDateTime' => '2026-08-13T13:48:23.079940554Z',
            'grantedToV2' => [
                $permission['type'] => [
                    'displayName' => 'Libellé ajouté par le serveur',
                    'id' => $permission['principal'],
                ],
            ],
            'id' => $permission['id'],
            'invitation' => ['invitedBy' => ['user' => [
                '@libre.graph.userType' => 'Member',
                'displayName' => 'Admin',
                'id' => 'b870d0d2-1077-4dcf-b961-cc43caa32a0f',
            ]]],
        ];

        if ($permission['actions'] !== []) {
            $body['@libre.graph.permissions.actions'] = $permission['actions'];
        } else {
            $body['roles'] = $permission['roles'];
        }

        return $body;
    }

    private function roleFits(string $roleId, bool $isRoot): bool
    {
        foreach (\App\Services\Filesystem\Backend\OpenCloud\OpenCloudRoleTable::ROLES as $role) {
            if ($role['id'] !== $roleId) {
                continue;
            }

            return $isRoot
                ? $role['family'] === \App\Services\Filesystem\Backend\OpenCloud\OpenCloudRoleTable::FAMILY_SPACE
                : $role['family'] === \App\Services\Filesystem\Backend\OpenCloud\OpenCloudRoleTable::FAMILY_ITEM;
        }

        // Le rôle d'administration existe côté racine, et SE5 ne l'octroie jamais.
        return $roleId === \App\Services\Filesystem\Backend\OpenCloud\OpenCloudRoleTable::MANAGE_ROLE_ID && $isRoot;
    }

    private function error(int $status, string $code, string $message): mixed
    {
        return Http::response([
            'error' => [
                'code' => $code,
                'innererror' => ['date' => '2026-08-13T13:46:14Z', 'request-id' => 'fake'],
                'message' => $message,
            ],
        ], $status);
    }

    private function uuid(string $seed): string
    {
        $this->sequence++;
        $hash = md5($seed . ':' . $this->sequence);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }
}
