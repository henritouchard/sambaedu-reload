<?php

declare(strict_types=1);

namespace App\ScriptsOs\Enums;

/**
 * Story 16.12 — D1 / D2.
 *
 * Type d'évènement déclencheur d'une exécution de script côté poste.
 *
 *  - `logon`     — script user-logon (Windows GPO User Logon / Linux session logon)
 *  - `startup`   — script machine-startup (avant logon, contexte SYSTEM/root)
 *  - `shutdown`  — script machine-shutdown
 *  - `logoff`    — script user-logoff
 *  - `oneshot`   — script exécuté manuellement (ex: maintenance admin via SSH/PsExec)
 *
 * BackedEnum string (portabilité storage = colonne `action` string(16) en DB +
 * cast Eloquent natif).
 */
enum ScriptExecutionAction: string
{
    case LOGON = 'logon';
    case STARTUP = 'startup';
    case SHUTDOWN = 'shutdown';
    case LOGOFF = 'logoff';
    case ONESHOT = 'oneshot';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
