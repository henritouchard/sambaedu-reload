<?php

declare(strict_types=1);

/*
 * =============================================================================
 * fake-bbb-server.php — Epic 57 (outil de DEV/QA interne)
 *
 * Un FAUX serveur d'API BigBlueButton, sans aucune dépendance, servi par le
 * serveur intégré de PHP, destiné à rendre jouable SUR L'HÔTE la moitié de la
 * dette QA de l'extension `extensions/bbb/`.
 *
 * ── Pourquoi il existe ──────────────────────────────────────────────────────
 *
 * L'extension BBB a été développée et testée de bout en bout sans jamais
 * rencontrer un vrai serveur BigBlueButton. Les deux défauts les plus coûteux
 * de l'epic — l'URL de base doublée en `…/apiapi/create` (57.2) et l'hydratation
 * d'un enregistrement sans bloc `playback` (57.3) — n'ont été trouvés qu'en
 * développant la story SUIVANTE, et tous deux imputaient leur faute au serveur
 * distant. Déployer un vrai BBB coûte un Ubuntu dédié, du HTTPS obligatoire et
 * une grande plage UDP ; ce script coûte une commande.
 *
 * ⚠️ SA SEULE VALEUR EST SA FIDÉLITÉ. Un faux serveur approximatif donnerait une
 * confiance fausse, ce qui serait PIRE que rien. D'où :
 *
 *   - le checksum est validé À L'IDENTIQUE du vrai protocole,
 *     `sha1(<méthode> + <query sans le paramètre checksum> + <secret>)`, sur la
 *     query BRUTE — c'est exactement ce que construit
 *     `Util/UrlBuilder::buildQs()` du fork `sambaedu/bigbluebutton-api-php` ;
 *   - la racine `/bigbluebutton/api` répond SANS vérifier le checksum, comme le
 *     vrai. C'est précisément le défaut que la 57.1 a corrigé en choisissant
 *     `getMeetings` plutôt qu'un GET sur l'URL de base : un secret faux passe la
 *     racine et n'échoue qu'au premier salon, en production, devant une classe.
 *     Ce faux serveur REPRODUIT ce piège au lieu de le masquer ;
 *   - les formes XML sont celles que `Responses/*` et `Core/*` savent lire, y
 *     compris les cas où ils ne savent PAS les lire (voir les pièges).
 *
 * Ce que ce script NE PROUVERA JAMAIS, et qui reste à jouer contre un vrai
 * serveur : le rôle vécu dans la conférence (modérateur vs participant), la
 * jonction d'un invité à travers le proxy, la production réelle d'un
 * enregistrement, et tout le chemin TLS (le faux serveur parle http en clair).
 * Voir `docs/qa/domains/extensions.md`, Section 26.
 *
 * ── Utilisation ─────────────────────────────────────────────────────────────
 *
 *   php scripts/dev/fake-bbb-server.php --help
 *   php scripts/dev/fake-bbb-server.php --port 8088 --secret sekret-de-test
 *   php scripts/dev/fake-bbb-server.php --port 8089 --load 12          # 2ᵉ instance
 *
 * ⚠️ CE N'EST PAS UN LIVRABLE. Il vit dans `scripts/dev/`, à côté de
 * `build-test-extension.sh`, et ne doit JAMAIS partir dans le paquet
 * `sambaedu-ext-bbb` : `packaging/build-deb.sh` ne copie que `public`, `src`,
 * `views`, `composer.*` et `manifest.json`.
 * =============================================================================
 */

// =============================================================================
//  Réponse
// =============================================================================

final class FakeBbbReply
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public static function xml(string $body, int $status = 200): self
    {
        return new self($status, 'text/xml;charset=UTF-8', $body);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, 'text/html;charset=UTF-8', $body);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, 'text/plain;charset=UTF-8', $body);
    }

    public static function redirect(string $location): self
    {
        return new self(302, 'text/html;charset=UTF-8', '', ['Location' => $location]);
    }
}

// =============================================================================
//  L'état — un fichier JSON jetable, sous verrou
// =============================================================================

/**
 * Le magasin d'état.
 *
 * `php -S` est mono-processus par défaut, mais `PHP_CLI_SERVER_WORKERS` le rend
 * multi-processus sans prévenir : toute lecture-modification-écriture passe donc
 * par un `flock()`. C'est la même leçon que la migration v3 du magasin de
 * l'extension (review 57.3 #1) — un TOCTOU ne se répare pas après coup.
 */
final class FakeBbbStore
{
    public function __construct(private readonly string $path)
    {
    }

    /** Structure vide, telle que la CLI l'écrit au démarrage. */
    public static function blank(): array
    {
        return [
            'config' => [
                'secret' => 'sekret-de-test',
                'running' => 'on-join',
                'recordings_on_create' => 1,
            ],
            'runtime' => [
                'load' => 0,
                'traps' => [],
                'delay_ms' => 5000,
            ],
            'meetings' => [],
            'recordings' => [],
        ];
    }

    /**
     * Lit l'état, le passe à `$fn`, réécrit ce que `$fn` a rendu comme état.
     *
     * @param  callable(array): array{0: mixed, 1: array}  $fn  rend [résultat, état]
     */
    public function mutate(callable $fn): mixed
    {
        $handle = fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('état illisible : ' . $this->path);
        }

        try {
            flock($handle, LOCK_EX);

            $raw = stream_get_contents($handle);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $state = is_array($state) ? $state + self::blank() : self::blank();

            [$result, $next] = $fn($state);

            if ($next !== $state) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                fflush($handle);
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function read(): array
    {
        return $this->mutate(static fn (array $s): array => [$s, $s]);
    }

    public function write(array $state): void
    {
        $this->mutate(static fn (): array => [null, $state]);
    }
}

// =============================================================================
//  Le serveur
// =============================================================================

final class FakeBbbServer
{
    /**
     * Les pièges activables. Ce sont EUX le cœur de la valeur de l'outil : les
     * trois premiers ont réellement mordu pendant l'Epic 57, le quatrième
     * éprouve les deux bornes de temps du client.
     */
    public const TRAPS = [
        'no-meetings-element' => 'getMeetings répond SUCCESS SANS le moindre élément `meetings` (avertissement PHP, jamais d\'exception : aucun catch ne l\'attrape) — review 57.4',
        'recording-no-playback' => 'getRecordings injecte deux enregistrements SANS bloc `playback` : un `processing` (écarté par le filtre state=published) et un `published` (qui passe le filtre et atteint Record::__construct) — review 57.3',
        'html' => 'toutes les réponses de /api sont une page HTML de reverse-proxy en 200 — le corps n\'est pas du XML',
        'slow' => 'toutes les réponses de /api sont retardées de --delay ms (défaut 5000 : au-delà de la borne de sonde à 3 s, en deçà de la borne totale à 8 s)',
    ];

    public function __construct(
        private readonly FakeBbbStore $store,
        private readonly string $origin,
        private readonly bool $sleepEnabled = true,
    ) {
    }

