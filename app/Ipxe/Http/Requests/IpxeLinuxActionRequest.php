<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.4 — AC5.5 / AC5.3.
 *
 * Validation du body de `GET|POST /ipxe/linux/action`.
 *
 * **Parité legacy** (`preseed.cfg:83`) :
 *   curl -F 'ret=0' -F 'uuid=<uuid>' -F 'name=<hostname>' http://se4fs/ipxe/linux/action
 *
 * `authorize()` = true (auth via middleware `auth.v1.lan-only`).
 */
class IpxeLinuxActionRequest extends FormRequest
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
            'uuid' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:64'],
            'ret' => ['nullable', 'integer'],
        ];
    }
}
