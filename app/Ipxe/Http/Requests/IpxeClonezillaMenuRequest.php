<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.7 — AC3.2.
 *
 * Validation permissive du body de `GET|POST /ipxe/clonezilla-menu`. Règles iso
 * `IpxeMaintenanceRequest` (3.2).
 *
 * `authorize()` retourne `true` — l'auth est portée par le middleware
 * `auth.v1.lan-only` (D5).
 */
class IpxeClonezillaMenuRequest extends FormRequest
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
        // Post-review #4 — parité stricte iso `IpxeMaintenanceRequest`. Le
        // service `IpxeService::handleClonezillaMenu()` LIT `product`
        // (ligne 302) — il faut donc le valider. `session_ipxe` était orphelin
        // (jamais lu), retiré.
        return [
            'mac' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:64'],
            'product' => ['nullable', 'string', 'max:128'],
        ];
    }
}