    /**
     * Le routage.
     *
     * ⚠️ **Les DEUX formes d'URL de base doivent marcher**, puisque c'est
     * exactement le bug que l'on veut pouvoir reproduire ET voir disparaître :
     * `apiBase()` normalise `…/bigbluebutton/api` ET `…/bigbluebutton` vers
     * `…/bigbluebutton/`, et la bibliothèque y colle `api/<méthode>`. Le routeur
     * accepte donc N'IMPORTE QUEL préfixe suivi de `/api/<méthode>`, y compris
     * un préfixe vide (base `http://127.0.0.1:8088` toute nue).
     *
     * Et surtout : `…/bigbluebutton/apiapi/create` — ce que produirait un client
     * SANS `apiBase()` — ne matche RIEN et tombe sur un 404 HTML, donc sur la
     * `BadResponseException` du fork, donc sur « réponse inattendue » côté
     * extension. C'est le symptôme exact du défaut de 57.2, reproduit à
     * l'identique.
     */
    public function handle(string $httpMethod, string $path, string $rawQuery): FakeBbbReply
    {
        if ($path === '') {
            $path = '/';
        }

        // Surface de pilotage, hors API : jamais de checksum, jamais de piège.
        if ($path === '/control' || str_starts_with($path, '/control/')) {
            return $this->control($path, $rawQuery);
        }

        // Pages « humaines » : la conférence bidon et la lecture bidon.
        if (str_ends_with($path, '/fake-bbb/conference')) {
            return $this->conferencePage($rawQuery);
        }

        if (str_ends_with($path, '/fake-bbb/playback')) {
            return $this->playbackPage($rawQuery);
        }

        if (preg_match('~^(?<prefix>.*)/api/(?<method>[A-Za-z]+)$~', $path, $m) === 1) {
            return $this->api($m['method'], $m['prefix'], $rawQuery, $httpMethod);
        }

        if (preg_match('~^(?<prefix>.*)/api/?$~', $path, $m) === 1) {
            return $this->api('', $m['prefix'], $rawQuery, $httpMethod);
        }

        return $this->proxyNotFound($path);
    }

    // -------------------------------------------------------------------------
    //  L'API
    // -------------------------------------------------------------------------

    private function api(string $method, string $prefix, string $rawQuery, string $httpMethod): FakeBbbReply
    {
        $state = $this->store->read();
        $traps = (array) ($state['runtime']['traps'] ?? []);

        if (in_array('slow', $traps, true) && $this->sleepEnabled) {
            usleep(max(0, (int) ($state['runtime']['delay_ms'] ?? 5000)) * 1000);
        }

        if (in_array('html', $traps, true)) {
            // Un corps qui n'est PAS du XML, servi en 200 : le fork lève sur
            // `new SimpleXMLElement($data)`, donc l'extension passe par son
            // `catch (Throwable)` et non par `BadResponseException`.
            return FakeBbbReply::html($this->proxyPage(
                'Service temporairement indisponible',
                'Le mandataire n\'a pas pu joindre le serveur d\'application.',
            ));
        }

        // ═══════════════════════════════════════════════════════════════════
        //  LA RACINE NE VÉRIFIE PAS LE CHECKSUM — et c'est VOULU.
        //
        //  Un vrai BigBlueButton répond sa version à qui la demande, sans
        //  signature. C'est ce qui rendait `server_bbb_is_up()` de SE4
        //  trompeur : il déclarait « vert » un serveur dont le secret était
        //  faux. Masquer ce comportement ici rendrait l'outil menteur sur le
        //  point même que la 57.1 a corrigé.
        // ═══════════════════════════════════════════════════════════════════
        if ($method === '') {
            return FakeBbbReply::xml($this->response([
                'returncode' => 'SUCCESS',
                'version' => '2.0',
                'apiVersion' => '2.0',
                'bbbVersion' => '2.7.5',
            ]));
        }

        $known = ['create', 'join', 'isMeetingRunning', 'getMeetings', 'getRecordings', 'deleteRecordings'];

        if (! in_array($method, $known, true)) {
            // Les six seules méthodes que le legacy appelait, et donc les six
            // seules que l'extension porte (carte 57, § 7). Tout le reste rend
            // `unsupportedRequest`, comme un vrai BBB — délibérément : servir
            // un `getMeetingInfo` plausible inviterait à s'appuyer dessus.
            return FakeBbbReply::xml($this->failure(
                'unsupportedRequest',
                'This request is not supported.',
            ));
        }

        $secret = (string) ($state['config']['secret'] ?? '');

        if (! $this->checksumIsValid($method, $rawQuery, $secret)) {
            return FakeBbbReply::xml($this->failure(
                'checksumError',
                'Checksums do not match',
            ));
        }

        $params = self::parseQuery($rawQuery);

        return match ($method) {
            'create' => $this->create($params),
            'join' => $this->join($params, $prefix),
            'isMeetingRunning' => $this->isMeetingRunning($params),
            'getMeetings' => $this->getMeetings(),
            'getRecordings' => $this->getRecordings($params),
            'deleteRecordings' => $this->deleteRecordings($params),
        };
    }

    /**
     * **La validation du checksum, à l'identique du vrai protocole.**
     *
     * `UrlBuilder::buildQs()` fait, mot pour mot :
     *
     *     return $params . '&checksum=' . sha1($method . $params . $securitySalt);
     *
     * Deux conséquences qu'il faut absolument reproduire :
     *
     * 1. le calcul porte sur la query BRUTE, telle qu'elle est passée sur le
     *    fil — pas sur des paramètres re-encodés. Ré-encoder avec
     *    `http_build_query()` casserait la signature sur le premier nom
     *    contenant un caractère que PHP encode autrement (le fork émet par
     *    exemple, faute de frappe, un paramètre nommé
     *    `breakoutRoomsPrivateChatEnabled(`) ;
     * 2. quand il n'y a AUCUN paramètre — `getMeetings` et la racine — la query
     *    vaut littéralement `&checksum=…`, avec un `&` de tête et un segment
     *    vide devant. Retirer le segment `checksum` doit alors rendre la chaîne
     *    VIDE, pas `&`. D'où le découpage sur `&` puis la recomposition.
     */
    private function checksumIsValid(string $method, string $rawQuery, string $secret): bool
    {
        $given = null;
        $kept = [];

        foreach ($rawQuery === '' ? [] : explode('&', $rawQuery) as $segment) {
            if ($given === null && str_starts_with($segment, 'checksum=')) {
                $given = substr($segment, strlen('checksum='));

                continue;
            }

            $kept[] = $segment;
        }

        if ($given === null) {
            return false;
        }

        return hash_equals(sha1($method . implode('&', $kept) . $secret), $given);
    }

    // -------------------------------------------------------------------------
    //  create
    // -------------------------------------------------------------------------

