<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.4 — D1 / AC1.2.
 *
 * Whitelist stricte des variantes desktop Linux acceptées par
 * `/ipxe/linux/preseed`.
 *
 * **Sécurité critique** — l'enum est l'UNIQUE source de vérité des
 * variantes autorisées. Toute valeur reçue côté `IpxeLinuxPreseedController`
 * est validée via {@see self::fromString()}.
 *
 * **7 cases stricts en 3.4** :
 *
 *  - `Base`     — Debian sans desktop (serveur léger).
 *  - `Gnome`    — Debian + GNOME (défaut menu).
 *  - `Lxde`     — Debian + LXDE.
 *  - `Kde`      — Debian + KDE.
 *  - `Mate`     — Debian + MATE.
 *  - `Xfce`     — Debian + XFCE.
 *  - `Cinnamon` — Debian + Cinnamon.
 *
 * **Note Nird/Ubuntu** : ces 2 distributions n'ont pas de variante desktop
 * (le desktop est imposé par la cfg dédiée). Le controller mappe
 * `LinuxDistribution::Nird` + `LinuxDistribution::Ubuntu` → `variant = Base`
 * par défaut (lecture seule — la variante n'a pas d'impact sur l'assemblage
 * du preseed pour ces 2 distributions).
 */
enum LinuxDesktopVariant: string
{
    case Base = 'base';
    case Gnome = 'gnome';
    case Lxde = 'lxde';
    case Kde = 'kde';
    case Mate = 'mate';
    case Xfce = 'xfce';
    case Cinnamon = 'cinnamon';

    /**
     * Résout une string brute vers le case enum correspondant.
     *
     * @param  string  $raw  Valeur brute reçue du firmware iPXE
     *                        (`type=gnome`, `type=lxde`...).
     * @return self|null     Case enum ou `null` si valeur hors whitelist.
     */
    public static function fromString(string $raw): ?self
    {
        $normalized = strtolower(trim($raw));

        return self::tryFrom($normalized);
    }
}
