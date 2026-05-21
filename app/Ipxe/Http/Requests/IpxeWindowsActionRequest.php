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
 * **Post-review code-review #5 + #N3 (décision Henri 2026-05-21)** : la spec
 * AC5.7 demandait `Rule::in(['winpe', 'oobe'])` mais on l'a déviée
 * volontairement. La validation `etape` reste `nullable|string|max:32` (sans
 * `Rule::in`) pour deux raisons :
 *  1. Les postes WinPE legacy en production POST déjà des étapes
 *     `sysprep|nosysprep|join|renomme|post|wpkg` qui ne sont pas encore
 *     portées en SE5 (déférées 3.7). Renvoyer 422 casserait ces postes en
 *     transition.
 *  2. Le controller fait l'enum check via {@see WindowsInstallStep::fromString()}
 *     et répond 200 + log warning `ipxe.windows.action.unsupported_step` sur
 *     étape inconnue. Cf. commentaire dans `IpxeWindowsActionController::__invoke()`.
 *
 * **Post-review code-review #N5 (décision Henri 2026-05-21)** : `ret` est
 * strict via `Rule::in(['0', '1', '-1'])` — defense in depth (un attaquant
 * LAN ne peut plus poster `ret=arbitrary-string`).
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
            // Post-review #N5 : `ret` strict via Rule::in (defense in depth).
            'ret' => ['nullable', 'string', \Illuminate\Validation\Rule::in(['0', '1', '-1'])],
        ];
    }
}
