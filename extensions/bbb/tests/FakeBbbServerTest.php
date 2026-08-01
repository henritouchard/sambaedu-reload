<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use BigBlueButton\Core\Meeting;
use BigBlueButton\Parameters\CreateMeetingParameters;
use BigBlueButton\Parameters\DeleteRecordingsParameters;
use BigBlueButton\Parameters\GetRecordingsParameters;
use BigBlueButton\Parameters\IsMeetingRunningParameters;
use BigBlueButton\Parameters\JoinMeetingParameters;
use BigBlueButton\Responses\ApiVersionResponse;
use BigBlueButton\Responses\CreateMeetingResponse;
use BigBlueButton\Responses\DeleteRecordingsResponse;
use BigBlueButton\Responses\GetMeetingsResponse;
use BigBlueButton\Responses\GetRecordingsResponse;
use BigBlueButton\Responses\IsMeetingRunningResponse;
use BigBlueButton\Util\UrlBuilder;
use FakeBbbReply;
use FakeBbbServer;
use FakeBbbStore;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SambaEdu\ExtBbb\Bbb\LiveBbbApiClient;
use SambaEdu\ExtBbb\Bbb\RoomMeeting;
use SimpleXMLElement;
use Throwable;

/**
 * **LA PREUVE QUE LE FAUX SERVEUR EST FIDÈLE — par le vrai fork, pas par des
 * chaînes.**
 *
 * `scripts/dev/fake-bbb-server.php` n'a de valeur que s'il répond ce qu'un vrai
 * BigBlueButton répondrait. Une assertion sur une sous-chaîne ne prouverait
 * rien : elle dirait que le XML contient les lettres attendues, pas que
 * `sambaedu/bigbluebutton-api-php` sait le lire. Chaque test de ce fichier
 * construit donc un objet `Responses\*` de la BIBLIOTHÈQUE à partir du corps
 * rendu par le faux serveur, et vérifie qu'il se comporte comme attendu.
 *
 * Trois familles :
 *
 * 1. **le protocole** — checksum calculé à l'identique, formes d'URL de base,
 *    hydratation des réponses par le fork ;
 * 2. **l'enchaînement** — `create` → `join` → `isMeetingRunning` → `getRecordings`
 *    → `deleteRecordings`, avec un état qui bouge vraiment ;
 * 3. **les pièges** — et ceux-là s'assertent à l'ENVERS : le test ne prouve pas
 *    que tout va bien, il prouve que **la casse se reproduit**. Un piège qui ne
 *    mord plus est un piège cassé, et il rendrait l'outil rassurant à tort.
 *
 * ⚠️ Le script vit dans `scripts/dev/`, HORS du paquet `sambaedu-ext-bbb`
 * (`packaging/build-deb.sh` ne copie que `public`, `src`, `views`,
 * `composer.*`, `manifest.json`). Sur une installation packagée, ces tests se
 * sautent proprement plutôt que d'échouer.
 */
final class FakeBbbServerTest extends TestCase
{
    private const SECRET = 'secret-de-test-9f3b';

    private const ORIGIN = 'http://127.0.0.1:8088';

    private string $stateFile = '';

    /** @var list<resource> */
    private array $processes = [];

    protected function setUp(): void
    {
        $script = self::scriptPath();

        if (! is_file($script)) {
            self::markTestSkipped('scripts/dev/fake-bbb-server.php absent (installation packagée)');
        }

        require_once $script;

        $this->stateFile = tempnam(sys_get_temp_dir(), 'fake-bbb-test-');
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            @proc_terminate($process, SIGKILL);
            @proc_close($process);
        }

        $this->processes = [];

