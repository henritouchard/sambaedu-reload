<?php

declare(strict_types=1);

namespace App\Services\Network\Data;

/**
 * Story 8.4 — Issue typée d'une mise à jour DDNS pilotée par DHCP.
 *
 * Le but de ce type est de rendre l'idempotence **mesurable** : `UNCHANGED`
 * doit dominer massivement les logs (un renouvellement de bail toutes les
 * 5 min par poste, contre un changement d'IP occasionnel). Un ratio qui
 * dériverait vers `UPDATED` signalerait une lecture d'état défaillante.
 */
enum DnsUpdateOutcome: string
{
    /** État déjà conforme — AUCUNE commande d'écriture exécutée. */
    case UNCHANGED = 'unchanged';

    /** Le nom n'avait aucun A record — ajout. */
    case CREATED = 'created';

    /** L'IP a changé — suppression des A périmés puis ajout. */
    case UPDATED = 'updated';

    /** Un ou plusieurs A records supprimés (release/expiry de bail). */
    case DELETED = 'deleted';

    /** Écarté par un garde-fou (préfixe ignoré, hors établissement, entrée invalide). */
    case SKIPPED = 'skipped';

    /** Échec d'exécution `samba-tool` (l'appelant répond quand même 200). */
    case FAILED = 'failed';

    /** L'issue traduit-elle une écriture effective dans le DNS ? */
    public function isWrite(): bool
    {
        return in_array($this, [self::CREATED, self::UPDATED, self::DELETED], true);
    }
}
