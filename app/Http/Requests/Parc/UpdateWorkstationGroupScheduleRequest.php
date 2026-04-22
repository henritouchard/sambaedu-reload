<?php

declare(strict_types=1);

namespace App\Http\Requests\Parc;

use App\Models\WorkstationGroupSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Story 4-4 (v0.2 D7) — Validation de mise à jour partielle d'un schedule.
 *
 * Même règles conditionnelles que Store, mais tous les champs sont optionnels
 * (`sometimes`). Le service lève DomainException si le schedule est un
 * one-shot terminé (AC23).
 */
class UpdateWorkstationGroupScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('computer.control') ?? false;
    }

    public function rules(): array
    {
        $mode = $this->input('mode', WorkstationGroupSchedule::MODE_RECURRING);

        $rules = [
            'mode' => ['sometimes', Rule::in(WorkstationGroupSchedule::SUPPORTED_MODES)],
            'action' => ['sometimes', Rule::in(WorkstationGroupSchedule::SUPPORTED_ACTIONS)],
            'enabled' => ['sometimes', 'boolean'],
        ];

        if ($mode === WorkstationGroupSchedule::MODE_RECURRING) {
            $rules['days_of_week'] = ['sometimes', 'array', 'min:1', 'max:7'];
            $rules['days_of_week.*'] = ['integer', 'between:1,7'];
            $rules['time_of_day'] = ['sometimes', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'];
            $rules['timezone'] = ['sometimes', Rule::in(WorkstationGroupSchedule::SUPPORTED_TIMEZONES)];
            $rules['run_at'] = ['prohibited'];
        } elseif ($mode === WorkstationGroupSchedule::MODE_ONE_SHOT) {
            $rules['run_at'] = ['sometimes', 'date', 'after:now'];
            $rules['days_of_week'] = ['prohibited'];
            $rules['time_of_day'] = ['prohibited'];
            $rules['timezone'] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return (new StoreWorkstationGroupScheduleRequest())->messages();
    }
}
