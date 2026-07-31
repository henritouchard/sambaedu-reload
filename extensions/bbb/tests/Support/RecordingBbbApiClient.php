<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests\Support;

use SambaEdu\ExtBbb\Bbb\BbbApiClient;
use SambaEdu\ExtBbb\Bbb\ConnectionResult;
use SambaEdu\ExtBbb\Bbb\CreateResult;
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

    /** Le nombre d'appels qui SORTENT réellement du serveur (la fabrique d'URL n'en est pas un). */
    public function outboundCalls(): int
    {
        return count($this->calls) + count($this->created) + count($this->probed);
    }
}
