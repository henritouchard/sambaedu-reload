<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Http;

/**
 * Story 57.1 — Routeur minimal : méthode + chemin NU, correspondance exacte.
 *
 * Pas de paramètres d'URL, pas d'expressions régulières, pas de conteneur : le
 * périmètre de l'extension est une poignée de routes fixes. Ce qui compte ici
 * est ailleurs :
 *
 * - **les chemins déclarés sont NUS** (`/login`, jamais `/ext/bbb/login`) — le
 *   proxy a retiré le préfixe ;
 * - **`dispatch()` ne laisse JAMAIS échapper une exception** : `GET /` est la
 *   sonde de santé de l'extension (`ExtensionHealthService` la frappe toutes
 *   les 5 minutes et considère joignable TOUTE réponse HTTP, 5xx comprise). Une
 *   base SQLite corrompue doit rendre une page d'erreur, pas une connexion
 *   pendue ni une trace PHP.
 */
final class Router
{
    /** @var array<string, array<string, callable(Request): Response>> chemin => méthode => handler */
    private array $routes = [];

    /** @var callable(int, string): Response */
    private $errorHandler;

    /**
     * @param  callable(int, string): Response  $errorHandler  (statut, code interne)
     */
    public function __construct(callable $errorHandler)
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * @param  callable(Request): Response  $handler
     */
    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$path][strtoupper($method)] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->routePath();

        try {
            if (! isset($this->routes[$path])) {
                return ($this->errorHandler)(404, 'route.not_found');
            }

            $handler = $this->routes[$path][strtoupper($request->method)] ?? null;

            if ($handler === null) {
                return ($this->errorHandler)(405, 'route.method_not_allowed');
            }

            return $handler($request);
        } catch (\Throwable $e) {
            // Fail-SOFT, et uniquement ici : l'extension doit rendre quelque
            // chose. Le détail part sur la sortie d'erreur du service (journal
            // systemd), jamais dans la page — un visiteur n'a pas à apprendre le
            // chemin d'un fichier ni le schéma d'une table.
            error_log('[ext-bbb] erreur non rattrapée : ' . $e::class . ' — ' . $e->getMessage());

            return ($this->errorHandler)(500, 'internal.error');
        }
    }
}
