<?php

declare(strict_types=1);

namespace App\ScriptsOs\Enums;

/**
 * Story 16.12 — D1 / D2.
 *
 * OS du poste qui exécute le script. Détermine la variante de wrapper rendu
 * par `WrapperScriptRenderer` (`.cmd` Windows vs `.sh` Linux).
 */
enum ScriptExecutionOs: string
{
    case WINDOWS = 'windows';
    case LINUX = 'linux';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
