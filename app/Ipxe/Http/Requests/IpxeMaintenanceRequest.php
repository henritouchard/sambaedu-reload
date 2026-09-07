<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation permissive du body de `GET|POST /ipxe/maintenance`. Règles iso
 * `IpxeBootRequest`.
 *
 * `authorize()` retourne `true` — l'auth est portée par le middleware
 * `auth.v1.lan-only`.
 */
class IpxeMaintenanceRequest extends FormRequest
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
