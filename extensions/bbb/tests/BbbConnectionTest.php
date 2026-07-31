<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use BigBlueButton\Exceptions\BadResponseException;
use BigBlueButton\Parameters\CreateMeetingParameters;
use BigBlueButton\Responses\CreateMeetingResponse;
use BigBlueButton\Responses\GetMeetingsResponse;
use BigBlueButton\Responses\IsMeetingRunningResponse;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SambaEdu\ExtBbb\Bbb\CallOutcome;
use SambaEdu\ExtBbb\Bbb\ConnectionStatus;
use SambaEdu\ExtBbb\Bbb\LiveBbbApiClient;
use SambaEdu\ExtBbb\Bbb\RoomMeeting;
use SimpleXMLElement;

/**
 * Story 57.1 — **LE MAPPING DU TEST DE CONNEXION (AC2), SANS RÉSEAU.**
 *
 * L'AC exige un « retour explicite ». Ce qui est prouvé ici est justement la
 * partie qu'un test d'intégration ne prouverait pas commodément : que chaque
 * situation réelle du serveur BBB se traduit par le BON diagnostic. Les réponses
 * sont de VRAIS objets de la bibliothèque, construits depuis le XML qu'un
 * serveur BigBlueButton renvoie — seul le transport est remplacé.
 *
 * L'appel réel contre un serveur BBB (ou un Scalelite) reste une dette de
 * validation VM, explicitement listée par la story.
 */
final class BbbConnectionTest extends TestCase
{
    private function clientReturning(string $xml): LiveBbbApiClient
    {
        return new LiveBbbApiClient(
            static fn (): GetMeetingsResponse => new GetMeetingsResponse(new SimpleXMLElement($xml))
        );
    }

    private function clientThrowing(\Throwable $e): LiveBbbApiClient
    {
        return new LiveBbbApiClient(static function () use ($e): GetMeetingsResponse {
            throw $e;
        });
    }

    #[Test]
    public function a_successful_call_reports_the_number_of_running_meetings(): void
    {
        $client = $this->clientReturning(<<<'XML'
            <response>
                <returncode>SUCCESS</returncode>
                <meetings>
                    <meeting><meetingID>salon-1</meetingID><meetingName>3A</meetingName></meeting>
                    <meeting><meetingID>salon-2</meetingID><meetingName>4B</meetingName></meeting>
                </meetings>
            </response>
        XML);

        $result = $client->testConnection('https://bbb.example.test/api', 'secret');

        self::assertSame(ConnectionStatus::Ok, $result->status);
        self::assertSame(2, $result->meetingCount);
        self::assertStringContainsString('2 réunion', $result->message);
    }

    #[Test]
    public function a_successful_call_without_any_meeting_is_still_a_success(): void
    {
        // Contrôle POSITIF adossé au précédent : « aucune réunion » n'est pas un
        // échec, et c'est l'état NORMAL d'un serveur qu'on vient de déclarer.
        $client = $this->clientReturning(
            '<response><returncode>SUCCESS</returncode><meetings></meetings></response>'
        );

        $result = $client->testConnection('https://bbb.example.test/api', 'secret');

        self::assertTrue($result->isOk());
        self::assertSame(0, $result->meetingCount);
    }

    #[Test]
    public function a_checksum_error_is_reported_as_an_invalid_secret(): void
    {
        // LE cas que le legacy ne détectait pas : `server_bbb_is_up()` faisait un
        // simple GET sur l'URL de base, donc un secret erroné passait la
        // « validation » et n'échouait qu'au premier salon créé.
        $client = $this->clientReturning(<<<'XML'
            <response>
                <returncode>FAILED</returncode>
                <messageKey>checksumError</messageKey>
                <message>Checksums do not match</message>
            </response>
        XML);

        $result = $client->testConnection('https://bbb.example.test/api', 'mauvais-secret');

        self::assertSame(ConnectionStatus::InvalidSecret, $result->status);
        self::assertStringContainsString('Secret invalide', $result->message);
    }

