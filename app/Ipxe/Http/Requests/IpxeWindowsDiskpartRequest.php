<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.5 — AC5.7 / AC5.4.
 *
 * Validation du body de `GET|POST /ipxe/windows/diskpart.txt`.
 *
 * Minimal — le body diskpart est statique, pas d'interpolation conditionnelle
 * (parité legacy strict `diskpart.php:22-25`). On valide juste la présence
 * de MAC/UUID pour permettre le matching audit (best-effort).
 *
 * `authorize()` = true (auth via middleware `auth.v1.lan-only`).
 */
class IpxeWindowsDiskpartRequest extends FormRequest
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
            'version' => ['nullable', 'string', 'max:32'],
            'bios' => ['nullable', 'string', 'max:16'],
            'disk' => ['nullable'],
            'perso' => ['nullable'],
        ];
    }
}
