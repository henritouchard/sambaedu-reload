<?php

declare(strict_types=1);

namespace App\Exceptions\Filesystem;

use InvalidArgumentException;

/**
 * Story 60.1 — la RECETTE STOCKÉE est mal formée (motif de chemin ou `nodes_spec`
 * hors vocabulaire).
 *
 * Frontière volontaire avec {@see PlanResolutionException} : cette exception-ci
 * dit « la recette est invalide », l'autre dit « les données d'entrée de la
 * résolution sont invalides ». Les deux validations ne se mélangent pas — une
 * recette bien formée peut échouer à se résoudre sur un groupe au nom impossible,
 * et l'inverse n'a aucun sens.
 *
 * `InvalidArgumentException` comme parent : c'est un contrat d'appel violé, au
 * même titre que les refus de {@see \App\Services\Filesystem\DirectoryTemplateService}.
 */
final class InvalidTreeSpecException extends InvalidArgumentException
{
    public static function make(string $reason): self
    {
        return new self('Recette invalide (arbre de répertoire) : ' . $reason);
    }
}
