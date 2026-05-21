<?php

declare(strict_types=1);

namespace App\Ipxe\Exceptions;

use RuntimeException;

/**
 * Story 3.5 — T1.5 / AC2.1.
 *
 * Exception levée par {@see \App\Ipxe\Services\WindowsUnattendBuilder::build()}
 * en cas d'échec de l'assemblage de l'unattend.xml :
 *
 *  - Template `resources/ipxe/windows/unattend.xml` manquant ou illisible.
 *  - XML mal formé (parser DOMDocument échoue).
 *  - Node XPath obligatoire introuvable (template altéré).
 *  - Fragment XML injecté non parsable (`appendXML` échoue).
 *
 * **Wrap obligatoire côté caller** : un firmware iPXE / setup.exe doit
 * recevoir une réponse `text/plain` propre (jamais une 500 HTML) — le
 * controller {@see \App\Ipxe\Http\Controllers\IpxeWindowsUnattendController}
 * catch cette exception et retourne une 500 minimaliste avec body vide +
 * log error structuré `ipxe.windows.unattend.generation_error`.
 */
class UnattendGenerationException extends RuntimeException
{
}
