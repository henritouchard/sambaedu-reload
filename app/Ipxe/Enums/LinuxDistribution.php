<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.4 — D1 / AC1.2.
 *
 * Whitelist stricte des distributions Linux acceptées par
 * `/ipxe/linux/preseed`.
 *
 * **Sécurité critique** — l'enum est l'UNIQUE source de vérité des
 * distributions autorisées. Toute valeur reçue côté `IpxeLinuxPreseedController`
 * est validée via {@see self::fromString()} :
 *
 *  - `null` retourné  → 422 + log warning `ipxe.linux.preseed.invalid_distribution`.
 *  - case retourné    → dispatch vers le service `LinuxPreseedService`.
 *
 * **3 cases stricts en 3.4** :
 *
 *  - `Debian` — Debian standard (avec domain AD) — variantes Gnome/LXDE/KDE/etc.
 *  - `Ubuntu` — Ubuntu Focal 20.04+ (sans domaine, `perso=1`).
 *  - `Nird`   — Distribution NIRD (Debian dérivée écoles primaires, `perso=1`).
 *
 * **Mapping legacy** :
 *
 *  - L'URL preseed accepte aussi des versions Debian directes (`trixie`,
 *    `bookworm`, `bullseye`) qui mappent toutes vers la distribution
 *    `Debian` (la version Debian est portée par
 *    `config('sambaedu.linux.version_debian')`).
 *  - `ubuntu` et alias `focal`/`jammy` mappent vers `Ubuntu`.
 */
enum LinuxDistribution: string
{
    case Debian = 'debian';
    case Ubuntu = 'ubuntu';
    case Nird = 'nird';

    /**
     * Résout une string brute (whitelist + alias versions Debian/Ubuntu)
     * vers le case enum correspondant.
     *
     * @param  string  $raw  Valeur brute reçue du firmware iPXE
     *                        (`os=trixie`, `os=ubuntu`, `os=nird`...).
     * @return self|null     Case enum ou `null` si valeur hors whitelist.
     */
    public static function fromString(string $raw): ?self
    {
        $normalized = strtolower(trim($raw));

        // Alias versions Debian (trixie/bookworm/bullseye) → Debian.
        // Note post-review #7 : `buster` (Debian 10) retiré — EOL juin 2024.
        if (in_array($normalized, ['debian', 'trixie', 'bookworm', 'bullseye'], true)) {
            return self::Debian;
        }

        // Alias versions Ubuntu (focal, jammy, noble...) → Ubuntu.
        if (in_array($normalized, ['ubuntu', 'focal', 'jammy', 'noble'], true)) {
            return self::Ubuntu;
        }

        if ($normalized === 'nird') {
            return self::Nird;
        }

        return null;
    }
}
