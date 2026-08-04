<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 60.1 — les QUATRE natures de nœud d'un plan de fichiers. Enum FERMÉE :
 * toute valeur inconnue rencontrée dans une `nodes_spec` stockée fait rejeter la
 * recette ({@see App\Models\DirectoryTemplate::assertValidTreeSpec()}).
 *
 * Le vocabulaire est NEUTRE : il décrit ce que le nœud EST pour le métier, pas
 * comment un backend l'écrira. POSIX, Nextcloud ou tout autre plan de fichiers
 * traduisent ces quatre natures avec leurs propres moyens.
 *
 *  - `partagee`      : chemin fixe, octrois dérivés des rôles de la recette.
 *                      Le cas ordinaire (`_travail`, `_profs`).
 *  - `activable`     : chemin fixe dont certains octrois sont SUSPENDABLES. La
 *                      désactivation vide l'octroi, elle ne supprime NI le nœud
 *                      NI les données (cas réel : `_echange`, dont la bascule
 *                      historique passe l'accès de la classe à « rien » en
 *                      conservant le dossier). Modéliser ce nœud « optionnel »
 *                      détruirait des données à la désactivation — D9 : aucune
 *                      suppression implicite n'est exprimable.
 *  - `par_membre`    : un nœud PAR MEMBRE du groupe portant le rôle d'arête visé,
 *                      avec octroi NOMINATIF (sujet = identité interne du membre).
 *                      C'est le dossier personnel de l'élève dans le partage
 *                      classe : sans cette nature, le partage classe n'est pas
 *                      exprimable.
 *  - `contenu_libre` : chemin fixe dont le plan gouverne les DROITS mais PAS les
 *                      enfants. Un enseignant qui crée `devoirs/Contrôle du 12 mai/`
 *                      à l'exécution ne fabrique pas un écart : sous une politique
 *                      d'écart STRICTE, sans ce drapeau, ce dossier serait un état
 *                      non modélisé.
 *
 * Cases PascalCase, valeurs snake_case (convention maison).
 */
enum PlanNodeNature: string
{
    case Partagee = 'partagee';
    case Activable = 'activable';
    case ParMembre = 'par_membre';
    case ContenuLibre = 'contenu_libre';

    /** Libellé FR (aucune UI en 60.1 — sert aux messages d'erreur et à la doc). */
    public function label(): string
    {
        return match ($this) {
            self::Partagee => 'Dossier partagé',
            self::Activable => 'Dossier activable',
            self::ParMembre => 'Dossier par membre',
            self::ContenuLibre => 'Dossier à contenu libre',
        };
    }

    /**
     * `false` pour `contenu_libre` UNIQUEMENT : le plan ne prétend rien sur les
     * ENFANTS de ce nœud. Drapeau destiné à la relecture d'état (60.4) — un
     * enfant non modélisé sous un tel nœud n'est pas un écart.
     */
    public function governsChildren(): bool
    {
        return $this !== self::ContenuLibre;
    }

    /** `true` si la nature s'expanse en un nœud par membre au rôle d'arête visé. */
    public function expandsPerMember(): bool
    {
        return $this === self::ParMembre;
    }

    /**
     * `true` si la nature accepte des octrois SUSPENDABLES. Un octroi suspendable
     * sur une autre nature serait une contradiction stockée (rien ne pourrait
     * jamais le suspendre) : la validation le refuse.
     */
    public function acceptsSuspendableGrants(): bool
    {
        return $this === self::Activable;
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
