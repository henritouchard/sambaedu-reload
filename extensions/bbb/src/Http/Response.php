<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Http;

use SambaEdu\ExtBbb\Url;

/**
 * Story 57.1 — La réponse sortante.
 *
 * Un objet plutôt qu'un `echo` direct : c'est ce qui permet d'affirmer en test,
 * sans serveur HTTP, qu'une page rend bien 403, qu'une redirection pointe le bon
 * chemin PRÉFIXÉ, ou qu'un secret n'apparaît pas dans un corps HTML.
 */
final class Response
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Redirection interne à l'extension : le chemin donné est NU, l'en-tête émis
     * porte le préfixe. Jamais de `Location:` fabriqué à la main ailleurs.
     */
    public static function redirect(string $path, int $status = 302): self
    {
        return new self('', $status, ['Location' => Url::to($path)]);
    }

    /** Redirection vers une URL déjà absolue (SE5, fournisseur OIDC). */
    public static function redirectTo(string $absoluteUrl, int $status = 302): self
    {
        return new self('', $status, ['Location' => $absoluteUrl]);
    }

    public function send(): void
    {
        if (! headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }

            // Une page d'extension ne se met jamais en cache partagé : elle est
            // toujours fonction de l'utilisateur connecté.
            header('Cache-Control: no-store, private', true);
            header('X-Content-Type-Options: nosniff', true);
            header('Referrer-Policy: same-origin', true);
        }

        echo $this->body;
    }
}