    #[Test]
    public function another_api_failure_is_reported_as_an_unexpected_response(): void
    {
        $client = $this->clientReturning(<<<'XML'
            <response>
                <returncode>FAILED</returncode>
                <messageKey>unsupportedRequest</messageKey>
                <message>This request is not supported.</message>
            </response>
        XML);

        $result = $client->testConnection('https://bbb.example.test/api', 'secret');

        self::assertSame(ConnectionStatus::InvalidResponse, $result->status);
        self::assertStringContainsString('unsupportedRequest', $result->message);
    }

    #[Test]
    public function a_transport_failure_is_reported_as_unreachable(): void
    {
        // Hôte inconnu, port fermé, TLS refusé, délai dépassé : la bibliothèque
        // lève une RuntimeException portant le message cURL.
        $client = $this->clientThrowing(new RuntimeException('Unhandled curl error: Could not resolve host'));

        $result = $client->testConnection('https://absent.example.test/api', 'secret');

        self::assertSame(ConnectionStatus::Unreachable, $result->status);
        self::assertStringNotContainsString('curl', $result->message, 'aucun détail de transport dans la page');
    }

    #[Test]
    public function a_non_2xx_http_response_is_an_unexpected_response_not_an_outage(): void
    {
        // Une URL de base erronée (page d'accueil, reverse-proxy, 404) : quelque
        // chose répond. Dire « injoignable » enverrait l'administrateur vérifier
        // son réseau au lieu de son URL.
        $client = $this->clientThrowing(new BadResponseException('Bad response, HTTP code: 404'));

        $result = $client->testConnection('https://bbb.example.test/mauvais-chemin', 'secret');

        self::assertSame(ConnectionStatus::InvalidResponse, $result->status);
    }

    #[Test]
    public function an_unparseable_body_is_an_unexpected_response(): void
    {
        // `new SimpleXMLElement()` sur du HTML : le serveur parle, mais pas le
        // protocole attendu.
        $client = $this->clientThrowing(new \Exception('String could not be parsed as XML'));

        $result = $client->testConnection('https://portail.example.test/', 'secret');

        self::assertSame(ConnectionStatus::InvalidResponse, $result->status);
    }

    #[Test]
    public function no_result_message_ever_carries_the_secret(): void
    {
        // Le secret partagé ne sort JAMAIS du serveur : ni dans une page, ni
        // dans une URL, ni dans un message d'erreur.
        $secret = 'secret-ultra-confidentiel-9f3b';

        $results = [
            $this->clientReturning('<response><returncode>SUCCESS</returncode><meetings></meetings></response>')
                ->testConnection('https://bbb.example.test/api', $secret),
            $this->clientReturning('<response><returncode>FAILED</returncode><messageKey>checksumError</messageKey></response>')
                ->testConnection('https://bbb.example.test/api', $secret),
            $this->clientThrowing(new RuntimeException('curl error with ' . $secret))
                ->testConnection('https://bbb.example.test/api', $secret),
        ];

        foreach ($results as $result) {
            self::assertStringNotContainsString($secret, $result->message);
        }
    }

    #[Test]
    public function the_outgoing_call_is_bounded_in_time(): void
    {
        // La garde de conception de la stack retenue : le serveur intégré de PHP
        // est mono-processus, un serveur BBB lent gèlerait toute l'extension —
        // sonde de santé comprise.
        self::assertSame(2000, LiveBbbApiClient::CONNECT_TIMEOUT_MS, 'valeur du legacy, conservée');
        self::assertLessThanOrEqual(8000, LiveBbbApiClient::TOTAL_TIMEOUT_MS);
    }

    // =====================================================================
    // Story 57.2 — Ouverture d'un meeting
    // =====================================================================

