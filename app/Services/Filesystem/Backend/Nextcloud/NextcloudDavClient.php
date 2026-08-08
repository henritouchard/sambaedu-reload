<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Nextcloud;

use App\Services\Nextcloud\NextcloudConnectionConfig;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Story 61.3 — LE TROISIÈME REGISTRE D'APPELS DU DÉPÔT : WebDAV.
 *
 * Les deux premiers (OCS et l'endpoint d'administration) parlent en formulaire et
 * répondent en JSON. Celui-ci parle en XML et répond en `multistatus` — et c'est le
 * seul canal qui sache POSER UNE RÈGLE DE PERMISSION AVANCÉE PAR CHEMIN. Ce n'est
 * pas un choix d'élégance : la route REST voisine qui semble faite pour ça est en
 * réalité l'INTERRUPTEUR d'activation des permissions avancées (elle prend un
 * booléen et rien d'autre ; lui passer un principal ou un chemin rend une erreur de
 * requête), et sa sœur ne désigne que QUI a le droit de gérer les règles. Vérifié
 * dans le code de l'application distante, mesuré contre l'instance : aucune route
 * REST ne pose de règle par chemin.
 *
 * ---------------------------------------------------------------------------
 * **LE PIÈGE STRUCTUREL DE CE PROTOCOLE : L'ENVELOPPE NE CONCLUT RIEN.**
 *
 * Une écriture aboutie et une écriture refusée rendent TOUTES DEUX un `207`. Le
 * verdict est le statut PORTÉ PAR CHAQUE PROPRIÉTÉ, dans le corps. Lire l'enveloppe
 * serait exactement la signature de défaut que cet epic traque depuis les Epics
 * 56/57 : un signal accepté qui n'atteint jamais son destinataire. {@see verdictFor()}
 * est donc le SEUL endroit qui décide, et il ne regarde jamais le code de
 * l'enveloppe pour un `207`.
 *
 * **Et son second piège : `404` dans le corps veut dire « rien ».** La propriété
 * des règles répond `404` quand aucune règle n'est posée. Ce n'est pas une erreur —
 * même famille que le refus de méthode d'une création rejouée, ou que le statut
 * « existe déjà » du canal OCS. Le traiter en échec rendrait tout nœud sans règle
 * illisible ; le traiter en « je n'ai pas pu lire » le rendrait éternellement non
 * mesurable. C'est une réponse, et elle vaut « aucune règle ».
 *
 * ---------------------------------------------------------------------------
 * **AUCUN SHELL, JAMAIS.** L'outil en ligne de commande de l'instance sait tout
 * faire — et il suppose un accès système AU SERVEUR NEXTCLOUD, qu'on n'a pas sur une
 * instance distante ou tierce. Le sondage d'ouverture d'epic s'en était servi ; c'est
 * précisément ce qu'il ne faut pas reproduire. Ce backend est 100 % HTTP, donc
 * falsifiable, donc testable sans réseau. Un test d'architecture l'épingle.
 */
final class NextcloudDavClient
{
    /**
     * Délai par appel. Un peu plus long que celui du client d'administration : une
     * relecture de règles porte sur un chemin, pas sur un inventaire, mais elle
     * traverse le montage du dossier d'équipe.
     */
    private const TIMEOUT_SECONDS = 20;

    /** Espace de noms des propriétés propres à l'instance. */
    public const NS_NC = 'http://nextcloud.org/ns';

    public const NS_DAV = 'DAV:';

    public function __construct(private readonly NextcloudConnectionConfig $config)
    {
    }

    // =========================================================================
    // Structure
    // =========================================================================

    /**
     * Crée UN niveau de collection. **Un seul** : ce protocole ne crée pas les
     * parents, et un parent manquant rend un conflit — que l'on nomme au lieu de
     * l'avaler, parce que c'est un défaut d'ordre de pose de notre côté.
     */
    public function makeCollection(string $path): NextcloudDavOutcome
    {
        try {
            $response = $this->send('MKCOL', $path);
        } catch (ConnectionException $e) {
            return NextcloudDavOutcome::failed($this->transportReason($e), 0);
        }

        return match ($response->status()) {
            201 => NextcloudDavOutcome::created(201),
            // Refus de méthode sur une création rejouée : le dossier est là.
            405 => NextcloudDavOutcome::alreadyThere(405),
            409 => NextcloudDavOutcome::missingParent(409),
            default => NextcloudDavOutcome::failed(
                sprintf('création du dossier refusée par l\'instance (HTTP %d).', $response->status()),
                $response->status(),
            ),
        };
    }

    /** Le chemin existe-t-il ? `null` = la question n'a pas eu de réponse. */
    public function exists(string $path): ?bool
    {
        try {
            $response = $this->send('PROPFIND', $path, self::EXISTENCE_BODY, ['Depth' => '0']);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->status() === 404) {
            return false;
        }

        return $response->status() === 207 ? true : null;
    }

