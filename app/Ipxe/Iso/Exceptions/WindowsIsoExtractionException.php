<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Exceptions;

use RuntimeException;

/**
 * Levée par {@see \App\Ipxe\Iso\Services\WindowsIsoExtractor} quand une étape
 * d'extraction native (montage loop, copie, …) échoue.
 *
 * Porte le `exitCode` de la commande fautive (ou -1 pour une erreur interne
 * PHP) afin que {@see \App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob} le reporte tel
 * quel dans la row `WindowsIsoDownload` (parité avec l'ancien exit code du
 * script `install-win-iso.sh`).
 */
class WindowsIsoExtractionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = -1)
    {
        parent::__construct($message);
    }
}
