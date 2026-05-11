<?php

declare(strict_types=1);

namespace App\Gpo\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handle d'action loggée GPO — émis par {@see GpoLogger::action()}.
 *
 * Convention Epic 16 : chaque action GPO (lecture, écriture, sync, audit)
 * émet au moins 3 logs : `start` (constructeur), `step` (étape intermédiaire),
 * `end` (success ou failure). Toutes les méthodes propagent automatiquement
 * `operation_id`, `action_type` et la durée écoulée dans le contexte Monolog.
 *
 * Voir `app/Gpo/README.md` § Convention de logging Epic 16 pour le catalogue
 * complet des `action_type` reconnus.
 */
final class GpoActionLog
{
    /**
     * Taille maximale (octets) au-delà de laquelle stdout/stderr sont tronqués
     * dans les logs `gpo.sambatool.exec` (avec marker `[truncated]`).
     */
    public const STDIO_TRUNCATE_BYTES = 8 * 1024;

    /** Timestamp (microsecondes) du démarrage de l'action — utilisé pour la durée. */
    private readonly float $startedAt;

    private bool $closed = false;

    /**
     * @param  array<string,mixed>  $baseContext  Contexte additionnel pushé sur tous les logs.
     */
    public function __construct(
        private readonly string $actionType,
        private readonly string $operationId,
        private readonly string $channel,
        private readonly array $baseContext = [],
    ) {
        $this->startedAt = microtime(true);

        $this->emit('info', sprintf('[gpo] %s start', $this->actionType), [
            'outcome' => 'start',
        ]);
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function actionType(): string
    {
        return $this->actionType;
    }

    /**
     * Log d'étape intermédiaire (niveau `info` par défaut, `debug` si verbeux).
     *
     * @param  array<string,mixed>  $context
     */
    public function step(string $message, array $context = [], string $level = 'info'): void
    {
        $this->emit($level, sprintf('[gpo] %s step: %s', $this->actionType, $message), $context);
    }

    /**
     * Log de succès — clôture l'action. Idempotent (no-op si déjà clos).
     *
     * @param  array<string,mixed>  $context
     */
    public function success(array $context = []): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $this->emit('info', sprintf('[gpo] %s success', $this->actionType), array_merge($context, [
            'outcome' => 'success',
        ]));
    }

    /**
     * Log d'échec — clôture l'action et inclut les détails de l'exception.
     * Idempotent (no-op si déjà clos).
     *
     * Note : le champ `error.trace` n'est inclus qu'au niveau log `debug` pour
     * éviter de polluer prod (gros volume de stacktraces). En revanche
     * `error.class` et `error.message` sont toujours présents.
     *
     * @param  array<string,mixed>  $context
     */
    public function failure(Throwable $e, array $context = []): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $errorPayload = [
            'class' => $e::class,
            'message' => $e->getMessage(),
        ];

        if ($this->isDebugLevel()) {
            $errorPayload['trace'] = $e->getTraceAsString();
        }

        $errorContext = [
            'outcome' => 'failure',
            'error' => $errorPayload,
        ];

        $this->emit('error', sprintf('[gpo] %s failure: %s', $this->actionType, $e->getMessage()), array_merge($context, $errorContext));
    }

    /**
     * Log de diff structuré (avant / après) — utile sur les mutations
     * (`section.write`, `link.set`, `inheritance.set`, etc.).
     */
    public function diff(string $what, mixed $before, mixed $after): void
    {
        $this->emit('info', sprintf('[gpo] %s diff: %s', $this->actionType, $what), [
            'diff' => [
                'what' => $what,
                'before' => $before,
                'after' => $after,
            ],
        ]);
    }

    /**
     * Log d'exécution `samba-tool` (niveau debug) — émis par
     * {@see SambaToolRunner}. Tronque stdout/stderr à 8 Ko.
     *
     * @param  list<string>  $command  Arguments en mode array (pas concat string).
     * @param  array<string,mixed>  $context
     */
    public function sambaToolExec(array $command, int $exitCode, string $stdout, string $stderr, float $durationMs, array $context = []): void
    {
        $this->emit('debug', '[gpo] samba-tool exec', array_merge($context, [
            'action_type_extra' => 'gpo.sambatool.exec',
            'command' => $command,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'stdout' => $this->truncate($stdout),
            'stderr' => $this->truncate($stderr),
        ]));
    }

    /**
     * Tronque une chaîne à `STDIO_TRUNCATE_BYTES` (8 Ko) avec marker explicite.
     */
    private function truncate(string $output): string
    {
        if (strlen($output) <= self::STDIO_TRUNCATE_BYTES) {
            return $output;
        }

        return substr($output, 0, self::STDIO_TRUNCATE_BYTES) . "\n[truncated]";
    }

    /**
     * Vérifie si le channel `gpo` est configuré au niveau `debug`
     * (ou plus verbeux). Détermine si on inclut les détails sensibles
     * (stacktrace, dumps complets) dans les logs.
     */
    private function isDebugLevel(): bool
    {
        $level = strtolower((string) config('logging.channels.'.$this->channel.'.level', 'info'));

        return $level === 'debug';
    }

    /**
     * Émet effectivement le log via le channel Laravel.
     *
     * @param  array<string,mixed>  $extraContext
     */
    private function emit(string $level, string $message, array $extraContext): void
    {
        $elapsedMs = (microtime(true) - $this->startedAt) * 1000.0;

        $context = array_merge($this->baseContext, $extraContext, [
            'operation_id' => $this->operationId,
            'action_type' => $this->actionType,
            'elapsed_ms' => round($elapsedMs, 2),
        ]);

        Log::channel($this->channel)->log($level, $message, $context);
    }
}
