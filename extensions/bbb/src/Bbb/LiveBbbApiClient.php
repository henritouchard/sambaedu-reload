<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

use BigBlueButton\BigBlueButton;
use BigBlueButton\Exceptions\BadResponseException;
use BigBlueButton\Parameters\CreateMeetingParameters;
use BigBlueButton\Parameters\DeleteRecordingsParameters;
use BigBlueButton\Parameters\GetRecordingsParameters;
use BigBlueButton\Parameters\IsMeetingRunningParameters;
use BigBlueButton\Parameters\JoinMeetingParameters;
use BigBlueButton\Responses\CreateMeetingResponse;
use BigBlueButton\Responses\DeleteRecordingsResponse;
use BigBlueButton\Responses\GetMeetingsResponse;
use BigBlueButton\Responses\GetRecordingsResponse;
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
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  Story 57.4 — UNE SONDE DE CHARGE, ET LE MÊME APPEL QUE LE TEST DE CONNEXION
 *
 *  `measureLoad` réutilise `getMeetings` : c'est le même aller-retour signé,
 *  mais deux mappings différents — l'un compte des conférences pour le dire à
 *  un administrateur, l'autre somme des participants pour choisir un serveur.
 *  Ce qui les sépare vraiment est la BORNE : 3 s pour la sonde, 8 s pour tout
 *  le reste, parce que la sonde se paie une fois par serveur configuré à chaque
 *  démarrage de salon.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class LiveBbbApiClient implements BbbApiClient
{
    /** Valeur du legacy, conservée telle quelle (D5). */
    public const CONNECT_TIMEOUT_MS = 2000;

    /** Borne TOTALE : au-delà, on rend la main plutôt que de tenir le processus. */
    public const TOTAL_TIMEOUT_MS = 8000;

    /**
     * Story 57.4 — **BORNE DÉDIÉE À LA SONDE DE CHARGE, et volontairement plus
     * courte que tout le reste.**
     *
     * Le démarrage d'un salon interroge TOUS les serveurs normaux avant d'en
     * choisir un : la borne se paie donc autant de fois qu'il y a de serveurs,
     * avant même la première tentative de création. Trois secondes disent la
     * chose suivante, et rien d'autre : un serveur incapable d'annoncer sa
     * charge en 3 s n'est pas un bon hôte pour une classe qui commence — et
     * l'écarter n'est pas une erreur, c'est la mesure.
     *
     * ⚠️ Rappel de 57.1, à ne pas ré-instruire : le fork re-pose lui-même
     * `CURLOPT_CONNECTTIMEOUT = 10` après nos options. C'est
     * `CURLOPT_TIMEOUT_MS` — qu'il ne touche pas — qui tient réellement cette
     * borne.
     */
    public const PROBE_TIMEOUT_MS = 3000;

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

    /**
     * Story 57.3 — **Seuls les enregistrements PUBLIÉS sont demandés.**
     *
     * Première moitié de la double défense contre le piège d'hydratation du
     * fork : un enregistrement encore en traitement (`processing`) ou déjà
     * effacé (`deleted`) n'a pas de bloc `playback`, et `Record::__construct`
     * le lit sans garde. Filtrer en amont évite le cas ; l'hydratation sous
     * garde le rattrape s'il survient quand même.
     */
    public const RECORDINGS_STATE = 'published';

    /**
     * ⚠️ Le troisième paramètre (borne totale, en millisecondes) est arrivé avec
     * la story 57.4 : le MÊME appel `getMeetings` sert le test de connexion
     * (8 s) et la sonde de charge (3 s). Les doublures de test qui n'en
     * déclarent que deux — ou aucun — continuent de fonctionner : PHP autorise
     * les arguments surnuméraires sur une fonction utilisateur.
     *
     * @var callable(string, string, int): GetMeetingsResponse
     */
    private $meetingsTransport;

    /** @var callable(string, string, CreateMeetingParameters): CreateMeetingResponse */
    private $createTransport;

    /** @var callable(string, string, string): IsMeetingRunningResponse */
    private $runningTransport;

    /** @var callable(string, string, GetRecordingsParameters): GetRecordingsResponse */
    private $recordingsTransport;

    /** @var callable(string, string, DeleteRecordingsParameters): DeleteRecordingsResponse */
    private $deleteRecordingTransport;

    /**
     * @param  (callable(string, string, int): GetMeetingsResponse)|null  $transport
     *         Le transport réel par défaut ; une doublure en test, pour prouver
     *         le MAPPING sans réseau.
     * @param  (callable(string, string, CreateMeetingParameters): CreateMeetingResponse)|null  $createTransport
     * @param  (callable(string, string, string): IsMeetingRunningResponse)|null  $runningTransport
     * @param  (callable(string, string, GetRecordingsParameters): GetRecordingsResponse)|null  $recordingsTransport
     * @param  (callable(string, string, DeleteRecordingsParameters): DeleteRecordingsResponse)|null  $deleteRecordingTransport
     */
    public function __construct(
        ?callable $transport = null,
        ?callable $createTransport = null,
        ?callable $runningTransport = null,
        ?callable $recordingsTransport = null,
        ?callable $deleteRecordingTransport = null,
    ) {
        $this->meetingsTransport = $transport ?? self::defaultTransport(...);
        $this->createTransport = $createTransport ?? self::defaultCreateTransport(...);
        $this->runningTransport = $runningTransport ?? self::defaultRunningTransport(...);
        $this->recordingsTransport = $recordingsTransport ?? self::defaultRecordingsTransport(...);
        $this->deleteRecordingTransport = $deleteRecordingTransport ?? self::defaultDeleteRecordingTransport(...);
    }

    public function testConnection(string $baseUrl, string $secret): ConnectionResult
    {
        try {
            $response = ($this->meetingsTransport)($baseUrl, $secret, self::TOTAL_TIMEOUT_MS);
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
            $count = count(self::meetingsOf($response));
        } catch (Throwable) {
            // `SUCCESS` sans nœud `meetings` : la connexion est prouvée, le
            // décompte ne l'est pas.
            $count = 0;
        }

        return ConnectionResult::ok($count);
    }

    /**
     * Story 57.4 — **LA CHARGE, MESURÉE COMME LE LEGACY LA MESURAIT.**
     *
     * `load_server_bbb()` (SE4) faisait exactement cela pour un serveur normal :
     * `getMeetings()`, puis la somme des `participantCount`. C'est cette partie
     * du legacy qui « se reprend telle quelle » (D5) — le reste de sa mécanique
     * (cache en mémoire partagée, compteur d'échecs, nettoyage cron-only) est
     * explicitement NON portée.
     *
     * ⚠️ Le seuil des serveurs Scalelite ne passe JAMAIS par ici : il n'est pas
     * une mesure, et le sélecteur n'appelle pas cette méthode pour eux.
     */
    public function measureLoad(string $baseUrl, string $secret): LoadResult
    {
        try {
            $response = ($this->meetingsTransport)($baseUrl, $secret, self::PROBE_TIMEOUT_MS);
        } catch (BadResponseException $e) {
            return LoadResult::invalidResponse($e->getMessage());
        } catch (RuntimeException) {
            // Hôte inconnu, port fermé, TLS refusé, borne de sonde dépassée.
            return LoadResult::unreachable();
        } catch (Throwable) {
            return LoadResult::invalidResponse();
        }

        if ($response->failed()) {
            return $response->getMessageKey() === 'checksumError'
                ? LoadResult::invalidSecret()
                : LoadResult::invalidResponse($response->getMessageKey());
        }

        if (! $response->success()) {
            return LoadResult::invalidResponse();
        }

        $participants = 0;

        foreach (self::meetingsOf($response) as $meeting) {
            try {
                $participants += (int) $meeting->getParticipantCount();
            } catch (Throwable) {
                // ⚠️ TROISIÈME rendez-vous avec le défaut de famille du fork
                // (après `getMeetingLayout` en 57.2 et `Record::playback` en
                // 57.3) : un accesseur typé sur une propriété sans valeur par
                // défaut. Un meeting illisible ne doit pas emporter la mesure
                // des autres — au pire il est sous-compté, ce qui rend son
                // serveur un peu plus attractif, jamais indisponible. Le
                // correctif de fond appartient au fork, pas à cette story.
                continue;
            }
        }

        return LoadResult::ok($participants);
    }

    /**
     * Les meetings d'une réponse `getMeetings`, **sans réveiller le fork sur un
     * XML licite mais dépourvu de nœud `meetings`.**
     *
     * ⚠️ Relevé sur pièce en 57.4 : `GetMeetingsResponse::getMeetings()` fait
     * `foreach ($this->rawXml->meetings->children() …)` — et `children()` sur un
     * enfant ABSENT rend `null`, ce qui déclenche un avertissement PHP
     * (« foreach() argument must be of type array|object ») à chaque appel. Ce
     * n'est pas une exception : aucun `catch` ne l'attrape, elle part dans le
     * journal du service et, en test, fait échouer la suite (`failOnWarning`).
     *
     * ⚠️ `isset()`, et surtout PAS `=== null` : SimpleXML rend un ÉLÉMENT VIDE
     * pour un enfant absent, jamais `null` — piège déjà relevé en 57.3.
     *
     * `SUCCESS` sans nœud `meetings`, c'est un serveur SANS AUCUNE conférence :
     * une charge de zéro, donc le meilleur candidat possible.
     *
     * @return array<int, \BigBlueButton\Core\Meeting>
     */
    private static function meetingsOf(GetMeetingsResponse $response): array
    {
        $xml = $response->getRawXml();

        if (! isset($xml->meetings)) {
            return [];
        }

        return $response->getMeetings();
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
    // Story 57.3 — Enregistrements : deux appels sortants de plus
    // =====================================================================

    public function getRecordings(
        string $baseUrl,
        string $secret,
        array $meetingIds = [],
        string $recordId = '',
    ): RecordingsResult {
        try {
            $response = ($this->recordingsTransport)(
                $baseUrl,
                $secret,
                self::recordingsParameters($meetingIds, $recordId),
            );
        } catch (BadResponseException $e) {
            return RecordingsResult::invalidResponse($e->getMessage());
        } catch (RuntimeException) {
            return RecordingsResult::unreachable();
        } catch (Throwable) {
            return RecordingsResult::invalidResponse();
        }

        if ($response->failed()) {
            return $response->getMessageKey() === 'checksumError'
                ? RecordingsResult::invalidSecret()
                : RecordingsResult::invalidResponse($response->getMessageKey());
        }

        if (! $response->success()) {
            return RecordingsResult::invalidResponse();
        }

        return RecordingsResult::ok(self::hydrateRecords($response));
    }

    public function deleteRecording(string $baseUrl, string $secret, string $recordId): DeleteResult
    {
        try {
            $response = ($this->deleteRecordingTransport)(
                $baseUrl,
                $secret,
                new DeleteRecordingsParameters($recordId),
            );
        } catch (BadResponseException $e) {
            return DeleteResult::invalidResponse($e->getMessage());
        } catch (RuntimeException) {
            return DeleteResult::unreachable();
        } catch (Throwable) {
            return DeleteResult::invalidResponse();
        }

        if ($response->failed()) {
            return $response->getMessageKey() === 'checksumError'
                ? DeleteResult::invalidSecret()
                : DeleteResult::invalidResponse($response->getMessageKey());
        }

        if (! $response->success()) {
            return DeleteResult::invalidResponse();
        }

        try {
            return $response->isDeleted() ? DeleteResult::deleted() : DeleteResult::refused();
        } catch (Throwable) {
            // `SUCCESS` sans nœud `deleted` : on ne sait pas, donc on ne dit
            // surtout pas « supprimé ».
            return DeleteResult::invalidResponse();
        }
    }

    /**
     * L'hydratation, **enregistrement par enregistrement, sous garde.**
     *
     * ⚠️ `Record::__construct` lit `$xml->playback->format->type->__toString()`
     * sans la moindre vérification : un enregistrement sans bloc `playback`
     * (état `processing`, `deleted`) lève une erreur qui, remontée telle quelle,
     * viderait la liste ENTIÈRE — un seul XML bancal effacerait de l'écran tous
     * les cours des autres. C'est la même famille de piège que
     * `getMeetingLayout()`, relevée en 57.2.
     *
     * `getRecords()` construit toute la collection d'un coup : l'itération se
     * fait donc sur le XML brut, ce qui est le seul endroit où l'on peut isoler
     * un enregistrement fautif.
     *
     * @return list<RecordingItem>
     */
    private static function hydrateRecords(GetRecordingsResponse $response): array
    {
        $items = [];

        try {
            $xml = $response->getRawXml();

            // ⚠️ `isset()`, et surtout PAS `=== null` : SimpleXML rend un
            // ÉLÉMENT VIDE pour un enfant absent, jamais `null`. Comparer à
            // `null` laisserait donc passer le cas — vérifié sur pièce.
            // `SUCCESS` + `messageKey = noRecordings` : une liste vide, pas une
            // panne. C'est la réponse NORMALE d'un salon jamais enregistré.
            if (! isset($xml->recordings)) {
                return [];
            }

            $children = $xml->recordings->children();
        } catch (Throwable) {
            return [];
        }

        foreach ($children as $recordXml) {
            try {
                $items[] = new RecordingItem(
                    recordId: (string) $recordXml->recordID,
                    meetingId: (string) $recordXml->meetingID,
                    startTime: (float) (string) $recordXml->startTime,
                    endTime: (float) (string) $recordXml->endTime,
                    playbackUrl: self::playbackUrlOf($recordXml),
                    lengthMinutes: (int) (string) $recordXml->playback->format->length,
                );
            } catch (Throwable) {
                // Un enregistrement illisible est IGNORÉ ; les autres vivent.
                continue;
            }
        }

        return $items;
    }

    /** L'URL de lecture, ou rien — un enregistrement sans playback n'est pas lisible. */
    private static function playbackUrlOf(\SimpleXMLElement $recordXml): string
    {
        $format = $recordXml->playback->format ?? null;

        if ($format === null || ! isset($format->url)) {
            throw new RuntimeException('enregistrement sans bloc de lecture');
        }

        return (string) $format->url;
    }

    /**
     * ⚠️ **Vérifié sur pièce, pas sur parole** : `GetRecordingsParameters::getHTTPQuery()`
     * émet bien `meetingID`, `recordID` et `state`. L'incident `getMeetingLayout`
     * de 57.2 a appris que les déclarations de ce fork ne suffisent pas — un test
     * de mapping affirme la requête RÉELLEMENT construite.
     *
     * @param  list<string>  $meetingIds
     */
    private static function recordingsParameters(array $meetingIds, string $recordId): GetRecordingsParameters
    {
        $parameters = new GetRecordingsParameters();

        if ($meetingIds !== []) {
            // L'API BigBlueButton accepte une liste séparée par des virgules.
            $parameters->setMeetingId(implode(',', $meetingIds));
        }

        if ($recordId !== '') {
            $parameters->setRecordId($recordId);
        }

        $parameters->setState(self::RECORDINGS_STATE);

        return $parameters;
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

    private static function defaultTransport(
        string $baseUrl,
        string $secret,
        int $totalTimeoutMs = self::TOTAL_TIMEOUT_MS,
    ): GetMeetingsResponse {
        return self::client($baseUrl, $secret, $totalTimeoutMs)->getMeetings();
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

    private static function defaultRecordingsTransport(
        string $baseUrl,
        string $secret,
        GetRecordingsParameters $parameters,
    ): GetRecordingsResponse {
        return self::client($baseUrl, $secret)->getRecordings($parameters);
    }

    private static function defaultDeleteRecordingTransport(
        string $baseUrl,
        string $secret,
        DeleteRecordingsParameters $parameters,
    ): DeleteRecordingsResponse {
        return self::client($baseUrl, $secret)->deleteRecordings($parameters);
    }

    /**
     * Constructeur du fork : ($baseUrl, $secret, $opts) — les options cURL vivent
     * sous la clé `curl`.
     *
     * `$totalTimeoutMs` est paramétré depuis 57.4 : la sonde de charge se borne
     * plus court que les appels qui font vraiment quelque chose.
     */
    private static function client(
        string $baseUrl,
        string $secret,
        int $totalTimeoutMs = self::TOTAL_TIMEOUT_MS,
    ): BigBlueButton {
        return new BigBlueButton(self::apiBase($baseUrl), $secret, [
            'curl' => [
                CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
                CURLOPT_TIMEOUT_MS => $totalTimeoutMs,
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
