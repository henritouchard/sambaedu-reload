<?php

declare(strict_types=1);

namespace App\Exceptions\Filesystem;

use RuntimeException;

/**
 * Story 60.3 — un rapport de backend viole l'un de ses invariants de CONSTRUCTION.
 *
 * **Pourquoi une exception distincte de celles du plan.** Un plan qui ne se résout
 * pas et un rapport qui ne couvre pas son plan sont deux fautes différentes, à
 * deux étages différents : la première est une donnée de recette inexploitable, la
 * seconde est un BACKEND qui ment sur ce qu'il a fait. Les confondre rendrait
 * illisible, en journal comme en test, la seule chose qu'on ait besoin de savoir :
 * de quel côté de la ligne de contrat le défaut se trouve.
 *
 * Cette exception ne se rattrape pas pour continuer : elle signale un défaut
 * d'implémentation de backend, pas une situation d'exploitation.
 */
final class InvalidBackendReportException extends RuntimeException
{
    public static function make(string $message): self
    {
        return new self($message);
    }
}
