<?php

declare(strict_types=1);

namespace App\Services\Network\Exceptions;

use RuntimeException;

/**
 * Levée quand le daemon `isc-dhcp-server.service` est inactif ou
 * non installé (cf. `systemctl is-active` retour non-zero).
 *
 * Pattern aligné `App\Services\Print\Exceptions\CupsDaemonDownException`.
 * Distincte de `DhcpCommandException` (erreur métier d'une commande) pour
 * permettre aux appelants de basculer en mode dégradé sans considérer
 * que la mutation a échoué — la persistance DB reste valide.
 */
class DhcpDaemonDownException extends RuntimeException
{
}
