<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.5 — AC5.7 / AC5.5.
 *
 * Validation du body de `GET|POST /ipxe/windows/sysprep.xml`.
 *
 * **Stub minimal D15** : la logique complète dépend de `IpxeProgrammedActionResolver`
 * non porté (3.7). En 3.5, on accepte juste `name` nullable + log info.
 *
 * `authorize()` = true (auth via middleware `auth.v1.lan-only`).
 */
class IpxeWindowsSysprepRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:64'],
            'mac' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:64'],
        ];
    }
}
