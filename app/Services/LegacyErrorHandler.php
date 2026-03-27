<?php

namespace App\Services;

/**
 * Handlers PHP pour capturer les erreurs et exceptions du code legacy.
 *
 * Ces handlers seront branchés dans legacy/bootstrap.php (story 1bis.2).
 * Pour l'instant, seul le code est créé — le branchement viendra plus tard.
 */
class LegacyErrorHandler
{
    /**
     * Handler pour set_error_handler() — capture les warnings/errors PHP legacy.
     *
     * @return bool false pour laisser le handler PHP par défaut s'exécuter aussi
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // Ne pas logger les erreurs volontairement supprimées via @
        if (!(error_reporting() & $errno)) {
            return false;
        }

        // Ignorer les warnings require/include — le fatal qui suit sera capturé
        // par le try/catch de executeViaBootstrap avec le contexte complet.
        if ($errno === E_WARNING && preg_match('/^(require|include)/', $errstr)) {
            return false;
        }

        if (function_exists('app') && app()->bound(ErrorLoggerService::class)) {
            app(ErrorLoggerService::class)->log('legacy', "{$errstr} in {$errfile}:{$errline}");
        }

        return false;
    }

    /**
     * Handler pour set_exception_handler() — capture les exceptions non catchées en contexte legacy.
     */
    public static function handleException(\Throwable $exception): void
    {
        if (function_exists('app') && app()->bound(ErrorLoggerService::class)) {
            app(ErrorLoggerService::class)->log('legacy', $exception->getMessage());
        }

        throw $exception;
    }
}
