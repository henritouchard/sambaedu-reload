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
 * **Whitelist stricte étapes 3.8** (D5 / AC2.1) :
 *  - `etape` ∈ `['winpe', 'oobe', 'sysprep', 'nosysprep', 'join', 'renomme',
 *    'post', 'wpkg']` (defense in depth — l'enum {@see WindowsInstallStep}
 *    reste l'autorité finale côté controller).
 *
 * **Post-review code-review #5 + #N3 (décision Henri 2026-05-21)** : en 3.5
 * la validation `etape` était `nullable|string|max:32` SANS `Rule::in` car les
 * postes WinPE legacy en production POSTaient déjà `sysprep|nosysprep|join|
 * renomme|post|wpkg` (non portées) → 422 aurait cassé les postes en transition.
 *
 * **Story 3.8** : les 6 étapes ci-dessus sont maintenant portées natives — on
 * peut donc remettre `Rule::in` (defense in depth strict). Note : un poste qui
 * POST `etape=arbitrary` rece 422 (FormRequest rejette) mais un poste legacy
 * pré-3.5 qui POST une étape future inconnue (ex: `etape=v3`) recevra aussi
 * 422 — comportement attendu (le SE5 est l'autorité).
 *
 * **Post-review code-review #N5 (décision Henri 2026-05-21)** : `ret` est
 * strict via `Rule::in(['0', '1', '2', '-1'])` — defense in depth (un attaquant
 * LAN ne peut plus poster `ret=arbitrary-string`). Story 3.8 étend avec `2`
 * (sysprep KO → mode clonage sans sysprep + join ret=2 → clonage terminé).
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
            // Story 3.8 — D5 / AC2.1 : whitelist 8 étapes (defense in depth
            // avant enum WindowsInstallStep::fromString).
            'etape' => ['nullable', 'string', 'max:32', Rule::in([
                'winpe',
                'oobe',
                'sysprep',
                'nosysprep',
                'join',
                'renomme',
                'post',
                'wpkg',
            ])],
            // Post-review #N5 (3.5) + Story 3.8 AC2.2 : `ret` strict — `2`
            // ajouté pour les variantes sysprep KO / join clonage terminé.
            'ret' => ['nullable', 'string', Rule::in(['0', '1', '2', '-1'])],
            // Story 3.8 — paramètres optionnels portés par le legacy.
            // `role` = nouveau nom du poste lors du `etape=renomme&ret=0`.
            // `ou` = LDAP OU pour le `etape=join` `Add-Computer -OUPath`.
            'role' => ['nullable', 'string', 'max:128'],
            'ou' => ['nullable', 'string', 'max:512'],
        ];
    }
}
