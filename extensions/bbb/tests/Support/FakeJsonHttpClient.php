<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests\Support;

use SambaEdu\ExtBbb\Oidc\ErrorCodes;
use SambaEdu\ExtBbb\Oidc\JsonHttpClient;
use SambaEdu\ExtBbb\Oidc\OidcException;

/**
 * Le transport HTTP remplacé, le protocole intact.
 *
 * On enregistre ce que le client a réellement envoyé au token endpoint : c'est
 * ce qui permet d'affirmer que la `redirect_uri` repart VERBATIM et que le
 * `code_verifier` est bien celui qui a produit le `code_challenge`.
 */
final class FakeJsonHttpClient implements JsonHttpClient
{
    /** @var list<string> */
    public array $getUrls = [];

    /** @var list<array{url: string, fields: array<string, string>, user: string, password: string}> */
    public array $posts = [];

    /** @var array{status: int, body: array<string, mixed>}|(callable(array<string, string>): array{status: int, body: array<string, mixed>})|null */
    private $tokenResponse;

    /**
     * @param  array<string, array<string, mixed>>  $documents  URL => document JSON
     * @param  array{status: int, body: array<string, mixed>}|(callable(array<string, string>): array{status: int, body: array<string, mixed>})|null  $tokenResponse
     *         Une valeur figée, ou une fabrique — indispensable quand la réponse
     *         dépend du `nonce` tiré à l'autorisation, donc connu seulement une
     *         fois le parcours démarré.
     */
    public function __construct(
        private array $documents = [],
        array|callable|null $tokenResponse = null,
    ) {
        $this->tokenResponse = $tokenResponse;
    }

    public function getJson(string $url): array
    {
        $this->getUrls[] = $url;

        if (! isset($this->documents[$url])) {
            throw OidcException::of(ErrorCodes::DISCOVERY_UNAVAILABLE, 'aucun document simulé pour ' . $url);
        }

        return $this->documents[$url];
    }

    public function postForm(string $url, array $fields, string $basicUser, string $basicPassword): array
    {
        $this->posts[] = ['url' => $url, 'fields' => $fields, 'user' => $basicUser, 'password' => $basicPassword];

        if (is_callable($this->tokenResponse)) {
            return ($this->tokenResponse)($fields);
        }

        return $this->tokenResponse ?? ['status' => 500, 'body' => []];
    }
}
