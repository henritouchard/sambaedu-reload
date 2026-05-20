<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.3 — AC7.2.
 *
 * Validation permissive du body de `GET|POST /ipxe/enrollment/byod`.
 * Iso `IpxeEnrollmentNameRequest`.
 */
class IpxeEnrollmentByodRequest extends FormRequest
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
            'new_name' => ['nullable', 'string', 'max:64'],
        ];
    }
}
