<?php

declare(strict_types=1);

namespace App\Gpo\Services;

/**
 * Scanner stateless du dossier des conteneurs Wine partagés.
 *
 * Port natif du `dir("/var/sambaedu/unattended/install/wine")` du legacy
 * `gpo/wine.php:43-49`. Retourne la liste triée alpha des préfixes Wine
 * disponibles (extraits du pattern `wine-<X>`).
 *
 * Path configurable via `config('sambaedu.gpo.wine.prefix_base')` ; fallback
 * hardcodé legacy si la clé est absente (parité iso `wine.php:43`).
 *
 * Story 16.3c — AC1.2.
 *
 * @legacy-port path="sambaedu/gpo/wine.php:43-49"
 */
class WinePrefixScanner
{
    /**
     * Path par défaut iso-legacy. Override via
     * `config('sambaedu.gpo.wine.prefix_base')` ou paramètre $basePath.
     */
    public const DEFAULT_BASE_PATH = '/var/sambaedu/unattended/install/wine';

    /**
     * Liste les noms d'applications Wine (suffixes de `wine-<X>`) présents dans
     * le dossier de base. Retourne une liste triée alpha (case-insensitive),
     * sans le préfixe `wine-`.
     *
     * Iso-legacy gracieux : dossier inexistant ou illisible → liste vide.
     *
     * @return list<string>
     */
    public function list(?string $basePath = null): array
    {
        $path = $basePath ?? $this->resolveBasePath();

        if (! is_dir($path)) {
            return [];
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return [];
        }

        $apps = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $matches = [];
            // @legacy-port regex iso `gpo/wine.php:46` `#^wine-(.*)$#`.
            if (preg_match('#^wine-(.+)$#', $entry, $matches) === 1) {
                $apps[] = $matches[1];
            }
        }

        sort($apps, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($apps);
    }

    /**
     * Vérifie qu'un nom d'application correspond à un conteneur Wine présent.
     *
     * Utilisé pour la validation des inputs UI (chaîne d'application doit
     * appartenir au scan FS). Chaîne vide = conteneur par défaut `.wine`,
     * toujours autorisée et hors scope de ce check.
     */
    public function exists(string $application, ?string $basePath = null): bool
    {
        if ($application === '') {
            return true; // conteneur défaut `.wine`
        }

        return in_array($application, $this->list($basePath), true);
    }

    /**
     * Résout le chemin de base via la config Laravel (fallback iso-legacy).
     */
    private function resolveBasePath(): string
    {
        $configured = config('sambaedu.gpo.wine.prefix_base');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return self::DEFAULT_BASE_PATH;
    }
}
