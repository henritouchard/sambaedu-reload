<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

use BigBlueButton\BigBlueButton;
use BigBlueButton\Exceptions\BadResponseException;
use BigBlueButton\Responses\GetMeetingsResponse;
use RuntimeException;
use Throwable;

/**
 * Story 57.1 — **LE TEST DE CONNEXION : UN VRAI APPEL, SIGNÉ ET BORNÉ.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI `getMeetings` ET PAS UN GET SUR L'URL DE BASE
 *
 *  Toutes les requêtes de l'API BigBlueButton portent un `checksum` calculé
 *  avec le secret partagé. Un `GET` sur l'URL de base — ce que faisait
 *  `server_bbb_is_up()` dans le legacy — prouve seulement qu'un serveur écoute :
 *  un secret erroné passait donc la « validation » et n'échouait qu'au premier
 *  salon créé, en production, devant une classe. `getMeetings` est l'appel
 *  signé le moins coûteux : il prouve l'URL **et** le secret.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Bornes de temps — la garde de conception de la stack retenue (D2).** Le
 * serveur intégré de PHP est mono-processus ; un serveur BBB lent gèlerait toute
 * l'extension, sonde de santé comprise. D'où `CONNECTTIMEOUT_MS = 2000` (valeur
 * du legacy, conservée) et un total borné à 8 s. Et d'où, surtout, le fait que
 * ce test soit le SEUL appel BBB de la story : il est déclenché par une action
 * explicite de l'administrateur, jamais au rendu d'une page.
 *
 * **TLS VÉRIFIÉ.** Le legacy passait `CURLOPT_SSL_VERIFYPEER` et
 * `CURLOPT_SSL_VERIFYHOST` à `false` sur TOUS ses appels BBB : le secret partagé
 * voyageait alors vers n'importe quel intermédiaire capable de se faire passer
 * pour le serveur. C'est explicitement un défaut à ne pas porter (D5).
 *
 * ⚠️ **Limites de la bibliothèque, relevées et assumées** (fork
 * `sambaedu/bigbluebutton-api-php` 2.0.12) : elle applique les options fournies
 * PUIS re-pose elle-même `CURLOPT_CONNECTTIMEOUT = 10` et
 * `CURLOPT_FOLLOWLOCATION = 1`. La borne de connexion effective retombe donc à
 * 10 s ; c'est `CURLOPT_TIMEOUT_MS` — que la bibliothèque ne touche pas — qui
 * tient la promesse de borne totale. `CURLOPT_SSL_VERIFYPEER` est forcé à `1`
 * par la bibliothèque, ce qui va dans notre sens ; `VERIFYHOST` reste à notre
 * main. Aucun patch de la bibliothèque n'est fait ici : ce serait sortir du
 * périmètre de la story pour un gain nul (la borne totale suffit).
 */
final class LiveBbbApiClient implements BbbApiClient
{
    /** Valeur du legacy, conservée telle quelle (D5). */
    public const CONNECT_TIMEOUT_MS = 2000;

    /** Borne TOTALE : au-delà, on rend la main plutôt que de tenir le processus. */
    public const TOTAL_TIMEOUT_MS = 8000;

    /** @var callable(string, string): GetMeetingsResponse */
    private $transport;

    /**
     * @param  (callable(string, string): GetMeetingsResponse)|null  $transport
     *         Le transport réel par défaut ; une doublure en test, pour prouver
     *         le MAPPING sans réseau.
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? self::defaultTransport(...);
    }

    public function testConnection(string $baseUrl, string $secret): ConnectionResult
    {
        try {
            $response = ($this->transport)($baseUrl, $secret);
        } catch (BadResponseException $e) {
            // Quelque chose a répondu, mais hors 2xx : une URL de base erronée
            // (page d'accueil, reverse-proxy, 404) plutôt qu'un serveur absent.
            return ConnectionResult::invalidResponse($e->getMessage());
        } catch (RuntimeException) {
            // Erreur de transport cURL : hôte inconnu, port fermé, TLS refusé,
            // délai dépassé. Le détail n'apporte rien à l'administrateur et
            // pourrait porter l'URL signée.
            return ConnectionResult::unreachable();
        } catch (Throwable) {
            // Corps illisible : `new SimpleXMLElement()` sur du HTML, par
            // exemple. Le serveur parle, mais pas le protocole attendu.
            return ConnectionResult::invalidResponse();
        }

        if ($response->failed()) {
            return $response->getMessageKey() === 'checksumError'
                ? ConnectionResult::invalidSecret()
                : ConnectionResult::invalidResponse($response->getMessageKey());
        }

        if (! $response->success()) {
            return ConnectionResult::invalidResponse();
        }

        try {
            $count = count($response->getMeetings());
        } catch (Throwable) {
            // `SUCCESS` sans nœud `meetings` : la connexion est prouvée, le
            // décompte ne l'est pas.
            $count = 0;
        }

        return ConnectionResult::ok($count);
    }

    private static function defaultTransport(string $baseUrl, string $secret): GetMeetingsResponse
    {
        // Constructeur du fork : ($baseUrl, $secret, $opts) — les options cURL
        // vivent sous la clé `curl`.
        $client = new BigBlueButton($baseUrl, $secret, [
            'curl' => [
                CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
                CURLOPT_TIMEOUT_MS => self::TOTAL_TIMEOUT_MS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ],
        ]);

        return $client->getMeetings();
    }
}