    private function create(array $params): FakeBbbReply
    {
        $meetingId = (string) ($params['meetingID'] ?? '');

        if ($meetingId === '') {
            return FakeBbbReply::xml($this->failure(
                'missingParamMeetingID',
                'You must specify a meeting ID for the meeting.',
            ));
        }

        return FakeBbbReply::xml($this->store->mutate(function (array $state) use ($params, $meetingId): array {
            $existing = $state['meetings'][$meetingId] ?? null;

            // `createMeeting` est IDEMPOTENT côté BigBlueButton — c'est ce qui
            // permet au bouton « démarrer OU entrer » de l'extension de ne tenir
            // aucun état local (docblock de RoomMeeting).
            if (is_array($existing)) {
                return [
                    $this->createdXml($existing, 'duplicateWarning', 'This conference was already in existence and may currently be in progress.'),
                    $state,
                ];
            }

            $now = (int) round(microtime(true) * 1000);

            $meeting = [
                'meetingID' => $meetingId,
                'meetingName' => (string) ($params['name'] ?? $meetingId),
                'internalMeetingID' => sha1($meetingId . '|' . $now) . '-' . $now,
                'attendeePW' => (string) ($params['attendeePW'] ?? ''),
                'moderatorPW' => (string) ($params['moderatorPW'] ?? ''),
                'createTime' => $now,
                'createDate' => gmdate('D M d H:i:s \U\T\C Y', intdiv($now, 1000)),
                'voiceBridge' => 70000 + (int) (hexdec(substr(sha1($meetingId), 0, 4)) % 10000),
                'duration' => (int) ($params['duration'] ?? 240),
                'recording' => ($params['record'] ?? 'false') === 'true',
                'running' => ($state['config']['running'] ?? 'on-join') === 'on-create',
                'participantCount' => 0,
                'moderatorCount' => 0,
                'hasUserJoined' => false,
                'startTime' => $now,
            ];

            $state['meetings'][$meetingId] = $meeting;

            // ⚠️ RACCOURCI ASSUMÉ, et c'est la divergence la plus visible de cet
            // outil : un vrai BigBlueButton ne publie un enregistrement que
            // PLUSIEURS MINUTES APRÈS la fin de la conférence, une fois le
            // traitement terminé. Ici il existe dès la création, sinon l'onglet
            // « Enregistrements » ne serait jamais jouable sur l'hôte. La
            // production réelle d'un enregistrement reste une dette de VM.
            $count = (int) ($state['config']['recordings_on_create'] ?? 1);

            for ($i = 0; $i < $count; $i++) {
                $state['recordings'][] = $this->syntheticRecording($meeting, $i);
            }

            return [$this->createdXml($meeting, '', ''), $state];
        }));
    }

    private function createdXml(array $meeting, string $messageKey, string $message): string
    {
        $fields = [
            'returncode' => 'SUCCESS',
            'meetingID' => $meeting['meetingID'],
            'internalMeetingID' => $meeting['internalMeetingID'],
            'parentMeetingID' => 'bbb-none',
            'attendeePW' => $meeting['attendeePW'],
            'moderatorPW' => $meeting['moderatorPW'],
            'createTime' => (string) $meeting['createTime'],
            'voiceBridge' => (string) $meeting['voiceBridge'],
            'dialNumber' => '613-555-1234',
            'createDate' => $meeting['createDate'],
            'hasUserJoined' => $meeting['hasUserJoined'] ? 'true' : 'false',
            'duration' => (string) $meeting['duration'],
            'hasBeenForciblyEnded' => 'false',
        ];

        if ($messageKey !== '') {
            $fields['messageKey'] = $messageKey;
            $fields['message'] = $message;
        }

        return $this->response($fields);
    }

    // -------------------------------------------------------------------------
    //  join
    // -------------------------------------------------------------------------

    /**
     * `join` est le SEUL endpoint que l'extension n'appelle jamais : elle se
     * contente de SIGNER son URL (`joinUrl()`), et c'est le NAVIGATEUR qui la
     * suit. Ce qui arrive ici est donc ce qu'un humain verra.
     *
     * Le mot de passe est retiré de la redirection : il n'a rien à faire dans la
     * barre d'adresse ni dans l'historique, et l'extension elle-même prend soin
     * de ne le laisser que dans un `Location:` suivi immédiatement.
     */
    private function join(array $params, string $prefix): FakeBbbReply
    {
        $meetingId = (string) ($params['meetingID'] ?? '');
        $fullName = (string) ($params['fullName'] ?? '');
        $password = (string) ($params['password'] ?? '');
        $redirect = ($params['redirect'] ?? 'false') === 'true';

        $outcome = $this->store->mutate(function (array $state) use ($meetingId, $password): array {
            $meeting = $state['meetings'][$meetingId] ?? null;

            if (! is_array($meeting)) {
                return [['error' => 'notFound', 'message' => 'We could not find a meeting with that meeting ID'], $state];
            }

            $role = match (true) {
                $password !== '' && $password === $meeting['moderatorPW'] => 'MODERATOR',
                $password !== '' && $password === $meeting['attendeePW'] => 'VIEWER',
                default => null,
            };

            if ($role === null) {
                return [['error' => 'invalidPassword', 'message' => 'You must supply the moderator or attendee password for this meeting.'], $state];
            }

            // ═══════════════════════════════════════════════════════════════
            //  C'EST LA JONCTION QUI OUVRE LE SALON, pas la création.
            //
            //  Un vrai BigBlueButton répond `running=false` tant qu'AUCUN
            //  participant n'a rejoint, même sur un meeting créé. Or
            //  `RoomsController::join()` et `GuestController` refusent l'entrée
            //  quand `isMeetingRunning` est faux : l'ordre « le prof arrive
            //  d'abord » est une contrainte RÉELLE du produit, et l'effacer
            //  ici rendrait vert sur l'hôte un scénario qui peut échouer en
            //  vrai. Le mode `--running=on-create` existe pour les explorations
            //  au `curl`, et il est annoncé comme une divergence.
            // ═══════════════════════════════════════════════════════════════
            $meeting['running'] = true;
            $meeting['hasUserJoined'] = true;
            $meeting['participantCount'] = (int) $meeting['participantCount'] + 1;

            if ($role === 'MODERATOR') {
                $meeting['moderatorCount'] = (int) $meeting['moderatorCount'] + 1;
            }

            $state['meetings'][$meetingId] = $meeting;

            return [['role' => $role, 'name' => $meeting['meetingName']], $state];
        });

        if (isset($outcome['error'])) {
            return FakeBbbReply::xml($this->failure($outcome['error'], $outcome['message']));
        }

        if (! $redirect) {
            return FakeBbbReply::xml($this->response([
                'returncode' => 'SUCCESS',
                'messageKey' => 'successfullyJoined',
                'message' => 'You have joined successfully.',
                'meeting_id' => $meetingId,
                'user_id' => 'w_' . substr(sha1($fullName . $meetingId), 0, 12),
                'auth_token' => substr(sha1('auth' . $fullName), 0, 12),
                'session_token' => substr(sha1('session' . $fullName), 0, 12),
            ]));
        }

        // Le mot de passe ne survit PAS à la redirection.
        return FakeBbbReply::redirect($this->origin . $prefix . '/fake-bbb/conference?' . http_build_query([
            'meetingID' => $meetingId,
            'meetingName' => $outcome['name'],
            'fullName' => $fullName,
            'role' => $outcome['role'],
        ]));
    }

    // -------------------------------------------------------------------------
    //  isMeetingRunning
    // -------------------------------------------------------------------------

