<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 16.10 — AC5.2 / T6.2.
 *
 * Validation supplémentaire du body de `POST /api/v1/agent/refresh` :
 *
 *  - `refresh_token` : 64 chars hex (sha256-compatible).
 *
 * Le middleware `EnsureRefreshToken` fait déjà cette validation pour
 * formater une réponse `{code:refresh.missing}` cohérente. Le FormRequest
 * couvre le cas où la route serait appelée sans middleware (sécurité
 * défense en profondeur — utile en tests Feature avec `withoutMiddleware`).
 */
class RefreshTokenRequest extends FormRequest
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
            'refresh_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ];
    }
}
