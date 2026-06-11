<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 23.3 — payload de `POST /api/v1/agent/enrollment`.
 *
 * `ticket` est volontairement `nullable` (pas `required`) : une demande SANS
 * ticket doit suivre le chemin métier 409/403 de l'AC4 (futur point d'accueil
 * de la porte 2 — Story 25.3), pas un 422 de validation. Seuls les types et
 * bornes sont validés ici ; l'autorisation est portée par le hash du ticket
 * dans {@see \App\Services\Agent\Enrollment\EnrollmentService::redeem()}.
 */
class EnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Endpoint poste sans auth préalable (le ticket EST l'identité) —
        // le périmètre réseau est porté par le middleware `local.request`.
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ticket' => ['nullable', 'string', 'max:128'],
            'uuid' => ['nullable', 'string', 'max:64'],
            'mac' => ['nullable', 'string', 'max:32'],
            'hostname' => ['nullable', 'string', 'max:255'],
        ];
    }
}
