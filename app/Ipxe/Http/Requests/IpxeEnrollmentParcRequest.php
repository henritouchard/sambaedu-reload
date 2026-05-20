<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.3 — AC7.2.
 *
 * Validation permissive partagée par `GET|POST /ipxe/enrollment/parc-add` et
 * `GET|POST /ipxe/enrollment/parc-remove`. Ajoute `parc` (int positif) à la
 * base iso `IpxeBootRequest`.
 *
 * **Pas de `exists:workstation_groups,id`** — validation business côté
 * `WorkstationEnrollmentService`.
 */
class IpxeEnrollmentParcRequest extends FormRequest
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
        return [
            'mac' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:64'],
            'product' => ['nullable', 'string', 'max:128'],
            'parc' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
