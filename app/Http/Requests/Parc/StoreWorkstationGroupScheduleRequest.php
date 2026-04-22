<?php

declare(strict_types=1);

namespace App\Http\Requests\Parc;

use App\Models\WorkstationGroupSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Story 4-4 (v0.2 D7) — Validation de création d'une programmation.
 *
 * Règles conditionnelles selon `mode` :
 *  - recurring → days_of_week + time_of_day + timezone requis ; run_at prohibé.
 *  - one_shot  → run_at requis + after:now ; days_of_week / time_of_day / timezone prohibés.
 *
 * L'exclusivité est aussi garantie au niveau DB par la contrainte CHECK
 * `wgs_mode_exclusivity` (défense en profondeur AC20).
 */
class StoreWorkstationGroupScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('computer.control') ?? false;
    }

    public function rules(): array
    {
        $mode = $this->input('mode', WorkstationGroupSchedule::MODE_RECURRING);

        $rules = [
            'workstation_group_id' => ['required', 'integer', 'exists:workstation_groups,id'],
            'mode' => ['required', Rule::in(WorkstationGroupSchedule::SUPPORTED_MODES)],
            'action' => ['required', Rule::in(WorkstationGroupSchedule::SUPPORTED_ACTIONS)],
            'enabled' => ['sometimes', 'boolean'],
        ];

        if ($mode === WorkstationGroupSchedule::MODE_RECURRING) {
            $rules['days_of_week'] = ['required', 'array', 'min:1', 'max:7'];
            $rules['days_of_week.*'] = ['integer', 'between:1,7'];
            $rules['time_of_day'] = ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'];
            $rules['timezone'] = ['required', Rule::in(WorkstationGroupSchedule::SUPPORTED_TIMEZONES)];
            $rules['run_at'] = ['prohibited'];
        } elseif ($mode === WorkstationGroupSchedule::MODE_ONE_SHOT) {
            $rules['run_at'] = ['required', 'date', 'after:now'];
            $rules['days_of_week'] = ['prohibited'];
            $rules['time_of_day'] = ['prohibited'];
            $rules['timezone'] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'mode.required' => 'Le mode (récurrent / date unique) est requis.',
            'mode.in' => 'Le mode doit être "recurring" ou "one_shot".',
            'action.required' => 'Une action (wake/shutdown) est requise.',
            'action.in' => 'Seules les actions « wake » et « shutdown » sont autorisées.',
            'days_of_week.required' => 'Au moins un jour de la semaine est requis.',
            'days_of_week.min' => 'Au moins un jour de la semaine est requis.',
            'days_of_week.*.between' => 'Chaque jour doit être entre 1 (lundi) et 7 (dimanche).',
            'time_of_day.required' => "L'heure d'exécution est requise.",
            'time_of_day.regex' => "L'heure doit être au format HH:MM (ou HH:MM:SS).",
            'timezone.required' => 'Le fuseau horaire est requis.',
            'timezone.in' => 'Fuseau horaire non supporté (France métropole et DOM-TOM uniquement).',
            'run_at.required' => "La date d'exécution est requise pour un one-shot.",
            'run_at.after' => "La date d'exécution doit être dans le futur.",
            'run_at.prohibited' => "Le champ run_at ne peut pas être renseigné pour un schedule récurrent.",
            'days_of_week.prohibited' => "Les jours de la semaine ne peuvent pas être renseignés pour un one-shot.",
            'time_of_day.prohibited' => "L'heure récurrente ne peut pas être renseignée pour un one-shot.",
            'timezone.prohibited' => "La timezone ne peut pas être renseignée pour un one-shot.",
        ];
    }
}