    private function isMeetingRunning(array $params): FakeBbbReply
    {
        $meetingId = (string) ($params['meetingID'] ?? '');

        if ($meetingId === '') {
            return FakeBbbReply::xml($this->failure(
                'missingParamMeetingID',
                'You must specify a meeting ID for the meeting.',
            ));
        }

        $state = $this->store->read();
        $meeting = $state['meetings'][$meetingId] ?? null;

        // Un meeting inconnu n'est pas une erreur pour un vrai BBB : c'est
        // simplement « non, il ne tourne pas ».
        return FakeBbbReply::xml($this->response([
            'returncode' => 'SUCCESS',
            'running' => is_array($meeting) && $meeting['running'] === true ? 'true' : 'false',
        ]));
    }

    // -------------------------------------------------------------------------
    //  getMeetings
    // -------------------------------------------------------------------------

    private function getMeetings(): FakeBbbReply
    {
        $state = $this->store->read();
        $traps = (array) ($state['runtime']['traps'] ?? []);

        $meetings = array_values($state['meetings']);
        $load = (int) ($state['runtime']['load'] ?? 0);

        if ($load > 0) {
            // La charge synthétique : un meeting « fantôme » qui porte à lui
            // seul les N participants réclamés. `measureLoad()` somme des
            // `participantCount`, elle ne compte pas les conférences.
            $meetings[] = [
                'meetingID' => 'charge-synthetique',
                'meetingName' => 'Charge synthétique (--load)',
                'internalMeetingID' => 'charge-synthetique-0',
                'attendeePW' => 'ap',
                'moderatorPW' => 'mp',
                'createTime' => 1554729625768,
                'createDate' => 'Mon Apr 08 15:20:25 UTC 2019',
                'voiceBridge' => 70000,
                'duration' => 240,
                'recording' => false,
                'running' => true,
                'participantCount' => $load,
                'moderatorCount' => 1,
                'hasUserJoined' => true,
                'startTime' => 1554729625807,
            ];
        }

        // ═══════════════════════════════════════════════════════════════════
        //  LE PIÈGE QUI N'EST PAS UNE EXCEPTION (review 57.4)
        //
        //  `GetMeetingsResponse::getMeetings()` fait
        //  `foreach ($this->rawXml->meetings->children() …)`. Sur un XML
        //  DÉPOURVU d'élément `meetings`, `children()` rend `null` et le
        //  `foreach` déclenche un AVERTISSEMENT PHP — pas une exception. Aucun
        //  `catch (Throwable)` ne l'attrape : il part dans le journal du
        //  service, et sous PHPUnit (`failOnWarning`) il fait échouer la suite.
        //
        //  Un élément `meetings` VIDE, lui, est parfaitement sûr (vérifié :
        //  `children()` rend alors un SimpleXMLElement). C'est bien l'ABSENCE
        //  de l'élément qui mord — et certains serveurs, dont des Scalelite,
        //  l'omettent quand il n'y a rien à lister.
        // ═══════════════════════════════════════════════════════════════════
        if (in_array('no-meetings-element', $traps, true)) {
            return FakeBbbReply::xml($this->response([
                'returncode' => 'SUCCESS',
                'messageKey' => 'noMeetings',
                'message' => 'no meetings were found on this server',
            ]));
        }

        if ($meetings === []) {
            return FakeBbbReply::xml(
                "<response>\n"
                . "  <returncode>SUCCESS</returncode>\n"
                . "  <meetings></meetings>\n"
                . "  <messageKey>noMeetings</messageKey>\n"
                . "  <message>no meetings were found on this server</message>\n"
                . "</response>\n"
            );
        }

        $body = '';

        foreach ($meetings as $meeting) {
            $body .= $this->meetingXml($meeting);
        }

        return FakeBbbReply::xml(
            "<response>\n  <returncode>SUCCESS</returncode>\n  <meetings>\n"
            . $body
            . "  </meetings>\n</response>\n"
        );
    }

    /** Toutes les balises que `Core\Meeting::__construct` lit, dans l'ordre du vrai. */
    private function meetingXml(array $m): string
    {
        $fields = [
            'meetingName' => (string) $m['meetingName'],
            'meetingID' => (string) $m['meetingID'],
            'internalMeetingID' => (string) $m['internalMeetingID'],
            'createTime' => (string) $m['createTime'],
            'createDate' => (string) $m['createDate'],
            'voiceBridge' => (string) $m['voiceBridge'],
            'dialNumber' => '613-555-1234',
            'attendeePW' => (string) $m['attendeePW'],
            'moderatorPW' => (string) $m['moderatorPW'],
            'running' => $m['running'] ? 'true' : 'false',
            'duration' => (string) $m['duration'],
            'hasUserJoined' => $m['hasUserJoined'] ? 'true' : 'false',
            'recording' => $m['recording'] ? 'true' : 'false',
            'hasBeenForciblyEnded' => 'false',
            'startTime' => (string) $m['startTime'],
            'endTime' => '0',
            'participantCount' => (string) $m['participantCount'],
            'listenerCount' => '0',
            'voiceParticipantCount' => '0',
            'videoCount' => '0',
            'maxUsers' => '0',
            'moderatorCount' => (string) $m['moderatorCount'],
        ];

        $xml = "    <meeting>\n";

        foreach ($fields as $name => $value) {
            $xml .= '      <' . $name . '>' . self::escape($value) . '</' . $name . ">\n";
        }

        // `attendees` et `metadata` sont TOUJOURS émis, comme le vrai les émet.
        //
        // ⚠️ `Meeting::getAttendees()` fait
        // `foreach ($this->rawXml->attendees->attendee …)` — même famille de
        // défaut que `getMeetings()`. Vérifié sur pièce : ce qui compte est la
        // PRÉSENCE de l'élément, pas sa forme (`<attendees></attendees>` et
        // `<attendees/>` sont également sûrs) ; c'est son ABSENCE qui
        // déclencherait l'avertissement. L'extension n'appelle pas cet
        // accesseur : le piège reste latent, et il n'y a aucune raison de
        // l'armer depuis ici.
        $xml .= "      <attendees></attendees>\n";
        $xml .= "      <metadata></metadata>\n";
        $xml .= "      <isBreakout>false</isBreakout>\n";

        return $xml . "    </meeting>\n";
    }

    // -------------------------------------------------------------------------
    //  getRecordings
    // -------------------------------------------------------------------------

