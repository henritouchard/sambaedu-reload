<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Story 54.2 (AC3) — Levée quand `ExtensionLifecycleService::integrate()` /
 * `uninstall()` est appelé sur une cible qui ne peut PAS transiter :
 * identifiant inconnu, ou type ≠ `link` (fail-closed défensif — le moteur
 * d'installation `app` n'existe pas avant l'Epic 56).
 *
 * Toujours attrapée par le SFC appelant → `toastError`, jamais une 500.
 */
final class ExtensionLifecycleException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /** Identifiant d'extension inconnu du registre. */
    public static function unknownExtension(int $id): self
    {
        return new self("Extension #{$id} introuvable dans le registre.");
    }

    /**
     * Type non pris en charge par le cycle de vie 54.2 — seul `link` est
     * intégrable/désinstallable aujourd'hui ; le type `app` arrive avec son
     * vrai moteur d'installation en Epic 56.
     */
    public static function unsupportedType(string $typeValue): self
    {
        return new self(
            "Type d'extension « {$typeValue} » non pris en charge par cette action — Epic 56."
        );
    }

    /**
     * Story 56.1 (review #1) — L'extension existe et est `available`, mais sa
     * source ne la propose plus : source gelée par l'admin, ou dernier
     * catalogue non vérifié (`error`). Le fail-closed NFR2 doit tenir même
     * quand l'appel ne vient pas du rendu de la bibliothèque (appel Livewire
     * direct sur un identifiant connu, écran périmé, futur canal artisan).
     */
    public static function sourceNoLongerOffers(string $extensionName): self
    {
        return new self(
            "« {$extensionName} » n'est plus proposée par sa source (source désactivée, "
            .'ou catalogue non vérifié) — intégration refusée.'
        );
    }
}
