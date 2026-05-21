<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.5 — AC5.7 / AC5.1.
 *
 * Validation du body de `GET|POST /ipxe/installation-windows`.
 *
 * **Whitelist permissive** : pattern iso 3.1 `IpxeBootRequest` — un poste
 * peut poster avec MAC/UUID vides (handshake). La validation stricte se
 * fait en aval côté service.
 *
 * `authorize()` = true (auth via middleware `auth.v1.lan-only`).
 */
class IpxeInstallationWindowsRequest extends FormRequest
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
            'session_ipxe' => ['nullable', 'string', 'max:64'],
        ];
    }
}