        if ($this->stateFile !== '' && is_file($this->stateFile)) {
            @unlink($this->stateFile);
        }
    }

    private static function scriptPath(): string
    {
        return dirname(__DIR__, 3) . '/scripts/dev/fake-bbb-server.php';
    }

    /**
     * Un serveur prêt à répondre, avec l'état neuf et la configuration voulue.
     *
     * @param  list<string>  $traps
     */
    private function server(array $traps = [], int $load = 0, string $running = 'on-join', int $recordings = 1): FakeBbbServer
    {
        $store = new FakeBbbStore($this->stateFile);

        $state = FakeBbbStore::blank();
        $state['config'] = ['secret' => self::SECRET, 'running' => $running, 'recordings_on_create' => $recordings];
        $state['runtime'] = ['load' => $load, 'traps' => $traps, 'delay_ms' => 0];
        $store->write($state);

        // `sleepEnabled: false` : le piège « slow » se prouve contre un vrai
        // serveur (test HTTP plus bas), pas en tenant la suite 5 s.
        return new FakeBbbServer($store, self::ORIGIN, false);
    }

    /**
     * **Le point de fidélité central** : l'URL est construite par la
     * BIBLIOTHÈQUE, pas par le test. `UrlBuilder` est exactement ce que
     * `LiveBbbApiClient` fait tourner en production ; si le faux serveur accepte
     * ce qu'elle produit, il acceptera ce que l'extension émet.
     */
    private function call(FakeBbbServer $server, string $method, string $query = '', string $base = 'http://127.0.0.1:8088/bigbluebutton/api'): FakeBbbReply
    {
        $url = (new UrlBuilder(self::SECRET, LiveBbbApiClient::apiBase($base)))->buildUrl($method, $query);

        return $server->handle(
            'GET',
            (string) parse_url($url, PHP_URL_PATH),
            (string) parse_url($url, PHP_URL_QUERY),
        );
    }

    private static function xml(FakeBbbReply $reply): SimpleXMLElement
    {
        return new SimpleXMLElement($reply->body);
    }

    // =====================================================================
    //  1. Le protocole
    // =====================================================================

    #[Test]
    public function the_checksum_is_computed_exactly_as_the_library_computes_it(): void
    {
        $server = $this->server();

        // `getMeetings` n'a AUCUN paramètre : `buildQs()` produit alors la query
        // `&checksum=…`, avec un `&` de tête et un segment vide devant. C'est le
        // cas le plus facile à rater — et c'est celui du test de connexion.
        $reply = $this->call($server, 'getMeetings');

        $response = new GetMeetingsResponse(self::xml($reply));

        self::assertTrue($response->success(), 'la query sans paramètre doit valider');
        self::assertSame(200, $reply->status);
        self::assertStringStartsWith('text/xml', $reply->contentType);
    }

    #[Test]
    public function a_wrong_secret_yields_the_protocol_error_that_tells_it_apart_from_unreachable(): void
    {
        $server = $this->server();

        // Signée avec un AUTRE secret : c'est la seule chose qui change.
        $url = (new UrlBuilder('mauvais-secret', 'http://127.0.0.1:8088/bigbluebutton/'))->buildUrl('getMeetings');

        $reply = $server->handle('GET', (string) parse_url($url, PHP_URL_PATH), (string) parse_url($url, PHP_URL_QUERY));

        $response = new GetMeetingsResponse(self::xml($reply));

        self::assertTrue($response->failed());
        self::assertSame('checksumError', $response->getMessageKey());

        // Et c'est bien ce que l'extension traduit par « secret invalide », le
        // diagnostic qui distingue un secret faux d'un serveur absent (57.1).
        $client = new LiveBbbApiClient(static fn (): GetMeetingsResponse => new GetMeetingsResponse(self::xml($reply)));

        self::assertSame('InvalidSecret', $client->testConnection('http://x/bigbluebutton/', 'peu importe')->status->name);
    }

    #[Test]
    public function a_query_carrying_the_forks_own_odd_parameter_name_still_validates(): void
    {
        // ⚠️ Le fork émet, faute de frappe assumée dans `getHTTPQuery()`, un
        // paramètre littéralement nommé `breakoutRoomsPrivateChatEnabled(`.
        // Valider le checksum en ré-encodant les paramètres casserait donc la
        // signature de TOUTE création de salon. Ce test fige que le faux serveur
        // travaille bien sur la query BRUTE.
        $parameters = $this->capturedCreateParameters();
        $query = $parameters->getHTTPQuery();

        self::assertStringContainsString('breakoutRoomsPrivateChatEnabled%28=', $query, 'le fork a-t-il été corrigé ?');

        $reply = $this->call($this->server(), 'create', $query);

        self::assertTrue((new CreateMeetingResponse(self::xml($reply)))->success());
    }

    #[Test]
    public function both_accepted_forms_of_the_base_url_reach_the_same_endpoint(): void
    {
        // Les deux formes que `apiBase()` accepte, et la forme nue sans chemin :
        // c'est précisément le défaut de 57.2 qu'on veut pouvoir voir ABSENT.
        foreach ([
            'http://127.0.0.1:8088/bigbluebutton/api',
            'http://127.0.0.1:8088/bigbluebutton/api/',
            'http://127.0.0.1:8088/bigbluebutton',
            'http://127.0.0.1:8088/bigbluebutton/',
            'http://127.0.0.1:8088',
        ] as $configured) {
            $reply = $this->call($this->server(), 'getMeetings', '', $configured);

            self::assertTrue(
                (new GetMeetingsResponse(self::xml($reply)))->success(),
                $configured,
            );
        }
    }

    #[Test]
    public function the_doubled_api_path_answers_the_very_404_that_the_defect_produced(): void
    {
        // Ce que produirait un client SANS `apiBase()` : `<base>` valant
        // `…/bigbluebutton/api`, la bibliothèque y colle `api/create`.
        $reply = $this->server()->handle('GET', '/bigbluebutton/apiapi/create', 'meetingID=x&checksum=peu-importe');

        self::assertSame(404, $reply->status);
        self::assertStringStartsWith('text/html', $reply->contentType);

        // Le fork lève alors `BadResponseException` sur le code HTTP, et un
        // client qui tenterait quand même de lire le corps n'y trouverait pas
        // de XML. L'extension rapporte « réponse inattendue » — un serveur
        // sain, accusé à tort.
        self::assertTrue($this->failsToParse($reply));
    }

    #[Test]
    public function the_root_endpoint_answers_a_version_without_checking_the_checksum(): void
    {
        // ⚠️ FIDÉLITÉ VOLONTAIRE À UN DÉFAUT DU PROTOCOLE. Un vrai
        // BigBlueButton rend sa version à qui la demande, sans signature.
        // C'est ce qui rendait `server_bbb_is_up()` de SE4 trompeur — il
        // déclarait vert un serveur au secret faux. Masquer ce comportement
        // ferait perdre à l'outil la démonstration de la 57.1.
        $reply = $this->server()->handle('GET', '/bigbluebutton/api', '&checksum=absolument-faux');

        $response = new ApiVersionResponse(self::xml($reply));

        self::assertTrue($response->success(), 'la racine ne vérifie PAS le checksum, comme le vrai');
        self::assertSame('2.0', $response->getApiVersion());
        self::assertNotSame('', $response->getBbbVersion());
    }

    #[Test]
    public function an_endpoint_outside_the_six_the_extension_uses_is_refused(): void
    {
        $reply = $this->call($this->server(), 'getMeetingInfo', 'meetingID=x');

        $xml = self::xml($reply);

        self::assertSame('FAILED', (string) $xml->returncode);
        self::assertSame('unsupportedRequest', (string) $xml->messageKey);
    }

    // =====================================================================
    //  2. L'enchaînement
    // =====================================================================

    #[Test]
    public function create_is_hydrated_by_the_library_and_is_idempotent(): void
    {
        $server = $this->server();
        $query = $this->capturedCreateParameters()->getHTTPQuery();

        $first = new CreateMeetingResponse(self::xml($this->call($server, 'create', $query)));

        self::assertTrue($first->success());
        self::assertSame('salon-jeton-opaque', $first->getMeetingId());
        self::assertSame('mot-de-passe-participant', $first->getAttendeePassword());
        self::assertSame('mot-de-passe-moderateur', $first->getModeratorPassword());
        self::assertSame(LiveBbbApiClient::MEETING_DURATION_MINUTES, $first->getDuration());
        self::assertGreaterThan(0.0, $first->getCreationTime());
        self::assertNotSame('', $first->getInternalMeetingId());
        self::assertFalse($first->hasBeenForciblyEnded());

        // `createMeeting` est IDEMPOTENT côté BigBlueButton : c'est ce qui rend
        // possible le bouton « démarrer OU entrer » sans état local.
        $second = new CreateMeetingResponse(self::xml($this->call($server, 'create', $query)));

        self::assertTrue($second->success());
        self::assertSame('duplicateWarning', $second->getMessageKey());
        self::assertSame($first->getInternalMeetingId(), $second->getInternalMeetingId());
    }

    #[Test]
    public function a_meeting_only_starts_running_once_somebody_has_joined(): void
    {
        $server = $this->server();
        $this->call($server, 'create', $this->capturedCreateParameters()->getHTTPQuery());

        $running = fn (): IsMeetingRunningResponse => new IsMeetingRunningResponse(self::xml($this->call(
            $server,
            'isMeetingRunning',
            (new IsMeetingRunningParameters('salon-jeton-opaque'))->getHTTPQuery(),
        )));

        // ⚠️ C'est le comportement d'un VRAI BigBlueButton, et il porte une
        // contrainte réelle du produit : `RoomsController::join()` et
        // `GuestController` refusent l'entrée tant que le salon ne tourne pas.
        // L'ordre « le professeur arrive d'abord » n'est pas décoratif.
        self::assertTrue($running()->success());
        self::assertFalse($running()->isRunning(), 'créé n\'est pas ouvert');

        $join = new JoinMeetingParameters('salon-jeton-opaque', 'Prof Martin', 'mot-de-passe-moderateur');
        $join->setRedirect(true);

        $reply = $this->call($server, 'join', $join->getHTTPQuery());

        self::assertSame(302, $reply->status);
        self::assertStringContainsString('role=MODERATOR', $reply->headers['Location']);

        // Le mot de passe ne survit PAS à la redirection : il n'a rien à faire
        // dans la barre d'adresse ni dans l'historique du navigateur.
        self::assertStringNotContainsString('mot-de-passe-moderateur', $reply->headers['Location']);

        self::assertTrue($running()->isRunning(), 'la jonction ouvre le salon');
    }

    #[Test]
    public function a_wrong_password_on_join_is_refused_the_way_the_real_server_refuses_it(): void
    {
        $server = $this->server();
        $this->call($server, 'create', $this->capturedCreateParameters()->getHTTPQuery());

        $join = new JoinMeetingParameters('salon-jeton-opaque', 'Intrus', 'pas-le-bon');
        $join->setRedirect(true);

        $xml = self::xml($this->call($server, 'join', $join->getHTTPQuery()));

        self::assertSame('FAILED', (string) $xml->returncode);
        self::assertSame('invalidPassword', (string) $xml->messageKey);
    }

    #[Test]
    public function get_meetings_is_hydrated_into_real_meeting_objects_and_sums_the_load(): void
    {
        $server = $this->server(load: 12);
        $this->call($server, 'create', $this->capturedCreateParameters()->getHTTPQuery());

        $response = new GetMeetingsResponse(self::xml($this->call($server, 'getMeetings')));

        self::assertTrue($response->success());

        $meetings = $response->getMeetings();

        self::assertCount(2, $meetings, 'le salon créé + la charge synthétique');
        self::assertContainsOnlyInstancesOf(Meeting::class, $meetings);

        $total = 0;

        foreach ($meetings as $meeting) {
            // Les mêmes accesseurs que `measureLoad()` : s'ils lèvent ici, ils
            // lèveraient en production.
            $total += $meeting->getParticipantCount();
            self::assertNotSame('', $meeting->getMeetingId());
            self::assertNotSame('', $meeting->getInternalMeetingId());
            self::assertIsBool($meeting->isRunning());
            self::assertGreaterThan(0.0, $meeting->getCreationTime());
        }

        self::assertSame(12, $total, '--load porte la charge annoncée');
    }

    #[Test]
    public function an_empty_server_answers_an_EMPTY_meetings_element_and_that_is_safe(): void
    {
        // **Contrôle positif du piège `no-meetings-element`.** Sans lui, le test
        // du piège passerait tout aussi bien si le faux serveur omettait
        // TOUJOURS l'élément. Vérifié sur pièce : `children()` d'un élément vide
        // rend un `SimpleXMLElement`, pas `null` — l'élément vide est sûr, c'est
        // son ABSENCE qui mord.
        $response = new GetMeetingsResponse(self::xml($this->call($this->server(), 'getMeetings')));

        self::assertTrue($response->success());
        self::assertTrue(isset($response->getRawXml()->meetings));
        self::assertSame('noMeetings', $response->getMessageKey());

        $warning = null;
        set_error_handler(static function (int $n, string $s) use (&$warning): bool {
            $warning = $s;

            return true;
        });

        try {
            self::assertSame([], $response->getMeetings());
        } finally {
            restore_error_handler();
        }

        self::assertNull($warning, 'un élément `meetings` vide ne doit RIEN déclencher');
    }

    #[Test]
    public function recordings_are_hydrated_by_the_library_then_really_deleted(): void
    {
        $server = $this->server(recordings: 2);
        $this->call($server, 'create', $this->capturedCreateParameters()->getHTTPQuery());

        $parameters = new GetRecordingsParameters();
        $parameters->setMeetingId('salon-jeton-opaque');
        $parameters->setState(LiveBbbApiClient::RECORDINGS_STATE);

        $response = new GetRecordingsResponse(self::xml($this->call($server, 'getRecordings', $parameters->getHTTPQuery())));

        self::assertTrue($response->success());

        // `getRecords()` construit TOUTE la collection d'un coup : s'il passe,
        // c'est que chaque enregistrement porte son bloc `playback` complet.
        $records = $response->getRecords();

        self::assertCount(2, $records);

        foreach ($records as $record) {
            self::assertSame('salon-jeton-opaque', $record->getMeetingId());
            self::assertSame('published', $record->getState());
            self::assertTrue($record->isPublished());
            self::assertSame('presentation', $record->getPlaybackType());
            self::assertStringContainsString('/fake-bbb/playback', $record->getPlaybackUrl());
            self::assertGreaterThan(0, $record->getPlaybackLength());
            self::assertGreaterThan(0.0, $record->getStartTime());
        }

        // …et la suppression retire VRAIMENT.
        $recordId = $records[0]->getRecordId();

        $deleted = new DeleteRecordingsResponse(self::xml($this->call(
            $server,
            'deleteRecordings',
            (new DeleteRecordingsParameters($recordId))->getHTTPQuery(),
        )));

        self::assertTrue($deleted->success());
        self::assertTrue($deleted->isDeleted());

        $after = new GetRecordingsResponse(self::xml($this->call($server, 'getRecordings', $parameters->getHTTPQuery())));

        self::assertCount(1, $after->getRecords());

        // Rejouer la même suppression : un vrai BBB ne connaît plus l'identifiant.
        $again = new DeleteRecordingsResponse(self::xml($this->call(
            $server,
            'deleteRecordings',
            (new DeleteRecordingsParameters($recordId))->getHTTPQuery(),
        )));

        self::assertTrue($again->failed());
        self::assertSame('notFound', $again->getMessageKey());
    }

    #[Test]
    public function a_room_that_never_recorded_gets_the_normal_answer_without_a_recordings_element(): void
    {
        // Réponse NORMALE d'un salon jamais enregistré : SUCCESS, `noRecordings`
        // et **aucun** élément `recordings`. C'est exactement ce que la garde
        // `isset($xml->recordings)` de l'extension attrape (57.3).
        $server = $this->server(recordings: 0);
        $this->call($server, 'create', $this->capturedCreateParameters()->getHTTPQuery());

        $parameters = new GetRecordingsParameters();
        $parameters->setMeetingId('salon-jeton-opaque');
        $parameters->setState(LiveBbbApiClient::RECORDINGS_STATE);

        $response = new GetRecordingsResponse(self::xml($this->call($server, 'getRecordings', $parameters->getHTTPQuery())));

        self::assertTrue($response->success());
        self::assertSame('noRecordings', $response->getMessageKey());
        self::assertFalse(isset($response->getRawXml()->recordings));

        $client = new LiveBbbApiClient(null, null, null, static fn (): GetRecordingsResponse => $response);

        $result = $client->getRecordings('http://x/bigbluebutton/', self::SECRET, ['salon-jeton-opaque']);

        self::assertSame('Ok', $result->outcome->name);
        self::assertSame([], $result->items);
    }

    // =====================================================================
    //  3. Les pièges — et ils s'assertent À L'ENVERS
    // =====================================================================

    #[Test]
    public function the_missing_meetings_element_trap_really_produces_the_php_warning(): void
    {
        // ⚠️ CE TEST PROUVE QUE LA CASSE SE REPRODUIT, pas que tout va bien.
        // `GetMeetingsResponse::getMeetings()` fait
        // `foreach ($this->rawXml->meetings->children() …)` ; `children()` sur un
        // enfant ABSENT rend `null`, et le `foreach` déclenche un
        // AVERTISSEMENT PHP — jamais une exception. Aucun `catch (Throwable)` ne
        // l'attrape : c'est le défaut que la review 57.4 a qualifié de
        // « premier invisible ».
        $server = $this->server(traps: ['no-meetings-element']);
        $this->call($server, 'create', $this->capturedCreateParameters()->getHTTPQuery());

        $response = new GetMeetingsResponse(self::xml($this->call($server, 'getMeetings')));

        self::assertTrue($response->success(), 'le serveur répond SUCCESS : rien ne signale l\'anomalie');
        self::assertFalse(isset($response->getRawXml()->meetings), 'l\'élément doit être ABSENT, pas vide');

        $warning = null;
        set_error_handler(static function (int $n, string $s) use (&$warning): bool {
            $warning = $s;

            return true;
        });

        try {
            $response->getMeetings();
        } finally {
            restore_error_handler();
        }

        self::assertNotNull($warning, 'le piège doit MORDRE : sans avertissement, il ne prouve rien');
        self::assertStringContainsString('foreach()', (string) $warning);

        // Et la garde `meetingsOf()` de l'extension, elle, tient : même XML,
        // aucun avertissement, et une charge de zéro — un serveur sans la
        // moindre conférence est le meilleur candidat possible.
        $silent = new GetMeetingsResponse(self::xml($this->call($server, 'getMeetings')));
        $client = new LiveBbbApiClient(static fn (): GetMeetingsResponse => $silent);

        $seen = null;
        set_error_handler(static function (int $n, string $s) use (&$seen): bool {
            $seen = $s;

            return true;
        });

        try {
            $load = $client->measureLoad('http://x/bigbluebutton/', self::SECRET);
        } finally {
            restore_error_handler();
        }

        self::assertNull($seen, 'la garde de l\'extension doit rendre le piège muet');
        self::assertSame('Ok', $load->outcome->name);
        self::assertSame(0, $load->participants);
    }

    #[Test]
    public function the_recording_without_playback_trap_really_blows_up_the_whole_hydration(): void
    {
        // ⚠️ CE TEST PROUVE QUE LA CASSE SE REPRODUIT. `Record::__construct` lit
        // `$xml->playback->format->type->__toString()` sans garde : un seul
        // enregistrement sans bloc `playback` fait échouer `getRecords()` — donc
        // viderait de l'écran TOUS les cours des autres (review 57.3).
        $server = $this->server(traps: ['recording-no-playback'], recordings: 1);
        $this->call($server, 'create', $this->capturedCreateParameters()->getHTTPQuery());

        $parameters = new GetRecordingsParameters();
        $parameters->setMeetingId('salon-jeton-opaque');
        $parameters->setState(LiveBbbApiClient::RECORDINGS_STATE);

        $response = new GetRecordingsResponse(self::xml($this->call($server, 'getRecordings', $parameters->getHTTPQuery())));

        // PREMIÈRE ligne de défense honorée par le faux serveur comme par un
        // vrai : `state=published` écarte l'enregistrement `processing`.
        $states = [];

        foreach ($response->getRawXml()->recordings->children() as $record) {
            $states[] = (string) $record->state;
        }

        self::assertSame(['published', 'published'], $states, 'le filtre state=published doit écarter le `processing`');

        // …mais l'enregistrement PUBLIÉ dont la lecture n'est pas encore posée
        // passe le filtre, atteint le fork, et l'emporte tout entier.
        $exploded = false;

        set_error_handler(static fn (): bool => true);

        try {
            $response->getRecords();
        } catch (Throwable) {
            $exploded = true;
        } finally {
            restore_error_handler();
        }

        self::assertTrue($exploded, 'le piège doit MORDRE : sans casse, il ne prouve rien');

        // SECONDE ligne de défense : l'hydratation sous garde de l'extension
        // survit et rend l'enregistrement lisible, en écartant le seul fautif.
        $client = new LiveBbbApiClient(null, null, null, static fn (): GetRecordingsResponse => $response);

        $result = $client->getRecordings('http://x/bigbluebutton/', self::SECRET, ['salon-jeton-opaque']);

        self::assertSame('Ok', $result->outcome->name);
        self::assertCount(1, $result->items, 'le cours des autres survit au XML bancal');
        self::assertStringContainsString('/fake-bbb/playback', $result->items[0]->playbackUrl);
    }

    #[Test]
    public function the_html_trap_serves_a_body_that_is_not_xml_at_all(): void
    {
        $server = $this->server(traps: ['html']);

        $reply = $this->call($server, 'getMeetings');

        // 200, donc PAS de `BadResponseException` : c'est le cas du mandataire
        // qui répond « à la place » du serveur, et il se distingue du 404.
        self::assertSame(200, $reply->status);
        self::assertStringStartsWith('text/html', $reply->contentType);

        self::assertTrue(
            $this->failsToParse($reply),
            'le fork doit échouer à parser : c\'est tout l\'objet du piège',
        );
    }

    /**
     * `new SimpleXMLElement()` sur un corps qui n'est pas du XML lève — mais
     * **libxml crache d'abord une bordée d'avertissements PHP**, qu'aucun
     * `catch` n'attrape et que `failOnWarning` ferait remonter en échec de
     * suite. Ils sont neutralisés ICI, et seulement ici : c'est le comportement
     * réel du fork face à une page de mandataire, et il vaut d'être noté.
     */
    private function failsToParse(FakeBbbReply $reply): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            self::xml($reply);

            return false;
        } catch (Throwable) {
            return true;
        } finally {
            restore_error_handler();
        }
    }

    // =====================================================================
    //  4. Contre un serveur RÉELLEMENT en écoute
    // =====================================================================

    /**
     * **Le seul test qui prouve la chaîne entière** : `LiveBbbApiClient` →
     * cURL → HTTP → le faux serveur, sans aucune doublure. Les tests
     * précédents prouvent la FORME des réponses ; celui-ci prouve qu'elles
     * arrivent, signées, à travers un vrai socket.
     */
    #[Test]
    #[IgnoreDeprecations]
    public function the_real_client_drives_a_really_listening_fake_server(): void
    {
        $port = $this->boot();

        $client = new LiveBbbApiClient();

        foreach ([
            'http://127.0.0.1:' . $port . '/bigbluebutton/api',
            'http://127.0.0.1:' . $port . '/bigbluebutton',
        ] as $base) {
            self::assertSame('Ok', $client->testConnection($base, self::SECRET)->status->name, $base);
        }

        $base = 'http://127.0.0.1:' . $port . '/bigbluebutton/api';

        self::assertSame('InvalidSecret', $client->testConnection($base, 'pas-le-bon')->status->name);

        $meeting = new RoomMeeting('salon-http', 'Cours de maths', 'ap', 'mp', 'http://se5.test/ext/bbb/rooms');

        self::assertSame('Ok', $client->createMeeting($base, self::SECRET, $meeting)->outcome->name);
        self::assertFalse($client->isMeetingRunning($base, self::SECRET, 'salon-http')->running);

        // Le navigateur du professeur suit l'URL de jonction ; c'est ce geste
        // qui ouvre le salon.
        $joinUrl = $client->joinUrl($base, self::SECRET, 'salon-http', 'Prof Martin', 'mp');

        self::assertSame(302, $this->fetchStatus($joinUrl));

        $running = $client->isMeetingRunning($base, self::SECRET, 'salon-http');

        self::assertSame('Ok', $running->outcome->name);
        self::assertTrue($running->running);

        $load = $client->measureLoad($base, self::SECRET);

        self::assertSame('Ok', $load->outcome->name);
        self::assertSame(1, $load->participants, 'le professeur qui vient d\'entrer');

        $recordings = $client->getRecordings($base, self::SECRET, ['salon-http']);

        self::assertSame('Ok', $recordings->outcome->name);
        self::assertCount(1, $recordings->items);

        // La page de lecture publiée par le faux serveur répond vraiment.
        self::assertSame(200, $this->fetchStatus($recordings->items[0]->playbackUrl));

        self::assertSame('Ok', $client->deleteRecording($base, self::SECRET, $recordings->items[0]->recordId)->outcome->name);
        self::assertSame([], $client->getRecordings($base, self::SECRET, ['salon-http'])->items);

        // Et le chemin doublé — le défaut de 57.2 — répond bien 404.
        self::assertSame(404, $this->fetchStatus($base . 'api/getMeetings'));
    }

    /**
     * **La borne de sonde et la borne totale, mesurées.**
     *
     * Une réponse retardée de 4 s est au-delà des 3 s de `PROBE_TIMEOUT_MS` et
     * en deçà des 8 s de `TOTAL_TIMEOUT_MS` : le MÊME appel `getMeetings` doit
     * donc échouer comme sonde et réussir comme test de connexion. C'est la
     * seule façon d'observer sur l'hôte que les deux bornes sont bien deux
     * bornes distinctes.
     */
    #[Test]
    #[IgnoreDeprecations]
    public function the_slow_trap_separates_the_probe_bound_from_the_total_bound(): void
    {
        $port = $this->boot(['slow'], delayMs: 4000);

        $client = new LiveBbbApiClient();
        $base = 'http://127.0.0.1:' . $port . '/bigbluebutton/api';

        self::assertSame('Unreachable', $client->measureLoad($base, self::SECRET)->outcome->name);
        self::assertSame('Ok', $client->testConnection($base, self::SECRET)->status->name);
    }

    #[Test]
    public function the_command_line_refuses_an_unknown_trap_and_documents_the_real_ones(): void
    {
        $help = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::scriptPath()) . ' --help', $help, $status);

        self::assertSame(0, $status);
        self::assertStringContainsString('recording-no-playback', implode("\n", $help));

        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::scriptPath()) . ' --trap inexistant 2>/dev/null', $out, $status);

        self::assertSame(1, $status, 'un piège inconnu doit être refusé, pas ignoré en silence');
    }

    // =====================================================================
    //  Outillage
    // =====================================================================

    /**
     * Démarre le faux serveur sur un port libre et attend qu'il réponde.
     *
     * `php -S` est lancé DIRECTEMENT plutôt qu'à travers la CLI du script : la
     * CLI démarre le serveur par `passthru()`, et tuer le processus enveloppant
     * laisserait le petit-fils en écoute jusqu'à la fin de la suite.
     *
     * @param  list<string>  $traps
     */
    private function boot(array $traps = [], int $delayMs = 0): int
    {
        $port = self::freePort();

        $store = new FakeBbbStore($this->stateFile);

        $state = FakeBbbStore::blank();
        $state['config'] = ['secret' => self::SECRET, 'running' => 'on-join', 'recordings_on_create' => 1];
        $state['runtime'] = ['load' => 0, 'traps' => $traps, 'delay_ms' => $delayMs];
        $store->write($state);

        $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, self::scriptPath()],
            $descriptors,
            $pipes,
            null,
            ['FAKE_BBB_STATE' => $this->stateFile] + getenv(),
        );

        if (! is_resource($process)) {
            self::markTestSkipped('impossible de démarrer le serveur intégré de PHP');
        }

        $this->processes[] = $process;

        // Le piège « slow » retarde /api mais PAS /control : c'est la sonde de
        // disponibilité qui ne doit rien attendre.
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

            if (is_resource($socket)) {
                fclose($socket);

                return $port;
            }

            usleep(50_000);
        }

        self::markTestSkipped('le serveur intégré n\'a pas répondu sur le port ' . $port);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            self::markTestSkipped('aucun port libre');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function fetchStatus(string $url): int
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return $status;
    }

    /**
     * Les paramètres de création RÉELLEMENT construits par l'extension —
     * capturés par le transport injectable, sans réflexion ni recopie.
     *
     * Recopier ici la liste des `set*()` de `LiveBbbApiClient::createParameters()`
     * ferait exactement la « règle recopiée » qui a mordu trois fois sur cet
     * epic : le test finirait par valider une requête que l'extension n'émet
     * plus.
     */
    private function capturedCreateParameters(): CreateMeetingParameters
    {
        $captured = null;

        $client = new LiveBbbApiClient(
            null,
            static function (string $b, string $s, CreateMeetingParameters $p) use (&$captured): CreateMeetingResponse {
                $captured = $p;

                return new CreateMeetingResponse(new SimpleXMLElement('<response><returncode>SUCCESS</returncode></response>'));
            },
        );

        $client->createMeeting(
            'http://127.0.0.1:8088/bigbluebutton/',
            self::SECRET,
            new RoomMeeting(
                'salon-jeton-opaque',
                'Cours de maths & sciences',
                'mot-de-passe-participant',
                'mot-de-passe-moderateur',
                'http://se5.test/ext/bbb/rooms',
            ),
        );

        self::assertInstanceOf(CreateMeetingParameters::class, $captured);

        return $captured;
    }
}
