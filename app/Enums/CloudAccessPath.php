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
 * **CE RÉGLAGE A UN EFFET SUR LE POSTE DEPUIS LA STORY 63.5.** En position
 * {@see self::ClientNatif}, l'application DÉSIGNÉE comme client du cloud actif
 * entre dans l'ensemble cible des applications du poste
 * ({@see \App\Services\Agent\CloudSyncClient}), et WPKG l'installe. En position
 * {@see self::Web}, rien n'est unionné. Ce docblock affirmait le contraire
 * jusqu'au 2026-08-16 : la doc suit le code, elle ne le devance pas.
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
