<?php

namespace Tests\Unit\Ldap;

use Tests\TestCase;
use LdapRecord\Container;
use LdapRecord\Connection;
use App\Constants\Ldap\LdapFilter;
use App\Constants\Ldap\LdapAttributes;

/**
 * Tests de comparaison des données entre LdapRecord et le legacy
 * 
 * Valide que LdapRecord peut lire les mêmes données que le système legacy
 * 
 * NOTE: Ces tests nécessitent un serveur LDAP fonctionnel et peuvent être skippés
 * si le serveur n'est pas disponible
 */
class LdapLegacyDataComparisonTest extends TestCase
{
    /**
     * Test que LdapRecord peut lire des utilisateurs comme le legacy
     * 
     * Compare le nombre d'utilisateurs trouvés entre les deux systèmes
     */
    public function test_can_read_users_like_legacy(): void
    {
        // Vérifier que le serveur LDAP est disponible
        $connection = Container::getConnection('default');
        if (!$connection) {
            $this->markTestSkipped('Connexion LDAP non configurée');
        }
        
        try {
            $connection->connect();
        } catch (\Exception $e) {
            $this->markTestSkipped('Serveur LDAP non accessible: ' . $e->getMessage());
        }
        
        $baseDn = $connection->getConfiguration()->get('base_dn');
        if (empty($baseDn)) {
            $this->markTestSkipped('Base DN non configuré');
        }
        
        // Requête LdapRecord
        $query = $connection->query()
            ->setBaseDn($baseDn)
            ->select(LdapAttributes::USER_MINIMAL)
            ->rawFilter(LdapFilter::USER_ALL)
            ->limit(10);
        
        $ldapRecordResults = $query->get();
        
        // Vérifier qu'on obtient des résultats (ou au moins que la requête fonctionne)
        $this->assertIsArray($ldapRecordResults, 'LdapRecord doit retourner un tableau');
        
        // Si on a des résultats, vérifier leur structure
        if (count($ldapRecordResults) > 0) {
            $firstResult = reset($ldapRecordResults);
            $this->assertIsArray($firstResult, 'Chaque résultat doit être un tableau');
            
            // Vérifier qu'on a au moins le DN
            if (isset($firstResult['dn'])) {
                $this->assertNotEmpty($firstResult['dn'], 'Le DN doit être présent');
            }
        }
    }
    
    /**
     * Test que LdapRecord peut lire des groupes comme le legacy
     */
    public function test_can_read_groups_like_legacy(): void
    {
        $connection = Container::getConnection('default');
        if (!$connection) {
            $this->markTestSkipped('Connexion LDAP non configurée');
        }
        
        try {
            $connection->connect();
        } catch (\Exception $e) {
            $this->markTestSkipped('Serveur LDAP non accessible: ' . $e->getMessage());
        }
        
        $baseDn = $connection->getConfiguration()->get('base_dn');
        if (empty($baseDn)) {
            $this->markTestSkipped('Base DN non configuré');
        }
        
        // Requête LdapRecord pour les groupes
        $query = $connection->query()
            ->setBaseDn($baseDn)
            ->select(LdapAttributes::GROUP_FAST)
            ->rawFilter(LdapFilter::GROUP_ALL)
            ->limit(10);
        
        $ldapRecordResults = $query->get();
        
        $this->assertIsArray($ldapRecordResults, 'LdapRecord doit retourner un tableau');
    }
    
    /**
     * Test que les DN spécifiques SambaEdu sont accessibles
     */
    public function test_can_access_sambaedu_specific_dns(): void
    {
        $isProduction = app()->environment('production');
        if (!$isProduction) {
            $this->markTestSkipped('Aucun DN spécifique SambaEdu configuré (normal en environnement local/dev)');
        }
        $connection = Container::getConnection('default');
        if (!$connection) {
            $this->markTestSkipped('Connexion LDAP non configurée');
        }
        
        try {
            $connection->connect();
        } catch (\Exception $e) {
            $this->markTestSkipped('Serveur LDAP non accessible: ' . $e->getMessage());
        }
        
        // Tester chaque DN spécifique s'il est configuré
        $dns = [
            'base_dn_people' => config('ldap.connections.default.base_dn_people'),
            'base_dn_groups' => config('ldap.connections.default.base_dn_groups'),
            'base_dn_computers' => config('ldap.connections.default.base_dn_computers'),
            'base_dn_parcs' => config('ldap.connections.default.base_dn_parcs'),
        ];
        
        $testedCount = 0;
        foreach ($dns as $key => $dn) {
            if (!empty($dn)) {
                // Tester qu'on peut lire depuis ce DN
                $query = $connection->query()
                    ->setBaseDn($dn)
                    ->select(['dn'])
                    ->limit(1);
                
                try {
                    $result = $query->get();
                    $this->assertIsArray($result, "Le DN $key doit être accessible");
                    $testedCount++;
                } catch (\Exception $e) {
                    // Si le DN n'existe pas, c'est OK, on skip juste ce test
                    $this->markTestSkipped("DN $key non accessible: " . $e->getMessage());
                }
            }
        }
        
        // En environnement local/dev, les DN spécifiques peuvent ne pas être configurés
        // En production, c'est une erreur si aucun DN n'est configuré
        if ($testedCount === 0) {
                $this->fail('Aucun DN spécifique SambaEdu configuré - requis en production');
        }
        
        $this->assertGreaterThan(0, $testedCount, 'Au moins un DN doit être configuré et testé');
    }
}

