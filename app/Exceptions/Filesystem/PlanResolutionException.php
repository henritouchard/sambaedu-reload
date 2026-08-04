<?php

declare(strict_types=1);

namespace App\Exceptions\Filesystem;

use RuntimeException;

/**
 * Story 60.1 — la résolution d'un plan a ÉCHOUÉ sur ses données d'entrée (nom de
 * groupe ou login non conforme, segment de chemin dangereux après substitution,
 * rôle de recette sans cible connue du contexte…).
 *
 * **Pourquoi une exception plutôt qu'un plan partiel.** Un plan est comparé octet
 * pour octet à l'état relu (story 60.4) : un plan amputé en silence d'un nœud
 * ferait passer une absence pour une conformité. On échoue bruyamment, jamais à
 * moitié.
 *
 * Frontière avec {@see InvalidTreeSpecException} : ici la recette est bien formée,
 * ce sont les données de résolution qui ne le sont pas.
 */
final class PlanResolutionException extends RuntimeException
{
    public static function make(string $reason): self
    {
        return new self('Résolution du plan impossible : ' . $reason);
    }
}
