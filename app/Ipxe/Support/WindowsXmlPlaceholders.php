<?php

declare(strict_types=1);

namespace App\Ipxe\Support;

/**
 * Story 3.5 — D6 / AC1.3.
 *
 * Catalogue des placeholders `###_<KEY>_###` injectés dans le template
 * unattend.xml + helper de sanitization defense-in-depth pour les flows XML
 * Windows et shell-arg de l'install.bat WinPE.
 *
 * **Sécurité critique** :
 *
 *  - **`sanitize()`** : sanitize une valeur AVANT injection dans un node XML
 *    (escape les chars XML-special via `htmlspecialchars(..., ENT_XML1)`
 *    pour bloquer l'injection de balises ou entités).
 *  - **`sanitizeShellArg()`** : sanitize une valeur AVANT injection dans une
 *    commande bash du `.bat` WinPE (un `;`/`&`/backtick/newline permettrait
 *    une injection de commande arbitraire côté client WinPE).
 *  - **`interpolate()`** : remplace chaque `###_<KEY>_###` par
 *    `sanitize($values[strtolower(key)] ?? '')` (parité legacy
 *    `windows.inc.php:347-358` `preg_replace`).
 *
 * **Anti-pattern** :
 *  - ❌ Ne PAS exposer le mapping `placeholder → valeur secrète` dans les
 *    logs : seul le sha256 du XML final est tracé.
 *  - ❌ Ne PAS ajouter de placeholder hors catalogue : risque d'injection
 *    via input user.
 */
final class WindowsXmlPlaceholders
{
    /**
     * Catalogue des 3 placeholders connus du template unattend.xml legacy
     * (`sambaedu/ipxe/Win10/unattend.xml`) mappés vers leur clé `config(...)`.
     *
     * **Convention** : clé `###_<UPPERCASE_KEY>_###` → chemin `config(...)`.
     *
     * Les placeholders par-poste (`###_NAME_###`) ne sont PAS dans ce catalogue
     * — ils sont injectés directement par {@see WindowsUnattendBuilder::build()}
     * (lecture du modèle Workstation).
     *
     * @return array<string, string>  Mapping `placeholder_key (uppercase
     *                                 SANS `###_..._###`) → chemin config()`.
     */
    public static function catalog(): array
    {
        return [
            // ###_ADMINSE_NAME_###   → nom de l'admin local Windows.
            'ADMINSE_NAME' => 'sambaedu.windows.adminse_name',
            // ###_SE4FS_NAME_###     → nom DNS du SE4FS (utilisé pour `setx
            //                          se4fs`, curl OOBE postinst).
            'SE4FS_NAME' => 'sambaedu.se4fs_name',
        ];
    }

    /**
     * Sanitize une valeur avant injection dans un node XML / attribut XML.
     *
     * **Règles** :
     *  1. Remplace les newlines (`\n`, `\r`) par un espace — un newline dans
     *     un attribut XML pose problème (certains parseurs strict rejettent).
     *  2. Remplace les chars non-printables (< 0x20 sauf tab) par espace.
     *  3. Escape les chars XML-special via `htmlspecialchars(..., ENT_XML1 |
     *     ENT_QUOTES, 'UTF-8')` (`<` → `&lt;`, `>` → `&gt;`, `&` → `&amp;`,
     *     `"` → `&quot;`, `'` → `&apos;`).
     *
     * **Anti-injection XML** : sans escape, un hostname `PC-101&<EVIL>` cassé
     * en amont injecterait `<EVIL>` comme balise XML dans
     * `<ComputerName>...</ComputerName>`. Le parser Windows ignorerait silence,
     * ou pire interpréterait une balise inattendue.
     *
     * **Note encodage** : `htmlspecialchars` n'échappe pas `'` par défaut, on
     * force `ENT_QUOTES` pour blinder les attributs quoted-singles.
     *
     * @param  string|int|null  $value  Valeur brute.
     * @return string                   Valeur XML-safe.
     */
    public static function sanitize(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;

        // Replace newlines + chars non-printables par espace.
        $str = preg_replace('/[\r\n]+/', ' ', $str) ?? $str;
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $str) ?? $str;

