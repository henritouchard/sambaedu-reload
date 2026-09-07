<?php

declare(strict_types=1);

namespace App\Services\Print\Exceptions;

/**
 * Levée quand le daemon Samba est inaccessible (`rpcclient
 * srvinfo` ou `rpcclient enumdrivers` retourne RC != 0 / ne répond pas).
 *
 * Distincte de {@see PrintDriverException} (erreur métier d'une commande
 * individuelle) pour permettre aux appelants — en particulier
 * `PrinterDriversSyncCommand` — de différencier « Samba down » d'une
 * erreur ponctuelle d'argument. La commande sync l'attrape pour
 * interrompre la synchronisation sans marquer tous les rows SER comme
 * orphelins, comme le fait le canal CUPS.
 *
 * À ne PAS confondre avec {@see KerberosTicketException} qui exige une
 * action côté admin système (renouvellement de ticket).
 */
class SambaUnavailableException extends \RuntimeException
{
}
