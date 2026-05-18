<?php

declare(strict_types=1);

namespace App\ScriptsOs\Http\Requests;

use App\ScriptsOs\Enums\ScriptExecutionAction;
use App\ScriptsOs\Enums\ScriptExecutionOs;
use App\ScriptsOs\Enums\ScriptExecutionSource;
use App\ScriptsOs\Enums\ScriptExecutionStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Story 16.12 — AC2.2 / D3.
 *
 * FormRequest pour `POST /api/v1/script-execution-logs`. Validation stricte
 * du payload émis par le wrapper côté poste :
 *
 *  - Tous les champs enums sont vérifiés contre les BackedEnum.
 *  - `stdout` / `stderr` plafonnés à 16 KB côté request (le mutator
 *    Model truncate ensuite à 8 KB pour la persistence).
 *  - `started_at` borné par `withValidator()` (anti-replay + anti-clock-skew) :
 *      - pas plus de 5 min dans le futur (skew tolérance)
 *      - pas plus de 7 jours dans le passé (un retry tardif après reboot
 *        peut avoir un peu de retard, mais > 7j → log obsolète, on rejette)
 *
 * **Authz** : `authorize()` retourne `true` — l'authentification est gérée
 * par le middleware `auth.v1.workstation` en amont (16.10).
 */
class IngestScriptExecutionLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public function rules(): array
    {
        return [
            'script_id' => ['nullable', 'integer', 'min:1'],
            'script_source' => ['required', 'string', Rule::in(ScriptExecutionSource::values())],
            'action' => ['required', 'string', Rule::in(ScriptExecutionAction::values())],
            'os' => ['required', 'string', Rule::in(ScriptExecutionOs::values())],
            'status' => ['required', 'string', Rule::in(ScriptExecutionStatus::values())],
            'exit_code' => ['nullable', 'integer', 'between:-2147483648,2147483647'],
            // 16 KB max côté request — le model truncate ensuite à 8 KB
            // (mutator). Garde-fou supplémentaire contre les POST monstrueux.
            'stdout' => ['nullable', 'string', 'max:16384'],
            'stderr' => ['nullable', 'string', 'max:16384'],
            'started_at' => ['required', 'date'],
            'duration_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            // Story 16.12 post-review Q3 (Opus-A) — `correlation_id` désormais
            // **required** pour mitiger un replay JWT capturé sur LAN. Un
            // attaquant qui modifie le correlation_id casse l'idempotence du
            // wrapper légitime → forcé à réutiliser celui capturé → dédupliqué
            // par UNIQUE pgsql `sel_ws_corr_unique`. Le wrapper renderer 16.12
            // génère **toujours** un UUID (D4) → transparent côté postes.
            'correlation_id' => ['required', 'uuid'],
        ];
    }

    /**
     * Validation custom — anti-replay + anti-clock-skew sur `started_at`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $startedAtRaw = $this->input('started_at');
            if (! is_string($startedAtRaw) && ! is_numeric($startedAtRaw)) {
                return;
            }

            try {
                $startedAt = Carbon::parse((string) $startedAtRaw);
            } catch (\Throwable) {
                // La règle 'date' a déjà ajouté une erreur — pas besoin de dupliquer.
                return;
            }

            $skewFuture = (int) config('scriptsos.started_at_skew_seconds_future', 300);
            $skewPast = (int) config('scriptsos.started_at_skew_seconds_past', 7 * 86400);

            if ($startedAt->isAfter(Carbon::now()->addSeconds($skewFuture))) {
                $v->errors()->add(
                    'started_at',
                    'started_at.future',
                );
            }

            if ($startedAt->isBefore(Carbon::now()->subSeconds($skewPast))) {
                $v->errors()->add(
                    'started_at',
                    'started_at.too_old',
                );
            }
        });
    }
}
