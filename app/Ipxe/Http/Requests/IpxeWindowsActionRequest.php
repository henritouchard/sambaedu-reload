<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation du body de `POST /ipxe/windows/action` (parité legacy curl `-F`
 * multipart).
 *
 * **Whitelist stricte des étapes** :
 *  - `etape` ∈ `['winpe', 'oobe', 'sysprep', 'nosysprep', 'join', 'renomme',
 *    'post', 'wpkg']` (defense in depth — l'enum {@see WindowsInstallStep}
 *    reste l'autorité finale côté controller).
 *
 * Un poste qui POST une étape hors liste — `etape=arbitrary` comme
 * `etape=v3` — reçoit un 422 : SE5 est l'autorité sur les étapes reconnues.
 *
 * `ret` est strict via `Rule::in(['0', '1', '2', '-1'])` (defense in depth : un
 * attaquant LAN ne peut pas poster `ret=arbitrary-string`). La valeur `2` sert
 * aux variantes sysprep KO → clonage sans sysprep, et join terminé.
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
            // Whitelist 8 étapes (defense in depth avant
            // WindowsInstallStep::fromString).
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
            // `ret` strict : `2` sert aux variantes sysprep KO / join clonage
            // terminé.
            'ret' => ['nullable', 'string', Rule::in(['0', '1', '2', '-1'])],
            // Paramètres optionnels portés par le legacy.
            // `role` = nouveau nom du poste lors du `etape=renomme&ret=0`.
            // `ou` = LDAP OU pour le `etape=join` `Add-Computer -OUPath`.
            'role' => ['nullable', 'string', 'max:128'],
            'ou' => ['nullable', 'string', 'max:512'],
        ];
    }
}
