<?php

declare(strict_types=1);

namespace App\Gpo\Support;

use Illuminate\Support\Str;

/**
 * Façade des logs GPO — Epic 16.
 *
 * Entrée unique pour démarrer une action loggée. Chaque appel retourne un
 * {@see GpoActionLog} qui doit recevoir un `success()` ou un `failure()`
 * pour clôturer l'action.
 *
 * Convention : voir `app/Gpo/README.md` § Convention de logging Epic 16 pour
 * le catalogue complet des `action_type`.
 *
 * Exemple d'utilisation :
 *
 * ```php
 * $log = GpoLogger::action('gpo.list', context: ['gpo_name' => null]);
 * try {
 *     $log->step('starting samba-tool exec');
 *     $gpos = $this->runner->execute(['gpo', 'listall']);
 *     $log->success(['count' => count($gpos)]);
 *     return $gpos;
 * } catch (\Throwable $e) {
 *     $log->failure($e);
 *     throw $e;
 * }
 * ```
 */
final class GpoLogger
{
    /** Nom du channel Laravel (cf. `config/logging.php`). */
    public const CHANNEL = 'gpo';

    /**
     * Démarre une nouvelle action loggée. Émet immédiatement un log `start`
     * et retourne un handle pour les logs suivants.
     *
     * @param  string  $type  Type d'action (catalogue dans README.md — `gpo.list`,
     *                        `gpo.show`, `gpo.section.write`, etc.).
     * @param  string|null  $operationId  UUID v4. Auto-généré si null.
     * @param  array<string,mixed>  $context  Contexte additionnel propagé sur tous
     *                                        les logs de l'action (ex. `gpo_name`,
     *                                        `target_dn`, `caller`).
     */
    public static function action(string $type, ?string $operationId = null, array $context = []): GpoActionLog
    {
        return new GpoActionLog(
            actionType: $type,
            operationId: $operationId ?? (string) Str::uuid(),
            channel: self::CHANNEL,
            baseContext: $context,
        );
    }
}
