<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Story 55.2 — capture des enregistrements du channel `oidc` pour vérifier que
 * les **codes fins** partent bien au journal (et que la PII n'y part PAS).
 *
 * ⚠️ `Log::spy()` seul ne suffit pas : le code écrit via `Log::channel('oidc')`,
 * et un spy renverrait `null` sur `channel()` — l'appel de niveau suivant
 * planterait. On stubbe donc `channel()` en `andReturnSelf()` (patron
 * `tests/Unit/Auth/V1/Services/MigrationAttemptRecorderTest.php`), et on
 * intercepte tous les niveaux.
 *
 * Ce que ça permet de prouver, et qu'aucune assertion sur la réponse HTTP ne
 * peut prouver : la réponse est volontairement MUETTE (pas d'oracle), donc la
 * seule trace exploitable du motif réel est le journal. Un journal muet
 * rendrait toute intégration ratée indiagnosticable (FR20).
 */
trait CapturesOidcLogs
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    protected array $capturedLogs = [];

    protected function captureLogs(): void
    {
        $this->capturedLogs = [];

        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
            Log::shouldReceive($level)->andReturnUsing(
                function (string $message = '', array $context = []) use ($level): void {
                    $this->capturedLogs[] = [
                        'level' => $level,
                        'message' => $message,
                        'context' => $context,
                    ];
                }
            );
        }
    }

    /** Contextes journalisés portant un `action_type` donné. */
    protected function logContextsOfType(string $actionType): array
    {
        return array_values(array_filter(
            array_column($this->capturedLogs, 'context'),
            static fn (array $context): bool => ($context['action_type'] ?? null) === $actionType,
        ));
    }

    /** Codes INTERNES journalisés (`OidcErrorCodes::*`). */
    protected function loggedCodes(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $record): mixed => $record['context']['code'] ?? null,
            $this->capturedLogs,
        )));
    }

    /** Le journal complet, aplati — pour prouver l'ABSENCE d'une valeur (PII). */
    protected function flattenedLogs(): string
    {
        return json_encode($this->capturedLogs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
