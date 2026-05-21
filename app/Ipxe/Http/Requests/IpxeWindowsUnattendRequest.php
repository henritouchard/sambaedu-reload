<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Story 3.5 — AC5.7 / AC5.2.
 *
 * Validation du body de `GET|POST /ipxe/windows/unattend.xml`.
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
class IpxeWindowsUnattendRequest extends FormRequest
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
            'disk' => ['nullable'],
            'perso' => ['nullable'],
        ];
    }

    /**
     * Override le comportement Laravel par défaut (redirect 302 sur route web)
     * pour renvoyer 422 text/plain — l'endpoint sert un text/plain consommé
     * par WinPE/iPXE, pas du HTML.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            new Response('', Response::HTTP_UNPROCESSABLE_ENTITY, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Robots-Tag' => 'noindex',
            ]),
        );
    }
}
