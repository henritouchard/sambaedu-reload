<?php

declare(strict_types=1);

namespace App\Services\Network\Exceptions;

use InvalidArgumentException;

/**
 * Story 8.1 — Exception métier pour les violations de validation DHCP.
 *
 * Levée par `DhcpService::validateName/Mac/Ip` quand l'entrée utilisateur ne
 * respecte pas le format métier (regex MAC, format IPv4, format `cn`).
 *
 * Distincte de `DhcpCommandException` (exec system fail) et
 * `DhcpDaemonDownException` (service injoignable) pour permettre aux
 * appelants de :
 *  - afficher une erreur inline ciblée sur le champ fautif (UI Livewire) ;
 *  - distinguer une faute de saisie d'un crash système (mode dégradé AC6).
 *
 * Étend `InvalidArgumentException` (cohérent avec `CupsPrinterService`).
 */
class DhcpValidationException extends InvalidArgumentException
{
}
