<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Exceptions;

use RuntimeException;

/**
 * Story 3.6 — D5 — Exception levée par {@see \App\Ipxe\Iso\Services\WindowsIsoUrlValidator}
 * lorsqu'une URL ne respecte pas les contraintes de sécurité (regex iso-legacy,
 * allowlist host Microsoft, scheme HTTPS, extraction iso_name).
 *
 * Le message porte une description fr destinée à être affichée à l'admin
 * via un toast Livewire. Ne JAMAIS y exposer de stack-trace ni de path interne.
 */
class WindowsIsoValidationException extends RuntimeException
{
}
