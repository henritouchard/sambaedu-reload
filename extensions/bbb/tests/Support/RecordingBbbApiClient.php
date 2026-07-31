<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests\Support;

use SambaEdu\ExtBbb\Bbb\BbbApiClient;
use SambaEdu\ExtBbb\Bbb\ConnectionResult;
use SambaEdu\ExtBbb\Bbb\CreateResult;
use SambaEdu\ExtBbb\Bbb\DeleteResult;
use SambaEdu\ExtBbb\Bbb\RecordingItem;
use SambaEdu\ExtBbb\Bbb\RecordingsResult;
use SambaEdu\ExtBbb\Bbb\RoomMeeting;
use SambaEdu\ExtBbb\Bbb\RunningResult;

/**
 * Doublure du client BBB : elle enregistre ce qu'on lui a demandé de faire.
 *
 * Ce qu'elle prouve dans la page d'administration : que le test de connexion est
 * déclenché par une ACTION EXPLICITE, avec le couple (URL, secret) du serveur
 * visé — et surtout, par son compteur, qu'aucun rendu de page ne l'appelle.
 *
 * Story 57.2 — elle porte les deux nouveaux appels SORTANTS et la fabrique
 * LOCALE de l'URL de jonction. La distinction compte : {@see outboundCalls()}
 * ne compte QUE ce qui sort, et c'est ce nombre qui doit valoir zéro au rendu
 * d'une page.
 *
 * L'URL de jonction fabriquée ici est FAUSSE mais elle porte le mot de passe
 * reçu, ce qui permet à un test d'affirmer *lequel des deux* le serveur a
 * choisi — la matrice de rôle est exactement le sujet de la story.
 */
final class RecordingBbbApiClient implements BbbApiClient
{
    /** @var list<array{url: string, secret: string}> */
    public array $calls = [];

    /** @var list<array{url: string, secret: string, meeting: RoomMeeting}> */
    public array $created = [];

    /** @var list<array{url: string, secret: string, meetingId: string}> */
    public array $probed = [];

    /** @var list<array{url: string, secret: string, meetingId: string, fullName: string, password: string}> */
    public array $joins = [];

    public ?CreateResult $createResult = null;

    public ?RunningResult $runningResult = null;

    public function __construct(private readonly ?ConnectionResult $result = null)
    {
    }

    public function testConnection(string $baseUrl, string $secret): ConnectionResult
    {
        $this->calls[] = ['url' => $baseUrl, 'secret' => $secret];

        return $this->result ?? ConnectionResult::ok(3);
    }

    public function createMeeting(string $baseUrl, string $secret, RoomMeeting $meeting): CreateResult
    {
        $this->created[] = ['url' => $baseUrl, 'secret' => $secret, 'meeting' => $meeting];

        return $this->createResult ?? CreateResult::started();
    }

    public function isMeetingRunning(string $baseUrl, string $secret, string $meetingId): RunningResult
    {
        $this->probed[] = ['url' => $baseUrl, 'secret' => $secret, 'meetingId' => $meetingId];

        return $this->runningResult ?? RunningResult::running();
    }

    public function joinUrl(
        string $baseUrl,
        string $secret,
        string $meetingId,
        string $fullName,
        string $password,
    ): string {
        $this->joins[] = [
            'url' => $baseUrl,
            'secret' => $secret,
            'meetingId' => $meetingId,
            'fullName' => $fullName,
            'password' => $password,
        ];

        return 'https://bbb.example.test/join?meetingID=' . rawurlencode($meetingId)
            . '&fullName=' . rawurlencode($fullName)
            . '&password=' . rawurlencode($password)
            . '&checksum=doublure';
    }

    // =====================================================================
    // Story 57.3 — Les enregistrements
    // =====================================================================

    /** @var list<array{url: string, secret: string, meetingIds: list<string>, recordId: string}> */
    public array $listed = [];

    /** @var list<array{url: string, secret: string, recordId: string}> */
    public array $deleted = [];

    /**
     * Ce que le serveur RÉPOND, indexé par URL de base — et volontairement pas
     * par ce qu'on lui a demandé.
     *
     * C'est ce qui rend exécutable le test le plus important de l'AC2 : un
     * serveur peut renvoyer des enregistrements qu'on ne lui a PAS demandés (le
     * filtre `meetingID` est un paramètre qu'on envoie, pas une garantie qu'on
     * reçoit). La doublure doit donc pouvoir « en renvoyer trop » pour que la
     * re-vérification côté extension prouve quelque chose.
     *
     * @var array<string, RecordingsResult>
     */
    public array $recordingsByServer = [];

    public ?RecordingsResult $recordingsResult = null;

    public ?DeleteResult $deleteResult = null;

    public function getRecordings(
        string $baseUrl,
        string $secret,
        array $meetingIds = [],
        string $recordId = '',
    ): RecordingsResult {
        $this->listed[] = [
            'url' => $baseUrl,
            'secret' => $secret,
            'meetingIds' => array_values($meetingIds),
            'recordId' => $recordId,
        ];

        $result = $this->recordingsByServer[$baseUrl] ?? $this->recordingsResult ?? RecordingsResult::ok([]);

        // Une demande portant un `recordID` interroge UN enregistrement : la
        // doublure imite le serveur réel en ne rendant que celui-là.
        if ($recordId !== '' && $result->isOk()) {
            $result = RecordingsResult::ok(array_values(array_filter(
                $result->items,
                static fn (RecordingItem $item): bool => $item->recordId === $recordId,
            )));
        }

        return $result;
    }

    public function deleteRecording(string $baseUrl, string $secret, string $recordId): DeleteResult
    {
        $this->deleted[] = ['url' => $baseUrl, 'secret' => $secret, 'recordId' => $recordId];

        return $this->deleteResult ?? DeleteResult::deleted();
    }

    /** Le nombre d'appels qui SORTENT réellement du serveur (la fabrique d'URL n'en est pas un). */
    public function outboundCalls(): int
    {
        return count($this->calls)
            + count($this->created)
            + count($this->probed)
            + count($this->listed)
            + count($this->deleted);
    }
}
