<?php

declare(strict_types=1);

namespace App\Ipxe\Exceptions;

use RuntimeException;

/**
 * Story 3.8 — D9 / AC8.2.
 *
 * Exception levée par {@see \App\Ipxe\Support\WindowsXmlPlaceholders::sanitizeBatPlaceholder()}
 * quand un input dynamique contient un caractère d'injection cmd.exe interdit :
 *
 *  - `\x00-\x1F`, `\x7F` — chars non-printables / control (newlines, NUL...).
 *  - `;` — séparateur de commandes batch / PowerShell.
 *  - `&` — séparateur cmd.exe (`A & B`).
 *  - `|` — pipe cmd.exe.
 *  - `` ` `` — backtick (substitution PowerShell / certains shells).
 *  - `$` — substitution PowerShell variable.
 *  - `%` — substitution variable cmd.exe.
 *  - `"` / `'` — quotes (échappent un argument quoted).
 *  - `\` — backslash (caractère d'échappement cmd.exe).
 *
 * **Stratégie 0-trust** : on REJETTE plutôt qu'escape — un poste compromis
 * qui envoie `name=";calc.exe;rem"` doit être silencieusement bloqué côté
 * controller (200 + body vide + log warning) plutôt que partiellement
 * neutralisé.
 *
 * **Wrap obligatoire côté caller** : le controller catch cette exception et
 * retourne 200 + body vide + log warning `ipxe.windows.action.placeholder_injection_attempt`
 * (cf. {@see \App\Ipxe\Http\Controllers\IpxeWindowsActionController}).
 */
class BatPlaceholderInjectionException extends RuntimeException
{
}
