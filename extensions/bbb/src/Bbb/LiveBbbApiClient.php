<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

use BigBlueButton\BigBlueButton;
use BigBlueButton\Exceptions\BadResponseException;
use BigBlueButton\Parameters\CreateMeetingParameters;
use BigBlueButton\Parameters\IsMeetingRunningParameters;
use BigBlueButton\Parameters\JoinMeetingParameters;
use BigBlueButton\Responses\CreateMeetingResponse;
use BigBlueButton\Responses\GetMeetingsResponse;
use BigBlueButton\Responses\IsMeetingRunningResponse;
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
 * **tous** les appels de ce fichier soient déclenchés par une action explicite —
 * jamais au rendu d'une page.
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
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  Story 57.2 — DEUX APPELS SORTANTS DE PLUS, ET UNE FABRIQUE LOCALE
 *
 *  `createMeeting` et `isMeetingRunning` sortent, donc sont bornés comme le
 *  test de connexion. `joinUrl` ne sort PAS : `getJoinMeetingURL` se contente
 *  de signer une URL. C'est ce qui rend l'inversion de la story possible — le
 *  serveur fabrique lui-même l'URL de jonction, sans rien demander à personne,
 *  et le navigateur ne voit le mot de passe que dans une redirection qu'il
 *  suit immédiatement.
 *
 *  ⚠️ **La valeur retournée par `joinUrl()` porte un mot de passe** : elle ne
 *  se journalise pas, ne s'affiche pas, ne se met pas dans un `href`.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class LiveBbbApiClient implements BbbApiClient
{
    /** Valeur du legacy, conservée telle quelle (D5). */
    public const CONNECT_TIMEOUT_MS = 2000;

    /** Borne TOTALE : au-delà, on rend la main plutôt que de tenir le processus. */
    public const TOTAL_TIMEOUT_MS = 8000;

    /**
     * Durée maximale d'un meeting, en minutes. **Codée en dur dans le legacy**
     * (`duration = 240`), et reprise telle quelle : rien dans les AC ne demande
     * de la rendre configurable, et un paramètre de plus est un paramètre à
     * expliquer. Passé ce délai, BigBlueButton ferme le meeting ; le créateur le
     * ré-ouvre d'un clic, `createMeeting` étant idempotent.
     */
    public const MEETING_DURATION_MINUTES = 240;

    /** Mention affichée par le client BigBlueButton, iso-legacy (qui disait « 4 »). */
    public const MEETING_COPYRIGHT = 'SambaÉdu 5';

    /** @var callable(string, string): GetMeetingsResponse */
    private $meetingsTransport;

    /** @var callable(string, string, CreateMeetingParameters): CreateMeetingResponse */
    private $createTransport;

    /** @var callable(string, string, string): IsMeetingRunningResponse */
    private $runningTransport;

    /**
     * @param  (callable(string, string): GetMeetingsResponse)|null  $transport
     *         Le transport réel par défaut ; une doublure en test, pour prouver
     *         le MAPPING sans réseau.
     * @param  (callable(string, string, CreateMeetingParameters): CreateMeetingResponse)|null  $createTransport
     * @param  (callable(string, string, string): IsMeetingRunningResponse)|null  $runningTransport
     */
    public function __construct(
        ?callable $transport = null,
        ?callable $createTransport = null,
        ?callable $runningTransport = null,
    ) {
        $this->meetingsTransport = $transport ?? self::defaultTransport(...);
        $this->createTransport = $createTransport ?? self::defaultCreateTransport(...);
        $this->runningTransport = $runningTransport ?? self::defaultRunningTransport(...);
    }

    public function testConnection(string $baseUrl, string $secret): ConnectionResult
    {
        try {
            $response = ($this->meetingsTransport)($baseUrl, $secret);
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

    public function createMeeting(string $baseUrl, string $secret, RoomMeeting $meeting): CreateResult
    {
        try {
            $response = ($this->createTransport)($baseUrl, $secret, self::createParameters($meeting));
        } catch (BadResponseException $e) {
            return CreateResult::invalidResponse($e->getMessage());
        } catch (RuntimeException) {
            return CreateResult::unreachable();
        } catch (Throwable) {
            return CreateResult::invalidResponse();
        }

        if ($response->failed()) {
            return $response->getMessageKey() === 'checksumError'
                ? CreateResult::invalidSecret()
                : CreateResult::invalidResponse($response->getMessageKey());
        }

        return $response->success() ? CreateResult::started() : CreateResult::invalidResponse();
    }

    public function isMeetingRunning(string $baseUrl, string $secret, string $meetingId): RunningResult
    {
        try {
            $response = ($this->runningTransport)($baseUrl, $secret, $meetingId);
        } catch (BadResponseException $e) {
            return RunningResult::invalidResponse($e->getMessage());
        } catch (RuntimeException) {
            return RunningResult::unreachable();
        } catch (Throwable) {
            return RunningResult::invalidResponse();
        }

        if ($response->failed()) {
            return $response->getMessageKey() === 'checksumError'
                ? RunningResult::invalidSecret()
                : RunningResult::invalidResponse($response->getMessageKey());
        }

        if (! $response->success()) {
            return RunningResult::invalidResponse();
        }

        try {
            return $response->isRunning() ? RunningResult::running() : RunningResult::notRunning();
        } catch (Throwable) {
            // `SUCCESS` sans nœud `running` : on ne sait pas, donc on ne dit pas
            // « ouvert ». Fail-closed jusque dans un cas anodin.
            return RunningResult::invalidResponse();
        }
    }

    public function joinUrl(
        string $baseUrl,
        string $secret,
        string $meetingId,
        string $fullName,
        string $password,
    ): string {
        $parameters = new JoinMeetingParameters($meetingId, $fullName, $password);

        // Le navigateur atterrit directement dans la conférence plutôt que sur
        // une réponse XML.
        $parameters->setRedirect(true);

        return self::client($baseUrl, $secret)->getJoinMeetingURL($parameters);
    }

    // =====================================================================

    /**
     * Les paramètres du meeting, **portés du legacy** (D5 : « se reprend presque
     * tel quel »).
     *
     * Ce qui a été volontairement laissé de côté :
     *
     * - `welcomeMessage` — il ne servait qu'à afficher le lien invité, sujet de
     *   la story 57.3 ;
     * - `guestPolicy` — même raison : sans invités, `ALWAYS_ACCEPT` et
     *   `ASK_MODERATOR` n'ont personne à filtrer. Le défaut du serveur
     *   s'applique, et 57.3 tranchera avec le besoin sous les yeux ;
     * - `meetingName = "<nom> de <username>"` — le nom du salon est affiché tel
     *   que le professeur l'a écrit ; la page, elle, dit qui l'a créé.
     */
    private static function createParameters(RoomMeeting $meeting): CreateMeetingParameters
    {
        $parameters = new CreateMeetingParameters($meeting->meetingId, $meeting->name);

        $parameters->setAttendeePassword($meeting->attendeePassword);
        $parameters->setModeratorPassword($meeting->moderatorPassword);
        $parameters->setCopyright(self::MEETING_COPYRIGHT);
        $parameters->setRecord(true);
        $parameters->setAllowStartStopRecording(true);
        $parameters->setAllowModsToEjectCameras(true);
        $parameters->setBreakoutRoomsEnabled(true);
        $parameters->setBreakoutRoomsRecord(true);
        $parameters->setBreakoutRoomsPrivateChatEnabled(true);
        $parameters->setLockSettingsDisablePrivateChat(true);
        $parameters->setDuration(self::MEETING_DURATION_MINUTES);

        // ⚠️ **OBLIGATOIRE, et ce n'est pas cosmétique.** Le fork déclare
        // `getMeetingLayout(): string` sur une propriété qui n'a aucune valeur
        // par défaut : ne pas la poser fait lever une `TypeError` au moment de
        // construire la requête — c'est-à-dire un échec de TOUT démarrage de
        // salon, rapporté comme une « réponse inattendue » du serveur, qui n'y
        // serait pour rien. Le legacy appelait déjà `setMeetingLayout("")`, sans
        // dire pourquoi ; voilà pourquoi.
        $parameters->setMeetingLayout('');

        // Même famille de problème, en moins brutal : le fork passe ces quatre
        // valeurs à `trim()` sans les avoir initialisées. Les poser à vide les
        // fait simplement disparaître de la requête (les paramètres vides sont
        // écartés) et évite d'appuyer sur une corde usée de la bibliothèque.
        // Le message d'accueil, en particulier, ne servait dans le legacy qu'à
        // afficher le lien invité : sujet de la story suivante.
        $parameters->setWelcomeMessage('');
        $parameters->setModeratorOnlyMessage('');
        $parameters->setBannerText('');
        $parameters->setBannerColor('');

        if ($meeting->logoutUrl !== '') {
            $parameters->setLogoutUrl($meeting->logoutUrl);
        }

        return $parameters;
    }

    private static function defaultTransport(string $baseUrl, string $secret): GetMeetingsResponse
    {
        return self::client($baseUrl, $secret)->getMeetings();
    }

    private static function defaultCreateTransport(
        string $baseUrl,
        string $secret,
        CreateMeetingParameters $parameters,
    ): CreateMeetingResponse {
        return self::client($baseUrl, $secret)->createMeeting($parameters);
    }

    private static function defaultRunningTransport(
        string $baseUrl,
        string $secret,
        string $meetingId,
    ): IsMeetingRunningResponse {
        return self::client($baseUrl, $secret)->isMeetingRunning(new IsMeetingRunningParameters($meetingId));
    }

    /** Constructeur du fork : ($baseUrl, $secret, $opts) — les options cURL vivent sous la clé `curl`. */
    private static function client(string $baseUrl, string $secret): BigBlueButton
    {
        return new BigBlueButton(self::apiBase($baseUrl), $secret, [
            'curl' => [
                CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
                CURLOPT_TIMEOUT_MS => self::TOTAL_TIMEOUT_MS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ],
        ]);
    }

    /**
     * **Normalise l'URL de base pour la bibliothèque** — relevé en développant
     * la story 57.2, et corrigé ici parce que sans cela AUCUN appel n'aboutit.
     *
     * `UrlBuilder::buildUrl()` construit `<base> . 'api/' . <méthode>` : la
     * bibliothèque attend donc une base terminée par `/` et **sans** le segment
     * `api`, c'est-à-dire `https://serveur/bigbluebutton/`. Or la page
     * d'administration propose (et normalise vers) `…/bigbluebutton/api`, ce qui
     * produirait `…/bigbluebutton/apiapi/create` — un 404, rapporté comme
     * « réponse inattendue », sur un serveur pourtant parfaitement configuré.
     *
     * Le correctif vit ICI plutôt que dans la validation de saisie : ce que
     * l'administrateur écrit est ce qui reste écrit, et les deux formes usuelles
     * (avec ou sans `/api`) sont acceptées. Aucune ligne de la base n'est
     * réécrite.
     */
    public static function apiBase(string $baseUrl): string
    {
        $normalized = rtrim(trim($baseUrl), '/');

        if ($normalized === '') {
            return '';
        }

        if (str_ends_with(strtolower($normalized), '/api')) {
            $normalized = substr($normalized, 0, -4);
        }

        return $normalized . '/';
    }
}
