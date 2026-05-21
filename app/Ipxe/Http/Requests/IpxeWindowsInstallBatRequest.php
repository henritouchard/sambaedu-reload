<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Story 3.5 — AC5.7 / AC5.3.
 *
 * Validation du body de `GET|POST /ipxe/windows/install.bat`.
 *
 * **Whitelist stricte** :
 *  - `version` ∈ `config('ipxe.windows.allowed_versions')`.
 *  - `bios` ∈ `['legacy', 'uefi']`.
 *
 * Le controller revalide via {@see \App\Ipxe\Enums\WindowsVersion::fromString()}
 * (defense in depth).
 *
 * `authorize()` = true (auth via middleware `auth.v1.lan-only`).
 */
class IpxeWindowsInstallBatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $allowedVersions = (array) config(
            'ipxe.windows.allowed_versions',
            ['Win10', 'Win11'],
        );

        return [
            'mac' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:64'],
            'product' => ['nullable', 'string', 'max:128'],
            'version' => ['nullable', 'string', 'max:32', Rule::in($allowedVersions)],
            'bios' => ['nullable', 'string', 'max:16', Rule::in(['legacy', 'uefi'])],
            'debug' => ['nullable'],
            'disk' => ['nullable'],
            'perso' => ['nullable'],
            'action' => ['nullable', 'string', 'max:32'],
        ];
    }
}
