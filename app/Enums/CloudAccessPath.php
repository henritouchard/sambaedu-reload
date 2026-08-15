<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 63.3 — PAR OÙ L'UTILISATEUR ATTEINT LE CLOUD.
 *
 * Deux positions, et deux seulement : le navigateur, ou le client de
 * synchronisation posé sur le poste. Vocabulaire FERMÉ (même figure que
 * {@see ActiveCloud} et {@see FileBackendName}) : il n'existe littéralement
 * aucune valeur qui signifierait « les deux » ni « on verra ».
 *
 * **CE RÉGLAGE N'A PAS ENCORE D'EFFET SUR LE POSTE, ET L'ÉCRAN LE DIT.** La pose
 * du client de synchronisation est livrée par un chantier séparé ; d'ici là,
 * seul l'accès par le navigateur est effectivement posé. Persister un réglage
 * que personne ne lit est exactement le défaut qu'a porté l'ancienne grille de
 * plafonds par défaut (supprimée par la story 63.4) — la différence, ici, est que
 * l'écran l'ANNONCE au lieu de laisser croire à un effet.
 *
 * Cases PascalCase, valeurs snake_case (convention maison).
 */
enum CloudAccessPath: string
{
    /** L'utilisateur atteint ses fichiers par le portail web de l'instance. */
    case Web = 'web';

    /** L'utilisateur atteint ses fichiers par le client de synchronisation du poste. */
    case ClientNatif = 'client_natif';

    /** Libellé FR — aucune valeur technique brute à l'écran. */
    public function label(): string
    {
        return match ($this) {
            self::Web => 'Par le navigateur',
            self::ClientNatif => 'Par le client de synchronisation',
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
