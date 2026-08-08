<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 60.3 — VOCABULAIRE de la colonne `network_shares.backend` : qui est
 * l'autorité d'écriture des droits d'un partage.
 *
 * Enum FERMÉE, et c'est le point. Une valeur hors vocabulaire n'est jamais
 * ramenée à un défaut : elle fait échouer la résolution en nommant ce qui était
 * attendu ({@see \App\Exceptions\Filesystem\UnknownFileBackendException}). Un
 * repli silencieux sur `posix` écrirait des permissions POSIX pour un partage que
 * l'administrateur croit hébergé ailleurs — la pire forme du défaut que cet epic
 * combat : le signal qui n'atteint pas son destinataire.
 *
 * **TROIS cases, et il n'y en aura pas de quatrième pour Nextcloud** (story 61.3,
 * recadrage du 2026-08-08). La story 60.3 annonçait ici `nextcloud` ET
 * `nextcloud_delegue` — le second devait servir une instance sur laquelle SE5
 * n'aurait qu'un compte ordinaire. Il n'existera pas : mesuré contre une instance
 * réelle, un compte ordinaire ne peut créer ni Team folder (HTTP 200 avec un 403
 * dans le corps OCS, rien créé), ni groupe, et un partage visant un groupe échoue.
 * Sans Team folder, pas de clôture — donc pas de cloisonnement, qui est le
 * problème que tout le plan de fichiers existe pour résoudre. Une case déclarée
 * que le produit ne sait pas tenir est exactement le défaut que cet epic combat ;
 * on ne l'ouvre donc pas, ni maintenant ni plus tard.
 *
 * Les cases arrivent PAR CODE (D6 : l'adaptateur est natif, par produit ; le
 * runtime, lui, est une extension) — jamais par configuration.
 *
 * **Ce que cette colonne achète** (décision Q-D, 2026-08-04) : POSIX est conservé
 * et deviendra retirable. Le jour venu, le retirer sera basculer cette valeur et
 * lancer une migration explicite (D9), pas réécrire le domaine.
 *
 * Cases PascalCase, valeurs snake_case (convention maison).
 */
enum FileBackendName: string
{
    /**
     * L'autorité historique : permissions POSIX + partage SMB. VALEUR LÉGITIME
     * DE COLONNE dès cette story (c'est ce que sont tous les partages existants),
     * mais SANS implémentation ici — la descente de l'exécution sous la ligne de
     * contrat est la story 60.4.
     */
    case Posix = 'posix';

    /**
     * Le backend qui n'exécute rien et le DIT. Il sert l'aperçu avant
     * application, et il est la seconde implémentation qui prouve que le contrat
     * n'est pas POSIX déguisé.
     *
     * Il ne prouve pas, à lui seul, que le contrat est bon : n'exécutant rien, il
     * satisfait n'importe quel contrat (D3). C'est le double propagateur des
     * tests et le squelette Nextcloud jetable qui portent cette preuve-là.
     */
    case Preview = 'preview';

    /**
     * Story 61.3 — L'AUTORITÉ BASCULE : le plan devient un Team folder Nextcloud,
     * les octrois des permissions de groupe, et la clôture des règles de masque.
     *
     * **Ce que ce choix change POUR L'UTILISATEUR** — et c'est ce que dit la
     * description : un partage servi par ce backend n'a AUCUN chemin SMB (D7 :
     * impossibilité vérifiée, pas une coupe de périmètre). Il se consulte au web
     * et se synchronise par le client de bureau. Aucune lettre de lecteur n'est
     * émise pour lui.
     *
     * **SE5 exige un compte ADMINISTRATEUR de l'instance.** La configuration qui
     * ne le permet pas est refusée à la saisie (61.2), et la capacité « Accès
     * Nextcloud » doit être active pour que cette case soit seulement posable.
     */
    case Nextcloud = 'nextcloud';

    /** Libellé FR — aucune valeur technique brute à l'écran (iso 42.3 D1). */
    public function label(): string
    {
        return match ($this) {
            self::Posix => 'Serveur de fichiers (POSIX/SMB)',
            self::Preview => 'Aperçu (aucune exécution)',
            self::Nextcloud => 'Nextcloud (dossier d\'équipe)',
        };
    }

    /**
     * Phrase courte destinée à l'infobulle du badge : ce que le choix de backend
     * change POUR L'UTILISATEUR (le chemin d'accès), pas comment il est écrit.
     */
    public function description(): string
    {
        return match ($this) {
            self::Posix => 'Les droits sont posés sur le serveur de fichiers et le lecteur se monte en SMB.',
            self::Preview => "Aucun droit n'est posé : ce backend sert uniquement à prévisualiser un plan.",
            self::Nextcloud => 'Les droits sont posés dans un dossier d\'équipe Nextcloud. L\'accès se fait '
                . 'par le web et par le client de synchronisation — il n\'y a PAS de lecteur réseau SMB '
                . 'pour ce répertoire.',
        };
    }

    /** `true` si la valeur brute appartient au vocabulaire fermé. */
    public static function isKnown(mixed $value): bool
    {
        return is_string($value) && self::tryFrom($value) !== null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
