<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Story 54.1 (AC2) — Levée quand un manifest d'extension est INVALIDE.
 *
 * L'exception porte TOUJOURS le **champ fautif** (`$field`) et la **raison**
 * (`$reason`) : l'admin doit pouvoir corriger sans deviner. Le champ est
 * exprimé en notation pointée du manifest (`visibility.roles`, `entry_url`…).
 *
 * Elle est levée par le validateur PUR
 * ({@see \App\Services\Extensions\ExtensionManifestValidator}) — donc AVANT
 * toute écriture. La synchro de source
 * ({@see \App\Services\Extensions\ExtensionCatalogService::syncBundled()}) la
 * capture PAR MANIFEST : l'extension fautive est ignorée avec un
 * `Log::warning` structuré, **les autres manifests de la source continuent
 * d'être chargés**.
 *
 * ⚠️ Le rejet d'une `manifest_version` non supportée est STRICT — aucun repli
 * tolérant (même décision qu'en Story 33.2 pour le schéma d'échange amont).
 */
final class InvalidExtensionManifestException extends RuntimeException
{
    private function __construct(
        public readonly string $field,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Champ obligatoire absent ou vide. */
    public static function missingField(string $field): self
    {
        return new self(
            $field,
            'champ obligatoire manquant',
            "Manifest d'extension invalide — champ obligatoire manquant : « {$field} ».",
        );
    }

    /** Champ présent mais hors domaine (type, format, contenu). */
    public static function invalidField(string $field, string $reason): self
    {
        return new self(
            $field,
            $reason,
            "Manifest d'extension invalide — champ « {$field} » : {$reason}.",
        );
    }

    /**
     * Version de manifest déclarée non supportée par cette instance SE5
     * (rejet strict, pas de repli — iso-décision 33.2).
     *
     * @param  list<int>  $supported
     */
    public static function unsupportedVersion(mixed $declared, array $supported): self
    {
        $declaredLabel = is_scalar($declared) ? (string) $declared : gettype($declared);
        $supportedLabel = $supported === [] ? '(aucune)' : implode(', ', $supported);

        return new self(
            'manifest_version',
            "version « {$declaredLabel} » non supportée",
            "Manifest d'extension invalide — champ « manifest_version » : version « {$declaredLabel} » "
            ."non supportée ; supportées : {$supportedLabel}.",
        );
    }
}
