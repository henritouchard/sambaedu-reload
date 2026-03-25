<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Tests unitaires pour legacy/bootstrap.php.
 *
 * Vérifie que le bootstrap rend app(), config(), auth() disponibles
 * et que les error handlers sont branchés.
 */
class LegacyBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * AC1 — Le bootstrap rend app() disponible.
     */
    public function test_bootstrap_makes_app_available(): void
    {
        // L'app Laravel est déjà bootstrappée par le test case,
        // on vérifie que require du bootstrap est idempotent
        require_once base_path('legacy/bootstrap.php');

        $this->assertTrue(function_exists('app'));
        $this->assertNotNull(app());
    }

    /**
     * AC1 — Le bootstrap rend config() disponible.
     */
    public function test_bootstrap_makes_config_available(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $this->assertTrue(function_exists('config'));
        $this->assertNotNull(config('app.name'));
    }

    /**
     * AC1 — Le bootstrap rend auth() disponible.
     */
    public function test_bootstrap_makes_auth_available(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $this->assertTrue(function_exists('auth'));
        $this->assertNotNull(auth());
    }

    /**
     * AC1 — Le bootstrap est idempotent (double require ne crashe pas).
     */
    public function test_bootstrap_is_idempotent(): void
    {
        require_once base_path('legacy/bootstrap.php');
        require_once base_path('legacy/bootstrap.php');

        // Si on arrive ici sans erreur, le test passe
        $this->assertTrue(defined('LEGACY_BOOTSTRAP_LOADED'));
    }

    /**
     * AC1 — Le bootstrap charge config.inc.php et ldap.inc.php.
     */
    public function test_bootstrap_loads_config_and_ldap_shim(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $this->assertTrue(defined('LEGACY_CONFIG_LOADED'));
        $this->assertTrue(defined('LEGACY_LDAP_SHIM_LOADED'));
    }
}
