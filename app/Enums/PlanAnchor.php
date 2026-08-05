<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 60.5 — l'ANCRE LOGIQUE d'un plan : le nom NEUTRE de la zone dans laquelle
 * il vit.
 *
 * **Ce n'est pas un chemin, et c'est tout l'intérêt.** La coupe de l'epic interdit
 * à un plan de porter un chemin absolu : la racine réelle est un savoir de
 * backend. Mais SE5 gouverne désormais DEUX zones disjointes — les répertoires
 * réseau nommés (Epic 34) et les arbres de classe (60.5) — et le plan doit
 * pouvoir dire laquelle sans dire où. L'ancre est ce mot-là : un jeton d'un
 * vocabulaire FERMÉ, que seule la garde de chemin du backend sait traduire
 * ({@see \App\Services\Filesystem\Backend\Posix\PosixPathGuard}). Une ancre
 * inconnue n'est donc pas un chemin refusé plus tard : c'est une valeur qui
 * n'existe pas.
 *
 * **Pourquoi les jetons ne reprennent PAS le nom des dossiers réels.** La garde de
 * neutralité des plans cherche, dans la sérialisation, des marqueurs de la couche
 * du dessous — dont le nom du répertoire des partages. Baptiser la zone logique du
 * même mot que le dossier physique aurait rendu cette garde inutilisable : elle
 * aurait tiré sur une valeur parfaitement neutre, et la seule issue commode aurait
 * été de retirer le marqueur, c'est-à-dire d'affaiblir la garde pour faire passer
 * un nom. Le jeton nomme donc la ZONE (« le réseau », « les classes »), jamais le
 * dossier — ce qu'une ancre logique doit faire de toute façon.
 *
 * Cases PascalCase, valeurs snake_case (convention maison).
 */
enum PlanAnchor: string
{
    /**
     * Zone des répertoires réseau nommés — l'ancre par DÉFAUT. Les plans plats de
     * l'Epic 34 ne changent donc pas d'un octet : ils n'ont jamais eu à se
     * prononcer, et leur silence vaut cette zone.
     */
    case Reseau = 'reseau';

    /**
     * Zone des arbres de classe matérialisés par la chaîne générique — la racine
     * NEUVE de la story 60.5, distincte de l'arbre historique auquel SE5 n'écrit
     * jamais.
     */
    case Classes = 'classes';

    /** Libellé FR — messages d'erreur, journaux, écrans. */
    public function label(): string
    {
        return match ($this) {
            self::Reseau => 'Répertoires réseau',
            self::Classes => 'Arbres de classe',
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

    /** L'ancre par défaut : celle des plans qui ne se prononcent pas. */
    public static function default(): self
    {
        return self::Reseau;
    }
}
