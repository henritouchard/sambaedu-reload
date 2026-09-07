<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\Contracts;

/**
 * Contrat d'écriture du contexte applicatif `apps.$id` (clé APCu legacy).
 *
 * Pendant écriture du `AppContextRepository`, qui, lui, ne fait que lire.
 * Ce contrat permet de mocker l'écriture dans les tests Feature
 * du Controller `ApplicationsScriptsController`.
 *
 * @see \App\Services\AppCustomization\Contracts\AppContextRepository Lecteur.
 * @see \App\Services\AppCustomization\CacheAppContextWriter Implémentation par défaut.
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
