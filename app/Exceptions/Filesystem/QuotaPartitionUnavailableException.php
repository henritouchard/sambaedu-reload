<?php

declare(strict_types=1);

namespace App\Exceptions\Filesystem;

use RuntimeException;

/**
 * Story 63.4 — LE REFUS D'ÉCRIRE UN PLAFOND SUR UNE PARTITION QUI N'EN PORTE PAS.
 *
 * ---------------------------------------------------------------------------
 * **Pourquoi une exception, et pas un simple `false`.** Le geste est une écriture :
 * ce qui doit se produire n'est pas « la méthode rend faux et l'appelant décide »,
 * c'est « RIEN n'est écrit — ni la règle, ni sa ligne d'audit ». Une valeur de
 * retour se néglige ; une exception, non.
 *
 * **Elle porte le motif TEL QUEL**, celui que
 * `XfsQuotaService::partitionQuotaAvailability()` a construit : c'est le même texte
 * que l'écran affiche à côté du champ fermé. Deux formulations pour une même cause
 * finiraient par diverger, et l'exploitant lirait deux histoires différentes selon
 * qu'il a cliqué ou forgé sa soumission.
 * ---------------------------------------------------------------------------
 */
final class QuotaPartitionUnavailableException extends RuntimeException
{
    private function __construct(
        public readonly string $partition,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forPartition(string $partition, string $reason): self
    {
        return new self(
            $partition,
            $reason,
            sprintf('Aucun plafond par défaut ne peut être posé sur « %s ». %s', $partition, $reason),
        );
    }
}
