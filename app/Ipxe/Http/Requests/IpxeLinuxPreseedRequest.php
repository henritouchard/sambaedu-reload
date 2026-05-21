<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Story 3.4 — AC5.5 / AC5.2.
 *
 * Validation du body de `GET|POST /ipxe/linux/preseed`.
 *
 * **Whitelist stricte os/type** : `os` et `type` doivent appartenir aux
 * listes définies dans `config/ipxe.php` (D11). Toute autre valeur
 * retourne 422 + log warning (cf. controller).
 *
 * Note : la validation est permissive sur le nullable car le legacy
 * accepte des appels avec OS/type vides (fallback config par défaut).
 * La validation enum stricte est faite côté controller via
 * {@see \App\Ipxe\Enums\LinuxDistribution::fromString()} et
 * {@see \App\Ipxe\Enums\LinuxDesktopVariant::fromString()}.
 */
class IpxeLinuxPreseedRequest extends FormRequest
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
        // Listes étendues pour accepter les alias (trixie, focal, etc.) :
        // la résolution `LinuxDistribution::fromString()` les mappe vers
        // les 3 cases enum (debian, ubuntu, nird).
        $allowedOsAliases = (array) config('ipxe.linux.allowed_os_versions', [
            'debian', 'ubuntu', 'nird',
            'trixie', 'bookworm', 'bullseye',
            'focal', 'jammy',
        ]);

        $allowedVariants = (array) config('ipxe.linux.allowed_variants', [
            'base', 'gnome', 'lxde', 'kde', 'mate', 'xfce', 'cinnamon',
        ]);
        // Inclusion de 'nird' pour parité legacy (type=nird passe sur preseed.php).
        $allowedVariants[] = 'nird';

        return [
            'mac' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:64'],
            'product' => ['nullable', 'string', 'max:128'],
            'os' => ['nullable', 'string', 'max:32', Rule::in($allowedOsAliases)],
            'type' => ['nullable', 'string', 'max:32', Rule::in($allowedVariants)],
            'mask' => ['nullable', 'string', 'max:32'],
            'gateway' => ['nullable', 'string', 'max:32'],
            'perso' => ['nullable'],
        ];
    }
}
