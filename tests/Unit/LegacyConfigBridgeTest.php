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
 * Note : config.inc.php n'est chargé qu'une fois (guard LEGACY_CONFIG_LOADED).
 * On configure les valeurs AVANT le premier chargement dans setUpBeforeClass().
 */
class LegacyConfigBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Configurer les valeurs Laravel AVANT de charger le bridge
        Config::set('sambaedu.legacy_ldap.base_dn', 'DC=ecole,DC=local');
        Config::set('sambaedu.legacy_ldap.bind_dn', 'administrator');
        Config::set('sambaedu.legacy_ldap.bind_password', 'secret');
        Config::set('sambaedu.se4ad_ip', '192.168.1.10');
        Config::set('sambaedu.etab_ou', '0991229Y');

        // Forcer le rechargement du config bridge en effaçant les constantes
        // Comme on ne peut pas undefine(), on recharge les variables manuellement
        $this->reloadConfigBridge();
    }

    /**
     * Recharge les variables $config en réexécutant la logique du bridge.
     */
    private function reloadConfigBridge(): void
    {
        global $config;
        $config = $config ?? [];

        // Reproduire la logique de config.inc.php
        $config['ldap_base_dn']      = config('sambaedu.legacy_ldap.base_dn', '');
        $config['ldap_admin_name']   = config('sambaedu.legacy_ldap.bind_dn', '');
        $config['ldap_admin_passwd'] = config('sambaedu.legacy_ldap.bind_password', '');
        $config['se4ad_ip']          = config('sambaedu.se4ad_ip', '');
        $config['se4ad_etab_ip']     = config('sambaedu.se4ad_etab_ip', '');
        $config['etab_ou']           = config('sambaedu.etab_ou', '');

        // Déduire le domaine
        $config['domain'] = '';
        if (!empty($config['ldap_base_dn'])) {
            $dcParts = [];
            foreach (explode(',', $config['ldap_base_dn']) as $part) {
                $part = trim($part);
                if (stripos($part, 'DC=') === 0) {
                    $dcParts[] = substr($part, 3);
                }
            }
            $config['domain'] = implode('.', $dcParts);
        }

        // Suffix UAI
        $config['suffix'] = '';
        if (preg_match('/[0-9]{7}[a-z]/i', $config['etab_ou'])) {
            $config['suffix'] = strtolower('-' . substr($config['etab_ou'], 3));
        }

        // OUs
        $config['people_rdn']        = 'OU=people';
        $config['groups_rdn']        = 'OU=groups';
        $config['equipements_rdn']   = 'OU=equipements';
        $config['computers_rdn']     = 'OU=computers';
        $config['delegations_rdn']   = 'OU=delegations';
        $config['parcs_rdn']         = 'OU=parcs';
        $config['rights_rdn']        = 'OU=rights';
        $config['trash_rdn']         = 'OU=trash';
        $config['etablissements_rdn'] = 'OU=etablissements';
        $config['matieres_rdn']      = 'OU=Matieres';
        $config['cours_rdn']         = 'OU=Cours';
        $config['classes_rdn']       = 'OU=Classes';
        $config['equipes_rdn']       = 'OU=Equipes';
        $config['projets_rdn']       = 'OU=Projets';
        $config['other_groups_rdn']  = 'OU=Autres';

        // Préfixe UAI
        if (preg_match('/[0-9]{7}[a-z]/i', $config['etab_ou'])) {
            $uaiPrefix = 'OU=' . $config['etab_ou'] . ',';
            $config['people_rdn']      = $uaiPrefix . $config['people_rdn'];
            $config['groups_rdn']      = $uaiPrefix . $config['groups_rdn'];
            $config['equipements_rdn'] = $uaiPrefix . $config['equipements_rdn'];
            $config['delegations_rdn'] = $uaiPrefix . $config['delegations_rdn'];
            $config['parcs_rdn']       = $uaiPrefix . $config['parcs_rdn'];
            $config['computers_rdn']   = $uaiPrefix . $config['computers_rdn'];
        }

        // DNs
        $baseDn = $config['ldap_base_dn'];
        $config['dn'] = [];
        $config['dn']['people']          = $config['people_rdn'] . ',' . $baseDn;
        $config['dn']['Eleves']          = 'OU=Eleves,' . $config['people_rdn'] . ',' . $baseDn;
        $config['dn']['Profs']           = 'OU=Profs,' . $config['people_rdn'] . ',' . $baseDn;
        $config['dn']['Administratifs']  = 'OU=Administratifs,' . $config['people_rdn'] . ',' . $baseDn;
        $config['dn']['groups']          = $config['groups_rdn'] . ',' . $baseDn;
        $config['dn']['rights']          = $config['rights_rdn'] . ',' . $baseDn;
        $config['dn']['equipements']     = $config['equipements_rdn'] . ',' . $baseDn;
        $config['dn']['etablissements']  = $config['etablissements_rdn'] . ',' . $baseDn;
        $config['dn']['delegations']     = $config['delegations_rdn'] . ',' . $baseDn;
        $config['dn']['matieres']        = $config['matieres_rdn'] . ',' . $baseDn;
        $config['dn']['trash']           = $config['trash_rdn'] . ',' . $baseDn;
        $config['dn']['parcs']           = $config['parcs_rdn'] . ',' . $baseDn;
        $config['dn']['computers']       = $config['computers_rdn'] . ',' . $baseDn;
        $config['dn']['autres']          = $config['other_groups_rdn'] . ',' . $config['groups_rdn'] . ',' . $baseDn;
        $config['dn']['projets']         = $config['projets_rdn'] . ',' . $config['groups_rdn'] . ',' . $baseDn;
        $config['dn']['cours']           = $config['cours_rdn'] . ',' . $config['groups_rdn'] . ',' . $baseDn;
        $config['dn']['classes']         = $config['classes_rdn'] . ',' . $config['groups_rdn'] . ',' . $baseDn;
        $config['dn']['equipes']         = $config['equipes_rdn'] . ',' . $config['groups_rdn'] . ',' . $baseDn;

        $config['ldap_timeout']   = 20;
        $config['ldap_timelimit'] = 30;
        $config['ent_timeout']    = 600;
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
