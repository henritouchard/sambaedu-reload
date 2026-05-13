<?php

declare(strict_types=1);

namespace App\Gpo\Enums;

use InvalidArgumentException;

/**
 * Codes d'erreur bitmask des scripts applications.
 *
 * Port natif des constantes legacy `SAMBAEDU_*_APP_ERROR` définies dans
 * `sambaedu/includes/config.inc.php:36-44` :
 *
 * ```php
 * define('SAMBAEDU_STARTUP_APP_ERROR',     256);
 * define('SAMBAEDU_SHUTDOWN_APP_ERROR',    512);
 * define('SAMBAEDU_LOGON_APP_ERROR',      1024);
 * define('SAMBAEDU_LOGOFF_APP_ERROR',     2048);
 * define('SAMBAEDU_LOGON_SYS_APP_ERROR',  4096);
 * define('SAMBAEDU_LOGOFF_SYS_APP_ERROR', 8192);
 * define('SAMBAEDU_WPKG_ERROR',          32768);
 * ```
 *
 * Story 16.7 — décision user D4 (2026-05-12) : ces constantes sont **100%
 * internes serveur** (consommées par `MachineBootLog::error` + UI admin) et
 * **n'apparaissent jamais sur la fil HTTP** côté postes Windows → migration
 * en BackedEnum int safe (aucune rupture binaire client).
 *
 * @legacy-port path="sambaedu/includes/config.inc.php:36-44"
 * @see \App\Gpo\Services\ApplicationLoggerService::logScripts() Consommateur primaire.
 */
enum ApplicationActionError: int
{
    case STARTUP = 256;
    case SHUTDOWN = 512;
    case LOGON = 1024;
    case LOGOFF = 2048;
    case LOGON_SYS = 4096;
    case LOGOFF_SYS = 8192;
    case WPKG = 32768;

    /**
     * Résout le bitmask correspondant à une action runtime
     * (`startup`/`logon`/...) — parité exacte du tableau `$err` dans
     * `applications.inc.php:787-795`.
     *
     * @throws InvalidArgumentException Si l'action ne correspond à aucun cas connu.
     */
    public static function fromAction(string $action): self
    {
        return match ($action) {
            'startup' => self::STARTUP,
            'shutdown' => self::SHUTDOWN,
            'logon' => self::LOGON,
            'logoff' => self::LOGOFF,
            'logon-system' => self::LOGON_SYS,
            'logoff-system' => self::LOGOFF_SYS,
            'wpkg' => self::WPKG,
            default => throw new InvalidArgumentException(sprintf(
                'Action inconnue pour ApplicationActionError : "%s"',
                $action,
            )),
        };
    }

    /**
     * Valeur bitmask brute (alias explicite de `->value` pour cohérence
     * sémantique avec le legacy `$err[$action]` qui retournait un int pur).
     */
    public function bitmask(): int
    {
        return $this->value;
    }
}
