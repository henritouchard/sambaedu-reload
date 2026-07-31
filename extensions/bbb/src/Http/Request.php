<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Http;

/**
 * Story 57.1 — La requête entrante, réduite à ce dont l'extension a besoin.
 *
 * ⚠️ **Le chemin arrive NU** (`/`, `/login`, `/admin/servers`) : le proxy
 * Apache a retiré `/ext/bbb`. Voir {@see \SambaEdu\ExtBbb\Url} — c'est le piège
 * n°1, et il se règle une fois pour toutes ici et là.
 *
 * Objet de valeur immuable : il se construit aussi bien depuis les superglobales
 * (production) que depuis un tableau (tests), ce qui rend tout le routage et
 * tous les contrôleurs testables SANS serveur HTTP.
 */
final class Request
{
    /**
     * @param  array<string, string|list<string>>  $query
     * @param  array<string, string|list<string>>  $post
     * @param  array<string, string>  $headers  Clés en minuscules.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $target = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        $path = parse_url($target, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? rawurldecode($path) : '/';

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (is_string($name) && str_starts_with($name, 'HTTP_') && is_scalar($value)) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
            }
        }

        return new self(
            method: $method,
            path: $path,
            query: self::flatten($_GET),
            post: self::flatten($_POST),
            headers: $headers,
        );
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;

        // Une valeur envoyée en tableau là où l'on attend une chaîne (un
        // `name="action[]"` fabriqué à la main) ne devient PAS une chaîne : elle
        // n'existe pas.
        return is_string($value) ? $value : $default;
    }

    /**
     * Story 57.2 — La valeur d'un champ MULTIPLE (`name="groups[]"`), telle que
     * les cases à cocher d'un formulaire l'envoient.
     *
     * Les entrées non textuelles sont écartées SILENCIEUSEMENT ici — c'est un
     * filtre de type, pas une autorisation. Ce qui décide de la légitimité des
     * valeurs, lui, est ailleurs, et il refuse au lieu de filtrer.
     *
     * @return list<string>
     */
    public function inputList(string $key): array
    {
        $value = $this->post[$key] ?? null;

        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $values = [];

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $values[] = $entry;
            }
        }

        return $values;
    }

    public function filled(string $key): bool
    {
        return trim($this->input($key)) !== '';
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Chemin NORMALISÉ pour le routage : sans slash final (sauf la racine), et
     * sans segments vides. `/admin/servers/` et `/admin//servers` mènent au même
     * endroit que `/admin/servers` — un lien mal recopié ne doit pas rendre 404.
     */
    public function routePath(): string
    {
        $segments = array_values(array_filter(explode('/', $this->path), static fn (string $s): bool => $s !== ''));

        return $segments === [] ? '/' : '/' . implode('/', $segments);
    }

    /**
     * Les scalaires deviennent des chaînes ; les tableaux ne sont conservés que
     * s'ils sont des LISTES de scalaires (`groups[]` d'un jeu de cases à
     * cocher). Toute autre forme — tableau associatif, tableau imbriqué — est
     * écartée : elle ne peut venir que d'une requête fabriquée.
     *
     * @param  array<mixed>  $values
     * @return array<string, string|list<string>>
     */
    private static function flatten(array $values): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (is_scalar($value)) {
                $flat[$key] = (string) $value;

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $list = [];

            foreach ($value as $entry) {
                if (is_scalar($entry)) {
                    $list[] = (string) $entry;
                }
            }

            $flat[$key] = $list;
        }

        return $flat;
    }
}