    private function getRecordings(array $params): FakeBbbReply
    {
        $state = $this->store->read();
        $traps = (array) ($state['runtime']['traps'] ?? []);

        $meetingFilter = self::csv((string) ($params['meetingID'] ?? ''));
        $recordFilter = self::csv((string) ($params['recordID'] ?? ''));
        $stateFilter = self::csv((string) ($params['state'] ?? ''));

        $records = $state['recordings'];

        if (in_array('recording-no-playback', $traps, true)) {
            // ═══════════════════════════════════════════════════════════════
            //  LE PIÈGE QUI VIDAIT LA LISTE ENTIÈRE (review 57.3)
            //
            //  `Core\Record::__construct` lit
            //  `$xml->playback->format->type->__toString()` sans la moindre
            //  garde. Sans bloc `playback`, `$xml->playback->format` vaut
            //  `null` et l'appel lève une `Error` — vérifié sur pièce. Comme
            //  `GetRecordingsResponse::getRecords()` construit TOUTE la
            //  collection d'un coup, un seul enregistrement bancal effaçait de
            //  l'écran tous les cours des autres.
            //
            //  DEUX anomalies sont injectées, et la distinction est le tout :
            //
            //   - `processing` : écarté par le filtre `state=published` que
            //     l'extension envoie — c'est sa PREMIÈRE ligne de défense, et
            //     ce faux serveur l'honore comme un vrai serveur l'honore ;
            //   - `published` SANS bloc `playback` : il PASSE le filtre et
            //     atteint `Record::__construct`. C'est un état réel et
            //     transitoire d'un vrai BBB (l'enregistrement est publié, ses
            //     formats de lecture ne sont pas encore posés), et c'est lui
            //     qui éprouve la SECONDE ligne de défense — l'hydratation
            //     enregistrement par enregistrement sous `try/catch`.
            // ═══════════════════════════════════════════════════════════════
            $records[] = [
                'recordID' => 'piege-processing-sans-playback',
                'meetingID' => $meetingFilter[0] ?? 'inconnu',
                'name' => 'Enregistrement en cours de traitement',
                'published' => false,
                'state' => 'processing',
                'startTime' => 1462807897120,
                'endTime' => 1462812873004,
                'playback' => null,
            ];

            $records[] = [
                'recordID' => 'piege-published-sans-playback',
                'meetingID' => $meetingFilter[0] ?? 'inconnu',
                'name' => 'Enregistrement publié dont la lecture n\'est pas encore posée',
                'published' => true,
                'state' => 'published',
                'startTime' => 1462807897120,
                'endTime' => 1462812873004,
                'playback' => null,
            ];
        }

        $records = array_values(array_filter($records, static function (array $r) use ($meetingFilter, $recordFilter, $stateFilter): bool {
            if ($meetingFilter !== [] && ! in_array((string) $r['meetingID'], $meetingFilter, true)) {
                return false;
            }

            if ($recordFilter !== [] && ! in_array((string) $r['recordID'], $recordFilter, true)) {
                return false;
            }

            // `state` absent ⇒ un vrai BBB ne rend que les `published`.
            $wanted = $stateFilter === [] ? ['published'] : $stateFilter;

            return in_array('any', $wanted, true) || in_array((string) $r['state'], $wanted, true);
        }));

        // ⚠️ Aucun enregistrement ⇒ le vrai BBB rend SUCCESS + `noRecordings`,
        // et **sans le moindre élément `recordings`**. C'est la réponse NORMALE
        // d'un salon jamais enregistré, et c'est exactement ce que la garde
        // `isset($xml->recordings)` de l'extension attrape.
        if ($records === []) {
            return FakeBbbReply::xml($this->response([
                'returncode' => 'SUCCESS',
                'messageKey' => 'noRecordings',
                'message' => 'There are no recordings for the meeting(s).',
            ]));
        }

        $body = '';

        foreach ($records as $record) {
            $body .= $this->recordXml($record);
        }

        return FakeBbbReply::xml(
            "<response>\n  <returncode>SUCCESS</returncode>\n  <recordings>\n"
            . $body
            . "  </recordings>\n</response>\n"
        );
    }

    private function recordXml(array $r): string
    {
        $xml = "    <recording>\n"
            . '      <recordID>' . self::escape((string) $r['recordID']) . "</recordID>\n"
            . '      <meetingID>' . self::escape((string) $r['meetingID']) . "</meetingID>\n"
            . '      <name><![CDATA[' . (string) $r['name'] . "]]></name>\n"
            . '      <published>' . ($r['published'] ? 'true' : 'false') . "</published>\n"
            . '      <state>' . self::escape((string) $r['state']) . "</state>\n"
            . '      <startTime>' . (string) $r['startTime'] . "</startTime>\n"
            . '      <endTime>' . (string) $r['endTime'] . "</endTime>\n"
            . "      <metadata>\n"
            . '        <meetingName><![CDATA[' . (string) $r['name'] . "]]></meetingName>\n"
            . "        <bbb-origin><![CDATA[SambaEdu 5 (faux serveur)]]></bbb-origin>\n"
            . "      </metadata>\n";

        // `playback` ABSENT — jamais `<playback></playback>` : c'est bien
        // l'absence de l'élément qui casse `Record::__construct`.
        if (is_array($r['playback'] ?? null)) {
            $xml .= "      <playback>\n        <format>\n"
                . '          <type>' . self::escape((string) $r['playback']['type']) . "</type>\n"
                . '          <url>' . self::escape((string) $r['playback']['url']) . "</url>\n"
                . '          <length>' . (int) $r['playback']['length'] . "</length>\n"
                . "        </format>\n      </playback>\n";
        }

        return $xml . "    </recording>\n";
    }

