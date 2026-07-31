<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests\Support;

use SambaEdu\ExtBbb\Bbb\BbbApiClient;
use SambaEdu\ExtBbb\Bbb\ConnectionResult;

/**
 * Doublure du client BBB : elle enregistre ce qu'on lui a demandé de tester.
 *
 * Ce qu'elle prouve dans la page d'administration : que le test de connexion est
 * déclenché par une ACTION EXPLICITE, avec le couple (URL, secret) du serveur
 * visé — et surtout, par son compteur, qu'aucun rendu de page ne l'appelle.
 */
final class RecordingBbbApiClient implements BbbApiClient
{
    /** @var list<array{url: string, secret: string}> */
    public array $calls = [];

    public function __construct(private readonly ?ConnectionResult $result = null)
    {
    }

    public function testConnection(string $baseUrl, string $secret): ConnectionResult
    {
        $this->calls[] = ['url' => $baseUrl, 'secret' => $secret];

        return $this->result ?? ConnectionResult::ok(3);
    }
}
