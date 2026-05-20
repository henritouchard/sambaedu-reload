<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.3 — AC7.2.
 *
 * Validation permissive du body de `GET|POST /ipxe/enrollment/room`.
 * Ajoute `room` (int positif) à la base iso `IpxeBootRequest`.
 *
 * **Pas de `exists:workstation_groups,id`** — la validation business est dans
 * {@see \App\Ipxe\Services\WorkstationEnrollmentService::assignRoom()} qui
 * retourne `false` sur ID invalide (l'iPXE doit recevoir un menu d'erreur,
 * pas un 422).
 */
class IpxeEnrollmentRoomRequest extends FormRequest
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
            'room' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