    private function syntheticRecording(array $meeting, int $index): array
    {
        $start = (int) $meeting['createTime'] - (($index + 1) * 3_600_000);
        $length = 12 + ($index * 7);

        return [
            'recordID' => sha1($meeting['meetingID'] . '|rec|' . $index) . '-' . $start,
            'meetingID' => $meeting['meetingID'],
            'name' => $meeting['meetingName'],
            'published' => true,
            'state' => 'published',
            'startTime' => $start,
            'endTime' => $start + ($length * 60_000),
            'playback' => [
                'type' => 'presentation',
                'url' => $this->origin . '/fake-bbb/playback?recordID=' . rawurlencode(sha1($meeting['meetingID'] . '|rec|' . $index) . '-' . $start),
                'length' => $length,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    //  deleteRecordings
    // -------------------------------------------------------------------------

    private function deleteRecordings(array $params): FakeBbbReply
    {
        $wanted = self::csv((string) ($params['recordID'] ?? ''));

        if ($wanted === []) {
            return FakeBbbReply::xml($this->failure(
                'missingParamRecordID',
                'You must specify a recordID.',
            ));
        }

        $deleted = $this->store->mutate(static function (array $state) use ($wanted): array {
            $before = count($state['recordings']);

            $state['recordings'] = array_values(array_filter(
                $state['recordings'],
                static fn (array $r): bool => ! in_array((string) $r['recordID'], $wanted, true),
            ));

            return [$before !== count($state['recordings']), $state];
        });

        if (! $deleted) {
            // Un vrai BBB refuse un recordID qu'il ne connaît pas.
            return FakeBbbReply::xml($this->failure('notFound', 'We could not find recordings'));
        }

        return FakeBbbReply::xml($this->response([
            'returncode' => 'SUCCESS',
            'deleted' => 'true',
        ]));
    }

    // -------------------------------------------------------------------------
    //  Surface de pilotage (hors protocole BBB)
    // -------------------------------------------------------------------------

    /**
     * De quoi rejouer toute la matrice QA sans redémarrer le serveur — ce qui
     * compte pour les scénarios d'équilibrage, où l'on inverse les charges entre
     * deux instances au milieu du parcours.
     */
    private function control(string $path, string $rawQuery): FakeBbbReply
    {
        $params = self::parseQuery($rawQuery);
        $action = substr($path, strlen('/control'));

        return match ($action) {
            '', '/' => FakeBbbReply::text($this->controlStatus()),
            '/load' => FakeBbbReply::text($this->store->mutate(function (array $state) use ($params): array {
                $state['runtime']['load'] = max(0, (int) ($params['n'] ?? 0));

                return [$this->controlStatus($state), $state];
            })),
            '/trap' => FakeBbbReply::text($this->store->mutate(function (array $state) use ($params): array {
                $traps = (array) $state['runtime']['traps'];

                foreach (self::csv((string) ($params['on'] ?? '')) as $name) {
                    if (isset(self::TRAPS[$name]) && ! in_array($name, $traps, true)) {
                        $traps[] = $name;
                    }
                }

                $off = self::csv((string) ($params['off'] ?? ''));
                $traps = in_array('all', $off, true)
                    ? []
                    : array_values(array_diff($traps, $off));

                $state['runtime']['traps'] = $traps;

                if (isset($params['delay'])) {
                    $state['runtime']['delay_ms'] = max(0, (int) $params['delay']);
                }

                return [$this->controlStatus($state), $state];
            })),
            '/reset' => FakeBbbReply::text($this->store->mutate(function (array $state): array {
                $state['meetings'] = [];
                $state['recordings'] = [];

                return ["Salons et enregistrements effacés.\n\n" . $this->controlStatus($state), $state];
            })),
            '/state' => FakeBbbReply::text(json_encode($this->store->read(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"),
            default => FakeBbbReply::text("Inconnu. Voir /control\n", 404),
        };
    }

    private function controlStatus(?array $state = null): string
    {
        $state ??= $this->store->read();
        $traps = (array) $state['runtime']['traps'];

        $lines = [
            'Faux serveur BigBlueButton — pilotage',
            '',
            '  secret            : ' . $state['config']['secret'],
            '  running           : ' . $state['config']['running'],
            '  charge (--load)   : ' . (int) $state['runtime']['load'] . ' participants synthétiques',
            '  pièges actifs     : ' . ($traps === [] ? '(aucun)' : implode(', ', $traps)),
            '  délai (--delay)   : ' . (int) $state['runtime']['delay_ms'] . ' ms',
            '  salons            : ' . count($state['meetings']),
            '  enregistrements   : ' . count($state['recordings']),
            '',
            'Pilotage à chaud :',
            '',
            '  GET /control/load?n=12',
            '  GET /control/trap?on=slow&delay=5000',
            '  GET /control/trap?off=all',
            '  GET /control/reset',
            '  GET /control/state',
            '',
            'Pièges disponibles :',
            '',
        ];

        foreach (self::TRAPS as $name => $description) {
            $lines[] = '  ' . str_pad($name, 24) . $description;
        }

        return implode("\n", $lines) . "\n";
    }

    // -------------------------------------------------------------------------
    //  Pages humaines
    // -------------------------------------------------------------------------

    private function conferencePage(string $rawQuery): FakeBbbReply
    {
        $p = self::parseQuery($rawQuery);
        $role = ($p['role'] ?? '') === 'MODERATOR' ? 'MODÉRATEUR' : 'PARTICIPANT';

        return FakeBbbReply::html($this->page(
            'Conférence (FAUX serveur)',
            '#0b6b3a',
            'Vous seriez ici dans la conférence',
            [
                'Salon' => (string) ($p['meetingName'] ?? ''),
                'meetingID' => (string) ($p['meetingID'] ?? ''),
                'Nom affiché' => (string) ($p['fullName'] ?? ''),
                'Rôle annoncé par le mot de passe' => $role,
            ],
            'Ce que cette page prouve : le mot de passe porté par l\'URL de jonction correspond '
            . 'bien au rôle attendu, et la signature a été acceptée. Ce qu\'elle ne prouve PAS, et '
            . 'qui reste à jouer contre un vrai BigBlueButton : le rôle VÉCU dans la conférence — '
            . 'micro, caméra, partage, expulsion, démarrage de l\'enregistrement.',
        ));
    }

    private function playbackPage(string $rawQuery): FakeBbbReply
    {
        $p = self::parseQuery($rawQuery);

        return FakeBbbReply::html($this->page(
            'Lecture (FAUX serveur)',
            '#1d4ed8',
            'Vous seriez ici dans la lecture de l\'enregistrement',
            ['recordID' => (string) ($p['recordID'] ?? '')],
            'Ce que cette page prouve : le lien de lecture publié par le faux serveur a bien été '
            . 'porté jusqu\'à l\'onglet « Enregistrements » de l\'extension. Ce qu\'elle ne prouve '
            . 'PAS : qu\'un enregistrement a réellement été produit — un vrai BigBlueButton ne le '
            . 'publie que plusieurs minutes après la fin de la conférence.',
        ));
    }

    /** @param array<string, string> $rows */
    private function page(string $title, string $color, string $heading, array $rows, string $note): string
    {
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::escape($title) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;margin:0;background:#f5f5f4;color:#1c1917}'
            . 'header{background:' . $color . ';color:#fff;padding:1.5rem 2rem}'
            . 'header b{display:block;font-size:.8rem;letter-spacing:.15em;text-transform:uppercase;opacity:.85}'
            . 'main{max-width:44rem;margin:2rem auto;padding:0 1.5rem}'
            . 'table{border-collapse:collapse;width:100%;background:#fff;box-shadow:0 1px 2px #0002}'
            . 'th,td{text-align:left;padding:.6rem .9rem;border-bottom:1px solid #e7e5e4;font-size:.95rem}'
            . 'th{width:18rem;color:#57534e;font-weight:600}'
            . 'p.note{margin-top:1.5rem;padding:1rem 1.2rem;background:#fff7ed;border-left:4px solid #ea580c;font-size:.9rem;line-height:1.6}'
            . '</style></head><body>'
            . '<header><b>faux serveur BigBlueButton — outil de dev/QA SambaÉdu 5</b>'
            . '<h1>' . self::escape($heading) . '</h1></header><main><table>';

        foreach ($rows as $label => $value) {
            $html .= '<tr><th>' . self::escape($label) . '</th><td><code>' . self::escape($value) . '</code></td></tr>';
        }

        return $html . '</table><p class="note">' . self::escape($note) . '</p></main></body></html>';
    }

    /**
     * Le 404 d'un mandataire ordinaire — et il n'est PAS décoratif.
     *
     * C'est lui que rencontre un client qui n'a pas normalisé son URL de base et
     * demande `…/bigbluebutton/apiapi/create`. Le fork lève alors
     * `BadResponseException('Bad response, HTTP code: 404')`, que l'extension
     * traduit par « réponse inattendue ». Symptôme exact du défaut de 57.2.
     */
    private function proxyNotFound(string $path): FakeBbbReply
    {
        return FakeBbbReply::html($this->proxyPage(
            '404 Not Found',
            'The requested URL ' . self::escape($path) . ' was not found on this server.',
        ), 404);
    }

    private function proxyPage(string $title, string $body): string
    {
        return "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><head>\n"
            . '<title>' . self::escape($title) . "</title>\n</head><body>\n"
            . '<h1>' . self::escape($title) . "</h1>\n<p>" . $body . "</p>\n<hr>\n"
            . "<address>Apache/2.4.62 (Debian) Server</address>\n</body></html>\n";
    }

    // -------------------------------------------------------------------------
    //  Fabrique XML
    // -------------------------------------------------------------------------

    /** @param array<string, string> $fields */
    private function response(array $fields): string
    {
        $xml = "<response>\n";

        foreach ($fields as $name => $value) {
            $xml .= '  <' . $name . '>' . self::escape($value) . '</' . $name . ">\n";
        }

        return $xml . "</response>\n";
    }

    private function failure(string $messageKey, string $message): string
    {
        return $this->response([
            'returncode' => 'FAILED',
            'messageKey' => $messageKey,
            'message' => $message,
        ]);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return array<string, string> */
    private static function parseQuery(string $rawQuery): array
    {
        $out = [];
        parse_str($rawQuery, $out);

        return array_map(static fn ($v): string => is_array($v) ? '' : (string) $v, $out);
    }

    /** @return list<string> */
    private static function csv(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $s): bool => $s !== ''));
    }
}

// =============================================================================
//  Le routeur (SAPI cli-server)
// =============================================================================

/** Point d'entrée du serveur intégré : `php -S host:port scripts/dev/fake-bbb-server.php`. */
function fake_bbb_route(): void
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT']));

    $store = new FakeBbbStore((string) getenv('FAKE_BBB_STATE'));
    $server = new FakeBbbServer($store, 'http://' . $host);

    $reply = $server->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $query);

    fake_bbb_log($path, $query, $reply);

    http_response_code($reply->status);
    header('Content-Type: ' . $reply->contentType);

    foreach ($reply->headers as $name => $value) {
        header($name . ': ' . $value);
    }

    echo $reply->body;
}

/**
 * **Le journal des appels — et il n'est pas décoratif.**
 *
 * `php -S` ne journalise PAS la ligne de requête quand un script de routage est
 * en place : il n'affiche que « Accepted » / « Closing ». Or le contrôle décisif
 * de l'équilibrage multi-serveurs consiste précisément à lire, dans le journal
 * de chaque serveur, que le PERDANT n'a vu qu'un `getMeetings` et que le
 * GAGNANT a vu `getMeetings` puis `create`. Sans cette ligne, le scénario ne se
 * vérifie pas — il se croit.
 */
function fake_bbb_log(string $path, string $query, FakeBbbReply $reply): void
{
    $label = preg_match('~/api/([A-Za-z]+)$~', $path, $m) === 1
        ? $m[1]
        : (str_ends_with(rtrim($path, '/'), '/api') ? '(racine)' : $path);

    $verdict = '';

    if (preg_match('~<returncode>([A-Z]+)</returncode>~', $reply->body, $m) === 1) {
        $verdict = $m[1];

        if (preg_match('~<messageKey>([^<]+)</messageKey>~', $reply->body, $key) === 1) {
            $verdict .= '/' . $key[1];
        }
    }

    $detail = '';
    parse_str($query, $params);

    foreach (['meetingID', 'recordID'] as $name) {
        if (isset($params[$name]) && is_string($params[$name]) && $params[$name] !== '') {
            $detail = $name . '=' . $params[$name];

            break;
        }
    }

    $stderr = fopen('php://stderr', 'w');

    if (is_resource($stderr)) {
        fwrite($stderr, sprintf(
            "[%s] %-18s %3d  %-22s %s\n",
            date('H:i:s'),
            $label,
            $reply->status,
            $verdict,
            $detail,
        ));
        fclose($stderr);
    }
}

// =============================================================================
//  La CLI
// =============================================================================

/** @param list<string> $argv */
function fake_bbb_main(array $argv): int
{
    $options = [
        'host' => '127.0.0.1',
        'port' => 8088,
        'secret' => 'sekret-de-test',
        'path' => '/bigbluebutton',
        'load' => 0,
        'delay' => 5000,
        'running' => 'on-join',
        'recordings' => 1,
        'traps' => [],
        'state' => '',
        'keep' => false,
    ];

    $args = array_slice($argv, 1);

    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];

        if (str_contains($arg, '=') && str_starts_with($arg, '--')) {
            [$arg, $inline] = explode('=', $arg, 2);
            array_splice($args, $i + 1, 0, [$inline]);
        }

        $next = static function () use (&$i, $args, $arg): string {
            if (! isset($args[$i + 1])) {
                fwrite(STDERR, $arg . " attend une valeur\n");
                exit(1);
            }

            return $args[++$i];
        };

        switch ($arg) {
            case '-h':
            case '--help':
                fake_bbb_help();

                return 0;
            case '--list-traps':
                foreach (FakeBbbServer::TRAPS as $name => $description) {
                    echo '  ', str_pad($name, 24), $description, "\n";
                }

                return 0;
            case '--host': $options['host'] = $next(); break;
            case '--port': $options['port'] = (int) $next(); break;
            case '--secret': $options['secret'] = $next(); break;
            case '--path': $options['path'] = '/' . trim($next(), '/'); break;
            case '--load': $options['load'] = max(0, (int) $next()); break;
            case '--delay': $options['delay'] = max(0, (int) $next()); break;
            case '--recordings': $options['recordings'] = max(0, (int) $next()); break;
            case '--state': $options['state'] = $next(); break;
            case '--keep-state': $options['keep'] = true; break;
            case '--running':
                $value = $next();

                if (! in_array($value, ['on-join', 'on-create'], true)) {
                    fwrite(STDERR, "--running attend « on-join » ou « on-create »\n");

                    return 1;
                }

                $options['running'] = $value;
                break;
            case '--trap':
                foreach (explode(',', $next()) as $name) {
                    $name = trim($name);

                    if (! isset(FakeBbbServer::TRAPS[$name])) {
                        fwrite(STDERR, 'Piège inconnu : ' . $name . " (voir --list-traps)\n");

                        return 1;
                    }

                    $options['traps'][] = $name;
                }
                break;
            default:
                fwrite(STDERR, 'Option inconnue : ' . $arg . " (voir --help)\n");

                return 1;
        }
    }

