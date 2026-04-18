<?php

namespace Tests\Unit;

use App\Config\LdapConfig;
use App\Config\SambaEduConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests unitaires pour legacy/config.inc.php.
 *
 * Vérifie que les variables de configuration legacy sont correctement
 * alimentées depuis SambaEduConfig (source unique /etc/sambaedu/sambaedu.conf).
 *
 * Note : config.inc.php n'est chargé qu'une fois (guard LEGACY_CONFIG_LOADED),
 * mais legacy_build_config() est appelable à chaque test via reloadConfigBridge().
 */
class LegacyConfigBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Bind un SambaEduConfig en dur pour les tests — pas de dépendance à /etc/
        $fake = $this->createMock(SambaEduConfig::class);
        $fake->method('ldap')->willReturn(new LdapConfig(
            url: 'ldaps://ecole.local',
            port: 636,
            baseDn: 'DC=ecole,DC=local',
            adminName: 'administrator',
            adminPassword: 'secret',
            domain: 'ecole.local',
            sambaDomain: 'ecole',
            peopleRdn: 'ou=Utilisateurs',
            groupsRdn: 'ou=Groups',
            computersRdn: 'ou=computers',
            parcsRdn: 'ou=Parcs',
            classesRdn: 'ou=classes',
            equipesRdn: 'ou=equipes',
            matieresRdn: 'ou=matieres',
            coursRdn: 'ou=cours',
            projetsRdn: 'ou=projets',
            otherGroupsRdn: 'ou=autres',
            delegationsRdn: 'ou=delegations',
            equipementsRdn: 'ou=Materiels',
            rightsRdn: 'ou=Rights',
            trashRdn: 'ou=Trash',
            etablissementsRdn: 'OU=etablissements',
            adminRdn: 'cn=Users',
            serverIp: '192.168.1.10',
            etabServerIp: null,
            strictLocalAd: false,
        ));
        $this->app->instance(SambaEduConfig::class, $fake);

        Config::set('sambaedu.etab_ou', '0991229Y');

        $this->reloadConfigBridge();
    }

    /**
     * Recharge $config en appelant la vraie fonction du bridge.
     *
     * legacy/config.inc.php ne peut être chargé qu'une fois (guard LEGACY_CONFIG_LOADED),
     * mais legacy_build_config() est appelable à volonté — ce qui permet de rejouer
     * la logique réelle avec des valeurs Laravel différentes entre chaque test.
     */
    private function reloadConfigBridge(): void
    {
        require_once base_path('legacy/config.inc.php');
        global $config;
        $config = legacy_build_config();
    }

    /**
     * AC2 — Les constantes legacy sont définies.
     */
    public function test_legacy_constants_are_defined(): void
    {
        require_once base_path('legacy/config.inc.php');

        $this->assertTrue(defined('SAMBAEDU_NO_WOL'));
        $this->assertEquals(1, SAMBAEDU_NO_WOL);
        $this->assertTrue(defined('SAMBAEDU_NO_SHUTDOWN'));
        $this->assertEquals(4, SAMBAEDU_NO_SHUTDOWN);
        $this->assertTrue(defined('SAMBAEDU_WPKG_ERROR'));
        $this->assertEquals(32768, SAMBAEDU_WPKG_ERROR);
        $this->assertTrue(defined('CACHE_DIR'));
    }

    /**
     * AC2 — Le $config array est alimenté depuis la config Laravel.
     */
    public function test_config_array_is_populated_from_laravel(): void
    {
        global $config;

        $this->assertEquals('DC=ecole,DC=local', $config['ldap_base_dn']);
        $this->assertEquals('administrator', $config['ldap_admin_name']);
        $this->assertEquals('secret', $config['ldap_admin_passwd']);
        $this->assertEquals('192.168.1.10', $config['se4ad_ip']);
    }

    /**
     * AC2 — Le domaine est extrait du base_dn.
     */
    public function test_domain_is_derived_from_base_dn(): void
    {
        global $config;
        $this->assertEquals('ecole.local', $config['domain']);
    }

    /**
     * AC2 — Les DNs sont construits correctement.
     */
    public function test_dns_are_built_correctly(): void
    {
        global $config;

        $this->assertArrayHasKey('dn', $config);
        $this->assertArrayHasKey('people', $config['dn']);
        $this->assertArrayHasKey('groups', $config['dn']);
        $this->assertArrayHasKey('Eleves', $config['dn']);
        $this->assertArrayHasKey('Profs', $config['dn']);

        $this->assertStringContainsString('DC=ecole,DC=local', $config['dn']['people']);
    }

    /**
     * AC2 — Le préfixe UAI est appliqué aux RDNs.
     */
    public function test_uai_prefix_is_applied_to_rdns(): void
    {
        global $config;

        $this->assertStringContainsString('OU=0991229Y', $config['people_rdn']);
        $this->assertStringContainsString('OU=0991229Y', $config['groups_rdn']);
    }

    /**
     * AC2 — Le suffix est calculé à partir de l'UAI.
     */
    public function test_suffix_is_calculated_from_uai(): void
    {
        global $config;
        $this->assertEquals('-1229y', $config['suffix']);
    }

    /**
     * AC2 — Les timeouts par défaut sont définis.
     */
    public function test_default_timeouts_are_set(): void
    {
        global $config;

        $this->assertEquals(20, $config['ldap_timeout']);
        $this->assertEquals(30, $config['ldap_timelimit']);
        $this->assertEquals(600, $config['ent_timeout']);
    }
}
