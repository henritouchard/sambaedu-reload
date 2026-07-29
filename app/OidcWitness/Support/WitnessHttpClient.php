<?php

declare(strict_types=1);

namespace App\OidcWitness\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use JsonException;
use RuntimeException;

/**
 * Story 55.3 — **Le seul canal de données du témoin : HTTP.**
 *
 * Tout ce que le témoin sait de l'utilisateur passe par ici. C'est délibéré :
 * un témoin qui lirait un modèle ou la base validerait la session SE5, pas le
 * SSO (FR24). Cette classe existe donc surtout pour être un **point
 * d'injection** — en test, on lui substitue un client Guzzle dont le handler
 * ré-entre dans le kernel HTTP de PHPUnit, si bien que le VRAI token endpoint
 * s'exécute de bout en bout : on remplace le transport, jamais le protocole.
 *
 * Le même client sert de collaborateur au `GenericProvider` de
 * `league/oauth2-client` — sinon l'échange de code sortirait par un autre canal
 * que la discovery, et le test ne prouverait qu'une moitié du chemin.
 *
 * `http_errors => false` : c'est le code appelant (ici, ou la bibliothèque
 * cliente) qui décide quoi faire d'un 4xx/5xx, jamais une exception de
 * transport qui masquerait un `{"error": "invalid_client"}` parfaitement
 * exploitable.
 */
class WitnessHttpClient
{
    private ?ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client;
    }

    public function client(): ClientInterface
    {
        return $this->client ??= new Client([
            'timeout' => max(1, (int) config('oidc.witness.http_timeout', 5)),
            'connect_timeout' => max(1, (int) config('oidc.witness.http_timeout', 5)),
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    /**
     * GET d'un document JSON. Toute anomalie (statut ≠ 200, corps non-JSON,
     * racine non-objet) lève : un document de protocole à moitié lu ne se
     * devine pas.
     *
     * @return array<string, mixed>
     */
    public function getJson(string $url): array
    {
        $response = $this->client()->request('GET', $url, [
            'headers' => ['Accept' => 'application/json'],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException(sprintf('GET %s a répondu %d', $url, $status));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('GET %s : réponse non-JSON', $url), 0, $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('GET %s : racine JSON inattendue', $url));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
