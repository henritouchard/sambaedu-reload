<?php

declare(strict_types=1);

namespace Tests\Feature\Filesystem\Backend\Nextcloud;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Story 61.3 — UNE INSTANCE EN MÉMOIRE, qui rejoue les CORPS MESURÉS.
 *
 * **Pourquoi un double avec un état, et pas une pile de réponses figées.** Les
 * propriétés que cette story doit prouver sont des propriétés de SÉQUENCE :
 * l'idempotence d'un second passage, l'adoption d'un dossier existant, la survie
 * d'un accès après pose, le retrait d'une règle à la main. Une pile de réponses les
 * rendrait toutes vraies par construction — le double serait vert parce qu'on lui a
 * dicté d'être vert.
 *
 * **Ce qu'il rejoue vient des relevés du 2026-08-08**, et de rien d'autre :
 *  - écritures en formulaire, lectures en JSON enveloppé ;
 *  - « existe déjà » = statut applicatif `102` ;
 *  - point de montage relu AVEC une barre oblique de tête que personne n'a écrite ;
 *  - création de collection : `201`, puis `405` au rejeu, `409` si le parent manque ;
 *  - relecture de règles : `207` d'enveloppe, statut PAR PROPRIÉTÉ, `404` quand
 *    aucune règle n'est posée, et un **libellé d'affichage AJOUTÉ par le serveur**
 *    que personne n'a écrit ;
 *  - propagation de l'ancêtre lisible dans sa propre propriété.
 */
final class FakeNextcloudInstance
{
    /** @var array<int, array{id:int,mount_point:string,quota:int,acl:bool,groups:array<string,int>}> */
    public array $folders = [];

    /** @var array<string, list<string>> nom de groupe => identifiants de comptes */
    public array $groups = [];

    /** @var array<string, bool> chemins de collections existants */
    public array $collections = [];

    /** @var array<string, list<array{type:string,id:string,mask:int,permissions:int}>> chemin => règles */
    public array $acl = [];

    /** @var list<array{method:string,url:string,body:string}> */
    public array $calls = [];

    /**
     * Principaux dont les règles sont **ACCEPTÉES SANS EFFET** — le mode de rupture
     * MESURÉ au sondage d'ouverture d'epic : l'instruction est reçue en succès, et
     * la relecture rend un accès là où on demandait zéro. C'est exactement ce que la
     * relecture après écriture existe pour constater.
     *
     * @var array<string, list<string>> chemin => identifiants de principaux avalés
     */
    public array $swallowRulesFor = [];

    private int $nextFolderId = 1;

    /**
     * Le compte d'administration : il DOIT exister, sans quoi rien n'est posable.
     * L'instance de sondage en a un, et SE5 l'exige (recadrage du 2026-08-08).
     */
    public function __construct(public readonly string $admin = 'admin')
    {
    }

    /** Un dossier d'équipe PRÉEXISTANT — le cas de l'adoption. */
    public function withFolder(string $mountPoint, bool $acl = false, array $groups = []): self
    {
        $id = $this->nextFolderId++;
        $this->folders[$id] = [
            'id' => $id,
            'mount_point' => $mountPoint,
            'quota' => -3,
            'acl' => $acl,
            'groups' => $groups,
        ];
        $this->collections[$mountPoint] = true;

        return $this;
    }

    public function withCollection(string $path): self
    {
        $this->collections[$path] = true;

        return $this;
    }

    /** @param list<array{type:string,id:string,mask:int,permissions:int}> $rules */
    public function withAcl(string $path, array $rules): self
    {
        $this->acl[$path] = $rules;

        return $this;
    }

    public function withGroup(string $name, array $members = []): self
    {
        $this->groups[$name] = $members;

        return $this;
    }

    /** Branche ce double sur le client HTTP. */
    public function install(): void
    {
        Http::fake(fn (Request $request) => $this->handle($request));
    }

