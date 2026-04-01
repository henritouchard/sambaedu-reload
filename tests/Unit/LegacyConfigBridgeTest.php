<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests unitaires pour legacy/config.inc.php.
 *
 * Vérifie que les variables de configuration legacy sont
 * correctement alimentées depuis config('sambaedu.*').
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

        // Configurer les valeurs Laravel avant d'appeler legacy_build_config()
        Config::set('sambaedu.legacy_ldap.base_dn', 'DC=ecole,DC=local');
        Config::set('sambaedu.legacy_ldap.bind_dn', 'administrator');
        Config::set('sambaedu.legacy_ldap.bind_password', 'secret');
        Config::set('sambaedu.se4ad_ip', '192.168.1.10');
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