    /** @param  list<CreateMeetingParameters>  $captured */
    private function clientCreating(string $xml, array &$captured = []): LiveBbbApiClient
    {
        return new LiveBbbApiClient(
            null,
            static function (string $url, string $secret, CreateMeetingParameters $p) use ($xml, &$captured): CreateMeetingResponse {
                $captured[] = $p;

                return new CreateMeetingResponse(new SimpleXMLElement($xml));
            },
        );
    }

    private function clientProbing(string $xml): LiveBbbApiClient
    {
        return new LiveBbbApiClient(
            null,
            null,
            static fn (): IsMeetingRunningResponse => new IsMeetingRunningResponse(new SimpleXMLElement($xml)),
        );
    }

    private function meeting(string $logoutUrl = 'https://se5.example.test/ext/bbb/rooms'): RoomMeeting
    {
        return new RoomMeeting(
            meetingId: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            name: 'Cours de mathématiques',
            attendeePassword: 'mot-de-passe-participant',
            moderatorPassword: 'mot-de-passe-moderateur',
            logoutUrl: $logoutUrl,
        );
    }

    #[Test]
    public function a_created_meeting_is_reported_as_started(): void
    {
        $client = $this->clientCreating(<<<'XML'
            <response>
                <returncode>SUCCESS</returncode>
                <meetingID>a1b2c3d4e5f60718293a4b5c6d7e8f90</meetingID>
                <attendeePW>mot-de-passe-participant</attendeePW>
                <moderatorPW>mot-de-passe-moderateur</moderatorPW>
            </response>
        XML);

        self::assertTrue($client->createMeeting('https://bbb.example.test/bigbluebutton/', 's', $this->meeting())->isOk());
    }

    #[Test]
    public function re_creating_a_live_meeting_is_a_success_and_that_is_what_replaces_the_garbage_collector(): void
    {
        // BigBlueButton répond `SUCCESS` avec `duplicateWarning` quand le
        // meeting existe déjà et que les mots de passe correspondent. C'est
        // cette idempotence qui permet au bouton du créateur d'être « démarrer
        // OU entrer » sans qu'aucun état de fonctionnement ne soit tenu
        // localement — là où le legacy maintenait un miroir en mémoire, avec
        // ses meetings fantômes et son ramasse-miettes à verrou.
        $client = $this->clientCreating(<<<'XML'
            <response>
                <returncode>SUCCESS</returncode>
                <messageKey>duplicateWarning</messageKey>
                <message>This conference was already in existence.</message>
                <meetingID>a1b2c3d4e5f60718293a4b5c6d7e8f90</meetingID>
            </response>
        XML);

        self::assertTrue($client->createMeeting('https://bbb.example.test/bigbluebutton/', 's', $this->meeting())->isOk());
    }

    #[Test]
    public function a_refused_checksum_on_create_names_the_secret_not_the_network(): void
    {
        $client = $this->clientCreating(
            '<response><returncode>FAILED</returncode><messageKey>checksumError</messageKey></response>'
        );

        $result = $client->createMeeting('https://bbb.example.test/bigbluebutton/', 'mauvais', $this->meeting());

        self::assertSame(CallOutcome::InvalidSecret, $result->outcome);
        self::assertStringContainsString('refusé le secret', $result->message);
        self::assertStringContainsString('administrateur', $result->message);
    }

    #[Test]
    public function a_create_that_never_reaches_the_server_says_so(): void
    {
        $client = new LiveBbbApiClient(null, static function (): CreateMeetingResponse {
            throw new RuntimeException('Unhandled curl error: Connection timed out');
        });

        $result = $client->createMeeting('https://absent.example.test/bigbluebutton/', 's', $this->meeting());

        self::assertSame(CallOutcome::Unreachable, $result->outcome);
        self::assertStringContainsString('injoignable', $result->message);
        self::assertStringNotContainsString('curl', $result->message);
    }

