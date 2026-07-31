<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use BigBlueButton\Exceptions\BadResponseException;
use BigBlueButton\Responses\GetMeetingsResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SambaEdu\ExtBbb\Bbb\ConnectionStatus;
use SambaEdu\ExtBbb\Bbb\LiveBbbApiClient;
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
