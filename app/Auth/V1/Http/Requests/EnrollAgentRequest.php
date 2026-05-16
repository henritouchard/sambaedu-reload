<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 16.10 — AC5.1.
 *
 * Validation du body de `POST /api/v1/agent/enroll`.
 *
 * - `uuid` : UUID strict (poste qui s'enrôle)
 * - `mac`  : MAC address regex (`AA:BB:CC:DD:EE:FF` ou `AA-BB-...`)
 * - `hostname` : max 64 chars (NetBIOS limit)
 * - `os` : enum `windows|linux`
 *
 * Réponses : 422 standard Laravel pour les erreurs de validation.
 *
 * **Pas d'auth web** : ce FormRequest est appelé après le middleware
 * `RequireBootstrapToken` qui a déjà validé le token. `authorize()`
 * retourne `true` pour permettre l'accès.
 */
class EnrollAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'string', 'uuid'],
            'mac' => ['required', 'string', 'regex:/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$/'],
            'hostname' => ['required', 'string', 'max:64'],
            'os' => ['required', 'string', 'in:windows,linux'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'uuid.uuid' => 'uuid must be a valid UUID v4',
            'mac.regex' => 'mac must be in form AA:BB:CC:DD:EE:FF (colon or dash separator)',
            'hostname.max' => 'hostname must be 64 characters or less',
            'os.in' => 'os must be either "windows" or "linux"',
        ];
    }
}
