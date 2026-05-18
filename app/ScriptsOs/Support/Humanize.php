<?php

declare(strict_types=1);

namespace App\ScriptsOs\Support;

/**
 * Story 16.12 — D13.
 *
 * Helpers d'affichage humanisé pour l'UI Livewire `/admin/settings/scripts-logs/`
 * et la commande artisan `script-logs:archive:rotate`.
 *
 *  - `duration(int $ms): string` — `45ms` / `1.2s` / `2.3 min` / `1.5 h`
 *  - `bytes(int $bytes): string` — `512 B` / `1.4 KiB` / `2.5 MiB`
 */
final class Humanize
{
    public static function duration(int $ms): string
    {
        if ($ms < 0) {
            return '0ms';
        }
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        if ($ms < 60000) {
            return number_format($ms / 1000, 1) . 's';
        }
        if ($ms < 3600000) {
            return number_format($ms / 60000, 1) . ' min';
        }

        return number_format($ms / 3600000, 1) . ' h';
    }

    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KiB', 'MiB', 'GiB', 'TiB'];
        $value = $bytes / 1024;
        $unit = 'KiB';
        foreach ($units as $u) {
            if ($value < 1024) {
                $unit = $u;
                break;
            }
            $value /= 1024;
            $unit = $u;
        }

        return sprintf('%.2f %s', $value, $unit);
    }
}
