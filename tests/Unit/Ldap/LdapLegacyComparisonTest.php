<?php

namespace Tests\Unit\Ldap;

use Tests\TestCase;
use LdapRecord\Container;
use LdapRecord\Connection;
use App\Config\SambaEduConfig;

/**
 * Tests de comparaison entre LdapRecord et le système legacy
 * 
 * Valide que LdapRecord peut accéder aux mêmes données que le legacy
 */
class LdapLegacyComparisonTest extends TestCase
{
    /**
     * Test que la configuration LdapRecord correspond à la configuration legacy
     */
    public function test_ldap_config_matches_legacy_config(): void
    {
        // Récupérer la config depuis SambaEduConfig
        $config = app(SambaEduConfig::class);
        $legacyConfig = $config->all();

        // Si la config legacy n'est pas disponible, charger depuis le fichier
        if (empty($legacyConfig) || empty($legacyConfig['ldap_base_dn'])) {
            $configFile = '/etc/sambaedu/sambaedu.conf';
            if (file_exists($configFile)) {
                $legacyConfig = parse_ini_file($configFile);
            }
        }

        // Récupérer la config LdapRecord
        $connection = Container::getConnection('default');
        $this->assertNotNull($connection, 'La connexion LdapRecord doit être configurée');

        $ldapConfig = $connection->getConfiguration();

        // Comparer les paramètres essentiels
        if (!empty($legacyConfig['ldap_base_dn'])) {
            $this->assertEquals(
                $legacyConfig['ldap_base_dn'],
                $ldapConfig->get('base_dn'),
                'Le base_dn doit correspondre entre legacy et LdapRecord'
            );
        }

        if (!empty($legacyConfig['domain']) && !empty($legacyConfig['ldap_admin_name'])) {
            $expectedUsername = $legacyConfig['ldap_admin_name'] . '@' . $legacyConfig['domain'];
            $this->assertEquals(
                $expectedUsername,
                $ldapConfig->get('username'),
                'Le username doit correspondre entre legacy et LdapRecord'
            );
        }
    }

    /**
     * Test que LdapRecord peut lire le même base_dn que le legacy
     */
    public function test_ldap_can_read_same_base_dn_as_legacy(): void
    {
        // Récupérer le base_dn depuis la config legacy
        $configFile = '/etc/sambaedu/sambaedu.conf';
        if (!file_exists($configFile)) {
            $this->markTestSkipped('Fichier de configuration legacy non trouvé');
        }

        $legacyConfig = parse_ini_file($configFile);
        $legacyBaseDn = $legacyConfig['ldap_base_dn'] ?? null;

        if (empty($legacyBaseDn)) {
            $this->markTestSkipped('Base DN non configuré dans la config legacy');
        }

        // Récupérer le base_dn depuis LdapRecord
        $connection = Container::getConnection('default');
        $this->assertNotNull($connection);

        $connection->connect();

        $ldapBaseDn = $connection->getConfiguration()->get('base_dn');

        // Vérifier qu'ils correspondent
        $this->assertEquals($legacyBaseDn, $ldapBaseDn, 'Les base_dn doivent correspondre');

        // Tester qu'on peut lire depuis ce base_dn avec LdapRecord
        $query = $connection->query()->setBaseDn($ldapBaseDn);
        $result = $query->select(['dn'])->limit(1)->get();

        $this->assertIsArray($result, 'LdapRecord doit pouvoir lire depuis le base_dn');
    }

    /**
     * Test que les constantes LDAP sont disponibles
     */
    public function test_ldap_constants_are_available(): void
    {
        $this->assertTrue(
            class_exists(\App\Constants\Ldap\LdapFilter::class),
            'La classe LdapFilter doit exister'
        );

        $this->assertTrue(
            class_exists(\App\Constants\Ldap\LdapAttributes::class),
            'La classe LdapAttributes doit exister'
        );

        $this->assertTrue(
            class_exists(\App\Constants\Ldap\LdapScope::class),
            'La classe LdapScope doit exister'
        );

        // Tester quelques constantes
        $this->assertNotEmpty(\App\Constants\Ldap\LdapFilter::USER_ALL);
        $this->assertIsArray(\App\Constants\Ldap\LdapAttributes::USER);
        $this->assertNotEmpty(\App\Constants\Ldap\LdapScope::SUBTREE);
    }
}

