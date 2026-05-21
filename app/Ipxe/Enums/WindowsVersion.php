<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.5 — D1 / AC1.2.
 *
 * Whitelist stricte des versions Windows acceptées par les endpoints natifs
 * 3.5 (`/ipxe/windows/{install.bat,unattend.xml,diskpart.txt}`).
 *
 * **Sécurité critique** — l'enum est l'UNIQUE source de vérité des versions
 * autorisées. Toute valeur reçue côté FormRequest est validée via
 * {@see self::fromString()} (whitelist enum + Rule::in côté FormRequest =
 * defense in depth).
 *
 * **2 cases stricts en 3.5** :
 *
 *  - `Win10` — Windows 10 (parité legacy `actions/wimboot10.php`).
 *  - `Win11` — Windows 11 (parité legacy `actions/wimboot11.php`).
 *
 * **Hors-scope 3.5** (déféré 3.7) :
 *  - `Win11-old` (variante `installw11old` legacy — cf. story 3.5 § D14).
 *  - `Win7` (legacy `windows.inc.php:364` — pas d'usage terrain documenté).
 *
 * **Anti-injection** : toute valeur non listée retourne `null` (court-circuite
 * silencieusement les injections type `version=Win11\nkernel http://evil`).
 */
enum WindowsVersion: string
{
    case Win10 = 'Win10';
    case Win11 = 'Win11';

    /**
     * Résout une string brute vers le case enum correspondant.
     *
     * @param  string  $raw  Valeur brute reçue du firmware iPXE
     *                        (`version=Win10`, `version=Win11`...).
     * @return self|null     Case enum ou `null` si valeur hors whitelist.
     */
    public static function fromString(string $raw): ?self
    {
        // Post-review : anti-injection. `trim()` strip silencieusement le
        // null byte `\x00` et les newlines `\r\n` → un input
        // `"Win11\x00"` ou `"Win11\nkernel http://evil"` matcherait
        // `tryFrom('Win11')` après trim. Stratégie :
        //  1. Strip uniquement les whitespace ASCII SAFE en début/fin
        //     ([\t\n\r ] via \s).
        //  2. Après strip : si la string contient encore un char
        //     non-imprimable (hors 0x20-0x7E), c'est une injection.
        $stripped = preg_replace('/^\s+|\s+$/u', '', $raw);
        if ($stripped === null) {
            return null;
        }
        if (preg_match('/[^\x20-\x7E]/', $stripped) === 1) {
            return null;
        }

        // Case preservation : la valeur est case-sensitive (parité legacy
        // `unattend.xml.php:17` qui pose `Win11` puis utilise `Win10` côté URL).
        return self::tryFrom($stripped);
    }
}
