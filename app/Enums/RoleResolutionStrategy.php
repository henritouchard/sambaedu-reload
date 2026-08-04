<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 60.2 — les QUATRE stratégies de résolution d'un rôle de recette. Enum
 * FERMÉE : toute valeur inconnue rencontrée dans une `roles_spec` stockée fait
 * rejeter la recette ({@see \App\Models\DirectoryTemplate::assertValidResolutionSpec()}).
 *
 * Un rôle de recette dit QUI reçoit un octroi. Jusqu'ici, ce « qui » était
 * toujours choisi à la main au moment de matérialiser un partage (story 34.3).
 * Une stratégie de résolution, c'est la RÈGLE qui répond à la place de l'humain —
 * et c'est ce qui permet à un groupe de matérialiser son arbre tout seul.
 *
 *  - `self`       : le groupe pour lequel on matérialise, en entier.
 *  - `designated` : la cible est choisie à la matérialisation. C'est le
 *                   comportement 34.3, et c'est le DÉFAUT — l'absence de clé
 *                   `resolution` vaut `designated`, ce qui laisse les quatre
 *                   recettes seedées strictement inchangées.
 *  - `pattern`    : un groupe APPARENTÉ, dérivé par motif de nom.
 *  - `edge_role`  : les membres du groupe de matérialisation qui portent tel
 *                   rôle sur l'ARÊTE d'appartenance (`user_group_user.role`).
 *
 * **Pourquoi la stratégie d'arête n'est pas une commodité.** Depuis le fold de la
 * story 4.13, les noms d'annuaire `Classe_X`, `Equipe_X` et `PP_X` se replient en
 * UNE SEULE ligne `user_groups` au nom nu (`3A`, type `classe`), et le statut de
 * chacun vit sur l'arête : `member` pour l'élève, `manager` pour l'enseignant,
 * `owner` pour le professeur principal. **L'équipe pédagogique n'a donc AUCUNE
 * ligne `user_groups` propre** — « les membres de la classe qui portent
 * `manager|owner` » est sa SEULE représentation en base. La stratégie d'arête est
 * le mécanisme qui remplace les groupes multiples de l'ancien système ; sans elle,
 * l'audience « les profs de cette classe » n'est pas exprimable du tout.
 *
 * Corollaire à ne pas oublier : `pattern` ne peut PAS retrouver `Equipe_3A` en
 * base, puisque la ligne n'existe plus. Cette stratégie sert les apparentés qui
 * existent RÉELLEMENT comme lignes distinctes, et elle reste hors du chemin
 * critique.
 *
 * Cases PascalCase, valeurs snake_case (convention maison). Le cas `self` porte le
 * nom de case `Itself` : `case Self` est licite en PHP mais donnerait `self::Self`
 * à la lecture, une ambiguïté gratuite entre le mot-clé et la case.
 */
enum RoleResolutionStrategy: string
{
    case Itself = 'self';
    case Designated = 'designated';
    case Pattern = 'pattern';
    case EdgeRole = 'edge_role';

    /** Clé additive portée par un rôle de `roles_spec`. */
    public const SPEC_KEY = 'resolution';

    /** Clé de la stratégie DANS la structure `resolution`. */
    public const SPEC_STRATEGY_KEY = 'strategy';

    /** Clé des rôles d'arête visés (stratégie `edge_role` UNIQUEMENT). */
    public const SPEC_EDGE_ROLES_KEY = 'edge_roles';

    /** Clé du motif de nom (stratégie `pattern` UNIQUEMENT). */
    public const SPEC_PATTERN_KEY = 'pattern';

    /** Stratégie par DÉFAUT en l'absence de clé `resolution` (iso-34.3). */
    public static function default(): self
    {
        return self::Designated;
    }

    /** Libellé FR (messages d'erreur et documentation — aucune UI en 60.2). */
    public function label(): string
    {
        return match ($this) {
            self::Itself => 'Le groupe lui-même',
            self::Designated => 'Un groupe désigné à la matérialisation',
            self::Pattern => 'Un groupe apparenté, dérivé par motif de nom',
            self::EdgeRole => 'Les membres portant tel rôle sur l\'arête',
        };
    }

    /**
     * `true` si la stratégie sait répondre SEULE, à partir du seul groupe de
     * matérialisation. `designated` répond `false` : sa cible vient d'une saisie,
     * donc une recette qui en porte un ne peut pas s'accrocher à un type de groupe
     * ({@see \App\Models\DirectoryTemplate::assertAttachable()}) — créer un groupe
     * ne pourrait pas matérialiser son arbre sans demander à quelqu'un.
     */
    public function isAutoResolvable(): bool
    {
        return $this !== self::Designated;
    }

    /**
     * `true` si la stratégie exige un rôle de maille `UserGroup`.
     *
     * `self`, `pattern` et `edge_role` désignent tous un GROUPE (le groupe
     * lui-même, un apparenté, ou une audience qualifiée par un rôle d'arête). Seul
     * `designated` accepte la maille `User` — et c'est indispensable : la recette
     * « d'un utilisateur à un utilisateur » porte deux rôles de maille `User`.
     */
    public function requiresGroupMaille(): bool
    {
        return $this !== self::Designated;
    }

    /**
     * Vocabulaire de clés FERMÉ de la structure `resolution`, par stratégie.
     *
     * Fermé pour la même raison que le vocabulaire de nœud (story 60.1) : un champ
     * qu'on ne comprend pas est un champ dont on ne fait rien, et une recette qui
     * en porte un ment sur ce qu'elle fait.
     *
     * @return list<string>
     */
    public function allowedSpecKeys(): array
    {
        return match ($this) {
            self::EdgeRole => [self::SPEC_STRATEGY_KEY, self::SPEC_EDGE_ROLES_KEY],
            self::Pattern => [self::SPEC_STRATEGY_KEY, self::SPEC_PATTERN_KEY],
            self::Itself, self::Designated => [self::SPEC_STRATEGY_KEY],
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