    #[Test]
    public function an_unexpected_create_response_is_never_taken_for_a_success(): void
    {
        foreach ([
            '<response><returncode>FAILED</returncode><messageKey>notFound</messageKey></response>',
            '<response><returncode>PEUT-ETRE</returncode></response>',
        ] as $xml) {
            $result = $this->clientCreating($xml)
                ->createMeeting('https://bbb.example.test/bigbluebutton/', 's', $this->meeting());

            self::assertFalse($result->isOk(), $xml);
        }

        $broken = new LiveBbbApiClient(null, static function (): CreateMeetingResponse {
            throw new BadResponseException('Bad response, HTTP code: 404');
        });

        self::assertSame(
            CallOutcome::InvalidResponse,
            $broken->createMeeting('https://bbb.example.test/portail', 's', $this->meeting())->outcome,
        );
    }

    #[Test]
    public function the_create_parameters_are_the_ones_the_legacy_used(): void
    {
        // D5 : « se reprend presque tel quel ». Ce test fige ce « presque » —
        // et surtout ce qui a été laissé de côté, à savoir tout ce qui relève
        // des invités (message d'accueil porteur du lien, politique d'invités),
        // sujet de la story suivante.
        $captured = [];
        $this->clientCreating(
            '<response><returncode>SUCCESS</returncode></response>',
            $captured,
        )->createMeeting('https://bbb.example.test/bigbluebutton/', 's', $this->meeting());

        self::assertCount(1, $captured);
        $query = $captured[0]->getHTTPQuery();

        self::assertStringContainsString('meetingID=a1b2c3d4e5f60718293a4b5c6d7e8f90', $query);
        // ⚠️ `http_build_query` encode en RFC 1738 : les espaces deviennent
        // des `+`, pas des `%20`.
        self::assertStringContainsString('name=' . urlencode('Cours de mathématiques'), $query);
        self::assertStringContainsString('attendeePW=mot-de-passe-participant', $query);
        self::assertStringContainsString('moderatorPW=mot-de-passe-moderateur', $query);
        self::assertStringContainsString('record=true', $query);
        self::assertStringContainsString('allowStartStopRecording=true', $query);
        self::assertStringContainsString('lockSettingsDisablePrivateChat=true', $query);
        self::assertStringContainsString('duration=240', $query);
        self::assertStringContainsString('copyright=' . urlencode(LiveBbbApiClient::MEETING_COPYRIGHT), $query);
        self::assertStringContainsString('logoutURL=' . urlencode('https://se5.example.test/ext/bbb/rooms'), $query);

        self::assertStringNotContainsString('guestPolicy=ASK_MODERATOR', $query, 'les invités sont la story suivante');
        self::assertStringNotContainsString('welcome=Pour+rejoindre', $query);
    }

    #[Test]
    public function an_extension_without_an_issuer_simply_omits_the_logout_url(): void
    {
        // L'extension ne connaît d'absolu que son issuer, et ne fabrique JAMAIS
        // d'URL depuis un en-tête de requête. Sans issuer, le paramètre est
        // omis — pas rempli d'une valeur devinée.
        $captured = [];
        $this->clientCreating('<response><returncode>SUCCESS</returncode></response>', $captured)
            ->createMeeting('https://bbb.example.test/bigbluebutton/', 's', $this->meeting(''));

        // La bibliothèque écarte les paramètres vides : l'URL de retour n'est
        // pas envoyée à blanc, elle n'est pas envoyée du tout.
        self::assertStringNotContainsString('logoutURL', $captured[0]->getHTTPQuery());
    }

    // =====================================================================
    // Story 57.2 — « Ce salon est-il ouvert ? »
    // =====================================================================

    #[Test]
    public function a_running_meeting_is_reported_as_running(): void
    {
        $result = $this->clientProbing('<response><returncode>SUCCESS</returncode><running>true</running></response>')
            ->isMeetingRunning('https://bbb.example.test/bigbluebutton/', 's', 'jeton');

        self::assertTrue($result->answered());
        self::assertTrue($result->running);
    }

