<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Levée quand `ExtensionLifecycleService::integrate` /
 * `uninstall()` est appelé sur une cible qui ne peut PAS transiter :
 * identifiant inconnu, ou type ≠ `link` (fail-closed défensif — le moteur
 * D'installation `app` n'existe pas avant l').
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
     * Type non pris en charge par le cycle de vie — seul `link` est
     * intégrable/désinstallable aujourd'hui ; le type `app` arrive avec son
     * vrai moteur d'installation en.
     */
    public static function unsupportedType(string $typeValue): self
    {
        return new self(
            "Type d'extension « {$typeValue} » non pris en charge par cette action."
        );
    }

    /**
     * L'extension existe et est `available`, mais sa
     * source ne la propose plus : source gelée par l'admin, ou dernier
     * catalogue non vérifié (`error`). Le fail-closed doit tenir même
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
