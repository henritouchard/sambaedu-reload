<?php

declare(strict_types=1);

namespace Tests\Unit\Ldap;

use App\Services\Ldap\EstablishmentMatcher;
use PHPUnit\Framework\TestCase;

class EstablishmentMatcherTest extends TestCase
{
    private const ETAB_DN = 'CN=0991229y,OU=Etablissements,DC=localdev,DC=fr';

    public function test_returns_all_when_no_establishment_dn(): void
    {
        $this->assertSame(
            EstablishmentMatcher::MATCH_ALL,
            EstablishmentMatcher::match('CN=pc-1,OU=salle,OU=Computers,DC=localdev,DC=fr', [], null)
        );

        $this->assertSame(
            EstablishmentMatcher::MATCH_ALL,
            EstablishmentMatcher::match('CN=pc-1,OU=salle,OU=Computers,DC=localdev,DC=fr', [], '')
        );

        $this->assertSame(
            EstablishmentMatcher::MATCH_ALL,
            EstablishmentMatcher::match('CN=pc-1,OU=salle,OU=Computers,DC=localdev,DC=fr', [], '   ')
        );
    }

    public function test_matches_by_tree_when_dn_is_under_establishment_dn(): void
    {
        $dn = 'CN=pc-1,OU=salle,' . self::ETAB_DN;

        $this->assertSame(
            EstablishmentMatcher::MATCH_TREE,
            EstablishmentMatcher::match($dn, [], self::ETAB_DN)
        );
    }

    public function test_tree_match_is_case_insensitive(): void
    {
        $dn = 'cn=pc-1,ou=salle,cn=0991229y,ou=etablissements,dc=localdev,dc=fr';

        $this->assertSame(
            EstablishmentMatcher::MATCH_TREE,
            EstablishmentMatcher::match($dn, [], self::ETAB_DN)
        );
    }

    public function test_matches_by_member_of(): void
    {
        $memberOf = [
            'CN=Eleves,OU=Groups,DC=localdev,DC=fr',
            self::ETAB_DN,
            'CN=Classe_3eme4,OU=classes,OU=Groups,DC=localdev,DC=fr',
        ];

        $this->assertSame(
            EstablishmentMatcher::MATCH_MEMBER_OF,
            EstablishmentMatcher::match('CN=pc-1,OU=Computers,DC=localdev,DC=fr', $memberOf, self::ETAB_DN)
        );
    }

    public function test_member_of_is_case_insensitive(): void
    {
        $memberOf = ['cn=0991229y,ou=etablissements,dc=localdev,dc=fr'];

        $this->assertSame(
            EstablishmentMatcher::MATCH_MEMBER_OF,
            EstablishmentMatcher::match('CN=pc-1,OU=Computers,DC=localdev,DC=fr', $memberOf, self::ETAB_DN)
        );
    }

    public function test_member_of_with_count_key_ldap_style(): void
    {
        $memberOf = [
            'count' => 1,
            0 => self::ETAB_DN,
        ];

        $this->assertSame(
            EstablishmentMatcher::MATCH_MEMBER_OF,
            EstablishmentMatcher::match('CN=pc-1,OU=Computers,DC=localdev,DC=fr', $memberOf, self::ETAB_DN)
        );
    }

    public function test_returns_null_when_no_match(): void
    {
        $memberOf = [
            'CN=Eleves,OU=Groups,DC=localdev,DC=fr',
            'CN=0751234A,OU=Etablissements,DC=localdev,DC=fr',
        ];

        $this->assertNull(
            EstablishmentMatcher::match(
                'CN=pc-1,OU=Computers,DC=localdev,DC=fr',
                $memberOf,
                self::ETAB_DN
            )
        );
    }

    public function test_returns_null_with_empty_dn_and_empty_memberof(): void
    {
        $this->assertNull(EstablishmentMatcher::match('', [], self::ETAB_DN));
        $this->assertNull(EstablishmentMatcher::match(null, null, self::ETAB_DN));
    }

    public function test_tree_takes_precedence_over_memberof(): void
    {
        $dn = 'CN=pc-1,OU=salle,' . self::ETAB_DN;
        $memberOf = [self::ETAB_DN];

        $this->assertSame(
            EstablishmentMatcher::MATCH_TREE,
            EstablishmentMatcher::match($dn, $memberOf, self::ETAB_DN)
        );
    }

    public function test_matches_by_ou_tree_when_code_appears_in_dn(): void
    {
        // Convention observée sur les postes d'AD central : OU=<code> dans le parent.
        $dn = 'CN=vm-w11-e1-1229y,OU=base,OU=0991229y,OU=computers,DC=lab1,DC=irundo,DC=fr';

        $this->assertSame(
            EstablishmentMatcher::MATCH_OU_TREE,
            EstablishmentMatcher::match($dn, [], self::ETAB_DN)
        );
    }

    public function test_ou_tree_match_is_case_insensitive(): void
    {
        $dn = 'CN=pc-1,OU=base,OU=0991229Y,OU=Computers,DC=lab1,DC=irundo,DC=fr';

        $this->assertSame(
            EstablishmentMatcher::MATCH_OU_TREE,
            EstablishmentMatcher::match($dn, [], self::ETAB_DN)
        );
    }

    public function test_ou_tree_not_matched_when_code_is_not_an_ou_component(): void
    {
        // "0991229y" apparaît dans le CN mais pas comme composant OU isolé.
        $dn = 'CN=test-0991229y,OU=computers,DC=lab1,DC=irundo,DC=fr';

        $this->assertNull(
            EstablishmentMatcher::match($dn, [], self::ETAB_DN)
        );
    }

    public function test_cn_tree_takes_precedence_over_ou_tree(): void
    {
        $dn = 'CN=pc-1,OU=0991229y,' . self::ETAB_DN;

        $this->assertSame(
            EstablishmentMatcher::MATCH_TREE,
            EstablishmentMatcher::match($dn, [], self::ETAB_DN)
        );
    }
}
