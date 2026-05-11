<?php

declare(strict_types=1);

namespace Tests\Unit\Ldap;

use App\Config\LdapDnHelper;
use App\Services\Ldap\EstablishmentWorkstationScope;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Couvre uniquement la logique pure de parsing DN (`ancestorOuNames`).
 * Les chemins LDAP (resolve / workstationDns / parentOuNames) nécessitent
 * un LdapRecord Container, hors scope d'un test unitaire.
 */
class EstablishmentWorkstationScopeTest extends TestCase
{
    private EstablishmentWorkstationScope $scope;
    private ReflectionMethod $ancestorOuNames;

    protected function setUp(): void
    {
        parent::setUp();

        $dnHelper = $this->createMock(LdapDnHelper::class);
        $this->scope = new EstablishmentWorkstationScope($dnHelper);

        $this->ancestorOuNames = new ReflectionMethod(EstablishmentWorkstationScope::class, 'ancestorOuNames');
        $this->ancestorOuNames->setAccessible(true);
    }

    /**
     * @param  array<int,string>  $expected
     */
    private function invoke(string $machineDn, string $computersDnLower, array $expected): void
    {
        $result = $this->ancestorOuNames->invoke($this->scope, $machineDn, $computersDnLower);
        $this->assertSame($expected, $result);
    }

    public function test_extracts_full_ancestor_chain_with_root(): void
    {
        $this->invoke(
            'cn=pc-1,ou=salle-a,ou=batiment-b,ou=computers,dc=localdev,dc=fr',
            'ou=computers,dc=localdev,dc=fr',
            ['salle-a', 'batiment-b', 'computers'],
        );
    }

    public function test_extracts_single_parent_ou_plus_root(): void
    {
        $this->invoke(
            'cn=pc-1,ou=techno,ou=computers,dc=localdev,dc=fr',
            'ou=computers,dc=localdev,dc=fr',
            ['techno', 'computers'],
        );
    }

    public function test_returns_just_root_when_machine_is_directly_under_computers(): void
    {
        $this->invoke(
            'cn=pc-1,ou=computers,dc=localdev,dc=fr',
            'ou=computers,dc=localdev,dc=fr',
            ['computers'],
        );
    }

    public function test_returns_empty_when_dn_is_outside_computers_subtree(): void
    {
        $this->invoke(
            'cn=pc-1,ou=salle-a,ou=other,dc=localdev,dc=fr',
            'ou=computers,dc=localdev,dc=fr',
            [],
        );
    }

}
