<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Exceptions;

use RuntimeException;

/**
 * Story 3.10 — Levée par {@see \App\Ipxe\Iso\Services\WinpeDriverInjector}
 * quand l'injection des pilotes NIC dans le `boot.wim` WinPE échoue
 * (`wimlib-imagex` absent, exit non-zéro, index invalide, …).
 *
 * Porte le `exitCode` de la commande `wimlib-imagex` fautive (ou -1 pour une
 * erreur interne PHP) — calque de {@see WindowsIsoExtractionException} — afin
 * que {@see \App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob} le reporte dans la row
 * `WindowsIsoDownload` (status `failed`, toast côté UI 3.6). Un demi-boot
 * (boot.wim sans NIC servi quand même) est ainsi évité : l'extraction échoue
 * proprement plutôt que de livrer une image incomplète.
 */
class WinpeDriverInjectionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = -1)
    {
        parent::__construct($message);
    }
}
