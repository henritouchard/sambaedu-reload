<?php

declare(strict_types=1);

namespace App\Gpo\Services;

/**
 * Exception métier émise par `WineImageQueuer::dispatch` quand un Job de
 * génération d'image Wine est déjà en queue / en cours pour la même
 * application (Cache::lock détenu).
 *
 * Capturée par le SFC Livewire `/admin/settings/gpo/wine` → toast warning.
 *
 * Story 16.3c — discrepance SM (a) tranchement.
 */
final class WineImageAlreadyQueuedException extends \RuntimeException
{
}
