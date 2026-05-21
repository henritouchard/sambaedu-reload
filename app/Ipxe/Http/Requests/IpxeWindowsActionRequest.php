<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Story 3.5 — AC5.7 / AC5.6.
 *
 * Validation du body de `POST /ipxe/windows/action` (parité legacy curl `-F`
 * multipart).
 *
 * **Whitelist stricte étapes 3.5** :
 *  - `etape` ∈ `['winpe', 'oobe']` (D15 — scope 3.5).
 *
 * Note : un poste qui poste `etape=sysprep|nosysprep|join|renomme|post|wpkg`
 * passe la validation (`nullable`) puis le controller répond 200 + log
 * warning `ipxe.windows.action.unsupported_step` (déféré 3.7). Cf. D4.
 *
 * Pour simplifier : on accepte n'importe quelle string `etape` ≤32 chars +
 * le controller fait l'enum check. Cohérent avec parité legacy
 * (`action.php:489` `default → http_response_code(403)`).
 *
 * `authorize()` = true (auth via middleware `auth.v1.lan-only`).
 */
class IpxeWindowsActionRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:64'],
            'mac' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:64'],
            'etape' => ['nullable', 'string', 'max:32'],
            'ret' => ['nullable', 'string', 'max:8'],
        ];
    }
}
