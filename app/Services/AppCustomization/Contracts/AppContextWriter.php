<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\Contracts;

/**
 * Contrat d'écriture du contexte applicatif `apps.$id` (clé APCu legacy).
 *
 * Story 16.7 — AC2.2 : pendant écriture du `AppContextRepository` (lecteur
 * Story 4.8). Ce contrat permet de mocker l'écriture dans les tests Feature
 * du Controller `ApplicationsScriptsController`.
 *
 * @see \App\Services\AppCustomization\Contracts\AppContextRepository Lecteur (Story 4.8).
 * @see \App\Services\AppCustomization\CacheAppContextWriter Implémentation par défaut (Story 16.15).
 */
interface AppContextWriter
{
    /**
     * Persiste le contexte applicatif sous la clé `apps.$id` avec TTL.
     *
     * @param  string  $id  md5 32 hex (validé en amont par le service appelant).
     * @param  array<string,mixed>  $context  Structure iso-legacy (clés
     *                                        `machine`, `user`, `list`, `salle`,
     *                                        `list_u`, `os`, `time`…).
     * @param  int  $ttl  TTL en secondes (parité legacy 1800s).
     */
    public function write(string $id, array $context, int $ttl = 1800): void;

    /**
     * Supprime la clé `apps.$id` (utilisé au `shutdown`/`logoff` parité
     * legacy `log_application_scripts` ligne 807-808).
     */
    public function forget(string $id): void;
}