    #[Test]
    public function a_meeting_that_is_over_is_reported_as_stopped_and_not_as_an_error(): void
    {
        $result = $this->clientProbing('<response><returncode>SUCCESS</returncode><running>false</running></response>')
            ->isMeetingRunning('https://bbb.example.test/bigbluebutton/', 's', 'jeton');

        self::assertTrue($result->answered(), 'le serveur a répondu : ce n\'est pas une panne');
        self::assertFalse($result->running);
        self::assertSame('', $result->message);
    }

    #[Test]
    public function an_outage_is_never_confused_with_a_closed_meeting(): void
    {
        // La nuance qui compte pour l'utilisateur : « attendez votre
        // professeur » et « le serveur est en panne » n'appellent pas la même
        // conduite.
        $unreachable = new LiveBbbApiClient(null, null, static function (): IsMeetingRunningResponse {
            throw new RuntimeException('Unhandled curl error: Could not resolve host');
        });

        $result = $unreachable->isMeetingRunning('https://absent.example.test/bigbluebutton/', 's', 'jeton');

        self::assertFalse($result->answered());
        self::assertFalse($result->running);
        self::assertStringContainsString('injoignable', $result->message);

        $refused = $this->clientProbing(
            '<response><returncode>FAILED</returncode><messageKey>checksumError</messageKey></response>'
        )->isMeetingRunning('https://bbb.example.test/bigbluebutton/', 'mauvais', 'jeton');

        self::assertFalse($refused->answered());
        self::assertSame(CallOutcome::InvalidSecret, $refused->outcome);
    }

    #[Test]
    public function a_success_without_a_running_node_is_not_taken_for_an_open_meeting(): void
    {
        // Fail-closed jusque dans un cas anodin : on ne sait pas, donc on ne
        // dit pas « ouvert ».
        $result = $this->clientProbing('<response><returncode>SUCCESS</returncode></response>')
            ->isMeetingRunning('https://bbb.example.test/bigbluebutton/', 's', 'jeton');

        self::assertFalse($result->running);
    }

    // =====================================================================
    // Story 57.2 — L'URL de jonction : LOCALE, signée, porteuse du rôle
    // =====================================================================

