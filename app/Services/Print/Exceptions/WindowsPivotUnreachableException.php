<?php

declare(strict_types=1);

namespace App\Services\Print\Exceptions;

/**
 * Story 6.2 — Levée quand le poste Windows pivot (depuis lequel on copie
 * les fichiers driver) est injoignable : `smbclient //pivot/print$ -L`
 * retourne RC != 0, ou la lecture de `rpcclient enumprinters <pivot>`
 * échoue avec un code suggérant un partage / hôte inaccessible.
 *
 * Sous-type de {@see PrintDriverException} — les appelants Livewire
 * peuvent l'attraper séparément pour afficher un message dédié
 * « Poste pivot {hostname} injoignable — vérifier qu'il est allumé ».
 */
class WindowsPivotUnreachableException extends PrintDriverException
{
}
