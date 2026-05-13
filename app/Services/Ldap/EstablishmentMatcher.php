<?php

declare(strict_types=1);

namespace App\Services\Ldap;

/**
 * Détermine le rattachement d'un objet AD à un établissement.
 *
 * Trois règles homogènes avec les conventions SE4FS observées :
 *  - `tree`     : DN sous le DN du groupe établissement (`,CN=<code>,OU=Etablissements,…`).
 *                 Inopérant en pratique mais conservé comme filet (cf. UserSyncService historique).
 *  - `ou_tree`  : DN contient `,OU=<code>,` quelque part dans la chaîne parente.
 *                 Convention observée sur les postes / parcs d'AD central multi-étab.
 *  - `memberOf` : l'attribut memberOf contient le DN du groupe établissement.
 *                 Convention observée pour les users.
 *
 * Retourne `null` si aucun rattachement détecté. Retourne `'all'` quand aucun
 * DN établissement n'est fourni (mode domaine entier).
 */
final class EstablishmentMatcher
{
    public const MATCH_ALL = 'all';
    public const MATCH_TREE = 'tree';
    public const MATCH_OU_TREE = 'ou_tree';
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

        $code = self::extractCodeFromEtabDn($normalizedEtabDn);
        if ($code !== null && $dn !== '' && str_contains($dn, ',ou=' . $code . ',')) {
            return self::MATCH_OU_TREE;
        }

        foreach (self::normalizeMemberOf($memberOf) as $groupDn) {
            if (strtolower(trim((string) $groupDn)) === $normalizedEtabDn) {
                return self::MATCH_MEMBER_OF;
            }
        }

        return null;
    }

    /**
     * Extrait le code étab depuis un DN du type "cn=<code>,ou=etablissements,…".
     * Retourne `null` si le DN n'a pas la forme attendue.
     */
    private static function extractCodeFromEtabDn(string $normalizedEtabDn): ?string
    {
        if (preg_match('/^cn=([^,]+),/', $normalizedEtabDn, $m) === 1) {
            return $m[1];
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
