<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.3 — AC7.2.
 *
 * Validation permissive du body de `GET|POST /ipxe/enrollment/name`. Règles
 * iso `IpxeBootRequest` (3.1) + `new_name` optionnel (saisie utilisateur).
 *
 * **Sanitisation business** : tout `new_name` reçu est passé par
 * {@see \App\Ipxe\Services\IpxeHostnameSanitizer} côté service — pas de
 * `regex` ici (sinon iPXE recevrait un 422 HTML/JSON au lieu d'un menu
 * d'erreur).
 *
 * `authorize()` retourne `true` — auth via middleware `auth.v1.lan-only` (D3).
 */
class IpxeEnrollmentNameRequest extends FormRequest
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
            'mac' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:64'],
            'product' => ['nullable', 'string', 'max:128'],
            'platform' => ['nullable', 'string', 'in:legacy,uefi'],
            // `new_name` accepte jusqu'à 64 chars en input — sanitize côté service.
            'new_name' => ['nullable', 'string', 'max:64'],
        ];
    }
}
