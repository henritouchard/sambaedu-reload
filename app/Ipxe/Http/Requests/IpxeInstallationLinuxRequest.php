<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.4 — AC5.5.
 *
 * Validation permissive du body de `GET|POST /ipxe/installation-linux`.
 * Règles iso `IpxeAdminRequest` (3.2) — le firmware iPXE pose des params
 * variés et la validation business est déléguée aux normalizers.
 *
 * `authorize()` retourne `true` — l'auth est portée par le middleware
 * `auth.v1.lan-only` (D3).
 */
class IpxeInstallationLinuxRequest extends FormRequest
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
        ];
    }
}
