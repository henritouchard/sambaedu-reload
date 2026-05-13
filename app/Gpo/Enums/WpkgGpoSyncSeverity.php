<?php

declare(strict_types=1);

namespace App\Gpo\Enums;

/**
 * Sévérité d'un diagnostic {@see \App\Gpo\Dto\WpkgGpoSyncReport} — Story 16.6.
 *
 * Ordre de gravité croissant : `ok` < `info` < `warning` < `error`.
 * La valeur la plus forte rencontrée pendant `WpkgGpoSynchronizer::audit()`
 * détermine le statut global retourné (cf. {@see self::merge()}).
 */
enum WpkgGpoSyncSeverity: string
{
    case Ok = 'ok';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Info => 1,
            self::Warning => 2,
            self::Error => 3,
        };
    }

    /**
     * Retourne la sévérité la plus forte entre deux valeurs (pour l'agrégation
     * progressive pendant `audit()`).
     */
    public function merge(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    /**
     * Code de sortie shell adapté (consommé par la commande artisan
     * `wpkg:gpo:sync`) — `ok`/`info` = 0, `warning` = 1, `error` = 2.
     */
    public function exitCode(): int
    {
        return match ($this) {
            self::Ok, self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
        };
    }
}
