<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Exceptions;

use RuntimeException;

/**
 * Story 3.6 — D15 — Exception levée par
 * {@see \App\Ipxe\Iso\Services\WindowsIsoDownloadOrchestrator}
 * lorsqu'un autre téléchargement est déjà en cours et détient le
 * `Cache::lock('ipxe.iso.download.global', 7200)` global.
 *
 * Côté UI Livewire : la méthode `submitDownload()` traduit cette exception
 * en un `toastError` "Un téléchargement est déjà en cours, attendez sa fin
 * ou annulez-le.".
 */
class WindowsIsoLockException extends RuntimeException
{
}
