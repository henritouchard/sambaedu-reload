<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

/**
 * Story 61.1 — LES ÉCHECS NETS, NOMMÉS ET DISTINCTS.
 *
 * Le spike 60.0 a mesuré que Nextcloud distingue nettement ses refus. Les
 * agréger en un seul « ça n'a pas marché » ferait perdre exactement
 * l'information dont l'exploitant a besoin : un privilège manquant se corrige sur
 * l'instance, une cible absente se corrige dans SE5, une instance injoignable se
 * corrige sur le réseau. Trois gestes différents — donc trois cas d'énumération.
 *
 * « Déjà conforme » **n'est pas ici** : ce n'est pas un échec (statuscode OCS
 * `102`), c'est un état, et il se dit par {@see NextcloudResult::conforming()}.
 * L'y ranger aurait reproduit le défaut que l'Epic 60 a passé son temps à
 * corriger — un code de transport qui remonte comme s'il était une décision.
 */
enum NextcloudFailure: string
{
    /** 401/403 — le compte n'a pas le niveau exigé (admin d'instance). */
    case Privilege = 'privilege';

    /** 404 — la cible nommée n'existe pas côté Nextcloud. */
    case Absent = 'cible_absente';

    /** Réseau, DNS, TLS, délai dépassé : on n'a pas parlé à l'instance. */
    case Injoignable = 'injoignable';

    /** L'instance a répondu, et elle a refusé (statuscode OCS ≠ 100/102, ou 4xx/5xx). */
    case Refus = 'refus';

    /** L'instance a répondu autre chose que ce que le protocole promet. */
    case Illisible = 'reponse_illisible';

    /**
     * **Mesuré sur `nc-spike` le 2026-08-08.** L'app « Stockage externe » est
     * active, le compte est admin, et pourtant le backend SMB n'est pas
     * disponible : `files_external` exige le binaire `smbclient` ou l'extension
     * `php-smbclient`. L'instance répond alors `422` avec
     * `{"message":"Invalid storage backend \"smb\""}`.
     *
     * Il MÉRITE son propre cas parce que sa correction est ailleurs que les
     * autres — ni le réseau, ni le compte, ni l'app : un paquet à installer sur
     * l'hôte de l'instance, **puis un redémarrage du service** (la détection des
     * backends est mise en cache : installer le paquet seul ne suffit pas, ce qui
     * en fait le piège le plus coûteux du lot).
     */
    case BackendIndisponible = 'backend_smb_indisponible';

    /**
     * Libellé destiné à l'exploitant. Aucun code de transport n'y figure : le code
     * vit dans le résultat, pour le journal, pas dans la phrase.
     */
    public function label(): string
    {
        return match ($this) {
            self::Privilege => 'privilège insuffisant',
            self::Absent => 'cible absente côté Nextcloud',
            self::Injoignable => 'instance injoignable',
            self::Refus => 'refus de l\'instance',
            self::Illisible => 'réponse illisible',
            self::BackendIndisponible => 'backend SMB indisponible sur l\'instance',
        };
    }
}
