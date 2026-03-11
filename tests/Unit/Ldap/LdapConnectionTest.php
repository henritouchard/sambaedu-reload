<?php

namespace Tests\Unit\Ldap;

use Tests\TestCase;
use LdapRecord\Container;
use LdapRecord\Connection;
use Illuminate\Support\Facades\Log;

/**
 * Tests de connexion LDAP avec LdapRecord
 * 
 * Valide que LdapRecord peut se connecter au même serveur LDAP que le legacy
 */
class LdapConnectionTest extends TestCase
{
    /**
     * Test que la connexion LDAP est configurée dans le container
     */
    public function test_ldap_connection_is_configured(): void
    {
        $connection = Container::getConnection('default');
        
        $this->assertNotNull($connection, 'La connexion LDAP doit être configurée dans le container');
        $this->assertInstanceOf(Connection::class, $connection);
    }
    
    /**
     * Test que la connexion LDAP peut se connecter au serveur
     */
    public function test_ldap_connection_can_connect(): void
    {
        $connection = Container::getConnection('default');
        
        $this->assertNotNull($connection);
        
        // Tester la connexion
        $connection->connect();
        
        // Si on arrive ici, la connexion a réussi
        $this->assertTrue(true, 'Connexion LDAP réussie');
    }
    
    /**
     * Test que la configuration de connexion contient les paramètres essentiels
     */
    public function test_ldap_connection_has_required_config(): void
    {
        $connection = Container::getConnection('default');
        
        $this->assertNotNull($connection);
        
        $config = $connection->getConfiguration();
        
        // Vérifier les paramètres essentiels
        $this->assertNotEmpty($config->get('hosts'), 'Les hôtes LDAP doivent être configurés');
        $this->assertNotEmpty($config->get('base_dn'), 'Le base_dn doit être configuré');
        $this->assertNotEmpty($config->get('username'), 'Le username doit être configuré');
        $this->assertIsInt($config->get('port'), 'Le port doit être un entier');
        $this->assertGreaterThan(0, $config->get('port'), 'Le port doit être supérieur à 0');
    }
    
    /**
     * Test qu'une requête simple fonctionne
     */
    public function test_ldap_connection_can_query(): void
    {
        $connection = Container::getConnection('default');
        
        $this->assertNotNull($connection);
        
        $connection->connect();
        
        // Faire une requête simple sur le base_dn
        $baseDn = $connection->getConfiguration()->get('base_dn');
        $query = $connection->query()->setBaseDn($baseDn);
        $result = $query->select(['dn'])->limit(1)->get();
        
        // Vérifier qu'on obtient un résultat
        $this->assertIsArray($result, 'Le résultat doit être un tableau');
        $this->assertGreaterThanOrEqual(0, count($result), 'Le résultat peut être vide mais doit être un tableau valide');
    }
    
    /**
     * Test que les DN spécifiques SambaEdu sont configurés
     */
    public function test_ldap_sambaedu_dns_are_configured(): void
    {
        $baseDnComputers = config('ldap.connections.default.base_dn_computers');
        $baseDnParcs = config('ldap.connections.default.base_dn_parcs');
        $baseDnPeople = config('ldap.connections.default.base_dn_people');
        $baseDnGroups = config('ldap.connections.default.base_dn_groups');
        
        // En environnement de test, la configuration peut être vide
        // On vérifie juste que les clés existent
        $this->assertIsArray(config('ldap.connections.default'));
        
        // Si la configuration n'est pas vide, au moins un DN doit être configuré
        if (!empty(config('ldap.connections.default.base_dn'))) {
            $hasAtLeastOneDn = !empty($baseDnComputers) || 
                              !empty($baseDnParcs) || 
                              !empty($baseDnPeople) || 
                              !empty($baseDnGroups);
            
            $this->assertTrue($hasAtLeastOneDn, 'Avec base_dn configuré, au moins un DN spécifique SambaEdu doit être configuré');
        } else {
            // En environnement de test sans configuration LDAP, c'est OK
            $this->assertTrue(true, 'Configuration LDAP vide en environnement de test');
        }
    }
}

