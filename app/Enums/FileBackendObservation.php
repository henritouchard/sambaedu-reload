<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 60.3 — ce qu'une RELECTURE d'état dit d'un nœud. Enum fermée.
 *
 * Vocabulaire distinct de {@see FileBackendOutcome}, et volontairement : écrire et
 * relire ne se racontent pas avec les mêmes mots. « Appliqué » n'a aucun sens pour
 * une observation ; « absent » n'en a aucun pour une écriture.
 *
 * **`non_observable` n'est pas `absent`.** « Absent » est un FAIT constaté (le
 * nœud n'existe pas là-bas) ; « non observable » dit que le backend n'a pas
 * regardé, ou ne sait pas regarder. Les confondre ferait passer une ignorance pour
 * une observation, et la détection d'écart (story 60.4) conclurait à une
 * disparition sur un backend qui n'a simplement rien lu. Le backend d'aperçu rend
 * `non_observable` partout — il n'observe rien, et il le dit, plutôt que de
 * répondre « conforme » à une question qu'il n'a pas posée.
 *
 * Cases PascalCase, valeurs snake_case (convention maison).
 */
enum FileBackendObservation: string
{
    /** Le nœud a été lu : ses octrois observés sont dans l'observation. */
    case Observe = 'observe';

    /** Le nœud a été cherché et n'existe pas côté backend. */
    case Absent = 'absent';

    /** Le backend n'a pas regardé, ou ne sait pas regarder ce nœud. */
    case NonObservable = 'non_observable';

    /** La relecture a échoué. `detail` nomme la cause — obligatoire. */
    case Echec = 'echec';

    /** Libellé FR. */
    public function label(): string
    {
        return match ($this) {
            self::Observe => 'Observé',
            self::Absent => 'Absent côté backend',
            self::NonObservable => 'Non observable',
            self::Echec => 'Échec de relecture',
        };
    }

    /**
     * `true` si l'état EXIGE un `detail` non vide — vérifié au constructeur de
     * {@see \App\Services\Filesystem\Backend\NodeObservation}.
     */
    public function requiresDetail(): bool
    {
        return $this === self::Echec;
    }

    /** `true` si des octrois observés peuvent accompagner cet état. */
    public function carriesGrants(): bool
    {
        return $this === self::Observe;
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
