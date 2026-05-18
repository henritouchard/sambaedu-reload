<?php

declare(strict_types=1);

namespace App\ScriptsOs\Enums;

/**
 * Story 16.12 — D1 / D2.
 *
 * Résultat applicatif de l'exécution.
 *
 *  - `success` — exit_code 0
 *  - `failure` — exit_code != 0 (ou wrapper crash)
 *  - `skipped` — wrapper a décidé de ne pas exécuter (ex: conditions GPO non remplies)
 *  - `timeout` — exit_code 124 (timeout) ou détection wrapper interne
 */
enum ScriptExecutionStatus: string
{
    case SUCCESS = 'success';
    case FAILURE = 'failure';
    case SKIPPED = 'skipped';
    case TIMEOUT = 'timeout';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