    if ($options['port'] < 1 || $options['port'] > 65535) {
        fwrite(STDERR, "Port invalide.\n");

        return 1;
    }

    if ($options['secret'] === '') {
        fwrite(STDERR, "Le secret partagé ne peut pas être vide : c'est lui qui signe chaque requête.\n");

        return 1;
    }

    $stateFile = $options['state'] !== ''
        ? $options['state']
        : sys_get_temp_dir() . '/fake-bbb-' . $options['port'] . '.json';

    @mkdir(dirname($stateFile), 0o755, true);

    $store = new FakeBbbStore($stateFile);

    $state = $options['keep'] && is_file($stateFile) ? $store->read() : FakeBbbStore::blank();
    $state['config'] = [
        'secret' => $options['secret'],
        'running' => $options['running'],
        'recordings_on_create' => $options['recordings'],
    ];
    $state['runtime'] = [
        'load' => $options['load'],
        'traps' => array_values(array_unique($options['traps'])),
        'delay_ms' => $options['delay'],
    ];
    $store->write($state);

    // Écouter sur toutes les interfaces est utile — une instance SE5 qui vit
    // ailleurs doit pouvoir joindre le faux serveur — mais `0.0.0.0` ne se colle
    // pas dans un champ « URL du serveur ». La bannière montre alors les URL
    // locales, et dit explicitement quoi y remplacer : DEVINER l'adresse
    // « la bonne » sur une machine multi-interfaces (VPN, ponts de VM, docker)
    // reviendrait à en afficher une fausse avec assurance.
    $anyInterface = in_array($options['host'], ['0.0.0.0', '::'], true);
    $reachable = $anyInterface ? '127.0.0.1' : $options['host'];

