<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

/**
 * Story 57.1 — Le canal HTTP du client OIDC, derrière une interface.
 *
 * L'extension ne connaît SE5 que par ses 7 variables d'environnement et par
 * HTTP. Ce contrat est le second canal, et il est une INTERFACE pour une raison
 * précise : la suite de tests de l'extension doit pouvoir exercer la découverte,
 * le JWKS et l'échange de code SANS réseau — on remplace le transport, jamais le
 * protocole.
 */
interface JsonHttpClient
{
    /**
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    public function getJson(string $url): array;

    /**
     * POST `application/x-www-form-urlencoded`, authentifié en
     * `client_secret_basic`.
     *
     * @param  array<string, string>  $fields
     * @return array{status: int, body: array<string, mixed>}
     *
     * @throws OidcException
     */
    public function postForm(string $url, array $fields, string $basicUser, string $basicPassword): array;
}