    private const EXISTENCE_BODY = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';

    // =========================================================================
    // Règles de permissions avancées
    // =========================================================================

    private const ACL_BODY = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop>'
        . '<nc:acl-list/><nc:inherited-acl-list/><nc:acl-enabled/><nc:acl-can-manage/>'
        . '</d:prop></d:propfind>';

    /**
     * RELIT les règles d'un chemin : celles posées ICI et celles qui DESCENDENT de
     * l'ancêtre, plus la sonde d'activation.
     */
    public function readAcl(string $path): NextcloudAclState
    {
        try {
            $response = $this->send('PROPFIND', $path, self::ACL_BODY, ['Depth' => '0']);
        } catch (ConnectionException $e) {
            return NextcloudAclState::unreadable($this->transportReason($e));
        }

        if ($response->status() === 404) {
            return NextcloudAclState::absent();
        }

        if ($response->status() !== 207) {
            return NextcloudAclState::unreadable(sprintf(
                'relecture des règles refusée par l\'instance (HTTP %d).',
                $response->status(),
            ));
        }

        $properties = $this->parseMultiStatus($response->body());
        if ($properties === null) {
            return NextcloudAclState::unreadable('réponse de relecture illisible (corps XML non exploitable).');
        }

        $own = $properties['{' . self::NS_NC . '}acl-list'] ?? null;
        $inherited = $properties['{' . self::NS_NC . '}inherited-acl-list'] ?? null;
        $enabled = $properties['{' . self::NS_NC . '}acl-enabled'] ?? null;

        // Un statut d'échec AUTRE que « absent » sur la propriété des règles est un
        // vrai refus : on ne conclut rien. `404` = aucune règle, et c'est tout.
        if ($own !== null && $own['status'] !== 200 && $own['status'] !== 404) {
            return NextcloudAclState::unreadable(sprintf(
                'la propriété des règles a été refusée par l\'instance (statut %d dans la réponse).',
                $own['status'],
            ));
        }

        return NextcloudAclState::read(
            $own !== null && $own['status'] === 200 ? $this->rulesIn($own['element']) : [],
            $inherited !== null && $inherited['status'] === 200 ? $this->rulesIn($inherited['element']) : [],
            $enabled !== null && $enabled['status'] === 200 && trim((string) $enabled['element']?->textContent) === '1',
        );
    }

    /**
     * ÉCRIT la liste COMPLÈTE des règles d'un chemin. Une liste vide retire tout ce
     * qui y était posé — c'est ainsi que la révocation opère, en une écriture.
     *
     * @param  list<NextcloudAclRule>  $rules
     */
    public function writeAcl(string $path, array $rules): NextcloudDavOutcome
    {
        try {
            $response = $this->send('PROPPATCH', $path, NextcloudAclRule::propertyUpdateBody($rules));
        } catch (ConnectionException $e) {
            return NextcloudDavOutcome::failed($this->transportReason($e), 0);
        }

        if ($response->status() === 404) {
            return NextcloudDavOutcome::failed(
                'le chemin visé par la règle n\'existe pas sur l\'instance : la règle n\'a pas été posée.',
                404,
            );
        }

        if ($response->status() !== 207) {
            return NextcloudDavOutcome::failed(sprintf(
                'écriture des règles refusée par l\'instance (HTTP %d).',
                $response->status(),
            ), $response->status());
        }

        return $this->verdictFor($response->body());
    }

    /**
     * **LE VERDICT EST DANS LE CORPS, PROPRIÉTÉ PAR PROPRIÉTÉ.**
     *
     * Une enveloppe `207` peut envelopper un échec — c'est le mode de rupture
     * normal de ce protocole, pas un cas tordu. On lit donc le statut de la
     * propriété qu'on vient d'écrire, et on refuse de conclure si elle est absente :
     * une réponse qui ne parle pas de ce qu'on a écrit ne prouve pas qu'on l'a
     * écrit.
     */
    private function verdictFor(string $body): NextcloudDavOutcome
    {
        $properties = $this->parseMultiStatus($body);
        if ($properties === null) {
            return NextcloudDavOutcome::failed(
                'réponse d\'écriture illisible (corps XML non exploitable) : rien ne prouve que la règle a été posée.',
                207,
            );
        }

        $entry = $properties['{' . self::NS_NC . '}acl-list'] ?? null;

        if ($entry === null) {
            return NextcloudDavOutcome::failed(
                'l\'instance a répondu sans se prononcer sur la propriété écrite : rien ne prouve que la '
                . 'règle a été posée (le code d\'enveloppe ne conclut rien).',
                207,
            );
        }

        if ($entry['status'] !== 200) {
            return NextcloudDavOutcome::failed(sprintf(
                'l\'instance a refusé la règle (statut %d porté par la propriété, dans une enveloppe qui '
                . 'annonçait pourtant un succès partiel).',
                $entry['status'],
            ), 207);
        }

        return NextcloudDavOutcome::created(207);
    }