    /**
     * ⚠️ `#[IgnoreDeprecations]` porte sur UNE dépréciation précise, et pas la
     * nôtre : le fork affecte `$this->curlopts` sans déclarer la propriété, ce
     * que PHP 8.2+ signale à chaque construction du client. Elle se produit
     * pour TOUS les appels de la bibliothèque, pas seulement ici ; la corriger
     * demanderait de modifier la dépendance, hors périmètre de cette story (le
     * `composer.lock` en épingle la version exacte). L'ignorer ici, nommément,
     * vaut mieux que de la laisser noyer le compte rendu — ou, pire, que de
     * couper les dépréciations pour toute la suite.
     */
    #[Test]
    #[IgnoreDeprecations]
    public function the_join_url_is_built_locally_and_signed(): void
    {
        // Aucune doublure ici : c'est la VRAIE fabrique de la bibliothèque qui
        // est exercée. Elle ne fait aucun réseau — elle signe une URL. C'est ce
        // qui permet au serveur de décider seul, et au navigateur de ne jamais
        // rien fournir d'autre qu'un jeton public.
        $url = (new LiveBbbApiClient())->joinUrl(
            'https://bbb.example.test/bigbluebutton/api',
            'secret-partage',
            'jeton-du-salon',
            'Paul Durand',
            'mot-de-passe-participant',
        );

        self::assertStringStartsWith('https://bbb.example.test/bigbluebutton/api/join?', $url);
        self::assertStringContainsString('meetingID=jeton-du-salon', $url);
        self::assertStringContainsString('fullName=Paul+Durand', $url);
        self::assertStringContainsString('password=mot-de-passe-participant', $url);
        self::assertStringContainsString('redirect=true', $url);
        self::assertMatchesRegularExpression('/&checksum=[0-9a-f]{40}$/', $url);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function the_join_url_signature_changes_with_the_password_and_with_the_secret(): void
    {
        // C'est bien le mot de passe qui porte le rôle dans la conférence, et
        // il est SIGNÉ : un participant ne peut pas transformer son URL en URL
        // de modérateur sans connaître le secret partagé du serveur.
        $client = new LiveBbbApiClient();
        $base = 'https://bbb.example.test/bigbluebutton/api';

        $attendee = $client->joinUrl($base, 'secret', 'jeton', 'Paul Durand', 'participant');
        $moderator = $client->joinUrl($base, 'secret', 'jeton', 'Paul Durand', 'moderateur');
        $otherSecret = $client->joinUrl($base, 'autre-secret', 'jeton', 'Paul Durand', 'participant');

        self::assertNotSame($attendee, $moderator);
        self::assertNotSame($attendee, $otherSecret, 'le checksum dépend du secret partagé');
    }

    // =====================================================================
    // Story 57.2 — L'URL de base attendue par la bibliothèque
    // =====================================================================

    #[Test]
    public function the_configured_base_url_is_normalised_to_what_the_library_expects(): void
    {
        // ⚠️ Relevé en développant 57.2 : la bibliothèque construit
        // `<base> . 'api/' . <méthode>`. Une base enregistrée comme
        // `…/bigbluebutton/api` — la forme que la page d'administration propose
        // — produirait `…/bigbluebutton/apiapi/create`, donc un 404 sur un
        // serveur pourtant sain. Les deux formes usuelles sont acceptées, et
        // rien n'est réécrit en base.
        foreach ([
            'https://bbb.example.test/bigbluebutton/api',
            'https://bbb.example.test/bigbluebutton/api/',
            'https://bbb.example.test/bigbluebutton',
            'https://bbb.example.test/bigbluebutton/',
        ] as $configured) {
            self::assertSame(
                'https://bbb.example.test/bigbluebutton/',
                LiveBbbApiClient::apiBase($configured),
                $configured,
            );
        }

        self::assertSame('', LiveBbbApiClient::apiBase(''));
    }

    #[Test]
    public function no_message_of_the_new_calls_ever_carries_a_secret_or_a_password(): void
    {
        $secret = 'secret-partage-9f3b';

        $messages = [
            $this->clientCreating('<response><returncode>FAILED</returncode><messageKey>checksumError</messageKey></response>')
                ->createMeeting('https://bbb.example.test/bigbluebutton/', $secret, $this->meeting())->message,
            (new LiveBbbApiClient(null, static function () use ($secret): CreateMeetingResponse {
                throw new RuntimeException('curl error with ' . $secret);
            }))->createMeeting('https://bbb.example.test/bigbluebutton/', $secret, $this->meeting())->message,
            $this->clientProbing('<response><returncode>FAILED</returncode><messageKey>checksumError</messageKey></response>')
                ->isMeetingRunning('https://bbb.example.test/bigbluebutton/', $secret, 'jeton')->message,
        ];

        foreach ($messages as $message) {
            self::assertStringNotContainsString($secret, $message);
            self::assertStringNotContainsString('mot-de-passe-moderateur', $message);
            self::assertStringNotContainsString('mot-de-passe-participant', $message);
        }
    }

    #[Test]
    public function tls_verification_is_never_disabled(): void
    {
        // Le legacy passait VERIFYPEER et VERIFYHOST à `false` sur TOUS ses
        // appels BBB : le secret partagé voyageait vers n'importe quel
        // intermédiaire capable de se faire passer pour le serveur. Défaut
        // explicitement non porté (D5) — et vérifié, pas seulement écrit.
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Bbb/LiveBbbApiClient.php');

        self::assertMatchesRegularExpression('/CURLOPT_SSL_VERIFYPEER\s*=>\s*true/', $source);
        self::assertMatchesRegularExpression('/CURLOPT_SSL_VERIFYHOST\s*=>\s*2/', $source);
        self::assertDoesNotMatchRegularExpression('/CURLOPT_SSL_VERIFY(PEER|HOST)\s*=>\s*(false|0)\b/', $source);
    }
}
