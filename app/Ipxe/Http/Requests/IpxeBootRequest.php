<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Story 3.1 — AC5.2.
 *
 * Validation **permissive** du body de `GET|POST /ipxe/boot`.
 *
 * Un firmware iPXE pose ses paramètres via `param mac ${net0/mac}` etc. —
 * les formats peuvent varier (mixed case, séparateur `:` ou `-`, voire
 * sans séparateur). La validation business du format MAC/UUID est déléguée
 * aux normalizers ({@see \App\Ipxe\Support\MacAddressNormalizer},
 * {@see \App\Ipxe\Support\UuidNormalizer}) qui retournent `null` en cas
 * d'invalide.
 *
 * **Ce FormRequest ne valide que les bornes basiques** :
 *
 *  - `mac` nullable, string, max 64 chars (large pour tolérer les variantes).
 *  - `uuid` nullable, string, max 64 chars (UUID v4 standard = 36, mais
 *    legacy `boot.php:36-41` reconstruit des UUIDs composites — on est
 *    tolérant).
 *  - `product` nullable, string, max 128 chars (un modèle hardware peut
 *    avoir un nom long).
 *
 * **Pas de regex stricte** : un poste avec MAC malformée recevra un menu
 * default (D6), pas un 422 — c'est le comportement iso-legacy attendu.
 *
 * **Pas d'`exists:` / `unique:`** : un poste inconnu est valide et doit
 * recevoir un menu default (D6), pas un 422.
 *
 * `authorize()` retourne `true` — l'auth est portée par le middleware
 * `auth.v1.lan-only` (D3).
 */
class IpxeBootRequest extends FormRequest
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
        ];
    }
}