    $origin = 'http://' . $reachable . ':' . $options['port'];
    $base = $origin . $options['path'];

    fake_bbb_banner($options, $stateFile, $origin, $base, $anyInterface);

    putenv('FAKE_BBB_STATE=' . $stateFile);

    $command = sprintf(
        '%s -S %s:%d %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($options['host']),
        $options['port'],
        escapeshellarg(__FILE__),
    );

    passthru($command, $code);

    return $code;
}

function fake_bbb_banner(array $options, string $stateFile, string $origin, string $base, bool $anyInterface = false): void
{
    $traps = $options['traps'] === [] ? '(aucun)' : implode(', ', $options['traps']);

    echo <<<TXT

    ┌────────────────────────────────────────────────────────────────────────┐
    │  FAUX serveur BigBlueButton — outil de DEV/QA, jamais un livrable      │
    └────────────────────────────────────────────────────────────────────────┘

      écoute            : {$options['host']}:{$options['port']}
      secret partagé    : {$options['secret']}
      charge annoncée   : {$options['load']} participants synthétiques
      pièges actifs     : {$traps}
      délai (piège slow): {$options['delay']} ms
      état (JSON jetable): {$stateFile}

    ── À COLLER dans /ext/bbb/admin/servers ────────────────────────────────

      {$base}/api        ← la forme que la page d'administration propose
      {$base}            ← l'autre forme acceptée par apiBase()

      Les DEUX doivent donner le même résultat : c'est le défaut de la story
      57.2 que l'on veut voir ABSENT. SE5 ne peut plus le produire — c'est
      apiBase() qui l'en empêche — mais son symptôme se constate à la main :

        curl -o /dev/null -w '%{http_code}\\n' '{$base}/apiapi/create'

      → 404 en HTML, donc BadResponseException côté fork, donc « réponse
      inattendue » côté extension : un serveur sain accusé à tort.

    ── Pilotage à chaud, sans redémarrer ───────────────────────────────────

      curl -s '{$origin}/control'
      curl -s '{$origin}/control/load?n=12'
      curl -s '{$origin}/control/trap?on=no-meetings-element'
      curl -s '{$origin}/control/trap?off=all'
      curl -s '{$origin}/control/reset'

    ── Ce que ce serveur ne prouvera JAMAIS ────────────────────────────────

      le rôle VÉCU dans la conférence · la jonction d'un invité à travers le
      proxy · la production RÉELLE d'un enregistrement · tout le chemin TLS
      (il parle http en clair). Voir docs/qa/domains/extensions.md § 26.

    Ctrl-C pour arrêter.


    TXT;

    if ($anyInterface) {
        echo "    ℹ️  --host {$options['host']} : écoute sur TOUTES les interfaces. Depuis une\n"
            . "       autre machine, remplacer 127.0.0.1 ci-dessus par l'adresse de celle-ci —\n"
            . "       et ne le faire que sur un réseau de confiance : le secret partagé\n"
            . "       circule en clair, ce serveur ne parle pas TLS.\n\n";
    }

    if ($options['running'] === 'on-create') {
        echo "    ⚠️  --running=on-create : DIVERGENCE ASSUMÉE. Un vrai BigBlueButton\n"
            . "       répond running=false tant qu'aucun participant n'a rejoint. Le mode\n"
            . "       fidèle est --running=on-join (défaut) : c'est la jonction du prof,\n"
            . "       suivie par son navigateur, qui ouvre le salon aux élèves.\n\n";
    }
}

function fake_bbb_help(): void
{
    $traps = '';

    foreach (FakeBbbServer::TRAPS as $name => $description) {
        $traps .= '  ' . str_pad($name, 24) . wordwrap($description, 50, "\n" . str_repeat(' ', 26)) . "\n";
    }

    echo <<<TXT
    fake-bbb-server.php — faux serveur d'API BigBlueButton (outil de DEV/QA SE5)

    Rend jouable SUR L'HÔTE, sans déployer un vrai BigBlueButton, la moitié de la
    dette QA de l'extension `extensions/bbb/` : le test de connexion signé, la
    création et la jonction d'un salon, la sonde de charge et l'équilibrage entre
    deux serveurs, la liste et la suppression d'enregistrements — et surtout les
    quatre cas piégeux qui ont réellement mordu pendant l'Epic 57.

    USAGE

      php scripts/dev/fake-bbb-server.php [options]

    OPTIONS

      --host <ip>          interface d'écoute (défaut 127.0.0.1)
      --port <n>           port d'écoute (défaut 8088)
      --secret <s>         secret partagé, celui à saisir dans SE5
                           (défaut « sekret-de-test »)
      --path <chemin>      chemin de base annoncé avant /api (défaut
                           /bigbluebutton). Cosmétique : le routeur accepte
                           N'IMPORTE QUEL préfixe suivi de /api/<méthode>,
                           exactement comme les deux formes qu'apiBase() rend
      --load <n>           charge annoncée : n participants synthétiques dans
                           getMeetings, pour éprouver l'équilibrage (défaut 0)
      --running <mode>     on-join (défaut, FIDÈLE : isMeetingRunning ne devient
                           vrai qu'après une jonction) ou on-create (raccourci
                           pour les explorations au curl — divergence annoncée)
      --recordings <n>     enregistrements fabriqués à chaque création de salon
                           (défaut 1 ; 0 pour un serveur qui n'enregistre rien)
      --trap <noms>        active un ou plusieurs pièges, séparés par des virgules
      --delay <ms>         délai du piège « slow » (défaut 5000)
      --state <chemin>     fichier JSON d'état (défaut /tmp/fake-bbb-<port>.json)
      --keep-state         conserve les salons et enregistrements du run précédent
      --list-traps         liste les pièges et sort
      -h, --help           cette aide

    PIÈGES

    {$traps}
    DEUX INSTANCES, POUR OBSERVER L'ÉQUILIBRAGE

      php scripts/dev/fake-bbb-server.php --port 8088 --load 0   &
      php scripts/dev/fake-bbb-server.php --port 8089 --load 12  &

      Déclarer les deux dans /ext/bbb/admin/servers, puis démarrer un salon : il
      doit partir sur le 8088. Inverser ensuite les charges à chaud —
      `curl -s 'http://127.0.0.1:8088/control/load?n=30'` — et démarrer un autre
      salon : il doit partir sur le 8089.

    CE QUE CET OUTIL NE PROUVERA JAMAIS

      Le rôle VÉCU dans la conférence (micro, caméra, expulsion), la jonction
      d'un invité à travers le proxy de l'instance, la production RÉELLE d'un
      enregistrement, et tout le chemin TLS — il parle http en clair, alors que
      le client vérifie pair et hôte. Ces quatre points restent à jouer contre un
      vrai serveur : docs/qa/domains/extensions.md, Section 26.

    TXT;
}

// =============================================================================
//  Amorçage — et RIEN ne s'exécute quand le fichier est simplement inclus
//  (les tests le chargent pour instancier FakeBbbServer sans réseau).
// =============================================================================

if (PHP_SAPI === 'cli-server') {
    fake_bbb_route();
} elseif (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(fake_bbb_main($argv));
}
