<?php

declare(strict_types=1);

namespace App\Ipxe\Support;

/**
 * Story 3.1 — D4 / AC2.2.
 *
 * Normalise un UUID vers la forme `lowercase trimmed`.
 *
 * **Pas** de validation regex stricte UUID v4 — le legacy `boot.php:24` fait
 * simplement `strtolower()` et accepte des UUIDs malformés (notamment quand
 * `boot.php:36-41` reconstruit l'UUID via hexadécimal de la MAC, le résultat
 * n'est pas un UUID v4 valide mais correspond à la valeur stockée en base
 * par certains postes legacy). Cohérence iso-legacy : tolérant.
 *
 * Retourne `null` si la chaîne est vide après trim (un poste qui ne pose
 * pas `uuid` → handshake déjà géré en amont par {@see \App\Ipxe\Services\IpxeService}).
 */
final class UuidNormalizer
{
    /**
     * Tente de normaliser une chaîne UUID vers lowercase trimmed.
     *
     * @param  string  $raw  Chaîne brute (peut être vide, mixed case).
     * @return string|null   UUID lowercase trimmed ou `null` si vide.
     */
    public static function normalize(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return strtolower($trimmed);
    }
}
