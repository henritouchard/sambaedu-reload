<?php

declare(strict_types=1);

namespace App\Services\Print\Exceptions;

/**
 * Story 6.2 — Levée quand l'authentification Kerberos échoue (ticket
 * `se4fs$` absent / expiré, KRB5_KT_NOTFOUND, KRB5_KDC_UNREACH…).
 *
 * Le message d'exception est volontairement lisible pour servir tel quel
 * dans un toast utilisateur (« Authentification Samba expirée — contacter
 * l'admin système »), sans leak technique. Le détail complet (commande,
 * stderr) reste loggé via `Log::error` côté Service.
 *
 * Distincte de {@see SambaUnavailableException} (daemon Samba HS) car
 * l'action corrective est différente : Kerberos = `kinit -k` /
 * `samba-tool` ; Samba HS = relancer le service `smbd`.
 */
class KerberosTicketException extends \RuntimeException
{
}
