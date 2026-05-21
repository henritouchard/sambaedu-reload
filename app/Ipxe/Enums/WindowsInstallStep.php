<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.5 — D1 / AC1.2.
 *
 * Whitelist stricte des étapes Windows post-install acceptées par
 * `/ipxe/windows/action` en scope 3.5.
 *
 * **Sécurité critique** — l'enum est l'UNIQUE source de vérité des étapes
 * tracées. Toute autre valeur (`sysprep`/`nosysprep`/`join`/`renomme`/
 * `post`/`wpkg`) retourne `null` → controller répond 200 vide + log warning
 * `ipxe.windows.action.unsupported_step` (déférée 3.7).
 *
 * **2 cases stricts en 3.5** :
 *
 *  - `Winpe` — début install WinPE post-boot (parité legacy
 *    `action.php:411-491` étape `winpe`).
 *  - `Oobe`  — fin install (1er logon OOBE) — `Workstation::os='windows'` +
 *    `progress=100%` (parité legacy `action.php:720-730` default branch).
 *
 * **Hors-scope 3.5** (déférée 3.7 quand `IpxeProgrammedActionResolver`
 * sera porté) : sysprep, nosysprep, join, renomme, post, wpkg.
 */
enum WindowsInstallStep: string
{
    case Winpe = 'winpe';
    case Oobe = 'oobe';

    /**
     * Résout une string brute vers le case enum correspondant.
     *
     * @param  string  $raw  Valeur brute reçue par le hook (multipart curl `-F`).
     * @return self|null     Case enum ou `null` si valeur hors whitelist.
     */
    public static function fromString(string $raw): ?self
    {
        $normalized = strtolower(trim($raw));

        return self::tryFrom($normalized);
    }
}
