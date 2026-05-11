<?php

declare(strict_types=1);

namespace App\Services\Ldap;

/**
 * Détermine le rattachement d'un objet AD à un établissement.
 *
 * Deux règles de rattachement homogènes avec la logique historique SE4FS :
 *  - `tree`     : le DN de l'objet est sous le DN du groupe établissement.
 *  - `memberOf` : l'objet a le DN établissement dans son attribut memberOf.
 *
 * Retourne `null` si aucun rattachement détecté. Retourne `'all'` quand aucun
 * DN établissement n'est fourni (mode domaine entier).
 */
final class EstablishmentMatcher
{
    public const MATCH_ALL = 'all';
    public const MATCH_TREE = 'tree';
    public const MATCH_MEMBER_OF = 'memberOf';

    /**
     * @param  array<int,string>|null  $memberOf  Liste brute (LDAP) des memberOf — peut contenir une clé 'count'.
     */
    public static function match(?string $objectDn, ?array $memberOf, ?string $establishmentDn): ?string
    {
        if ($establishmentDn === null || trim($establishmentDn) === '') {
            return self::MATCH_ALL;
        }

        $normalizedEtabDn = strtolower(trim($establishmentDn));

        $dn = strtolower(trim((string) ($objectDn ?? '')));
        if ($dn !== '' && str_contains($dn, ',' . $normalizedEtabDn)) {
            return self::MATCH_TREE;
        }

        foreach (self::normalizeMemberOf($memberOf) as $groupDn) {
            if (strtolower(trim((string) $groupDn)) === $normalizedEtabDn) {
                return self::MATCH_MEMBER_OF;
            }
        }

        return null;
    }

    /**
     * @param  array<int|string,mixed>|null  $memberOf
     * @return array<int,string>
     */
    private static function normalizeMemberOf(?array $memberOf): array
    {
        if ($memberOf === null) {
            return [];
        }

        if (isset($memberOf['count'])) {
            unset($memberOf['count']);
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? $v : '', $memberOf),
            static fn (string $v): bool => $v !== ''
        ));
    }
}
