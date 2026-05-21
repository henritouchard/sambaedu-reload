<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.4 — AC5.5 / AC5.4.
 *
 * Validation du body de `GET|POST /ipxe/linux/autorun` (stub minimal D15).
 *
 * `authorize()` = true (auth via middleware `auth.v1.lan-only`).
 */
class IpxeLinuxAutorunRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:64'],
        ];
    }
}