        // Escape XML special chars (DOMDocument l'aurait fait via nodeValue
        // mais nodeValue est setter direct — pas d'escape implicite côté
        // appendXML/textContent. On force ici pour cohérence).
        return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize une valeur avant injection dans une commande bash du `.bat`
     * WinPE.
     *
     * **Règles strictes** (replace par `_`) :
     *  - Newlines (`\n`, `\r`) — éviterait de terminer la commande courante
     *    et d'exécuter une commande arbitraire.
     *  - Quotes doubles `"` — éviterait d'échapper une string quoted.
     *  - Quotes simples `'` — idem.
     *  - `;` — séparateur de commandes batch/PowerShell.
     *  - `&` — séparateur cmd.exe (`A & B`).
     *  - `|` — pipe.
     *  - `` ` `` (backtick) — substitution dans certains shells / PowerShell.
     *  - `$` — substitution PowerShell variable (`$env:PATH`).
     *  - `>` / `<` — redirection IO.
     *  - `^` — escape char cmd.exe.
     *
     * @param  string|int|null  $value  Valeur brute.
     * @return string                   Valeur shell-safe.
     */
    public static function sanitizeShellArg(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;

        // Replace chars d'injection shell par `_`.
        $str = preg_replace('/[\r\n"\'\\\\;&|`\$><\^]/', '_', $str) ?? $str;

        // Replace chars non-printables par `_` également (pas d'espace ici,
        // un espace casserait certaines URLs / paths).
        $str = preg_replace('/[\x00-\x1F\x7F]/', '_', $str) ?? $str;

        return $str;
    }

    /**
     * Sanitize une valeur destinée à être assignée via `DOMNode::textContent =`
     * (qui escape NATIVEMENT les caractères XML lors de la sérialisation).
     *
     * Contrairement à {@see sanitize()} qui applique `htmlspecialchars`,
     * cette méthode applique UNIQUEMENT le filter newlines + non-printables.
     * Appliquer `htmlspecialchars` AVANT `textContent =` provoque un
     * double-escape (`&` → `&amp;` → sérialisé en `&amp;amp;`).
     *
     * **Règles** :
     *  - Newlines (`\n`, `\r`) → remplacés par espace (éviterait CommandLine multi-ligne).
     *  - Chars non-printables (`\x00-\x1F` sauf `\x09`) → remplacés par espace.
     *
     * Defense-in-depth post-review #3.
     *
     * @param  string|int|null  $value  Valeur brute.
     * @return string                   Valeur safe pour `textContent =`.
     */
    public static function sanitizeForTextContent(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;

        // Replace newlines + chars non-printables par espace.
        $str = preg_replace('/[\r\n]+/', ' ', $str) ?? $str;
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $str) ?? $str;

        return $str;
    }

    /**
     * Interpole un template (multi-lignes string) en remplaçant chaque
     * occurrence `###_<KEY>_###` par `sanitize($values[strtolower(key)] ?? '')`.
     *
     * **Algorithme iso-legacy** (`windows.inc.php:347-358`) :
     *  1. Pour chaque (param, valeur) dans `$values` :
     *     - Remplace `###_<UPPER(param)>_###` par `sanitize(valeur)`.
     *  2. Au final, remplace tous les `###_*_###` orphelins par `''`
     *     (cleanup des placeholders non résolus).
     *
     * @param  string  $template  Template avec placeholders `###_KEY_###`.
     * @param  array<string, string|int|null>  $values  Clés en lowercase OU
     *                                                  uppercase (case-insensitive).
     * @return string                                   Template interpolé.
     */
    public static function interpolate(string $template, array $values): string
    {
        $result = $template;

        foreach ($values as $key => $value) {
            $placeholder = '###_' . strtoupper((string) $key) . '_###';
            $sanitized = self::sanitize($value);
            $result = str_replace($placeholder, $sanitized, $result);
        }

        // Cleanup placeholders orphelins (iso-legacy preg_replace U flag).
        $result = preg_replace('/###_[^#]*_###/U', '', $result) ?? $result;

        return $result;
    }
}
