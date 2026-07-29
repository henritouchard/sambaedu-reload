<?php

declare(strict_types=1);

namespace Tests\Feature\OidcWitness\Concerns;

use App\OidcWitness\Support\WitnessHttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Psr\Http\Message\RequestInterface;

/**
 * Story 55.3 — **Comment tester un appel HTTP vers soi-même sur l'hôte.**
 *
 * En production, le témoin appelle `{issuer}/oidc/token` par HTTP réel : SE5
 * s'appelle lui-même à travers Apache, et le pool FPM multi-workers l'absorbe
 * sans se bloquer. Sur l'hôte de test, PHPUnit ne sert aucun socket : un vrai
 * `POST http://…` échouerait.
 *
 * On substitue donc au témoin un client Guzzle dont le **handler** ré-entre
 * dans le kernel HTTP de test. Conséquence : le VRAI `TokenController`, la VRAIE
 * discovery et le VRAI JWKS s'exécutent de bout en bout, avec leurs middlewares,
 * leur validation et leur journalisation.
 *
 * ⚠️ **On remplace le TRANSPORT, jamais le PROTOCOLE.** Rien n'est simulé du
 * flux OIDC : aucun `Http::fake()`, aucun mock de réponse, aucun raccourci vers
 * les services du fournisseur (la quarantaine l'interdirait de toute façon). Le
 * seul aménagement est le parsing du corps `application/x-www-form-urlencoded`
 * en paramètres de requête — exactement ce que mod_php/FPM fait avec `$_POST`
 * et que `Symfony\Request::create()` ne fait pas.
 */
trait ReentersTheTestKernel
{
    /**
     * Les requêtes réellement sorties du témoin, dans l'ordre — pour pouvoir
     * asserter que le chemin est bien passé par HTTP.
     *
     * @var list<array{method: string, path: string}>
     */
    protected array $witnessHttpCalls = [];

    protected function bindReentrantWitnessHttpClient(): void
    {
        $this->witnessHttpCalls = [];

        $handler = function (RequestInterface $request, array $options): PromiseInterface {
            $uri = $request->getUri();
            $query = $uri->getQuery();
            $target = $uri->getPath() . ($query !== '' ? '?' . $query : '');

            $this->witnessHttpCalls[] = [
                'method' => $request->getMethod(),
                'path' => $uri->getPath(),
            ];

            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }

            $body = (string) $request->getBody();
            $parameters = [];
            $content = null;

            $contentType = strtolower($request->getHeaderLine('Content-Type'));

            if ($body !== '' && str_contains($contentType, 'application/x-www-form-urlencoded')) {
                // Ce que le serveur web fait de `$_POST`.
                parse_str($body, $parameters);
            } elseif ($body !== '') {
                $content = $body;
            }

            $response = $this->call(
                $request->getMethod(),
                $target,
                $parameters,
                [],
                [],
                $this->transformHeadersToServerVars($headers),
                $content,
            );

            return Create::promiseFor(new PsrResponse(
                $response->getStatusCode(),
                $response->headers->all(),
                (string) $response->getContent(),
            ));
        };

        $guzzle = new Client([
            'handler' => HandlerStack::create($handler),
            'http_errors' => false,
        ]);

        $this->app->instance(WitnessHttpClient::class, new WitnessHttpClient($guzzle));
    }

    /** Les chemins réellement appelés par le témoin, dans l'ordre. @return list<string> */
    protected function witnessHttpPaths(): array
    {
        return array_map(static fn (array $call): string => $call['path'], $this->witnessHttpCalls);
    }
}