    // =========================================================================
    // Analyse du corps
    // =========================================================================

    /**
     * Les propriétés de la PREMIÈRE réponse d'un `multistatus`, indexées par nom
     * qualifié, chacune avec le statut qui la porte.
     *
     * `null` si le corps n'est pas exploitable — et « pas exploitable » n'est jamais
     * traduit en « vide » : ce serait déclarer conforme ce qu'on n'a pas lu.
     *
     * @return array<string, array{status:int, element:?DOMElement}>|null
     */
    private function parseMultiStatus(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Aucune entité externe n'est chargée : le corps vient du réseau.
        $loaded = $document->loadXML($body, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::NS_DAV);

        $response = $xpath->query('//d:multistatus/d:response')->item(0);
        if (! $response instanceof DOMElement) {
            return null;
        }

        $properties = [];

        foreach ($xpath->query('./d:propstat', $response) as $propstat) {
            $statusNode = $xpath->query('./d:status', $propstat)->item(0);
            $status = self::statusCodeOf($statusNode?->textContent ?? '');

            foreach ($xpath->query('./d:prop/*', $propstat) as $property) {
                if (! $property instanceof DOMElement) {
                    continue;
                }
                $key = '{' . ($property->namespaceURI ?? '') . '}' . $property->localName;
                // Une propriété citée deux fois : le premier statut fait foi, et
                // c'est celui du bloc de succès, qui vient en tête.
                $properties[$key] ??= ['status' => $status, 'element' => $property];
            }
        }

        return $properties;
    }

    /** Le code numérique d'une ligne de statut, `0` si elle est illisible. */
    private static function statusCodeOf(string $line): int
    {
        return preg_match('#\s(\d{3})\s#', ' ' . trim($line) . ' ', $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * Les règles portées par un élément de liste.
     *
     * **Les champs que le serveur ajoute sont IGNORÉS ICI**, à la source : la
     * relecture augmente chaque règle d'un libellé d'affichage que personne n'a
     * écrit. Ne lire que les quatre champs écrits est ce qui rend la comparaison
     * d'idempotence stable — sinon, dérive permanente et réécriture à chaque
     * passage.
     *
     * @return list<NextcloudAclRule>
     */
    private function rulesIn(?DOMElement $list): array
    {
        if (! $list instanceof DOMElement) {
            return [];
        }

        $rules = [];

        foreach ($list->getElementsByTagNameNS(self::NS_NC, 'acl') as $acl) {
            $type = self::childText($acl, 'acl-mapping-type');
            $id = self::childText($acl, 'acl-mapping-id');
            if ($type === null || $id === null || $id === '') {
                continue;
            }

            $rules[] = new NextcloudAclRule(
                $type,
                $id,
                (int) (self::childText($acl, 'acl-mask') ?? '0'),
                (int) (self::childText($acl, 'acl-permissions') ?? '0'),
            );
        }

        return $rules;
    }

    private static function childText(DOMElement $parent, string $local): ?string
    {
        $node = $parent->getElementsByTagNameNS(self::NS_NC, $local)->item(0);

        return $node === null ? null : trim($node->textContent);
    }

    // =========================================================================
    // Transport
    // =========================================================================

    /**
     * L'URL WebDAV d'un chemin, sous l'espace du compte d'administration.
     *
     * Chaque segment est encodé séparément : encoder la chaîne entière écraserait
     * les séparateurs, et n'en encoder aucun laisserait passer un nom de dossier à
     * espace — les deux se voient tout de suite, mais une seule fois qu'un
     * établissement a créé un dossier avec une apostrophe.
     */
    public function url(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));

        return $this->config->url('remote.php/dav/files/' . rawurlencode($this->config->adminUser))
            . ($segments === [] ? '' : '/' . implode('/', array_map(rawurlencode(...), $segments)));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $path, ?string $body = null, array $headers = []): Response
    {
        $request = $this->pending();

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        $options = $body === null ? [] : ['body' => $body];

        return $request->send($method, $this->url($path), $options);
    }

    private function pending(): PendingRequest
    {
        return Http::withBasicAuth($this->config->adminUser, $this->config->adminPassword())
            ->withHeaders([
                'Content-Type' => 'application/xml; charset=UTF-8',
                // Posé partout, comme sur le canal OCS : il dispense l'instance de
                // réclamer un état de session sur les routes d'API.
                'OCS-APIRequest' => 'true',
            ])
            ->withOptions(['verify' => $this->config->verifyTls])
            ->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * Raison COURTE d'un échec de transport : on ne recopie pas le message complet
     * du client HTTP, qui contient l'URL — parfois avec des identifiants dedans.
     */
    private function transportReason(ConnectionException $e): string
    {
        $message = trim($e->getMessage());

        return 'instance injoignable (' . ($message === ''
            ? 'échec de connexion'
            : (string) preg_replace('/\s+/', ' ', mb_substr($message, 0, 120))) . ').';
    }
}