    /** Les appels d'ÉCRITURE émis — ce qui rend « zéro écriture » vérifiable. */
    public function writes(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => in_array($c['method'], ['POST', 'PUT', 'DELETE', 'MKCOL', 'PROPPATCH'], true),
        ));
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    // =========================================================================
    // Routage
    // =========================================================================

    private function handle(Request $request)
    {
        $method = $request->method();
        $url = $request->url();
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $request->body()];

        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str($request->body(), $form);

        if (str_contains($path, '/remote.php/dav/files/')) {
            return $this->dav($method, $path, $request->body());
        }

        if (str_contains($path, '/apps/groupfolders/folders')) {
            return $this->folders($method, $path, $form);
        }

        if (str_contains($path, '/cloud/groups')) {
            return $this->cloudGroups($method, $path, $form);
        }

        if (str_contains($path, '/cloud/users/')) {
            return $this->cloudUsers($method, $path, $form);
        }

        return Http::response(self::ocs(404, [], 'route inconnue du double'), 200);
    }

    // =========================================================================
    // Dossiers d'équipe
    // =========================================================================

    private function folders(string $method, string $path, array $form)
    {
        $tail = trim((string) substr($path, (int) strpos($path, '/folders') + strlen('/folders')), '/');
        $segments = $tail === '' ? [] : explode('/', $tail);

        if ($segments === []) {
            if ($method === 'GET') {
                // Le point de montage revient AVEC une barre oblique de tête que
                // personne n'a écrite : deuxième occurrence du piège dans l'epic.
                $out = [];
                foreach ($this->folders as $folder) {
                    $out[(string) $folder['id']] = ['mount_point' => '/' . $folder['mount_point']] + $folder;
                }

                return Http::response(self::ocs(100, $out), 200);
            }

            if ($method === 'POST') {
                $id = $this->nextFolderId++;
                $this->folders[$id] = [
                    'id' => $id,
                    'mount_point' => (string) ($form['mountpoint'] ?? ''),
                    'quota' => -3,
                    'acl' => false,
                    'groups' => [],
                ];
                $this->collections[(string) ($form['mountpoint'] ?? '')] = true;

                return Http::response(self::ocs(100, ['id' => $id]), 200);
            }
        }

        $id = (int) ($segments[0] ?? 0);
        if (! isset($this->folders[$id])) {
            return Http::response(self::ocs(404, [], 'dossier inconnu'), 200);
        }

        $action = $segments[1] ?? '';

        if ($action === 'acl' && $method === 'POST') {
            $this->folders[$id]['acl'] = ((string) ($form['acl'] ?? '0')) === '1';

            return Http::response(self::ocs(100), 200);
        }

        if ($action === 'quota' && $method === 'POST') {
            $this->folders[$id]['quota'] = (int) ($form['quota'] ?? 0);

            return Http::response(self::ocs(100), 200);
        }

        if ($action === 'groups') {
            $group = isset($segments[2]) ? rawurldecode($segments[2]) : (string) ($form['group'] ?? '');

            if ($method === 'DELETE') {
                unset($this->folders[$id]['groups'][$group]);

                return Http::response(self::ocs(100), 200);
            }

            if ($method === 'POST' && isset($segments[2])) {
                if (! array_key_exists($group, $this->folders[$id]['groups'])) {
                    return Http::response(self::ocs(404, [], 'groupe absent de la carte'), 200);
                }
                $this->folders[$id]['groups'][$group] = (int) ($form['permissions'] ?? 0);

                return Http::response(self::ocs(100), 200);
            }

            if ($method === 'POST') {
                if (! isset($this->groups[$group])) {
                    // Mesuré : un octroi visant un groupe inconnu est refusé net.
                    return Http::response(self::ocs(404, [], 'Please specify a valid group'), 200);
                }
                $this->folders[$id]['groups'][$group] ??= 31;

                return Http::response(self::ocs(100), 200);
            }
        }

        if ($action === '' && $method === 'DELETE') {
            unset($this->folders[$id]);

            return Http::response(self::ocs(100), 200);
        }

        return Http::response(self::ocs(400, [], 'requête inattendue'), 200);
    }

    // =========================================================================
    // Groupes et comptes
    // =========================================================================

    private function cloudGroups(string $method, string $path, array $form)
    {
        $tail = trim((string) substr($path, (int) strpos($path, '/cloud/groups') + strlen('/cloud/groups')), '/');

        if ($tail === '' && $method === 'POST') {
            $name = (string) ($form['groupid'] ?? '');
            if (isset($this->groups[$name])) {
                return Http::response(self::ocs(102, [], 'group exists'), 200);
            }
            $this->groups[$name] = [];

            return Http::response(self::ocs(100), 200);
        }

        $name = rawurldecode($tail);
        if ($method === 'GET') {
            return isset($this->groups[$name])
                ? Http::response(self::ocs(100, ['users' => $this->groups[$name]]), 200)
                : Http::response(self::ocs(998, [], 'group not found'), 200);
        }

        return Http::response(self::ocs(400), 200);
    }

    private function cloudUsers(string $method, string $path, array $form)
    {
        if (! str_ends_with($path, '/groups')) {
            return Http::response(self::ocs(404), 200);
        }

        $userId = rawurldecode(basename(dirname($path)));
        $group = (string) ($form['groupid'] ?? '');

        if (! isset($this->groups[$group])) {
            return Http::response(self::ocs(102, [], 'group unknown'), 200);
        }

        if ($method === 'POST') {
            if (! in_array($userId, $this->groups[$group], true)) {
                $this->groups[$group][] = $userId;
            }

            return Http::response(self::ocs(100), 200);
        }

        $this->groups[$group] = array_values(array_filter(
            $this->groups[$group],
            static fn (string $u): bool => $u !== $userId,
        ));

        return Http::response(self::ocs(100), 200);
    }

    // =========================================================================
    // WebDAV
    // =========================================================================

    private function dav(string $method, string $path, string $body)
    {
        $prefix = '/remote.php/dav/files/' . rawurlencode($this->admin);
        $relative = rawurldecode(trim((string) substr($path, strlen($prefix)), '/'));
        $relative = implode('/', array_map(rawurldecode(...), explode('/', $relative)));

        if ($method === 'MKCOL') {
            if (isset($this->collections[$relative])) {
                return Http::response('', 405);
            }
            $parent = dirname($relative);
            if ($parent !== '.' && ! isset($this->collections[$parent])) {
                return Http::response('', 409);
            }
            $this->collections[$relative] = true;

            return Http::response('', 201);
        }

        if (! isset($this->collections[$relative])) {
            return Http::response('', 404);
        }

        if ($method === 'PROPPATCH') {
            $swallowed = $this->swallowRulesFor[$relative] ?? [];

            $this->acl[$relative] = array_values(array_filter(
                self::parseRules($body),
                static fn (array $r): bool => ! in_array($r['id'], $swallowed, true),
            ));

            // Succès annoncé, effet partiel : le corps dit 200 sur la propriété.
            return Http::response(self::writeMultiStatus($path), 207);
        }

        return Http::response($this->readMultiStatus($path, $relative), 207);
    }

    /** @return list<array{type:string,id:string,mask:int,permissions:int}> */
    private static function parseRules(string $body): array
    {
        $document = new \DOMDocument();
        $document->loadXML($body, LIBXML_NONET);

        $rules = [];
        foreach ($document->getElementsByTagNameNS('http://nextcloud.org/ns', 'acl') as $acl) {
            $get = static function (string $local) use ($acl): string {
                $node = $acl->getElementsByTagNameNS('http://nextcloud.org/ns', $local)->item(0);

                return $node === null ? '' : trim($node->textContent);
            };

            $rules[] = [
                'type' => $get('acl-mapping-type'),
                'id' => $get('acl-mapping-id'),
                'mask' => (int) $get('acl-mask'),
                'permissions' => (int) $get('acl-permissions'),
            ];
        }

        return $rules;
    }

    private static function writeMultiStatus(string $href, string $status = 'HTTP/1.1 200 OK'): string
    {
        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
            . '<d:response><d:href>' . $href . '</d:href>'
            . '<d:propstat><d:prop><nc:acl-list/></d:prop><d:status>' . $status . '</d:status></d:propstat>'
            . '</d:response></d:multistatus>';
    }

    private function readMultiStatus(string $href, string $relative): string
    {
        $own = $this->acl[$relative] ?? [];
        $inherited = [];
        $ancestor = dirname($relative);
        while ($ancestor !== '.' && $ancestor !== '/' && $ancestor !== '') {
            foreach ($this->acl[$ancestor] ?? [] as $rule) {
                $key = $rule['type'] . ':' . $rule['id'];
                $inherited[$key] ??= $rule;
            }
            $ancestor = dirname($ancestor);
        }

        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
            . '<d:response><d:href>' . $href . '</d:href>';

        $found = '<nc:acl-enabled>1</nc:acl-enabled><nc:acl-can-manage>1</nc:acl-can-manage>';
        $missing = '';

        $found .= $own === [] ? '' : '<nc:acl-list>' . self::renderRules($own) . '</nc:acl-list>';
        $missing .= $own === [] ? '<nc:acl-list/>' : '';

        $found .= $inherited === [] ? '' : '<nc:inherited-acl-list>' . self::renderRules(array_values($inherited)) . '</nc:inherited-acl-list>';
        $missing .= $inherited === [] ? '<nc:inherited-acl-list/>' : '';

        $xml .= '<d:propstat><d:prop>' . $found . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>';

        if ($missing !== '') {
            // `404` DANS le multistatus = « aucune règle », jamais une erreur.
            $xml .= '<d:propstat><d:prop>' . $missing . '</d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>';
        }

        return $xml . '</d:response></d:multistatus>';
    }

    /** @param list<array{type:string,id:string,mask:int,permissions:int}> $rules */
    private static function renderRules(array $rules): string
    {
        $xml = '';
        foreach ($rules as $rule) {
            $xml .= '<nc:acl>'
                . '<nc:acl-mapping-type>' . $rule['type'] . '</nc:acl-mapping-type>'
                . '<nc:acl-mapping-id>' . $rule['id'] . '</nc:acl-mapping-id>'
                // AJOUTÉ PAR LE SERVEUR : personne ne l'a écrit, et la comparaison
                // d'idempotence doit l'ignorer.
                . '<nc:acl-mapping-display-name>' . ucfirst($rule['id']) . '</nc:acl-mapping-display-name>'
                . '<nc:acl-mask>' . $rule['mask'] . '</nc:acl-mask>'
                . '<nc:acl-permissions>' . $rule['permissions'] . '</nc:acl-permissions>'
                . '</nc:acl>';
        }

        return $xml;
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = [], string $message = ''): array
    {
        return ['ocs' => [
            'meta' => ['status' => $code < 300 ? 'ok' : 'failure', 'statuscode' => $code, 'message' => $message],
            'data' => $data,
        ]];
    }
}
